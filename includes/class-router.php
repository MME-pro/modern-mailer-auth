<?php
/**
 * Smart routing: choose a connection from the message itself.
 *
 * @package ModernMailer
 */

namespace ModernMailer;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which connection a message should go out through.
 *
 * A site rarely wants every email on one path. Receipts and password resets
 * need a transactional sender with a clean reputation; a weekly digest does
 * not, and sending it alongside them drags that reputation down. Routing lets
 * the two share a site without sharing a sender.
 *
 * ## The shape of a rule
 *
 * Each rule names a connection and holds one or more groups of conditions:
 *
 *   [ 'connection' => 'c1a2b3c4', 'groups' => [ [ condition, condition ], [ … ] ] ]
 *
 * Conditions inside a group are ANDed; groups are ORed. That is the standard
 * arrangement, and it is expressive enough for anything a mail rule needs while
 * still being drawable as a form.
 *
 * Rules are evaluated in order and the first match wins. Order is therefore
 * meaningful and the admin can change it - with overlapping rules, "first
 * match" is the only ordering an author can reason about without simulating
 * the whole set.
 *
 * ## What routing deliberately does not do
 *
 * It does not decide fallback. A routed message that fails still falls through
 * to the backup connection and then to the queue, exactly as an unrouted one
 * does. Routing picks the preferred path; it does not opt a message out of the
 * machinery that stops mail being lost.
 */
class Router {

	/** Message properties a condition can test. */
	public const FIELDS = [
		'subject'    => 'Subject',
		'to'         => 'To address',
		'to_domain'  => 'Recipient domain',
		'from_email' => 'From address',
		'cc'         => 'Cc address',
		'bcc'        => 'Bcc address',
	];

	public const OPERATORS = [
		'contains'     => 'Contains',
		'not_contains' => 'Does not contain',
		'is'           => 'Is exactly',
		'is_not'       => 'Is not',
		'starts_with'  => 'Starts with',
		'ends_with'    => 'Ends with',
	];

