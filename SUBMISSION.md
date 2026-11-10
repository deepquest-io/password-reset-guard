# Submission log

## Password Reset Guard

| | |
|---|---|
| Submitted | 2026-11-10 |
| Version submitted | **2.0.2** (`password-reset-guard-2.0.2.zip`, 34,328 bytes) |
| Account | `deepquest` on WordPress.org |
| Assigned slug | **`password-reset-guard`** — confirmed correct, no action needed |
| Listing URL (once approved) | https://wordpress.org/plugins/password-reset-guard/ |
| SVN (once approved) | https://plugins.svn.wordpress.org/password-reset-guard/ |
| Status | In the review queue |
| Source of truth | GitHub `deepquest-io/password-reset-guard` |

The confirmation email is a **queue ticket, not approval**. The slug is provisional
until a human review completes, but it already matches the plugin name and text
domain, so a change is not expected.

### What happens next

1. A volunteer reviewer reads the code and tests it. Their email subject is
   `[WordPress Plugin Directory] Review in Progress: Password Reset Guard`.
2. If they find anything, they reply with a list. Fix it, bump the version,
   rebuild, upload the new zip **on the submission page** (not a new submission),
   and reply in the same email thread.
3. On approval, SVN access is granted and the checklist in `PUBLISHING.md` applies
   from step 3 onward.

### Housekeeping that matters

- Keep the WordPress.org account email working. No autoresponders, no forwarding
  that drops mail — reviews stop dead if they can't reach the author.
- Allow-list `plugins@wordpress.org`.
- Do **not** reply to the confirmation email if there is nothing to say.
- Do not resubmit the plugin. Any follow-up goes in the existing thread.

### Things to handle on the next release

- **Automated security review (since 2026-09-09):** every release is scanned
  during a 6-hour cooldown before the update API distributes it. A high risk score
  blocks the release automatically and emails all committers. A block is a risk
  score, not an accusation — fix and publish a new version rather than appealing.
- **Guideline 15:** the version number must be incremented for every release, and
  `trunk/readme.txt` must reflect the current version.
- **Guideline 14:** do not push rapid-fire commits; each one regenerates the zip.

### Known review risks in the current code

Recorded here so the response is fast if a reviewer raises them.

| Area | Risk | Prepared response |
|---|---|---|
| Geolocation default endpoint | `http://ip-api.com` is plain HTTP, and its free tier is non-commercial only | Opt-in, off by default, documented in two places. Cleanest fix if challenged: remove the built-in provider and keep the `pwrg_geo_lookup` / `pwrg_geo_endpoint` filters, so no third-party call ships at all (v2.1.0) |
| `template_redirect` 404 for `?author=` | Reviewer may prefer `wp_die()` or a redirect | Either is a one-line change if requested |
| `xmlrpc.php` 403 via `status_header()` + `exit` | Same category | One-line change to `wp_die()` if requested |
| Dynamic table name in SQL | Escaping is correct; scanner already silenced with reason | Point at the `$wpdb->prefix` interpolation in the readme/commit history |
