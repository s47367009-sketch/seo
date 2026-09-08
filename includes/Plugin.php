<?php
/**
 * Plugin bootstrap / service container.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Booted flag.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Settings handle.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * Registered services, keyed by lowercase short class name.
	 *
	 * @var array
	 */
	private $services = array();

	/**
	 * Modules that could not be loaded, kept for the tools screen.
	 *
	 * @var array
	 */
	private $broken = array();

	/**
	 * Module classes, in load order.
	 *
	 * @var array
	 */
	private $module_classes = array(
		'Settings',
		'Meta',
		'Cron',
		'Modules\OpenGraph',
		'Modules\Sitemap',
		'Modules\Robots',
		'Modules\Schema',
		'Modules\Redirects',
		'Modules\NotFound',
		'Modules\Links',
		'Modules\Content',
		'Modules\IndexManager',
		'Modules\Images',
		'Modules\Woo',
		'Modules\Geo',
		'Modules\Analytics',
		'Modules\KeywordResearch',
		'Modules\RankTracker',
		'Modules\Speed',
		'Modules\Audit',
		'Modules\Automation',
		'Modules\Migration',
		'Modules\Breadcrumbs',
		'AI\Gateway',
		'Agent\Agent',
		'App',
		'Editor',
		'Rest',
		'Admin',
	);

	/**
	 * Get instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Wire everything up.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'hoosh-seo', false, dirname( HOOSH_SEO_BASENAME ) . '/languages' );

		foreach ( $this->module_classes as $relative ) {
			$relative = str_replace( '_', '', $relative );
			$class    = 'HooshSEO\\' . $relative;
			$slug     = strtolower( substr( strrchr( '\\' . $relative, '\\' ), 1 ) );

			// A single broken module must never take the whole site down with it.
			try {
				if ( ! class_exists( $class ) ) {
					continue;
				}
				if ( ! method_exists( $class, 'instance' ) || ! is_callable( array( $class, 'instance' ) ) ) {
					$this->broken[] = $class;
					continue;
				}

				$object = call_user_func( array( $class, 'instance' ) );
			} catch ( \Throwable $e ) { // phpcs:ignore PHPCompatibility
				$this->broken[] = $class;
				$this->log_boot_error( $class, $e );
				continue;
			}

			if ( ! is_object( $object ) ) {
				continue;
			}

			$this->services[ $slug ] = $object;

			if ( 'settings' === $slug ) {
				$this->settings = $object;
			}
		}

		// Settings is a hard dependency for almost everything else; stop early
		// with a readable notice instead of a fatal further down the road.
		if ( null === $this->settings ) {
			add_action( 'admin_notices', array( $this, 'fatal_notice' ) );
			return;
		}

		// Front-end behaviour: redirects, 404 logging, click tracking, AI briefs.
		if ( ! is_admin() ) {
			foreach ( array( 'redirects', 'notfound', 'links', 'geo', 'images', 'speed', 'woo', 'breadcrumbs' ) as $slug ) {
				$service = $this->get( $slug );
				if ( $service && method_exists( $service, 'bootstrap_front' ) ) {
					$service->bootstrap_front();
				}
			}
		}

		add_action( 'init', array( $this, 'register_hooks' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 20 );
	}

	/**
	 * Resolve a module instance.
	 *
	 * @param string $slug Lowercase short class name.
	 * @return object|null
	 */
	public function get( $slug ) {
		return isset( $this->services[ $slug ] ) ? $this->services[ $slug ] : null;
	}

	/**
	 * All services.
	 *
	 * @return array
	 */
	public function services() {
		return $this->services;
	}

	/**
	 * Modules that failed to boot.
	 *
	 * @return array
	 */
	public function broken() {
		return $this->broken;
	}

	/**
	 * Remember a boot failure so it is visible instead of silent.
	 *
	 * @param string     $class Class that failed.
	 * @param \Throwable $e     The error.
	 */
	protected function log_boot_error( $class, $e ) {
		$log = (array) get_option( 'hoosh_seo_boot_errors', array() );
		$log[ $class ] = array(
			'message' => $e->getMessage(),
			'where'   => str_replace( HOOSH_SEO_DIR, '', $e->getFile() ) . ':' . $e->getLine(),
			'time'    => current_time( 'mysql' ),
		);
		// Keep only the last ten distinct failures.
		update_option( 'hoosh_seo_boot_errors', array_slice( $log, -10, null, true ), false );
	}

	/**
	 * Last resort notice when the settings module itself could not load.
	 */
	public function fatal_notice() {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'هوش‌سئو:', 'hoosh-seo' ),
			esc_html__( 'ماژول تنظیمات بارگذاری نشد؛ افزونه غیرفعال است تا سایت شما سالم بماند. جزئیات در فایل خطای وردپرس ثبت شده است.', 'hoosh-seo' )
		);
	}

	/**
	 * Global hooks.
	 */
	public function register_hooks() {
		add_filter( 'wp_resource_hints', array( $this, 'resource_hints' ), 10, 2 );
		add_filter( 'plugin_action_links_' . HOOSH_SEO_BASENAME, array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'wp_ajax_hoosh_seo_dismiss_notice', array( $this, 'dismiss_notice' ) );
	}

	/**
	 * Prefetch for the font CDN when the remote font option is enabled.
	 *
	 * @param array  $hints Hints.
	 * @param string $relation Relation.
	 * @return array
	 */
	public function resource_hints( $hints, $relation ) {
		if ( 'preconnect' === $relation && $this->settings && $this->settings->get( 'appearance.remote_font', true ) ) {
			$hints[] = array(
				'href'        => 'https://cdn.jsdelivr.net',
				'crossorigin' => 'anonymous',
			);
		}
		return $hints;
	}

	/**
	 * Run dbDelta when the plugin version changed.
	 */
	public function maybe_upgrade() {
		if ( get_option( 'hoosh_seo_version' ) !== HOOSH_SEO_VERSION ) {
			Database::install();
			update_option( 'hoosh_seo_version', HOOSH_SEO_VERSION );
		}
	}

	/**
	 * Plugin list action links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( App::studio_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'باز کردن استودیو', 'hoosh-seo' ) . '</a>'
		);
		$links[] = '<a href="' . esc_url( Admin::settings_url() ) . '">' . esc_html__( 'تنظیمات', 'hoosh-seo' ) . '</a>';
		return $links;
	}

	/**
	 * Plugin list row meta.
	 *
	 * @param array  $meta Meta.
	 * @param string $file File.
	 * @return array
	 */
	public function row_meta( $meta, $file ) {
		if ( HOOSH_SEO_BASENAME === $file ) {
			$meta[] = '<a href="' . esc_url( HOOSH_SEO_URL . 'docs/index.html' ) . '" target="_blank" rel="noopener">' . esc_html__( 'راهنمای فارسی', 'hoosh-seo' ) . '</a>';
		}
		return $meta;
	}

	/**
	 * Dismiss an admin notice (AJAX).
	 */
	public function dismiss_notice() {
		check_ajax_referer( 'hoosh_seo_notice', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}
		$id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		update_user_meta( get_current_user_id(), 'hoosh_notice_' . $id, 1 );
		wp_die();
	}
}
