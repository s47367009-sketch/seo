<?php
/**
 * Autopilot: scheduled maintenance jobs that fix things in batches, keep an
 * undo trail and (optionally) require a human review before applying.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\AI\Gateway;
use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;
use HooshSEO\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Class Automation
 */
final class Automation {

	const BATCH_OPTION = 'hoosh_automation_batches';

	/**
	 * Singleton.
	 *
	 * @var Automation|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Automation
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
		add_action( 'admin_post_hoosh_automation_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_hoosh_seo_automation_run', array( __CLASS__, 'ajax_run_job' ) );
	}

	/**
	 * Job catalog.
	 *
	 * @return array
	 */
	public static function jobs() {
		$settings = \hoosh_seo()->settings;
		$enabled  = (array) $settings->get( 'automation.jobs', array() );

		$catalog = array(
			'meta_fill'    => array(
				'label'   => __( 'ساخت متای ناقص', 'hoosh-seo' ),
				'desc'    => __( 'برای نوشته‌هایی که عنوان/توضیحات سئو ندارند، با هوش مصنوعی متا می‌سازد.', 'hoosh-seo' ),
				'scope'   => array( 'daily', 'manual' ),
				'ai'      => true,
				'writes'  => __( 'فیلدهای _hs_title و _hs_description', 'hoosh-seo' ),
				'undo'    => true,
			),
			'summary'      => array(
				'label'   => __( 'خلاصه و پاسخ آماده', 'hoosh-seo' ),
				'desc'    => __( 'خلاصه صفحه برای llms.txt و باکس پاسخ می‌سازد.', 'hoosh-seo' ),
				'scope'   => array( 'daily', 'manual' ),
				'ai'      => true,
				'writes'  => __( 'جدول pages.ai_summary', 'hoosh-seo' ),
				'undo'    => true,
			),
			'alt_fill'     => array(
				'label'   => __( 'متن جایگزین تصاویر', 'hoosh-seo' ),
				'desc'    => __( 'برای تصاویر بی alt؛ اول از نام فایل، بعد هوش مصنوعی.', 'hoosh-seo' ),
				'scope'   => array( 'daily', 'manual' ),
				'ai'      => false,
				'writes'  => __( 'postmeta تصویر (_wp_attachment_image_alt)', 'hoosh-seo' ),
				'undo'    => true,
			),
			'indexnow'     => array(
				'label'   => 'IndexNow',
				'desc'    => __( 'آخرین تغییرات را به بینگ/یاندکس/ناور ارسال می‌کند.', 'hoosh-seo' ),
				'scope'   => array( 'daily', 'manual' ),
				'ai'      => false,
				'writes'  => __( 'هیچ؛ فقط ارسال بیرونی', 'hoosh-seo' ),
				'undo'    => false,
			),
			'rank_sync'    => array(
				'label'   => __( 'سنجی جایگاه‌ها', 'hoosh-seo' ),
				'desc'    => __( 'کلمات ردیابی‌شده را دوباره می‌سنجد و تاریخچه را پر می‌کند.', 'hoosh-seo' ),
				'scope'   => array( 'daily', 'manual' ),
				'ai'      => false,
				'writes'  => __( 'جدول‌های keywords و positions', 'hoosh-seo' ),
				'undo'    => false,
			),
			'sitemap_ping' => array(
				'label'   => __( 'پینگ نقشه سایت', 'hoosh-seo' ),
				'desc'    => __( 'به گوگل، بینگ، یاندکس و پینترست خبر تغییر نقشه می‌دهد.', 'hoosh-seo' ),
				'scope'   => array( 'weekly', 'manual' ),
				'ai'      => false,
				'writes'  => __( 'هیچ', 'hoosh-seo' ),
				'undo'    => false,
			),
			'audit'        => array(
				'label'   => __( 'ممیزی کامل سایت', 'hoosh-seo' ),
				'desc'    => __( 'خزش داخلی + امتیازدهی به همه صفحات قابل‌ایندکس.', 'hoosh-seo' ),
				'scope'   => array( 'weekly', 'manual' ),
				'ai'      => false,
				'writes'  => __( 'جدول pages', 'hoosh-seo' ),
				'undo'    => false,
			),
			'links'        => array(
				'label'   => __( 'درج لینک داخلی', 'hoosh-seo' ),
				'desc'    => __( 'طبق قوانین لینک‌سازی، پیشنهادها را داخل متن می‌گذارد.', 'hoosh-seo' ),
				'scope'   => array( 'weekly', 'manual' ),
				'ai'      => false,
				'writes'  => __( 'post_content (با برچسب خودکار)', 'hoosh-seo' ),
				'undo'    => true,
			),
			'broken'       => array(
				'label'   => __( 'لینک‌های شکسته', 'hoosh-seo' ),
				'desc'    => __( 'نشکن‌ها را پیدا و برای هرکدام حدس مقصد می‌زند.', 'hoosh-seo' ),
				'scope'   => array( 'weekly', 'manual' ),
				'ai'      => false,
				'writes'  => __( 'جدول links + لاگ', 'hoosh-seo' ),
				'undo'    => false,
			),
			'optimize'     => array(
				'label'   => __( 'بهینه‌سازی محتوای ضعیف', 'hoosh-seo' ),
				'desc'    => __( 'صفحات با امتیاز پایین را در صف بازنویسی می‌گذارد (فقط در حالت اعمال خودکار).', 'hoosh-seo' ),
				'scope'   => array( 'weekly', 'manual' ),
				'ai'      => true,
				'writes'  => __( 'صف هوش مصنوعی', 'hoosh-seo' ),
				'undo'    => true,
			),
		);

		foreach ( $catalog as $key => &$job ) {
			$job['key']     = $key;
			$job['enabled'] = ! empty( $enabled[ $key ] );
			$job['state']   = $job['enabled'] ? 'on' : 'off';
			$last           = get_option( 'hoosh_job_' . $key );
			$job['last_run'] = $last ? (string) $last : '';
			$job['last']     = $last ? Helpers::time_ago_fa( (int) $last ) : __( 'هرگز', 'hoosh-seo' );
			$job['mode']     = $settings->on( 'automation.enabled', true )
				? ( 'apply' === $settings->get( 'automation.mode', 'suggest' ) && $job['undo'] ? 'auto' : 'suggest' )
				: 'paused';
		}
		unset( $job );

		return $catalog;
	}

