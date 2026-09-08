<?php
/**
 * The autonomous SEO agent.
 *
 * This is the entry point and the public surface. The pieces:
 *
 *   Insight      measures the site and finds what is worth doing
 *   Planner      turns that into an ordered, targeted plan
 *   Skills/Skill the actions themselves
 *   Guard        refuses anything unsafe or over budget
 *   Memory       the audit ledger and what past runs learned
 *   Pipeline     produces articles when there is nothing to fix
 *   Orchestrator runs the whole loop and verifies the result
 *
 * Nothing here runs unless the site turns it on. It is off by default, starts
 * in supervised mode, and every change is written to the ledger with an undo
 * stamp, because an agent that quietly edits a live site is a liability.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Class Agent
 */
final class Agent {

	/**
	 * Cron hook.
	 */
	const HOOK = 'hoosh_seo_agent_run';

	/**
	 * Singleton.
	 *
	 * @var Agent|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Agent
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
		add_action( self::HOOK, array( __CLASS__, 'cron_run' ) );
		add_action( 'admin_post_hoosh_seo_agent_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'admin_post_hoosh_seo_agent_halt', array( __CLASS__, 'ajax_halt' ) );
		add_action( 'hoosh_seo_settings_saved', array( __CLASS__, 'on_settings_saved' ) );
	}

	/**
	 * Keep the schedule in step with the settings.
	 *
	 * @param array $tree New settings tree.
	 */
	public static function on_settings_saved( $tree = array() ) {
		unset( $tree );
		self::sync_schedule();
	}

	/**
	 * Add or drop the cron event to match the chosen cadence.
	 */
	public static function sync_schedule() {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings ) {
			return;
		}
		if ( 'wp' !== $settings->get( 'automation.cron_driver', 'wp' ) ) {
			return;
		}

		$cadence = (string) $settings->get( 'agent.schedule', 'daily' );
		$map     = array(
			'hourly' => 'hoosh_hourly',
			'daily'  => 'daily',
			'weekly' => 'weekly',
		);

		if ( ! $settings->on( 'agent.enabled' ) || ! isset( $map[ $cadence ] ) ) {
			$next = wp_next_scheduled( self::HOOK );
			if ( $next ) {
				wp_unschedule_event( $next, self::HOOK );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$hour = max( 0, min( 23, (int) $settings->get( 'agent.hour', 3 ) ) );
			$at   = 'hourly' === $cadence ? time() + HOUR_IN_SECONDS : strtotime( 'today ' . str_pad( (string) $hour, 2, '0', STR_PAD_LEFT ) . ':15' );
			if ( ! $at || $at < time() ) {
				$at = time() + DAY_IN_SECONDS;
			}
			wp_schedule_event( $at, $map[ $cadence ], self::HOOK );
		}
	}

	/**
	 * Scheduled entry point.
	 *
	 * Runs late at night by default, which is when a slow agent costs the
	 * least and when a mistake is most likely to be noticed by morning.
	 */
	public static function cron_run() {
		$report = Orchestrator::run( array( 'triggered_by' => 'cron', 'mode' => 'auto' ) );
		if ( ! empty( $report['run_id'] ) ) {
			update_option( 'hoosh_seo_agent_last', array(
				'run_id'  => (int) $report['run_id'],
				'at'      => time(),
				'summary' => isset( $report['summary'] ) ? (string) $report['summary'] : '',
				'ok'      => ! empty( $report['ok'] ),
			), false );
		}
	}

	/**
	 * Manual run from the admin bar / Mission Control.
	 */
	public static function ajax_run() {
		if ( ! self::authorize( 'run' ) ) {
			return;
		}

		$dry = ! empty( $_REQUEST['dry_run'] ); // phpcs:ignore WordPress.Security.NonceVerification
		self::json(
			Orchestrator::run(
				array(
					'triggered_by' => 'manual',
					'mode'         => 'manual',
					'dry_run'      => $dry ? true : null,
				)
			)
		);
	}

	/**
	 * Flip the kill switch.
	 */
	public static function ajax_halt() {
		if ( ! self::authorize( 'halt' ) ) {
			return;
		}
		$halt = ! empty( $_REQUEST['halt'] ); // phpcs:ignore WordPress.Security.NonceVerification
		Guard::set_halted( $halt );
		if ( $halt ) {
			$next = wp_next_scheduled( self::HOOK );
			if ( $next ) {
				wp_unschedule_event( $next, self::HOOK );
			}
		} else {
			self::sync_schedule();
		}
		self::json( array( 'ok' => true, 'halted' => $halt ) );
	}

	/**
	 * Everything Mission Control needs in one request.
	 *
	 * @return array
	 */
	public static function status() {
		$settings = \hoosh_seo()->settings;
		$gate     = Guard::can_run();
		$ready    = \HooshSEO\Database::exists( 'agent_runs' );
		$totals   = $ready ? Memory::totals() : array();
		$last     = (array) get_option( 'hoosh_seo_agent_last', array() );

		return array(
			'enabled'    => (bool) $settings->on( 'agent.enabled' ),
			'halted'     => Guard::halted(),
			'autonomy'   => (string) $settings->get( 'agent.autonomy', 'supervised' ),
			'schedule'   => (string) $settings->get( 'agent.schedule', 'daily' ),
			'can_run'    => $gate,
			'next_run'   => wp_next_scheduled( self::HOOK ),
			'ai_ready'   => \HooshSEO\AI\Gateway::is_configured(),
			'driver'     => \HooshSEO\AI\Gateway::active_driver(),
			'skills'     => self::skill_list(),
			'totals'     => $totals,
			'last'       => $last,
			'limits'     => array(
				'max_steps'  => (int) $settings->get( 'agent.max_steps', 25 ),
				'max_minutes' => (int) $settings->get( 'agent.max_minutes', 8 ),
				'budget_usd' => (float) $settings->get( 'agent.budget_usd', 2.0 ),
				'parallel'   => (int) $settings->get( 'agent.parallel', 4 ),
				'publish'    => (string) $settings->get( 'agent.publish', 'draft' ),
				'dry_run'    => (bool) $settings->get( 'agent.dry_run', false ),
			),
			'runs'       => $ready ? Memory::runs( 12 ) : array(),
			'articles'   => class_exists( 'HooshSEO\Agent\Pipeline' ) ? Pipeline::ledger( '', 12 ) : array(),
			'autonomy_levels' => self::autonomy_levels(),
		);
	}

