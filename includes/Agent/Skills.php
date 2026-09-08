<?php
/**
 * The agent's skill registry.
 *
 * A skill is the smallest thing the agent can decide to do. Every skill
 * declares its own risk, cost and reversibility up front, so the planner can
 * reason about consequences and the guard can refuse things before they
 * happen rather than after.
 *
 * Handlers live in Skill and share one signature:
 *
 *     Skill::x( array $target, array $ctx ) : array
 *
 * returning { ok, title, reason, result, cost_usd, post_id, needs_review }.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Class Skills
 */
final class Skills {

	/**
	 * Skill catalog.
	 *
	 * `risk` drives the default review gate, `cost` is 1 when the skill spends
	 * AI tokens, and `undo` says whether the changelog can roll it back.
	 *
	 * @return array
	 */
	public static function catalog() {
		return array(
			'audit_fix'     => array(
				'label'    => __( 'رفع ایرادهای فنی', 'hoosh-seo' ),
				'group'    => 'technical',
				'risk'     => 'low',
				'cost'     => 0,
				'undo'     => true,
				'about'    => __( 'ایندکس‌بودن، کنونیکال و تگ‌های robots صفحات مشکل‌دار را درست می‌کند.', 'hoosh-seo' ),
			),
			'meta_fill'     => array(
				'label'    => __( 'تکمیل عنوان و توضیحات متا', 'hoosh-seo' ),
				'group'    => 'content',
				'risk'     => 'medium',
				'cost'     => 1,
				'undo'     => true,
				'about'    => __( 'برای صفحه‌هایی که عنوان یا توضیحات متا ندارند، متن بهینه می‌نویسد.', 'hoosh-seo' ),
			),
			'internal_link' => array(
				'label'    => __( 'لینک‌سازی داخلی', 'hoosh-seo' ),
				'group'    => 'content',
				'risk'     => 'medium',
				'cost'     => 0,
				'undo'     => true,
				'about'    => __( 'به صفحه‌های یتیم و کم‌لینک، از نوشته‌های مرتبط لینک می‌دهد.', 'hoosh-seo' ),
			),
			'alt_fill'      => array(
				'label'    => __( 'متن جایگزین تصاویر', 'hoosh-seo' ),
				'group'    => 'content',
				'risk'     => 'low',
				'cost'     => 1,
				'undo'     => true,
				'about'    => __( 'alt خالی تصاویر را با توصیف مرتبط پر می‌کند.', 'hoosh-seo' ),
			),
			'schema_fill'   => array(
				'label'    => __( 'تکمیل اسکیما', 'hoosh-seo' ),
				'group'    => 'technical',
				'risk'     => 'low',
				'cost'     => 1,
				'undo'     => true,
				'about'    => __( 'برای محتواهای بدون نشانه‌گذاری، اسکیمای مناسب می‌سازد.', 'hoosh-seo' ),
			),
			'redirect_404'  => array(
				'label'    => __( 'ریدایرکت خطاهای ۴۰۴', 'hoosh-seo' ),
				'group'    => 'technical',
				'risk'     => 'high',
				'cost'     => 0,
				'undo'     => true,
				'about'    => __( 'پرتکرارترین ۴۰۴ها را به نزدیک‌ترین صفحهٔ زنده ۳۰۱ می‌کند.', 'hoosh-seo' ),
			),
			'index_submit'  => array(
				'label'    => __( 'ثبت در ایندکس', 'hoosh-seo' ),
				'group'    => 'technical',
				'risk'     => 'low',
				'cost'     => 0,
				'undo'     => false,
				'about'    => __( 'نشانی‌های تازه یا تازه‌ویرایش‌شده را به IndexNow و گوگل اعلام می‌کند.', 'hoosh-seo' ),
			),
			'optimize_post' => array(
				'label'    => __( 'بهینه‌سازی نوشتهٔ ضعیف', 'hoosh-seo' ),
				'group'    => 'content',
				'risk'     => 'high',
				'cost'     => 1,
				'undo'     => true,
				'about'    => __( 'نوشته‌های کم‌امتیاز را بازنویسی و ساختار‌بندی می‌کند.', 'hoosh-seo' ),
			),
			'content_gap'   => array(
				'label'    => __( 'کشف فرصت محتوا', 'hoosh-seo' ),
				'group'    => 'strategy',
				'risk'     => 'low',
				'cost'     => 1,
				'undo'     => true,
				'about'    => __( 'از دادهٔ سرچ کنسول و کلمات کلیدی، موضوع‌های بی‌رقیب را پیدا می‌کند.', 'hoosh-seo' ),
			),
			'write_article' => array(
				'label'    => __( 'نگارش مقالهٔ تازه', 'hoosh-seo' ),
				'group'    => 'strategy',
				'risk'     => 'high',
				'cost'     => 1,
				'undo'     => true,
				'about'    => __( 'از فرصت پیدا‌شده، مقالهٔ کامل با ساختار، لینک داخلی و اسکیما می‌سازد.', 'hoosh-seo' ),
			),
		);
	}

