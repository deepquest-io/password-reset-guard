<?php
/**
 * Plugin Name:       Password Reset Guard
 * Plugin URI:        https://github.com/deepquest-io/password-reset-guard
 * Description:       Logs and rate-limits WordPress password-reset abuse, then closes the user-enumeration holes that make it profitable: REST users endpoint, XML-RPC, author archives and shortlinks.
 * Version:           2.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            deepquest
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       password-reset-guard
 * Domain Path:       /languages
 *
 * @package PasswordResetGuard
 */

defined( 'ABSPATH' ) || exit;

define( 'PWRG_VERSION', '2.0.1' );
define( 'PWRG_FILE', __FILE__ );
define( 'PWRG_DIR', plugin_dir_path( __FILE__ ) );
define( 'PWRG_URL', plugin_dir_url( __FILE__ ) );
define( 'PWRG_OPTION', 'pwrg_settings' );

require_once PWRG_DIR . 'includes/class-pwrg-admin.php';

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

/**
 * Default settings.
 *
 * @return array
 */
function pwrg_defaults() {
	return array(
		'email_attempts'      => 2,
		'hard_block_attempts' => 3,
		'window_minutes'      => 60,
		'block_hours'         => 24,
		'max_entries'         => 2000,
		'whitelist'           => '',
		'trust_proxy'         => 0,
		'geo_enabled'         => 0,
		'tor_enabled'         => 1,
		'block_author_enum'   => 1,
		'block_rest_users'    => 1,
		'block_xmlrpc'        => 1,
	);
}

/**
 * Current settings, merged over defaults.
 *
 * @return array
 */
function pwrg_settings() {
	$saved = get_option( PWRG_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	$settings = wp_parse_args( $saved, pwrg_defaults() );

	/**
	 * Filter the runtime settings.
	 *
	 * @param array $settings Settings array.
	 */
	return apply_filters( 'pwrg_settings', $settings );
}

/**
 * Single setting value.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function pwrg_setting( $key ) {
	$settings = pwrg_settings();

	return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
}

/**
 * Fully qualified log table name.
 *
 * @return string
 */
function pwrg_table() {
	global $wpdb;

	return esc_sql( $wpdb->prefix . 'pwrg_log' );
}

/* -------------------------------------------------------------------------
 * Install / uninstall lifecycle
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'pwrg_activate' );
register_deactivation_hook( __FILE__, 'pwrg_deactivate' );

/**
 * Create the log table, schedule maintenance and import legacy rows.
 */
function pwrg_activate() {
	global $wpdb;

	$table = esc_sql( $wpdb->prefix . 'pwrg_log' );
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		username     VARCHAR(191)    NOT NULL DEFAULT '',
		user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
		ip_address   VARCHAR(45)     NOT NULL DEFAULT '',
		country      VARCHAR(100)    NOT NULL DEFAULT '',
		country_code VARCHAR(2)      NOT NULL DEFAULT '',
		is_vpn       TINYINT(1)      NOT NULL DEFAULT 0,
		is_tor       TINYINT(1)      NOT NULL DEFAULT 0,
		reset_count  INT UNSIGNED    NOT NULL DEFAULT 1,
		first_seen   DATETIME        NOT NULL,
		last_seen    DATETIME        NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY idx_ip_user (ip_address, username),
		KEY idx_user (username),
		KEY idx_last (last_seen)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'pwrg_version', PWRG_VERSION, false );

	if ( ! wp_next_scheduled( 'pwrg_daily_maintenance' ) ) {
		wp_schedule_event( time() + 300, 'daily', 'pwrg_daily_maintenance' );
	}

}

/**
 * Clear scheduled events.
 */
function pwrg_deactivate() {
	wp_clear_scheduled_hook( 'pwrg_daily_maintenance' );
}

/**
 * Run schema upgrades after a plugin update.
 */
function pwrg_maybe_upgrade() {
	if ( get_option( 'pwrg_version' ) === PWRG_VERSION ) {
		return;
	}

	pwrg_activate();
}
add_action( 'plugins_loaded', 'pwrg_maybe_upgrade' );

/**
 * Daily maintenance: refresh the Tor list and prune old rows.
 */
function pwrg_daily_maintenance() {
	if ( pwrg_setting( 'tor_enabled' ) ) {
		pwrg_refresh_tor_list( true );
	}
	pwrg_prune_log();
}
add_action( 'pwrg_daily_maintenance', 'pwrg_daily_maintenance' );

