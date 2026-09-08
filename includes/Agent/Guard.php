<?php
/**
 * Agent guardrails.
 *
 * An agent that edits a live site has to be boring about safety. Everything
 * that can stop the agent lives here, in one place, and the orchestrator asks
 * before every single action:
 *
 *   - money budget per run and per month
 *   - wall-clock budget per run
 *   - step budget per run
 *   - how many posts it may touch in a day
 *   - per-post cooldown so it never thrashes one URL
 *   - explicit approval gates for irreversible skills
 *   - a global kill switch an operator can flip at any time
 *
 * @package HooshSEO
 */

namespace HooshSEO\Agent;

use HooshSEO\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Guard
 */
final class Guard {

	/**
	 * Kill-switch option. When true nothing autonomous runs, ever.
	 *
	 * @return bool
	 */
	public static function halted() {
		return (bool) get_option( 'hoosh_agent_halted', false );
	}

	/**
	 * Flip the kill switch.
	 *
	 * @param bool $halt Halt.
	 */
	public static function set_halted( $halt ) {
		update_option( 'hoosh_agent_halted', (bool) $halt, false );
	}

	/**
	 * Is the agent allowed to run at all?
	 *
	 * @param array $run Run context.
	 * @return array {allowed:bool, why:string}
	 */
	public static function can_run( $run = array() ) {
		$settings = \hoosh_seo()->settings;

		if ( ! $settings ) {
			return self::deny( 'settings-missing', __( 'تنظیمات افزونه در دسترس نیست.', 'hoosh-seo' ) );
		}
		if ( self::halted() ) {
			return self::deny( 'halted', __( 'ایجنت با کلید اضطراری متوقف شده است.', 'hoosh-seo' ) );
		}
		if ( ! $settings->on( 'agent.enabled' ) ) {
			return self::deny( 'disabled', __( 'ایجنت خاموش است.', 'hoosh-seo' ) );
		}
		if ( 'off' === $settings->get( 'agent.autonomy', 'supervised' ) ) {
			return self::deny( 'autonomy-off', __( 'سطح خودمختاری روی «خاموش» است.', 'hoosh-seo' ) );
		}
		if ( ! \HooshSEO\AI\Gateway::is_configured() ) {
			return self::deny( 'ai-missing', __( 'هوش مصنوعی پیکربندی نشده است.', 'hoosh-seo' ) );
		}
		if ( ! Database::exists( 'agent_runs' ) ) {
			return self::deny( 'db-missing', __( 'جدول‌های ایجنت ساخته نشده‌اند؛ افزونه را یک‌بار غیرفعال و دوباره فعال کنید.', 'hoosh-seo' ) );
		}
		// Never run a second instance at once.
		$lock = (int) get_option( 'hoosh_agent_lock', 0 );
		if ( $lock && $lock > time() - 30 * MINUTE_IN_SECONDS ) {
			return self::deny( 'locked', __( 'یک اجرای دیگر در جریان است.', 'hoosh-seo' ) );
		}
		return array( 'allowed' => true, 'code' => 'ok', 'why' => '' );
	}

	/**
	 * May this one skill run right now?
	 *
	 * @param string $skill Skill id.
	 * @param array  $ctx   Run context.
	 * @return array {allowed:bool, why:string}
	 */
	public static function can_skill( $skill, $ctx = array() ) {
		$settings = \hoosh_seo()->settings;

		if ( ! isset( $ctx['allowed_skills'][ $skill ] ) || ! $ctx['allowed_skills'][ $skill ] ) {
			return self::deny( 'skill-off', sprintf( /* translators: %s: skill */ __( 'مهارت «%s» خاموش است.', 'hoosh-seo' ), $skill ) );
		}
		if ( ! empty( $ctx['budget_exceeded'] ) ) {
			return self::deny( 'budget', __( 'سقف هزینهٔ این اجرا پر شده است.', 'hoosh-seo' ) );
		}
		if ( ! empty( $ctx['steps_done'] ) && $ctx['steps_done'] >= (int) $ctx['max_steps'] ) {
			return self::deny( 'steps', __( 'سقف گام‌های این اجرا پر شده است.', 'hoosh-seo' ) );
		}
		if ( ! empty( $ctx['started_at'] ) && ( time() - (int) $ctx['started_at'] ) >= (int) $ctx['max_seconds'] ) {
			return self::deny( 'time', __( 'زمان این اجرا تمام شده است.', 'hoosh-seo' ) );
		}

		// Daily cap on how many posts the agent may write to.
		$cap = (int) $settings->get( 'agent.max_post_edits', 30 );
		if ( $cap > 0 && ! empty( $ctx['posts_touched'] ) && count( (array) $ctx['posts_touched'] ) >= $cap ) {
			return self::deny( 'edit-cap', sprintf( /* translators: %d: cap */ __( 'سقف ویرایش روزانه (%d صفحه) پر شده است.', 'hoosh-seo' ), $cap ) );
		}

		return array( 'allowed' => true, 'code' => 'ok', 'why' => '' );
	}

