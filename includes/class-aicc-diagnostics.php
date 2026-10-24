<?php
/**
 * Hosting and AI-visibility diagnostics.
 *
 * @package AI_Crawler_Companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the checks that explain why AI visibility plugins misbehave on
 * GoDaddy Managed WordPress (and other CDN-fronted hosts).
 */
class AICC_Diagnostics {

	const RESULT_TRANSIENT = 'aicc_diag_results';

	/**
	 * Plugins known to generate /llms.txt, keyed by plugin file.
	 *
	 * @return array plugin file => label.
	 */
	public static function llms_txt_plugins() {
		return apply_filters(
			'aicc_llms_txt_plugins',
			array(
				'all-in-one-seo-pack/all_in_one_seo_pack.php'         => 'All in One SEO',
				'all-in-one-seo-pack-pro/all_in_one_seo_pack.php'     => 'All in One SEO Pro',
				'wordpress-seo/wp-seo.php'                            => 'Yoast SEO',
				'seo-by-rank-math/rank-math.php'                      => 'Rank Math',
				'website-llms-txt/website-llms-txt.php'               => 'Website LLMs.txt',
				'llms-txt-generator/llms-txt-generator.php'           => 'LLMs.txt Generator',
				'llmagnet/llmagnet.php'                               => 'LLMagnet',
				'llmagnet-ai-seo-optimizer/llmagnet.php'              => 'LLMagnet',
				'rankready/rankready.php'                             => 'RankReady',
			)
		);
	}

	/**
	 * Plugins known to serve Markdown copies of posts.
	 *
	 * @return array plugin file => label.
	 */
	public static function markdown_plugins() {
		return apply_filters(
			'aicc_markdown_plugins',
			array(
				'llm-markdown/llm-markdown.php' => 'LLM Markdown',
				'llmagnet/llmagnet.php'         => 'LLMagnet',
				'rankready/rankready.php'       => 'RankReady',
			)
		);
	}

	/**
	 * Active plugins whose file is in a list. Also matches by folder name
	 * so a renamed main file is still found.
	 *
	 * @param array $list plugin file => label.
	 * @return array label list (unique).
	 */
	private static function active_from( $list ) {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$folders = array();
		foreach ( $active as $file ) {
			$folders[ strtok( $file, '/' ) ] = true;
		}
		$found = array();
		foreach ( $list as $file => $label ) {
			if ( in_array( $file, $active, true ) || isset( $folders[ strtok( $file, '/' ) ] ) ) {
				$found[ $label ] = true;
			}
		}
		return array_keys( $found );
	}

