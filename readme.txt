=== Password Reset Guard ===
Contributors: deepquest
Tags: security, password reset, user enumeration, brute force, xmlrpc
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logs and rate-limits password reset abuse, then closes the user enumeration holes that make it worthwhile.

== Description ==

WordPress fires `lostpassword_post` for every password reset request — including requests for usernames that do not exist. That makes the "Lost your password?" form a free username oracle, and an equally free way to send unlimited reset mail to any user on the site.

Password Reset Guard turns that form into a dead end:

* **Every attempt is logged** — submitted username, resolved user ID, IP address, time, and repeat count. Valid and invalid usernames alike.
* **Reset mail is throttled per IP** — after N requests in a window, the reset email is silently suppressed. The form still returns its generic response, so an attacker learns nothing.
* **Repeat offenders are hard-blocked** — after N total requests the IP is refused for a configurable number of hours.
* **Enumeration vectors are closed** — `?author=N` scans return 404, REST user endpoints disappear for anonymous requests, `xmlrpc.php` is refused, and the WLW/RSD/shortlink headers that leak user IDs are removed.
* **Optional threat lookup** — flag Tor exit nodes using the Tor Project's official bulk exit list; opt in to country and proxy lookup via a third-party service.

Everything the plugin logs stays in your own database. Geolocation and Tor lookups are opt-in, and the geolocation provider can be swapped for your own through a filter.

= Safety rails =

* Logged-in administrators are never blocked, so an over-eager rate limit can never lock the site owner out.
* Any IP or IPv4 range can be whitelisted from the settings screen.
* The resolved client IP comes from `REMOTE_ADDR` unless you explicitly enable proxy-header trust — `X-Forwarded-For` is spoofable and is not trusted by default.

= Developer hooks =

* `pwrg_settings` — filter the runtime settings array.
* `pwrg_client_ip` — filter the resolved client IP.
* `pwrg_geo_lookup` — replace the geolocation provider (return an array with `country`, `country_code`, `is_proxy`).
* `pwrg_ip_blocked` — action fired when an IP is hard-blocked.
* `pwrg_trust_proxy_headers` — force proxy-header trust on or off in code.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through **Plugins → Add New**.
2. Activate it through the **Plugins** screen.
3. Open **Reset Guard** in the admin menu to review the log and adjust the thresholds.

The plugin creates one database table on activation and prunes it automatically. Defaults: suppress reset mail after 2 requests, hard-block after 3, for 24 hours.

== Frequently Asked Questions ==

= Does this stop brute-force logins? =

No. It covers password *reset* abuse and user enumeration. Pair it with a login rate limiter and strong passwords.

= Why is geolocation off by default? =

Turning it on sends visitor IP addresses to a third-party service. That is a privacy decision the site owner should make deliberately, so it ships disabled. You can also bypass the built-in provider entirely with the `pwrg_geo_lookup` filter.

= Will it block my own IP by accident? =

Not while you are logged in as an administrator — those requests are always exempt. If you lock yourself out from another device, add the IP to the whitelist or clear the block by deleting the `pwrg_block_*` transients in the options table.

= Does it work behind Cloudflare? =

Yes. Enable "Trust CF-Connecting-IP / X-Forwarded-For" in the settings, and only do so when the site is actually behind a proxy you control.

= What happens to the log when I deactivate it? =

Nothing. Deactivation only clears the scheduled maintenance event. Deleting the plugin through the admin removes the table, options and cached lookups.

== Screenshots ==

1. The log screen: attempt counters, filters and the CSV export.
2. The settings screen: thresholds, whitelist and hardening toggles.

== Changelog ==

= 2.0.0 =
* First public release.
* New prefix (`pwrg_`) and table name (`{prefix}pwrg_log`); existing rows are imported automatically on activation.
* Thresholds, block duration and row cap are now settings instead of constants.
* The hardcoded IP whitelist is replaced by a settings field that accepts IPs and IPv4 CIDR ranges.
* Logged-in administrators are never blocked.
* Geolocation is off by default and swappable through the `pwrg_geo_lookup` filter.
* Proxy headers are no longer trusted by default — `REMOTE_ADDR` is used unless enabled.
* The Tor exit list is cached in a transient and refreshed by WP-Cron instead of on page load.
* Replaced `SQL_CALC_FOUND_ROWS` with a separate count query; the log table gets a unique key on (IP, username) and is written with a single upsert.
* CSV export moved to `admin-post.php` with a dedicated nonce.
* Admin CSS/JS are enqueued as assets instead of printed inline, and the table is rendered with DOM nodes rather than string concatenation.
* All strings are translatable under the `password-reset-guard` text domain, with an uninstall routine.

= 1.0.1 =
* Whitelist support, 2-attempt email block, 3-attempt 24-hour hard block.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
Breaking rename: settings and thresholds move into the database, and the old constants are gone. Existing log rows are imported on activation. Review the settings screen after upgrading.
