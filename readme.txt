=== Modern Mailer - OAuth SMTP for Microsoft 365 and Gmail ===
Contributors: builtwithmtw
Tags: smtp, wp_mail, microsoft 365, gmail, oauth
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
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

= 0.1.0 =
* Initial release: Microsoft Graph app-only, Google service account, and Gmail OAuth providers.
