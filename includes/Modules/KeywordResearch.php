<?php
/**
 * Persian keyword research: 13 Iranian + global suggest sources, prefix/suffix
 * combiner, alphabet machine, depth expansion and a saved keyword vault.
 *
 * Endpoints are the public suggestion APIs of each service; they occasionally
 * move, so every source url/parser can be overridden in the settings
 * (keywords.sources_config) without touching code.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class KeywordResearch
 */
final class KeywordResearch {

	const OPTION = 'hoosh_kw_research';

	/**
	 * Singleton.
	 *
	 * @var KeywordResearch|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return KeywordResearch
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
		add_action( 'hoosh_seo_batch', array( __CLASS__, 'tick' ) );
	}

	/**
	 * Cron tick: keep a running session progressing.
	 */
	public static function tick() {
		$state = self::state();
		if ( empty( $state['running'] ) || empty( $state['session'] ) ) {
			return;
		}
		self::pump( 12 );
	}

	/**
	 * Source catalog.
	 *
	 * @return array
	 */
	public static function sources() {
		$catalog = array(
			'google'    => array(
				'label'   => 'Google Suggest',
				'type'    => 'suggest',
				'url'     => 'https://suggestqueries.google.com/complete/search?client=firefox&hl=%%lang%%&gl=%%country%%&q=%%query%%',
				'parser'  => 'firefox',
				'note'    => __( 'زنده‌ترین و بی‌سروصدا‌ترین منبع؛ پیشنهادها را از جستجوی واقعی کاربران می‌آورد.', 'hoosh-seo' ),
				'share'   => 1000,
			),
			'bing'      => array(
				'label'   => 'Bing Suggest',
				'type'    => 'suggest',
				'url'     => 'https://www.bing.com/AS/Suggestions?pt=page.home&mkt=%%mkt%%&qry=%%query%%&cp=1&csr=1&t=0',
				'parser'  => 'bing_html',
				'note'    => __( 'برای کلماتی که در گوگل نمایش داده نمی‌شوند.', 'hoosh-seo' ),
				'share'   => 500,
			),
			'youtube'   => array(
				'label'   => 'YouTube',
				'type'    => 'suggest',
				'url'     => 'https://suggestqueries-clients6.youtube.com/complete/search?client=youtube&hl=%%lang%%&ds=yt&q=%%query%%',
				'parser'  => 'youtube',
				'note'    => __( 'برای موضوعات ویدیویی و آپارات‌پسند.', 'hoosh-seo' ),
				'share'   => 500,
			),
			'digikala'  => array(
				'label'   => __( 'دیجی‌کالا', 'hoosh-seo' ),
				'type'    => 'shop',
				'url'     => 'https://www.digikala.com/ajax/search/suggest/?search-phrase=%%query%%',
				'parser'  => 'digikala',
				'note'    => __( 'برای فروشگاه اینترنتی؛ پیشنهاد محصول و برند.', 'hoosh-seo' ),
				'share'   => 2000,
			),
			'torob'     => array(
				'label'   => __( 'ترب', 'hoosh-seo' ),
				'type'    => 'price',
				'url'     => 'https://www.torob.com/api/v1/suggest/?q=%%query%%',
				'parser'  => 'list',
				'note'    => __( 'مقایسه قیمت؛ کالای پرجستجو.', 'hoosh-seo' ),
				'share'   => 2000,
			),
			'basalam'   => array(
				'label'   => __( 'باسلام', 'hoosh-seo' ),
				'type'    => 'market',
				'url'     => 'https://api.basalam.com/api/store/search/suggest?phrase=%%query%%',
				'parser'  => 'list',
				'note'    => __( 'بازار خانگی و محصولات دستی.', 'hoosh-seo' ),
				'share'   => 1000,
			),
			'snappshop' => array(
				'label'   => __( 'اسنپ‌شاپ', 'hoosh-seo' ),
				'type'    => 'shop',
				'url'     => 'https://snappshop.com/api/search-suggest?phrase=%%query%%',
				'parser'  => 'list',
				'note'    => __( 'پیشنهاد دسته و محصول.', 'hoosh-seo' ),
				'share'   => 1000,
			),
			'divar'     => array(
				'label'   => __( 'دیوار', 'hoosh-seo' ),
				'type'    => 'classified',
				'url'     => 'https://api.divar.ir/v8/suggest?text=%%query%%',
				'parser'  => 'list',
				'note'    => __( 'نیاز واقعی و محلی؛ برای سرویس‌ها و املاک.', 'hoosh-seo' ),
				'share'   => 1000,
			),
			'aparat'    => array(
				'label'   => __( 'آپارات', 'hoosh-seo' ),
				'type'    => 'video',
				'url'     => 'https://www.aparat.com/api/haifa/v3/search/suggest?title=%%query%%',
				'parser'  => 'aparat',
				'share'   => 800,
				'note'    => __( 'موضوعات ویدیویی فارسی.', 'hoosh-seo' ),
			),
			'cafebazaar' => array(
				'label'   => __( 'کافه‌بازار', 'hoosh-seo' ),
				'type'    => 'apps',
				'url'     => 'https://cafebazaar.ir/api/v1/search/suggest?q=%%query%%',
				'parser'  => 'list',
				'share'   => 800,
				'note'    => __( 'برای اپ و بازی.', 'hoosh-seo' ),
			),
			'zerebin'   => array(
				'label'   => __( 'زرع‌بین (ملات)', 'hoosh-seo' ),
				'type'    => 'niche',
				'url'     => 'https://www.zerebin.com/api/search/suggest?q=%%query%%',
				'parser'  => 'list',
				'share'   => 500,
				'note'    => __( 'نمونه برای یک بازار تخصصی.', 'hoosh-seo' ),
			),
			'bertina'   => array(
				'label'   => __( 'برف‌تينا (برند و کالا)', 'hoosh-seo' ),
				'type'    => 'niche',
				'url'     => 'https://www.bertina.ir/api/search/suggest?q=%%query%%',
				'parser'  => 'list',
				'share'   => 500,
				'note'    => __( 'دیگر نمونه بازار تخصصی.', 'hoosh-seo' ),
			),
			'google_trends' => array(
				'label'   => 'Google Trends',
				'type'    => 'suggest',
				'url'     => 'https://trends.google.com/trends/api/autocomplete/%%query%%/json?hl=%%lang%%&tz=-210',
				'parser'  => 'trends',
				'share'   => 300,
				'note'    => __( 'پیشنهادهای داغ؛ گاهی فقط چند مورد برمی‌گرداند.', 'hoosh-seo' ),
			),
			'duckduckgo' => array(
				'label'   => 'DuckDuckGo',
				'type'    => 'suggest',
				'url'     => 'https://duckduckgo.com/ac/?q=%%query%%&kl=%%lang%%-ir&type=list',
				'parser'  => 'ddg',
				'share'   => 500,
				'note'    => __( 'برای کلماتی که گوگل سانسور می‌کند.', 'hoosh-seo' ),
			),
			'shitor'    => array(
				'label'   => __( 'شیتر', 'hoosh-seo' ),
				'type'    => 'market',
				'url'     => 'https://www.shitor.com/search/suggest?q=%%query%%',
				'parser'  => 'list',
				'share'   => 800,
				'note'    => __( 'بازار پوشاک.', 'hoosh-seo' ),
			),
		);

		// Volume APIs, only listed when usable.
		$settings = \hoosh_seo()->settings;
		if ( $settings->get( 'analytics.serper.key' ) ) {
			$catalog['serper_suggest'] = array(
				'label'   => 'Serper (People also ask)',
				'type'    => 'api',
				'url'     => 'https://google.serper.dev/search',
				'parser'  => 'serper',
				'share'   => 100,
				'note'    => __( 'سؤال‌های مرتبط + نتایج واقعی برای سنجش سختی.', 'hoosh-seo' ),
			);
		}
		if ( $settings->get( 'analytics.dataforseo.password' ) ) {
			$catalog['dataforseo'] = array(
				'label'   => 'DataForSEO (حجم واقعی)',
				'type'    => 'volume',
				'url'     => 'https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live',
				'parser'  => 'dataforseo',
				'share'   => 10000,
				'note'    => __( 'حجم، رقابت و CPC؛ اگر اکانت دارید بهترین گزینه است.', 'hoosh-seo' ),
			);
		}
		if ( $settings->get( 'analytics.semrush.key' ) ) {
			$catalog['semrush'] = array(
				'label'   => 'Semrush',
				'type'    => 'volume',
				'url'     => 'https://api.semrush.com/?type=phrase_kwd_related&database=ir&phrase=%%query%%&display_limit=100&key=%%key%%',
				'parser'  => 'semrush',
				'share'   => 10000,
			);
		}

		$overrides = (array) $settings->get( 'keywords.sources_config', array() );
		foreach ( $overrides as $key => $override ) {
			if ( ! is_array( $override ) ) {
				continue;
			}
			$catalog[ $key ] = array_merge( isset( $catalog[ $key ] ) ? $catalog[ $key ] : array( 'label' => $key ), $override );
		}

		$enabled = (array) $settings->get( 'keywords.sources', array() );
		foreach ( $catalog as $key => &$meta ) {
			$meta['key']       = $key;
			$meta['enabled']   = $enabled ? ! empty( $enabled[ $key ] ) : true;
			$meta['share']     = (int) ( $meta['share'] ?? 1000 );
			$meta['editable']  = ! empty( $meta['url'] );
		}
		unset( $meta );

		return $catalog;
	}

