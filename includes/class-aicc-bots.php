<?php
/**
 * Bot registry and IP verification.
 *
 * @package AI_Crawler_Companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identifies AI and search bots by user agent and checks their IPs
 * against the ranges their operators publish.
 */
class AICC_Bots {

	const RANGES_OPTION  = 'aicc_ip_ranges';
	const REFRESH_DAYS   = 7;

	/**
	 * Hook IP range refreshes.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_refresh_ranges' ) );
	}

	/**
	 * Known bots, most specific first.
	 *
	 * Classes:
	 *   user     = fetches a page live because a person asked an AI something
	 *   search   = builds an AI search index
	 *   training = collects data for model training
	 *   engine   = classic search engine crawler
	 *
	 * @return array[] Each: name, pattern (case-insensitive substring), vendor, class, ranges (source keys).
	 */
	public static function registry() {
		$bots = array(
			array( 'ChatGPT-User', 'ChatGPT-User', 'OpenAI', 'user', array( 'openai-chatgpt-user' ) ),
			array( 'OAI-SearchBot', 'OAI-SearchBot', 'OpenAI', 'search', array( 'openai-searchbot' ) ),
			array( 'GPTBot', 'GPTBot', 'OpenAI', 'training', array( 'openai-gptbot' ) ),
			array( 'Claude-User', 'Claude-User', 'Anthropic', 'user', array() ),
			array( 'Claude-SearchBot', 'Claude-SearchBot', 'Anthropic', 'search', array() ),
			array( 'ClaudeBot', 'ClaudeBot', 'Anthropic', 'training', array() ),
			array( 'anthropic-ai', 'anthropic-ai', 'Anthropic', 'training', array() ),
			array( 'Perplexity-User', 'Perplexity-User', 'Perplexity', 'user', array( 'perplexity-user' ) ),
			array( 'PerplexityBot', 'PerplexityBot', 'Perplexity', 'search', array( 'perplexity-bot' ) ),
			array( 'MistralAI-User', 'MistralAI-User', 'Mistral', 'user', array() ),
			array( 'DuckAssistBot', 'DuckAssistBot', 'DuckDuckGo', 'user', array() ),
			array( 'Meta-ExternalFetcher', 'Meta-ExternalFetcher', 'Meta', 'user', array() ),
			array( 'Meta-ExternalAgent', 'Meta-ExternalAgent', 'Meta', 'training', array() ),
			array( 'FacebookBot', 'FacebookBot', 'Meta', 'training', array() ),
			array( 'Google-CloudVertexBot', 'Google-CloudVertexBot', 'Google', 'user', array( 'google-special', 'google-user' ) ),
			array( 'GoogleOther', 'GoogleOther', 'Google', 'training', array( 'google-googlebot', 'google-special' ) ),
			array( 'Googlebot', 'Googlebot', 'Google', 'engine', array( 'google-googlebot', 'google-special' ) ),
			array( 'bingbot', 'bingbot', 'Microsoft', 'engine', array( 'bing' ) ),
			array( 'Applebot', 'Applebot', 'Apple', 'engine', array( 'apple' ) ),
			array( 'Amazonbot', 'Amazonbot', 'Amazon', 'search', array() ),
			array( 'CCBot', 'CCBot', 'Common Crawl', 'training', array() ),
			array( 'Bytespider', 'Bytespider', 'ByteDance', 'training', array() ),
			array( 'cohere-ai', 'cohere-ai', 'Cohere', 'training', array() ),
			array( 'YouBot', 'YouBot', 'You.com', 'search', array() ),
			array( 'Diffbot', 'Diffbot', 'Diffbot', 'training', array() ),
			array( 'PetalBot', 'PetalBot', 'Huawei', 'engine', array() ),
			array( 'Timpibot', 'Timpibot', 'Timpi', 'training', array() ),
			array( 'ExaBot', 'ExaBot', 'Exa', 'search', array() ),
		);

		$out = array();
		foreach ( $bots as $b ) {
			$out[] = array(
				'name'    => $b[0],
				'pattern' => $b[1],
				'vendor'  => $b[2],
				'class'   => $b[3],
				'ranges'  => $b[4],
			);
		}

		/**
		 * Filter the bot registry. Add entries with the same keys.
		 *
		 * @param array[] $out Registry.
		 */
		return apply_filters( 'aicc_bot_registry', $out );
	}

	/**
	 * Match a user agent to a known bot.
	 *
	 * @param string $ua User agent.
	 * @return array|null Registry entry or null.
	 */
	public static function match( $ua ) {
		if ( '' === $ua ) {
			return null;
		}
		foreach ( self::registry() as $bot ) {
			if ( false !== stripos( $ua, $bot['pattern'] ) ) {
				return $bot;
			}
		}
		return null;
	}