	public function __construct(
		private Settings $settings,
		private Connections $connections
	) {}

	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'routing_enabled' );
	}

	/**
	 * The stored rules, with anything unusable dropped.
	 *
	 * Filtering on read rather than trusting the option means a rule pointing at
	 * a connection that has since been deleted is ignored instead of routing
	 * mail into a slot with no provider - which would fail every send that
	 * matched it.
	 *
	 * @return array<int,array{connection:string,groups:array<int,array<int,array{field:string,operator:string,value:string}>>}>
	 */
	public function rules(): array {
		$stored = $this->settings->get( 'routing_rules' );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$out = [];

		foreach ( $stored as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$connection = (string) ( $rule['connection'] ?? '' );

			if ( '' === $connection || ! $this->connections->exists( $connection ) ) {
				continue;
			}

			$groups = $this->clean_groups( $rule['groups'] ?? [] );

			// A rule with no usable condition would match everything, which is
			// never what an author who left a field blank meant.
			if ( [] === $groups ) {
				continue;
			}

			$out[] = [
				'connection' => $connection,
				'groups'     => $groups,
			];
		}

		return $out;
	}

	/**
	 * The slot this message should be sent through, or null for the primary.
	 */
	public function route( Message $message ): ?string {
		if ( ! $this->is_enabled() ) {
			return null;
		}

		foreach ( $this->rules() as $rule ) {
			if ( ! $this->matches( $rule['groups'], $message ) ) {
				continue;
			}

			$slot = $this->connections->slot_for( $rule['connection'] );

			if ( null === $slot ) {
				continue;
			}

			/**
			 * Fires when a routing rule selected a connection.
			 *
			 * @param string  $connection Connection id.
			 * @param Message $message    The message being routed.
			 */
			do_action( 'mmoa_message_routed', $rule['connection'], $message );

			return $slot;
		}

		return null;
	}

	/**
	 * Groups are ORed; conditions inside a group are ANDed.
	 *
	 * @param array<int,array<int,array{field:string,operator:string,value:string}>> $groups Condition groups.
	 */
	private function matches( array $groups, Message $message ): bool {
		foreach ( $groups as $group ) {
			$all = true;

			foreach ( $group as $condition ) {
				if ( ! $this->test( $condition, $message ) ) {
					$all = false;
					break;
				}
			}

			if ( $all ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{field:string,operator:string,value:string} $condition One condition.
	 */
	private function test( array $condition, Message $message ): bool {
		$haystacks = $this->values_for( $condition['field'], $message );
		$needle    = strtolower( trim( $condition['value'] ) );
		$operator  = $condition['operator'];

		// A negative operator has to hold for every candidate value, where a
		// positive one only needs one to match. "Does not contain" over a
		// three-recipient message means none of the three contains it - reading
		// it the other way round would make the rule fire whenever any single
		// recipient differed, which is not what the words say.
		$negative = in_array( $operator, [ 'not_contains', 'is_not' ], true );

		if ( [] === $haystacks ) {
			// Nothing to compare against. A negative condition is vacuously
			// true; a positive one cannot be satisfied.
			return $negative;
		}

		// compare() always answers the positive question - "does this value
		// contain / equal the needle" - and the sense is applied here. A
		// positive condition is satisfied by the first match; a negative one is
		// broken by it, and only holds if nothing matched at all.
		foreach ( $haystacks as $haystack ) {
			if ( $this->compare( $operator, strtolower( $haystack ), $needle ) ) {
				return ! $negative;
			}
		}

		return $negative;
	}

	private function compare( string $operator, string $haystack, string $needle ): bool {
		switch ( $operator ) {
			case 'contains':
			case 'not_contains':
				return '' !== $needle && false !== strpos( $haystack, $needle );
			case 'is':
			case 'is_not':
				return $haystack === $needle;
			case 'starts_with':
				return '' !== $needle && 0 === strpos( $haystack, $needle );
			case 'ends_with':
				return '' !== $needle && substr( $haystack, -strlen( $needle ) ) === $needle;
			default:
				return false;
		}
	}

	/**
	 * The candidate values a field offers for comparison.
	 *
	 * Address fields return one entry per recipient rather than a joined string,
	 * so "is exactly" means "one of the recipients is exactly this" instead of
	 * comparing against a comma-separated list that would never match.
	 *
	 * @return array<int,string>
	 */
	private function values_for( string $field, Message $message ): array {
		switch ( $field ) {
			case 'subject':
				return [ $message->subject() ];

			case 'from_email':
				return [ $message->from()['email'] ];

			case 'to':
				return array_column( $message->to(), 'email' );

			case 'cc':
				return array_column( $message->cc(), 'email' );

			case 'bcc':
				return array_column( $message->bcc(), 'email' );

			case 'to_domain':
				return array_values(
					array_filter(
						array_map(
							static function ( array $addr ): string {
								$at = strrpos( $addr['email'], '@' );

								return false === $at ? '' : substr( $addr['email'], $at + 1 );
							},
							$message->to()
						)
					)
				);

			default:
				return [];
		}
	}

	/**
	 * Drop conditions and groups that could never be evaluated.
	 *
	 * @param mixed $groups Raw groups.
	 * @return array<int,array<int,array{field:string,operator:string,value:string}>>
	 */
	private function clean_groups( $groups ): array {
		if ( ! is_array( $groups ) ) {
			return [];
		}

		$out = [];

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$conditions = [];

			foreach ( $group as $condition ) {
				if ( ! is_array( $condition ) ) {
					continue;
				}

				$field    = (string) ( $condition['field'] ?? '' );
				$operator = (string) ( $condition['operator'] ?? '' );
				$value    = (string) ( $condition['value'] ?? '' );

				if ( ! isset( self::FIELDS[ $field ], self::OPERATORS[ $operator ] ) ) {
					continue;
				}

				// An empty value makes every operator here either always true or
				// always false, so the condition says nothing. Dropping it is
				// safer than letting a half-finished rule capture all mail.
				if ( '' === trim( $value ) ) {
					continue;
				}

				$conditions[] = [
					'field'    => $field,
					'operator' => $operator,
					'value'    => $value,
				];
			}

			if ( [] !== $conditions ) {
				$out[] = $conditions;
			}
		}

		return $out;
	}

	/**
	 * Field and operator labels for the admin app.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function vocabulary(): array {
		return [
			'fields'    => [
				'subject'    => __( 'Subject', 'modern-mailer-oauth' ),
				'to'         => __( 'To address', 'modern-mailer-oauth' ),
				'to_domain'  => __( 'Recipient domain', 'modern-mailer-oauth' ),
				'from_email' => __( 'From address', 'modern-mailer-oauth' ),
				'cc'         => __( 'Cc address', 'modern-mailer-oauth' ),
				'bcc'        => __( 'Bcc address', 'modern-mailer-oauth' ),
			],
			'operators' => [
				'contains'     => __( 'Contains', 'modern-mailer-oauth' ),
				'not_contains' => __( 'Does not contain', 'modern-mailer-oauth' ),
				'is'           => __( 'Is exactly', 'modern-mailer-oauth' ),
				'is_not'       => __( 'Is not', 'modern-mailer-oauth' ),
				'starts_with'  => __( 'Starts with', 'modern-mailer-oauth' ),
				'ends_with'    => __( 'Ends with', 'modern-mailer-oauth' ),
			],
		];
	}
}
