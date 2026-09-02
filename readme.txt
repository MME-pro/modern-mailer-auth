=== MME-Mail to SMTP ===
Contributors: builtwithmtw
Tags: smtp, wp_mail, microsoft 365, gmail, oauth
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.10.0
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

= 0.10.0 =
* The plugin is now called MME-Mail to SMTP. Only the name changes - your connections, credentials and logs are untouched, and the entry in the Plugins list simply reads differently after the update.
* Added Zoho Mail as a connection. Choose the region your Zoho account belongs to, enter the address and an app password, and the server and port are set for you. The region matters: Zoho runs a separate mail system per data centre, and an account created on zoho.eu cannot sign in to the American server - which fails looking exactly like a wrong password.
* New look, taken from the MME-pro house colours. Same screens in the same places; the header band, buttons, tabs and charts now carry the brand rather than a generic blue.
* The screens now credit MME-pro in a footer, with the version and licence beside it - which is also the quickest thing to screenshot when reporting a problem.

= 0.9.1 =
* HTML email now arrives as HTML. A message whose body is HTML but that never said so was delivered with its tags showing - which is most theme functions, form plugins and hand-built templates, because WordPress sends plain text unless a Content-Type header is added. Such a message is now sent as HTML, with a readable plain-text version alongside it for the clients that want one.
* Fixed HTML email going out as plain text through SendGrid, Resend, Brevo, Postmark and SMTP2GO. This hit ordinary, correctly formed HTML mail - anything WooCommerce, WordPress itself or a form plugin sends - and the recipient saw the raw markup. The Microsoft, Google and SMTP connections were never affected.

= 0.9.0 =
* German translation, complete. The plugin follows your WordPress language, including each user's own profile setting, and reads as part of the admin rather than something bolted onto it.
* The From address and From name now belong to each connection instead of the whole site. Two connections normally authenticate as different mailboxes, and every provider here refuses or rewrites a From address the authenticated identity may not use - so one shared value meant a backup could only work by coincidence. Your existing address is copied to every connection on update; nothing to redo.
* Saved credentials are no longer shown in plain text. There is a copy button instead, so a key can be checked against the provider's own console without appearing on a screen that might be shared.
* Every connection now has a Disconnect button. It clears the provider and its credentials, and withdraws any account the connection is signed in to rather than merely forgetting it.

* Upgrade note: if you set MMOA_FROM_EMAIL or MMOA_FROM_NAME in wp-config.php, those constants now pin the primary connection. Additional connections take their own address from the settings screen.

= 0.8.6 =
* Click any row in the send log to see the full story of that attempt: WordPress and PHP versions, the connection settings that were in force, the server environment, the error, and - for SMTP - the entire conversation with the mail server. There is a button to copy the lot as text.
* Credentials never appear in a report. A stored password is listed as set, never shown, and the SMTP transcript is captured at a level where PHPMailer masks the login exchange itself.
* Reports are kept for failures only, so the log does not grow larger than the mail it describes. Entries recorded before this release have none.
* Sending a test message no longer falls back to the backup connection, and is no longer queued. A test exists to say whether the primary works, and both of those could report success while it was broken.
* SendGrid, Resend, Brevo and Other SMTP are available again. Postmark, Mailgun and SMTP2GO remain marked as coming soon.
* API keys are shown in the connection form, masked, with a button to reveal them - so a key that was pasted with a character missing can be spotted without sending a message to find out.
* Fixed the band of empty space that appeared under every provider tile.

= 0.8.5 =
* The connection chooser is now in a set order: Microsoft, Google, SendGrid, Resend, Brevo, Postmark, Mailgun, SMTP2GO, Other SMTP.

= 0.8.4 =
* The connection chooser now offers Microsoft and Google. The rest are shown as coming soon while they are finished.
* A connection already sending through one of those is untouched: it keeps working, keeps its settings, and stays editable. Nothing that was delivering mail stops delivering it.
* Fixed a misleading error on Outlook. Sending with a From address that is not the mailbox you signed in with was reported as "an administrator may have restricted it", which is the wrong place to look. It now names both addresses and tells you which one to change.
* Verify catches that mismatch too, so it surfaces before the first message fails rather than after.