/* -------------------------------------------------------------------------
 * Client identity
 * ---------------------------------------------------------------------- */

/**
 * Resolve the client IP.
 *
 * Proxy headers are only trusted when the site owner enables it, because
 * X-Forwarded-For is trivially spoofable by any client.
 *
 * @return string
 */
function pwrg_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	if ( pwrg_setting( 'trust_proxy' ) ) {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' );

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			$candidate = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			if ( false !== strpos( $candidate, ',' ) ) {
				$parts     = explode( ',', $candidate );
				$candidate = trim( $parts[0] );
			}

			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
				break;
			}
		}
	}

	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		$ip = '0.0.0.0';
	}

	/**
	 * Filter the resolved client IP.
	 *
	 * @param string $ip Client IP address.
	 */
	return apply_filters( 'pwrg_client_ip', $ip );
}

/**
 * Is the IP public (not private or reserved)?
 *
 * @param string $ip IP address.
 * @return bool
 */
function pwrg_is_public_ip( $ip ) {
	return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

/**
 * Match an IP against the whitelist (plain IPs and IPv4 CIDR ranges).
 *
 * @param string $ip IP address.
 * @return bool
 */
function pwrg_ip_is_whitelisted( $ip ) {
	$whitelist = (string) pwrg_setting( 'whitelist' );
	$entries   = preg_split( '/[\s,]+/', $whitelist, -1, PREG_SPLIT_NO_EMPTY );

	if ( empty( $entries ) ) {
		return false;
	}

	foreach ( $entries as $entry ) {
		if ( $entry === $ip ) {
			return true;
		}

		if ( false === strpos( $entry, '/' ) ) {
			continue;
		}

		list( $subnet, $bits ) = explode( '/', $entry, 2 );
		$bits                  = (int) $bits;

		if ( ! filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) || $bits < 0 || $bits > 32 ) {
			continue;
		}

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		if ( false === $ip_long || false === $subnet_long ) {
			continue;
		}

		$mask = -1 << ( 32 - $bits );

		if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
			return true;
		}
	}

	return false;
}

/**
 * A logged-in administrator is never blocked, so a locked-out owner can
 * always recover through wp-login.php.
 *
 * @return bool
 */
function pwrg_is_privileged() {
	return is_user_logged_in() && current_user_can( 'manage_options' );
}

/**
 * Should this request be exempt from blocking and logging?
 *
 * @return bool
 */
function pwrg_is_exempt() {
	return pwrg_ip_is_whitelisted( pwrg_client_ip() ) || pwrg_is_privileged();
}

/* -------------------------------------------------------------------------
 * Attempt logging and blocking
 * ---------------------------------------------------------------------- */

/**
 * Record a password reset request and apply rate limits.
 *
 * `lostpassword_post` fires for valid and invalid usernames alike, which is
 * exactly what makes it useful against enumeration.
 *
 * @param WP_Error|array $errors    Error object from the form (unused).
 * @param WP_User|false  $user_data Resolved user, if any.
 */
function pwrg_log_reset( $errors, $user_data = false ) {
	if ( pwrg_is_exempt() ) {
		return;
	}

	$ip = pwrg_client_ip();

	if ( get_transient( 'pwrg_block_' . md5( $ip ) ) ) {
		return; // Already serving a hard block.
	}

	$window = max( 1, (int) pwrg_setting( 'window_minutes' ) ) * MINUTE_IN_SECONDS;
	$key    = 'pwrg_attempts_' . md5( $ip );
	$count  = (int) get_transient( $key ) + 1;

	set_transient( $key, $count, $window );

	$raw   = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
	$user  = ( $user_data instanceof WP_User ) ? $user_data : false;

	// The hook does not always hand us a resolved user; look it up so the log
	// records the real user ID alongside the submitted string.
	if ( ! $user && '' !== $raw ) {
		$user = get_user_by( 'login', $raw );

		if ( ! $user ) {
			$user = get_user_by( 'email', $raw );
		}
	}

	$login = $user ? $user->user_login : $raw;
	$uid   = $user ? (int) $user->ID : 0;

	if ( '' === $login ) {
		$login = '(empty)';
	}

	pwrg_store_attempt( $login, $uid, $ip );

	if ( $count >= (int) pwrg_setting( 'email_attempts' ) ) {
		set_transient( 'pwrg_email_block_' . md5( $ip ), 1, $window );
	}

	if ( $count >= (int) pwrg_setting( 'hard_block_attempts' ) ) {
		$duration = max( 1, (int) pwrg_setting( 'block_hours' ) ) * HOUR_IN_SECONDS;
		set_transient( 'pwrg_block_' . md5( $ip ), 1, $duration );

		/**
		 * Fires when an IP is hard-blocked.
		 *
		 * @param string $ip    Client IP.
		 * @param int    $count Attempts in the current window.
		 */
		do_action( 'pwrg_ip_blocked', $ip, $count );
	}
}
add_action( 'lostpassword_post', 'pwrg_log_reset', 10, 2 );

