# Modern Mailer

A WordPress plugin that sends mail through the **Microsoft Graph** and **Gmail** APIs
using OAuth 2.0 — no mailbox password, and nothing that quietly expires.

> **Status: early. Not production-ready.** The Microsoft path and the Google
> service-account path are complete and covered by tests. The consumer Gmail
> connect flow and the retry queue are not built yet. See [Status](#status).

## Why

Two separate things break WordPress email on Microsoft 365, and they need different fixes.

**1. Basic authentication is going away.** Pointing WordPress at `smtp.office365.com`
with a mailbox password is on a terminal path — disabled by default for existing tenants
at the end of 2026, unavailable for new tenants after that.

**2. The usual OAuth replacement introduces its own failure.** Signing in interactively
stores a *refresh token*, and refresh tokens die: after ~90 days idle, on a password
change, on an MFA enrolment, on any Conditional Access change. When one dies, sending
stops **silently**, because almost no WordPress code checks what `wp_mail()` returned.

This plugin removes the refresh token from the design. Microsoft 365 uses **app-only**
authentication (client credentials): the site holds a credential, mints a short-lived
token whenever it needs one, and there is nothing left to expire. Google Workspace uses a
**service account** with domain-wide delegation, which has the same property.

### A third failure, found in real logs

Diagnosing a live site turned up something neither of the above fixes: a **brief network
blip** — the server unreachable for a millisecond or two — caused the token request to
fail. The mailer didn't check, carried on to the send step with an empty URL, and
WordPress rejected it with *"A valid URL was not provided."* The email was marked failed
and discarded. Over a year that cost the site 89 emails, including customer enquiries,
at a 4% loss rate.

The error message named the wrong thing entirely, which is what made it so hard to chase.

Three defences against that are already here: tokens are cached for their full lifetime so
most sends never touch the identity endpoint at all; a failed token fetch aborts instead of
continuing; and no URL is ever derived from a response body. The fourth and most important
— a persistent retry queue, so a transient outage delays an email instead of losing it —
is the next thing to build.

## How it works

Both target APIs accept a complete RFC 822 message:

| Transport | Endpoint | Field |
|---|---|---|
| Microsoft Graph | `POST /v1.0/users/{upn}/sendMail`, `Content-Type: text/plain` | base64 of the MIME |
| Gmail | `POST /gmail/v1/users/{sub}/messages/send` | `{"raw": "<base64url MIME>"}` |

So PHPMailer builds the message **once** and the same bytes go to either transport.
Attachments, inline `cid:` images, `Reply-To`, `Cc`/`Bcc`, custom headers and encoding are
all handled by WordPress core code rather than reassembled by hand into a JSON object —
which is where mailers that do reassemble them accumulate a long tail of "the attachment
vanished" reports.

Adding a transport means implementing three methods, not re-solving MIME.

## Requirements

- WordPress 6.5+, PHP 8.0+
- Microsoft path: a Microsoft 365 / Entra **work or school** tenant. Personal
  `outlook.com` accounts have no tenant and cannot use app-only auth.
- Google service-account path: a Google Workspace domain.

## Setup

Credentials belong in `wp-config.php`, which keeps them out of database dumps:

```php
define( 'MMOA_MS_TENANT_ID',     '...' );
define( 'MMOA_MS_CLIENT_ID',     '...' );
define( 'MMOA_MS_CLIENT_SECRET', '...' );
define( 'MMOA_MS_SENDER',        'noreply@yourdomain.com' );
```

Anything stored through the settings screen instead is encrypted with libsodium.

> **Scope the Entra app before using it.** The `Mail.Send` *application* permission lets
> the app send as **any** mailbox in the tenant until you restrict it:
>
> ```powershell
> New-ApplicationAccessPolicy -AppId <client-id> `
>   -PolicyScopeGroupId wp-senders@yourdomain.com -AccessRight RestrictAccess
> ```

## Tests

109 assertions across 5 files. Every outbound call is stubbed through WordPress's
`pre_http_request` filter, so the suite needs no credentials and sends nothing.

```bash
cd tests && ./run.sh
```

The plugin must sit in `wp-content/plugins/` of a working WordPress install. For LocalWP
or MAMP, point it at the right binary and socket:

```bash
PHP="/path/to/php" MYSQL_SOCK="/path/to/mysqld.sock" MMOA_TEST_HOST="mysite.local" ./run.sh
```

## Status

| | |
|---|---|
| Microsoft Graph, app-only | works, tested |
| Google Workspace service account | works, tested |
| Gmail consumer OAuth | sends and refreshes, but **the connect flow is not built** — the refresh token must be injected manually |
| Retry queue | **not built** — the highest priority |
| Large attachments | ~2 MB ceiling; enforced before sending. Messages are base64-encoded twice on this path, so the usable payload is about half the API limit. Chunked upload is planned |
| Setup wizard, i18n, Plugin Check | not done |

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
