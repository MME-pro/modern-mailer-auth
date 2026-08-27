<?php
/**
 * What Google and Microsoft each expect.
 *
 * @package ModernMailerBroker
 */

declare(strict_types=1);

namespace ModernMailer\Broker;

/**
 * The per-provider details, kept in one place so the handlers stay generic.
 *
 * Two parameters below are load-bearing for Google and worth stating plainly,
 * because getting them wrong produces a connection that works for an hour and
 * then stops: without `access_type=offline` Google returns no refresh token at
 * all, and without `prompt=consent` it omits one on every authorization after
 * the first - so reconnecting an account appears to succeed and then fails at
 * the next refresh. Microsoft's equivalent is the `offline_access` scope.
 */
final class Providers {

	public const GOOGLE    = 'google';
	public const MICROSOFT = 'microsoft';

	public static function is_family( string $family ): bool {
		return self::GOOGLE === $family || self::MICROSOFT === $family;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function spec( string $family ): array {
		if ( self::MICROSOFT === $family ) {
			return [
				'authorize'  => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
				'token'      => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',

				// User.Read is only so the profile call below can report which
				// mailbox connected. Nothing here ever reads mail, and asking
				// for a read scope would raise the verification bar and cost
				// conversions on the consent screen for no benefit.
				'scope'      => 'offline_access openid email https://graph.microsoft.com/Mail.Send https://graph.microsoft.com/User.Read',
				'extra_auth' => [ 'response_mode' => 'query', 'prompt' => 'select_account' ],
				'client_id'  => 'MICROSOFT_CLIENT_ID',
				'secret'     => 'MICROSOFT_CLIENT_SECRET',
				'profile'    => 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName',
				'revoke'     => null,
			];
		}

		return [
			'authorize'  => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token'      => 'https://oauth2.googleapis.com/token',
			'scope'      => 'https://www.googleapis.com/auth/gmail.send openid email',
			'extra_auth' => [ 'access_type' => 'offline', 'prompt' => 'consent' ],
			'client_id'  => 'GOOGLE_CLIENT_ID',
			'secret'     => 'GOOGLE_CLIENT_SECRET',
			'profile'    => 'https://openidconnect.googleapis.com/v1/userinfo',
			'revoke'     => 'https://oauth2.googleapis.com/revoke',
		];
	}

	/**
	 * The address this service is registered under with the provider.
	 *
	 * One per family, and it must match what is on file character for
	 * character - which is why it is derived from one configured base URL
	 * rather than from the incoming request, whose host a proxy can change.
	 */
	public static function redirect_uri( Config $config, string $family ): string {
		return $config->base_url() . 'callback/' . $family;
	}

	/**
	 * Pull the connected address out of a profile response.
	 *
	 * @param array<string,mixed> $profile
	 */
	public static function address( string $family, array $profile ): string {
		if ( self::MICROSOFT === $family ) {
			return (string) ( $profile['mail'] ?? $profile['userPrincipalName'] ?? '' );
		}

		return (string) ( $profile['email'] ?? '' );
	}
}