	/**
	 * Skill list with on/off state, for the settings screen.
	 *
	 * @return array
	 */
	protected static function skill_list() {
		$on  = (array) \hoosh_seo()->settings->get( 'agent.skills', array() );
		$out = array();
		foreach ( Skills::catalog() as $id => $meta ) {
			$out[] = array(
				'id'       => $id,
				'label'    => $meta['label'],
				'group'    => $meta['group'],
				'risk'     => $meta['risk'],
				'cost'     => (int) $meta['cost'],
				'about'    => $meta['about'],
				'enabled'  => ! empty( $on[ $id ] ),
				'runnable' => Skills::runnable( $id ),
			);
		}
		return $out;
	}

	/**
	 * The four autonomy levels, explained.
	 *
	 * @return array
	 */
	protected static function autonomy_levels() {
		return array(
			array(
				'id'    => 'off',
				'label' => __( 'خاموش', 'hoosh-seo' ),
				'about' => __( 'ایجنت هیچ کاری نمی‌کند.', 'hoosh-seo' ),
			),
			array(
				'id'    => 'supervised',
				'label' => __( 'زیر نظر (پیشنهادی)', 'hoosh-seo' ),
				'about' => __( 'همهٔ تغییرات برای تأیید شما نگه داشته می‌شوند.', 'hoosh-seo' ),
			),
			array(
				'id'    => 'assisted',
				'label' => __( 'نیمه‌خودکار', 'hoosh-seo' ),
				'about' => __( 'کارهای کم‌خطر خودکار، کارهای پرخطر با تأیید شما.', 'hoosh-seo' ),
			),
			array(
				'id'    => 'autonomous',
				'label' => __( 'کاملاً خودکار', 'hoosh-seo' ),
				'about' => __( 'خودش تصمیم می‌گیرد و اجرا می‌کند؛ فقط گزارش می‌دهد.', 'hoosh-seo' ),
			),
		);
	}

	/**
	 * Steps of one run, for the detail drawer.
	 *
	 * @param int $run_id Run.
	 * @return array
	 */
	public static function run_detail( $run_id ) {
		$run = Memory::run( (int) $run_id );
		if ( ! $run ) {
			return array( 'ok' => false, 'message' => __( 'این اجرا پیدا نشد.', 'hoosh-seo' ) );
		}
		return array(
			'ok'    => true,
			'run'   => $run,
			'steps' => Memory::steps( (int) $run_id, 300 ),
		);
	}

	/**
	 * Undo one recorded step where the skill supports it.
	 *
	 * @param int $step_id Step.
	 * @return array
	 */
	public static function revert( $step_id ) {
		global $wpdb;
		if ( ! \HooshSEO\Database::exists( 'agent_steps' ) ) {
			return array( 'ok' => false, 'message' => __( 'جدول گام‌ها وجود ندارد.', 'hoosh-seo' ) );
		}
		$step = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . \HooshSEO\Database::table( 'agent_steps' ) . ' WHERE id = %d', (int) $step_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! $step ) {
			return array( 'ok' => false, 'message' => __( 'گام پیدا نشد.', 'hoosh-seo' ) );
		}

		$meta = Skills::get( (string) $step['skill'] );
		if ( ! $meta || empty( $meta['undo'] ) ) {
			return array( 'ok' => false, 'message' => __( 'این کار قابل بازگشت خودکار نیست.', 'hoosh-seo' ) );
		}

		$result = json_decode( (string) $step['result'], true );
		$before = is_array( $result ) && isset( $result['before'] ) ? (array) $result['before'] : array();
		$post_id = (int) $step['post_id'];

		if ( $post_id && $before ) {
			\HooshSEO\Meta::save( $post_id, $before );
		}

		$wpdb->update( \HooshSEO\Database::table( 'agent_steps' ), array( 'reverted' => 1 ), array( 'id' => (int) $step_id ) );

		return array( 'ok' => true, 'reverted' => (int) $step_id, 'restored' => array_keys( $before ) );
	}

	/**
	 * Capability check for the admin-post endpoints.
	 *
	 * @param string $action Action name.
	 * @return bool
	 */
	protected static function authorize( $action ) {
		$nonce = isset( $_REQUEST['_hoosh_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_hoosh_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! wp_verify_nonce( $nonce, 'hoosh_seo_rest' ) ) {
			self::json( array( 'ok' => false, 'message' => __( 'نشانهٔ امنیتی نامعتبر است.', 'hoosh-seo' ) ), 403 );
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::json( array( 'ok' => false, 'message' => __( 'اجازهٔ این کار را ندارید.', 'hoosh-seo' ) ), 403 );
			return false;
		}
		unset( $action );
		return true;
	}

	/**
	 * Emit a JSON response and stop.
	 *
	 * @param mixed $data Payload.
	 * @param int   $code HTTP status.
	 */
	protected static function json( $data, $code = 200 ) {
		if ( ! headers_sent() ) {
			status_header( $code );
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
