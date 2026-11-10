=== Password Reset Guard ===
Contributors: deepquest
Tags: security, password reset, user enumeration, brute force, xmlrpc
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Logs and rate-limits WordPress password reset abuse, then closes the user enumeration holes that make it worthwhile.

== Description ==

**WordPress fires `lostpassword_post` for every password reset request — including requests for usernames that do not exist.**

That single behaviour turns the "Lost your password?" form into two things at once:

* a **free username oracle**, because an attacker can test whether an account exists one request at a time, at their leisure;
* a **free mail cannon**, because nothing stops anyone from triggering unlimited reset emails to any address on the site.

Most sites only notice after the owner's mailbox is full and the user list has been scraped. Password Reset Guard makes both attacks pointless.

= What it does =

**Logs every attempt.** Each reset request is recorded with the submitted username, the resolved user ID, the IP address, the country, VPN and Tor flags, a repeat counter and timestamps. Requests for usernames that do not exist are logged too — those are the interesting ones.

**Throttles reset mail per IP.** After a configurable number of requests inside a counting window, the reset email is silently suppressed. The form still returns WordPress's generic response, so the attacker cannot tell whether they were blocked or whether the account does not exist.

**Hard-blocks repeat offenders.** After a second, higher threshold the IP is refused outright for a configurable number of hours. Blocks are stored as expiring transients — they expire on their own and are cleared by neither a cache flush nor a plugin reinstall.

**Closes the enumeration vectors around the form:**

* `?author=N` archive scans return a 404 instead of revealing the author archive
* the `/wp/v2/users` REST endpoint is removed for anonymous requests
* `?author=` filtering is stripped from anonymous REST post queries — both the query arguments (`author__in` / `author__not_in`) and the advertised collection schema
* `xmlrpc.php` is refused with a 403 and `xmlrpc_enabled` is switched off
* the WLW manifest, RSD link and shortlink headers that leak author IDs are removed

**Gives you a usable dashboard.** A stats bar shows logged attempts, unique IPs, VPN/proxy IPs, Tor exits and the last 24 hours. The table filters by username, user ID, IP, country, VPN and Tor, sorts on any column, paginates at 50 rows and exports to CSV — either the current filter or everything.

= Design decisions you should know about =

**Administrators are never blocked.** Any request from a logged-in user with `manage_options` bypasses every limit. A tight threshold slows an attacker down but can never lock the site owner out of their own recovery flow.

**Proxy headers are not trusted by default.** `X-Forwarded-For` and friends are trivially spoofable by any client, so the client IP is resolved from `REMOTE_ADDR` unless you explicitly enable proxy trust. Turn it on only when the site actually sits behind Cloudflare or a reverse proxy you control.

**Geolocation is opt-in.** When enabled it sends the visitor IP to a third-party service, which is a privacy decision that belongs to the site owner. It ships disabled. The provider can be replaced entirely through the `pwrg_geo_lookup` and `pwrg_geo_endpoint` filters, so you can point it at your own internal service.

**Tor detection uses the official list.** Exit nodes come from the Tor Project's published bulk exit list, cached in a transient and refreshed once a day by WP-Cron. No per-request outbound calls.

**Nothing is written outside your database.** Activation creates one table. Deactivation only clears the scheduled event. Deleting the plugin removes the table, the options, the cached lookups and the cron event — on every site of a multisite network.

= Privacy =

Everything the plugin records stays in your own database. The plugin does not phone home, does not collect telemetry, and sends nothing anywhere unless you switch on geolocation or let it refresh the Tor list. Stored fields are limited to what the dashboard needs: username, user ID, IP address, country and code, two boolean flags, a counter and two timestamps. The log is capped at 2,000 rows by default and pruned oldest-first.

= Requirements =

* WordPress 5.8 or newer
* PHP 7.4 or newer
* MySQL 5.7+ / MariaDB 10.2+ (for `INSERT ... ON DUPLICATE KEY UPDATE`)
* No other plugin, theme, cache layer or hosting provider is required or assumed

= Developer hooks =

