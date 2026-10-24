<?php
/**
 * GoDaddy Managed WordPress CDN handling.
 *
 * @package AI_Crawler_Companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * GoDaddy Managed WordPress puts every site behind a Cloudflare CDN that
 * caches pages for up to 31 days, overrides the origin's Cache-Control
 * header, and cannot be turned off or given URL exclusions. Bots served
 * from that cache never reach WordPress, so any plugin that logs bots
 * inside WordPress undercounts them, and Markdown copies of posts can stay
 * stale after an edit.
 *
 * This class flushes that CDN through GoDaddy's own must-use plugin:
 * on a fixed interval (so a share of bot requests reaches the origin and
 * gets logged) and shortly after a post is published or updated (so .md
 * copies stay current). WP-Cron is disabled on GoDaddy, so the interval
 * check runs at the end of normal page requests instead.
 */
class AICC_CDN {

	const LAST_FLUSH_OPTION = 'aicc_last_flush';
	const PENDING_TRANSIENT = 'aicc_flush_pending';
	const DEBOUNCE_SECONDS  = 120;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'shutdown', array( __CLASS__, 'maybe_flush' ), 20 );
		if ( AICC_Settings::get( 'flush_on_update' ) ) {
			add_action( 'transition_post_status', array( __CLASS__, 'on_post_change' ), 10, 3 );
		}
		if ( AICC_Settings::get( 'debug_headers' ) ) {
			add_action( 'send_headers', array( __CLASS__, 'debug_headers' ) );
		}
	}

	/**
	 * Is GoDaddy's cache class loaded with a CDN flush method?
	 *
	 * @return bool
	 */
	public static function is_godaddy() {
		global $wpaas_cache_class;
		return is_object( $wpaas_cache_class ) && method_exists( $wpaas_cache_class, 'flush_cdn' );
	}

	/**
	 * Any GoDaddy Managed WordPress markers at all (even if the flush method is missing).
	 *
	 * @return bool
	 */
	public static function looks_like_godaddy() {
		return self::is_godaddy() || class_exists( 'WPaaS\\Plugin' ) || defined( 'GD_SYSTEM_PLUGIN_DIR' );
	}

	/**
	 * Flush the GoDaddy CDN now.
	 *
	 * @param string $reason Why (stored for the admin screen).
	 * @return bool True if a flush was requested.
	 */
	public static function flush( $reason = 'manual' ) {
		if ( ! self::is_godaddy() ) {
			return false;
		}
		global $wpaas_cache_class;
		try {
			$wpaas_cache_class->flush_cdn();
		} catch ( \Throwable $e ) {
			update_option( 'aicc_last_flush_error', substr( $e->getMessage(), 0, 300 ), false );
			return false;
		}
		update_option(
			self::LAST_FLUSH_OPTION,
			array(
				'time'   => time(),
				'reason' => sanitize_key( $reason ),
			),
			false
		);
		delete_option( 'aicc_last_flush_error' );
		return true;
	}

	/**
	 * Last flush details.
	 *
	 * @return array time (int, 0 if never) and reason.
	 */
	public static function last_flush() {
		$v = get_option( self::LAST_FLUSH_OPTION, array() );
		return array(
			'time'   => isset( $v['time'] ) ? (int) $v['time'] : 0,
			'reason' => isset( $v['reason'] ) ? (string) $v['reason'] : '',
		);
	}

	/**
	 * Mark a debounced flush when a public post goes live or changes.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_post_change( $new, $old, $post ) {
		if ( 'publish' !== $new && 'publish' !== $old ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return;
		}
		if ( ! get_transient( self::PENDING_TRANSIENT ) ) {
			set_transient( self::PENDING_TRANSIENT, time() + self::DEBOUNCE_SECONDS, HOUR_IN_SECONDS );
		}
	}

	/**
	 * At the end of any request: run a pending post-update flush once the
	 * debounce window has passed, or an interval flush when one is due.
	 */
	public static function maybe_flush() {
		if ( ! self::is_godaddy() || get_transient( 'aicc_flush_lock' ) ) {
			return;
		}

		$pending = (int) get_transient( self::PENDING_TRANSIENT );
		if ( $pending && time() >= $pending ) {
			set_transient( 'aicc_flush_lock', 1, 5 * MINUTE_IN_SECONDS );
			delete_transient( self::PENDING_TRANSIENT );
			self::flush( 'post_update' );
			return;
		}

		$hours = (int) AICC_Settings::get( 'flush_interval' );
		if ( $hours < 1 ) {
			return;
		}
		$last = self::last_flush();
		if ( time() - $last['time'] < $hours * HOUR_IN_SECONDS ) {
			return;
		}
		set_transient( 'aicc_flush_lock', 1, 5 * MINUTE_IN_SECONDS );
		self::flush( 'interval' );
	}

	/**
	 * Optional response headers for checking the plugin from outside.
	 */
	public static function debug_headers() {
		if ( headers_sent() ) {
			return;
		}
		$last = self::last_flush();
		header( 'X-AICC-Version: ' . AICC_VERSION );
		header( 'X-AICC-Last-Flush: ' . ( $last['time'] ? gmdate( 'c', $last['time'] ) : 'never' ) );
	}
}