	/**
	 * One skill's metadata.
	 *
	 * @param string $id Skill id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$catalog = self::catalog();
		return isset( $catalog[ $id ] ) ? $catalog[ $id ] : null;
	}

	/**
	 * Is this a real skill?
	 *
	 * @param string $id Skill id.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Does the handler exist and is it callable?
	 *
	 * @param string $id Skill id.
	 * @return bool
	 */
	public static function runnable( $id ) {
		$id = sanitize_key( (string) $id );
		return self::exists( $id ) && method_exists( 'HooshSEO\Agent\Skill', $id );
	}

	/**
	 * Skills the site currently allows the agent to use.
	 *
	 * @return array
	 */
	public static function enabled() {
		$settings = \hoosh_seo()->settings;
		$on       = (array) $settings->get( 'agent.skills', array() );
		$out      = array();
		foreach ( self::catalog() as $id => $meta ) {
			if ( ! empty( $on[ $id ] ) && self::runnable( $id ) ) {
				$out[ $id ] = $meta;
			}
		}
		return $out;
	}

	/**
	 * Run one skill against one target, with full bookkeeping.
	 *
	 * This is the only entry point the orchestrator uses, so budget checks,
	 * the audit ledger and the undo stamp can never be forgotten by a caller.
	 *
	 * @param string $id     Skill id.
	 * @param array  $target What to act on.
	 * @param array  $ctx    Run context (by reference — it accumulates spend).
	 * @return array Step record.
	 */
	public static function run( $id, $target = array(), &$ctx = array() ) {
		$start = microtime( true );
		$id    = sanitize_key( (string) $id );
		$meta  = self::get( $id );

		if ( ! $meta ) {
			return self::record( $id, $target, $ctx, array( 'ok' => false, 'error' => 'unknown-skill' ), $start );
		}
		if ( ! self::runnable( $id ) ) {
			return self::record( $id, $target, $ctx, array( 'ok' => false, 'error' => 'handler-missing' ), $start );
		}

		$gate = Guard::can_skill( $id, $ctx );
		if ( ! $gate['allowed'] ) {
			return self::record( $id, $target, $ctx, array( 'ok' => false, 'skipped' => true, 'error' => $gate['code'], 'message' => $gate['why'] ), $start );
		}

		$handler = array( 'HooshSEO\Agent\Skill', $id );

		try {
			$result = call_user_func( $handler, (array) $target, (array) $ctx );
		} catch ( \Throwable $e ) { // phpcs:ignore PHPCompatibility
			$result = array(
				'ok'    => false,
				'error' => 'exception',
				'message' => $e->getMessage(),
				'where' => str_replace( HOOSH_SEO_DIR, '', $e->getFile() ) . ':' . $e->getLine(),
			);
		}

		if ( ! is_array( $result ) ) {
			$result = array( 'ok' => (bool) $result );
		}

		return self::record( $id, $target, $ctx, $result, $start );
	}

