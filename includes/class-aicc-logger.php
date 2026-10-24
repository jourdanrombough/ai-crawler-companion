<?php
/**
 * Bot request logger.
 *
 * @package AI_Crawler_Companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records every request from a known bot that reaches WordPress.
 *
 * The row is written on `shutdown`, after the response is built, so it
 * includes the status code and content type (for example text/markdown
 * from a Markdown plugin that exits early).
 */
class AICC_Logger {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'aicc_db_version';

	/**
	 * Request context for the current bot hit, or null.
	 *
	 * @var array|null
	 */
	private static $ctx = null;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'aicc_hits';
	}

	/**
	 * Create or update the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ts datetime NOT NULL,
			bot varchar(64) NOT NULL,
			vendor varchar(32) NOT NULL,
			bot_class varchar(16) NOT NULL,
			verified varchar(10) NOT NULL,
			ip varchar(45) NOT NULL,
			method varchar(8) NOT NULL,
			uri text NOT NULL,
			status smallint(5) unsigned NOT NULL DEFAULT 0,
			content_type varchar(100) NOT NULL DEFAULT '',
			is_markdown tinyint(1) NOT NULL DEFAULT 0,
			accept varchar(255) NOT NULL DEFAULT '',
			ua text NOT NULL,
			PRIMARY KEY  (id),
			KEY ts (ts),
			KEY bot (bot)
		) {$charset};";
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Install the table if the stored version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Hook the capture as early as WordPress allows.
	 */
	public static function init() {
		if ( ! AICC_Settings::get( 'log_enabled' ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'capture' ), 0 );
	}

	/**
	 * Note a bot request; the row is written at shutdown.
	 */
	public static function capture() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ), 0, 1000 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$bot = AICC_Bots::match( $ua );
		if ( ! $bot ) {
			return;
		}
		self::$ctx = array(
			'bot'    => $bot,
			'ua'     => $ua,
			'ip'     => self::client_ip(),
			'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ), 0, 8 ) : '',
			'uri'    => isset( $_SERVER['REQUEST_URI'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 0, 2000 ) : '',
			'accept' => isset( $_SERVER['HTTP_ACCEPT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ), 0, 255 ) : '',
		);
		add_action( 'shutdown', array( __CLASS__, 'write' ), 0 );
	}

	/**
	 * Best guess at the real client IP.
	 *
	 * Uses REMOTE_ADDR when it is a public address (true on GoDaddy Managed
	 * WordPress). Falls back to CF-Connecting-IP, then X-Forwarded-For,
	 * only when REMOTE_ADDR is a private proxy address.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$flags  = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( filter_var( $remote, FILTER_VALIDATE_IP, $flags ) ) {
			return $remote;
		}
		$candidates = array();
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$candidates = array_merge( $candidates, explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
		}
		foreach ( $candidates as $c ) {
			$c = trim( $c );
			if ( filter_var( $c, FILTER_VALIDATE_IP, $flags ) ) {
				return $c;
			}
		}
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	/**
	 * Write the row for the current bot request.
	 */
	public static function write() {
		if ( ! self::$ctx ) {
			return;
		}
		global $wpdb;
		$ctx          = self::$ctx;
		self::$ctx    = null;
		$content_type = '';
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'Content-Type:' ) ) {
				$content_type = trim( substr( $h, 13 ) );
			}
		}
		// 1 only when Markdown was actually served. Failed .md requests stay visible through uri and status.
		$is_md  = false !== stripos( $content_type, 'text/markdown' ) ? 1 : 0;
		$status = (int) http_response_code();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'ts'           => gmdate( 'Y-m-d H:i:s' ),
				'bot'          => $ctx['bot']['name'],
				'vendor'       => $ctx['bot']['vendor'],
				'bot_class'    => $ctx['bot']['class'],
				'verified'     => AICC_Bots::verify( $ctx['bot'], $ctx['ip'] ),
				'ip'           => $ctx['ip'],
				'method'       => $ctx['method'],
				'uri'          => $ctx['uri'],
				'status'       => $status > 0 ? $status : 0,
				'content_type' => substr( $content_type, 0, 100 ),
				'is_markdown'  => $is_md,
				'accept'       => $ctx['accept'],
				'ua'           => $ctx['ua'],
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
		self::maybe_prune();
	}

	/**
	 * Delete rows older than the retention window, at most once a day.
	 */
	private static function maybe_prune() {
		$last = (int) get_option( 'aicc_last_prune', 0 );
		if ( time() - $last < DAY_IN_SECONDS ) {
			return;
		}
		update_option( 'aicc_last_prune', time(), false );
		global $wpdb;
		$days = (int) AICC_Settings::get( 'retention_days' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE ts < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Per-bot totals for the last N days.
	 *
	 * @param int $days Window.
	 * @return array
	 */
	public static function summary( $days ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		return $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT bot, vendor, bot_class, COUNT(*) AS hits,
					SUM(verified = %s) AS verified_hits,
					SUM(is_markdown) AS md_hits,
					COUNT(DISTINCT uri) AS urls,
					COUNT(DISTINCT CONCAT(uri, %s, DATE_FORMAT(ts, %s))) AS url_hours,
					MAX(ts) AS last_seen
				FROM %i WHERE ts >= %s GROUP BY bot, vendor, bot_class ORDER BY hits DESC',
				'yes',
				'|',
				'%Y-%m-%d %H',
				self::table(),
				$since
			),
			ARRAY_A
		);
	}

	/**
	 * Most recent rows.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function recent( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Stream all rows in a date window as CSV.
	 *
	 * @param int $days Window.
	 */
	public static function export_csv( $days ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ai-crawler-hits-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'ts_utc', 'bot', 'vendor', 'class', 'verified', 'ip', 'method', 'uri', 'status', 'content_type', 'is_markdown', 'accept', 'user_agent' ) );
		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ts, bot, vendor, bot_class, verified, ip, method, uri, status, content_type, is_markdown, accept, ua FROM %i WHERE ts >= %s ORDER BY id ASC LIMIT 5000 OFFSET %d', self::table(), $since, $offset ), ARRAY_N ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $r ) {
				fputcsv( $out, $r );
			}
			$offset += 5000;
		} while ( count( $rows ) === 5000 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