= 0.8.3 =
* One-click setup now works. The setup service it needs is live, so Sign in with Google connects a mailbox without anyone opening the Google Cloud console. Nothing to configure: the address ships with the plugin.
* Your mail still goes directly from your site to Gmail. The setup service performs the sign-in and never sees a message.
* Prefer to depend on nothing of ours? Choose My own OAuth client, or a service account, exactly as before. Both are untouched by this.
* One-click for Microsoft is not available yet and says so; Microsoft 365 with your own Azure application is unaffected.

= 0.8.2 =
* Every sign-in and disconnect button works again. All of them failed with "The link you followed has expired", however fresh the page was.
* The links are built for the admin app, which receives them as data and sets them as a link directly. They were being escaped for HTML instead, so the browser sent the escaped text verbatim and the security nonce arrived under the wrong name - which WordPress reports as an expired link, blaming the one thing that was not wrong.

= 0.8.1 =
* One-click setup is visible again. 0.8.0 shipped it hidden, because no setup service exists yet to answer it - but hiding a finished feature to avoid a bad error message was the wrong trade. Sign in with Google and Sign in with Microsoft are both on the connection screen.
* Pressing either without a setup service now says so plainly, and says it before leaving the site rather than after a browser error page on a host that cannot exist. Define MMOA_BROKER_URL with the address of your service to switch it on.

= 0.8.0 =
* Microsoft 365 and Outlook are now one Microsoft provider, and Google Workspace and Gmail one Google provider. The chooser listed authentication methods, which asked you to decide how to authenticate before deciding where to send. Each tile now asks how to connect once you have picked the service. Existing connections are migrated and keep sending exactly as before.
* Added one-click setup for Google and Microsoft, which obtains the credential without anyone opening a cloud console. It needs a hosted setup service, which does not exist yet, so it is switched off and hidden until MMOA_BROKER_URL points at one. Nothing else is affected.
* Added Outlook as a delegated Microsoft transport - sends as the signed-in mailbox, needs nothing registered in Azure, and works for personal accounts with no tenant. Reached through the Microsoft provider.
* Signing in from a connection other than the primary or backup used to store the credential against the primary, overwriting a working one and leaving the intended connection reporting itself disconnected however many times you tried.
* Credentials belonging to a setup method you are not using are now hidden rather than greyed out. Two dead boxes under a one-click choice read as something still to fill in.

= 0.7.4 =
* Verifying a Microsoft 365 connection now names the permission that is missing, instead of reporting whichever error Graph happened to return first. An app registration with no admin consent, or with everything granted except Mail.Send, is told exactly what to add and where to add it.
* Verify no longer reports a clean pass when it could only check part of the connection. Where the sending mailbox itself could not be read - that needs a permission sending does not require, and this plugin does not ask for - it now says so instead of leaving it unmentioned.
* Connecting or verifying now points out required fields that are still empty, before anything is sent to the provider. A half-filled connection used to come back as an error from the provider rather than a note on the field that was missing.

= 0.7.3 =
* Updates now appear promptly. The check remembered its answer for six hours, so a release could sit unnoticed for most of a working day; it is now fifteen minutes, and a failed check is retried after five rather than thirty.
* Check Again on the Updates screen now really does check again. It was rebuilding WordPress own list while still reading the plugin cached answer, so clicking it changed nothing until the cache expired.
* Added an update entry to Tools, Site Health. A check that cannot reach GitHub used to look exactly like a check that found nothing new - the site simply never offered an update and nothing said why. It now reports whether the check is getting through, and names the usual causes when it is not.

= 0.7.2 =
* The plugin screens now use the full width of the admin area. They were capped at 1240px, which left a growing empty margin on wide monitors while the email log and the provider grid were the things that wanted the room.