	/**
	 * GET a URL from the server and keep the headers we care about.
	 *
	 * @param string $url     URL.
	 * @param array  $headers Request headers.
	 * @return array status, content_type, cache (CDN status), headers, size, error.
	 */
	private static function probe( $url, $headers = array() ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 0,
				'headers'     => array_merge( array( 'User-Agent' => 'AI-Crawler-Companion-Diagnostics/' . AICC_VERSION ), $headers ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message() );
		}
		$h     = wp_remote_retrieve_headers( $res );
		$cache = '';
		foreach ( array( 'cf-cache-status', 'x-gateway-cache-status', 'x-cache', 'x-proxy-cache' ) as $k ) {
			if ( ! empty( $h[ $k ] ) ) {
				$cache .= ( $cache ? ', ' : '' ) . $k . ': ' . ( is_array( $h[ $k ] ) ? implode( ' ', $h[ $k ] ) : $h[ $k ] );
			}
		}
		return array(
			'status'        => (int) wp_remote_retrieve_response_code( $res ),
			'content_type'  => (string) wp_remote_retrieve_header( $res, 'content-type' ),
			'cache_control' => (string) wp_remote_retrieve_header( $res, 'cache-control' ),
			'vary'          => (string) wp_remote_retrieve_header( $res, 'vary' ),
			'link'          => is_array( $h['link'] ?? null ) ? implode( ', ', $h['link'] ) : (string) ( $h['link'] ?? '' ),
			'cache'         => $cache,
			'size'          => strlen( wp_remote_retrieve_body( $res ) ),
			'error'         => '',
		);
	}

	/**
	 * Build a result row.
	 *
	 * @param string $level  ok | warn | fail | info.
	 * @param string $label  Check name.
	 * @param string $detail Plain-language finding.
	 * @return array
	 */
	private static function row( $level, $label, $detail ) {
		return compact( 'level', 'label', 'detail' );
	}

	/**
	 * Run every check and cache the result for the admin screen.
	 *
	 * @return array rows.
	 */
	public static function run() {
		$rows = array();

		// Host.
		if ( AICC_CDN::is_godaddy() ) {
			$rows[] = self::row( 'info', 'Host', 'GoDaddy Managed WordPress detected, and its CDN flush method is available. This plugin can flush the CDN on a schedule and after post updates.' );
		} elseif ( AICC_CDN::looks_like_godaddy() ) {
			$rows[] = self::row( 'warn', 'Host', 'This looks like GoDaddy Managed WordPress, but the CDN flush method was not found. GoDaddy may have changed its system plugin; automatic flushing is off.' );
		} else {
			$rows[] = self::row( 'info', 'Host', 'GoDaddy Managed WordPress not detected. Logging and diagnostics still work; the CDN flush features do nothing on this host.' );
		}

		// WP-Cron.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$rows[] = self::row( 'warn', 'WP-Cron', 'DISABLE_WP_CRON is on. Plugins that schedule work with WP-Cron (log cleanup, llms.txt rebuilds, IndexNow pings) will not run unless the host triggers cron. This plugin does not rely on WP-Cron.' );
		} else {
			$rows[] = self::row( 'ok', 'WP-Cron', 'WP-Cron is enabled.' );
		}

		// Homepage caching.
		$home = self::probe( home_url( '/' ) );
		if ( $home['error'] ) {
			$rows[] = self::row( 'warn', 'Homepage cache', 'Could not fetch the homepage from the server: ' . $home['error'] );
		} else {
			$detail = 'Status ' . $home['status'] . '. Cache-Control: ' . ( $home['cache_control'] ? $home['cache_control'] : 'none' ) . '. ' . ( $home['cache'] ? 'CDN: ' . $home['cache'] . '.' : 'No CDN cache header seen.' );
			$cached = preg_match( '/max-age=(\d+)/', $home['cache_control'], $m ) && (int) $m[1] >= DAY_IN_SECONDS;
			if ( $cached ) {
				$detail .= ' Pages can be cached at the edge for a day or more, so most bot visits never reach WordPress and are invisible to any plugin that logs inside WordPress, including this one. Turn on the interval flush to let a steady share through.';
			}
			$rows[] = self::row( $cached ? 'warn' : 'ok', 'Homepage cache', $detail );
		}

		// llms.txt sources.
		$llms_plugins = self::active_from( self::llms_txt_plugins() );
		$llms         = self::probe( home_url( '/llms.txt' ) );
		$file_exists  = file_exists( ABSPATH . 'llms.txt' );
		$sources      = $llms_plugins;
		if ( $file_exists ) {
			$sources[] = 'a static llms.txt file in the site root';
		}
		$served = empty( $llms['error'] ) && 200 === $llms['status'];
		$detail = $served ? '/llms.txt is live (' . $llms['size'] . ' bytes, ' . $llms['content_type'] . ').' : '/llms.txt is not served' . ( empty( $llms['error'] ) ? ' (HTTP ' . $llms['status'] . ').' : '.' );
		$detail .= $sources ? ' Possible generators: ' . implode( ', ', $sources ) . '.' : ' No known generator plugin is active.';
		if ( AICC_CDN::looks_like_godaddy() ) {
			$detail .= ' GoDaddy also has its own LLMS.TXT switch under GoDaddy > Tools in wp-admin; check it is off if another plugin should own the file.';
		}
		$rows[] = self::row( count( $sources ) > 1 ? 'warn' : 'info', 'llms.txt', $detail . ( count( $sources ) > 1 ? ' More than one source can publish this file, and the one that wins depends on load order. Keep one.' : '' ) );

		// Markdown plugins and a live .md check.
		$md_plugins = self::active_from( self::markdown_plugins() );
		if ( ! $md_plugins ) {
			$rows[] = self::row( 'info', 'Markdown copies', 'No known Markdown plugin is active.' );
		} else {
			$rows[] = self::row( count( $md_plugins ) > 1 ? 'warn' : 'info', 'Markdown plugins', 'Active: ' . implode( ', ', $md_plugins ) . '.' . ( count( $md_plugins ) > 1 ? ' More than one plugin may answer .md URLs or add alternate links. Keep one.' : '' ) );
			$rows   = array_merge( $rows, self::markdown_checks() );
		}

		// LLMagnet analytics note.
		if ( in_array( 'LLMagnet', $llms_plugins, true ) || in_array( 'LLMagnet', $md_plugins, true ) ) {
			$rows[] = self::row( 'warn', 'LLMagnet analytics', 'LLMagnet counts bots inside WordPress by user agent only. Behind a long-lived CDN cache it undercounts heavily and cannot tell a real GPTBot from a fake one. Compare with the verified counts on the Overview tab.' );
		}

		// Our own logger.
		global $wpdb;
		$table  = AICC_Logger::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB
		if ( ! $exists ) {
			$rows[] = self::row( 'fail', 'Bot log', 'The log table is missing. Deactivate and reactivate the plugin.' );
		} else {
			$day    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ts >= %s', $table, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
			$rows[] = self::row( AICC_Settings::get( 'log_enabled' ) ? 'ok' : 'warn', 'Bot log', ( AICC_Settings::get( 'log_enabled' ) ? 'Logging is on. ' : 'Logging is off. ' ) . $day . ' bot requests logged in the last 24 hours.' );
		}

		// IP ranges.
		$ranges = get_option( AICC_Bots::RANGES_OPTION, array() );
		if ( ! AICC_Settings::get( 'verify_ips' ) ) {
			$rows[] = self::row( 'info', 'IP verification', 'Turned off in Settings.' );
		} elseif ( empty( $ranges['_updated'] ) ) {
			$rows[] = self::row( 'warn', 'IP verification', 'Published bot IP ranges have not been downloaded yet. Use "Refresh IP ranges" below.' );
		} else {
			$lists  = count( array_diff_key( $ranges, array( '_updated' => 1 ) ) );
			$rows[] = self::row( 'ok', 'IP verification', $lists . ' published range lists stored, updated ' . human_time_diff( (int) $ranges['_updated'] ) . ' ago.' );
		}

		set_transient( self::RESULT_TRANSIENT, array( 'time' => time(), 'rows' => $rows ), DAY_IN_SECONDS );
		return $rows;
	}

	/**
	 * Fetch the newest post's .md copy and its HTML page with a Markdown
	 * Accept header, and report what the CDN does with each.
	 *
	 * @return array rows.
	 */
	private static function markdown_checks() {
		$rows  = array();
		$posts = get_posts(
			array(
				'numberposts' => 1,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		if ( ! $posts ) {
			return array( self::row( 'info', 'Markdown test', 'No published post to test.' ) );
		}
		$url = get_permalink( $posts[0] );
		$md  = untrailingslashit( $url ) . '.md';

		$r = self::probe( $md );
		if ( $r['error'] ) {
			$rows[] = self::row( 'warn', 'Markdown test', 'Could not fetch ' . $md . ': ' . $r['error'] );
		} elseif ( 200 === $r['status'] && false !== stripos( $r['content_type'], 'markdown' ) ) {
			$detail = $md . ' returns ' . $r['content_type'] . ( $r['cache'] ? ' (' . $r['cache'] . ')' : '' ) . '.';
			$detail .= false !== stripos( $r['link'], 'canonical' ) ? ' It sends a canonical Link header pointing at the HTML page.' : ' It does not send a canonical Link header, so search engines may treat it as a duplicate page.';
			if ( AICC_CDN::is_godaddy() && ! AICC_Settings::get( 'flush_on_update' ) ) {
				$detail .= ' Flush on update is off, so this copy can stay cached and stale after you edit the post.';
			}
			$rows[] = self::row( false !== stripos( $r['link'], 'canonical' ) ? 'ok' : 'warn', 'Markdown test', $detail );
		} else {
			$rows[] = self::row( 'info', 'Markdown test', $md . ' returned HTTP ' . $r['status'] . ' ' . $r['content_type'] . '. The plugin may use a different URL pattern, or this post may be excluded.' );
		}

		// Content negotiation behind a shared cache.
		$n = self::probe( $url, array( 'Accept' => 'text/markdown' ) );
		if ( empty( $n['error'] ) && false !== stripos( $n['content_type'], 'markdown' ) ) {
			$varies = false !== stripos( $n['vary'], 'accept' );
			$level  = ( $varies && ! AICC_CDN::looks_like_godaddy() ) ? 'info' : 'fail';
			$rows[] = self::row(
				$level,
				'Content negotiation',
				'The HTML address returns Markdown when a client asks for text/markdown.' . ( $varies ? ' A Vary: Accept header is sent,' : ' No Vary: Accept header is sent,' ) . ' but a CDN that caches by URL alone can store the Markdown version and show it to human visitors and to Googlebot. Turn content negotiation off in your Markdown plugin and use .md URLs instead.'
			);
		}
		return $rows;
	}

	/**
	 * Last stored results.
	 *
	 * @return array|false time and rows.
	 */
	public static function last() {
		return get_transient( self::RESULT_TRANSIENT );
	}
}
