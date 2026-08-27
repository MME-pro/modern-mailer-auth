# The setup service

The server side of one-click setup. Plain PHP and MySQL, built to run on
ordinary shared hosting.

It exists for one reason: an OAuth client secret cannot ship inside a plugin,
because anyone who installs it can read it. So for a site to connect Gmail
without registering its own Google Cloud project, some server has to hold a
shared secret and perform the code exchange. This is that server.

## What it does not do

**It never sees an email.** It returns real Google and Microsoft credentials to
the site, and every message afterwards goes straight from the site to Gmail or
Graph.

The other way to build this — proxying the message itself — is what WP Mail
SMTP's Gmail integration does: `send_email()` there POSTs the message body to
`api.wpmailsmtp.com` and their servers send it on. That would put your
customers' mail on this host, make you a processor of it under GDPR, and mean an
outage here stops all mail rather than only stopping new connections.

**It stores no tokens.** There is no table of grants. The site keeps its refresh
token and sends it back when it needs a new access token. A credential exists
here only in the few minutes between someone approving at Google and the site
collecting it — encrypted for that window, deleted on use. A breach here means
some people sign in again; it does not mean every customer's mailbox.

## Check it before you trust it

Every deployment step below can be verified from one URL:

```
https://api.yourdomain.com/oauth/v1/health
```

```json
{
  "ready": true,
  "php": "8.2.29",
  "missing_extensions": [],
  "missing_settings": [],
  "providers": { "google": "configured", "microsoft": "not configured..." },
  "database": "ok",
  "config_file": "/home/you/.env.broker",
  "redirect_uris": {
    "google": "https://api.yourdomain.com/oauth/v1/callback/google",
    "microsoft": "https://api.yourdomain.com/oauth/v1/callback/microsoft"
  }
}
```

It reports setting *names*, never values. `redirect_uris` is echoed so you can
paste those exact strings into Google and Azure — a mismatch shows up here
rather than as `redirect_uri_mismatch` an hour later. `config_file` says which
file was actually read, because putting it one directory off looks identical to
having filled in nothing at all.

One provider is a valid deployment. Google configured and Microsoft blank still
reports `"ready": true`; only Microsoft one-click is unavailable.

## Install

**1. Database**

```sql
CREATE DATABASE broker CHARACTER SET utf8mb4;
```

Then load `schema.sql`. Two tables, both of things that expire within the hour.

**2. Files**

Upload so that `broker/public/` is the document root of your subdomain, and
`broker/src/` sits *above* it. If your host cannot move the document root, the
included `broker/.htaccess` denies everything outside `public/` — but moving the
root is better.

**3. Configuration**

Copy `.env.broker.example` to `.env.broker` **outside the document root**, one
level above `broker/`. Real environment variables win over the file, so a host's
control panel works too.

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # BROKER_KEY
```

`BROKER_BASE_URL` builds the redirect URI you register below, so it must match
character for character.

**4. Google**

<https://console.cloud.google.com/> → new project → enable the **Gmail API** →
OAuth consent screen (External, published) → Credentials → OAuth client ID →
**Web application** → authorised redirect URI:

```
https://api.yourdomain.com/oauth/v1/callback/google
```

Requested scope is `gmail.send` only. That is a **restricted** scope: a
multi-tenant app needs OAuth verification plus an annual third-party CASA
security assessment. Budget for it — it recurs yearly, and until it passes your
consent screen shows an unverified-app warning and is capped at 100 users.

**5. Microsoft**

Azure Portal → App registrations → new, **Accounts in any organizational
directory and personal Microsoft accounts** → Redirect URI, type Web:

```
https://api.yourdomain.com/oauth/v1/callback/microsoft
```

Certificates & secrets → new client secret. Delegated permissions:
`Mail.Send`, `User.Read`, `offline_access`. Complete publisher verification, or
tenants will see an unverified publisher on the consent screen.

**6. Point the plugin at it**

In `includes/auth/class-broker.php`:

```php
private const DEFAULT_URL = 'https://api.yourdomain.com/oauth/v1/';
```

Or per site, without touching the plugin:

```php
define( 'MMOA_BROKER_URL', 'https://api.yourdomain.com/oauth/v1/' );
```

## Routes

| Route | Called by | Does |
|---|---|---|
| `GET {family}/authorize` | the site, as a redirect | Records the request, sends the browser to the provider |
| `GET callback/{family}` | Google / Microsoft | Exchanges the code, parks the tokens behind a one-time handoff |
| `POST {family}/claim` | the site | Trades the handoff for the tokens. One use |
| `POST {family}/refresh` | the site | Mints a new access token. The hot route — roughly hourly per connection |
| `POST {family}/revoke` | the site | Revokes at the provider |

`{family}` is `google` or `microsoft`.

## Things that are load-bearing

**`access_type=offline` and `prompt=consent`** on Google. Without the first there
is no refresh token at all; without the second Google omits one on every
authorization after the first, so reconnecting an account appears to succeed and
then fails at the next refresh. Microsoft's equivalent is `offline_access`.

**Microsoft rotates the refresh token on every use** and retires the old one.
`refresh` passes the replacement back, and the plugin stores it. Dropping it
means a connection that works until the current window closes and then dies with
nothing to explain it.

**The callback is validated, not trusted.** `authorize` checks that the return
address is on the same host as the site that asked, is `admin-post.php`, and
carries the expected action. Without that check anyone could point a callback at
a host they control and have a handoff for your OAuth client delivered to them —
an open redirect that hands over tokens rather than merely traffic.

**Tokens never travel in a URL.** The redirect back to the site carries a
single-use code, not credentials, because anything in a redirect lands in the
site's access log, the administrator's browser history, and whatever `Referer`
the next page sends.

**A wrong guess does not consume a handoff.** Looking one up with the wrong site
leaves it alone; only a genuine match deletes it. Deleting on every lookup made
the row destroyable by anyone who could name the code but not its owner.

## Checking it works

```bash
curl -s -o /dev/null -w '%{http_code}\n' \
  'https://api.yourdomain.com/oauth/v1/google/authorize'          # 400, parameters missing
curl -s -o /dev/null -w '%{http_code}\n' \
  'https://api.yourdomain.com/oauth/v1/facebook/authorize'        # 404
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"site_id":"x","handoff":"nope"}' \
  'https://api.yourdomain.com/oauth/v1/google/claim'              # 410
```

A `500` with `misconfigured` means the configuration or database is wrong; the
detail is in the PHP error log, deliberately not in the response, because it
names database hosts and configuration keys.

## Requirements

PHP 8.1 or newer with `pdo_mysql`, `curl` and `sodium`. No Composer, no
dependencies.