= 0.7.1 =
* Clicking a tab no longer leaves a pale outline behind it. The ring was firing on plain focus, which a mouse click triggers as well as the Tab key; it now appears only for keyboard focus, where it is needed.

= 0.7.0 =
* Reworked the interface palette and typography. Cool neutrals with a single indigo accent, and Plus Jakarta Sans with Inter in place of the previous warm paper-and-serif treatment, which read as somebody else's brand rather than as a dashboard.
* Added Brevo, SMTP2GO and Resend, bringing the provider list to ten.
* Every provider now shows its own logo. The marks are drawn inline rather than loaded from the provider, so the screen makes no third-party request and nothing breaks if one is unreachable.
* The provider chooser is now a single wrapping row instead of three grouped sections - with ten providers, one field of marks is quicker to search than three headings and three grids.

= 0.6.2 =
* Fixed the admin styling actually reaching the page. WordPress ships plain element rules that are unlayered, and an unlayered rule beats a layered one whatever its specificity - so every heading size, heading colour and link colour in the app was silently losing to WordPress. The tabs were rendering in WordPress blue and the wordmark near-black on the dark band.
* WordPress admin notices no longer land in the middle of the plugin header. They were being relocated to just after the first heading inside the page, which was the wordmark.
* Confirmations now appear at the top right, clear of the admin bar.
* The activity chart has a proper empty state instead of a blank panel.

= 0.6.1 =
* Redesigned the dark theme. It was the light palette inverted rather than designed - cards barely separated from the canvas, the header band was darker than the page and disappeared, and oxblood lightened into pink. It is now built as the same room at night: elevation from lightness rather than shadow, chrome lighter than the canvas, and the semantic colours held to garnet and jade instead of drifting to pink and mint.
* Dark can now actually be chosen. There is a toggle in the header, remembered per browser; before this the dark styles existed but nothing ever applied them.
* Rebuilt the tabs around a single brass rule that travels between them rather than five that take turns appearing, measured from the labels so it stays correct in any translation.

= 0.6.0 =
* Gave the admin a visual identity. Paper canvas, ink chrome and a single brass accent, with Fraunces for display type and Inter Tight for everything else - a private bank's own collateral rather than a default admin template.
* The dashboard leads with one figure at display size rather than four equal cards, so the screen says what it is about before you read a word.
* Added an engine-turned guilloche - the rosette engraved on banknotes and share certificates - behind the header band and, faintly, behind the chart. It appears exactly twice.
* Colour now means one thing each: brass is the only decorative accent, and oxblood and malachite appear on failure and delivery and nowhere else.

= 0.5.2 =
* The MME-Mail to SMTP menu no longer has submenus. It opens straight onto the app, which carries its own tabs - Dashboard, Connections, Routing, Email Logs and Settings - so there is one navigation to follow rather than two that had to agree with each other.

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
* Moved to a top-level MME-Mail to SMTP menu with three screens: Settings, Backup and Logs. The Logs entry carries a count when the retry queue is holding anything.
* The Google OAuth redirect URI is now `admin-post.php?action=mmoa_google_callback`, which does not change if the admin menu is ever reorganised.
* Added a backup connection. When the primary fails, the message is retried immediately against a second, independently configured provider.
* Added a retry queue. A send that fails for a transient reason - a network or DNS fault at the host, a throttle, a 5xx - is held and retried across later requests instead of being lost. Attempts back off from five minutes and stop after about two days.
* A failed token refresh now falls back to the cached token while it is still genuinely valid, so a brief outage at the identity endpoint no longer costs an email.
* Added the Gmail sign-in flow. Enter your own OAuth client ID and secret, connect the account through Google's consent prompt, and revoke it again from the same screen.
* Queued and abandoned mail is surfaced in Site Health and on the settings screen, so mail that was never delivered cannot pass unnoticed.

= 0.1.0 =
* Initial release: Microsoft Graph app-only, Google service account, and Gmail OAuth providers.
