<?php
/**
 * The orchestrator — the loop that makes the agent autonomous.
 *
 *   observe   measure the site as it actually is
 *   plan      decide what to do about it
 *   act       run the skills, one audited step at a time
 *   verify    measure again and record whether it helped
 *   remember  store what worked so the next run starts smarter
 *
 * The verify pass is not decoration. Without it the agent has no way to know
 * that "I changed 30 pages" and "I improved 30 pages" are different claims,
 * and it will happily keep doing the first one.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Agent;

use HooshSEO\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Class Orchestrator
 */
final class Orchestrator {

	/**
	 * Run one full agent cycle.
	 *
	 * @param array $args {goal, triggered_by, mode, dry_run}.
	 * @return array Full run report.
	 */
	public static function run( $args = array() ) {
		$args = wp_parse_args(
			(array) $args,
			array(
				'goal'         => '',
				'triggered_by' => 'manual',
				'mode'         => 'auto',
				'dry_run'      => null,
				'max_steps'    => 0,
			)
		);

		$gate = Guard::can_run();
		if ( ! $gate['allowed'] ) {
			return array(
				'ok'      => false,
				'code'    => $gate['code'],
				'message' => $gate['why'],
			);
		}

		$ctx = Guard::context( 0, (string) $args['mode'] );
		if ( null !== $args['dry_run'] ) {
			$ctx['dry_run'] = (bool) $args['dry_run'];
		}
		if ( $args['max_steps'] ) {
			$ctx['max_steps'] = max( 1, (int) $args['max_steps'] );
		}

		$goal = $args['goal'] ? (string) $args['goal'] : __( 'بهبود خودکار سئوی سایت', 'hoosh-seo' );
		$ctx['run_id'] = Memory::start_run(
			array(
				'goal'         => $goal,
				'mode'         => (string) $args['mode'],
				'triggered_by' => sanitize_key( $args['triggered_by'] ),
			)
		);

		if ( ! Guard::lock( $ctx['run_id'] ) ) {
			Memory::finish_run( $ctx['run_id'], 'halted', __( 'اجرای دیگری در جریان بود.', 'hoosh-seo' ) );
			return array( 'ok' => false, 'code' => 'locked', 'message' => __( 'یک اجرای دیگر در جریان است.', 'hoosh-seo' ) );
		}

		$report = array(
			'ok'       => true,
			'run_id'   => $ctx['run_id'],
			'goal'     => $goal,
			'dry_run'  => (bool) $ctx['dry_run'],
			'phases'   => array(),
			'steps'    => array(),
		);

		try {
			// --- Observe -------------------------------------------------
			Memory::update_run( $ctx['run_id'], array( 'phase' => 'observe' ) );
			$before = Insight::diagnose();
			$report['phases']['observe'] = array(
				'score'    => $before['score'],
				'findings' => count( $before['findings'] ),
				'sources'  => $before['sources'],
				'stats'    => $before['stats'],
			);

			// --- Plan ----------------------------------------------------
			Memory::update_run( $ctx['run_id'], array( 'phase' => 'plan' ) );
			$plan = Planner::plan( $before, $ctx );
			$report['plan'] = array(
				'phase'     => $plan['phase'],
				'count'     => $plan['count'],
				'rationale' => $plan['rationale'],
			);
			Memory::update_run(
				$ctx['run_id'],
				array(
					'phase'       => 'act',
					'plan'        => wp_json_encode( $plan, JSON_UNESCAPED_UNICODE ),
					'steps_total' => $plan['count'],
					'score_before' => $before['score'],
				)
			);

			if ( ! $plan['count'] ) {
				Memory::finish_run( $ctx['run_id'], 'done', __( 'کاری برای انجام نبود.', 'hoosh-seo' ) );
				$report['message'] = __( 'ایرادی پیدا نشد و فرصت محتوایی تازه‌ای هم نبود.', 'hoosh-seo' );
				Guard::unlock();
				return $report;
			}

			// --- Act -----------------------------------------------------
			if ( $ctx['dry_run'] ) {
				$report['steps'] = self::dry_run( $plan );
			} else {
				$report['steps'] = self::execute( $plan, $ctx );
			}

			$tally = self::tally( $report['steps'] );
			$report['tally'] = $tally;
			$report['cost_usd'] = round( (float) $ctx['spent_usd'], 6 );

			Memory::update_run(
				$ctx['run_id'],
				array(
					'steps_done' => $tally['total'],
					'wins'       => $tally['done'],
					'skips'      => $tally['skipped'],
					'fails'      => $tally['failed'],
					'cost_usd'   => (float) $ctx['spent_usd'],
				)
			);

			// --- Verify --------------------------------------------------
			if ( ! $ctx['dry_run'] && $tally['done'] > 0 ) {
				Memory::update_run( $ctx['run_id'], array( 'phase' => 'verify' ) );
				$after = Insight::diagnose();
				$delta = round( (float) $after['score'] - (float) $before['score'], 1 );

				$report['verify'] = array(
					'score_before' => (float) $before['score'],
					'score_after'  => (float) $after['score'],
					'delta'        => $delta,
					'findings'     => count( $after['findings'] ),
					'findings_before' => count( $before['findings'] ),
				);
				Memory::update_run( $ctx['run_id'], array( 'score_after' => (float) $after['score'] ) );

				// --- Remember --------------------------------------------
				self::remember_outcome( $ctx, $report, $delta );
			}

			$summary = self::summarise( $report );
			Memory::finish_run( $ctx['run_id'], $tally['failed'] && ! $tally['done'] ? 'failed' : 'done', $summary );
			$report['summary'] = $summary;

			if ( ! $ctx['dry_run'] ) {
				self::notify( $report );
				Memory::prune();
			}
		} catch ( \Throwable $e ) { // phpcs:ignore PHPCompatibility
			$report['ok']      = false;
			$report['message'] = $e->getMessage();
			$report['where']   = str_replace( HOOSH_SEO_DIR, '', $e->getFile() ) . ':' . $e->getLine();
			Memory::finish_run( $ctx['run_id'], 'failed', $report['message'] . ' @ ' . $report['where'] );
		}

		Guard::unlock();

		/**
		 * Fires when an agent run finishes.
		 *
		 * @param array $report Run report.
		 */
		do_action( 'hoosh_seo_agent_run_finished', $report );

		return $report;
	}

