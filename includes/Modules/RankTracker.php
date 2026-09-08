<?php
/**
 * Rank tracking: daily SERP checks against GSC / Serper / DataForSEO / SerpAPI,
 * a local history table, movers, alerts and cost guards.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Class RankTracker
 */
final class RankTracker {

	/**
	 * Singleton.
	 *
	 * @var RankTracker|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return RankTracker
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Hooks.
	 */
	public function bootstrap() {
		add_action( 'admin_post_hoosh_rank_check', array( __CLASS__, 'ajax_check' ) );
	}

	/**
	 * Admin-post: run a check now.
	 */
	public static function ajax_check() {
		check_admin_referer( 'hoosh_seo_tools' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازه ندارید.', 'hoosh-seo' ) );
		}
		self::run( array( 'limit' => 40 ) );
		$url = wp_get_referer() ? wp_get_referer() : \HooshSEO\App::studio_url();
		wp_safe_redirect( add_query_arg( 'hs_msg', 'rank-checked', $url ) );
		exit;
	}

	/**
	 * Providers available right now.
	 *
	 * @return array
	 */
	public static function providers() {
		$settings = \hoosh_seo()->settings;
		return array(
			'gsc'        => array(
				'label'   => __( 'سرچ کنسول (دقیق‌ترین برای صفحات خودی)', 'hoosh-seo' ),
				'ready'   => Analytics::connected( 'gsc' ),
				'cost'    => 0,
				'accuracy' => 'high',
				'note'    => __( 'میانگین جایگاه واقعی از کلیک‌کنندگان؛ نه رتبه لحظه‌ای.', 'hoosh-seo' ),
			),
			'serper'     => array(
				'label'   => 'Serper.dev',
				'ready'   => (bool) $settings->get( 'analytics.serper.key' ),
				'cost'    => 0.0003,
				'accuracy' => 'high',
				'note'    => __( 'هر کوئری ≈ ۰٫۳۰ دلار به ازای هزار درخواست.', 'hoosh-seo' ),
			),
			'dataforseo' => array(
				'label'   => 'DataForSEO SERP',
				'ready'   => (bool) $settings->get( 'analytics.dataforseo.password' ),
				'cost'    => 0.0012,
				'accuracy' => 'high',
			),
			'serpapi'    => array(
				'label'   => 'SerpAPI',
				'ready'   => (bool) $settings->get( 'analytics.serpapi.key' ),
				'cost'    => 0.003,
				'accuracy' => 'high',
			),
			'scraper'    => array(
				'label'   => __( 'خزش مستقیم گوگل (بدون سرویس)', 'hoosh-seo' ),
				'ready'   => true,
				'cost'    => 0,
				'accuracy' => 'low',
				'note'    => __( 'بدون کلید کار می‌کند ولی ممکن است هر از گاهی بن شود یا خطا بدهد.', 'hoosh-seo' ),
				'fragile' => true,
			),
			'bing'       => array(
				'label'   => __( 'بین (خزش)', 'hoosh-seo' ),
				'ready'   => true,
				'cost'    => 0,
				'accuracy' => 'low',
			),
			'yahoo'      => array(
				'label'   => __( 'یاهوم (خزش)', 'hoosh-seo' ),
				'ready'   => true,
				'cost'    => 0,
				'accuracy' => 'low',
			),
		);
	}

	/**
	 * Chosen provider (falls back to scraper).
	 *
	 * @return string
	 */
	public static function provider() {
		$want  = (string) \hoosh_seo()->settings->get( 'analytics.rank_tracker.source', 'gsc' );
		$avail = self::providers();
		if ( isset( $avail[ $want ] ) && ! empty( $avail[ $want ]['ready'] ) ) {
			return $want;
		}
		foreach ( $avail as $key => $meta ) {
			if ( ! empty( $meta['ready'] ) ) {
				return $key;
			}
		}
		return 'scraper';
	}

	/**
	 * Tracking config summary for the Studio.
	 *
	 * @return array
	 */
	public static function config() {
		$settings = \hoosh_seo()->settings;
		return array(
			'provider'   => self::provider(),
			'providers'  => self::providers(),
			'country'    => (string) $settings->get( 'analytics.rank_tracker.country', 'ir' ),
			'language'   => (string) $settings->get( 'analytics.rank_tracker.lang', 'fa' ),
			'device'     => (string) $settings->get( 'analytics.rank_tracker.device', 'desktop' ),
			'frequency'  => (string) $settings->get( 'analytics.rank_tracker.schedule', 'daily' ),
			'depth'      => (int) $settings->get( 'analytics.rank_tracker.depth', 20 ),
			'cost_limit' => (float) $settings->get( 'analytics.rank_tracker.cost_limit', 2 ),
			'max'        => (int) $settings->get( 'analytics.rank_tracker.max_kw', 50 ),
			'personalize' => (bool) $settings->get( 'analytics.rank_tracker.personalize', false ),
			'report_email' => (string) $settings->get( 'analytics.rank_tracker.notify', get_option( 'admin_email' ) ),
			'spend_today'  => self::spend_today(),
		);
	}

