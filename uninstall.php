<?php
/**
 * Uninstall routine: remove the log table, options, transients and cron.
 *
 * @package PasswordResetGuard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove every trace of the plugin for one site.
 */
function pwrg_uninstall_site() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'pwrg_log' );

	delete_option( 'pwrg_settings' );
	delete_option( 'pwrg_version' );

	// Cached lookups and rate-limit counters. Enumerated by name rather than a
	// LIKE pattern so the rewrite semantics of the host database cannot leave
	// transients behind.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$pwrg_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			'%pwrg%'
		)
	);

	foreach ( (array) $pwrg_option_names as $pwrg_name ) {
		if (
			0 === strpos( $pwrg_name, '_transient_pwrg_' )
			|| 0 === strpos( $pwrg_name, '_transient_timeout_pwrg_' )
		) {
			delete_option( $pwrg_name );
		}
	}

	wp_clear_scheduled_hook( 'pwrg_daily_maintenance' );
}

if ( is_multisite() ) {
	$pwrg_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $pwrg_sites as $pwrg_site_id ) {
		switch_to_blog( (int) $pwrg_site_id );
		pwrg_uninstall_site();
		restore_current_blog();
	}
} else {
	pwrg_uninstall_site();
}