/**
 * Suppress the reset email once the IP hits its allowance.
 *
 * @param bool $allow   Whether to allow the reset email.
 * @param int  $user_id User ID.
 * @return bool
 */
function pwrg_allow_reset( $allow, $user_id = 0 ) {
	if ( pwrg_is_exempt() ) {
		return true;
	}

	$ip = pwrg_client_ip();

	if ( get_transient( 'pwrg_block_' . md5( $ip ) ) || get_transient( 'pwrg_email_block_' . md5( $ip ) ) ) {
		return false;
	}

	return $allow;
}
add_filter( 'allow_password_reset', 'pwrg_allow_reset', 999, 2 );

/* -------------------------------------------------------------------------
 * Enumeration hardening
 * ---------------------------------------------------------------------- */

/**
 * 404 the ?author=N archive probe.
 */
function pwrg_block_author_enum() {
	if ( ! pwrg_setting( 'block_author_enum' ) || is_user_logged_in() ) {
		return;
	}

	if ( isset( $_GET['author'] ) && is_numeric( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
		include get_query_template( '404' );
		exit;
	}
}
add_action( 'template_redirect', 'pwrg_block_author_enum' );

/**
 * Drop the users endpoints for anonymous requests.
 *
 * @param array $endpoints REST endpoints.
 * @return array
 */
function pwrg_restrict_rest_users( $endpoints ) {
	if ( ! pwrg_setting( 'block_rest_users' ) || is_user_logged_in() ) {
		return $endpoints;
	}

	unset( $endpoints['/wp/v2/users'] );
	unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

	return $endpoints;
}
add_filter( 'rest_endpoints', 'pwrg_restrict_rest_users' );

/**
 * Strip the author filter from anonymous post queries.
 *
 * Core maps the public `author` parameter to the `author__in` query var, so
 * the mapped keys are what actually has to go.
 *
 * @param array           $args    Query arguments.
 * @param WP_REST_Request $request Request object.
 * @return array
 */
function pwrg_strip_rest_author( $args, $request ) {
	if ( ! pwrg_setting( 'block_rest_users' ) || is_user_logged_in() ) {
		return $args;
	}

	unset( $args['author'], $args['author_exclude'], $args['author__in'], $args['author__not_in'] );

	return $args;
}
add_filter( 'rest_post_query', 'pwrg_strip_rest_author', 10, 2 );

/**
 * Unregister author filtering from the public collection schema so the
 * parameter is not advertised (or honoured) for anonymous callers.
 *
 * @param array  $params    Collection parameters.
 * @param string $post_type Post type slug.
 * @return array
 */
function pwrg_restrict_rest_params( $params, $post_type ) {
	if ( ! pwrg_setting( 'block_rest_users' ) || is_user_logged_in() ) {
		return $params;
	}

	unset( $params['author'], $params['author_exclude'] );

	return $params;
}
add_filter( 'rest_post_collection_params', 'pwrg_restrict_rest_params', 10, 2 );

/**
 * Remove author feeds and sitemaps discrimination is out of scope; just the
 * head-level leaks: WLW manifest, RSD and the shortlink that carries a post ID.
 */
function pwrg_clean_head() {
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'rsd_link' );
}
add_action( 'init', 'pwrg_clean_head' );

add_filter( 'get_shortlink', '__return_false', 20 );

/**
 * Reject XML-RPC outright — it is the classic amplification path for
 * credential and username probing.
 */