	/**
	 * Execute a plan step by step.
	 *
	 * @param array $plan Plan.
	 * @param array $ctx  Context (by reference).
	 * @return array Step records.
	 */
	protected static function execute( $plan, &$ctx ) {
		$steps   = isset( $plan['steps'] ) ? (array) $plan['steps'] : array();
		$records = array();
		$picked_404 = array();

		foreach ( $steps as $step ) {
			$skill  = isset( $step['skill'] ) ? (string) $step['skill'] : '';
			$target = isset( $step['target'] ) ? (array) $step['target'] : array();

			if ( ! $skill ) {
				continue;
			}

			// Stop as soon as any hard budget trips, rather than starting a
			// step we cannot finish.
			if ( ! empty( $ctx['budget_exceeded'] ) ) {
				$records[] = array(
					'skill'  => $skill,
					'status' => 'skipped',
					'error'  => __( 'سقف هزینه پر شد.', 'hoosh-seo' ),
					'title'  => __( 'گام اجرا نشد (سقف هزینه)', 'hoosh-seo' ),
				);
				continue;
			}
			if ( (int) $ctx['steps_done'] >= (int) $ctx['max_steps'] ) {
				break;
			}
			if ( ( time() - (int) $ctx['started_at'] ) >= (int) $ctx['max_seconds'] ) {
				$records[] = array(
					'skill'  => $skill,
					'status' => 'skipped',
					'error'  => __( 'زمان اجرا تمام شد.', 'hoosh-seo' ),
					'title'  => __( 'گام اجرا نشد (اتمام زمان)', 'hoosh-seo' ),
				);
				continue;
			}

			// 404 redirects are picked inside the skill; make sure one run
			// does not resolve the same row twice.
			if ( 'redirect_404' === $skill && isset( $target['pick'] ) ) {
				$target = array( 'skip' => array_values( $picked_404 ) );
			}

			$record          = Skills::run( $skill, $target, $ctx );
			$record['why']   = isset( $step['why'] ) ? $step['why'] : '';
			$records[]       = $record;

			if ( 'redirect_404' === $skill && ! empty( $record['post_id'] ) ) {
				$picked_404[] = (int) $record['post_id'];
			}
		}

		return $records;
	}

	/**
	 * Describe what a run would do, without touching anything.
	 *
	 * @param array $plan Plan.
	 * @return array
	 */
	protected static function dry_run( $plan ) {
		$out = array();
		foreach ( (array) ( isset( $plan['steps'] ) ? $plan['steps'] : array() ) as $i => $step ) {
			$meta = Skills::get( $step['skill'] );
			$out[] = array(
				'seq'     => $i + 1,
				'skill'   => $step['skill'],
				'title'   => $meta ? $meta['label'] : $step['skill'],
				'status'  => 'planned',
				'target'  => isset( $step['target'] ) ? $step['target'] : array(),
				'why'     => isset( $step['why'] ) ? $step['why'] : '',
				'review'  => Guard::needs_review( $step['skill'], isset( $step['target'] ) ? (array) $step['target'] : array() ),
			);
		}
		return $out;
	}

