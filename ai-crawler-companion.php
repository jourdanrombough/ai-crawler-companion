<?php
/**
 * Plugin Name:       AI Crawler Companion
 * Plugin URI:        https://optimizationtheory.com/
 * Description:       Logs AI and search bot visits with user agent and IP, checks them against the IP ranges the bot operators publish, and works around GoDaddy Managed WordPress CDN caching that hides bot traffic and serves stale Markdown.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Jourdan Rombough, Optimization Theory
 * Author URI:        https://optimizationtheory.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-crawler-companion
 */

defined( 'ABSPATH' ) || exit;

define( 'AICC_VERSION', '0.1.0' );
define( 'AICC_FILE', __FILE__ );
define( 'AICC_DIR', plugin_dir_path( __FILE__ ) );

require_once AICC_DIR . 'includes/class-aicc-settings.php';
require_once AICC_DIR . 'includes/class-aicc-bots.php';
require_once AICC_DIR . 'includes/class-aicc-logger.php';
require_once AICC_DIR . 'includes/class-aicc-cdn.php';
require_once AICC_DIR . 'includes/class-aicc-diagnostics.php';
require_once AICC_DIR . 'includes/class-aicc-admin.php';

register_activation_hook( __FILE__, array( 'AICC_Logger', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		AICC_Logger::maybe_upgrade();
		AICC_Logger::init();
		AICC_CDN::init();
		AICC_Bots::init();
		if ( is_admin() ) {
			AICC_Admin::init();
		}
	}
);