function pwrg_block_xmlrpc() {
	if ( ! pwrg_setting( 'block_xmlrpc' ) || is_user_logged_in() ) {
		return;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	if ( false !== strpos( $uri, 'xmlrpc.php' ) ) {
		status_header( 403 );
		nocache_headers();
		exit;
	}
}
add_action( 'init', 'pwrg_block_xmlrpc', 1 );

add_filter( 'xmlrpc_enabled', '__return_false' );

/* -------------------------------------------------------------------------
 * Geolocation (optional, off by default)
 * ---------------------------------------------------------------------- */

/**
 * Look up country and proxy status for an IP.
 *
 * Disabled by default: when enabled, the IP is sent to ip-api.com. Return a
 * different provider through the `pwrg_geo_lookup` filter to use your own.
 *
 * @param string $ip IP address.
 * @return array{country:string,country_code:string,is_proxy:bool}
 */
function pwrg_geo_lookup( $ip ) {
	$blank = array(
		'country'      => '',
		'country_code' => '',
		'is_proxy'     => false,
	);

	/**
	 * Short-circuit geolocation with your own provider.
	 *
	 * @param array|null $geo Result, or null to fall through to the default.
	 * @param string     $ip  IP address.
	 */
	$custom = apply_filters( 'pwrg_geo_lookup', null, $ip );

	if ( is_array( $custom ) ) {
		return wp_parse_args( $custom, $blank );
	}

	if ( ! pwrg_setting( 'geo_enabled' ) || ! pwrg_is_public_ip( $ip ) ) {
		return $blank;
	}

	$cache_key = 'pwrg_geo_' . md5( $ip );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	/**
	 * Filter the geolocation endpoint.
	 *
	 * Point this at an HTTPS provider of your own; the bundled free endpoint
	 * is HTTP-only.
	 *
	 * @param string $endpoint URL for the IP lookup.
	 * @param string $ip       IP address being looked up.
	 */
	$endpoint = apply_filters(
		'pwrg_geo_endpoint',
		'http://ip-api.com/json/' . rawurlencode( $ip ),
		$ip
	);

	$response = wp_remote_get(
		add_query_arg(
			'fields',
			'status,country,countryCode,proxy',
			$endpoint
		),
		array( 'timeout' => 5 )
	);

	$geo = $blank;

	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $data ) && isset( $data['status'] ) && 'success' === $data['status'] ) {
			$geo = array(
				'country'      => isset( $data['country'] ) ? sanitize_text_field( $data['country'] ) : '',
				'country_code' => isset( $data['countryCode'] ) ? sanitize_text_field( $data['countryCode'] ) : '',
				'is_proxy'     => ! empty( $data['proxy'] ),
			);
		}
	}

	set_transient( $cache_key, $geo, 7 * DAY_IN_SECONDS );

	return $geo;
}

/**
 * Turn a country code into a flag emoji.
 *
 * @param string $code Two letter country code.
 * @return string
 */
function pwrg_country_flag( $code ) {
	$code = strtoupper( (string) $code );

	if ( 2 !== strlen( $code ) ) {
		return '';
	}

	$a = ord( $code[0] ) - 65;
	$b = ord( $code[1] ) - 65;

	if ( $a < 0 || $a > 25 || $b < 0 || $b > 25 ) {
		return '';
	}

	if ( function_exists( 'mb_chr' ) ) {
		return mb_chr( 0x1F1E6 + $a, 'UTF-8' ) . mb_chr( 0x1F1E6 + $b, 'UTF-8' );
	}

	return '';
}

/* -------------------------------------------------------------------------
 * Tor exit nodes
 * ---------------------------------------------------------------------- */

/**
 * Fetch and cache the official Tor bulk exit list.
 *
 * @param bool $force Ignore the cache.
 * @return array
 */
function pwrg_refresh_tor_list( $force = false ) {
	$cached = get_transient( 'pwrg_tor_list' );

	if ( ! $force && is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_remote_get( 'https://check.torproject.org/torbulkexitlist', array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return is_array( $cached ) ? $cached : array();
	}

	$body = wp_remote_retrieve_body( $response );
	$list = array();

	foreach ( preg_split( '/\R/', $body, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
		$line = trim( $line );

		if ( filter_var( $line, FILTER_VALIDATE_IP ) ) {
			$list[] = $line;
		}
	}

	set_transient( 'pwrg_tor_list', $list, DAY_IN_SECONDS );

	return $list;
}

/**
 * Is this IP a Tor exit node?
 *
 * @param string $ip IP address.
 * @return bool
 */
function pwrg_is_tor_exit( $ip ) {
	if ( ! pwrg_setting( 'tor_enabled' ) || ! pwrg_is_public_ip( $ip ) ) {
		return false;
	}

	$list = pwrg_refresh_tor_list();

	return in_array( $ip, $list, true );
}

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

/**
 * Insert or increment a log row.
 *
 * @param string $username Username or submitted string.
 * @param int    $user_id  Resolved user ID (0 when unknown).
 * @param string $ip       Client IP.
 */
function pwrg_store_attempt( $username, $user_id, $ip ) {
	global $wpdb;

	$table = esc_sql( $wpdb->prefix . 'pwrg_log' );
	$geo   = pwrg_geo_lookup( $ip );
	$now   = current_time( 'mysql' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table}
				(username, user_id, ip_address, country, country_code, is_vpn, is_tor, reset_count, first_seen, last_seen)
			 VALUES (%s, %d, %s, %s, %s, %d, %d, 1, %s, %s)
			 ON DUPLICATE KEY UPDATE
				user_id      = VALUES(user_id),
				country      = VALUES(country),
				country_code = VALUES(country_code),
				is_vpn       = VALUES(is_vpn),
				is_tor       = VALUES(is_tor),
				reset_count  = reset_count + 1,
				last_seen    = VALUES(last_seen)",
			mb_substr( $username, 0, 191 ),
			$user_id,
			$ip,
			$geo['country'],
			$geo['country_code'],
			! empty( $geo['is_proxy'] ) ? 1 : 0,
			pwrg_is_tor_exit( $ip ) ? 1 : 0,
			$now,
			$now
		)
	);

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	if ( $total > (int) pwrg_setting( 'max_entries' ) ) {
		pwrg_prune_log();
	}
}