	/**
	 * Spend guard for today.
	 *
	 * @return float
	 */
	public static function spend_today() {
		$day = get_transient( 'hoosh_rank_spend_' . gmdate( 'Ymd' ) );
		return (float) ( $day ? $day : 0 );
	}

	/**
	 * Log spend.
	 *
	 * @param float $amount Amount.
	 */
	protected static function add_spend( $amount ) {
		$key   = 'hoosh_rank_spend_' . gmdate( 'Ymd' );
		$total = self::spend_today() + (float) $amount;
		set_transient( $key, $total, 2 * DAY_IN_SECONDS );
	}

	/**
	 * Keywords due for a check.
	 *
	 * @param array $args {ids, limit, kind}.
	 * @return array
	 */
	public static function due( $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args( (array) $args, array( 'ids' => array(), 'limit' => 50, 'kind' => '' ) );
		$table = Database::table( 'keywords' );
		$ids   = array_values( array_filter( array_map( 'absint', (array) $args['ids'] ) ) );

		$limit = max( 1, min( 500, (int) $args['limit'] ) );
		if ( $ids ) {
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			return (array) $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id IN (' . $in . ')', $ids ),
				ARRAY_A
			);
		}

		$sql = "SELECT k.* FROM {$table} k WHERE k.state = 'tracking'";
		if ( $args['kind'] ) {
			$sql .= $wpdb->prepare( ' AND k.kind = %s', sanitize_key( $args['kind'] ) ); // phpcs:ignore
		}
		// Skip rows checked recently.
		$frequency = (string) \hoosh_seo()->settings->get( 'analytics.rank_tracker.schedule', 'daily' );
		$gap       = 'hourly' === $frequency ? HOUR_IN_SECONDS : ( 'weekly' === $frequency ? 6 * DAY_IN_SECONDS : DAY_IN_SECONDS );
		$cut       = gmdate( 'Y-m-d H:i:s', time() - $gap );
		$sql      .= $wpdb->prepare( ' AND ( updated_at < %s OR position = 0 ) ORDER BY volume DESC LIMIT %d', $cut, $limit ); // phpcs:ignore

		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Run a tracking pass.
	 *
	 * @param array $args {ids, limit, provider, force}.
	 * @return array
	 */
	public static function run( $args = array() ) {
		$args = wp_parse_args(
			(array) $args,
			array(
				'ids'      => array(),
				'limit'    => (int) \hoosh_seo()->settings->get( 'analytics.rank_tracker.max_kw', 50 ),
				'provider' => '',
				'force'    => false,
			)
		);

		$provider = $args['provider'] ? sanitize_key( $args['provider'] ) : self::provider();
		$rows     = self::due( array( 'ids' => $args['ids'], 'limit' => (int) $args['limit'] ) );

		if ( ! $rows ) {
			return array(
				'ok'      => true,
				'checked' => 0,
				'message' => __( 'چیزی برای سنجش نبود؛ کلمات را در خزانه وضعیت «ردیابی» کنید.', 'hoosh-seo' ),
			);
		}

		$limit = (float) \hoosh_seo()->settings->get( 'analytics.rank_tracker.cost_limit', 2 );
		$cost  = self::spend_today();
		$meta  = self::providers();
		$price = (float) ( $meta[ $provider ]['cost'] ?? 0 );
		if ( $limit > 0 && $cost >= $limit ) {
			return array(
				'ok'      => false,
				'limited' => true,
				'checked' => 0,
				'message' => sprintf( /* translators: 1: spend, 2: limit */ __( 'سقف هزینه روزانه ردیابی پر شده (%1$s از %2$s دلار).', 'hoosh-seo' ), number_format_i18n( $cost, 4 ), number_format_i18n( $limit, 2 ) ),
			);
		}

		$started = time();
		$checked = 0;
		$found   = 0;
		$errors  = 0;
		$results = array();

		foreach ( $rows as $row ) {
			$out  = self::locate( (string) $row['phrase'], (string) $row['url'], $provider );
			$cost += $price;
			self::add_spend( $price );

			if ( ! $out['ok'] ) {
				$errors++;
				$results[] = array( 'keyword' => $row['phrase'], 'ok' => false, 'error' => $out['error'] );
				if ( self::spend_today() >= $limit && $limit > 0 ) {
					break;
				}
				continue;
			}

			$checked++;
			if ( $out['position'] ) {
				$found++;
			}
			self::record( (int) $row['id'], $out );
			$results[] = array(
				'keyword'  => $row['phrase'],
				'ok'       => true,
				'position' => (int) $out['position'],
				'delta'    => $out['delta'],
				'url'      => (string) $row['url'],
			);

			if ( ( time() - $started ) > 55 ) {
				break;
			}
			usleep( 400000 );
		}

		Helpers::cache_flush_all();

		$summary = array(
			'ok'      => true,
			'provider' => $provider,
			'checked' => $checked,
			'found'   => $found,
			'errors'  => $errors,
			'left'    => max( 0, count( $rows ) - ( $checked + $errors ) ),
			'spend'   => round( $cost, 5 ),
			'duration' => time() - $started,
			'results' => $results,
			'message' => $checked
				? sprintf( /* translators: 1: checked, 2: found */ __( '%1$d کلمه سنجیده شد؛ %2$d تا در نتایج پیدا شدند.', 'hoosh-seo' ), $checked, $found )
				: __( 'سنجی نتیجه‌ای نداشت.', 'hoosh-seo' ),
		);

		Audit::log(
			'tracking',
			'check',
			array(
				'label' => sprintf( /* translators: 1: count, 2: provider */ __( 'سنجی جایگاه با %2$s: %1$d کلمه', 'hoosh-seo' ), $checked, $provider ),
				'after' => array( 'checked' => $checked, 'found' => $found, 'errors' => $errors ),
			)
		);

		return $summary;
	}