* `pwrg_settings` — filter the runtime settings array
* `pwrg_client_ip` — filter the resolved client IP
* `pwrg_geo_lookup` — replace the geolocation provider (return `country`, `country_code`, `is_proxy`)
* `pwrg_geo_endpoint` — change the geolocation URL
* `pwrg_ip_blocked` — action fired when an IP is hard-blocked
* `pwrg_trust_proxy_headers` — force proxy-header trust on or off in code

= Compatibility =

Works on single sites and multisite installations, with any theme and any page builder. It does not touch the login form markup, adds no front-end assets and does not modify `.htaccess`. Because it operates on core hooks only, it behaves identically on shared hosting, VPS, managed WordPress and containerised installs.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it through **Plugins → Add New → Upload Plugin**.
2. Activate it through the **Plugins** screen.
3. Open **Reset Guard** in the admin menu to review the log and adjust the thresholds.

The plugin creates one database table on activation and prunes it automatically. Defaults: suppress reset mail after 2 requests, hard-block after 3, for 24 hours.

== Frequently Asked Questions ==

= Does this stop brute-force logins? =

No. It covers password *reset* abuse and user enumeration. Pair it with a login rate limiter and strong passwords; they defend different endpoints.

= Why is geolocation off by default? =

Turning it on sends visitor IP addresses to a third-party service over plain HTTP with the bundled provider. That is a privacy decision the site owner should make deliberately, so it ships disabled. You can also bypass the bundled provider entirely with the `pwrg_geo_lookup` filter and use your own.

= Will it block my own IP by accident? =

Not while you are logged in as an administrator — those requests are always exempt. If you lock yourself out from another device, add the IP (or its CIDR range) to the whitelist on the settings screen.

= Does it work behind Cloudflare or a load balancer? =

Yes. Enable "Trust CF-Connecting-IP / X-Forwarded-For" in the settings, and only do so when the site is genuinely behind a proxy you control. Otherwise clients can spoof those headers to dodge the rate limit.

= Is anything sent to a third party? =

Only if you enable it: the geolocation lookup sends the visitor IP to the configured provider, and the Tor list refresh fetches a public file from the Tor Project. Both are opt-out, both are cached, and the Tor list is fetched once a day at most.

= Can I keep the log forever? =

You can raise the row cap to 100,000 in the settings. Beyond that a dedicated log store is a better fit than a single table.

= What happens to the log when I deactivate it? =

Nothing. Deactivation only clears the scheduled maintenance event, so the data is still there if you reactivate. Deleting the plugin through the admin removes the table, options, transients and cron event.

= Does it work on multisite? =

Yes. Settings and the log table are per site, and uninstall cleans up every site on the network.

== Screenshots ==

1. The log screen: attempt counters, filters and the table with VPN and Tor flags.
2. The same table filtered to Tor exits only — one click on "Tor only".
3. Settings: the thresholds that decide when reset mail is suppressed and when an IP is blocked.
4. Settings: the IP / CIDR whitelist, the enumeration hardening toggles and the opt-in threat lookups.

== Changelog ==

= 2.0.2 =
* Plugin Check clean: no errors, no warnings.
* Table identifiers are interpolated from `$wpdb->prefix` directly instead of a pre-escaped variable, which removes the false-positive SQL interpolation notices without weakening escaping.
* Documented the one place where the plugin reads `$_POST` without its own nonce — the lost-password form, which `wp-login.php` renders and verifies.
* Uninstall's `DROP TABLE` is annotated as an intentional schema change.
* No behavioural change.

= 2.0.1 =
* Added a Plugin URI pointing at the source repository.
* Expanded the description, FAQ and privacy notes.
* Four screenshots added for the plugin directory.
* The build script no longer ships the directory used for WordPress.org artwork.

= 2.0.0 =
* First public release.
* New prefix (`pwrg_`) and table name (`{prefix}pwrg_log`).
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

= 2.0.2 =
Static-analysis clean-up only. No behaviour change.

= 2.0.1 =
Documentation and artwork update only. No behaviour change.

= 2.0.0 =
First public release.
