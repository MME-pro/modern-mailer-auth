# Status

What is built, what works, what does not, and what is left. Updated at 0.2.0.

The short version: sending through Microsoft 365 and Google works and is covered by tests.
The resilience layer added in 0.2.0 — stale-token grace, a backup connection and a retry
queue — is complete and tested. The remaining gaps are large attachments, the Microsoft
certificate credential, and release chores.

**195 assertions, all passing.** Nothing in 0.2.0 has been exercised against a live
Microsoft or Google endpoint — see [Built but not verified](#built-but-not-working--not-verified).

---

## Admin layout

A top-level **MME-Mail to SMTP** menu with three screens:

| Screen | Slug | Holds |
|---|---|---|
| Settings | `modern-mailer-oauth` | From address, force-sender, the **primary** connection, retry-queue toggle, logging and alert options, verify + test-email buttons |
| Backup | `modern-mailer-backup` | The **backup** connection and its verify button. Warns if no primary exists, or if both slots use the same provider |
| Logs | `modern-mailer-logs` | The retry queue (with Retry now / Return abandoned / Discard controls) above the send log. Carries a count bubble in the menu when the queue holds anything |

Every form carries a hidden `return_page`, so an action taken on Backup or Logs returns
there rather than dumping the admin on Settings.

The **OAuth redirect URI deliberately does not live under any of these screens.** It is
`admin-post.php?action=mmoa_google_callback`. Google matches that string character for
character against what the admin registered by hand, so tying it to a menu page would mean
that reorganising the admin silently breaks every existing Google connection, with an error
message that names the URI rather than the rename that caused it.

---

## Working, and covered by tests

| Feature | Where | Notes |
|---|---|---|
| Microsoft Graph, app-only | `providers/class-graph.php` | Client-credentials grant. No refresh token exists, so nothing expires except the client secret, and that is warned about ahead of time. |
| Google Workspace service account | `providers/class-gmail-service-account.php` | Domain-wide delegation, RS256 assertion signed locally. |
| Gmail consumer OAuth | `providers/class-gmail-oauth.php`, `auth/class-google-consent.php` | Sign-in prompt using **your own** OAuth client. Revocable from the settings screen. |
| One MIME pipeline | `class-mail-catcher.php` | PHPMailer builds the message; the same bytes go to either API. Attachments, inline `cid:` images, Cc/Bcc and custom headers are core's work, not ours. |
| Loud failures | `class-mail-catcher.php`, `class-health-monitor.php` | `wp_mail()` returns honestly, `wp_mail_failed` fires, repeated failures raise an admin notice, a Site Health warning and the `mmoa_send_failing` action. |
| Actionable error messages | `map_error()` / `map_aad_error()` in each provider | Named misconfigurations, not echoed API bodies. |
| Stale-token grace | `class-token-store.php`, `providers/abstract-provider.php` | A failed refresh falls back to the cached token while it is still genuinely valid. |
| Backup connection | `class-dispatcher.php`, `class-settings.php`, `class-secrets.php` | Second independent provider, tried immediately when the primary fails. |
| Retry queue | `class-queue.php` | Transient failures are held and retried across later requests. |
| Failure classification | `class-failure.php` | Decides what is worth retrying, and where. |
| Oversize rejection | `class-dispatcher.php` | Caught before the wire, with a message naming the real limit. |
| Encrypted credential storage | `class-secrets.php` | libsodium, keyed from `AUTH_KEY`/`SECURE_AUTH_SALT`. `wp-config.php` constants win over the database. |
| Three-screen admin menu | `admin/class-admin-page.php`, `admin/views/` | Settings / Backup / Logs, with a queue count bubble. Rendering of all three is asserted. |

## Built but NOT working / NOT verified

| Thing | State |
|---|---|
| **One-click setup** | Complete on the plugin side and covered by 49 assertions - authorize URL, handoff claim, refresh with token rotation, revoke, per-connection isolation, and the guarantee that no message body ever reaches the broker. **Shipped switched off**, because the broker service does not exist yet: `Broker::DEFAULT_URL` is empty, so `is_available()` is false and the affordance is hidden everywhere. A button that is present and always fails reads as the plugin being broken. Point `MMOA_BROKER_URL` at a running service to switch it on. See [BROKER.md](BROKER.md) for the API it must implement. |
| **Outlook provider** | Delegated Microsoft sending (`/me/sendMail`), reached through the Microsoft provider as its one-click mode. Hidden along with one-click while no broker exists - the registry drops a provider that declares itself unavailable, so it cannot be selected and then fail. It has no own-client path by design: Microsoft 365 already is that path, and being app-only it is the better choice for a shared address anyway. |
| **Live-site verification of 0.2.0** | Everything in 0.2.0 is verified by the test suite against stubbed HTTP. **None of it has been exercised against a real Microsoft or Google endpoint.** The Gmail sign-in flow in particular has never round-tripped through the actual consent screen. |
| **Queue table on an upgraded site** | `activate()` creates it on fresh installs and `maybe_upgrade()` creates it on any admin page load. A site that upgrades and then never loads wp-admin before a send will have `enqueue()` fail — it returns `false` and the send reports the original error, so nothing is silently swallowed, but nothing is queued either. |
| **`ms_certificate`** | The key exists in `Secrets` and the `MMOA_MS_CERTIFICATE` constant is mapped, but **nothing reads it.** Setting it does nothing. Certificate credentials are not implemented. |
| **Gmail OAuth on a non-HTTPS host** | Google refuses non-HTTPS redirect URIs except for `localhost`. On a plain-HTTP staging domain the sign-in cannot complete. Nothing in the plugin detects this and warns first. |
| **Menu position** | The top-level menu is registered at position 80. If another plugin claims the same slot WordPress silently overwrites one of them. Not yet defended against. |

## Not built

| Thing | Priority | Note |
|---|---|---|
| Chunked upload for large attachments | High | ~2 MB effective ceiling today. Both APIs cap a request at 4–5 MB and a message on this path is base64-encoded twice, so usable payload is about half the nominal limit. Graph needs an upload session; Gmail needs resumable upload. |
| Microsoft certificate credential | Medium | Would remove the last expiring secret from the Microsoft path. `Jwt_Signer` already exists and does RS256, so most of the work is the assertion shape and the admin UI. |
| Queue depth cap | Medium | The queue is unbounded. A long outage on a high-volume site could grow the table considerably — each row holds a full message body. Nothing trims by count. |
| Digest for abandoned mail | Medium | `mmoa_queue_exhausted` fires and Site Health goes critical, but no email is sent listing what was lost. |
| Per-connection health | Low | `Health_Monitor` tracks the site as a whole. A primary that always fails and a backup that always works looks healthy, which it is for delivery but not for diagnosis. The send log distinguishes them; the health state does not. |
| i18n shipping | Low | Every string is wrapped and the text domain is right, but no `.pot` is generated and `languages/` is empty. |
| Plugin Check / WP.org review pass | Low | Not yet run. |
| Setup wizard | Low | Settings screen only. |

---

## Design decisions worth knowing before changing things

**A queued send reports success to `wp_mail()`.** Once a message is safely on the queue,
the caller has nothing left to do about it, so returning `false` would make
correctly-written plugins show users an error for mail that is about to arrive. The failure
is still recorded in the log and still counts against health — the queue is not allowed to
make breakage invisible, which is the whole failure mode this plugin exists to prevent.

**The queue stores complete message bodies.** It has to; you cannot resend what you did not
keep. This is a real departure from `Logger`, which never stores content on purpose. The
mitigations are structural: delivered rows are deleted immediately, abandoned rows after
seven days, and the table is dropped on uninstall. Retention is measured in minutes in the
healthy case rather than the 30 days the log keeps metadata.

**Providers do not know which connection they are.** `Settings` and `Secrets` carry a slot
prefix, so `Graph` asking for `ms_tenant_id` resolves to the primary or the backup purely
by which view it was constructed with. Adding the backup connection required **no changes
inside any provider**, and adding a third slot would need none either.

**`Settings::$cache` is static.** All slots read and write the one option row, so a
per-instance cache would let a write through the primary leave a backup view stale for the
rest of the request.

**Both connection slots share one OAuth redirect URI.** Google matches it exactly against
the client's registered list, so varying it per slot would force admins to register two and
break silently when they registered one. The slot travels in the `state` parameter instead.

**`access_type=offline` *and* `prompt=consent` are both load-bearing.** Without the first,
Google returns no refresh token and sending dies within the hour. Without the second, it
omits the refresh token on every authorization *after* the first, so reconnecting an account
appears to work and then fails at the next refresh. This is the single most common Gmail
integration bug and `test-google-consent.php` asserts both parameters.

**`add_query_arg()` does not encode values.** It expects pre-encoded input. The redirect
URI contains its own query string, so leaving it raw truncates the parameter and Google
rejects every sign-in with `redirect_uri_mismatch`. There is a test for this because it was
written wrong once.

---

## The failure this release was built for

From a real site's WP Mail SMTP logs — 90 failed sends out of 2,158, every one on the
Microsoft 365 mailer:

```
Mailer: Outlook
cURL error 7: Failed to connect to login.microsoftonline.com port 443
after 1 ms: Couldn't connect to server

Mailer: 365 / Outlook
http_request_failed: ["A valid URL was not provided."]
```

Read in order, the second error is fallout from the first: no token was obtained, so the
send URL was never built, and the mailer reported the empty URL instead of the connection
failure. That sent the admin hunting through firewall settings for what was actually a DNS
fault. **`after 1 ms` is the tell** — a firewall drop times out over seconds; an instant
refusal means the hostname resolved to something unroutable.

How 0.2.0 answers each part:

| Part of the failure | Answer |
|---|---|
| The misleading "no valid URL" | `Http::is_valid_url()` rejects it by name, blaming the earlier call. Predates 0.2.0. |
| Token endpoint unreachable while a valid token was held | Stale-token grace — the send never touches the network for auth. |
| Token endpoint unreachable with no token | Backup connection, then the queue. |
| The email was discarded | The queue keeps it and retries for ~2 days. |
| Nobody noticed for a year | Health, Site Health, admin notice, and `mmoa_queue_exhausted` for confirmed loss. |

Worth being precise about the limits: none of this fixes the host's DNS. It converts lost
mail into late mail, and makes the real cause legible. A sustained multi-day outage with no
backup connection configured still ends in abandoned messages — visible ones, but abandoned.

## Extension points

| Hook | Fires |
|---|---|
| `mmoa_before_send` | Before a message is handed to a provider. |
| `mmoa_send_failing` | Once, when consecutive failures cross the alert threshold. |
| `mmoa_backup_used` | The backup delivered what the primary could not. |
| `mmoa_token_refresh_degraded` | A refresh failed and the previous token was used. Sending works, but is one token lifetime from stopping. |
| `mmoa_queue_exhausted` | Messages were abandoned. Unambiguous data loss; page someone. |