/**
 * Trim the log down to the configured row cap.
 */
function pwrg_prune_log() {
	global $wpdb;

	$table = esc_sql( $wpdb->prefix . 'pwrg_log' );
	$max   = max( 100, (int) pwrg_setting( 'max_entries' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

	if ( $total <= $max ) {
		return;
	}

	$excess = $total - $max;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} ORDER BY last_seen ASC LIMIT %d",
			$excess
		)
	);
}

/**
 * Aggregate counters for the dashboard.
 *
 * @return array
 */
function pwrg_get_stats() {
	global $wpdb;

	$table = esc_sql( $wpdb->prefix . 'pwrg_log' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return array(
		'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
		'unique_ips' => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT ip_address) FROM {$table}" ),
		'vpn'        => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT ip_address) FROM {$table} WHERE is_vpn = 1" ),
		'tor'        => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT ip_address) FROM {$table} WHERE is_tor = 1" ),
		'last24h'    => (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE last_seen > %s",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		),
	);
	// phpcs:enable
}

/**
 * Query log rows with filters, sorting and pagination.
 *
 * @param array $args Query arguments.
 * @return array{rows:array,total:int}
 */
function pwrg_query_log( $args = array() ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'username'  => '',
			'user_id'   => 0,
			'ip'        => '',
			'country'   => '',
			'vpn'       => false,
			'tor'       => false,
			'sort'      => 'last_seen',
			'dir'       => 'DESC',
			'page'      => 1,
			'per_page'  => 50,
		)
	);

	$table = esc_sql( $wpdb->prefix . 'pwrg_log' );
	$where   = array( '1=1' );
	$params  = array();

	if ( '' !== $args['username'] ) {
		$where[]  = 'username LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $args['username'] ) . '%';
	}

	if ( $args['user_id'] > 0 ) {
		$where[]  = 'user_id = %d';
		$params[] = (int) $args['user_id'];
	}

	if ( '' !== $args['ip'] ) {
		$where[]  = 'ip_address LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $args['ip'] ) . '%';
	}

	if ( '' !== $args['country'] ) {
		$where[]  = 'country_code = %s';
		$params[] = strtoupper( substr( $args['country'], 0, 2 ) );
	}

	if ( $args['vpn'] ) {
		$where[] = 'is_vpn = 1';
	}

	if ( $args['tor'] ) {
		$where[] = 'is_tor = 1';
	}

	$columns = array( 'username', 'user_id', 'ip_address', 'country', 'reset_count', 'last_seen' );
	$sort    = in_array( $args['sort'], $columns, true ) ? $args['sort'] : 'last_seen';
	$dir     = ( 'ASC' === strtoupper( (string) $args['dir'] ) ) ? 'ASC' : 'DESC';
	$per_page = min( 200, max( 1, (int) $args['per_page'] ) );
	$page     = max( 1, (int) $args['page'] );
	$offset   = ( $page - 1 ) * $per_page;

	$sql_where = implode( ' AND ', $where );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
	if ( $params ) {
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}", $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$sql_where} ORDER BY {$sort} {$dir} LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
	} else {
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$sql_where} ORDER BY {$sort} {$dir} LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
	}
	// phpcs:enable

	return array(
		'rows'  => is_array( $rows ) ? $rows : array(),
		'total' => $count,
	);
}