	/**
	 * Admin-post entry (whole pass).
	 */
	public static function ajax_run() {
		check_admin_referer( 'hoosh_seo_tools' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازه ندارید.', 'hoosh-seo' ) );
		}
		$scope = isset( $_REQUEST['scope'] ) ? sanitize_key( wp_unslash( $_REQUEST['scope'] ) ) : 'manual'; // phpcs:ignore
		self::run( $scope );
		wp_safe_redirect( add_query_arg( 'hs_msg', 'automation-run', \HooshSEO\App::studio_url( 'autopilot' ) ) );
		exit;
	}

	/**
	 * AJAX: run a single job.
	 */
	public static function ajax_run_job() {
		check_ajax_referer( 'hoosh_seo_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'اجازه ندارید.', 'hoosh-seo' ) ), 403 );
		}
		$job  = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
		$limit = isset( $_POST['limit'] ) ? absint( wp_unslash( $_POST['limit'] ) ) : 0;
		$out  = self::run_job( $job, array( 'limit' => $limit, 'scope' => 'manual' ) );
		if ( empty( $out['ok'] ) ) {
			wp_send_json_error( $out );
		}
		wp_send_json_success( $out );
	}

	/**
	 * Run a scheduled scope.
	 *
	 * @param string $scope daily|weekly|manual.
	 * @param array  $args  Overrides.
	 * @return array
	 */
	public static function run( $scope = 'daily', $args = array() ) {
		$settings = \hoosh_seo()->settings;
		$scope    = in_array( $scope, array( 'daily', 'weekly', 'manual', 'all' ), true ) ? $scope : 'daily';

		if ( ! $settings->on( 'automation.enabled', true ) && 'manual' !== $scope ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'اتوپایلوت خاموش است.', 'hoosh-seo' ) );
		}

		$hour  = (int) $settings->get( 'automation.hour', 3 );
		$lock  = get_transient( 'hoosh_automation_lock' );
		if ( $lock && 'manual' !== $scope ) {
			return array( 'ok' => false, 'running' => true, 'message' => __( 'اجرای قبلی هنوز تمام نشده.', 'hoosh-seo' ) );
		}
		set_transient( 'hoosh_automation_lock', time(), 15 * MINUTE_IN_SECONDS );

		if ( 'daily' === $scope && $hour > 0 && (int) gmdate( 'G' ) < $hour && ! $settings->on( 'advanced.debug', false ) ) {
			// Not yet time-of-day; keep it cheap.
			$wait = true;
		} else {
			$wait = false;
		}
		if ( $wait ) {
			delete_transient( 'hoosh_automation_lock' );
			return array( 'ok' => true, 'deferred' => true, 'message' => sprintf( /* translators: %d hour */ __( 'اجرا به ساعت %d موکول شد.', 'hoosh-seo' ), $hour ) );
		}

		$batch  = self::new_batch();
		$jobs   = self::jobs();
		$ran    = array();
		$results = array();
		$changed = 0;

		foreach ( $jobs as $key => $job ) {
			if ( ! $job['enabled'] ) {
				continue;
			}
			if ( 'all' !== $scope && ! in_array( $scope, (array) $job['scope'], true ) ) {
				continue;
			}
			$out = self::run_job( $key, array_merge( (array) $args, array( 'batch' => $batch, 'scope' => $scope ) ) );
			$ran[] = $key;
			$results[ $key ] = $out;
			$changed += (int) ( $out['changed'] ?? 0 );
			update_option( 'hoosh_job_' . $key, time(), false );
		}

		delete_transient( 'hoosh_automation_lock' );

		self::remember(
			$batch,
			array(
				'scope'   => $scope,
				'jobs'    => $ran,
				'changed' => $changed,
				'at'      => time(),
				'user'    => (int) get_current_user_id(),
			)
		);

		$summary = array(
			'ok'      => true,
			'batch'   => $batch,
			'scope'   => $scope,
			'jobs'    => $ran,
			'changed' => $changed,
			'results' => $results,
			'undo'    => $settings->on( 'automation.undo_enabled', true ) && $changed,
			'message' => $ran
				? sprintf( /* translators: 1: jobs, 2: changed */ __( '%1$d کار اجرا شد و %2$d تغییر ایجاد کرد.', 'hoosh-seo' ), count( $ran ), $changed )
				: __( 'هیچ کاری فعال نبود؛ از فهرست کارها روشن کنید.', 'hoosh-seo' ),
		);

		if ( $settings->on( 'automation.notify', true ) && $changed ) {
			self::notify( $summary );
		}
		if ( 'weekly' === $scope && $settings->get( 'reports.schedule', 'weekly' ) && $settings->on( 'reports.enabled', true ) ) {
			self::maybe_report();
		}

		Audit::log(
			'automation',
			'run',
			array(
				'batch_id' => $batch,
				'label'     => sprintf( /* translators: 1: scope, 2: count */ __( 'اجرای %1$s: %2$d تغییر', 'hoosh-seo' ), $scope, $changed ),
				'after'     => array( 'jobs' => $ran, 'changed' => $changed ),
			)
		);

		return $summary;
	}

	/**
	 * Run one job.
	 *
	 * @param string $job  Job key.
	 * @param array  $args Args.
	 * @return array
	 */
	public static function run_job( $job, $args = array() ) {
		$jobs = self::jobs();
		$job  = sanitize_key( (string) $job );
		if ( ! isset( $jobs[ $job ] ) ) {
			return array( 'ok' => false, 'message' => __( 'کار ناشناخته است.', 'hoosh-seo' ) );
		}
		$args = wp_parse_args(
			(array) $args,
			array(
				'limit' => (int) \hoosh_seo()->settings->get( 'automation.pages_per_run', 25 ),
				'batch' => self::new_batch(),
				'scope' => 'manual',
				'dry'   => false,
			)
		);

		$out = array(
			'ok'        => true,
			'job'       => $job,
			'label'     => (string) $jobs[ $job ]['label'],
			'processed' => 0,
			'changed'   => 0,
			'queued'    => 0,
			'batch'     => (string) $args['batch'],
		);

		switch ( $job ) {
			case 'meta_fill':
				$out = array_merge( $out, self::job_meta_fill( $args ) );
				break;
			case 'summary':
				$out = array_merge( $out, self::job_summary( $args ) );
				break;
			case 'alt_fill':
				$out = array_merge( $out, self::job_alt( $args ) );
				break;
			case 'indexnow':
				$out = array_merge( $out, self::job_indexnow( $args ) );
				break;
			case 'rank_sync':
				$out = array_merge( $out, self::job_rank( $args ) );
				break;
			case 'sitemap_ping':
				$out = array_merge( $out, self::job_ping( $args ) );
				break;
			case 'audit':
				$out = array_merge( $out, self::job_audit( $args ) );
				break;
			case 'links':
				$out = array_merge( $out, self::job_links( $args ) );
				break;
			case 'broken':
				$out = array_merge( $out, self::job_broken( $args ) );
				break;
			case 'optimize':
				$out = array_merge( $out, self::job_optimize( $args ) );
				break;
		}

		if ( ! empty( $out['changed'] ) && ! empty( $args['batch'] ) ) {
			self::remember( (string) $args['batch'], array( 'job' => $job, 'changed' => (int) $out['changed'] ) );
		}
		return $out;
	}

	/**
	 * Queue AI meta for pages missing it.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_meta_fill( $args ) {
		global $wpdb;
		$posts = (array) $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_hs_description'
				 WHERE p.post_status = 'publish' AND p.post_type IN (" . self::type_in() . ")
				   AND ( m.meta_value IS NULL OR m.meta_value = '' )
				 ORDER BY p.post_date DESC LIMIT %d",
				max( 1, (int) $args['limit'] )
			)
		);

		if ( ! $posts ) {
			return array( 'processed' => 0, 'changed' => 0, 'message' => __( 'نوشته‌ی بدون متا نمانده.', 'hoosh-seo' ) );
		}
		if ( ! Gateway::is_configured() ) {
			return array( 'processed' => count( $posts ), 'changed' => 0, 'blocked' => true, 'message' => __( 'هوش مصنوعی وصل نیست؛ صفی ساخته نشد.', 'hoosh-seo' ) );
		}

		$jobs = array();
		foreach ( $posts as $id ) {
			$jobs[] = array( 'task' => 'bulk_meta', 'post_id' => (int) $id, 'args' => array( 'apply' => 'auto' !== \hoosh_seo()->settings->get( 'automation.mode', 'suggest' ) ? false : true ) );
		}
		$queued = Gateway::queue_many( $jobs, array( 'batch_id' => (string) $args['batch'], 'created_by' => (int) get_current_user_id() ) );

		return array(
			'processed' => count( $posts ),
			'queued'    => (int) $queued,
			'changed'   => 0,
			'message'   => sprintf( /* translators: %d queued */ __( '%d نوشته در صف ساخت متا قرار گرفت.', 'hoosh-seo' ), $queued ),
		);
	}

	/**
	 * AI summaries for llms.txt/answer box.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_summary( $args ) {
		global $wpdb;
		$ids = array();
		if ( Database::exists( 'pages' ) ) {
			$ids = (array) $wpdb->get_col( // phpcs:ignore
				$wpdb->prepare(
					'SELECT post_id FROM ' . Database::table( 'pages' ) . ' WHERE ( ai_summary = "" OR ai_summary IS NULL ) AND type <> %s ORDER BY word_count DESC LIMIT %d',
					'archive',
					max( 1, (int) $args['limit'] )
				)
			);
		}
		if ( ! $ids ) {
			return array( 'processed' => 0, 'changed' => 0, 'message' => __( 'خلاصه‌ای برای ساخت نبود.', 'hoosh-seo' ) );
		}
		if ( ! Gateway::is_configured() ) {
			return array( 'processed' => count( $ids ), 'changed' => 0, 'blocked' => true, 'message' => __( 'برای خلاصه‌سازی، اتصال هوش مصنوعی لازم است.', 'hoosh-seo' ) );
		}

		$jobs = array();
		foreach ( $ids as $id ) {
			$jobs[] = array( 'task' => 'summary', 'post_id' => (int) $id, 'args' => array( 'apply' => true ) );
		}
		$queued = Gateway::queue_many( $jobs, array( 'batch_id' => (string) $args['batch'] ) );

		Geo::bust();

		return array(
			'processed' => count( $ids ),
			'queued'    => (int) $queued,
			'changed'   => (int) $queued,
			'message'   => sprintf( /* translators: %d queued */ __( '%d خلاصه در صف است.', 'hoosh-seo' ), $queued ),
		);
	}

	/**
	 * Fill image alts.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_alt( $args ) {
		$limit = max( 1, min( 200, (int) $args['limit'] ) );
		$out   = Images::backfill_alt( array( 'limit' => $limit, 'use_ai' => true ) );
		return array(
			'processed' => (int) ( $out['processed'] ?? 0 ),
			'changed'   => (int) ( $out['filled'] ?? 0 ),
			'message'   => (string) ( $out['message'] ?? '' ),
		);
	}

	/**
	 * Push fresh URLs to IndexNow.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_indexnow( $args ) {
		$urls = IndexManager::recent_urls( max( 1, min( 500, (int) $args['limit'] ) ) );
		if ( ! $urls ) {
			return array( 'processed' => 0, 'changed' => 0, 'message' => __( 'آدرس تازه‌ای نبود.', 'hoosh-seo' ) );
		}
		$out = IndexManager::indexnow( $urls );
		return array(
			'processed' => count( $urls ),
			'changed'   => ! empty( $out['ok'] ) ? count( $urls ) : 0,
			'sent'      => (array) ( $out['engines'] ?? array() ),
			'message'   => ! empty( $out['ok'] )
				? sprintf( /* translators: %d urls */ __( '%d آدرس به IndexNow داده شد.', 'hoosh-seo' ), count( $urls ) )
				: __( 'ارسال IndexNow ناموفق بود (کلید یا شبکه؟).', 'hoosh-seo' ),
		);
	}

	/**
	 * Rank check pass.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_rank( $args ) {
		$out = RankTracker::run( array( 'limit' => max( 1, (int) ( $args['limit'] / 2 ) ) ) );
		return array(
			'processed' => (int) ( $out['checked'] ?? 0 ),
			'changed'   => (int) ( $out['found'] ?? 0 ),
			'errors'    => (int) ( $out['errors'] ?? 0 ),
			'spend'     => (float) ( $out['spend'] ?? 0 ),
			'message'   => (string) ( $out['message'] ?? '' ),
		);
	}

	/**
	 * Ping search engines about the sitemap.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_ping( $args ) {
		unset( $args );
		$out = Sitemap::ping_all();
		return array(
			'processed' => count( (array) ( $out['pinged'] ?? array() ) ),
			'changed'   => 0,
			'pinged'    => (array) ( $out['pinged'] ?? array() ),
			'message'   => (string) ( $out['message'] ?? __( 'پینگ انجام شد.', 'hoosh-seo' ) ),
		);
	}

	/**
	 * Full audit pass.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_audit( $args ) {
		$out = Content::scan( array( 'limit' => max( 20, (int) $args['limit'] * 4 ) ) );
		return array(
			'processed' => (int) ( $out['done'] ?? 0 ),
			'changed'   => 0,
			'progress'  => array(
				'total'     => (int) ( $out['total'] ?? 0 ),
				'done'      => (int) ( $out['done'] ?? 0 ),
				'remaining' => (int) ( $out['remaining'] ?? 0 ),
				'percent'   => (int) ( $out['percent'] ?? 0 ),
				'running'   => (bool) ( $out['running'] ?? false ),
			),
			'message'   => (string) ( $out['message'] ?? '' ),
		);
	}

	/**
	 * Insert internal links from the rules.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_links( $args ) {
		$budget = min( (int) \hoosh_seo()->settings->get( 'automation.link_budget', 400 ), 400 );
		$out    = Links::auto_link(
			array(
				'limit'  => max( 1, (int) $args['limit'] ),
				'budget' => $budget,
				'apply'  => 'apply' === \hoosh_seo()->settings->get( 'automation.mode', 'suggest' ),
				'batch'  => (string) $args['batch'],
			)
		);
		return array(
			'processed' => (int) ( $out['scanned'] ?? 0 ),
			'changed'   => (int) ( $out['inserted'] ?? 0 ),
			'suggestions' => (int) ( $out['suggestions'] ?? 0 ),
			'message'   => (string) ( $out['message'] ?? '' ),
		);
	}

	/**
	 * Broken-link sweep.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_broken( $args ) {
		$out = Links::check_broken( array( 'limit' => max( 10, (int) $args['limit'] * 5 ) ) );
		return array(
			'processed' => (int) ( $out['checked'] ?? 0 ),
			'changed'   => (int) ( $out['broken'] ?? 0 ),
			'rows'      => array_slice( (array) ( $out['rows'] ?? array() ), 0, 20 ),
			'message'   => (string) ( $out['message'] ?? '' ),
		);
	}

	/**
	 * Queue rewrites for weak pages.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	protected static function job_optimize( $args ) {
		global $wpdb;
		$threshold = (int) \hoosh_seo()->settings->get( 'automation.analysis_threshold', 70 );
		if ( ! Database::exists( 'pages' ) ) {
			return array( 'ok' => false, 'message' => __( 'هنوز ممیزی‌ای اجرا نشده.', 'hoosh-seo' ) );
		}
		$ids = (array) $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare(
				'SELECT post_id FROM ' . Database::table( 'pages' ) . ' WHERE score > 0 AND score < %d ORDER BY score ASC LIMIT %d',
				$threshold,
				max( 1, (int) $args['limit'] )
			)
		);
		if ( ! $ids ) {
			return array( 'processed' => 0, 'changed' => 0, 'message' => __( 'صفحه ضعیفی زیر آستانه نیست.', 'hoosh-seo' ) );
		}
		if ( 'apply' !== \hoosh_seo()->settings->get( 'automation.mode', 'suggest' ) ) {
			return array(
				'processed' => count( $ids ),
				'changed'   => 0,
				'suggest'   => true,
				'rows'      => array_slice( $ids, 0, 20 ),
				'message'   => sprintf( /* translators: %d count */ __( '%d صفحه نیاز به بازنویسی دارد؛ در حالت پیشنهادی چیزی عوض نشد.', 'hoosh-seo' ), count( $ids ) ),
			);
		}
		if ( ! Gateway::is_configured() ) {
			return array( 'processed' => count( $ids ), 'changed' => 0, 'blocked' => true, 'message' => __( 'هوش مصنوعی وصل نیست.', 'hoosh-seo' ) );
		}
		$jobs = array();
		foreach ( $ids as $id ) {
			$jobs[] = array( 'task' => 'rewrite', 'post_id' => (int) $id, 'args' => array( 'apply' => false ) );
		}
		$queued = Gateway::queue_many( $jobs, array( 'batch_id' => (string) $args['batch'] ) );

		return array(
			'processed' => count( $ids ),
			'queued'    => (int) $queued,
			'changed'   => 0,
			'message'   => sprintf( /* translators: %d queued */ __( '%d صفحه برای بازنویسی در صف هستند (با بازبینی شما اعمال می‌شود).', 'hoosh-seo' ), $queued ),
		);
	}

	/**
	 * SQL fragment for managed post types.
	 *
	 * @return string
	 */
	protected static function type_in() {
		global $wpdb;
		$types = array_values( (array) Helpers::managed_post_types() );
		if ( ! $types ) {
			$types = array( 'post' );
		}
		$out = array();
		foreach ( $types as $type ) {
			$out[] = $wpdb->prepare( '%s', sanitize_key( $type ) );
		}
		return implode( ',', $out );
	}

	/**
	 * Drain queued AI work in small slices (called from the batch cron).
	 */
	public static function drain_batches() {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'automation.enabled', true ) ) {
			return;
		}
		$batch = max( 1, (int) $settings->get( 'automation.batch', 20 ) );
		Gateway::process_queue( $batch );
	}

	/**
	 * New batch id.
	 *
	 * @return string
	 */
	public static function new_batch() {
		return 'b' . gmdate( 'YmdHis' ) . wp_generate_password( 6, false, false );
	}

	/**
	 * Remember a batch for the undo UI.
	 *
	 * @param string $batch Batch id.
	 * @param array  $data  Data.
	 */
	protected static function remember( $batch, $data ) {
		$batches = (array) get_option( self::BATCH_OPTION, array() );
		if ( isset( $batches[ $batch ] ) ) {
			$batches[ $batch ] = array_merge(
				$batches[ $batch ],
				array(
					'changed' => (int) ( $batches[ $batch ]['changed'] ?? 0 ) + (int) ( $data['changed'] ?? 0 ),
					'at'      => time(),
				)
			);
			if ( ! empty( $data['job'] ) ) {
				$batches[ $batch ]['jobs'][] = $data['job'];
			}
		} else {
			$batches[ $batch ] = array_merge( array( 'jobs' => array(), 'changed' => 0 ), (array) $data );
		}
		if ( count( $batches ) > 60 ) {
			$batches = array_slice( $batches, -60, null, true );
		}
		update_option( self::BATCH_OPTION, $batches, false );
	}

	/**
	 * Batch list for the Studio.
	 *
	 * @return array
	 */
	public static function batches() {
		global $wpdb;
		$out = array();
		foreach ( (array) get_option( self::BATCH_OPTION, array() ) as $batch => $data ) {
			$counts = array( 'rows' => 0, 'reverted' => 0 );
			if ( Database::exists( 'changelog' ) ) {
				$counts['rows']     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::table( 'changelog' ) . ' WHERE batch_id = %s', $batch ) ); // phpcs:ignore
				$counts['reverted'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Database::table( 'changelog' ) . ' WHERE batch_id = %s AND reverted = 1', $batch ) ); // phpcs:ignore
			}
			$out[] = array(
				'batch'    => (string) $batch,
				'scope'    => (string) ( $data['scope'] ?? 'manual' ),
				'jobs'     => array_values( array_unique( (array) ( $data['jobs'] ?? array() ) ) ),
				'changed'  => (int) ( $data['changed'] ?? 0 ),
				'rows'     => (int) $counts['rows'],
				'reverted' => (int) $counts['reverted'],
				'at'       => isset( $data['at'] ) ? Helpers::time_ago_fa( (int) $data['at'] ) : '',
				'user'     => ! empty( $data['user'] ) ? (string) get_the_author_meta( 'display_name', (int) $data['user'] ) : __( 'کرون', 'hoosh-seo' ),
				'undoable' => $counts['rows'] > $counts['reverted'],
			);
		}
		return array_values( array_reverse( $out, true ) );
	}

	/**
	 * Undo a batch.
	 *
	 * @param string $batch Batch id.
	 * @return array
	 */
	public static function undo( $batch ) {
		global $wpdb;
		$batch = sanitize_text_field( (string) $batch );
		if ( '' === $batch || ! Database::exists( 'changelog' ) ) {
			return array( 'ok' => false, 'message' => __( 'چیزی برای بازگشت نیست.', 'hoosh-seo' ) );
		}
		if ( ! \hoosh_seo()->settings->on( 'automation.undo_enabled', true ) ) {
			return array( 'ok' => false, 'message' => __( 'بازگشت خودکار در تنظیمات غیرفعال است.', 'hoosh-seo' ) );
		}

		$rows = (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT id FROM ' . Database::table( 'changelog' ) . ' WHERE batch_id = %s AND reverted = 0 AND applied = 1 ORDER BY id DESC LIMIT 200',
				$batch
			),
			ARRAY_A
		);
		$undone = 0;
		$failed = 0;
		foreach ( $rows as $row ) {
			$out = Audit::revert( (int) $row['id'] );
			if ( ! empty( $out['ok'] ) ) {
				$undone++;
			} else {
				$failed++;
			}
		}

		Geo::bust();
		Helpers::cache_flush_all();

		return array(
			'ok'      => (bool) $undone,
			'undone'  => $undone,
			'failed'  => $failed,
			'message' => $undone
				? sprintf( /* translators: 1: undone, 2: failed */ __( '%1$d تغییر برگشت خورد (%2$d مورد قابل بازگشت نبود).', 'hoosh-seo' ), $undone, $failed )
				: __( 'این بسته تغییر قابل‌بازگشتی نداشت (فقط ارسال بیرونی بود).', 'hoosh-seo' ),
		);
	}

	/**
	 * Status panel.
	 *
	 * @return array
	 */
	public static function status() {
		$settings = \hoosh_seo()->settings;
		$next     = wp_next_scheduled( 'hoosh_seo_daily' );
		$batch    = wp_next_scheduled( 'hoosh_seo_batch' );

		return array(
			'enabled'    => (bool) $settings->get( 'automation.enabled', true ),
			'mode'       => (string) $settings->get( 'automation.mode', 'suggest' ),
			'pages_per_run' => (int) $settings->get( 'automation.pages_per_run', 25 ),
			'batch_size' => (int) $settings->get( 'automation.batch', 20 ),
			'schedule'   => (string) $settings->get( 'automation.schedule', 'daily' ),
			'hour'       => (int) $settings->get( 'automation.hour', 3),
			'cron_driver' => (string) $settings->get( 'automation.cron_driver', 'wp' ),
			'undo_enabled' => (bool) $settings->get( 'automation.undo_enabled', true ),
			'review_before_apply' => (bool) $settings->get( 'automation.review_before_apply', true ),
			'next_daily' => $next ? array( 'ts' => (int) $next, 'ago' => Helpers::time_ago_fa( (int) $next ) ) : null,
			'next_batch' => $batch ? array( 'ts' => (int) $batch, 'ago' => Helpers::time_ago_fa( (int) $batch ) ) : null,
			'locked'     => (bool) get_transient( 'hoosh_automation_lock' ),
			'jobs'       => array_values( self::jobs() ),
			'batches'    => self::batches(),
			'queue'      => Gateway::overview()['queue'] ?? array(),
			'notify'     => array(
				'on'   => (bool) $settings->get( 'automation.notify', true ),
				'email' => (string) ( $settings->get( 'automation.notify_email' ) ?: get_option( 'admin_email' ) ),
			),
			'cli_url'    => $settings->get( 'automation.cron_key' )
				? add_query_arg( array( 'hoosh_cron' => 'batch', 'key' => (string) $settings->get( 'automation.cron_key' ) ), home_url( '/' ) )
				: '',
		);
	}

	/**
	 * Email a short run report.
	 *
	 * @param array $summary Summary.
	 */
	protected static function notify( $summary ) {
		$settings = \hoosh_seo()->settings;
		$to       = (string) ( $settings->get( 'automation.notify_email' ) ?: get_option( 'admin_email' ) );
		if ( ! $to ) {
			return;
		}
		$lines = array();
		foreach ( (array) ( $summary['results'] ?? array() ) as $key => $result ) {
			$lines[] = sprintf( '- %s: %s', (string) ( $result['label'] ?? $key ), (string) ( $result['message'] ?? '' ) );
		}
		wp_mail(
			$to,
			sprintf( /* translators: 1: site, 2: scope */ __( '[%1$s] اتوپایلوت %2$s', 'hoosh-seo' ), get_bloginfo( 'name' ), (string) $summary['scope'] ),
			"گزارش اجرا:\n\n" . implode( "\n", $lines ) . "\n\n" . ( ! empty( $summary['undo'] ) ? 'بازگشت: ' . \HooshSEO\App::studio_url( 'autopilot' ) : '' ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}

	/**
	 * Weekly report email (guarded to once per period).
	 */
	protected static function maybe_report() {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'analytics.alerts.weekly_report', true ) ) {
			return;
		}
		$last = get_option( 'hoosh_last_report' );
		if ( $last && ( time() - (int) $last ) < 5 * DAY_IN_SECONDS ) {
			return;
		}
		$rows = $settings->on( 'reports.enabled', true ) ? Reports::email_report( array( 'attach' => $settings->on( 'automation.report_attach', false ) ) ) : array( 'sent' => false );
		if ( ! empty( $rows['sent'] ) ) {
			update_option( 'hoosh_last_report', time(), false );
		}
	}

	/**
	 * Suggested changes waiting for review (apply mode off).
	 *
	 * @return array
	 */
	public static function pending() {
		global $wpdb;
		if ( ! Database::exists( 'ai_jobs' ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( // phpcs:ignore
			"SELECT j.id, j.type AS task, j.post_id, j.result, j.created_at, j.batch_id, p.post_title
			 FROM " . Database::table( 'ai_jobs' ) . " j
			 LEFT JOIN {$wpdb->posts} p ON p.ID = j.post_id
			 WHERE j.status = 'done' AND j.applied = 0 AND j.result IS NOT NULL AND j.result <> ''
			 ORDER BY j.id DESC LIMIT 40",
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$data = (array) json_decode( (string) $row['result'], true );
			$fields = array();
			foreach ( array( 'title', 'description', 'keywords', 'faq', 'schema', 'alt', 'summary', 'answer' ) as $field ) {
				if ( ! empty( $data[ $field ] ) ) {
					$fields[ $field ] = is_array( $data[ $field ] ) ? wp_json_encode( $data[ $field ], JSON_UNESCAPED_UNICODE ) : (string) $data[ $field ];
				}
			}
			$row['fields']   = $fields;
			$row['ago']      = Helpers::time_ago_fa( strtotime( (string) $row['created_at'] . ' UTC' ) );
			$row['post_id']  = (int) $row['post_id'];
			$row['job']      = (int) $row['id'];
			$row['edit']     = $row['post_id'] ? (string) get_edit_post_link( (int) $row['post_id'], 'raw' ) : '';
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Apply or reject a suggestion.
	 *
	 * @param int    $job_id Job id.
	 * @param bool   $apply  Apply?
	 * @param array  $fields Overrides.
	 * @return array
	 */
	public static function review( $job_id, $apply = true, $fields = array() ) {
		global $wpdb;
		$job_id = (int) $job_id;
		$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'ai_jobs' ) . ' WHERE id = %d', $job_id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return array( 'ok' => false, 'message' => __( 'کار پیدا نشد.', 'hoosh-seo' ) );
		}
		if ( ! $apply ) {
			$wpdb->update( Database::table( 'ai_jobs' ), array( 'applied' => 2 ), array( 'id' => $job_id ) ); // phpcs:ignore
			return array( 'ok' => true, 'applied' => false, 'message' => __( 'پیشنهاد رد شد.', 'hoosh-seo' ) );
		}

		$data = (array) json_decode( (string) $row['result'], true );
		$data = array_merge( $data, (array) $fields );
		$out  = \HooshSEO\AI\Tasks::apply( (string) $row['type'], (int) $row['post_id'], $data );

		if ( ! empty( $out['ok'] ) ) {
			$wpdb->update( Database::table( 'ai_jobs' ), array( 'applied' => 1 ), array( 'id' => $job_id ) ); // phpcs:ignore
			Audit::log(
				'automation',
				'apply',
				array(
					'post_id'  => (int) $row['post_id'],
					'batch_id' => (string) $row['batch_id'],
					'label'    => sprintf( /* translators: %s task */ __( 'اعمال پیشنهاد %s', 'hoosh-seo' ), (string) $row['type'] ),
					'after'    => array_intersect_key( $data, array_flip( array( 'title', 'description', 'keywords', 'faq', 'alt', 'summary' ) ) ),
				)
			);
			Geo::bust();
		}
		return $out;
	}
}
