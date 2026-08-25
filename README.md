# Modern Mailer

A WordPress plugin that sends mail through the **Microsoft Graph** and **Gmail** APIs
using OAuth 2.0 — no mailbox password, and nothing that quietly expires.

> **Status: early.** All three providers, the backup connection, the retry queue and the
> Gmail sign-in flow are built and covered by 195 passing assertions — but **none of it has
> been run against a live Microsoft or Google endpoint yet**, and large attachments are
> still capped at ~2 MB. See [Status](#status) and [docs/STATUS.md](docs/STATUS.md).

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

Follow-up log analysis on that site sharpened the picture. The token request was failing
with `cURL error 7 ... after 1 ms` — refused instantly, not timed out — recurring for
minutes at a stretch while the credentials were entirely correct. So the fault was the
host's outbound DNS, and every one of the lost emails was deliverable moments later.

Four defences against that are now in place:

1. **No URL is ever derived from a response body**, and a URL that would be empty is
   rejected by name rather than becoming a misleading transport error.
2. **Tokens are cached for their full lifetime**, so most sends never touch the identity
   endpoint at all — and if a refresh fails while the previous token is still valid, that
   token is used rather than losing the message.
3. **A backup connection** is tried immediately when the primary fails. Separate
   credentials and a separate endpoint, so it survives faults that are permanent for the
   primary.
4. **A persistent retry queue** holds anything that failed for a transient reason and
   retries it across later requests, backing off from five minutes over roughly two days.
   In-request retries span seconds; this is the only layer that outlives the outage that
   actually loses mail.

Failures that cannot be helped by any of that — an oversized attachment, a wrong client
secret — are never queued and never retried on the backup, because the answer would be the
same every time.

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

Everything lives under a top-level **Modern Mailer** menu: **Settings** (site options and
the primary connection), **Backup** (the fallback connection), **Logs** (the retry queue
and the send log).

Credentials belong in `wp-config.php`, which keeps them out of database dumps:

```php
define( 'MMOA_MS_TENANT_ID',     '...' );
define( 'MMOA_MS_CLIENT_ID',     '...' );
define( 'MMOA_MS_CLIENT_SECRET', '...' );
define( 'MMOA_MS_SENDER',        'noreply@yourdomain.com' );
```

The backup connection takes the same constants with a `BACKUP` infix, so a Google fallback
behind a Microsoft primary is:

```php
define( 'MMOA_BACKUP_PROVIDER',                    'gmail_sa' );
define( 'MMOA_BACKUP_GOOGLE_SA_CLIENT_EMAIL',      '...' );
define( 'MMOA_BACKUP_GOOGLE_SA_PRIVATE_KEY',       '...' );
define( 'MMOA_BACKUP_GOOGLE_SENDER',               'noreply@yourdomain.com' );
```

Pair *different* providers. A second Microsoft app registration in the same tenant shares
the identity endpoint, so it fails at the same moment as the primary and buys you nothing.

Anything stored through the admin screens instead is encrypted with libsodium.

### Connecting a consumer Gmail account

The Gmail path uses **your own** OAuth client — nothing is proxied through a shared
application, so the tokens are only ever seen by your site.

1. In your Google Cloud project, create a **Web application** OAuth client.
2. Add this exact redirect URI, which is the same for both connections:
   `https://yoursite.com/wp-admin/admin-post.php?action=mmoa_google_callback`
3. Publish the consent screen to **In production**. Left in Testing, Google expires the
   refresh token every seven days and sending stops without warning.
4. Paste the client ID and secret into Modern Mailer, **save**, then press *Sign in with
   Google*.

Google refuses non-HTTPS redirect URIs except for `localhost`, so this cannot be completed
on a plain-HTTP staging domain.

> **Scope the Entra app before using it.** The `Mail.Send` *application* permission lets
> the app send as **any** mailbox in the tenant until you restrict it:
>
> ```powershell
> New-ApplicationAccessPolicy -AppId <client-id> `
>   -PolicyScopeGroupId wp-senders@yourdomain.com -AccessRight RestrictAccess
> ```

## Tests

195 assertions across 7 files. Every outbound call is stubbed through WordPress's
`pre_http_request` filter, so the suite needs no credentials and sends nothing.

```bash
cd tests && ./run.sh
```

| File | Covers |
|---|---|
| `test-graph.php` | Microsoft Graph: token flow, send shape, AADSTS error mapping |
| `test-failures.php` | Mid-life 401 recovery, error message quality, alerting |
| `test-gmail.php` | Both Google paths, JWT signing, base64url correctness |
| `test-resilience.php` | Stale-token grace, backup connection, retry queue |
| `test-google-consent.php` | Gmail sign-in: authorization URL, callback, state replay, disconnect |
| `test-regression-wpms.php` | The WP Mail SMTP "no valid URL" failure, and that throttled mail is kept |
| `test-final.php` | End-to-end wp_mail() behaviour, all three admin screens, redirect-URI stability |

The plugin must sit in `wp-content/plugins/` of a working WordPress install. For LocalWP
or MAMP, point it at the right binary and socket:

```bash
PHP="/path/to/php" MYSQL_SOCK="/path/to/mysqld.sock" MMOA_TEST_HOST="mysite.local" ./run.sh
```

On **Windows** LocalWP there is no socket, and the CLI binary loads no extensions by
default, so pass the port and the extensions explicitly. Find the port in
`%APPDATA%\Local\sites.json` under `services.mysql.ports.MYSQL`:

```bash
PHPDIR="$APPDATA/Local/lightning-services/php-8.2.29+0/bin/win64"
MMOA_TEST_HOST=mysite.local "$PHPDIR/php.exe" \
  -d extension_dir="$PHPDIR/ext" \
  -d extension=php_mysqli.dll -d extension=php_openssl.dll -d extension=php_sodium.dll \
  -d mysqli.default_port=10013 \
  test-resilience.php
```

## Status

Full detail, including known gaps and what is deliberately out of scope, is in
[docs/STATUS.md](docs/STATUS.md).

| | |
|---|---|
| Microsoft Graph, app-only | works, tested |
| Google Workspace service account | works, tested |
| Gmail consumer OAuth | works, tested — sign-in prompt, own OAuth client, revocable |
| Backup connection | works, tested |
| Retry queue | works, tested |
| Large attachments | ~2 MB ceiling, enforced before sending. Messages are base64-encoded twice on this path, so the usable payload is about half the API limit. Chunked upload not built |
| Microsoft certificate credential | not built — `MMOA_MS_CERTIFICATE` is reserved but unread |
| Setup wizard, i18n, Plugin Check | not done |

## Releasing

Pushing a `v*` tag builds the installable zip in GitHub Actions and publishes it
as a release; sites find it themselves through the built-in update checker, so
publishing the release is the deploy. The exact commands, including the version
bump, are in [docs/RELEASING.md](docs/RELEASING.md).

```bash
git add -A && git commit -m "Release 0.4.3 - ..." && git push origin main
git tag v0.4.3 && git push origin v0.4.3
```

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
