<?php
/**
 * The two short-lived things this service remembers.
 *
 * @package ModernMailerBroker
 */

declare(strict_types=1);

namespace ModernMailer\Broker;

use PDO;

/**
 * Pending authorizations, and handoffs waiting to be claimed.
 *
 * What is NOT here is the interesting part: there is no table of grants. The
 * site keeps the refresh token; refreshing means the site sends it back and
 * this service exchanges it using the client secret it holds. So a token exists
 * here only during the few minutes between an admin approving at Google and the
 * site claiming it, and it is encrypted for that window and deleted on use.
 *
 * That is a deliberate difference from how this is usually built. Holding every
 * customer's refresh token indefinitely makes a breach here catastrophic and
 * makes you a processor of their mailbox access under GDPR. Holding nothing
 * makes the worst case "one admin has to sign in again".
 */
final class Store {

	public function __construct( private PDO $pdo, private Crypto $crypto ) {}

	public static function connect( Config $config, Crypto $crypto ): self {
		$pdo = new PDO(
			$config->require( 'DB_DSN' ),
			$config->get( 'DB_USER' ),
			$config->get( 'DB_PASS' ),
			[
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES   => false,
			]
		);

		return new self( $pdo, $crypto );
	}

	/**
	 * Record an authorization about to be sent to a provider.
	 *
	 * The returned id is what travels to Google or Microsoft as `state`. It is
	 * deliberately not the site's own state value: that one goes back to the
	 * site untouched, and mixing the two would let anything that saw one guess
	 * the other.
	 */
	public function open_flow( string $family, string $site_id, string $site_url, string $callback, string $site_state ): string {
		$id = bin2hex( random_bytes( 32 ) );

		$this->pdo->prepare(
			'INSERT INTO flows (id, family, site_id, site_url, callback, site_state, expires_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?)'
		)->execute(
			[ $id, $family, $site_id, $site_url, $callback, $site_state, gmdate( 'Y-m-d H:i:s', time() + 900 ) ]
		);

		return $id;
	}

	/**
	 * Take a flow by id, removing it so a replayed callback finds nothing.
	 *
	 * @return array<string,mixed>|null
	 */
	public function take_flow( string $id ): ?array {
		$statement = $this->pdo->prepare( 'SELECT * FROM flows WHERE id = ? AND expires_at > UTC_TIMESTAMP()' );
		$statement->execute( [ $id ] );

		$row = $statement->fetch();

		// Deleted whether or not it was still valid: an expired row has no
		// further use, and leaving it lets a stale id be tried repeatedly.
		$this->pdo->prepare( 'DELETE FROM flows WHERE id = ?' )->execute( [ $id ] );

		return $row ?: null;
	}

	/**
	 * Park a set of tokens behind a one-time code.
	 *
	 * The code is returned in the clear, because the site needs it; only its
	 * hash is stored, so a copy of this table is not a set of usable codes.
	 *
	 * @param array<string,mixed> $tokens
	 */
	public function open_handoff( string $family, string $site_id, array $tokens ): string {
		$code = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );

		$this->pdo->prepare(
			'INSERT INTO handoffs (code_hash, family, site_id, payload, expires_at)
			 VALUES (?, ?, ?, ?, ?)'
		)->execute(
			[
				hash( 'sha256', $code ),
				$family,
				$site_id,
				$this->crypto->seal( (string) json_encode( $tokens ) ),
				gmdate( 'Y-m-d H:i:s', time() + 300 ),
			]
		);

		return $code;
	}

	/**
	 * Redeem a handoff. One use only.
	 *
	 * @return array<string,mixed>|null
	 */
	public function take_handoff( string $family, string $site_id, string $code ): ?array {
		$hash = hash( 'sha256', $code );

		$statement = $this->pdo->prepare( 'SELECT * FROM handoffs WHERE code_hash = ? AND expires_at > UTC_TIMESTAMP()' );
		$statement->execute( [ $hash ] );

		$row = $statement->fetch();

		if ( ! $row ) {
			return null;
		}

		// A mismatch does not consume the handoff. Deleting on any lookup made
		// the row destroyable by anyone who could name the code but not the
		// site it belongs to - so a wrong guess, or a retry that raced, threw
		// away a credential the right site was still coming to collect.
		//
		// Consumed only on a genuine match, below.
		if ( $row['family'] !== $family || $row['site_id'] !== $site_id ) {
			return null;
		}

		$this->pdo->prepare( 'DELETE FROM handoffs WHERE code_hash = ?' )->execute( [ $hash ] );

		$json = $this->crypto->open( (string) $row['payload'] );

		if ( null === $json ) {
			return null;
		}

		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * How many authorizations a site has started in the last hour.
	 *
	 * A site that has genuinely lost its connection tries a handful of times.
	 * Hundreds means something is wrong, or someone is using this service to
	 * generate consent screens against our OAuth client, and both are worth
	 * stopping before Google notices on our behalf.
	 */
	public function recent_flows( string $site_id ): int {
		$statement = $this->pdo->prepare(
			'SELECT COUNT(*) AS n FROM flows WHERE site_id = ? AND expires_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 45 MINUTE)'
		);
		$statement->execute( [ $site_id ] );

		return (int) ( $statement->fetch()['n'] ?? 0 );
	}

	/**
	 * Drop anything that has aged out.
	 *
	 * Called on a small fraction of requests rather than from cron, because
	 * shared hosting cron is unreliable and these tables are tiny.
	 */
	public function prune(): void {
		$this->pdo->exec( 'DELETE FROM flows WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)' );
		$this->pdo->exec( 'DELETE FROM handoffs WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)' );
	}
}
