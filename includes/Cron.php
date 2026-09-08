<?php
/**
 * Cron driver: WP-Cron, server cron (via a keyed endpoint) or WP-CLI.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cron
 */
class Cron {

	const DAILY   = 'hoosh_seo_daily';
	const HOURLY  = 'hoosh_seo_hourly';
	const WEEKLY  = 'hoosh_seo_weekly';
	const BATCH   = 'hoosh_seo_batch';

	/**
	 * Custom interval list.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function intervals( $schedules ) {
		$schedules['hoosh_hourly'] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'هر ساعت (هوش‌سئو)', 'hoosh-seo' ),
		);
		$schedules['hoosh_6h']     = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'هر ۶ ساعت (هوش‌سئو)', 'hoosh-seo' ),
		);
		$schedules['hoosh_10min']  = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'هر ۱۰ دقیقه (صفوفه هوش‌سئو)', 'hoosh-seo' ),
		);
		return $schedules;
	}

	/**
	 * Schedule everything the plugin needs.
	 */
	public static function schedule_all() {
		if ( 'wp' !== hoosh_seo()->settings->get( 'automation.cron_driver', 'wp' ) ) {
			return;
		}
		self::schedule( self::DAILY, 'daily' );
		self::schedule( self::HOURLY, 'hoosh_hourly' );
		self::schedule( self::BATCH, 'hoosh_10min' );
		self::schedule( self::WEEKLY, 'weekly' );
	}

	/**
	 * Schedule one event if not already scheduled.
	 *
	 * @param string $hook     Hook.
	 * @param string $recurrence Recurrence.
	 */
	public static function schedule( $hook, $recurrence ) {
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
		}
	}

	/**
	 * Remove all plugin events.
	 */
	public static function clear_all() {
		foreach ( array( self::DAILY, self::HOURLY, self::WEEKLY, self::BATCH ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	/**
	 * Singleton (also wires the hooks).
	 *
	 * @var Cron|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Cron
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
	 * Register hooks.
	 */
	public function bootstrap() {
		add_filter( 'cron_schedules', array( __CLASS__, 'intervals' ) );

		add_action( self::DAILY, array( __CLASS__, 'run_daily' ) );
		add_action( self::HOURLY, array( __CLASS__, 'run_hourly' ) );
		add_action( self::WEEKLY, array( __CLASS__, 'run_weekly' ) );
		add_action( self::BATCH, array( __CLASS__, 'run_batch' ) );
		add_action( 'init', array( __CLASS__, 'maybe_external_trigger' ) );

		// Keep the queue moving on sites where real cron is unreliable: when an
		// admin loads the Studio we drain a small slice instead of waiting 10min.
		add_action( 'admin_init', array( __CLASS__, 'soft_drain' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * Re-arm events if they were dropped by a cleanup plugin.
	 */
	public static function ensure_scheduled() {
		if ( 'wp' === hoosh_seo()->settings->get( 'automation.cron_driver', 'wp' ) ) {
			self::schedule_all();
		}
	}

	/**
	 * Schedule a near-immediate batch when work is queued.
	 */
	public static function soft_drain() {
		if ( ! self::queued() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::BATCH ) || wp_next_scheduled( self::BATCH ) > time() + 900 ) {
			wp_schedule_single_event( time() + 5, self::BATCH );
		}
	}

	/**
	 * Anything waiting in the queue?
	 *
	 * @return bool
	 */
	public static function queued() {
		if ( ! Database::exists( 'ai_jobs' ) ) {
			return false;
		}
		global $wpdb;
		$table = Database::table( 'ai_jobs' );
		return (bool) $wpdb->get_var( "SELECT id FROM {$table} WHERE status IN ('queued','running') LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Allow a server cron / systemd timer to trigger the daily run:
	 * curl -s "https://site/​?hoosh_cron=KEY&task=daily"
	 */
	public static function maybe_external_trigger() {
		if ( ! isset( $_GET['hoosh_cron'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$key  = sanitize_text_field( wp_unslash( $_GET['hoosh_cron'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$saved = get_option( 'hoosh_seo_cron_key', '' );
		if ( ! $saved || ! hash_equals( (string) $saved, (string) $key ) ) {
			wp_die( 'Invalid cron key', '', array( 'response' => 403 ) );
		}
		$task = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : 'daily'; // phpcs:ignore WordPress.Security.NonceVerification
		set_time_limit( 300 );
		switch ( $task ) {
			case 'hourly':
				self::run_hourly();
				break;
			case 'weekly':
				self::run_weekly();
				break;
			case 'batch':
				self::run_batch();
				break;
			default:
				self::run_daily();
		}
		wp_die( 'ok' );
	}

	/**
	 * Every 10 minutes: drain the AI / audit queues.
	 */
	public static function run_batch() {
		$gateway = hoosh_seo()->get( 'gateway' );
		if ( $gateway ) {
			$gateway->process_queue( (int) hoosh_seo()->settings->get( 'ai.batch_size', 5 ) );
		}
		$automation = hoosh_seo()->get( 'automation' );
		if ( $automation ) {
			$automation->drain_batches();
		}
	}

	/**
	 * Hourly: refresh near-expiry analytics, retry failed jobs.
	 */
	public static function run_hourly() {
		$gateway = hoosh_seo()->get( 'gateway' );
		if ( $gateway ) {
			$gateway->retry_failed();
		}
		$audit = hoosh_seo()->get( 'audit' );
		if ( $audit ) {
			$audit->resume_interrupted();
		}
	}

	/**
	 * Daily automation run.
	 */
	public static function run_daily() {
		$automation = hoosh_seo()->get( 'automation' );
		if ( $automation ) {
			$automation->run( 'daily' );
		}
	}

	/**
	 * Weekly: sitemap ping, broken links, retention cleanup, report.
	 */
	public static function run_weekly() {
		$automation = hoosh_seo()->get( 'automation' );
		if ( $automation ) {
			$automation->run( 'weekly' );
		}
	}

	/**
	 * Next run timestamps per hook (for the Tools screen).
	 *
	 * @return array
	 */
	public static function status() {
		$out = array();
		foreach ( array( self::DAILY, self::HOURLY, self::WEEKLY, self::BATCH ) as $hook ) {
			$next   = wp_next_scheduled( $hook );
			$driver = hoosh_seo()->settings->get( 'automation.cron_driver', 'wp' );
			$out[ $hook ] = array(
				'hook'    => $hook,
				'driver'  => $driver,
				'next'    => $next ? (int) $next : 0,
				'next_fa' => $next ? self::human_delta( $next - time() ) : __( 'زمان‌بندی نشده', 'hoosh-seo' ),
			);
		}
		return $out;
	}

	/**
	 * "۳ ساعت دیگر" style delta.
	 *
	 * @param int $seconds Seconds from now.
	 * @return string
	 */
	public static function human_delta( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds < 60 ) {
			return 'کمتر از یک دقیقه دیگر';
		}
		if ( $seconds < HOUR_IN_SECONDS ) {
			return Helpers::digits( (int) round( $seconds / MINUTE_IN_SECONDS ) ) . ' دقیقه دیگر';
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			return Helpers::digits( (int) round( $seconds / HOUR_IN_SECONDS ) ) . ' ساعت دیگر';
		}
		return Helpers::digits( (int) round( $seconds / DAY_IN_SECONDS ) ) . ' روز دیگر';
	}
}
