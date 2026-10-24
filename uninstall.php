<?php
/**
 * Removes the plugin's table and options when it is deleted from wp-admin.
 *
 * @package AI_Crawler_Companion
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'aicc_hits' ); // phpcs:ignore WordPress.DB

foreach ( array( 'aicc_settings', 'aicc_db_version', 'aicc_ip_ranges', 'aicc_last_flush', 'aicc_last_flush_error', 'aicc_last_prune' ) as $aicc_option ) {
	delete_option( $aicc_option );
}
foreach ( array( 'aicc_ranges_lock', 'aicc_flush_lock', 'aicc_flush_pending', 'aicc_diag_results', 'aicc_ranges_report' ) as $aicc_transient ) {
	delete_transient( $aicc_transient );
}
