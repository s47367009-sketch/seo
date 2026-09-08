<?php
/**
 * Deactivation handler.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Deactivator
 */
final class Deactivator {

	/**
	 * Run on plugin deactivation: drop cron, caches and rewrites.
	 */
	public static function run() {
		Cron::clear_all();

		foreach ( array( 'hoosh_seo_daily', 'hoosh_seo_hourly', 'hoosh_seo_weekly', 'hoosh_seo_batch', 'hoosh_seo_prune_audit', 'hoosh_seo_prune_ai_log' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		Helpers::cache_flush_all();
		delete_option( 'hoosh_running_job' );

		// Restore file permissions we may have tightened.
		$root = untrailingslashit( ABSPATH );
		if ( hoosh_seo()->settings->get( 'robots.override_file', false ) && file_exists( $root . '/robots.txt' ) ) {
			@chmod( $root . '/robots.txt', 0644 ); // phpcs:ignore
		}

		// Our rewrite rules must go, otherwise front-end routes 404 while inactive.
		flush_rewrite_rules();
	}
}