	/**
	 * Locate one keyword.
	 *
	 * @param string $phrase   Keyword.
	 * @param string $url      Our URL.
	 * @param string $provider Provider key.
	 * @return array
	 */
	public static function locate( $phrase, $url, $provider = '' ) {
		$provider = $provider ? $provider : self::provider();
		$settings = \hoosh_seo()->settings;

		$args = array(
			'country'  => (string) $settings->get( 'analytics.rank_tracker.country', 'ir' ),
			'language' => (string) $settings->get( 'analytics.rank_tracker.lang', 'fa' ),
			'device'   => (string) $settings->get( 'analytics.rank_tracker.device', 'desktop' ),
			'depth'    => max( 10, min( 100, (int) $settings->get( 'analytics.rank_tracker.depth', 20 ) ) ),
			'personal' => (bool) $settings->get( 'analytics.rank_tracker.personalize', false ),
		);

		switch ( $provider ) {
			case 'gsc':
				return self::locate_gsc( $phrase, $url, $args );
			case 'serper':
				return self::locate_serper( $phrase, $url, $args );
			case 'dataforseo':
				return self::locate_dataforseo( $phrase, $url, $args );
			case 'serpapi':
				return self::locate_serpapi( $phrase, $url, $args );
			case 'bing':
				return self::locate_scraper( $phrase, $url, $args, 'bing' );
			case 'yahoo':
				return self::locate_scraper( $phrase, $url, $args, 'yahoo' );
		}
		return self::locate_scraper( $phrase, $url, $args, 'google' );
	}

	/**
	 * Common normalisation of a locate result.
	 *
	 * @param int   $position Position (0 = not found).
	 * @param array $extra    Extra.
	 * @return array
	 */
	protected static function position_result( $position, $extra = array() ) {
		return array_merge(
			array(
				'ok'       => true,
				'position' => (int) $position,
				'found'    => (int) $position > 0,
				'error'    => '',
			),
			(array) $extra
		);
	}

	/**
	 * GSC average position for a query+page pair.
	 *
	 * @param string $phrase Keyword.
	 * @param string $url    URL.
	 * @param array  $args   Args.
	 * @return array
	 */
	protected static function locate_gsc( $phrase, $url, $args ) {
		$rel  = Helpers::rel_url( (string) $url );
		$rows = Analytics::query(
			'searchAnalytics',
			array(
				'startDate'   => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
				'endDate'     => gmdate( 'Y-m-d' ),
				'dimensions'  => array( 'query', 'page' ),
				'dimensionFilterGroups' => array(
					array(
						'filters' => array(
							array( 'dimension' => 'page', 'operator' => 'equals', 'expression' => home_url( $rel ) ),
							array( 'dimension' => 'query', 'operator' => 'is', 'expression' => $phrase ),
						),
					),
				),
				'rowLimit'    => 25,
				'dimensionFilterGroupJoinWith' => array( 'AND' ),
			)
		);

		if ( ! is_array( $rows ) ) {
			return array( 'ok' => false, 'position' => 0, 'error' => 'gsc-unavailable' );
		}
		foreach ( $rows as $row ) {
			$keys = (array) ( $row['keys'] ?? array() );
			if ( $keys && Helpers::normalize_fa( (string) $keys[0] ) === Helpers::normalize_fa( $phrase ) ) {
				return self::position_result( (int) round( (float) ( $row['position'] ?? 0 ) ), array(
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'source'      => 'gsc',
					'metric'      => 'average',
				) );
			}
		}
		return self::position_result( 0, array( 'source' => 'gsc' ) );
	}