	/**
	 * Persian + Latin alphabet machine.
	 *
	 * @param string $mode fa|en|both.
	 * @return array
	 */
	public static function alphabet( $mode = 'both' ) {
		$fa = array( 'ا', 'ب', 'پ', 'ت', 'ث', 'ج', 'چ', 'ح', 'خ', 'د', 'ذ', 'ر', 'ز', 'ژ', 'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ع', 'غ', 'ف', 'ق', 'ک', 'گ', 'ل', 'م', 'ن', 'و', 'ه', 'ی' );
		$en = range( 'a', 'z' );
		if ( 'fa' === $mode ) {
			return $fa;
		}
		if ( 'en' === $mode ) {
			return $en;
		}
		return array_merge( $fa, $en );
	}

	/**
	 * Common Persian prefix/suffix seeds.
	 *
	 * @return array
	 */
	public static function presets() {
		return array(
			'prefix' => array(
				__( 'بهترین', 'hoosh-seo' ),
				__( 'ارزان‌ترین', 'hoosh-seo' ),
				__( 'خرید', 'hoosh-seo' ),
				__( 'قیمت', 'hoosh-seo' ),
				__( 'نحوه', 'hoosh-seo' ),
				__( 'آموزش', 'hoosh-seo' ),
				__( 'درمان', 'hoosh-seo' ),
				__( 'مدل', 'hoosh-seo' ),
				__( 'بدون', 'hoosh-seo' ),
				__( 'جدید', 'hoosh-seo' ),
				__( 'آنلاین', 'hoosh-seo' ),
				__( 'رایگان', 'hoosh-seo' ),
			),
			'suffix' => array(
				__( 'قیمت', 'hoosh-seo' ),
				__( 'خرید', 'hoosh-seo' ),
				__( 'چند', 'hoosh-seo' ),
				__( 'چیست', 'hoosh-seo' ),
				__( 'نحوه استفاده', 'hoosh-seo' ),
				__( 'مزایا', 'hoosh-seo' ),
				__( 'معایب', 'hoosh-seo' ),
				__( 'مقایسه', 'hoosh-seo' ),
				__( 'در تهران', 'hoosh-seo' ),
				__( '۱۴۰۳', 'hoosh-seo' ),
				__( '۱۴۰۴', 'hoosh-seo' ),
				__( 'رایگان', 'hoosh-seo' ),
				__( 'دانلود', 'hoosh-seo' ),
				__( 'آموزش', 'hoosh-seo' ),
				__( 'برند ایرانی', 'hoosh-seo' ),
				__( 'ارسال رایگان', 'hoosh-seo' ),
				__( 'گارانتی', 'hoosh-seo' ),
				__( 'قسطی', 'hoosh-seo' ),
			),
			'questions' => array(
				__( 'چگونه', 'hoosh-seo' ),
				__( 'چرا', 'hoosh-seo' ),
				__( 'چه زمانی', 'hoosh-seo' ),
				__( 'آیا', 'hoosh-seo' ),
				__( 'کجا', 'hoosh-seo' ),
				__( 'چه فرقی دارد', 'hoosh-seo' ),
			),
			'injections' => array(
				__( 'برند', 'hoosh-seo' ),
				__( 'مدل', 'hoosh-seo' ),
				__( 'سایز', 'hoosh-seo' ),
				__( 'رنگ', 'hoosh-seo' ),
				__( 'کارکرد', 'hoosh-seo' ),
				__( 'مصرف', 'hoosh-seo' ),
				__( 'گارانتی', 'hoosh-seo' ),
				__( 'ارسال', 'hoosh-seo' ),
			),
		);
	}

