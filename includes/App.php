<?php
/**
 * The Studio: a standalone, full-screen application opened in its own tab.
 *
 * It is deliberately not a wp-admin page: no admin chrome, no wp-admin CSS,
 * its own design system, RTL-first, and it talks to the plugin REST API.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class App
 */
final class App {

	/**
	 * Singleton.
	 *
	 * @var App|null
	 */
	private static $instance = null;

	/**
	 * Validated token payload for the current request.
	 *
	 * @var array|null
	 */
	private $auth = null;

	/**
	 * Get instance.
	 *
	 * @return App
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Hooks.
	 */
	public function bootstrap() {
		add_action( 'init', array( $this, 'register_routes' ), 1 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 1 );
		add_action( 'init', array( $this, 'maybe_serve_early' ), 2 );
	}

	/**
	 * Studio path (pretty permalinks) — falls back to a query-string URL.
	 *
	 * @return string
	 */
	public static function path() {
		$path = hoosh_seo()->settings ? hoosh_seo()->settings->get( 'advanced.app_path', HOOSH_SEO_APP_SLUG ) : HOOSH_SEO_APP_SLUG;
		return trim( (string) $path, '/' );
	}

	/**
	 * Register rewrite rules for every public route owned by the plugin.
	 */
	public static function register_routes() {
		$path = self::path();
		if ( '' === $path ) {
			$path = HOOSH_SEO_APP_SLUG;
		}
		add_rewrite_rule( '^' . preg_quote( $path, '#' ) . '/?$', 'index.php?hoosh_studio=1', 'top' );
		add_rewrite_rule( '^' . preg_quote( $path, '#' ) . '/(api|token)/?$', 'index.php?hoosh_studio=1&hoosh_route=$matches[1]', 'top' );
		add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?hoosh_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap-([a-z0-9\-_]+)(?:-(\d+))?\.xml$', 'index.php?hoosh_sitemap=$matches[1]&paged=$matches[2]', 'top' );
		add_rewrite_rule( '^(news|video|image|author|html)-sitemap\.xml$', 'index.php?hoosh_sitemap=$matches[1]', 'top' );
		add_rewrite_rule( '^llms\.txt$', 'index.php?hoosh_llms=short', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?hoosh_llms=full', 'top' );
		add_rewrite_rule( '^hoosh-link/(\d+)/?$', 'index.php?hoosh_link=$matches[1]', 'top' );
	}

	/**
	 * Expose query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		return array_merge(
			$vars,
			array( 'hoosh_studio', 'hoosh_route', 'hoosh_sitemap', 'paged', 'hoosh_llms', 'hoosh_link', 'hoosh_export', 'hoosh_cron' )
		);
	}

	/**
	 * Serve robots.txt / llms.txt even when the request never reaches the router.
	 */
	public function maybe_serve_early() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$uri = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! $uri ) {
			return;
		}
		$uri = trim( (string) $uri, '/' );

