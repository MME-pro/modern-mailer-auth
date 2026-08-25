# Releasing

Publishing a release **is** the deploy. GitHub Actions builds the installable
zip, and every site running the plugin finds it through the built-in update
checker ([`includes/class-updater.php`](../includes/class-updater.php)) and
offers it on the Plugins screen. There is nothing to upload to a site by hand.

Repository: <https://github.com/MME-pro/modern-mailer-auth>

---

## Everyday work (no release)

Changes that are not ready to go out to sites just go to `main`. No tag, no
release, no site sees anything.

```bash
git add -A
git commit -m "Short summary of the change"
git push origin main
```

---

## Cutting a release

### 1. Bump the version in all four places

They must agree. The workflow refuses to publish when the tag and the plugin
header disagree, because a mismatch would offer every site an update it already
has, forever.

| File | Line |
|---|---|
| `modern-mailer-oauth.php` | ` * Version:           0.4.3` |
| `modern-mailer-oauth.php` | `const VERSION     = '0.4.3';` |
| `package.json` | `"version": "0.4.3",` |
| `readme.txt` | `Stable tag: 0.4.3` |

All four at once, replacing the old version with the new one:

```bash
OLD=0.4.2
NEW=0.4.3

sed -i "s/Version:           $OLD/Version:           $NEW/" modern-mailer-oauth.php
sed -i "s/const VERSION     = '$OLD'/const VERSION     = '$NEW'/" modern-mailer-oauth.php
sed -i "s/\"version\": \"$OLD\"/\"version\": \"$NEW\"/" package.json
sed -i "s/^Stable tag: $OLD/Stable tag: $NEW/" readme.txt

grep -n "$NEW" modern-mailer-oauth.php package.json readme.txt   # expect 4 lines
```

Pick the number the way the change deserves: `0.4.2 -> 0.4.3` for fixes and
small additions, `0.4.2 -> 0.5.0` for a new provider or a reworked screen.
Never reuse or lower a number — WordPress compares versions, so a site on a
higher number will never be offered a lower one.

### 2. Add a changelog entry

At the top of the `== Changelog ==` section in `readme.txt`, above the previous
version. This text is what an admin reads in the update details:

```
= 0.4.3 =
* What changed, in a sentence a site owner would understand.
```

### 3. Run the tests

```bash
cd tests && ./run.sh
```

On Windows LocalWP, see the invocation in [../README.md](../README.md#tests) —
the CLI binary loads no extensions by default.

### 4. Commit, push, tag

The tag is what triggers the release. Push the commit **first**, so the tag
points at a commit that is already on `main`:

```bash
git add -A
git commit -m "Release 0.4.3 - short summary"
git push origin main

git tag v0.4.3
git push origin v0.4.3
```

The tag is `v` + the version. `v0.4.3`, not `0.4.3`.

### 5. Watch it build

<https://github.com/MME-pro/modern-mailer-auth/actions>

It takes a couple of minutes: install dependencies, build the admin app, check
the tag against the header, pack the zip, publish the release.

### 6. Confirm the release exists

```bash
curl -s https://api.github.com/repos/MME-pro/modern-mailer-auth/releases/latest \
  | grep -E '"tag_name"|browser_download_url'
```

Expected: the new tag, and a `modern-mailer-oauth.zip` download URL.

That zip is now what every site will install.

---

## What a site does next

Nothing is pushed to a site. Each one checks GitHub on its own:

- The check runs in the admin and on cron, and the answer is cached for six
  hours — so a site can take up to six hours to notice a new release.
- To see it immediately on a given site: **Dashboard - Updates - Check again**.
- Sites with auto-updates enabled for this plugin update themselves on the next
  cron run. Everyone else clicks **Update now** on the Plugins screen.

A site still on a version older than 0.4.2 has no update checker in it. Install
the release zip on it once by hand (**Plugins - Add New - Upload Plugin**, then
Replace current with uploaded); from then on it updates itself.

---

## When something goes wrong

**The workflow failed on the version check.** The tag and the `Version:` header
disagree. Fix the header, then move the tag:

```bash
git tag -d v0.4.3
git push origin :refs/tags/v0.4.3     # delete the remote tag

# bump the header properly, commit, push, then:
git tag v0.4.3
git push origin v0.4.3
```

**A release went out broken.** Do not delete it and reuse the number — sites
that already updated would be stranded on it. Fix forward: bump to the next
version and cut a new release. Delete the bad release on GitHub afterwards if
you want it out of the list.

**Re-run a release without a new commit.** Actions - Release - Run workflow, and
give it the existing tag.

---

## Quick reference

```bash
# ordinary change
git add -A && git commit -m "..." && git push origin main

# release 0.4.3
# (bump the 4 version lines + changelog first)
git add -A && git commit -m "Release 0.4.3 - ..." && git push origin main
git tag v0.4.3 && git push origin v0.4.3
```