	/**
	 * Start a research session.
	 *
	 * @param array $args {seeds, sources, depth, prefixes, suffixes, injections, alphabet,
	 *                   max_results, delay, dedupe, remove_numbers, min_length, max_length, lang, country}.
	 * @return array
	 */
	public static function start( $args = array() ) {
		$settings = \hoosh_seo()->settings;
		$args = wp_parse_args(
			(array) $args,
			array(
				'seeds'         => array(),
				'sources'       => array(),
				'depth'         => (int) $settings->get( 'keywords.depth', 1 ),
				'prefixes'      => (array) $settings->get( 'keywords.prefixes', array() ),
				'suffixes'      => (array) $settings->get( 'keywords.suffixes', array() ),
				'injections'    => array(),
				'alphabet'      => (bool) $settings->get( 'keywords.alphabet', true ),
				'intent_tags'   => (bool) $settings->get( 'keywords.intent_tags', true ),
				'auto_tags'     => (bool) $settings->get( 'keywords.auto_tags', false ),
				'per_source'    => (int) $settings->get( 'keywords.per_source', 10 ),
				'max_results'   => (int) $settings->get( 'keywords.max_results', 10 ),
				'delay'         => (int) $settings->get( 'keywords.delay', 2 ),
				'dedupe'        => (bool) $settings->get( 'keywords.dedupe', true ),
				'remove_numbers' => (bool) $settings->get( 'keywords.remove_numbers', false ),
				'remove_dup_words' => (bool) $settings->get( 'keywords.remove_duplicates_words', true ),
				'min_length'    => (int) $settings->get( 'keywords.min_length', 3 ),
				'max_length'    => (int) $settings->get( 'keywords.max_length', 60 ),
				'lang'          => (string) $settings->get( 'keywords.lang', 'fa' ),
				'rotate_ua'     => (bool) $settings->get( 'keywords.rotate_ua', true ),
				'auto_estimate' => (bool) $settings->get( 'ai.enabled', true )
					&& (bool) $settings->get( 'keywords.ai_estimate', true )
					&& in_array( (string) $settings->get( 'keywords.volume_provider', 'auto' ), array( 'auto', 'estimate' ), true ),
				'to_post'       => 0,
			)
		);

		$seeds = self::clean_seeds( $args['seeds'] );
		if ( ! $seeds ) {
			return array( 'ok' => false, 'message' => __( 'حداقل یک کلمه اولیه وارد کنید.', 'hoosh-seo' ) );
		}

		$all      = self::sources();
		$sources  = array_keys( (array) $args['sources'] ) ? array_intersect( array_keys( (array) $args['sources'] ), array_keys( $all ) ) : array_keys( $all );
		if ( ! $sources ) {
			$sources = array( 'google' );
		}

		$queue = self::build_queue( $seeds, $sources, $args );

		if ( ! Database::exists( 'kw_research' ) ) {
			return array( 'ok' => false, 'message' => __( 'جدول پژوهش موجود نیست؛ یک‌بار افزونه را غیرفعال و فعال کنید.', 'hoosh-seo' ) );
		}

		global $wpdb;
		$wpdb->insert( // phpcs:ignore
			Database::table( 'kw_research' ),
			array(
				'seed'       => mb_substr( implode( ' | ', $seeds ), 0, 250 ),
				'lang'       => substr( (string) $args['lang'], 0, 5 ),
				'sources'    => wp_json_encode( array_values( $sources ) ),
				'depth'      => max( 1, min( 3, (int) $args['depth'] ) ),
				'prefixes'   => wp_json_encode( array_values( (array) $args['prefixes'] ) ),
				'suffixes'   => wp_json_encode( array_values( (array) $args['suffixes'] ) ),
				'items'      => wp_json_encode( array() ),
				'stats'      => wp_json_encode(
					array(
						'queue'     => count( $queue ),
						'done'      => 0,
						'found'     => 0,
						'errors'    => 0,
						'skipped'   => 0,
						'started'   => time(),
					)
				),
				'status'     => 'running',
				'created_by' => (int) get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$session = (int) $wpdb->insert_id;

		update_option(
			self::OPTION,
			array(
				'session'  => $session,
				'running'  => true,
				'paused'   => false,
				'queue'    => $queue,
				'seen'     => array(),
				'args'     => $args,
				'sources'  => $sources,
				'started'  => time(),
				'to_post'  => (int) $args['to_post'],
				'estimate' => (bool) $args['auto_estimate'],
				'items'    => array(),
				'stats'    => array( 'found' => 0, 'done' => 0, 'errors' => 0, 'total' => count( $queue ) ),
			),
			false
		);

		$run = self::pump( 8 );

		return array_merge(
			array(
				'ok'      => true,
				'session' => $session,
				'seeds'   => $seeds,
				'queued'  => count( $queue ),
				'sources' => array_values( $sources ),
				'message' => sprintf( /* translators: 1: queue size, 2: sources */ __( 'پژوهش شروع شد: %1$s درخواست به %2$d منبع.', 'hoosh-seo' ), Helpers::number( count( $queue ) ), count( $sources ) ),
			),
			(array) ( $run['progress'] ?? array() )
		);
	}

	/**
	 * Normalise seeds: trim, dedupe, split multi-line, drop junk.
	 *
	 * @param mixed $seeds Seeds.
	 * @return array
	 */
	protected static function clean_seeds( $seeds ) {
		if ( is_string( $seeds ) ) {
			$seeds = preg_split( '/[\r\n,؛;]+/u', $seeds );
		}
		$out = array();
		foreach ( (array) $seeds as $seed ) {
			if ( ! is_scalar( $seed ) ) {
				continue;
			}
			$seed = trim( (string) preg_replace( '/\s+/u', ' ', $seed ) );
			$seed = mb_substr( $seed, 0, 60 );
			if ( Helpers::strlen( $seed ) < 2 ) {
				continue;
			}
			$key = Helpers::normalize_fa( $seed );
			if ( isset( $out[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = $seed;
		}
		return array_values( $out );
	}

	/**
	 * Build the request queue (seed × source × variants).
	 *
	 * @param array $seeds   Seeds.
	 * @param array $sources Sources.
	 * @param array $args    Args.
	 * @return array
	 */
	protected static function build_queue( $seeds, $sources, $args ) {
		$queue  = array();
		$prefix = array_values( array_filter( array_map( 'trim', (array) $args['prefixes'] ) ) );
		$suffix = array_values( array_filter( array_map( 'trim', (array) $args['suffixes'] ) ) );
		$inject = array_values( array_filter( array_map( 'trim', (array) $args['injections'] ) ) );
		$alphabet = ! empty( $args['alphabet'] ) ? ( ( 'fa' === (string) ( $args['lang'] ?? 'fa' ) ) ? self::alphabet( 'fa' ) : self::alphabet( 'both' ) ) : array();
		$depth    = max( 1, min( 3, (int) $args['depth'] ) );

		$variants = array();
		foreach ( $seeds as $seed ) {
			$variants[ $seed ] = array( 'seed' => $seed, 'terms' => array( $seed ) );

			// Prefix/suffix combos (depth >= 2).
			if ( $depth >= 2 ) {
				foreach ( $prefix as $word ) {
					$variants[ $seed ]['terms'][] = $word . ' ' . $seed;
				}
				foreach ( $suffix as $word ) {
					$variants[ $seed ]['terms'][] = $seed . ' ' . $word;
				}
			}
			if ( $depth >= 3 ) {
				foreach ( $prefix as $p ) {
					foreach ( $suffix as $s ) {
						$variants[ $seed ]['terms'][] = $p . ' ' . $seed . ' ' . $s;
					}
				}
				foreach ( $inject as $word ) {
					$variants[ $seed ]['terms'][] = $seed . ' ' . $word;
				}
			}
			// Alphabet machine.
			foreach ( array_slice( $alphabet, 0, $depth >= 2 ? 99 : 12 ) as $letter ) {
				$variants[ $seed ]['terms'][] = $seed . ' ' . $letter;
			}
			$variants[ $seed ]['terms'] = array_values( array_unique( array_filter( $variants[ $seed ]['terms'] ) ) );
		}

		foreach ( $variants as $variant ) {
			foreach ( $variant['terms'] as $term ) {
				foreach ( $sources as $source ) {
					$queue[] = array( 'term' => $term, 'source' => $source, 'seed' => $variant['seed'] );
				}
			}
		}

		// Cap the work so a typo cannot build a million requests.
		$cap = min( 6000, count( $queue ) );
		return array_slice( $queue, 0, $cap );
	}

	/**
	 * Current state.
	 *
	 * @return array
	 */
	protected static function state() {
		return (array) get_option( self::OPTION, array() );
	}

	/**
	 * Save state.
	 *
	 * @param array $state State.
	 */
	protected static function save_state( $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Pump the queue (bounded by time).
	 *
	 * @param int $batch Requests per call.
	 * @return array
	 */
	public static function pump( $batch = 10 ) {
		$state = self::state();
		if ( empty( $state['queue'] ) ) {
			return self::finish( $state );
		}
		if ( ! empty( $state['paused'] ) ) {
			return array( 'running' => false, 'paused' => true, 'progress' => self::progress( $state ) );
		}

		$settings = \hoosh_seo()->settings;
		$catalog  = self::sources();
		$delay    = max( 0, (int) $settings->get( 'keywords.delay', 2 ) );
		$batch    = max( 1, min( 25, (int) $batch ) );
		$started  = time();
		$done     = 0;

		foreach ( array_slice( (array) $state['queue'], 0, $batch, true ) as $index => $item ) {
			$result = self::fetch( $item, $catalog, $state );
			$done++;

			$state['items'] = array_merge( (array) ( $state['items'] ?? array() ), (array) $result['items'] );
			if ( count( (array) $state['items'] ) > 4000 ) {
				$state['items'] = array_slice( (array) $state['items'], -4000 );
			}
			$state['seen'][ (string) $item['source'] . '|' . $item['term'] ] = 1;
			$state['queue']  = array_values( (array) $state['queue'] );
			unset( $state['queue'][ $index ] );
			$state['queue'] = array_values( (array) $state['queue'] );

			if ( ! empty( $result['error'] ) ) {
				$state['stats']['errors'] = (int) ( $state['stats']['errors'] ?? 0 ) + 1;
			}
			$state['stats']['done']  = (int) ( $state['stats']['done'] ?? 0 ) + 1;
			$state['stats']['found'] = count( (array) $state['items'] );

			$cap = (int) ( $state['args']['max_results'] ?? 1500 );
			if ( $cap > 0 && count( (array) $state['items'] ) >= $cap ) {
				$state['queue'] = array();
				break;
			}

			if ( $delay && ! empty( $state['queue'] ) ) {
				usleep( $delay * 100000 );
			}
			if ( ( time() - $started ) > 18 ) {
				break;
			}
		}

		self::persist_items( $state );
		self::save_state( $state );

		if ( ! $state['queue'] ) {
			return self::finish( $state );
		}

		return array(
			'running'  => true,
			'done'     => $done,
			'progress' => self::progress( $state ),
		);
	}

	/**
	 * Fetch one source for one term.
	 *
	 * @param array $item    Queue item.
	 * @param array $catalog Sources.
	 * @param array $state   Session state.
	 * @return array
	 */
	protected static function fetch( $item, $catalog, $state ) {
		$key = (string) $item['source'];
		if ( ! isset( $catalog[ $key ] ) ) {
			return array( 'items' => array(), 'error' => 'unknown-source' );
		}
		$source   = $catalog[ $key ];
		$settings = \hoosh_seo()->settings;
		$args     = (array) ( $state['args'] ?? array() );

		$url = (string) ( $source['url'] ?? '' );
		if ( ! $url ) {
			return array( 'items' => array(), 'error' => 'no-url' );
		}

		$replace = array(
			'%%query%%'   => rawurlencode( (string) $item['term'] ),
			'%%lang%%'    => (string) ( $args['lang'] ?? 'fa' ),
			'%%country%%' => (string) $settings->get( 'analytics.rank_tracker.country', 'ir' ),
			'%%mkt%%'     => ( $args['lang'] ?? 'fa' ) . '-' . strtoupper( (string) $settings->get( 'analytics.rank_tracker.country', 'ir' ) ),
			'%%key%%'     => (string) $settings->get( 'analytics.semrush.key', '' ),
		);
		$url = strtr( $url, $replace );

		if ( false === strpos( $url, '://' ) ) {
			return array( 'items' => array(), 'error' => 'bad-url' );
		}

		$request = array(
			'timeout' => 8,
			'headers' => array(
				'Accept'       => 'application/json, text/plain, */*',
				'Accept-Language' => ( ( $args['lang'] ?? 'fa' ) === 'fa' ? 'fa-IR,fa;q=0.9' : 'en-US,en;q=0.9' ),
			),
		);
		if ( ! empty( $args['rotate_ua'] ) ) {
			$request['headers']['User-Agent'] = Helpers::random_ua();
		}

		if ( 'post' === (string) ( $source['method'] ?? '' ) ) {
			$request['method'] = 'POST';
			$request['headers']['Content-Type'] = 'application/json';
			$request['body'] = wp_json_encode( array( 'q' => (string) $item['term'] ) );
		}

		$raw = Helpers::http( $url, $request );
		if ( ! $raw['ok'] || '' === $raw['body'] ) {
			return array( 'items' => array(), 'error' => (string) ( $raw['error'] ? $raw['error'] : 'http-' . (int) $raw['code'] ) );
		}

		$found = self::parse( (string) $raw['body'], (string) ( $source['parser'] ?? 'auto' ) );
		$found = self::filter( $found, $item, $args, $state );

		$items = array();
		foreach ( $found as $phrase ) {
			$items[] = array(
				'keyword'   => $phrase,
				'source'    => $key,
				'seed'      => (string) ( $item['seed'] ?? $item['term'] ),
				'query'     => (string) $item['term'],
				'volume'    => 0,
				'difficulty' => 0,
				'cpc'       => 0,
				'competition' => '',
				'intent'    => ! empty( $args['intent_tags'] ) ? self::guess_intent( $phrase ) : '',
				'tags'      => ! empty( $args['auto_tags'] ) ? array( $key ) : array(),
			);
		}

		return array( 'items' => $items, 'error' => '' );
	}

	/**
	 * Parse a suggestion payload.
	 *
	 * @param string $body   Response.
	 * @param string $parser Parser name.
	 * @return array
	 */
	public static function parse( $body, $parser = 'auto' ) {
		$out = array();

		if ( in_array( $parser, array( 'firefox', 'auto' ), true ) ) {
			$json = json_decode( $body, true );
			if ( is_array( $json ) && isset( $json[1] ) && is_array( $json[1] ) ) {
				return array_map( 'strval', $json[1] );
			}
		}
		if ( 'youtube' === $parser || 'auto' === $parser ) {
			// window.dotl_… && window.google.ac.h(["term",[["s1",…],…]])
			if ( preg_match( '/\[\s*"[^"]+"\s*,\s*\[(.*)\]\s*\)\s*;?\s*$/su', $body, $m ) ) {
				$rows = json_decode( '[' . $m[1] . ']', true );
				if ( is_array( $rows ) ) {
					foreach ( $rows as $row ) {
						if ( isset( $row[0] ) ) {
							$out[] = (string) $row[0];
						}
					}
					if ( $out ) {
						return $out;
					}
				}
			}
		}
		if ( 'bing_html' === $parser || 'html' === $parser || 'html_titles' === $parser ) {
			if ( preg_match_all( '/<(?:li|a|div)[^>]*>(.*?)<\/(?:li|a|div)>/is', $body, $m ) ) {
				foreach ( (array) $m[1] as $chunk ) {
					$text = trim( html_entity_decode( wp_strip_all_tags( (string) $chunk ), ENT_QUOTES, 'UTF-8' ) );
					if ( $text && mb_strlen( $text ) < 70 ) {
						$out[] = $text;
					}
				}
			}
			if ( $out ) {
				return $out;
			}
		}
		if ( 'digikala' === $parser ) {
			$json = json_decode( $body, true );
			$rows = array();
			if ( isset( $json['suggestion']['product'] ) && is_array( $json['suggestion']['product'] ) ) {
				foreach ( (array) $json['suggestion']['product'] as $row ) {
					if ( isset( $row['title'] ) ) {
						$rows[] = (string) $row['title'];
					}
				}
			}
			if ( isset( $json['suggestions'] ) && is_array( $json['suggestions'] ) ) {
				foreach ( (array) $json['suggestions'] as $row ) {
					$rows[] = is_array( $row ) ? (string) ( $row['title'] ?? $row['text'] ?? '' ) : (string) $row;
				}
			}
			if ( $rows ) {
				return array_values( array_filter( $rows ) );
			}
		}
		if ( 'aparat' === $parser ) {
			$json = json_decode( $body, true );
			foreach ( array( 'd', 'results', 'suggest' ) as $branch ) {
				if ( isset( $json[ $branch ] ) && is_array( $json[ $branch ] ) ) {
					foreach ( (array) $json[ $branch ] as $row ) {
						if ( is_string( $row ) ) {
							$out[] = $row;
						} elseif ( isset( $row['title'] ) ) {
							$out[] = (string) $row['title'];
						}
					}
					return $out;
				}
			}
		}
		if ( 'serper' === $parser ) {
			$json = json_decode( $body, true );
			foreach ( array( 'peopleAlsoAsk', 'relatedSearches' ) as $branch ) {
				foreach ( (array) ( $json[ $branch ] ?? array() ) as $row ) {
					if ( isset( $row['question'] ) ) {
						$out[] = (string) $row['question'];
					} elseif ( isset( $row['query'] ) ) {
						$out[] = (string) $row['query'];
					}
				}
			}
			return $out;
		}
		if ( 'semrush' === $parser ) {
			$lines = preg_split( '/[\r\n]+/', trim( $body ) );
			array_shift( $lines );
			foreach ( (array) $lines as $line ) {
				$parts = explode( '|', $line );
				if ( isset( $parts[1] ) ) {
					$out[] = (string) $parts[1];
				}
			}
			return $out;
		}
		if ( 'dataforseo' === $parser ) {
			$json = json_decode( $body, true );
			foreach ( (array) ( $json['tasks'][0]['result'] ?? array() ) as $row ) {
				if ( isset( $row['keyword'] ) ) {
					$out[] = (string) $row['keyword'];
				}
			}
			return $out;
		}

		// Generic JSON: hunt for a list of strings or {title|name|keyword} rows.
		$json = json_decode( $body, true );
		if ( is_array( $json ) ) {
			$out = array_merge( $out, self::harvest( $json ) );
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $out ) ) ) );
	}

	/**
	 * Recursively harvest plausible keyword strings from JSON.
	 *
	 * @param array $data Data.
	 * @param int   $depth Depth.
	 * @return array
	 */
	protected static function harvest( $data, $depth = 0 ) {
		$out = array();
		if ( $depth > 5 ) {
			return $out;
		}
		foreach ( (array) $data as $key => $value ) {
			if ( is_string( $value ) ) {
				$len = mb_strlen( $value );
				if ( $len > 2 && $len < 70 && ! preg_match( '#[{}<>\[\]]#u', $value ) ) {
					$out[] = $value;
				}
				continue;
			}
			if ( is_array( $value ) ) {
				$out = array_merge( $out, self::harvest( $value, $depth + 1 ) );
			}
		}
		unset( $key );
		return $out;
	}

	/**
	 * Filter + dedupe found phrases.
	 *
	 * @param array $phrases Phrases.
	 * @param array $item    Queue item.
	 * @param array $args    Session args.
	 * @param array $state   State.
	 * @return array
	 */
	protected static function filter( $phrases, $item, $args, $state ) {
		$out  = array();
		$seen = (array) ( $state['seen_phrases'] ?? array() );

		foreach ( (array) $phrases as $phrase ) {
			$phrase = trim( (string) preg_replace( '/\s+/u', ' ', $phrase ) );
			$length = Helpers::strlen( $phrase );
			if ( $length < (int) ( $args['min_length'] ?? 3 ) || $length > (int) ( $args['max_length'] ?? 60 ) ) {
				continue;
			}
			if ( ! empty( $args['remove_numbers'] ) && preg_match( '/\d{3,}/u', $phrase ) ) {
				continue;
			}
			if ( ! empty( $args['remove_dup_words'] ) && preg_match( '/(\p{L}{3,})\s+\1/iu', $phrase ) ) {
				continue;
			}
			$norm = Helpers::normalize_fa( $phrase );
			if ( $norm === Helpers::normalize_fa( (string) $item['term'] ) ) {
				continue;
			}
			if ( ! empty( $args['dedupe'] ) && isset( $seen[ $norm ] ) ) {
				continue;
			}
			$seen[ $norm ] = 1;
			$out[]         = $phrase;
			if ( count( $out ) >= (int) ( $args['per_source'] ?? 10 ) ) {
				break;
			}
		}
		$state['seen_phrases'] = $seen;

		return $out;
	}

	/**
	 * Naive intent guess from Persian qualifiers.
	 *
	 * @param string $phrase Phrase.
	 * @return string
	 */
	public static function guess_intent( $phrase ) {
		$phrase = Helpers::normalize_fa( (string) $phrase );
		$map    = array(
			'transactional' => array( 'خرید', 'قیمت خرید', 'ثبت نام', 'دریافت', 'دانلود', 'رزرو', 'اجاره', 'نصب', 'استعلام' ),
			'commercial'    => array( 'بهترین', 'مقایسه', 'قیمت', 'ارزان', 'لوازم یدکی', 'نقد و بررسی', 'مدل' ),
			'navigational'  => array( 'سایت', 'آدرس', 'ورود', 'لاگین', 'اپلیکیشن', 'اینستاگرام', 'تلگرام', 'شماره' ),
			'informational' => array( 'چگونه', 'چیست', 'چرا', 'طرز', 'آشپزی', 'نحوه', 'علت', 'راه', 'آموزش', 'مثال', 'کجا' ),
		);
		foreach ( $map as $intent => $words ) {
			foreach ( $words as $word ) {
				if ( false !== mb_strpos( $phrase, Helpers::normalize_fa( $word ) ) ) {
					return $intent;
				}
			}
		}
		return 'informational';
	}

	/**
	 * Write items into the session row.
	 *
	 * @param array $state State.
	 */
	protected static function persist_items( &$state ) {
		global $wpdb;
		if ( empty( $state['session'] ) ) {
			return;
		}
		$existing = (array) $wpdb->get_var( $wpdb->prepare( 'SELECT items FROM ' . Database::table( 'kw_research' ) . ' WHERE id = %d', (int) $state['session'] ) ); // phpcs:ignore
		$existing = (array) json_decode( (string) $existing, true );
		$items    = array_merge( $existing, (array) ( $state['items'] ?? array() ) );
		$unique   = array();
		foreach ( $items as $item ) {
			$key = Helpers::normalize_fa( (string) ( $item['keyword'] ?? '' ) );
			if ( '' === $key || isset( $unique[ $key ] ) ) {
				continue;
			}
			$unique[ $key ] = $item;
		}
		$items = array_values( $unique );

		$wpdb->update( // phpcs:ignore
			Database::table( 'kw_research' ),
			array(
				'items' => wp_json_encode( $items ),
				'stats' => wp_json_encode(
					array_merge(
						(array) ( $state['stats'] ?? array() ),
						array(
							'found' => count( $items ),
							'last'  => time(),
						)
					)
				),
			),
			array( 'id' => (int) $state['session'] )
		);

		$state['items'] = $items;
	}

	/**
	 * Finish + optional AI volume estimate.
	 *
	 * @param array $state State.
	 * @return array
	 */
	protected static function finish( $state ) {
		global $wpdb;
		if ( ! empty( $state['session'] ) ) {
			$wpdb->update( Database::table( 'kw_research' ), array( 'status' => 'done' ), array( 'id' => (int) $state['session'] ) ); // phpcs:ignore
		}
		$state['running'] = false;
		$state['queue']   = array();
		self::save_state( $state );

		if ( ! empty( $state['estimate'] ) && ! empty( $state['items'] ) && class_exists( '\HooshSEO\AI\Gateway' ) && \HooshSEO\AI\Gateway::is_configured() ) {
			self::estimate_with_ai( $state );
		}

		return array(
			'running'  => false,
			'done'     => true,
			'progress' => self::progress( $state ),
			'message'  => sprintf( /* translators: %d found */ __( 'پژوهش تمام شد؛ %d کلمه یکتا پیدا شد.', 'hoosh-seo' ), count( (array) ( $state['items'] ?? array() ) ) ),
		);
	}

	/**
	 * Ask the AI for volume/difficulty when no API is wired up.
	 *
	 * @param array $state State.
	 */
	protected static function estimate_with_ai( &$state ) {
		$keywords = array_slice( array_map(
			static function ( $item ) {
				return (string) ( $item['keyword'] ?? '' );
			},
			(array) ( $state['items'] ?? array() )
		), 0, 40 );
		$keywords = array_values( array_filter( $keywords ) );
		if ( ! $keywords ) {
			return;
		}

		$result = \HooshSEO\AI\Tasks::run(
			'research',
			0,
			array(
				'keywords' => $keywords,
				'skip_post' => true,
			),
			true
		);
		if ( empty( $result['ok'] ) ) {
			return;
		}

		$map = array();
		foreach ( (array) ( $result['data']['items'] ?? array() ) as $row ) {
			if ( empty( $row['keyword'] ) ) {
				continue;
			}
			$map[ Helpers::normalize_fa( (string) $row['keyword'] ) ] = $row;
		}

		$global = isset( $map[ Helpers::normalize_fa( 'عمومی' ) ] ) ? $map[ Helpers::normalize_fa( 'عمومی' ) ] : array();
		foreach ( (array) $state['items'] as $index => $item ) {
			$key = Helpers::normalize_fa( (string) $item['keyword'] );
			if ( isset( $map[ $key ] ) ) {
				$state['items'][ $index ]['volume']     = (int) $map[ $key ]['volume'];
				$state['items'][ $index ]['difficulty'] = (int) $map[ $key ]['difficulty'];
				$state['items'][ $index ]['cpc']        = (float) $map[ $key ]['cpc'];
				$state['items'][ $index ]['intent']     = (string) $map[ $key ]['intent'];
				$state['items'][ $index ]['estimate']   = 'ai';
			} elseif ( $global ) {
				$state['items'][ $index ]['volume']   = (int) $global['volume'];
				$state['items'][ $index ]['difficulty'] = (int) $global['difficulty'];
				$state['items'][ $index ]['estimate'] = 'ai-average';
			}
		}
		self::persist_items( $state );
	}

	/**
	 * Progress payload.
	 *
	 * @param array $state State.
	 * @return array
	 */
	protected static function progress( $state ) {
		$total = (int) ( $state['stats']['total'] ?? 0 );
		$done  = (int) ( $state['stats']['done'] ?? 0 );
		return array(
			'session'   => (int) ( $state['session'] ?? 0 ),
			'running'   => ! empty( $state['running'] ),
			'paused'    => ! empty( $state['paused'] ),
			'total'     => $total,
			'done'      => $done,
			'left'      => $total ? max( 0, $total - $done ) : count( (array) ( $state['queue'] ?? array() ) ),
			'percent'   => $total ? (int) round( ( $done / max( 1, $total ) ) * 100 ) : 0,
			'found'     => count( (array) ( $state['items'] ?? array() ) ),
			'errors'    => (int) ( $state['stats']['errors'] ?? 0 ),
			'sources'   => array_values( (array) ( $state['sources'] ?? array() ) ),
			'elapsed'   => isset( $state['started'] ) ? Helpers::time_ago_fa( (int) $state['started'] ) : '',
		);
	}

	/**
	 * Status for the Studio.
	 *
	 * @param int $session Session id.
	 * @return array
	 */
	public static function status( $session = 0 ) {
		$state = self::state();
		if ( $session && (int) ( $state['session'] ?? 0 ) !== (int) $session ) {
			$state = self::load_session( (int) $session );
		}
		$page = isset( $_GET['page'] ) ? 0 : 0; // phpcs:ignore
		unset( $page );

		return array(
			'ok'       => true,
			'progress' => self::progress( $state ),
			'items'    => array_slice( (array) ( $state['items'] ?? array() ), 0, 400 ),
		);
	}

	/**
	 * Load a stored session.
	 *
	 * @param int $id Session id.
	 * @return array
	 */
	protected static function load_session( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'kw_research' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return array();
		}
		return array(
			'session' => (int) $row['id'],
			'running' => 'running' === $row['status'],
			'items'   => (array) json_decode( (string) $row['items'], true ),
			'stats'   => (array) json_decode( (string) $row['stats'], true ),
			'sources' => (array) json_decode( (string) $row['sources'], true ),
			'queue'   => array(),
		);
	}

	/**
	 * Pause the run.
	 *
	 * @return array
	 */
	public static function pause() {
		$state = self::state();
		$state['paused'] = true;
		self::save_state( $state );
		return array( 'ok' => true, 'paused' => true );
	}

	/**
	 * Resume.
	 *
	 * @return array
	 */
	public static function resume() {
		$state = self::state();
		$state['paused'] = false;
		$state['running'] = true;
		self::save_state( $state );
		return self::pump( 10 );
	}

	/**
	 * Stop a session.
	 *
	 * @param int $session Session id.
	 * @return array
	 */
	public static function stop( $session = 0 ) {
		global $wpdb;
		$state = self::state();
		$state['running'] = false;
		$state['paused']  = false;
		$state['queue']   = array();
		self::save_state( $state );

		$id = (int) ( $session ? $session : ( $state['session'] ?? 0 ) );
		if ( $id ) {
			$wpdb->update( Database::table( 'kw_research' ), array( 'status' => 'stopped' ), array( 'id' => $id ) ); // phpcs:ignore
		}
		return array( 'ok' => true, 'message' => __( 'پژوهش متوقف شد.', 'hoosh-seo' ) );
	}

	/**
	 * Sessions list.
	 *
	 * @return array
	 */
	public static function sessions() {
		global $wpdb;
		if ( ! Database::exists( 'kw_research' ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( 'SELECT id, seed, lang, sources, depth, status, stats, created_at FROM ' . Database::table( 'kw_research' ) . ' ORDER BY id DESC LIMIT 40', ARRAY_A ); // phpcs:ignore
		foreach ( $rows as &$row ) {
			$stats        = (array) json_decode( (string) $row['stats'], true );
			$row['items'] = (int) ( $stats['found'] ?? 0 );
			$row['errors'] = (int) ( $stats['errors'] ?? 0 );
			$row['sources'] = array_values( (array) json_decode( (string) $row['sources'], true ) );
			$row['created'] = Helpers::time_ago_fa( strtotime( (string) $row['created_at'] . ' UTC' ) );
			$row['status_label'] = 'done' === $row['status'] ? __( 'تمام‌شده', 'hoosh-seo' ) : ( 'running' === $row['status'] ? __( 'در حال اجرا', 'hoosh-seo' ) : $row['status'] );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Save selected keywords into the vault.
	 *
	 * @param array $args {items, post_id, state, tags, replace}.
	 * @return array
	 */
	public static function save( $args = array() ) {
		$args = wp_parse_args(
			(array) $args,
			array(
				'items'   => array(),
				'post_id' => 0,
				'state'   => 'saved',
				'tags'    => array(),
				'replace' => false,
				'source'  => 'research',
			)
		);

		$added   = 0;
		$updated = 0;
		foreach ( (array) $args['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				$item = array( 'keyword' => (string) $item );
			}
			$id = self::upsert(
				array(
					'phrase'      => (string) ( $item['keyword'] ?? $item['phrase'] ?? '' ),
					'post_id'     => (int) ( $item['post_id'] ?? $args['post_id'] ),
					'volume'      => (int) ( $item['volume'] ?? 0 ),
					'difficulty'  => (int) ( $item['difficulty'] ?? 0 ),
					'cpc'         => (float) ( $item['cpc'] ?? 0 ),
					'competition' => (string) ( $item['competition'] ?? '' ),
					'intent'      => (string) ( $item['intent'] ?? self::guess_intent( (string) ( $item['keyword'] ?? '' ) ) ),
					'source'      => (string) ( $item['source'] ?? $args['source'] ),
					'kind'        => (string) ( $item['kind'] ?? 'research' ),
					'state'       => (string) ( $item['state'] ?? $args['state'] ),
					'tags'        => (array) ( $item['tags'] ?? $args['tags'] ),
				)
			);
			if ( $id ) {
				$added++;
			}
		}

		if ( (int) $args['post_id'] && $added ) {
			$keywords = array_slice( array_map(
				static function ( $item ) {
					return is_array( $item ) ? (string) ( $item['keyword'] ?? '' ) : (string) $item;
				},
				(array) $args['items']
			), 0, 5 );
			$keywords = array_values( array_filter( $keywords ) );
			if ( $keywords ) {
				Meta::save( (int) $args['post_id'], array( 'keywords' => $keywords ) );
				$updated = 1;
			}
		}

		Audit::log(
			'keywords',
			'save',
			array(
				'post_id' => (int) $args['post_id'],
				'label'   => sprintf( /* translators: %d count */ __( '%d کلمه کلیدی ذخیره شد', 'hoosh-seo' ), $added ),
				'after'   => array( 'count' => $added ),
			)
		);

		return array(
			'ok'      => (bool) $added,
			'saved'   => $added,
			'attached' => (bool) $updated,
			'message' => $added
				? sprintf( /* translators: %d count */ __( '%d کلمه ذخیره شد.', 'hoosh-seo' ), $added )
				: __( 'چیزی ذخیره نشد.', 'hoosh-seo' ),
		);
	}

	/**
	 * Insert or update one keyword row.
	 *
	 * @param array $data Data.
	 * @return int
	 */
	public static function upsert( $data ) {
		global $wpdb;
		if ( ! Database::exists( 'keywords' ) ) {
			return 0;
		}
		$phrase = trim( (string) ( $data['phrase'] ?? '' ) );
		if ( '' === $phrase ) {
			return 0;
		}
		$norm = Helpers::normalize_fa( $phrase );
		$id   = isset( $data['id'] ) ? (int) $data['id'] : 0;

		if ( ! $id ) {
			$id = (int) $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare( 'SELECT id FROM ' . Database::table( 'keywords' ) . ' WHERE norm = %s AND post_id = %d LIMIT 1', $norm, (int) ( $data['post_id'] ?? 0 ) )
			);
		}

		$row = array(
			'phrase'      => mb_substr( $phrase, 0, 250 ),
			'norm'        => mb_substr( $norm, 0, 250 ),
			'post_id'     => (int) ( $data['post_id'] ?? 0 ),
			'url'         => mb_substr( (string) ( $data['url'] ?? ( ( $data['post_id'] ?? 0 ) ? get_permalink( (int) $data['post_id'] ) : '' ) ), 0, 480 ),
			'volume'      => (int) ( $data['volume'] ?? 0 ),
			'difficulty'  => (int) ( $data['difficulty'] ?? 0 ),
			'cpc'         => (float) ( $data['cpc'] ?? 0 ),
			'competition' => mb_substr( (string) ( $data['competition'] ?? '' ), 0, 12 ),
			'intent'      => mb_substr( (string) ( $data['intent'] ?? '' ), 0, 20 ),
			'source'      => mb_substr( (string) ( $data['source'] ?? 'manual' ), 0, 40 ),
			'kind'        => mb_substr( (string) ( $data['kind'] ?? 'focus' ), 0, 20 ),
			'state'       => mb_substr( (string) ( $data['state'] ?? 'saved' ), 0, 20 ),
			'tags'        => wp_json_encode( array_values( array_map( 'sanitize_text_field', (array) ( $data['tags'] ?? array() ) ) ) ),
			'updated_at'  => current_time( 'mysql', true ),
		);

		if ( $id ) {
			unset( $row['norm'] );
			$wpdb->update( Database::table( 'keywords' ), $row, array( 'id' => $id ) ); // phpcs:ignore
			return $id;
		}
		$wpdb->insert( Database::table( 'keywords' ), $row ); // phpcs:ignore
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete keywords.
	 *
	 * @param array $ids Ids.
	 * @return array
	 */
	public static function delete( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return array( 'ok' => false, 'deleted' => 0 );
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( 'keywords' ) . ' WHERE id IN (' . $in . ')', $ids ) ); // phpcs:ignore
		return array( 'ok' => true, 'deleted' => count( $ids ), 'message' => sprintf( /* translators: %d */ __( '%d کلمه حذف شد.', 'hoosh-seo' ), count( $ids ) ) );
	}

	/**
	 * Keyword vault listing.
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function all( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			(array) $args,
			array(
				'search'  => '',
				'status'  => '',
				'state'   => '',
				'kind'    => '',
				'post_id' => 0,
				'orderby' => 'volume',
				'order'   => 'desc',
				'per'     => 50,
				'paged'   => 1,
			)
		);
		if ( ! Database::exists( 'keywords' ) ) {
			return array( 'rows' => array(), 'total' => 0, 'pages' => 0, 'summary' => array() );
		}

		$table  = Database::table( 'keywords' );
		$where  = '1=1';
		$params = array();

		if ( '' !== $args['search'] ) {
			$where   .= ' AND (phrase LIKE %s OR url LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			array_push( $params, $like, $like );
		}
		$state = $args['state'] ? $args['state'] : $args['status'];
		if ( $state && in_array( $state, array( 'saved', 'tracking', 'archived', 'ignored' ), true ) ) {
			$where   .= ' AND state = %s';
			$params[] = $state;
		}
		if ( $args['kind'] ) {
			$where   .= ' AND kind = %s';
			$params[] = sanitize_key( $args['kind'] );
		}
		if ( (int) $args['post_id'] ) {
			$where   .= ' AND post_id = %d';
			$params[] = (int) $args['post_id'];
		}

		$orderby = in_array( $args['orderby'], array( 'volume', 'position', 'difficulty', 'updated_at', 'phrase', 'clicks', 'impressions', 'cpc' ), true ) ? $args['orderby'] : 'volume';
		$order   = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per     = max( 5, min( 1000, (int) $args['per'] ) );
		$offset  = ( max( 1, (int) $args['paged'] ) - 1 ) * $per;

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT {$per} OFFSET {$offset}";
		$rows = (array) ( $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ) ); // phpcs:ignore
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ) ); // phpcs:ignore

		foreach ( $rows as &$row ) {
			$row['keyword']     = (string) $row['phrase'];
			$row['updated']     = (string) $row['updated_at'];
			$row['volume']      = (int) $row['volume'];
			$row['difficulty']  = (int) $row['difficulty'];
			$row['cpc']         = (float) $row['cpc'];
			$row['position']    = (float) $row['position'];
			$row['prev_position'] = (float) $row['prev_position'];
			$row['delta']       = $row['prev_position'] ? round( $row['prev_position'] - $row['position'], 1 ) : null;
			$row['tags']        = (array) json_decode( (string) $row['tags'], true );
			$row['post_title']  = $row['post_id'] ? get_the_title( (int) $row['post_id'] ) : '';
			$row['edit']        = $row['post_id'] ? (string) get_edit_post_link( (int) $row['post_id'], 'raw' ) : '';
			$row['difficulty_label'] = self::difficulty_label( (int) $row['difficulty'] );
		}
		unset( $row );

		return array(
			'rows'    => $rows,
			'total'   => $total,
			'pages'   => (int) ceil( $total / $per ),
			'page'    => (int) $args['paged'],
			'summary' => self::summary(),
		);
	}

	/**
	 * Difficulty bucket label.
	 *
	 * @param int $value Value.
	 * @return string
	 */
	public static function difficulty_label( $value ) {
		$value = (int) $value;
		if ( $value <= 0 ) {
			return __( 'نامعلوم', 'hoosh-seo' );
		}
		if ( $value < 25 ) {
			return __( 'آسان', 'hoosh-seo' );
		}
		if ( $value < 50 ) {
			return __( 'متوسط', 'hoosh-seo' );
		}
		if ( $value < 75 ) {
			return __( 'سخت', 'hoosh-seo' );
		}
		return __( 'بسیار سخت', 'hoosh-seo' );
	}

	/**
	 * Vault summary.
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;
		$empty = array( 'found' => 0, 'saved' => 0, 'tracking' => 0, 'archived' => 0, 'total_volume' => 0, 'easy' => 0, 'top3' => 0 );
		if ( ! Database::exists( 'keywords' ) ) {
			return $empty;
		}
		$table = Database::table( 'keywords' );
		$row   = (array) $wpdb->get_row( // phpcs:ignore
			"SELECT COUNT(*) total, SUM(state='tracking') tracking, SUM(state='saved') saved, SUM(state='archived') archived,
				COALESCE(SUM(volume),0) vol, SUM(difficulty BETWEEN 1 AND 24) easy, SUM(position > 0 AND position <= 3) top3
			 FROM {$table}",
			ARRAY_A
		);
		$session = (array) $wpdb->get_row( 'SELECT COALESCE(SUM(JSON_LENGTH(items)),0) n FROM ' . Database::table( 'kw_research' ), ARRAY_A ); // phpcs:ignore

		return array(
			'found'        => (int) ( $session['n'] ?? 0 ),
			'saved'        => (int) ( $row['saved'] ?? 0 ),
			'tracking'     => (int) ( $row['tracking'] ?? 0 ),
			'archived'     => (int) ( $row['archived'] ?? 0 ),
			'total'        => (int) ( $row['total'] ?? 0 ),
			'total_volume' => (int) ( $row['vol'] ?? 0 ),
			'easy'         => (int) ( $row['easy'] ?? 0 ),
			'top3'         => (int) ( $row['top3'] ?? 0 ),
		);
	}

	/**
	 * Keywords attached to a post (metabox + Studio).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function for_post( $post_id ) {
		global $wpdb;
		if ( ! Database::exists( 'keywords' ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT * FROM ' . Database::table( 'keywords' ) . ' WHERE post_id = %d ORDER BY volume DESC LIMIT 30',
				(int) $post_id
			),
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$row['keyword'] = (string) $row['phrase'];
			$row['volume']  = (int) $row['volume'];
			$row['position'] = (float) $row['position'];
			$row['difficulty'] = (int) $row['difficulty'];
			$row['delta']   = $row['prev_position'] ? round( (float) $row['prev_position'] - (float) $row['position'], 1 ) : null;
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Retention: drop old sessions.
	 */
	public static function prune() {
		global $wpdb;
		if ( ! Database::exists( 'kw_research' ) ) {
			return 0;
		}
		$days = (int) \hoosh_seo()->settings->get( 'keywords.retain', 60 );
		if ( $days < 1 ) {
			return 0;
		}
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . Database::table( 'kw_research' ) . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND status <> %s', $days, 'running' )
		); // phpcs:ignore
	}
}
