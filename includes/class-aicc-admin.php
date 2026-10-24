<?php
/**
 * Admin screen under Tools.
 *
 * @package AI_Crawler_Companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tools > AI Crawler Companion: Overview, Recent hits, Settings, Diagnostics.
 */
class AICC_Admin {

	const SLUG = 'ai-crawler-companion';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		foreach ( array( 'save_settings', 'flush', 'refresh_ranges', 'export', 'run_diag' ) as $action ) {
			add_action( 'admin_post_aicc_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . plugin_basename( AICC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_management_page( 'AI Crawler Companion', 'AI Crawler Companion', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * "Open" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Open</a>' );
		return $links;
	}

	/**
	 * Admin page URL.
	 *
	 * @param string $tab    Tab.
	 * @param array  $extra  Extra query args.
	 * @return string
	 */
	private static function url( $tab = 'overview', $extra = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $extra ), admin_url( 'tools.php' ) );
	}

	/**
	 * Capability check for admin-post handlers.
	 */
	private static function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Hidden fields for an admin-post form.
	 *
	 * @param string $action Action suffix.
	 */
	private static function form_fields( $action ) {
		echo '<input type="hidden" name="action" value="aicc_' . esc_attr( $action ) . '">';
		wp_nonce_field( 'aicc_' . $action );
	}

	/**
	 * Save settings.
	 */
	public static function handle_save_settings() {
		self::guard();
		check_admin_referer( 'aicc_save_settings' );
		$input = isset( $_POST['aicc'] ) && is_array( $_POST['aicc'] ) ? wp_unslash( $_POST['aicc'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		AICC_Settings::save( $input );
		wp_safe_redirect( self::url( 'settings', array( 'aicc_msg' => 'saved' ) ) );
		exit;
	}

	/**
	 * Flush the CDN now.
	 */
	public static function handle_flush() {
		self::guard();
		check_admin_referer( 'aicc_flush' );
		$ok = AICC_CDN::flush( 'manual' );
		wp_safe_redirect( self::url( 'overview', array( 'aicc_msg' => $ok ? 'flushed' : 'flush_failed' ) ) );
		exit;
	}

	/**
	 * Download the published IP lists now.
	 */
	public static function handle_refresh_ranges() {
		self::guard();
		check_admin_referer( 'aicc_refresh_ranges' );
		$report = AICC_Bots::refresh_ranges();
		set_transient( 'aicc_ranges_report', $report, HOUR_IN_SECONDS );
		wp_safe_redirect( self::url( 'diagnostics', array( 'aicc_msg' => 'ranges' ) ) );
		exit;
	}

	/**
	 * CSV export.
	 */
	public static function handle_export() {
		self::guard();
		check_admin_referer( 'aicc_export' );
		$days = isset( $_POST['days'] ) ? max( 1, min( 730, (int) $_POST['days'] ) ) : 30;
		AICC_Logger::export_csv( $days );
	}

	/**
	 * Run diagnostics.
	 */
	public static function handle_run_diag() {
		self::guard();
		check_admin_referer( 'aicc_run_diag' );
		AICC_Diagnostics::run();
		wp_safe_redirect( self::url( 'diagnostics' ) );
		exit;
	}

	/**
	 * Notice text for a redirect message.
	 */
	private static function notice() {
		$msg = isset( $_GET['aicc_msg'] ) ? sanitize_key( wp_unslash( $_GET['aicc_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$map = array(
			'saved'        => array( 'success', 'Settings saved.' ),
			'flushed'      => array( 'success', 'GoDaddy CDN flush requested. It can take a minute to reach every edge location.' ),
			'flush_failed' => array( 'error', 'The flush did not run. GoDaddy\'s CDN flush method is not available on this site.' ),
			'ranges'       => array( 'success', 'IP range download finished. Results are below.' ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
	}

	/**
	 * Page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array(
			'overview'    => 'Overview',
			'recent'      => 'Recent hits',
			'settings'    => 'Settings',
			'diagnostics' => 'Diagnostics',
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'overview';
		}
		echo '<div class="wrap"><h1>AI Crawler Companion</h1>';
		self::notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%1$s" class="nav-tab%2$s">%3$s</a>', esc_url( self::url( $key ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		echo '<style>.aicc-t td,.aicc-t th{vertical-align:top}.aicc-pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600}.aicc-ok{background:#d1f0d9;color:#0a5c24}.aicc-warn{background:#fcefc7;color:#6b4d00}.aicc-fail{background:#f8d7d7;color:#8a1111}.aicc-info{background:#e2ecf8;color:#123a6b}.aicc-uri{word-break:break-all;max-width:420px}</style>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	/**
	 * Overview tab.
	 */
	private static function tab_overview() {
		$last = AICC_CDN::last_flush();
		echo '<h2>GoDaddy CDN</h2>';
		if ( AICC_CDN::is_godaddy() ) {
			$hours = (int) AICC_Settings::get( 'flush_interval' );
			echo '<p>GoDaddy Managed WordPress detected. Interval flush: <strong>' . esc_html( $hours ? 'every ' . $hours . ' hour' . ( 1 === $hours ? '' : 's' ) : 'off' ) . '</strong>. Flush after post updates: <strong>' . ( AICC_Settings::get( 'flush_on_update' ) ? 'on' : 'off' ) . '</strong>. Last flush: <strong>' . esc_html( $last['time'] ? human_time_diff( $last['time'] ) . ' ago (' . $last['reason'] . ')' : 'never' ) . '</strong>.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			self::form_fields( 'flush' );
			submit_button( 'Flush GoDaddy CDN now', 'secondary', 'submit', false );
			echo '</form>';
		} else {
			echo '<p>GoDaddy Managed WordPress not detected. Bot logging works on any host; the CDN tools only apply on GoDaddy.</p>';
		}

		foreach ( array( 7, 30 ) as $days ) {
			$rows = AICC_Logger::summary( $days );
			echo '<h2>Bots, last ' . (int) $days . ' days</h2>';
			if ( ! $rows ) {
				echo '<p>No bot requests logged yet.</p>';
				continue;
			}
			echo '<table class="widefat striped aicc-t"><thead><tr><th>Bot</th><th>Vendor</th><th>Type</th><th>Requests</th><th>IP verified</th><th>Markdown served</th><th>Unique URLs</th><th>Bot-hours</th><th>Last seen (UTC)</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
					esc_html( $r['bot'] ),
					esc_html( $r['vendor'] ),
					esc_html( $r['bot_class'] ),
					(int) $r['hits'],
					(int) $r['verified_hits'],
					(int) $r['md_hits'],
					(int) $r['urls'],
					(int) $r['url_hours'],
					esc_html( $r['last_seen'] )
				);
			}
			echo '</tbody></table>';
		}
		echo '<p class="description">Bot-hours counts each URL once per bot per clock hour, which keeps retries and bursts from inflating the numbers. "IP verified" counts requests whose IP is inside the ranges the operator publishes; bots without a published list can never be verified this way.</p>';

		echo '<h2>Export</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'export' );
		echo '<label>Last <input type="number" name="days" value="30" min="1" max="730" style="width:80px"> days</label> ';
		submit_button( 'Download CSV', 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Recent hits tab.
	 */
	private static function tab_recent() {
		$rows = AICC_Logger::recent( 100 );
		echo '<h2>Last 100 bot requests</h2>';
		if ( ! $rows ) {
			echo '<p>No bot requests logged yet. If your site is behind a long-lived CDN cache, most bot visits never reach WordPress; turn on the interval flush in Settings.</p>';
			return;
		}
		echo '<table class="widefat striped aicc-t"><thead><tr><th>Time (UTC)</th><th>Bot</th><th>Verified</th><th>IP</th><th>Request</th><th>Status</th><th>Type</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$level = 'yes' === $r['verified'] ? 'ok' : ( 'no' === $r['verified'] ? 'fail' : 'info' );
			printf(
				'<tr><td>%s</td><td>%s</td><td><span class="aicc-pill aicc-%s">%s</span></td><td>%s</td><td class="aicc-uri">%s %s</td><td>%d</td><td>%s</td></tr>',
				esc_html( $r['ts'] ),
				esc_html( $r['bot'] ),
				esc_attr( $level ),
				esc_html( $r['verified'] ),
				esc_html( $r['ip'] ),
				esc_html( $r['method'] ),
				esc_html( $r['uri'] ),
				(int) $r['status'],
				esc_html( $r['is_markdown'] ? 'markdown' : $r['content_type'] )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">Verified: yes = IP is in the operator\'s published list; no = it is not (likely a spoofed user agent); unlisted = the operator publishes no list; pending = lists not downloaded yet.</p>';
	}

	/**
	 * Settings tab.
	 */
	private static function tab_settings() {
		$s  = AICC_Settings::all();
		$gd = AICC_CDN::is_godaddy();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'save_settings' );
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">Bot logging</th><td><label><input type="checkbox" name="aicc[log_enabled]" value="1"' . checked( $s['log_enabled'], 1, false ) . '> Log requests from AI and search bots</label><p class="description">Only bots are logged. Requests from browsers are never stored.</p></td></tr>';

		echo '<tr><th scope="row">Keep logs for</th><td><input type="number" name="aicc[retention_days]" value="' . esc_attr( $s['retention_days'] ) . '" min="7" max="730" style="width:90px"> days</td></tr>';

		echo '<tr><th scope="row">IP verification</th><td><label><input type="checkbox" name="aicc[verify_ips]" value="1"' . checked( $s['verify_ips'], 1, false ) . '> Check bot IPs against the ranges OpenAI, Perplexity, Google, Microsoft and Apple publish</label><p class="description">The lists are downloaded weekly, during an admin page load.</p></td></tr>';

		echo '<tr><th scope="row">Interval CDN flush</th><td><select name="aicc[flush_interval]"' . disabled( $gd, false, false ) . '>';
		foreach ( array( 0 => 'Off', 1 => 'Every hour', 3 => 'Every 3 hours', 6 => 'Every 6 hours', 12 => 'Every 12 hours', 24 => 'Every 24 hours' ) as $v => $label ) {
			echo '<option value="' . (int) $v . '"' . selected( (int) $s['flush_interval'], $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">Clears GoDaddy\'s whole CDN cache on this schedule so that bot requests reach WordPress and get logged. The cost is a short burst of uncached page loads after each flush. Hourly suits a measurement period; most sites are fine with 12 or 24 hours or off.' . ( $gd ? '' : ' <strong>Not available: GoDaddy Managed WordPress not detected.</strong>' ) . '</p></td></tr>';

		echo '<tr><th scope="row">Flush after post updates</th><td><label><input type="checkbox" name="aicc[flush_on_update]" value="1"' . checked( $s['flush_on_update'], 1, false ) . disabled( $gd, false, false ) . '> Clear the CDN about two minutes after a post is published, updated or unpublished</label><p class="description">Keeps Markdown (.md) copies and llms.txt from staying stale at the edge. Several edits inside two minutes share one flush.</p></td></tr>';

		echo '<tr><th scope="row">Debug headers</th><td><label><input type="checkbox" name="aicc[debug_headers]" value="1"' . checked( $s['debug_headers'], 1, false ) . '> Send X-AICC-Version and X-AICC-Last-Flush headers</label><p class="description">Useful for checking from outside whether a response came from WordPress or from the CDN cache.</p></td></tr>';

		echo '</table>';
		submit_button( 'Save settings' );
		echo '</form>';
	}

	/**
	 * Diagnostics tab.
	 */
	private static function tab_diagnostics() {
		echo '<h2>Diagnostics</h2><p>Checks this host for the problems that make AI visibility plugins misbehave: CDN caching, disabled WP-Cron, competing llms.txt generators, Markdown URLs and content negotiation.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		self::form_fields( 'run_diag' );
		submit_button( 'Run diagnostics', 'primary', 'submit', false );
		echo '</form><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
		self::form_fields( 'refresh_ranges' );
		submit_button( 'Refresh IP ranges', 'secondary', 'submit', false );
		echo '</form>';

		$report = get_transient( 'aicc_ranges_report' );
		if ( $report ) {
			echo '<h3>IP range download</h3><table class="widefat striped aicc-t" style="max-width:640px"><tbody>';
			foreach ( $report as $key => $v ) {
				echo '<tr><td>' . esc_html( $key ) . '</td><td>' . esc_html( is_int( $v ) ? $v . ' prefixes' : $v ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$last = AICC_Diagnostics::last();
		if ( ! $last ) {
			echo '<p>No results yet.</p>';
			return;
		}
		echo '<h3>Results from ' . esc_html( human_time_diff( $last['time'] ) ) . ' ago</h3>';
		echo '<table class="widefat striped aicc-t"><tbody>';
		foreach ( $last['rows'] as $r ) {
			printf( '<tr><td style="width:90px"><span class="aicc-pill aicc-%1$s">%1$s</span></td><td style="width:180px"><strong>%2$s</strong></td><td>%3$s</td></tr>', esc_attr( $r['level'] ), esc_html( $r['label'] ), esc_html( $r['detail'] ) );
		}
		echo '</tbody></table>';
	}
}