	/**
	 * Published IP range sources.
	 *
	 * @return array key => URL.
	 */
	public static function sources() {
		return apply_filters(
			'aicc_ip_range_sources',
			array(
				'openai-gptbot'       => 'https://openai.com/gptbot.json',
				'openai-searchbot'    => 'https://openai.com/searchbot.json',
				'openai-chatgpt-user' => 'https://openai.com/chatgpt-user.json',
				'perplexity-bot'      => 'https://www.perplexity.ai/perplexitybot.json',
				'perplexity-user'     => 'https://www.perplexity.ai/perplexity-user.json',
				'google-googlebot'    => 'https://developers.google.com/static/search/apis/ipranges/googlebot.json',
				'google-special'      => 'https://developers.google.com/static/search/apis/ipranges/special-crawlers.json',
				'google-user'         => 'https://developers.google.com/static/search/apis/ipranges/user-triggered-fetchers.json',
				'bing'                => 'https://www.bing.com/toolbox/bingbot.json',
				'apple'               => 'https://search.developer.apple.com/applebot.json',
			)
		);
	}

	/**
	 * Refresh ranges from an admin request when they are older than a week.
	 */
	public static function maybe_refresh_ranges() {
		if ( ! AICC_Settings::get( 'verify_ips' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$stored = get_option( self::RANGES_OPTION, array() );
		$age    = isset( $stored['_updated'] ) ? time() - (int) $stored['_updated'] : PHP_INT_MAX;
		if ( $age < self::REFRESH_DAYS * DAY_IN_SECONDS || get_transient( 'aicc_ranges_lock' ) ) {
			return;
		}
		set_transient( 'aicc_ranges_lock', 1, 10 * MINUTE_IN_SECONDS );
		self::refresh_ranges();
	}

	/**
	 * Download every published range list. Keeps the previous copy of a list that fails.
	 *
	 * @return array Per-source counts or error strings.
	 */
	public static function refresh_ranges() {
		$stored = get_option( self::RANGES_OPTION, array() );
		$report = array();
		foreach ( self::sources() as $key => $url ) {
			$res = wp_remote_get( $url, array( 'timeout' => 10 ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				$report[ $key ] = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
				continue;
			}
			$data     = json_decode( wp_remote_retrieve_body( $res ), true );
			$prefixes = array();
			if ( isset( $data['prefixes'] ) && is_array( $data['prefixes'] ) ) {
				foreach ( $data['prefixes'] as $p ) {
					if ( ! empty( $p['ipv4Prefix'] ) ) {
						$prefixes[] = (string) $p['ipv4Prefix'];
					} elseif ( ! empty( $p['ipv6Prefix'] ) ) {
						$prefixes[] = (string) $p['ipv6Prefix'];
					}
				}
			}
			if ( $prefixes ) {
				$stored[ $key ] = $prefixes;
				$report[ $key ] = count( $prefixes );
			} else {
				$report[ $key ] = 'no prefixes in response';
			}
		}
		$stored['_updated'] = time();
		update_option( self::RANGES_OPTION, $stored, false );
		return $report;
	}

	/**
	 * Verify a bot's IP against its published ranges.
	 *
	 * @param array  $bot Registry entry.
	 * @param string $ip  Client IP.
	 * @return string yes | no | unlisted (operator publishes no list) | pending (lists not downloaded yet) | off
	 */
	public static function verify( $bot, $ip ) {
		if ( ! AICC_Settings::get( 'verify_ips' ) ) {
			return 'off';
		}
		if ( empty( $bot['ranges'] ) ) {
			return 'unlisted';
		}
		$stored = get_option( self::RANGES_OPTION, array() );
		$any    = false;
		foreach ( $bot['ranges'] as $key ) {
			if ( empty( $stored[ $key ] ) ) {
				continue;
			}
			$any = true;
			foreach ( $stored[ $key ] as $cidr ) {
				if ( self::ip_in_cidr( $ip, $cidr ) ) {
					return 'yes';
				}
			}
		}
		return $any ? 'no' : 'pending';
	}

	/**
	 * IPv4 / IPv6 CIDR membership.
	 *
	 * @param string $ip   Address.
	 * @param string $cidr Range like 1.2.3.0/24 or 2001:db8::/32.
	 * @return bool
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$addr  = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $net || false === $addr || strlen( $net ) !== strlen( $addr ) ) {
			return false;
		}
		$bits  = isset( $parts[1] ) ? (int) $parts[1] : strlen( $net ) * 8;
		$bytes = intdiv( $bits, 8 );
		if ( $bytes && substr( $net, 0, $bytes ) !== substr( $addr, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		return ( $net[ $bytes ] & $mask ) === ( $addr[ $bytes ] & $mask );
	}
}
