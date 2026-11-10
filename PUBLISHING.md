# Publishing to the WordPress.org plugin directory

Everything you need from "zip is ready" to "plugin is live", including the SVN workflow you'll use for every release after the first.

---

## 0. Before you submit

| Check | Why |
|---|---|
| `Stable tag:` in `readme.txt` matches `Version:` in the plugin header | A mismatch is the single most common submission error |
| `Contributors:` lists your **WordPress.org username** (not GitHub) | The listing credits whoever is named here |
| `Tested up to:` reflects the current WordPress release | Outdated values are rejected by the checker |
| `License:` is GPL-compatible and a `LICENSE` file is included | GPL-2.0-or-later here |
| No minified code without source, no tracking, no hidden admin pages | Guideline 1, 7 and 8 |
| No site-specific configuration, credentials or IP addresses | Guideline 7 |
| Plugin Check reports **0 errors** | Not mandatory, but it is what reviewers run |

Run the checks locally first:

```bash
cd password-reset-guard
php -l password-reset-guard.php
php -l includes/class-pwrg-admin.php
./bin/build.sh                       # produces build/password-reset-guard-<version>.zip

# with WordPress + the Plugin Check plugin installed:
wp plugin check password-reset-guard
```

---

## 1. Submit the plugin

1. Sign in at **https://wordpress.org/plugins/developers/add/** (requires a WordPress.org account with 2FA enabled).
2. Upload the zip from `build/` — **not** the git archive. It must contain exactly one top-level folder named `password-reset-guard/`.
3. Enter the plugin name and a short description.
4. Submit. You get a confirmation email and a **ticket number** — keep it.

The slug is derived from the plugin name, not the zip. If you want the slug `password-reset-guard`, the submitted name must be **Password Reset Guard** (it is already claimed for you by this submission, first come first served).

---

## 2. The review

Human review. Typical wait is **2–12 weeks**, occasionally longer; there is no expedite.

While waiting:

- Watch the email address on the account and the **#pluginreview** Slack channel.
- Reviewers reply with a list of required changes. Fix them, bump the version, rebuild, reply with the new zip attached in the same ticket thread.
- Do not submit the same plugin twice — it restarts the queue.

What they check, in practice:

| Guideline | What it means here |
|---|---|
| 1 — GPL | `LICENSE` present, headers consistent ✔ |
| 4 — no hidden functionality | Admin must be reachable from the plugin's own screens; nothing silent ✔ |
| 7 — no external service without consent | Geolocation is opt-in and documented ✔ |
| 8 — no admin hijacking | No dashboard notices, no menu reordering ✔ |
| Security | Nonces, capability checks, prepared statements, escaping, `ABSPATH` guard ✔ |
| Direct database calls | Allowed where a table is genuinely needed; uninstall must clean up ✔ |

---

## 3. After approval — SVN access

Approval creates the repository:

```
https://plugins.svn.wordpress.org/password-reset-guard/
```

Your WordPress.org username gets commit rights. Typical layout:

```
password-reset-guard/
├── trunk/          ← the plugin files, exactly what ships
├── tags/
│   └── 2.0.1/      ← one directory per released version (Stable tag points here)
└── assets/         ← directory artwork ONLY (banners, icons, screenshots)
```

**Do not put screenshots in `trunk/assets/`.** The SVN `assets/` folder is at the repository root, next to `trunk/`, and never ships to users.

Check out:

```bash
svn co https://plugins.svn.wordpress.org/password-reset-guard/ ~/pwrg-svn
```

---

## 4. First commit

```bash
cd ~/pwrg-svn

# copy the plugin files into trunk (no .git, no build/, no .wordpress-org)
rsync -av --delete \
  --exclude '.git' --exclude 'build' --exclude '.wordpress-org' --exclude 'bin' \
  ~/path/to/password-reset-guard/ trunk/

# directory artwork
cp ~/path/to/password-reset-guard/.wordpress-org/screenshot-*.png assets/

svn add --force trunk assets
svn ci -m "Initial commit of Password Reset Guard 2.0.1"
```

Tag the release:

```bash
svn cp trunk tags/2.0.1
svn ci -m "Tagging version 2.0.1"
```

`Stable tag: 2.0.1` in `readme.txt` must match the tag directory name exactly.

---

## 5. Artwork

Drop these in the SVN `assets/` folder (not in the plugin zip):

| File | Size |
|---|---|
| `banner-1544x500.png` | 1544×500 (and `banner-772x250.png` for retina) |
| `icon-128x128.png` / `icon-256x256.png` | square |
| `screenshot-1.png` … `screenshot-N.png` | 4:3 recommended, ≥1200px wide |

The numbered screenshots map to the `== Screenshots ==` list in `readme.txt`, in order. This repo keeps its copies in `.wordpress-org/` — that folder is excluded from the build, so it never reaches users.

---

## 6. Every release after that

```bash
cd ~/pwrg-svn
svn up

# sync trunk from git
rsync -av --delete \
  --exclude '.git' --exclude 'build' --exclude '.wordpress-org' --exclude 'bin' \
  ~/path/to/password-reset-guard/ trunk/

svn ci -m "Version 2.0.2: <summary>"

# tag it
svn cp trunk tags/2.0.2
svn ci -m "Tagging version 2.0.2"
```

Bump `Version:` in the header and `Stable tag:` in `readme.txt` **before** syncing, or WordPress will keep serving the old tag.

Users see the update within a few hours (the update check runs on a 12-hour cycle but is triggered immediately when the tag is committed).

---

## 7. Why bother, if GitHub is already public?

| | GitHub | WordPress.org |
|---|---|---|
| Discoverability | Search, referral | 60k+ installs/month searching the directory |
| Updates | Manual only | Native "Update now" button on every site |
| Trust signal | Stars | Active installs, ratings, verified compatibility |
| Review | None | Human security review before listing |

Both. GitHub is the development source; WordPress.org is the distribution channel.

---

## 8. Common rejection reasons

- `Stable tag` missing or not matching a `tags/` directory
- Bundled library shipping a different license than GPL
- Minified JS/CSS without the unminified source
- Calling a third-party API on page load without consent
- Registering a custom post type or menu without an obvious admin UI
- `Contributors:` naming a username that does not exist on WordPress.org
- Output not escaped, or `$_POST` read without a nonce

---

## 9. Quick checklist for this plugin

- [x] `readme.txt` in the required format with `Stable tag: 2.0.1`
- [x] GPL-2.0 `LICENSE` file included
- [x] `Requires at least: 5.8`, `Requires PHP: 7.4`, `Tested up to: 7.1`
- [x] Plugin Check: 0 errors
- [x] Four screenshots at 1300px wide
- [x] No site names, IPs, credentials or absolute paths
- [ ] `Contributors:` changed to the real WordPress.org username
- [ ] Zip uploaded at https://wordpress.org/plugins/developers/add/
