<?php
/**
 * Admin UI: log viewer, settings screen and CSV export.
 *
 * @package PasswordResetGuard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin screen, settings and AJAX endpoints.
 */
class PWRG_Admin {

	/**
	 * Capability required for every screen and action.
	 */
	const CAP = 'manage_options';

	/**
	 * Settings group name.
	 */
	const GROUP = 'pwrg_settings_group';

	/**
	 * Hook everything up.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_pwrg_logs', array( $this, 'ajax_logs' ) );
		add_action( 'wp_ajax_pwrg_stats', array( $this, 'ajax_stats' ) );
		add_action( 'wp_ajax_pwrg_clear', array( $this, 'ajax_clear' ) );
		add_action( 'admin_post_pwrg_export', array( $this, 'export_csv' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PWRG_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add the top-level menu entry.
	 */
	public function menu() {
		add_menu_page(
			__( 'Password Reset Guard', 'password-reset-guard' ),
			__( 'Reset Guard', 'password-reset-guard' ),
			self::CAP,
			'password-reset-guard',
			array( $this, 'render_page' ),
			'dashicons-shield',
			80
		);
	}

	/**
	 * Add a Settings link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=password-reset-guard' );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'password-reset-guard' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Register the settings option and its sanitizer.
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			PWRG_OPTION,
			array(
				'type'              => 'array',
				'default'           => pwrg_defaults(),
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	/**
	 * Sanitize the settings form.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$defaults = pwrg_defaults();
		$input    = is_array( $input ) ? $input : array();

		$clean = array(
			'email_attempts'      => $this->clamp( $input, 'email_attempts', 1, 50, $defaults['email_attempts'] ),
			'hard_block_attempts' => $this->clamp( $input, 'hard_block_attempts', 1, 100, $defaults['hard_block_attempts'] ),
			'window_minutes'      => $this->clamp( $input, 'window_minutes', 1, 1440, $defaults['window_minutes'] ),
			'block_hours'         => $this->clamp( $input, 'block_hours', 1, 720, $defaults['block_hours'] ),
			'max_entries'         => $this->clamp( $input, 'max_entries', 100, 100000, $defaults['max_entries'] ),
			'whitelist'           => $this->sanitize_whitelist( isset( $input['whitelist'] ) ? $input['whitelist'] : '' ),
			'trust_proxy'         => empty( $input['trust_proxy'] ) ? 0 : 1,
			'geo_enabled'         => empty( $input['geo_enabled'] ) ? 0 : 1,
			'tor_enabled'         => empty( $input['tor_enabled'] ) ? 0 : 1,
			'block_author_enum'   => empty( $input['block_author_enum'] ) ? 0 : 1,
			'block_rest_users'    => empty( $input['block_rest_users'] ) ? 0 : 1,
			'block_xmlrpc'        => empty( $input['block_xmlrpc'] ) ? 0 : 1,
		);

		if ( $clean['hard_block_attempts'] < $clean['email_attempts'] ) {
			$clean['hard_block_attempts'] = $clean['email_attempts'];
		}

		return $clean;
	}

	/**
	 * Clamp an integer field.
	 *
	 * @param array  $input    Input array.
	 * @param string $key      Key.
	 * @param int    $min      Minimum.
	 * @param int    $max      Maximum.
	 * @param int    $fallback Fallback.
	 * @return int
	 */
	private function clamp( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] || ! is_numeric( $input[ $key ] ) ) {
			return (int) $fallback;
		}

		return min( $max, max( $min, (int) $input[ $key ] ) );
	}

	/**
	 * Keep only valid IPs and IPv4 CIDR ranges from the whitelist field.
	 *
	 * @param string $raw Raw field value.
	 * @return string
	 */
	private function sanitize_whitelist( $raw ) {
		$lines = preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$valid = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( filter_var( $line, FILTER_VALIDATE_IP ) ) {
				$valid[] = $line;
				continue;
			}

			if ( false !== strpos( $line, '/' ) ) {
				list( $subnet, $bits ) = explode( '/', $line, 2 );

				if ( filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && is_numeric( $bits ) && $bits >= 0 && $bits <= 32 ) {
					$valid[] = $subnet . '/' . (int) $bits;
				}
			}
		}

		return implode( "\n", array_unique( $valid ) );
	}

	/**
	 * Load CSS/JS on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_password-reset-guard' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'pwrg-admin',
			PWRG_URL . 'assets/pwrg-admin.css',
			array(),
			PWRG_VERSION
		);

		wp_enqueue_script(
			'pwrg-admin',
			PWRG_URL . 'assets/pwrg-admin.js',
			array(),
			PWRG_VERSION,
			true
		);

		wp_localize_script(
			'pwrg-admin',
			'pwrgAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'exportUrl'     => admin_url( 'admin-post.php' ),
				'nonce'         => wp_create_nonce( 'pwrg_ajax' ),
				'exportNonce'   => wp_create_nonce( 'pwrg_export' ),
				'currentIp'     => pwrg_client_ip(),
				'i18n'          => array(
					'loading'      => __( 'Loading…', 'password-reset-guard' ),
					'empty'        => __( 'No entries found.', 'password-reset-guard' ),
					'confirmClear' => __( 'Delete every logged attempt? This cannot be undone.', 'password-reset-guard' ),
					'error'        => __( 'Request failed. Reload the page and try again.', 'password-reset-guard' ),
					'prev'         => __( '« Prev', 'password-reset-guard' ),
					'next'         => __( 'Next »', 'password-reset-guard' ),
					/* translators: 1: current page, 2: total pages, 3: total rows. */
					'pageOf'       => __( 'Page %1$s of %2$s (%3$s rows)', 'password-reset-guard' ),
					'vpn'          => __( 'VPN', 'password-reset-guard' ),
					'tor'          => __( 'Tor', 'password-reset-guard' ),
					'clean'        => __( 'Clean', 'password-reset-guard' ),
				),
			)
		);
	}

	/**
	 * AJAX: paginated log rows.
	 */
	public function ajax_logs() {
		check_ajax_referer( 'pwrg_ajax', 'nonce' );
		$this->verify_ajax();

		$args = array(
			'username' => isset( $_GET['filter_username'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_username'] ) ) : '',
			'user_id'  => isset( $_GET['filter_user_id'] ) ? (int) $_GET['filter_user_id'] : 0,
			'ip'       => isset( $_GET['filter_ip'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_ip'] ) ) : '',
			'country'  => isset( $_GET['filter_country'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_country'] ) ) : '',
			'vpn'      => ! empty( $_GET['filter_vpn'] ),
			'tor'      => ! empty( $_GET['filter_tor'] ),
			'sort'     => isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'last_seen',
			'dir'      => isset( $_GET['dir'] ) ? sanitize_text_field( wp_unslash( $_GET['dir'] ) ) : 'DESC',
			'page'     => isset( $_GET['page'] ) ? (int) $_GET['page'] : 1,
			'per_page' => 50,
		);

		$result = pwrg_query_log( $args );

		foreach ( $result['rows'] as &$row ) {
			$row['flag'] = pwrg_country_flag( $row['country_code'] );
			unset( $row['id'] );
		}
		unset( $row );

		wp_send_json_success(
			array(
				'rows'     => $result['rows'],
				'total'    => $result['total'],
				'page'     => max( 1, $args['page'] ),
				'pages'    => (int) ceil( $result['total'] / $args['per_page'] ),
				'per_page' => $args['per_page'],
			)
		);
	}

	/**
	 * AJAX: dashboard counters.
	 */
	public function ajax_stats() {
		check_ajax_referer( 'pwrg_ajax', 'nonce' );
		$this->verify_ajax();
		wp_send_json_success( pwrg_get_stats() );
	}

	/**
	 * AJAX: empty the log.
	 */
	public function ajax_clear() {
		check_ajax_referer( 'pwrg_ajax', 'nonce' );
		$this->verify_ajax();

		global $wpdb;


		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}pwrg_log" );

		wp_send_json_success( array( 'message' => __( 'Log cleared.', 'password-reset-guard' ) ) );
	}

	/**
	 * Nonce + capability gate for AJAX.
	 */
	private function verify_ajax() {
		check_ajax_referer( 'pwrg_ajax', 'nonce' );

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( __( 'You are not allowed to do this.', 'password-reset-guard' ), 403 );
		}
	}

	/**
	 * Stream the log as CSV.
	 */
	public function export_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to export this log.', 'password-reset-guard' ), 403 );
		}

		check_admin_referer( 'pwrg_export' );

		$result = pwrg_query_log(
			array(
				'username' => isset( $_GET['filter_username'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_username'] ) ) : '',
				'user_id'  => isset( $_GET['filter_user_id'] ) ? (int) $_GET['filter_user_id'] : 0,
				'ip'       => isset( $_GET['filter_ip'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_ip'] ) ) : '',
				'country'  => isset( $_GET['filter_country'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_country'] ) ) : '',
				'vpn'      => ! empty( $_GET['filter_vpn'] ),
				'tor'      => ! empty( $_GET['filter_tor'] ),
				'sort'     => isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'last_seen',
				'dir'      => isset( $_GET['dir'] ) ? sanitize_text_field( wp_unslash( $_GET['dir'] ) ) : 'DESC',
				'page'     => 1,
				'per_page' => 100000,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="password-reset-guard-' . gmdate( 'Y-m-d-His' ) . '.csv"' );

		$header = array(
			__( 'User', 'password-reset-guard' ),
			__( 'User ID', 'password-reset-guard' ),
			__( 'IP Address', 'password-reset-guard' ),
			__( 'Country', 'password-reset-guard' ),
			__( 'Code', 'password-reset-guard' ),
			__( 'VPN', 'password-reset-guard' ),
			__( 'Tor', 'password-reset-guard' ),
			__( 'Resets', 'password-reset-guard' ),
			__( 'First Seen', 'password-reset-guard' ),
			__( 'Last Seen', 'password-reset-guard' ),
		);

		$lines = array( $this->csv_line( $header ) );

		foreach ( $result['rows'] as $row ) {
			$lines[] = $this->csv_line(
				array(
					$row['username'],
					$row['user_id'],
					$row['ip_address'],
					$row['country'],
					$row['country_code'],
					$row['is_vpn'] ? 'yes' : 'no',
					$row['is_tor'] ? 'yes' : 'no',
					$row['reset_count'],
					$row['first_seen'],
					$row['last_seen'],
				)
			);
		}

		// BOM so Excel reads UTF-8 correctly, then the quoted CSV body.
		echo "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Quote one CSV row. Every cell is quoted, which neutralises formula
	 * injection in spreadsheet apps.
	 *
	 * @param array $cells Cell values.
	 * @return string
	 */
	private function csv_line( $cells ) {
		$out = array();

		foreach ( $cells as $cell ) {
			$out[] = '"' . str_replace( '"', '""', (string) $cell ) . '"';
		}

		return implode( ',', $out );
	}

	/**
	 * Render the admin screen.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$settings = pwrg_settings();
		?>
		<div class="wrap pwrg-wrap">
			<h1><?php esc_html_e( 'Password Reset Guard', 'password-reset-guard' ); ?></h1>

			<div class="pwrg-stats" id="pwrg-stats">
				<div class="pwrg-stat"><span data-stat="total">–</span><small><?php esc_html_e( 'Logged attempts', 'password-reset-guard' ); ?></small></div>
				<div class="pwrg-stat"><span data-stat="unique_ips">–</span><small><?php esc_html_e( 'Unique IPs', 'password-reset-guard' ); ?></small></div>
				<div class="pwrg-stat"><span data-stat="vpn">–</span><small><?php esc_html_e( 'VPN / proxy', 'password-reset-guard' ); ?></small></div>
				<div class="pwrg-stat"><span data-stat="tor">–</span><small><?php esc_html_e( 'Tor exits', 'password-reset-guard' ); ?></small></div>
				<div class="pwrg-stat"><span data-stat="last24h">–</span><small><?php esc_html_e( 'Last 24 hours', 'password-reset-guard' ); ?></small></div>
			</div>

			<form id="pwrg-filters" class="pwrg-filters" onsubmit="return false;">
				<label>
					<span><?php esc_html_e( 'User', 'password-reset-guard' ); ?></span>
					<input type="text" id="pwrg-f-username" placeholder="admin">
				</label>
				<label>
					<span><?php esc_html_e( 'User ID', 'password-reset-guard' ); ?></span>
					<input type="number" id="pwrg-f-user-id" placeholder="1" min="0">
				</label>
				<label>
					<span><?php esc_html_e( 'IP', 'password-reset-guard' ); ?></span>
					<input type="text" id="pwrg-f-ip" placeholder="203.0.113.">
				</label>
				<label>
					<span><?php esc_html_e( 'Country', 'password-reset-guard' ); ?></span>
					<input type="text" id="pwrg-f-country" placeholder="TH" maxlength="2">
				</label>
				<button type="button" class="button" id="pwrg-f-vpn"><?php esc_html_e( 'VPN only', 'password-reset-guard' ); ?></button>
				<button type="button" class="button" id="pwrg-f-tor"><?php esc_html_e( 'Tor only', 'password-reset-guard' ); ?></button>
				<button type="button" class="button button-primary" id="pwrg-f-apply"><?php esc_html_e( 'Filter', 'password-reset-guard' ); ?></button>
				<button type="button" class="button" id="pwrg-f-reset"><?php esc_html_e( 'Clear filters', 'password-reset-guard' ); ?></button>
			</form>

			<div class="pwrg-actions">
				<button type="button" class="button" id="pwrg-export"><?php esc_html_e( 'Export CSV (filtered)', 'password-reset-guard' ); ?></button>
				<button type="button" class="button" id="pwrg-export-all"><?php esc_html_e( 'Export CSV (all)', 'password-reset-guard' ); ?></button>
				<button type="button" class="button pwrg-danger" id="pwrg-clear"><?php esc_html_e( 'Clear log', 'password-reset-guard' ); ?></button>
			</div>

			<table class="wp-list-table widefat fixed striped pwrg-table">
				<thead>
					<tr>
						<th class="pwrg-sort" data-col="username"><?php esc_html_e( 'User', 'password-reset-guard' ); ?></th>
						<th class="pwrg-sort" data-col="user_id"><?php esc_html_e( 'ID', 'password-reset-guard' ); ?></th>
						<th class="pwrg-sort" data-col="ip_address"><?php esc_html_e( 'IP address', 'password-reset-guard' ); ?></th>
						<th class="pwrg-sort" data-col="country"><?php esc_html_e( 'Country', 'password-reset-guard' ); ?></th>
						<th><?php esc_html_e( 'VPN', 'password-reset-guard' ); ?></th>
						<th><?php esc_html_e( 'Tor', 'password-reset-guard' ); ?></th>
						<th class="pwrg-sort" data-col="reset_count"><?php esc_html_e( 'Resets', 'password-reset-guard' ); ?></th>
						<th class="pwrg-sort" data-col="last_seen"><?php esc_html_e( 'Last seen', 'password-reset-guard' ); ?></th>
					</tr>
				</thead>
				<tbody id="pwrg-rows">
					<tr><td colspan="8" class="pwrg-empty"><?php esc_html_e( 'Loading…', 'password-reset-guard' ); ?></td></tr>
				</tbody>
			</table>

			<div class="pwrg-pagination" id="pwrg-pagination"></div>

			<hr>

			<h2><?php esc_html_e( 'Settings', 'password-reset-guard' ); ?></h2>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pwrg-email-attempts"><?php esc_html_e( 'Block reset emails after', 'password-reset-guard' ); ?></label></th>
						<td>
							<input type="number" id="pwrg-email-attempts" name="<?php echo esc_attr( PWRG_OPTION ); ?>[email_attempts]" value="<?php echo esc_attr( $settings['email_attempts'] ); ?>" min="1" max="50" class="small-text">
							<p class="description"><?php esc_html_e( 'Reset requests from one IP before the reset email is suppressed.', 'password-reset-guard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pwrg-hard-block"><?php esc_html_e( 'Hard-block the IP after', 'password-reset-guard' ); ?></label></th>
						<td>
							<input type="number" id="pwrg-hard-block" name="<?php echo esc_attr( PWRG_OPTION ); ?>[hard_block_attempts]" value="<?php echo esc_attr( $settings['hard_block_attempts'] ); ?>" min="1" max="100" class="small-text">
							<p class="description"><?php esc_html_e( 'Total requests in the window before every action from that IP is refused.', 'password-reset-guard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pwrg-window"><?php esc_html_e( 'Counting window (minutes)', 'password-reset-guard' ); ?></label></th>
						<td><input type="number" id="pwrg-window" name="<?php echo esc_attr( PWRG_OPTION ); ?>[window_minutes]" value="<?php echo esc_attr( $settings['window_minutes'] ); ?>" min="1" max="1440" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pwrg-block-hours"><?php esc_html_e( 'Block duration (hours)', 'password-reset-guard' ); ?></label></th>
						<td><input type="number" id="pwrg-block-hours" name="<?php echo esc_attr( PWRG_OPTION ); ?>[block_hours]" value="<?php echo esc_attr( $settings['block_hours'] ); ?>" min="1" max="720" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="pwrg-max-entries"><?php esc_html_e( 'Keep at most', 'password-reset-guard' ); ?></label></th>
						<td>
							<input type="number" id="pwrg-max-entries" name="<?php echo esc_attr( PWRG_OPTION ); ?>[max_entries]" value="<?php echo esc_attr( $settings['max_entries'] ); ?>" min="100" max="100000" class="small-text">
							<p class="description"><?php esc_html_e( 'Oldest rows are pruned first.', 'password-reset-guard' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pwrg-whitelist"><?php esc_html_e( 'Never block these IPs', 'password-reset-guard' ); ?></label></th>
						<td>
							<textarea id="pwrg-whitelist" name="<?php echo esc_attr( PWRG_OPTION ); ?>[whitelist]" rows="4" class="large-text code" placeholder="203.0.113.7&#10;198.51.100.0/24"><?php echo esc_textarea( $settings['whitelist'] ); ?></textarea>
							<p class="description">
								<?php
								printf(
									/* translators: %s: the visitor's current IP address. */
									esc_html__( 'One IP or IPv4 CIDR range per line. Your current IP is %s. Logged-in administrators are never blocked.', 'password-reset-guard' ),
									'<code>' . esc_html( pwrg_client_ip() ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enumeration hardening', 'password-reset-guard' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="<?php echo esc_attr( PWRG_OPTION ); ?>[block_author_enum]" value="1" <?php checked( $settings['block_author_enum'] ); ?>> <?php esc_html_e( '404 the ?author= scan', 'password-reset-guard' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( PWRG_OPTION ); ?>[block_rest_users]" value="1" <?php checked( $settings['block_rest_users'] ); ?>> <?php esc_html_e( 'Hide REST user endpoints from anonymous requests', 'password-reset-guard' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( PWRG_OPTION ); ?>[block_xmlrpc]" value="1" <?php checked( $settings['block_xmlrpc'] ); ?>> <?php esc_html_e( 'Block xmlrpc.php', 'password-reset-guard' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Threat lookup', 'password-reset-guard' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="<?php echo esc_attr( PWRG_OPTION ); ?>[tor_enabled]" value="1" <?php checked( $settings['tor_enabled'] ); ?>> <?php esc_html_e( 'Flag Tor exit nodes (official Tor Project list)', 'password-reset-guard' ); ?></label><br>
								<label><input type="checkbox" name="<?php echo esc_attr( PWRG_OPTION ); ?>[geo_enabled]" value="1" <?php checked( $settings['geo_enabled'] ); ?>> <?php esc_html_e( 'Look up country and proxy status (sends the IP to ip-api.com)', 'password-reset-guard' ); ?></label>
								<p class="description"><?php esc_html_e( 'Geolocation is off by default because it discloses visitor IPs to a third party over plain HTTP. Enable only if that trade-off is acceptable, or replace the provider with the pwrg_geo_lookup and pwrg_geo_endpoint filters.', 'password-reset-guard' ); ?></p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Proxy headers', 'password-reset-guard' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( PWRG_OPTION ); ?>[trust_proxy]" value="1" <?php checked( $settings['trust_proxy'] ); ?>> <?php esc_html_e( 'Trust CF-Connecting-IP / X-Forwarded-For', 'password-reset-guard' ); ?></label>
							<p class="description"><?php esc_html_e( 'Enable only when the site sits behind Cloudflare or a reverse proxy you control — otherwise clients can spoof these headers.', 'password-reset-guard' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

new PWRG_Admin();
