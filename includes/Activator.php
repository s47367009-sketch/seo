<?php
/**
 * Activation / deactivation routines.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Activator
 */
class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function run() {
		self::check_requirements();
		Database::install();
		self::seed_options();
		self::ensure_upload_dir();
		self::schedule_events();
		self::register_routes( true );
		self::detect_conflicts();

		set_transient( 'hoosh_seo_activated', 1, 40 );
		update_option( 'hoosh_seo_activated_at', time(), false );
	}

	/**
	 * Bail out with a readable message when the host is too old.
	 */
	protected static function check_requirements() {
		if ( ! function_exists( 'mb_strlen' ) && ! extension_loaded( 'mbstring' ) ) {
			deactivate_plugins( HOOSH_SEO_BASENAME );
			wp_die(
				esc_html__( 'هوش‌سئو برای فعال‌سازی به افزونه PHP «mbstring» نیاز دارد. از میزبان خود بخواهید آن را فعال کند.', 'hoosh-seo' ),
				esc_html__( 'افزونه فعال نشد', 'hoosh-seo' ),
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Seed defaults but never overwrite an existing configuration.
	 */
	protected static function seed_options() {
		if ( ! get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		if ( false === get_option( 'hoosh_seo_indexnow_key' ) ) {
			update_option( 'hoosh_seo_indexnow_key', wp_generate_password( 32, false, false ), false );
		}
		if ( ! get_option( 'hoosh_seo_cron_key' ) ) {
			update_option( 'hoosh_seo_cron_key', wp_generate_password( 24, false, false ), false );
		}
		// Mirror the site's indexing preference so the app shows the real state.
		add_option( 'hoosh_seo_network_ready', 1, '', false );
	}

	/**
	 * Private folder used for exports / imports.
	 */
	protected static function ensure_upload_dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'hoosh-seo';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_readable( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
			@file_put_contents( $dir . '/.htaccess', "Order allow,deny\nDeny from all\n<FilesMatch \"\\.(csv|json|txt)$\">\nRequire all granted\n</FilesMatch>\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/**
	 * Exports folder path.
	 *
	 * @return string
	 */
	public static function export_dir() {
		return self::ensure_upload_dir();
	}

	/**
	 * Register cron events.
	 */
	protected static function schedule_events() {
		Cron::schedule_all();
	}

	/**
	 * Add rewrite rules and flush once.
	 *
	 * @param bool $flush Whether to flush.
	 */
	public static function register_routes( $flush = false ) {
		App::register_routes();
		if ( $flush ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Notice the user about conflicting SEO plugins (Pars SEO parity).
	 */
	protected static function detect_conflicts() {
		$active = array(
			'wordpress-seo/wp-seo.php'              => 'Yoast SEO',
			'google-sitemap-generator/sitemap.php'   => 'Google XML Sitemaps',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'seopress/seopress.php'                  => 'SEOPress',
			'sitemap-network/sitemap.php'            => 'Sitemap',
		);
		$found  = array();
		foreach ( $active as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				$found[] = $label;
			}
		}
		if ( $found ) {
			update_option( 'hoosh_seo_conflicts', $found, false );
		}
	}
}
