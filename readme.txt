=== Modern Mailer - OAuth SMTP for Microsoft 365 and Gmail ===
Contributors: builtwithmtw
Tags: smtp, wp_mail, microsoft 365, gmail, oauth
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.5.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends WordPress email through the Microsoft Graph and Gmail APIs using OAuth 2.0, with no password and nothing that expires and needs reauthorizing.

== Description ==

Microsoft is retiring Basic authentication for SMTP AUTH in Exchange Online, which ends the long-standing practice of pointing WordPress at smtp.office365.com with a mailbox password. The usual replacement is OAuth with a delegated sign-in, but that swaps one failure for another: it stores a refresh token, and refresh tokens die. They expire after about 90 days idle, and are revoked outright by a password change, an MFA enrolment, or a Conditional Access policy change.

When that happens, sending stops. Silently - because almost no WordPress code checks what `wp_mail()` returned. Sites routinely go weeks before somebody notices the receipts stopped arriving.

This plugin removes the refresh token from the design entirely.

**Microsoft 365** uses app-only authentication (the OAuth 2.0 client credentials grant). The site holds a client credential and mints a short-lived access token whenever it needs one. There is no sign-in prompt, no consent screen, and nothing left in the system to expire.

**Google Workspace** uses a service account with domain-wide delegation, which has the same property for the same reason.

**Gmail** consumer accounts use standard OAuth, because a service account cannot impersonate an @gmail.com address. This is the one path where a refresh token is unavoidable, and the plugin is explicit about that rather than pretending otherwise.

= Also =

* One MIME pipeline. Both APIs accept a complete RFC 822 message, so the message PHPMailer already built is what gets sent. Attachments, inline images, Reply-To, Cc, Bcc and custom headers are handled by WordPress core code rather than reassembled by hand.
* Failures are loud. `wp_mail()` returns an accurate result, `wp_mail_failed` fires with a real error, repeated failures raise an admin notice and a Site Health warning, and the `mmoa_send_failing` action lets you route alerts anywhere.
* Error messages name the actual misconfiguration - which credential is wrong, which Exchange policy is missing - instead of echoing a raw API response.
* Client secret expiry warnings, because an Entra secret lasts at most 24 months and its expiry is a scheduled outage.
* A backup connection and a persistent retry queue, so a transient fault at your host delays an email instead of losing it. Failures that no retry can help - an oversized attachment, a wrong secret - are reported immediately instead.
* Credentials belong in wp-config.php. Anything stored in the database instead is encrypted with libsodium.

== External services ==

This plugin sends your outgoing email through third-party APIs. Nothing is transmitted until you configure a provider.

**Microsoft (Entra ID and Microsoft Graph)** - used when the Microsoft 365 provider is selected.

* `https://login.microsoftonline.com` - receives your tenant ID, application ID and client secret, in exchange for an access token.
* `https://graph.microsoft.com` - receives the complete outgoing email: sender, recipients, subject, body and attachments.
* Terms: https://www.microsoft.com/licensing/terms/ - Privacy: https://privacy.microsoft.com/privacystatement

**Google (OAuth 2.0 and the Gmail API)** - used when either Google provider is selected.

* `https://oauth2.googleapis.com` - receives a signed assertion or your refresh token, in exchange for an access token.
* `https://gmail.googleapis.com` - receives the complete outgoing email: sender, recipients, subject, body and attachments.
* Terms: https://policies.google.com/terms - Privacy: https://policies.google.com/privacy

== Frequently Asked Questions ==

= Can I use a personal outlook.com or hotmail.com address? =

No. App-only authentication needs a Microsoft 365 work or school tenant, and consumer accounts do not have one.

= Why does it want an Exchange access policy? =

The `Mail.Send` application permission lets the app send as any mailbox in the tenant. Scoping it with `New-ApplicationAccessPolicy`, or with RBAC for Applications, restricts it to the mailboxes you choose. Do not skip this.

= My Gmail connection stops working every week. =

Your Google Cloud consent screen is still in Testing status, which expires refresh tokens every seven days. Publish it to In production and reconnect.

= How large can attachments be? =

About 2 MB in this version. Both APIs cap a single request at 4-5 MB, and a message on this path is base64-encoded twice, so the usable payload is roughly half the nominal limit. Oversized messages are rejected before sending with a message saying so. Chunked upload for larger attachments is planned.