	/**
	 * Count outcomes.
	 *
	 * @param array $steps Steps.
	 * @return array
	 */
	protected static function tally( $steps ) {
		$out = array( 'total' => 0, 'done' => 0, 'skipped' => 0, 'failed' => 0, 'review' => 0 );
		foreach ( (array) $steps as $step ) {
			$out['total']++;
			$status = isset( $step['status'] ) ? (string) $step['status'] : 'failed';
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ]++;
			} elseif ( 'planned' === $status ) {
				$out['total']--;
			}
		}
		return $out;
	}

	/**
	 * Store what this run learned.
	 *
	 * @param array $ctx    Context.
	 * @param array $report Report.
	 * @param float $delta  Score delta.
	 */
	protected static function remember_outcome( $ctx, $report, $delta ) {
		if ( ! \hoosh_seo()->settings->on( 'agent.memory' ) ) {
			return;
		}

		Memory::remember( 'site', 'last-run', 'outcome', array(
			'at'    => time(),
			'delta' => (float) $delta,
			'phase' => isset( $report['plan']['phase'] ) ? $report['plan']['phase'] : '',
			'wins'  => isset( $report['tally']['done'] ) ? (int) $report['tally']['done'] : 0,
		) );

		// Which skills actually moved the score? That ordering is what makes
		// run 20 better than run 1.
		$by_skill = array();
		foreach ( (array) ( isset( $report['steps'] ) ? $report['steps'] : array() ) as $step ) {
			if ( empty( $step['skill'] ) || 'done' !== ( isset( $step['status'] ) ? $step['status'] : '' ) ) {
				continue;
			}
			$by_skill[ $step['skill'] ] = isset( $by_skill[ $step['skill'] ] ) ? $by_skill[ $step['skill'] ] + 1 : 1;
		}
		foreach ( $by_skill as $skill => $count ) {
			Memory::remember( 'site', 'skill:' . $skill, 'effectiveness', array(
				'runs'  => 1,
				'wins'  => (int) $count,
				'delta' => (float) $delta / max( 1, count( $by_skill ) ),
			) );
		}
	}

	/**
	 * One-line human summary of a run.
	 *
	 * @param array $report Report.
	 * @return string
	 */
	protected static function summarise( $report ) {
		$tally = isset( $report['tally'] ) ? (array) $report['tally'] : array();
		$parts = array();

		if ( ! empty( $tally['done'] ) ) {
			$parts[] = sprintf( /* translators: %d: count */ __( '%d کار انجام شد', 'hoosh-seo' ), (int) $tally['done'] );
		}
		if ( ! empty( $tally['review'] ) ) {
			$parts[] = sprintf( /* translators: %d: count */ __( '%d مورد در انتظار تأیید شما', 'hoosh-seo' ), (int) $tally['review'] );
		}
		if ( ! empty( $tally['failed'] ) ) {
			$parts[] = sprintf( /* translators: %d: count */ __( '%d مورد ناموفق', 'hoosh-seo' ), (int) $tally['failed'] );
		}
		if ( isset( $report['verify']['delta'] ) ) {
			$delta   = (float) $report['verify']['delta'];
			$parts[] = sprintf( /* translators: %s: delta */ __( 'امتیاز سلامت %s', 'hoosh-seo' ), ( $delta >= 0 ? '+' : '' ) . $delta );
		}
		if ( ! empty( $report['cost_usd'] ) ) {
			$parts[] = sprintf( /* translators: %s: cost */ __( 'هزینهٔ هوش مصنوعی %s دلار', 'hoosh-seo' ), number_format_i18n( (float) $report['cost_usd'], 4 ) );
		}

		return $parts ? implode( ' — ', $parts ) : __( 'تغییری لازم نبود.', 'hoosh-seo' );
	}

	/**
	 * Email the site owner what the agent did.
	 *
	 * @param array $report Report.
	 */
	protected static function notify( $report ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'agent.notify' ) ) {
			return;
		}
		$to = (string) $settings->get( 'agent.notify_email', '' );
		if ( ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}
		if ( ! is_email( $to ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: summary */
			__( '[%1$s] گزارش اِجنت سئو: %2$s', 'hoosh-seo' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			isset( $report['summary'] ) ? $report['summary'] : ''
		);

		$lines   = array( isset( $report['summary'] ) ? $report['summary'] : '', '' );
		foreach ( array_slice( (array) ( isset( $report['steps'] ) ? $report['steps'] : array() ), 0, 25 ) as $step ) {
			$lines[] = sprintf(
				'• [%s] %s',
				isset( $step['status'] ) ? $step['status'] : '?',
				isset( $step['title'] ) ? $step['title'] : ( isset( $step['skill'] ) ? $step['skill'] : '' )
			);
		}
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=hoosh-seo#/agent' );

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
