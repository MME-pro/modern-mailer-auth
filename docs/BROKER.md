# The OAuth broker

One-click setup needs a service you run. This document is its specification: the
plugin side is finished and calls exactly these four routes, so a service that
implements them makes one-click work with no further changes here.

## Why a service is unavoidable

An OAuth client secret cannot ship inside a plugin — anyone who installs it can
read it out of the source. So for a site to connect a mailbox without registering
its own OAuth client, somebody must hold a shared client secret and perform the
code exchange on the site's behalf. That is the whole job.

## What it is not

It is not a mail relay, and this is the design decision worth defending.

WP Mail SMTP's Gmail integration takes the other road. Its
`Pro/Providers/Gmail/Api/Client.php` sends the message itself:

```php
const API_BASE_URL = 'https://api.wpmailsmtp.com/gmail/v1/';

public function send_email( $message, $allow_queue = true ) {
    return $this->request( 'endpoints/send-email/', [ 'message' => $message, ... ], 'POST' );
}
```

The site never holds a Google token; the message body is POSTed to their servers
and they send it on. That makes the vendor a processor of every customer's email,
puts message content on a third-party host, and means an outage of that service
stops all mail rather than merely stopping new connections.

Their own Outlook integration is the shape used here: the service brokers the
OAuth handshake and returns real Microsoft tokens, and mail then goes straight
from the site to `graph.microsoft.com`.

So: **the broker sees tokens, never messages.** If it goes down, nobody can
connect a new account and tokens cannot be refreshed, but a site holding a valid
access token keeps sending, and any connection using its own OAuth client is
unaffected entirely. The test suite asserts this — `test-one-click.php` fails if
a message body ever reaches a broker host.

## Configuring the plugin

Default base URL is `https://api.modernmailer.app/oauth/v1/`. Override with a
constant, or filter it to `''` to switch one-click off entirely and offer only
the own-client path:

```php
define( 'MMOA_BROKER_URL', 'https://api.example.com/oauth/v1/' );
add_filter( 'mmoa_broker_url', fn() => '' ); // disable
```

Families are `google` and `microsoft`. Routes below are relative to the base URL.

## Scopes to request

| Family | Scope |
|---|---|
| `google` | `https://www.googleapis.com/auth/gmail.send` |
| `microsoft` | `https://graph.microsoft.com/Mail.Send`, `offline_access`, `User.Read` |

`User.Read` is only so `/me` can report which mailbox connected. Request nothing
wider: the plugin never reads mail, and a read scope on the consent screen costs
you conversions and raises the verification bar.

For Google, `access_type=offline` and `prompt=consent` are both load-bearing.
Without the first you get an access token that dies in an hour; without the
second Google omits the refresh token on every authorization after the first, so
reconnecting an account appears to succeed and then fails at the next refresh.

---

## `GET {family}/authorize`

The browser is sent here. Query parameters:

| Parameter | Meaning |
|---|---|
| `site_id` | Opaque, stable, random per site |
| `site_url` | `home_url()`, for display and support only |
| `callback` | Where to return the browser — always `admin-post.php?action=mmoa_one_click_callback` |
| `state` | Opaque; echo it back untouched |
| `version` | Plugin version |

Redirect to the provider's consent screen using **your** client ID and **your**
registered redirect URI. That is what spares every site from registering one.

Keep `callback`, `state` and `site_id` against your own short-lived record keyed
by the state you send the provider.

**Validate `callback` before storing it.** It must be an `admin-post.php` URL
under the `site_url` given. Without that check it is an open redirect, and worse,
a way to have a handoff delivered to an attacker's host.

### Returning the browser

After the provider redirects to you and you exchange the code for tokens, send
the browser back to `callback` with:

```
{callback}&state={state}&handoff={one-time code}
```

or, on failure:

```
{callback}&state={state}&error=access_denied&error_description=...
```

**Do not put tokens in that URL.** They would land in the site's access log, the
admin's browser history, and any Referer header the next page sends. The handoff
is a short-lived, single-use code; the tokens come back over the POST below,
which nothing else can observe.

Suggested handoff lifetime: 5 minutes, one use.

---

## `POST {family}/claim`

Every POST body below is JSON and always carries `site_id`, `site_url` and
`version`. This one adds `handoff`.

Return `200` with:

```json
{
  "access_token": "...",
  "refresh_token": "...",
  "expires_in": 3599,
  "email": "someone@gmail.com"
}
```

`email` is optional but worth returning — it is what lets the connection screen
say *Connected as someone@gmail.com* rather than just *Connected*.

A response without `refresh_token` is rejected by the plugin rather than stored,
because it would work for an hour and then stop. Fail loudly at your end too if
the provider withholds one.

The handoff must be single-use. A second claim on the same code should return
`410`.

---

## `POST {family}/refresh`

Body adds `refresh_token`. Return `200` with:

```json
{ "access_token": "...", "expires_in": 3599, "refresh_token": "..." }
```

Include `refresh_token` **only when it changed**. Microsoft rotates on every use
and retires the old one; the plugin stores the replacement when present and keeps
what it has when absent. Dropping a rotated token is a connection that works
until the current window closes and then dies with nothing to explain it.

This is the hot route — it runs whenever a site's cached access token expires,
so it should be cheap and it must be reliable.

---

## `POST {family}/revoke`

Body adds `refresh_token`. Revoke at the provider and forget your record. Any
`200` is success.

The plugin forgets the credential locally *before* calling this and reports a
failure here as "disconnected here, but the service could not be told". An admin
who asked to disconnect ends up disconnected regardless of your availability.

---

## Errors

Non-200 responses should carry:

```json
{ "error": "machine_code", "message": "Something an admin can act on." }
```

`message` is shown to the admin verbatim when present, so write it for them: name
what they must do, or say plainly that the fault is yours. Without one, the
plugin falls back on status-based wording — `404`/`410` as expired, `401`/`403`
as refused, `429` as rate limited, `5xx` as an outage that leaves existing
connections sending.

---

## What you are taking on

Worth being clear-eyed about before building it:

- **Google verification.** `gmail.send` is a restricted scope. A multi-tenant app
  needs OAuth verification plus an annual third-party CASA security assessment —
  a real recurring cost, renewed yearly, not a form.
- **Microsoft.** A multi-tenant Azure app plus publisher verification. Some
  tenants will require administrator consent before any of their users can grant.
- **Availability.** Every brokered site refreshes tokens through you roughly
  hourly. You are not on the send path, which is the point — but you are on the
  path to *keeping* sites able to send.
- **Custody.** You hold refresh tokens for other people's mailboxes. Encrypt at
  rest, key them to `site_id`, and make revocation genuinely revoke.
- **GDPR.** Tokens and email addresses are personal data, so a DPA with customers
  is needed. Note this is *much* narrower than the proxy model, where message
  bodies would make you a processor of the mail itself.

## Keeping the own-client path

Do not remove it. It is the default, it depends on nothing you run, and it is the
answer for any customer whose policy forbids a third party holding their grant.
One-click is a convenience layered over it, not a replacement.

For Microsoft the two paths are genuinely different providers, not two ways to
configure one:

- **Microsoft 365** (`graph`) is app-only. An administrator registers an Azure
  app, grants `Mail.Send` application permission, and the site mints tokens from
  a client credential. Nothing expires but the secret, and it can send as any
  mailbox in the tenant.
- **Outlook** (`outlook`) is delegated and brokered. It sends only as the
  signed-in account, holds a refresh token, needs no Azure registration at all,
  and works for personal accounts that have no tenant.

Neither replaces the other, which is why both are listed.