	/**
	 * Does this action need a human before it is applied?
	 *
	 * Publishing, deleting and redirect rules are never silent.
	 *
	 * @param string $skill Skill id.
	 * @param array  $args  Action arguments.
	 * @return bool
	 */
	public static function needs_review( $skill, $args = array() ) {
		$settings = \hoosh_seo()->settings;
		$mode     = (string) $settings->get( 'agent.autonomy', 'supervised' );

		if ( 'supervised' === $mode ) {
			return true;
		}
		if ( 'autonomous' !== $mode ) {
			// 'assisted' reviews the risky ones only.
			return in_array( $skill, array( 'write_article', 'redirect_404', 'publish' ), true );
		}
		// Even fully autonomous, publishing obeys its own setting.
		if ( 'write_article' === $skill && 'review' === $settings->get( 'agent.publish', 'draft' ) ) {
			return true;
		}
		if ( 'autonomous' === $mode && ! empty( $args['destructive'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Has this post been touched too recently?
	 *
	 * Stops the agent rewriting the same page every night, which is exactly how
	 * an automated tool loses rankings.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function in_cooldown( $post_id ) {
		$hours = (int) \hoosh_seo()->settings->get( 'agent.cooldown_hours', 24 );
		if ( $hours <= 0 ) {
			return false;
		}
		$at = (int) get_post_meta( (int) $post_id, '_hs_agent_at', true );
		return $at && $at > time() - ( $hours * HOUR_IN_SECONDS );
	}

	/**
	 * Stamp a post so the cooldown applies.
	 *
	 * @param int    $post_id Post.
	 * @param string $skill   Skill that touched it.
	 */
	public static function touch_post( $post_id, $skill = '' ) {
		update_post_meta( (int) $post_id, '_hs_agent_at', time() );
		if ( $skill ) {
			update_post_meta( (int) $post_id, '_hs_agent_skill', sanitize_key( $skill ) );
		}
	}

	/**
	 * Would spending this much push the run over its money cap?
	 *
	 * @param float $cost Estimated USD.
	 * @param array $ctx  Run context.
	 * @return bool
	 */
	public static function cost_allows( $cost, $ctx = array() ) {
		$cap = (float) ( isset( $ctx['budget_usd'] ) ? $ctx['budget_usd'] : 0 );
		if ( $cap <= 0 ) {
			return true;
		}
		$spent = (float) ( isset( $ctx['spent_usd'] ) ? $ctx['spent_usd'] : 0 );
		return ( $spent + (float) $cost ) <= $cap;
	}

	/**
	 * Take the run lock.
	 *
	 * @param int $run_id Run.
	 * @return bool
	 */
	public static function lock( $run_id ) {
		$current = (int) get_option( 'hoosh_agent_lock', 0 );
		if ( $current && $current > time() - 30 * MINUTE_IN_SECONDS ) {
			return false;
		}
		update_option( 'hoosh_agent_lock', time(), false );
		update_option( 'hoosh_agent_lock_run', (int) $run_id, false );
		return true;
	}

	/**
	 * Release the run lock.
	 */
	public static function unlock() {
		delete_option( 'hoosh_agent_lock' );
		delete_option( 'hoosh_agent_lock_run' );
	}

	/**
	 * Build the context object the orchestrator threads through a run.
	 *
	 * @param int    $run_id Run.
	 * @param string $mode   Run mode.
	 * @return array
	 */
	public static function context( $run_id = 0, $mode = 'auto' ) {
		$settings = \hoosh_seo()->settings;
		return array(
			'run_id'         => (int) $run_id,
			'mode'           => (string) $mode,
			'started_at'     => time(),
			'max_steps'      => max( 1, (int) $settings->get( 'agent.max_steps', 25 ) ),
			'max_seconds'    => max( 30, (int) $settings->get( 'agent.max_minutes', 8 ) * 60 ),
			'budget_usd'     => (float) $settings->get( 'agent.budget_usd', 2.0 ),
			'parallel'       => max( 1, min( 12, (int) $settings->get( 'agent.parallel', 4 ) ) ),
			'dry_run'        => (bool) $settings->get( 'agent.dry_run', false ),
			'steps_done'     => 0,
			'spent_usd'      => 0.0,
			'posts_touched'  => array(),
			'budget_exceeded' => false,
			'allowed_skills' => (array) $settings->get( 'agent.skills', array() ),
			'autonomy'       => (string) $settings->get( 'agent.autonomy', 'supervised' ),
		);
	}

	/**
	 * Uniform denial shape.
	 *
	 * @param string $code Machine code.
	 * @param string $why  Human reason (Persian).
	 * @return array
	 */
	protected static function deny( $code, $why ) {
		return array( 'allowed' => false, 'code' => (string) $code, 'why' => (string) $why );
	}
}