		if ( 'robots.txt' === $uri && hoosh_seo()->settings->get( 'robots.virtual', true ) ) {
			$real = untrailingslashit( ABSPATH ) . '/robots.txt';
			// A physical file wins unless the user asked us to override it.
			if ( ! file_exists( $real ) || hoosh_seo()->settings->get( 'robots.override_file', false ) ) {
				Modules\Robots::serve();
			}
		}
	}

	/**
	 * Router: studio, sitemaps, llms.txt, click redirect, exports.
	 *
	 * @param \WP $wp Request.
	 */
	public function maybe_serve( $wp ) {
		if ( isset( $wp->query_vars['hoosh_sitemap'] ) ) {
			Modules\Sitemap::serve( (string) $wp->query_vars['hoosh_sitemap'], isset( $wp->query_vars['paged'] ) ? (int) $wp->query_vars['paged'] : 0 );
		}

		if ( isset( $wp->query_vars['hoosh_llms'] ) ) {
			Modules\Geo::serve_llms( (string) $wp->query_vars['hoosh_llms'] );
		}

		if ( ! empty( $wp->query_vars['hoosh_link'] ) ) {
			Modules\Links::track_and_redirect( (int) $wp->query_vars['hoosh_link'] );
		}

		if ( isset( $wp->query_vars['hoosh_export'] ) ) {
			$this->serve_export( (string) $wp->query_vars['hoosh_export'] );
		}

		if ( ! isset( $wp->query_vars['hoosh_studio'] ) ) {
			return;
		}

		if ( 'token' === ( isset( $wp->query_vars['hoosh_route'] ) ? $wp->query_vars['hoosh_route'] : '' ) ) {
			$this->serve_token();
			exit;
		}

		$this->serve_studio();
		exit;
	}

	/**
	 * Studio URL with a short-lived, single-purpose token.
	 *
	 * @param string $view Optional deep link (e.g. "keywords").
	 * @return string
	 */
	public static function studio_url( $view = '' ) {
		$token = self::issue_token();
		$args  = array(
			'hoosh_studio' => 1,
			'hs_token'     => $token,
		);
		if ( $view ) {
			$args['view'] = rawurlencode( $view );
		}

		$permalink = get_option( 'permalink_structure' );
		if ( $permalink && hoosh_seo()->settings->get( 'advanced.app_path' ) ) {
			$url = home_url( '/' . self::path() . '/' );
			return add_query_arg( $args, $url );
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Sign a token for the current user.
	 *
	 * @return string
	 */
	public static function issue_token() {
		$user_id = get_current_user_id();
		$ttl     = (int) hoosh_seo()->settings->get( 'security.token_ttl', 43200 );
		$exp     = time() + max( 300, $ttl );
		$payload = wp_json_encode(
			array(
				'uid' => $user_id,
				'exp' => $exp,
				'cap'  => self::capability_hash(),
			)
		);
		$body = base64_encode( $payload ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $body . '.' . hash_hmac( 'sha256', $body, self::secret() );
	}

	/**
	 * Token secret: salted with wp salts so tokens die on salt rotation.
	 *
	 * @return string
	 */
	protected static function secret() {
		$parts = array();
		foreach ( array( 'LOGGED_IN_SALT', 'LOGGED_IN_KEY', 'AUTH_SALT' ) as $const ) {
			if ( defined( $const ) ) {
				$parts[] = constant( $const );
			}
		}
		$parts[] = get_option( 'hoosh_seo_token_seed' );
		if ( ! $parts[ count( $parts ) - 1 ] ) {
			$seed = wp_generate_password( 48, false, false );
			update_option( 'hoosh_seo_token_seed', $seed, false );
			$parts[ count( $parts ) - 1 ] = $seed;
		}
		return implode( '|', $parts );
	}

	/**
	 * A hash of the user's capability set, so a permission change invalidates tokens.
	 *
	 * @return string
	 */
	protected static function capability_hash() {
		$user = wp_get_current_user();
		$caps = array_keys( (array) $user->allcaps );
		sort( $caps );
		return substr( md5( implode( ',', array_slice( $caps, 0, 40 ) ) ), 0, 12 );
	}

	/**
	 * Validate the token from the request.
	 *
	 * @param bool $required Require manage_options (studio) or allow editors (exports).
	 * @return bool
	 */
	public function verify_token( $required = 'manage_options' ) {
		if ( $this->auth ) {
			return true;
		}

		$token = isset( $_GET['hs_token'] ) ? sanitize_text_field( wp_unslash( $_GET['hs_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return false;
		}

		list( $body, $signature ) = explode( '.', $token, 2 );
		$expected = hash_hmac( 'sha256', $body, self::secret() );
		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		$payload = json_decode( base64_decode( $body ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $payload ) || empty( $payload['exp'] ) || $payload['exp'] < time() ) {
			return false;
		}

		$user = get_userdata( (int) $payload['uid'] );
		if ( ! $user ) {
			return false;
		}

		$cap = 'manage_options';
		if ( 'edit_posts' === $required ) {
			$cap = hoosh_seo()->settings->get( 'security.allow_editors', true ) ? 'edit_posts' : 'manage_options';
		}

		if ( ! user_can( $user, $cap ) ) {
			return false;
		}

		if ( isset( $payload['cap'] ) && $payload['cap'] !== self::capability_hash_for( $user ) ) {
			return false;
		}

		$this->auth = array(
			'user_id' => (int) $user->ID,
			'login'   => $user->user_login,
			'cap'     => $cap,
		);

		// Act as that user for the rest of the request (capabilities + REST nonce).
		wp_set_current_user( (int) $user->ID );

		return true;
	}

	/**
	 * Capability hash for an arbitrary user.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	protected static function capability_hash_for( $user ) {
		$caps = array_keys( (array) $user->allcaps );
		sort( $caps );
		return substr( md5( implode( ',', array_slice( $caps, 0, 40 ) ) ), 0, 12 );
	}

	/**
	 * Mints a fresh token for the SPA (keeps long sessions alive).
	 */
	protected function serve_token() {
		if ( ! $this->verify_token() ) {
			status_header( 403 );
			wp_die( 'forbidden' );
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( array( 'token' => self::issue_token(), 'ttl' => (int) hoosh_seo()->settings->get( 'security.token_ttl', 43200 ) ) );
		exit;
	}

	/**
	 * CSV / JSON exports, token authenticated.
	 *
	 * @param string $type Export type.
	 */
	protected function serve_export( $type ) {
		if ( ! $this->verify_token( 'edit_posts' ) ) {
			status_header( 403 );
			wp_die( 'forbidden' );
		}

		$allowed = array(
			'keywords'   => 'HooshSEO-keywords',
			'audit'      => 'HooshSEO-audit',
			'pages'      => 'HooshSEO-pages',
			'redirects'  => 'HooshSEO-redirects',
			'notfound'   => 'HooshSEO-404',
			'links'      => 'HooshSEO-links',
			'clicks'     => 'HooshSEO-clicks',
			'positions'  => 'HooshSEO-positions',
			'research'   => 'HooshSEO-keywords',
			'images'     => 'HooshSEO-images',
			'schema'     => 'HooshSEO-schema',
			'report'     => 'HooshSEO-report',
		);

		if ( ! isset( $allowed[ $type ] ) ) {
			status_header( 404 );
			wp_die( 'unknown export' );
		}

		$rows = Reports::export_rows( $type );
		if ( 'report' === $type ) {
			Reports::stream_html( $rows, $allowed[ $type ] );
			exit;
		}

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $allowed[ $type ] . '-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );

		// BOM so Excel on Windows renders Persian correctly.
		echo "\xEF\xBB\xBF"; // phpcs:ignore
		$handle = fopen( 'php://output', 'w' );
		foreach ( $rows as $row ) {
			fputcsv( $handle, (array) $row );
		}
		fclose( $handle );
		exit;
	}

	/**
	 * Render the Studio shell document.
	 */
	protected function serve_studio() {
		if ( ! $this->verify_token() ) {
			$this->render_expired();
			exit;
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: same-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );

		$config = $this->config();

		echo '<!doctype html>' . "\n";
		echo '<html lang="fa-IR" dir="rtl" data-theme="' . esc_attr( $config['appearance']['theme'] ) . '">' . "\n";
		echo '<head>' . "\n";
		echo '<meta charset="' . esc_attr( get_option( 'blog_charset', 'UTF-8' ) ) . '">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
		echo '<meta name="robots" content="noindex, nofollow">' . "\n";
		echo '<meta name="color-scheme" content="light dark">' . "\n";
		echo '<meta name="theme-color" content="#0e1116">' . "\n";
		echo '<title>' . esc_html__( 'استودیوی هوش‌سئو', 'hoosh-seo' ) . ' — ' . esc_html( get_bloginfo( 'name' ) ) . '</title>' . "\n";
		echo '<link rel="icon" href="' . esc_url( HOOSH_SEO_URL . 'assets/img/favicon.svg' ) . '" sizes="any">' . "\n";
		echo '<script>window.HOOSH = ' . wp_json_encode( $config ) . ';</script>' . "\n";
		echo '<link rel="stylesheet" href="' . esc_url( $this->asset_url( 'assets/app.css' ) ) . '?ver=' . HOOSH_SEO_VERSION . '">' . "\n";
		echo '</head>' . "\n";
		echo '<body class="hs-body hs-density-' . esc_attr( $config['appearance']['density'] ) . ' hs-accent-' . esc_attr( $config['appearance']['accent'] ) . '">' . "\n";
		echo '<div id="hs-root" class="hs-app" aria-busy="true"></div>' . "\n";
		echo '<noscript><div class="hs-noscript">' . esc_html__( 'برای اجرای استودیو هوش‌سئو، جاوااسکریپت را فعال کنید.', 'hoosh-seo' ) . '</div></noscript>' . "\n";
		echo '<script src="' . esc_url( $this->asset_url( 'assets/app.js' ) ) . '?ver=' . HOOSH_SEO_VERSION . '" defer></script>' . "\n";
		echo '</body>' . "\n";
		echo '</html>';
	}

	/**
	 * Friendly expired-token page with a one-click re-open.
	 */
	protected function render_expired() {
		status_header( 401 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		$back = admin_url( 'admin.php?page=hoosh-seo' );
		echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>' . esc_html__( 'دسترسی منقضی شد', 'hoosh-seo' ) . '</title>';
		echo '<link rel="stylesheet" href="' . esc_url( $this->asset_url( 'assets/app.css' ) ) . '?ver=' . HOOSH_SEO_VERSION . '">';
		echo '<body class="hs-body hs-solo"><div class="hs-expired">';
		echo '<h1>' . esc_html__( 'مهلت این نشانی تمام شد', 'hoosh-seo' ) . '</h1>';
		echo '<p>' . esc_html__( 'استودیو با یک نشانی موقت و یک‌بارمصرف باز می‌شود. از پیشخوان وردپرس دوباره آن را باز کنید.', 'hoosh-seo' ) . '</p>';
		echo '<a class="hs-btn hs-btn--primary" href="' . esc_url( $back ) . '">' . esc_html__( 'بازگشت به پیشخوان', 'hoosh-seo' ) . '</a>';
		echo '</div></body></html>';
	}

	/**
	 * Everything the SPA needs to boot. One place, no extra round trip.
	 *
	 * @return array
	 */
	public function config() {
		$settings = hoosh_seo()->settings;

		return array(
			'version'     => HOOSH_SEO_VERSION,
			'rest'        => esc_url_raw( rest_url( $settings->get( 'advanced.rest_namespace', 'hoosh/v1' ) ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'studioUrl'   => esc_url_raw( self::studio_url() ),
			'site'        => array(
				'name'     => get_bloginfo( 'name' ),
				'home'     => home_url( '/' ),
				'admin'    => admin_url(),
				'timezone' => wp_timezone_string(),
				'locale'   => get_locale(),
				'charset'  => get_option( 'blog_charset' ),
				'wp'       => get_bloginfo( 'version' ),
				'php'      => PHP_VERSION,
				'multisite' => is_multisite(),
			),
			'user'        => array(
				'id'      => get_current_user_id(),
				'name'    => wp_get_current_user()->display_name,
				'email'   => wp_get_current_user()->user_email,
				'canManage' => current_user_can( 'manage_options' ),
				'avatar'  => get_avatar_url( get_current_user_id(), array( 'size' => 48 ) ),
			),
			'appearance'  => array(
			'theme'    => $settings->get( 'appearance.theme', 'auto' ),
				'accent'   => $settings->get( 'appearance.accent', 'firouzeh' ),
				'density'  => $settings->get( 'appearance.density', 'comfortable' ),
				'font'     => $settings->get( 'appearance.font', 'vazirmatn' ),
				'remoteFont' => (bool) $settings->get( 'appearance.remote_font', true ),
				'fontUrl'  => $settings->get( 'appearance.font_url', '' ),
				'digits'   => (bool) $settings->get( 'appearance.persian_digits', true ),
				'shamsi'   => (bool) $settings->get( 'appearance.shamsi', true ),
				'customCss' => $settings->get( 'appearance.custom_css', '' ),
			),
			'nav'         => $this->nav(),
			'taxonomies'  => $this->public_taxonomies(),
			'postTypes'   => Helpers::post_types( false ),
			'settings'    => $this->settings_for_app( $settings->all() ),
			'ai'          => array(
				'enabled'   => (bool) $settings->get( 'ai.enabled', true ),
				'driver'    => $settings->get( 'ai.driver', '' ),
				'providers' => AI\Providers::catalog(),
				'tasks'     => AI\Tasks::catalog(),
				'spend'     => AI\Gateway::spend_summary(),
			),
			'keywordSources' => Modules\KeywordResearch::sources(),
			'schemaTypes'    => Modules\Schema::types(),
			'checks'         => Modules\Content::check_catalog(),
			'features'       => $this->feature_flags(),
			'notices'        => $this->notices(),
			'kpiSnapshot'    => Modules\Audit::quick_snapshot(),
		);
	}

	/**
	 * Sidebar navigation definition (single source for nav + router).
	 *
	 * @return array
	 */
	public function nav() {
		return array(
			array(
				'group' => 'امروز',
				'items' => array(
				array( 'id' => 'dashboard', 'label' => 'داشبورد', 'icon' => 'gauge' ),
				array( 'id' => 'agent', 'label' => 'ایجنت خودکار', 'icon' => 'robot', 'badge' => 'agent' ),
				array( 'id' => 'tasks', 'label' => 'صف کارها', 'icon' => 'check-list', 'badge' => 'tasks' ),
				),
			),
			array(
				'group' => 'محتوا و کلمات',
				'items' => array(
					array( 'id' => 'content', 'label' => 'تحلیل محتوا', 'icon' => 'document' ),
					array( 'id' => 'snippet', 'label' => 'استودیو اسنیپت', 'icon' => 'search-snippet' ),
					array( 'id' => 'keywords', 'label' => 'تحقیق کلمات کلیدی', 'icon' => 'magnifier' ),
					array( 'id' => 'tracking', 'label' => 'ردیاب رتبه', 'icon' => 'trend' ),
					array( 'id' => 'links', 'label' => 'لینک داخلی', 'icon' => 'link' ),
				),
			),
			array(
				'group' => 'ساختار سایت',
				'items' => array(
					array( 'id' => 'audit', 'label' => 'ممیزی فنی', 'icon' => 'stethoscope', 'badge' => 'issues' ),
					array( 'id' => 'sitemap', 'label' => 'نقشه سایت', 'icon' => 'map' ),
					array( 'id' => 'index', 'label' => 'ایندکس‌بان', 'icon' => 'eye' ),
					array( 'id' => 'schema', 'label' => 'استودیوی اسکیما', 'icon' => 'braces' ),
					array( 'id' => 'redirects', 'label' => 'ریدایرکت‌ها', 'icon' => 'arrow-turn' ),
					array( 'id' => 'notfound', 'label' => 'خطاهای ۴۰', 'icon' => 'warning', 'badge' => '404' ),
					array( 'id' => 'images', 'label' => 'سئوی تصاویر', 'icon' => 'image' ),
					array( 'id' => 'woo', 'label' => 'سئوی فروشگاه', 'icon' => 'cart' ),
				),
			),
			array(
				'group' => 'دیده‌شدن',
				'items' => array(
					array( 'id' => 'ai-search', 'label' => 'موتورهای پاسخ (GEO)', 'icon' => 'sparkle' ),
					array( 'id' => 'search-console', 'label' => 'سرچ کنسول و آنالیتیکس', 'icon' => 'chart' ),
					array( 'id' => 'social', 'label' => 'شبکه‌های اجتماعی', 'icon' => 'share' ),
					array( 'id' => 'speed', 'label' => 'سرعت و Core Web Vitals', 'icon' => 'bolt' ),
				),
			),
			array(
				'group' => 'خودکارسازی',
				'items' => array(
					array( 'id' => 'autopilot', 'label' => 'خلبانی سئو', 'icon' => 'robot' ),
					array( 'id' => 'reports', 'label' => 'گزارش و خلاصه مدیریتی', 'icon' => 'report' ),
					array( 'id' => 'ai', 'label' => 'تنظیمات هوش مصنوعی', 'icon' => 'chip' ),
				),
			),
			array(
				'group' => 'تنظیمات',
				'items' => array(
					array( 'id' => 'settings', 'label' => 'تنظیمات عمومی', 'icon' => 'settings' ),
					array( 'id' => 'titles', 'label' => 'قالب عنوان و متا', 'icon' => 'text' ),
					array( 'id' => 'robots', 'label' => 'رباتز و دسترسی', 'icon' => 'shield' ),
					array( 'id' => 'crawl', 'label' => 'رصد خزنده‌ها', 'icon' => 'bug' ),
					array( 'id' => 'tools', 'label' => 'ابزارها و مهاجرت', 'icon' => 'wrench' ),
					array( 'id' => 'help', 'label' => 'راهنما و پشتیبانی', 'icon' => 'life' ),
				),
			),
		);
	}

	/**
	 * Public taxonomies for filters.
	 *
	 * @return array
	 */
	protected function public_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$out[ $tax->name ] = array(
			'label' => $tax->labels->singular_name,
				'types' => $tax->object_type,
			);
		}
		return $out;
	}

	/**
	 * Strip secrets before handing settings to the browser.
	 *
	 * @param array $tree Settings tree.
	 * @return array
	 */
	protected function settings_for_app( $tree ) {
		$walk = function ( &$node ) use ( &$walk ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			foreach ( $node as $key => &$value ) {
				$is_secret = is_string( $key ) && preg_match( '/(key|token|secret|password|credentials|cron_key|api)$/i', $key );
				if ( $is_secret ) {
					$value = array( 'set' => (bool) $value, 'masked' => is_string( $value ) ? Helpers::mask_key( $value ) : '' );
					continue;
				}
				if ( is_array( $value ) ) {
					$walk( $value );
				}
			}
			unset( $value );
		};
		$walk( $tree );

		// Never ship Google/OAuth payloads or provider keys to the browser.
		unset( $tree['analytics']['gsc']['credentials'], $tree['analytics']['gsc']['token'], $tree['analytics']['gsc']['refresh'] );
		unset( $tree['analytics']['ga4']['credentials'], $tree['analytics']['ga4']['token'], $tree['analytics']['ga4']['refresh'] );
		unset( $tree['ai']['providers'] );

		return $tree;
	}

	/**
	 * Feature flags so the SPA can hide what the site cannot use.
	 *
	 * @return array
	 */
	protected function feature_flags() {
		return array(
			'woo'        => class_exists( 'WooCommerce' ),
			'edd'        => function_exists( 'edd_get_edd_version' ) || defined( 'EDD_VERSION' ),
			'elementor'  => defined( 'ELEMENTOR_VERSION' ),
			'gutenberg'  => function_exists( 'use_block_editor_for_post' ),
			'multilingual' => defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_languages_list' ),
			'nginx'      => ( false !== stripos( (string) $_SERVER['SERVER_SOFTWARE'], 'nginx' ) ), // phpcs:ignore
			'canWriteRobots' => is_writable( untrailingslashit( ABSPATH ) . '/robots.txt' ) || ! file_exists( untrailingslashit( ABSPATH ) . '/robots.txt' ),
			'canWriteHtaccess' => is_writable( untrailingslashit( ABSPATH ) . '/.htaccess' ),
			'prettyLinks' => (bool) get_option( 'permalink_structure' ),
			'curl'        => function_exists( 'curl_init' ),
			'gd'          => function_exists( 'imagecreatetruecolor' ),
			'objectCache' => wp_using_ext_object_cache(),
			'isSsl'       => is_ssl(),
			'blogPublic'  => (bool) get_option( 'blog_public' ),
			'aiReady'     => AI\Gateway::is_configured(),
			'indexnow'    => true,
		);
	}

	/**
	 * Pending notices (plugin + site health).
	 *
	 * @return array
	 */
	protected function notices() {
		$out = array();
		foreach ( (array) get_option( 'hoosh_seo_notices', array() ) as $key => $notice ) {
			if ( is_array( $notice ) ) {
				$notice['key'] = $key;
				$out[]         = $notice;
			}
		}
		return $out;
	}

	/**
	 * Asset URL, with a ?file= fallback for hosts with odd rewrite setups.
	 *
	 * @param string $relative Relative path inside the plugin.
	 * @return string
	 */
	public function asset_url( $relative ) {
		$url = HOOSH_SEO_URL . ltrim( $relative, '/' );
		/**
		 * Filter Studio asset URLs (for CDN / minify plugins).
		 *
		 * @param string $url Asset URL.
		 * @param string $relative Relative path.
		 */
		return apply_filters( 'hoosh_seo_asset_url', $url, $relative );
	}
}
