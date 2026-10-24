<?php
/**
 * Settings storage.
 *
 * @package AI_Crawler_Companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's single settings option.
 */
class AICC_Settings {

	const OPTION = 'aicc_settings';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'log_enabled'     => 1,
			'retention_days'  => 90,
			'verify_ips'      => 1,
			'flush_interval'  => 0,  // Hours between automatic CDN flushes. 0 = off.
			'flush_on_update' => 1,  // Flush the CDN shortly after a post is published or updated.
			'debug_headers'   => 0,  // Send X-AICC-* headers on every response.
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Sanitize and save settings from a form submission.
	 *
	 * @param array $input Raw input.
	 * @return array Saved settings.
	 */
	public static function save( $input ) {
		$allowed_intervals = array( 0, 1, 3, 6, 12, 24 );
		$interval          = isset( $input['flush_interval'] ) ? (int) $input['flush_interval'] : 0;
		$clean             = array(
			'log_enabled'     => empty( $input['log_enabled'] ) ? 0 : 1,
			'retention_days'  => max( 7, min( 730, isset( $input['retention_days'] ) ? (int) $input['retention_days'] : 90 ) ),
			'verify_ips'      => empty( $input['verify_ips'] ) ? 0 : 1,
			'flush_interval'  => in_array( $interval, $allowed_intervals, true ) ? $interval : 0,
			'flush_on_update' => empty( $input['flush_on_update'] ) ? 0 : 1,
			'debug_headers'   => empty( $input['debug_headers'] ) ? 0 : 1,
		);
		update_option( self::OPTION, $clean, false );
		return $clean;
	}
}