== Changelog ==

= 0.5.2 =
* The Modern Mailer menu no longer has submenus. It opens straight onto the app, which carries its own tabs - Dashboard, Connections, Routing, Email Logs and Settings - so there is one navigation to follow rather than two that had to agree with each other.

= 0.5.1 =
* Fixed the on/off switches. The thumb was almost the same colour as the track when a switch was off, so the control read as a blank pill that gave no sign of its state - or that it was a control at all. The off track now has its own colour, the thumb carries a shadow, and the switch is larger and easier to hit.
* Clicking the text beside a switch now toggles it. It previously did nothing: the label pointed at a button, which browsers do not reliably activate that way.

= 0.5.0 =
* Added additional connections. Beyond Primary and Backup you can now configure up to ten more, each with its own provider and its own credentials.
* Added smart routing. Rules send matching email through a chosen connection - receipts through a transactional sender, a newsletter through somewhere else. Conditions can test the subject, the To, Cc or Bcc addresses, the recipient domain or the From address; conditions in a group are combined with And, groups with Or, and the first matching rule wins.
* Routing chooses the path and nothing more: a routed message that fails still falls back to the backup connection and then to the retry queue, exactly as an unrouted one does.
* A rule pointing at a connection that has been deleted, or one left without a condition, is ignored rather than capturing mail. Deleting a connection removes its stored credentials and any rule that referenced it.
* The queue records which connection a message was routed to, so a retry cannot silently move it to a different sender after the rules change.

= 0.4.2 =
* The plugin now checks GitHub for its own updates. New versions appear on the Plugins screen and under Dashboard - Updates like any other plugin, and update with one click or automatically if the site has auto-updates on.

= 0.4.1 =
* Reworked the Other SMTP form: server, username and password on one row; encryption as TLS / SSL / None radio buttons; the port set automatically from the encryption and still editable; authentication as an explicit on/off choice, on by default.
* Switching authentication off now disables the username and password rather than leaving them looking editable, and a username missing while authentication is on is reported before anything is dialled.

= 0.4.0 =
* Added Other SMTP - any server that speaks SMTP, including the SMTP endpoint of a service not listed here. Host, port, STARTTLS or implicit TLS, and an optional username and password.
* Verifying an SMTP connection opens a real session and authenticates, so a wrong port, the wrong encryption or a bad password is reported before the first message is sent rather than after.
* SMTP replies are classified correctly for retrying: a 4xx (greylisting, over quota) is held and tried again, a 5xx rejection is reported immediately and not retried.

= 0.3.0 =
* Rebuilt the admin as a single-page app: Dashboard, Connections, Email Logs and Settings under one menu, with a sending-status indicator on every screen.
* Added a Sign in with Google button for the consumer Gmail connection, alongside the redirect URI it needs and a plain explanation of what the consent prompt grants.
* Added a REST API the admin app runs on. Provider settings forms are generated from each provider's declared fields, so a provider added by another plugin gets a working form without any UI changes.
* Added SendGrid, Postmark and Mailgun.
* Added a notice when another plugin has taken over wp_mail(), which previously left this plugin configured but silently not sending.

= 0.2.0 =
* Moved to a top-level Modern Mailer menu with three screens: Settings, Backup and Logs. The Logs entry carries a count when the retry queue is holding anything.
* The Google OAuth redirect URI is now `admin-post.php?action=mmoa_google_callback`, which does not change if the admin menu is ever reorganised.
* Added a backup connection. When the primary fails, the message is retried immediately against a second, independently configured provider.
* Added a retry queue. A send that fails for a transient reason - a network or DNS fault at the host, a throttle, a 5xx - is held and retried across later requests instead of being lost. Attempts back off from five minutes and stop after about two days.
* A failed token refresh now falls back to the cached token while it is still genuinely valid, so a brief outage at the identity endpoint no longer costs an email.
* Added the Gmail sign-in flow. Enter your own OAuth client ID and secret, connect the account through Google's consent prompt, and revoke it again from the same screen.
* Queued and abandoned mail is surfaced in Site Health and on the settings screen, so mail that was never delivered cannot pass unnoticed.

= 0.1.0 =
* Initial release: Microsoft Graph app-only, Google service account, and Gmail OAuth providers.