	/**
	 * Normalise a handler result, charge the budget and write the ledger row.
	 *
	 * @param string $id     Skill.
	 * @param array  $target Target.
	 * @param array  $ctx    Context (by reference).
	 * @param array  $result Raw handler output.
	 * @param float  $start  microtime.
	 * @return array
	 */
	protected static function record( $id, $target, &$ctx, $result, $start ) {
		$meta   = self::get( $id );
		$ms     = (int) round( ( microtime( true ) - $start ) * 1000 );
		$ok     = ! empty( $result['ok'] );
		$skipped = ! empty( $result['skipped'] );

		$cost = isset( $result['cost_usd'] ) ? (float) $result['cost_usd'] : 0.0;
		if ( ! isset( $ctx['spent_usd'] ) ) {
			$ctx['spent_usd'] = 0.0;
		}
		$ctx['spent_usd'] += $cost;
		if ( isset( $ctx['budget_usd'] ) && $ctx['budget_usd'] > 0 && $ctx['spent_usd'] >= $ctx['budget_usd'] ) {
			$ctx['budget_exceeded'] = true;
		}

		$needs_review = isset( $result['needs_review'] )
			? (bool) $result['needs_review']
			: Guard::needs_review( $id, (array) $target );

		$ctx['steps_done'] = isset( $ctx['steps_done'] ) ? (int) $ctx['steps_done'] + 1 : 1;

		if ( $ok && ! empty( $result['post_id'] ) ) {
			$ctx['posts_touched'][ (int) $result['post_id'] ] = true;
			Guard::touch_post( (int) $result['post_id'], $id );
		}

		$status = $ok ? 'done' : ( $skipped ? 'skipped' : 'failed' );
		if ( $ok && $needs_review ) {
			$status = 'review';
		}

		$step = array(
			'run_id'       => isset( $ctx['run_id'] ) ? (int) $ctx['run_id'] : 0,
			'seq'          => isset( $ctx['steps_done'] ) ? (int) $ctx['steps_done'] : 0,
			'skill'        => $id,
			'title'        => isset( $result['title'] ) ? $result['title'] : ( $meta ? $meta['label'] : $id ),
			'reason'       => isset( $result['reason'] ) ? $result['reason'] : array(),
			'payload'      => array( 'target' => $target ),
			'result'       => self::trim_result( $result ),
			'status'       => $status,
			'needs_review' => $needs_review ? 1 : 0,
			'post_id'      => isset( $result['post_id'] ) ? (int) $result['post_id'] : ( isset( $target['post_id'] ) ? (int) $target['post_id'] : 0 ),
			'cost_usd'     => $cost,
			'ms'           => $ms,
			'error'        => isset( $result['message'] ) ? $result['message'] : ( isset( $result['error'] ) ? $result['error'] : '' ),
		);

		$step['id'] = Memory::log_step( $step );

		/**
		 * Fires after any agent skill completes.
		 *
		 * @param string $id     Skill id.
		 * @param array  $result Handler result.
		 * @param array  $ctx    Run context.
		 */
		do_action( 'hoosh_seo_agent_step', $id, $result, $ctx );

		return $step;
	}

	/**
	 * Keep ledger rows small — a generated article does not belong in a log.
	 *
	 * @param array $result Raw result.
	 * @return array
	 */
	protected static function trim_result( $result ) {
		$out = array();
		foreach ( (array) $result as $key => $value ) {
			if ( in_array( $key, array( 'draft', 'content', 'html', 'brief' ), true ) ) {
				$out[ $key ] = is_string( $value ) ? mb_substr( $value, 0, 400 ) . '…' : $value;
				continue;
			}
			if ( is_string( $value ) && mb_strlen( $value ) > 600 ) {
				$value = mb_substr( $value, 0, 600 ) . '…';
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