	/**
	 * Serper.dev SERP.
	 *
	 * @param string $phrase Keyword.
	 * @param string $url    URL.
	 * @param array  $args   Args.
	 * @return array
	 */
	protected static function locate_serper( $phrase, $url, $args ) {
		$settings = \hoosh_seo()->settings;
		$raw      = Helpers::http(
			'https://google.serper.dev/search',
			array(
				'method'  => 'POST',
				'timeout' => 12,
				'headers' => array(
					'X-API-KEY'  => (string) $settings->get( 'analytics.serper.key' ),
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'q'   => $phrase,
						'gl'  => strtolower( $args['country'] ),
						'hl'  => $args['language'],
						'num' => min( 100, $args['depth'] ),
						'device' => 'mobile' === $args['device'] ? 'mobile' : 'desktop',
					)
				),
			)
		);
		if ( empty( $raw['ok'] ) ) {
			return array( 'ok' => false, 'position' => 0, 'error' => 'serper-http' );
		}
		$json    = json_decode( (string) $raw['body'], true );
		$results = (array) ( $json['organic'] ?? array() );
		return self::rank_in( $results, $url, 'organic', array( 'source' => 'serper' ) );
	}

	/**
	 * DataForSEO live SERP.
	 *
	 * @param string $phrase Keyword.
	 * @param string $url    URL.
	 * @param array  $args   Args.
	 * @return array
	 */
	protected static function locate_dataforseo( $phrase, $url, $args ) {
		$settings = \hoosh_seo()->settings;
		$raw      = Helpers::http(
			'https://api.dataforseo.com/v3/serp/google/organic/live/advanced',
			array(
				'method'   => 'POST',
				'timeout'  => 20,
				'auth'     => array( (string) $settings->get( 'analytics.dataforseo.login', '' ), (string) $settings->get( 'analytics.dataforseo.password', '' ) ),
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						array(
							'keyword'        => $phrase,
							'language_code'  => 'fa',
							'location_code'  => 'ir' === strtolower( $args['country'] ) ? 2171 : 0,
							'location'       => '',
							'device'         => 'mobile' === $args['device'] ? 'mobile' : 'desktop',
							'os'             => 'windows',
							'keyword_limit'  => min( 100, $args['depth'] ),
						),
					)
				),
			)
		);
		if ( empty( $raw['ok'] ) ) {
			return array( 'ok' => false, 'position' => 0, 'error' => 'dataforseo-http' );
		}
		$json  = json_decode( (string) $raw['body'], true );
		$items = (array) ( $json['tasks'][0]['result'][0]['items'] ?? array() );
		$items = array_values( array_filter( $items, 'is_array' ) );
		return self::rank_in( $items, $url, 'serp', array( 'source' => 'dataforseo' ) );
	}

	/**
	 * SerpAPI.
	 *
	 * @param string $phrase Keyword.
	 * @param string $url    URL.
	 * @param array  $args   Args.
	 * @return array
	 */
	protected static function locate_serpapi( $phrase, $url, $args ) {
		$settings = \hoosh_seo()->settings;
		$query    = array(
			'engine'  => 'google',
			'q'       => $phrase,
			'hl'      => $args['language'],
			'gl'      => strtolower( $args['country'] ),
			'num'     => min( 100, $args['depth'] ),
			'num'     => 100,
			'api_key' => (string) $settings->get( 'analytics.serpapi.key' ),
		);
		if ( 'mobile' === $args['device'] ) {
			$query['device'] = 'mobile';
		}
		$raw = Helpers::http( 'https://serpapi.com/search.json?' . http_build_query( $query ), array( 'timeout' => 15 ) );
		if ( empty( $raw['ok'] ) ) {
			return array( 'ok' => false, 'position' => 0, 'error' => 'serpapi-http' );
		}
		$json    = json_decode( (string) $raw['body'], true );
		$results = (array) ( $json['organic_results'] ?? array() );
		return self::rank_in( $results, $url, 'organic', array( 'source' => 'serpapi' ) );
	}

	/**
	 * Direct scraping fallback (Google/Bing/Yahoo).
	 *
	 * @param string $phrase Keyword.
	 * @param string $url    URL.
	 * @param array  $args   Args.
	 * @param string $engine Engine.
	 * @return array
	 */
	protected static function locate_scraper( $phrase, $url, $args, $engine = 'google' ) {
		$target = Helpers::rel_url( (string) $url );
		$host   = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
		$host   = preg_replace( '/^www\./', '', $host );

		$endpoints = array(
			'google' => 'https://www.google.com/search?' . http_build_query(
				array(
					'q'    => $phrase,
					'hl'   => $args['language'],
					'gl'   => strtolower( $args['country'] ),
					'num'  => 100,
					'nfpr' => 1,
				)
			),
			'bing'   => 'https://www.bing.com/search?' . http_build_query( array( 'q' => $phrase, 'count' => 50, 'setlang' => $args['language'], 'cc' => strtoupper( $args['country'] ) ) ),
			'yahoo'  => 'https://search.yahoo.com/search?' . http_build_query( array( 'p' => $phrase, 'n' => 50 ) ),
		);
		$endpoint = isset( $endpoints[ $engine ] ) ? $endpoints[ $engine ] : $endpoints['google'];

		$raw = Helpers::http(
			$endpoint,
			array(
				'timeout' => 12,
				'headers' => array(
					'User-Agent'      => Helpers::random_ua(),
					'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.5',
					'Accept'          => 'text/html,application/xhtml+xml',
				),
			)
		);
		if ( empty( $raw['ok'] ) || stripos( (string) $raw['body'], 'unusual traffic' ) !== false || 429 === (int) $raw['code'] ) {
			return array( 'ok' => false, 'position' => 0, 'error' => 'serp-blocked' );
		}

		$html = (string) $raw['body'];
		$links = array();
		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( (array) $m[1] as $href ) {
				$links[] = (string) html_entity_decode( $href, ENT_QUOTES, 'UTF-8' );
			}
		}

		// Google wraps results in /url?q= — unwrap.
		$clean = array();
		foreach ( $links as $link ) {
			if ( preg_match( '#[?&]q=([^&]+)#', $link, $m ) ) {
				$link = rawurldecode( $m[1] );
			}
			if ( preg_match( '#^https?://#i', $link ) ) {
				$clean[] = $link;
			}
		}

		$position = 0;
		foreach ( $clean as $index => $link ) {
			$link_host = (string) wp_parse_url( $link, PHP_URL_HOST );
			$link_host = preg_replace( '/^www\./', '', $link_host );
			if ( $host && $link_host !== $host ) {
				continue;
			}
			if ( $target && false === strpos( Helpers::rel_url( $link ), $target ) ) {
				continue;
			}
			$position = (int) $index + 1;
			break;
		}

		return self::position_result( $position, array(
			'source'   => $engine,
			'method'   => 'scrape',
			'accuracy' => 'low',
		) );
	}

	/**
	 * Find our rank in a provider result set.
	 *
	 * @param array  $results Results.
	 * @param string $url     URL.
	 * @param string $kind    Kind.
	 * @param array  $extra   Extra.
	 * @return array
	 */
	protected static function rank_in( $results, $url, $kind, $extra = array() ) {
		$target = untrailingslashit( (string) $url );
		$host   = (string) wp_parse_url( $target, PHP_URL_HOST );
		$host   = preg_replace( '/^www\./', '', $host );
		$rel    = Helpers::rel_url( $target );

		$index = 0;
		foreach ( (array) $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$link     = (string) ( $row['link'] ?? $row['url'] ?? $row['displayed_link'] ?? '' );
			$position = isset( $row['position'] ) ? (int) $row['position'] : 0;
			if ( $position ) {
				$index = $position;
			} else {
				$index++;
			}
			if ( ! $link ) {
				continue;
			}
			$link_host = preg_replace( '/^www\./', '', (string) wp_parse_url( $link, PHP_URL_HOST ) );
			if ( $host && $link_host !== $host ) {
				continue;
			}
			// Same host: accept path match, else rank 1st host hit.
			if ( $rel ) {
				$link_rel = Helpers::rel_url( $link );
				if ( $link_rel === $rel || 0 === strpos( $rel, $link_rel ) || 0 === strpos( $link_rel, $rel ) ) {
					return self::position_result( $index, array_merge( array( 'source' => $kind ), $extra ) );
				}
			} else {
				return self::position_result( $index, array_merge( array( 'source' => $kind ), $extra ) );
			}
		}
		return self::position_result( 0, array_merge( array( 'source' => $kind ), $extra ) );
	}

	/**
	 * Store one observation.
	 *
	 * @param int   $keyword_id Keyword row.
	 * @param array $out        Locate result.
	 * @return int
	 */
	public static function record( $keyword_id, $out ) {
		global $wpdb;
		$keyword_id = (int) $keyword_id;
		if ( ! $keyword_id || ! Database::exists( 'positions' ) ) {
			return 0;
		}
		$today    = gmdate( 'Y-m-d' );
		$position = (int) ( $out['position'] ?? 0 );

		$prev = (float) $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare(
				'SELECT position FROM ' . Database::table( 'keywords' ) . ' WHERE id = %d',
				$keyword_id
			)
		);
		$prev_pos = (float) $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare(
				'SELECT position FROM ' . Database::table( 'positions' ) . ' WHERE keyword_id = %d AND recorded_on < %s ORDER BY recorded_on DESC LIMIT 1',
				$keyword_id,
				$today
			)
		);

		$delta = ( $prev_pos && $position ) ? round( $prev_pos - $position, 1 ) : ( $position ? null : 0 );

		$exists = (int) $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare(
				'SELECT id FROM ' . Database::table( 'positions' ) . ' WHERE keyword_id = %d AND recorded_on = %s LIMIT 1',
				$keyword_id,
				$today
			)
		);
		$data = array(
			'keyword_id'    => $keyword_id,
			'recorded_on'   => $today,
			'position'      => $position ? $position : 101,
			'prev_position' => $prev_pos ? $prev_pos : $prev,
			'delta'         => (float) $delta,
			'url'           => mb_substr( (string) ( $out['url'] ?? '' ), 0, 480 ),
			'source'        => mb_substr( (string) ( $out['source'] ?? '' ), 0, 30 ),
			'clicks'        => (int) ( $out['clicks'] ?? 0 ),
			'impressions'   => (int) ( $out['impressions'] ?? 0 ),
			'device'        => mb_substr( (string) \hoosh_seo()->settings->get( 'analytics.rank_tracker.device', 'desktop' ), 0, 10 ),
			'created_at'    => current_time( 'mysql', true ),
		);

		if ( $exists ) {
			unset( $data['keyword_id'], $data['recorded_on'] );
			$wpdb->update( Database::table( 'positions' ), $data, array( 'id' => $exists ) ); // phpcs:ignore
			$id = $exists;
		} else {
			$wpdb->insert( Database::table( 'positions' ), $data ); // phpcs:ignore
			$id = (int) $wpdb->insert_id;
		}

		$wpdb->update( // phpcs:ignore
			Database::table( 'keywords' ),
			array(
				'position'      => $position,
				'prev_position' => $prev ?: $prev_pos,
				'clicks'        => (int) ( $out['clicks'] ?? 0 ),
				'impressions'   => (int) ( $out['impressions'] ?? 0 ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => $keyword_id )
		);

		// Alerts for big drops.
		if ( $delta && $delta <= -5 ) {
			$phrase = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT phrase FROM ' . Database::table( 'keywords' ) . ' WHERE id = %d', $keyword_id ) ); // phpcs:ignore
			Admin::push_notice(
				'rank-drop-' . $keyword_id,
				'warning',
				__( 'افت شدید جایگاه', 'hoosh-seo' ),
				sprintf( /* translators: 1: keyword, 2: drop */ __( '«%1$s» %2$d رتبه افت کرد (از %3$s به %4$s).', 'hoosh-seo' ), $phrase, abs( (int) $delta ), Helpers::number( $prev_pos ), Helpers::number( $position ) )
			);
		}

		return $id;
	}

	/**
	 * Track / untrack keywords.
	 *
	 * @param array  $ids  Ids.
	 * @param string $state New state.
	 * @return array
	 */
	public static function set_state( $ids, $state ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return array( 'ok' => false, 'updated' => 0, 'message' => __( 'کلمه‌ای انتخاب نشده.', 'hoosh-seo' ) );
		}
		$allowed = array( 'saved', 'tracking', 'archived', 'ignored' );
		$state   = in_array( $state, $allowed, true ) ? $state : 'saved';
		$in      = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::table( 'keywords' ) . ' SET state = %s, updated_at = UTC_TIMESTAMP() WHERE id IN (' . $in . ')', array_merge( array( $state ), $ids ) ) ); // phpcs:ignore

		return array(
			'ok'      => true,
			'updated' => count( $ids ),
			'state'   => $state,
			'message' => sprintf( /* translators: 1: count, 2: state */ __( '%1$d کلمه به وضعیت %2$s رفت.', 'hoosh-seo' ), count( $ids ), self::state_label( $state ) ),
		);
	}

	/**
	 * State label.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function state_label( $state ) {
		$map = array(
			'saved'    => __( 'ذخیره', 'hoosh-seo' ),
			'tracking' => __( 'ردیابی', 'hoosh-seo' ),
			'archived' => __( 'بایگانی', 'hoosh-seo' ),
			'ignored'  => __( 'نادیده', 'hoosh-seo' ),
		);
		return isset( $map[ $state ] ) ? $map[ $state ] : $state;
	}

	/**
	 * Bulk edit volume/difficulty/tags.
	 *
	 * @param array $ids  Ids.
	 * @param array $data Fields.
	 * @return array
	 */
	public static function bulk_update( $ids, $data ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return array( 'ok' => false, 'message' => __( 'چیزی انتخاب نشده.', 'hoosh-seo' ) );
		}
		$update = array();
		foreach ( array( 'volume' => 'int', 'difficulty' => 'int', 'cpc' => 'float' ) as $field => $type ) {
			if ( isset( $data[ $field ] ) && '' !== $data[ $field ] ) {
				$update[ $field ] = 'int' === $type ? (int) $data[ $field ] : (float) $data[ $field ];
			}
		}
		if ( isset( $data['intent'] ) ) {
			$update['intent'] = sanitize_key( (string) $data['intent'] );
		}
		if ( isset( $data['tags'] ) ) {
			$update['tags'] = wp_json_encode( array_values( array_map( 'sanitize_text_field', (array) $data['tags'] ) ) );
		}
		if ( isset( $data['post_id'] ) ) {
			$update['post_id'] = (int) $data['post_id'];
			$update['url']     = $update['post_id'] ? mb_substr( (string) get_permalink( $update['post_id'] ), 0, 480 ) : '';
		}
		if ( isset( $data['phrase'] ) && 1 === count( $ids ) ) {
			$update['phrase'] = mb_substr( sanitize_text_field( (string) $data['phrase'] ), 0, 250 );
			$update['norm']   = Helpers::normalize_fa( $update['phrase'] );
		}
		if ( ! $update ) {
			return array( 'ok' => false, 'message' => __( 'فیلدی برای ذخیره نبود.', 'hoosh-seo' ) );
		}
		$update['updated_at'] = current_time( 'mysql', true );

		$table = Database::table( 'keywords' );
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$set   = array();
		$params = array();
		foreach ( $update as $field => $value ) {
			$set[]    = '`' . $field . '` = %s';
			$params[] = $value;
		}
		$sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $set ) . ' WHERE id IN (' . $in . ')';
		$wpdb->query( $wpdb->prepare( $sql, array_merge( $params, $ids ) ) ); // phpcs:ignore

		return array( 'ok' => true, 'updated' => count( $ids ), 'message' => __( 'تغییرات ذخیره شد.', 'hoosh-seo' ) );
	}

	/**
	 * History for a keyword (Studio sparkline).
	 *
	 * @param int   $keyword_id Keyword id.
	 * @param int   $days       Window.
	 * @param array $args       Filters.
	 * @return array
	 */
	public static function series( $keyword_id, $days = 30, $args = array() ) {
		global $wpdb;
		$days = max( 3, min( 365, (int) $days ) );
		if ( ! Database::exists( 'positions' ) ) {
			return array( 'rows' => array(), 'labels' => array() );
		}
		$sql = 'SELECT recorded_on, position, prev_position, delta, clicks, impressions, source, url FROM ' . Database::table( 'positions' ) . ' WHERE recorded_on >= DATE_SUB(CURDATE(), INTERVAL %d DAY)';
		$params = array( $days );
		if ( (int) $keyword_id ) {
			$sql     .= ' AND keyword_id = %d';
			$params[]  = (int) $keyword_id;
		}
		$sql .= ' ORDER BY recorded_on ASC';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore

		$out    = array();
		$labels = array();
		$labels_fa = array();
		foreach ( $rows as $row ) {
			$position = (int) $row['position'];
			$out[]    = array(
				'date'      => (string) $row['recorded_on'],
				'position'  => $position,
				'delta'     => (float) ( $row['delta'] ?? 0 ),
				'clicks'    => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'source'    => (string) $row['source'],
				'url'       => (string) $row['url'],
			);
			$labels[] = (string) $row['recorded_on'];
			$labels_fa[] = (string) Helpers::jalali_date( strtotime( (string) $row['recorded_on'] ), 'j/n' );
		}

		$labels = array_reverse( $labels );
		unset( $labels );
		$labels_rtl = array_reverse( $labels_fa );
		unset( $labels_rtl );

		return array(
			'rows'    => array_values( array_reverse( $out ) ),
			'labels'  => array_values( $labels_fa ),
			'count'   => count( $out ),
			'average' => $out ? round( array_sum( array_column( $out, 'position' ) ) / max( 1, count( $out ) ), 1 ) : 0,
			'best'    => $out ? min( array_column( $out, 'position' ) ) : 0,
			'worst'   => $out ? max( array_column( $out, 'position' ) ) : 0,
		);
	}

	/**
	 * Tracked keywords with movement.
	 *
	 * @param array $args {limit, search}.
	 * @return array
	 */
	public static function keywords( $args = array() ) {
		$args  = wp_parse_args( (array) $args, array( 'limit' => 100, 'search' => '' ) );
		$items = KeywordResearch::all(
			array(
				'state'   => 'tracking',
				'per'     => max( 5, min( 1000, (int) $args['limit'] ) ),
				'search'  => (string) $args['search'],
				'orderby' => 'position',
				'order'   => 'asc',
			)
		);
		$rows = array();
		foreach ( (array) $items['rows'] as $row ) {
			$rows[] = array(
				'id'        => (int) $row['id'],
				'keyword'   => (string) $row['phrase'],
				'position'  => (float) $row['position'],
				'prev'      => (float) $row['prev_position'],
				'delta'     => $row['delta'],
				'volume'    => (int) $row['volume'],
				'url'       => (string) $row['url'],
				'post_title' => (string) ( $row['post_title'] ?? '' ),
				'clicks'    => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'intent'    => (string) $row['intent'],
				'source'    => (string) $row['source'],
				'updated'   => (string) $row['updated_at'],
				'series'    => self::series( (int) $row['id'], 30 ),
			);
		}
		return $rows;
	}

	/**
	 * Dashboard summary.
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;
		$empty = array(
			'covered'  => 0,
			'tracking' => 0,
			'top3'     => 0,
			'top10'    => 0,
			'improved' => 0,
			'lost'     => 0,
			'average'  => 0,
			'last_run' => '',
			'series'   => array(),
			'labels'   => array(),
			'gainers'  => array(),
			'losers'   => array(),
		);
		if ( ! Database::exists( 'keywords' ) ) {
			return $empty;
		}

		$row = (array) $wpdb->get_row( // phpcs:ignore
			'SELECT COUNT(*) total, SUM(state=\'tracking\') tracking,
				SUM(position > 0 AND position <= 3) top3, SUM(position > 0 AND position <= 10) top10,
				AVG(CASE WHEN position BETWEEN 1 AND 100 THEN position END) avg_pos,
				SUM(position > 0 AND prev_position > position) improved,
				SUM(position > prev_position AND prev_position > 0) lost
			 FROM ' . Database::table( 'keywords' ),
			ARRAY_A
		);

		$last = (string) $wpdb->get_var( 'SELECT MAX(recorded_on) FROM ' . Database::table( 'positions' ) ); // phpcs:ignore

		// Average position per day over tracked keywords.
		$rows   = (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT recorded_on d, AVG(position) p FROM ' . Database::table( 'positions' ) . '
				 WHERE position <= 100 AND recorded_on >= DATE_SUB(CURDATE(), INTERVAL %d DAY) GROUP BY recorded_on ORDER BY recorded_on ASC',
				90
			),
			ARRAY_A
		);
		$series = array();
		$labels = array();
		foreach ( $rows as $r ) {
			$series[] = round( (float) $r['p'], 1 );
			$labels[] = Helpers::jalali_date( strtotime( (string) $r['d'] ), 'j/n' );
		}

		$movers = (array) $wpdb->get_results( // phpcs:ignore
			'SELECT phrase, url, position, prev_position, (prev_position - position) delta, volume FROM ' . Database::table( 'keywords' ) . '
			 WHERE state = \'tracking\' AND position > 0 AND prev_position > 0 ORDER BY delta DESC LIMIT 8',
			ARRAY_A
		);
		$losers = (array) $wpdb->get_results( // phpcs:ignore
			'SELECT phrase, url, position, prev_position, (prev_position - position) delta, volume FROM ' . Database::table( 'keywords' ) . '
			 WHERE state = \'tracking\' AND position > 0 AND prev_position > 0 ORDER BY delta ASC LIMIT 8',
			ARRAY_A
		);
		foreach ( array( $movers, $losers ) as &$set ) {
			foreach ( $set as &$m ) {
				$m['delta'] = (float) $m['delta'];
				$m['position'] = (float) $m['position'];
				$m['volume'] = (int) $m['volume'];
			}
			unset( $m );
		}
		unset( $set );

		return array(
			'covered'   => (int) ( $row['total'] ?? 0 ),
			'tracking'  => (int) ( $row['tracking'] ?? 0 ),
			'top3'      => (int) ( $row['top3'] ?? 0 ),
			'top10'     => (int) ( $row['top10'] ?? 0 ),
			'improved'  => (int) ( $row['improved'] ?? 0 ),
			'lost'      => (int) ( $row['lost'] ?? 0 ),
			'average'   => $row['avg_pos'] ? round( (float) $row['avg_pos'], 1 ) : 0,
			'last_run'  => $last ? Helpers::time_ago_fa( strtotime( $last . ' UTC' ) ) : '',
			'series'    => array_reverse( $series ),
			'labels'    => array_reverse( $labels ),
			'gainers'   => $movers,
			'losers'    => $losers,
			'provider'  => self::provider(),
		);
	}

	/**
	 * Retention.
	 */
	public static function prune() {
		global $wpdb;
		$days = (int) \hoosh_seo()->settings->get( 'analytics.rank_tracker.retain', 365 );
		if ( $days < 7 || ! Database::exists( 'positions' ) ) {
			return 0;
		}
		return (int) $wpdb->query( // phpcs:ignore
			$wpdb->prepare( 'DELETE FROM ' . Database::table( 'positions' ) . ' WHERE recorded_on < DATE_SUB(CURDATE(), INTERVAL %d DAY)', $days )
		);
	}

	/**
	 * Check specific keywords now (Studio button).
	 *
	 * @param array $ids   Keyword ids.
	 * @param int   $limit Fallback limit.
	 * @return array
	 */
	public static function check( $ids = array(), $limit = 0 ) {
		return self::run(
			array(
				'ids'   => (array) $ids,
				'limit' => $limit ? (int) $limit : 25,
				'force' => true,
			)
		);
	}

}
