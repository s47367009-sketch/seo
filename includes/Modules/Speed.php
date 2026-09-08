<?php
/**
 * Speed: front-end trims that do not need a caching plugin — emoji/oEmbed
 * removal, defer, critical CSS, font preload, Gravatar cache, CDN rewrite,
 * heartbeat and revision tuning, plus a PageSpeed pass.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Class Speed
 */
final class Speed {

	/**
	 * Singleton.
	 *
	 * @var Speed|null
	 */
	private static $instance = null;

	/**
	 * Handles we never touch.
	 *
	 * @var array
	 */
	protected static $never = array( 'jquery', 'jquery-core', 'jquery-migrate' );

	/**
	 * Get instance.
	 *
	 * @return Speed
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Admin-side hooks.
	 */
	public function bootstrap() {
		add_action( 'admin_post_hoosh_speed_run', array( __CLASS__, 'ajax_run' ) );
		add_filter( 'wp_revisions_to_keep', array( __CLASS__, 'revisions' ), 10, 2 );
	}

	/**
	 * Front-end hooks.
	 */
	public function bootstrap_front() {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'speed.enabled', false ) ) {
			return;
		}

		if ( $settings->on( 'speed.disable_emojis', true ) ) {
			add_action( 'init', array( __CLASS__, 'disable_emojis' ) );
		}
		if ( $settings->on( 'speed.disable_oembed', true ) ) {
			add_action( 'init', array( __CLASS__, 'disable_oembed' ) );
		}
		if ( $settings->on( 'speed.remove_query_strings', false ) ) {
			add_filter( 'script_loader_src', array( __CLASS__, 'strip_ver' ), 20 );
			add_filter( 'style_loader_src', array( __CLASS__, 'strip_ver' ), 20 );
		}
		if ( $settings->on( 'speed.remove_jquery_migrate', true ) ) {
			add_action( 'wp_default_scripts', array( __CLASS__, 'drop_migrate' ) );
		}
		if ( $settings->on( 'speed.defer_js', false ) || (int) $settings->get( 'speed.js_delay_ms', 0 ) > 0 ) {
			add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 20, 3 );
		}
		if ( $settings->on( 'speed.remove_unused_css', false ) ) {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'unused_css' ), 99 );
		}
		if ( $settings->on( 'speed.inline_critical_css', false ) ) {
			add_action( 'wp_head', array( __CLASS__, 'critical_css' ), 1 );
		}
		if ( $settings->on( 'speed.preload_fonts', true ) ) {
			add_action( 'wp_head', array( __CLASS__, 'preload_fonts' ), 1 );
		}
		if ( $settings->on( 'speed.preload_lcp', true ) && is_singular() ) {
			add_action( 'wp_head', array( __CLASS__, 'preload_lcp' ), 2 );
		}
		if ( $settings->on( 'speed.image_placeholders', false ) ) {
			add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'image_attributes' ), 20, 3 );
		}
		if ( $settings->on( 'speed.lazy_iframes', true ) ) {
			add_filter( 'the_content', array( __CLASS__, 'lazy_iframes' ), 30 );
		}
		if ( $settings->on( 'speed.gravatar_cache', false ) ) {
			add_filter( 'get_avatar', array( __CLASS__, 'gravatar_cache' ), 20, 1 );
		}
		$avatar = (int) $settings->get( 'speed.avatar_size', 0 );
		if ( $avatar > 0 ) {
			add_filter( 'pre_get_avatar_data', array( __CLASS__, 'avatar_size' ), 20, 2 );
		}
		if ( $settings->on( 'speed.dns_prefetch', true ) ) {
			add_filter( 'wp_resource_hints', array( __CLASS__, 'resource_hints' ), 20, 2 );
		}
		$cdn = trim( (string) $settings->get( 'speed.cdn_url', '' ) );
		if ( $cdn ) {
			add_filter( 'wp_get_attachment_url', array( __CLASS__, 'cdn_url' ), 20, 2 );
		}
		$heartbeat = (string) $settings->get( 'speed.heartbeat', 'reduce' );
		if ( 'off' === $heartbeat ) {
			add_action( 'init', array( __CLASS__, 'kill_heartbeat' ) );
		} elseif ( 'reduce' === $heartbeat ) {
			add_filter( 'heartbeat_settings', array( __CLASS__, 'heartbeat_settings' ) );
		}
		$autosave = (int) $settings->get( 'speed.autosave_interval', 60 );
		if ( $autosave > 30 ) {
			add_filter( 'heartbeat_settings', array( __CLASS__, 'autosave_settings' ) );
		}
		if ( $settings->on( 'speed.minify_html', false ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'buffer_html' ), 0 );
		}
	}

	/**
	 * Tweak catalog (drives the Studio list).
	 *
	 * @return array
	 */
	public static function catalog() {
		return array(
			'disable_emojis'       => array(
				'label'   => __( 'حذف اسکریپت ایموجی', 'hoosh-seo' ),
				'desc'    => __( 'یک اسکریپت و چند استایل بی‌مصرف از سربرگ حذف می‌شود.', 'hoosh-seo' ),
				'impact'  => 9,
				'safe'    => true,
				'type'    => 'bool',
			),
			'disable_oembed'       => array(
				'label'   => __( 'حذف oEmbed غیرلازم', 'hoosh-seo' ),
				'desc'    => __( 'کشف خودکار ویدئو/توییتر را خاموش می‌کند؛ ویدیوهای داخل محتوا دست‌نخورده می‌مانند.', 'hoosh-seo' ),
				'impact'  => 4,
				'safe'    => true,
				'type'    => 'bool',
			),
			'remove_jquery_migrate' => array(
				'label'   => 'Remove jQuery Migrate',
				'desc'    => __( 'اگر قالب/افزونه‌ها با jQuery 3 کار می‌کنند، این فایل لازم نیست.', 'hoosh-seo' ),
				'impact'  => 15,
				'safe'    => false,
				'type'    => 'bool',
			),
			'remove_query_strings' => array(
				'label'   => __( 'حذف ?ver= از فایل‌ها', 'hoosh-seo' ),
				'desc'    => __( 'کش مرورگر بهتر می‌خورد؛ اگر کش‌شکن دستی دارید لازم نیست.', 'hoosh-seo' ),
				'impact'  => 2,
				'safe'    => true,
				'type'    => 'bool',
			),
			'defer_js'             => array(
				'label'   => __( 'تأخیر در اجرای جاوااسکریپت', 'hoosh-seo' ),
				'desc'    => __( 'اسکریپت‌ها defer می‌شوند؛ jQuery و استثناها دست‌نخورده.', 'hoosh-seo' ),
				'impact'  => 22,
				'safe'    => false,
				'type'    => 'bool',
			),
			'remove_unused_css'    => array(
				'label'   => __( 'حذف CSS بلوک‌های بلااستفاده', 'hoosh-seo' ),
				'desc'    => __( 'وقتی صفحه‌ای بلوک گوتنبرگ ندارد، استایل بلوک‌ها لود نمی‌شود.', 'hoosh-seo' ),
				'impact'  => 18,
				'safe'    => true,
				'type'    => 'bool',
			),
			'inline_critical_css'  => array(
				'label'   => __( 'CSS حیاتی درون‌خطی', 'hoosh-seo' ),
				'desc'    => __( 'استایل خود افزونه به‌صورت inline و بقیه با media=print بارگذاری می‌شود.', 'hoosh-seo' ),
				'impact'  => 12,
				'safe'    => false,
				'type'    => 'bool',
			),
			'preload_fonts'        => array(
				'label'   => __( 'پیش‌بارگذاری فونت وزیرمتن', 'hoosh-seo' ),
				'desc'    => __( 'فونت از CDN با font-display:swap و preload می‌آید.', 'hoosh-seo' ),
				'impact'  => 10,
				'safe'    => true,
				'type'    => 'bool',
			),
			'preload_lcp'          => array(
				'label'   => __( 'پیش‌بارگذاری تصویر شاخص', 'hoosh-seo' ),
				'desc'    => __( 'تصویر شاخص نوشته با rel=preload و fetchpriority=high.', 'hoosh-seo' ),
				'impact'  => 20,
				'safe'    => true,
				'type'    => 'bool',
			),
			'image_placeholders'   => array(
				'label'   => __( 'placeholder برای تصاویر', 'hoosh-seo' ),
				'desc'    => __( 'ابعاد + بک‌گراند خالی تا صفحه موقع لود تصویر نپرد.', 'hoosh-seo' ),
				'impact'  => 8,
				'safe'    => true,
				'type'    => 'bool',
			),
			'lazy_iframes'         => array(
				'label'   => __( 'لزی‌لود iframe و ویدئو', 'hoosh-seo' ),
				'desc'    => __( 'iframe ها با loading=lazy و poster لود می‌شوند.', 'hoosh-seo' ),
				'impact'  => 25,
				'safe'    => true,
				'type'    => 'bool',
			),
			'gravatar_cache'       => array(
				'label'   => __( 'کش آواتار روی هاست', 'hoosh-seo' ),
				'desc'    => __( 'آواتارها یک‌بار دانلود و از سرور خودتان سرو می‌شوند.', 'hoosh-seo' ),
				'impact'  => 6,
				'safe'    => true,
				'type'    => 'bool',
			),
			'dns_prefetch'         => array(
				'label'   => __( 'dns-prefetch برای میزبان‌های بیرونی', 'hoosh-seo' ),
				'desc'    => __( 'اتصال به CDN/ویدئو زودتر برقرار می‌شود.', 'hoosh-seo' ),
				'impact'  => 3,
				'safe'    => true,
				'type'    => 'bool',
			),
			'minify_html'          => array(
				'label'   => __( 'کوچک‌سازی HTML', 'hoosh-seo' ),
				'desc'    => __( 'حذف فاصله‌های اضافه از خروجی؛ برای قالب‌های شلوخ مؤثر است.', 'hoosh-seo' ),
				'impact'  => 5,
				'safe'    => false,
				'type'    => 'bool',
			),
			'avatar_size'          => array(
				'label'   => __( 'سایز آواتار', 'hoosh-seo' ),
				'desc'    => __( 'اگر بزرگ‌تر از نیاز قالب است، بیهوده دانلود می‌شود.', 'hoosh-seo' ),
				'impact'  => 2,
				'safe'    => true,
				'type'    => 'number',
				'min'     => 0,
				'max'     => 128,
			),
			'js_delay_ms'          => array(
				'label'   => __( 'تأخیر JS (میلی‌ثانیه)', 'hoosh-seo' ),
				'desc'    => __( 'برایInteraction‌های غیرحیاتی؛ ۰ یعنی فقط defer.', 'hoosh-seo' ),
				'impact'  => 10,
				'safe'    => false,
				'type'    => 'number',
				'min'     => 0,
				'max'     => 5000,
				'step'    => 100,
			),
			'revisions_limit'      => array(
				'label'   => __( 'محدودکردن رونویش‌ها', 'hoosh-seo' ),
				'desc'    => __( '۰ یعنی بی‌حد؛ ۵ تا ۱۰ معمولاً کافی است.', 'hoosh-seo' ),
				'impact'  => 1,
				'safe'    => false,
				'type'    => 'number',
				'min'     => 0,
				'max'     => 100,
			),
			'autosave_interval'    => array(
				'label'   => __( 'فاصله ذخیره خودکار (ثانیه)', 'hoosh-seo' ),
				'desc'    => __( 'روی هاست‌های شلوغ، ۱۲۰ یا ۳۰۰ فشار را کم می‌کند.', 'hoosh-seo' ),
				'impact'  => 1,
				'safe'    => true,
				'type'    => 'number',
				'min'     => 30,
				'max'     => 600,
				'step'    => 15,
			),
			'heartbeat'            => array(
				'label'   => 'Heartbeat',
				'desc'    => __( 'کاهش ضربان یا خاموش‌کردن کامل در سایت/پیشخوان.', 'hoosh-seo' ),
				'impact'  => 3,
				'safe'    => true,
				'type'    => 'select',
				'options' => array(
					'reduce' => __( 'کاهش (۶۰ ثانیه)', 'hoosh-seo' ),
					'off'    => __( 'خاموش', 'hoosh-seo' ),
					'keep'   => __( 'دست‌نخورده', 'hoosh-seo' ),
				),
			),
			'cdn_url'              => array(
				'label'   => 'CDN URL',
				'desc'    => __( 'دامنه‌ای که تصاویر از آن سرو می‌شوند؛ خالی = غیرفعال.', 'hoosh-seo' ),
				'impact'  => 8,
				'safe'    => false,
				'type'    => 'text',
			),
			'excluded_scripts'     => array(
				'label'   => __( 'استثناهای JS', 'hoosh-seo' ),
				'desc'    => __( 'handle اسکریپت‌هایی که هرگز defer/delay نمی‌شوند.', 'hoosh-seo' ),
				'impact'  => 0,
				'safe'    => true,
				'type'    => 'list',
			),
		);
	}

	/**
	 * What is on, what it saves, what is missing.
	 *
	 * @return array
	 */
	public static function checks() {
		$settings = \hoosh_seo()->settings;
		$catalog  = self::catalog();
		$rows     = array();
		$score    = 0;
		$possible = 0;
		$savings  = 0;

		foreach ( $catalog as $key => $def ) {
			$value = $settings->get( 'speed.' . $key, null );
			if ( null === $value ) {
				$value = false;
			}
			$on = is_bool( $value ) ? $value : ( '0' !== (string) $value && '' !== $value && 0 !== $value );
			if ( 'heartbeat' === $key ) {
				$on = 'off' === (string) $value || 'reduce' === (string) $value;
			}
			if ( 'cdn_url' === $key ) {
				$on = (bool) $value;
			}
			if ( 'excluded_scripts' === $key ) {
				$on = (bool) $value;
			}
			$possible += (int) $def['impact'];
			if ( $on ) {
				$score   += (int) $def['impact'];
				$savings += (int) $def['impact'];
			}
			$rows[] = array(
				'key'      => $key,
				'label'    => (string) $def['label'],
				'desc'     => (string) $def['desc'],
				'type'     => (string) $def['type'],
				'value'    => $value,
				'on'       => (bool) $on,
				'impact'   => (int) $def['impact'],
				'safe'     => (bool) $def['safe'],
				'options'  => isset( $def['options'] ) ? $def['options'] : array(),
				'min'      => isset( $def['min'] ) ? $def['min'] : null,
				'max'      => isset( $def['max'] ) ? $def['max'] : null,
				'step'     => isset( $def['step'] ) ? $def['step'] : null,
				'warning'  => ! $def['safe'],
			);
		}

		return array(
			'rows'     => $rows,
			'score'    => $possible ? (int) round( ( $score / $possible ) * 100 ) : 0,
			'savings'  => $savings,
			'possible' => $possible,
			'enabled'  => (bool) $settings->get( 'speed.enabled', false ),
			'psi'      => array(
				'url'   => Helpers::abs_url( '/' ),
				'state' => get_transient( 'hoosh_psi_' . md5( home_url( '/' ) . '|mobile' ) ) ? 'cached' : 'unknown',
			),
		);
	}

	/**
	 * Summary for the Studio header.
	 *
	 * @return array
	 */
	public static function summary() {
		$checks = self::checks();
		$out    = array(
			'score'    => (int) $checks['score'],
			'count'    => count( array_filter( (array) $checks['rows'], static function ( $row ) { return ! empty( $row['on'] ); } ) ),
			'rows'     => array_slice( (array) $checks['rows'], 0, 12 ),
			'missing'  => array_values( array_filter( (array) $checks['rows'], static function ( $row ) { return empty( $row['on'] ) && ! empty( $row['safe'] ); } ) ),
			'enabled'  => (bool) $checks['enabled'],
			'psi'      => Analytics::summary( 28 )['available'] ? 'ok' : 'none',
			'warnings' => array(),
		);
		foreach ( $out['missing'] as $row ) {
			if ( ! empty( $row['warning'] ) ) {
				$out['warnings'][] = (string) $row['label'];
			}
		}
		return $out;
	}

	/**
	 * Toggle one or more tweaks.
	 *
	 * @param array $args {toggles, only, all, safe}.
	 * @return array
	 */
	public static function toggle( $args = array() ) {
		$args     = wp_parse_args( (array) $args, array( 'toggles' => array(), 'only' => array(), 'all' => false ) );
		$settings = \hoosh_seo()->settings;
		$toggles  = (array) $args['toggles'];
		$catalog  = self::catalog();
		$changed  = array();

		if ( ! empty( $args['all'] ) ) {
			foreach ( $catalog as $key => $def ) {
				if ( ! empty( $def['safe'] ) && 'bool' === $def['type'] ) {
					$toggles[ $key ] = true;
				}
			}
			$toggles['enabled'] = true;
		}
		foreach ( (array) $args['only'] as $key ) {
			$toggles[ (string) $key ] = true;
		}

		$tree = array();
		foreach ( $toggles as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( 'enabled' === $key ) {
				$tree['enabled'] = (bool) $value;
				continue;
			}
			if ( ! isset( $catalog[ $key ] ) ) {
				continue;
			}
			$type = (string) $catalog[ $key ]['type'];
			if ( 'bool' === $type ) {
				$tree[ $key ] = (bool) $value;
			} elseif ( 'number' === $type ) {
				$tree[ $key ] = (int) $value;
			} elseif ( 'list' === $type ) {
				$tree[ $key ] = array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) );
			} elseif ( 'text' === $type ) {
				$tree[ $key ] = esc_url_raw( (string) $value );
			} else {
				$tree[ $key ] = sanitize_key( (string) $value );
			}
			$changed[] = $key;
		}

		if ( ! $tree ) {
			return array( 'ok' => false, 'message' => __( 'تغییری نبود.', 'hoosh-seo' ) );
		}

		$before = (array) $settings->get( 'speed', array() );
		$settings->set( array( 'speed' => $tree ), true );
		Helpers::cache_flush_all();

		Audit::log(
			'crawl',
			'settings',
			array(
				'label'  => sprintf( /* translators: %s items */ __( 'تنظیمات سرعت: %s', 'hoosh-seo' ), implode( ', ', $changed ) ),
				'before' => array_intersect_key( $before, array_combine( array_keys( $tree ), array_keys( $tree ) ) ),
				'after'  => (array) $settings->get( 'speed', array() ),
			)
		);

		$checks = self::checks();
		return array(
			'ok'      => true,
			'changed' => $changed,
			'score'   => (int) $checks['score'],
			'rows'    => (array) $checks['rows'],
			'speed'   => $checks,
			'message' => sprintf( /* translators: %d count */ __( '%d تنظیم سرعت اعمال شد.', 'hoosh-seo' ), count( $changed ) ),
		);
	}

	/**
	 * Apply the safe set (admin-post + REST).
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function run( $args = array() ) {
		$args    = wp_parse_args( (array) $args, array( 'scope' => 'safe' ) );
		$catalog = self::catalog();
		$toggles = array( 'enabled' => true );

		foreach ( $catalog as $key => $def ) {
			if ( 'bool' !== $def['type'] ) {
				continue;
			}
			if ( 'safe' === $args['scope'] && empty( $def['safe'] ) ) {
				continue;
			}
			$toggles[ $key ] = true;
		}
		if ( 'safe' !== $args['scope'] ) {
			$toggles['js_delay_ms'] = 0;
			$toggles['revisions_limit'] = 10;
			$toggles['autosave_interval'] = 120;
		}

		$out          = self::toggle( array( 'toggles' => $toggles ) );
		$out['scope'] = (string) $args['scope'];
		return $out;
	}

	/**
	 * Admin-post runner.
	 */
	public static function ajax_run() {
		check_admin_referer( 'hoosh_seo_tools' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازه ندارید.', 'hoosh-seo' ) );
		}
		self::run( array( 'scope' => isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'safe' ) ); // phpcs:ignore
		wp_safe_redirect( add_query_arg( 'hs_msg', 'speed-applied', \HooshSEO\App::studio_url( 'speed' ) ) );
		exit;
	}

	/**
	 * Kill emoji scripts + filters.
	 */
	public static function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( __CLASS__, 'tiny_mce_no_emoji' ) );
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	/**
	 * Drop the emoji tinymce plugin.
	 *
	 * @param array $plugins Plugins.
	 * @return array
	 */
	public static function tiny_mce_no_emoji( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	}

	/**
	 * Disable oEmbed discovery + rewrite.
	 */
	public static function disable_oembed() {
		if ( ! is_admin() ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}
		add_filter( 'embed_oembed_discover', '__return_false' );
	}

	/**
	 * Remove jQuery Migrate.
	 *
	 * @param \WP_Scripts $scripts Scripts.
	 */
	public static function drop_migrate( $scripts ) {
		if ( is_admin() || ! $scripts instanceof \WP_Scripts ) {
			return;
		}
		if ( ! empty( $scripts->registered['jquery-core'] ) ) {
			$scripts->registered['jquery-core']->deps = array();
		}
		$scripts->remove( 'jquery' );
		$scripts->add( 'jquery', includes_url( '/js/jquery/jquery.min.js' ), array(), null ); // phpcs:ignore
	}

	/**
	 * Strip cache-busting query strings.
	 *
	 * @param string $src Source.
	 * @return string
	 */
	public static function strip_ver( $src ) {
		if ( is_string( $src ) && false !== strpos( $src, '?' ) ) {
			$src = remove_query_arg( array( 'ver', 'm', 'p' ), $src );
			$src = rtrim( $src, '?&' );
		}
		return $src;
	}

	/**
	 * Defer (and optionally delay) a script.
	 *
	 * @param string $tag    Tag.
	 * @param string $handle Handle.
	 * @param string $src    Source.
	 * @return string
	 */
	public static function script_tag( $tag, $handle, $src ) {
		$settings = \hoosh_seo()->settings;
		if ( is_admin() ) {
			return $tag;
		}
		$excluded = array_merge( self::$never, array_map( 'sanitize_key', (array) $settings->get( 'speed.excluded_scripts', array() ) ) );
		if ( in_array( (string) $handle, $excluded, true ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) ) {
			return $tag;
		}
		$delay = (int) $settings->get( 'speed.js_delay_ms', 0 );

		if ( $delay > 0 && $settings->on( 'speed.defer_js', false ) ) {
			$url   = esc_url( (string) $src );
			$ms    = max( 100, $delay );
			return sprintf(
				'<script type="text/hoosh-defer" data-src="%1$s" data-handle="%2$s"></script><script>(function(){var d=%3$d;setTimeout(function(){document.querySelectorAll(\'script[type="text/hoosh-defer"]\').forEach(function(s){var n=document.createElement("script");n.src=s.dataset.src;if(s.async){n.async=1;}s.replaceWith(n);});},d);})();</script>',
				$url,
				esc_attr( (string) $handle ),
				$ms
			);
		}
		return str_replace( ' src=', ' defer src=', $tag );
	}

	/**
	 * Dequeue block CSS on pages without blocks.
	 */
	public static function unused_css() {
		if ( is_admin() ) {
			return;
		}
		$post_id = get_queried_object_id();
		$has_blocks = false;
		if ( $post_id && function_exists( 'has_blocks' ) ) {
			$has_blocks = has_blocks( (string) get_post_field( 'post_content', (int) $post_id ) );
		} elseif ( is_archive() || is_search() ) {
			$has_blocks = true;
		}
		if ( $has_blocks ) {
			return;
		}
		wp_dequeue_style( 'wp-block-library' );
		wp_dequeue_style( 'wp-block-library-theme' );
		wp_dequeue_style( 'wc-blocks-style' );
		wp_dequeue_style( 'global-styles' );
		remove_action( 'wp_head', 'wp_enqueue_global_styles' );
	}

	/**
	 * Inline the plugin's front CSS, defer the rest.
	 */
	public static function critical_css() {
		$file = HOOSH_SEO_DIR . 'assets/front.css';
		if ( ! file_exists( $file ) ) {
			return;
		}
		$css = (string) file_get_contents( $file ); // phpcs:ignore
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = trim( (string) preg_replace( '/\s*([{}:;,])\s*/', '$1', $css ) );
		if ( strlen( $css ) > 18000 ) {
			$css = mb_substr( $css, 0, 18000 );
		}
		echo '<style id="hoosh-critical">' . $css . '</style>' . "\n"; // phpcs:ignore
	}

	/**
	 * Preload the remote font stylesheet.
	 */
	public static function preload_fonts() {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'appearance.remote_font', true ) ) {
			return;
		}
		printf(
			'<link rel="preload" as="style" href="%s" /><link rel="stylesheet" href="%s" media="print" onload="this.media=\'all\'" />' . "\n",
			esc_url( self::font_css_url() ),
			esc_url( self::font_css_url() )
		);
	}

	/**
	 * Font CSS URL.
	 *
	 * @return string
	 */
	public static function font_css_url() {
		return 'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css';
	}

	/**
	 * Preload the featured image on singular views.
	 */
	public static function preload_lcp() {
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || ! has_post_thumbnail( $post_id ) ) {
			return;
		}
		$image = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
		if ( ! $image ) {
			return;
		}
		printf(
			'<link rel="preload" as="image" href="%1$s" fetchpriority="high" />' . "\n",
			esc_url( $image[0] )
		);
	}

	/**
	 * Image attributes: lazy, async decoding, placeholder background.
	 *
	 * @param array             $attrs      Attributes.
	 * @param \WPAttachment| int $attachment Attachment.
	 * @param string            $size       Size.
	 * @return array
	 */
	public static function image_attributes( $attrs, $attachment = null, $size = 'thumbnail' ) {
		unset( $attachment, $size );
		if ( ! isset( $attrs['loading'] ) ) {
			$attrs['loading']  = 'lazy';
			$attrs['decoding'] = 'async';
		}
		$width  = isset( $attrs['width'] ) ? (int) $attrs['width'] : 0;
		$height = isset( $attrs['height'] ) ? (int) $attrs['height'] : 0;
		if ( $width && $height ) {
			$attrs['style'] = trim( (string) ( $attrs['style'] ?? '' ) ) . sprintf( 'aspect-ratio:%d/%d;background:#f2f2f2;', $width, $height );
		}
		return $attrs;
	}

	/**
	 * Lazy iframes + videos.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function lazy_iframes( $content ) {
		if ( ! is_string( $content ) || ( false === strpos( $content, '<iframe' ) && false === strpos( $content, '<video' ) ) ) {
			return $content;
		}
		$content = (string) preg_replace_callback(
			'/<iframe\b[^>]*>/i',
			function ( $m ) {
				$tag = $m[0];
				if ( false !== strpos( $tag, 'loading=' ) ) {
					return $tag;
				}
				return rtrim( $tag, '>' ) . ' loading="lazy">';
			},
			$content
		);
		$content = (string) preg_replace_callback(
			'/<video\b([^>]*)>/i',
			function ( $m ) {
				$attrs = $m[1];
				if ( false !== stripos( $attrs, 'preload' ) ) {
					return $m[0];
				}
				return '<video' . $attrs . ' preload="none" loading="lazy">';
			},
			$content
		);
		return $content;
	}

	/**
	 * Serve avatars from the local cache.
	 *
	 * @param string $avatar HTML.
	 * @return string
	 */
	public static function gravatar_cache( $avatar ) {
		if ( ! preg_match( '/src=[\'"]([^\'"]+)[\'"]/i', (string) $avatar, $m ) ) {
			return $avatar;
		}
		$source = (string) $m[1];
		if ( 0 !== strpos( $source, 'https:' ) && 0 !== strpos( $source, 'http:' ) ) {
			return $avatar;
		}
		$dir = wp_upload_dir();
		if ( ! is_dir( $dir['basedir'] . '/hoosh-avatars' ) ) {
			if ( ! wp_mkdir_p( $dir['basedir'] . '/hoosh-avatars' ) ) {
				return $avatar;
			}
		}
		$name = md5( $source ) . '.png';
		$file = $dir['basedir'] . '/hoosh-avatars/' . $name;

		if ( ! file_exists( $file ) || ( time() - (int) filemtime( $file ) ) > 7 * DAY_IN_SECONDS ) {
			$raw = Helpers::http( $source, array( 'timeout' => 8 ) );
			if ( empty( $raw['ok'] ) || strlen( (string) $raw['body'] ) < 200 ) {
				return $avatar;
			}
			if ( ! file_put_contents( $file, $raw['body'] ) ) { // phpcs:ignore
				return $avatar;
			}
		}
		return str_replace( $m[1], $dir['baseurl'] . '/hoosh-avatars/' . $name, $avatar );
	}

	/**
	 * Cap avatar size.
	 *
	 * @param array $args Args.
	 * @param mixed $argument Requested.
	 * @return array
	 */
	public static function avatar_size( $args, $argument = null ) {
		unset( $argument );
		$size = (int) \hoosh_seo()->settings->get( 'speed.avatar_size', 0 );
		if ( $size > 0 ) {
			$args['size'] = $size;
		}
		return $args;
	}

	/**
	 * Resource hints for third-party hosts in the current content.
	 *
	 * @param array  $hints Hints.
	 * @param string $relation Relation.
	 * @return array
	 */
	public static function resource_hints( $hints, $relation ) {
		if ( 'dns-prefetch' !== $relation && 'preconnect' !== $relation ) {
			return $hints;
		}
		$hosts = array( 'cdn.jsdelivr.net', 'www.youtube.com', 'i.ytimg.com', 'secure.gravatar.com', 'fonts.googleapis.com', 'fonts.gstatic.com' );
		foreach ( $hosts as $host ) {
			if ( ! in_array( '//' . $host, (array) $hints, true ) ) {
				$hints[] = array( 'href' => '//' . $host, 'crossorigin' => 'preconnect' === $relation );
			}
		}
		return $hints;
	}

	/**
	 * Rewrite attachment URLs to a CDN host.
	 *
	 * @param string $url URL.
	 * @param int    $id  Attachment ID.
	 * @return string
	 */
	public static function cdn_url( $url, $id = 0 ) {
		unset( $id );
		$cdn = trim( (string) \hoosh_seo()->settings->get( 'speed.cdn_url', '' ) );
		if ( ! $cdn || ! is_string( $url ) ) {
			return $url;
		}
		$uploads = wp_parse_url( (string) ( wp_get_upload_dir()['baseurl'] ?? $url ) );
		$cdn_url = wp_parse_url( $cdn );
		if ( empty( $uploads['host'] ) || empty( $cdn_url['host'] ) || $uploads['host'] === $cdn_url['host'] ) {
			return $url;
		}
		if ( false === strpos( $url, $uploads['host'] ) ) {
			return $url;
		}
		$scheme = (string) ( $cdn_url['scheme'] ?? 'https' );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		return $scheme . '://' . $cdn_url['host'] . ( isset( $cdn_url['port'] ) ? ':' . $cdn_url['port'] : '' ) . $path;
	}

	/**
	 * Heartbeat every 60s instead of 15s.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function heartbeat_settings( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}

	/**
	 * Slow down the editor autosave.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function autosave_settings( $settings ) {
		$settings['interval'] = max( 60, (int) \hoosh_seo()->settings->get( 'speed.autosave_interval', 60 ) );
		return $settings;
	}

	/**
	 * Kill the heartbeat entirely.
	 */
	public static function kill_heartbeat() {
		wp_deregister_script( 'heartbeat' );
		wp_register_script( 'heartbeat', '', array(), null, true ); // phpcs:ignore
	}

	/**
	 * Revision cap.
	 *
	 * @param int    $num  Number.
	 * @param object $post Post.
	 * @return int
	 */
	public static function revisions( $num, $post = null ) {
		unset( $post );
		$limit = (int) \hoosh_seo()->settings->get( 'speed.revisions_limit', 0 );
		if ( $limit < 1 ) {
			return $num;
		}
		return $limit;
	}

	/**
	 * Buffer the page and minify it on the way out.
	 */
	public static function buffer_html() {
		if ( is_admin() || is_user_logged_in() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( ! headers_sent() ) {
			ob_start( array( __CLASS__, 'minify_html' ) );
		}
	}

	/**
	 * Naive, safe HTML minifier (keeps pre/textarea/script intact).
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function minify_html( $html ) {
		$html = (string) $html;
		if ( '' === $html || false === strpos( $html, '<' ) ) {
			return $html;
		}
		$keep = array();
		$html = (string) preg_replace_callback(
			'#<(pre|textarea|script|style)\b.*?</\1>#is',
			function ( $m ) use ( &$keep ) {
				$key            = '<!--hoosh-keep-' . count( $keep ) . '-->';
				$keep[ $key ]   = $m[0];
				return $key;
			},
			$html
		);
		$html = (string) preg_replace( '/\s+/', ' ', $html );
		$html = (string) preg_replace( '/>\s+</', '><', $html );
		$html = (string) preg_replace( '/<!--(?!\s*(?:\[if|\[endif|hoosh-keep)).*?-->/s', '', $html );
		foreach ( $keep as $key => $value ) {
			$html = str_replace( $key, $value, $html );
		}
		return trim( $html );
	}

	/**
	 * Impact of what is currently on, in bytes (rough, for the UI).
	 *
	 * @return array
	 */
	public static function estimate() {
		$checks = self::checks();
		$bytes  = 0;
		foreach ( (array) $checks['rows'] as $row ) {
			if ( empty( $row['on'] ) ) {
				continue;
			}
			switch ( $row['key'] ) {
				case 'disable_emojis':
					$bytes += 8500;
					break;
				case 'remove_jquery_migrate':
					$bytes += 15000;
					break;
				case 'remove_unused_css':
					$bytes += 45000;
					break;
				case 'disable_oembed':
					$bytes += 6500;
					break;
				case 'gravatar_cache':
					$bytes += 4000;
					break;
				case 'minify_html':
					$bytes += 3000;
					break;
			}
		}
		return array(
			'bytes'  => $bytes,
			'kb'     => round( $bytes / 1024, 1 ),
			'score'  => (int) $checks['score'],
			'rows'   => (array) $checks['rows'],
		);
	}
}
