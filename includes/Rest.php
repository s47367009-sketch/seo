<?php
/**
 * REST API for the Studio (namespace hoosh/v1).
 *
 * @package HooshSEO
 */

namespace HooshSEO;

use HooshSEO\AI\Gateway;
use HooshSEO\Agent\Agent;
use HooshSEO\Agent\Guard;
use HooshSEO\Agent\Insight;
use HooshSEO\Agent\Memory;
use HooshSEO\Agent\Orchestrator;
use HooshSEO\Agent\Pipeline;
use HooshSEO\Agent\Planner;
use HooshSEO\Agent\Skills;
use HooshSEO\AI\Providers;
use HooshSEO\AI\Tasks;
use HooshSEO\Modules\Audit;
use HooshSEO\Modules\Content;
use HooshSEO\Modules\Geo;
use HooshSEO\Modules\Images;
use HooshSEO\Modules\IndexManager;
use HooshSEO\Modules\KeywordResearch;
use HooshSEO\Modules\Links;
use HooshSEO\Modules\NotFound;
use HooshSEO\Modules\RankTracker;
use HooshSEO\Modules\Analytics;
use HooshSEO\Modules\Redirects;
use HooshSEO\Modules\Robots;
use HooshSEO\Modules\Schema;
use HooshSEO\Modules\Sitemap;
use HooshSEO\Modules\Woo;
use HooshSEO\Modules\Automation;
use HooshSEO\Modules\Migration;
use HooshSEO\Modules\Speed;
use HooshSEO\Modules\OpenGraph;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rest
 */
final class Rest {

	/**
	 * Singleton.
	 *
	 * @var Rest|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Rest
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Register routes.
	 */
	public function bootstrap() {
		add_action( 'rest_api_init', array( $this, 'register' ) );
		add_filter( 'rest_allow_access', array( __CLASS__, 'allow_access' ), 10, 2 );
	}

	/**
	 * Namespace in use.
	 *
	 * @return string
	 */
	public static function namespace_() {
		return (string) hoosh_seo()->settings->get( 'advanced.rest_namespace', 'hoosh/v1' );
	}

	/**
	 * Capability gate for every route.
	 *
	 * @return string
	 */
	public static function capability() {
		$cap = (string) hoosh_seo()->settings->get( 'security.min_capability', 'manage_options' );
		/**
		 * Filter the capability required for HooshSEO REST writes.
		 *
		 * @param string $cap Capability name.
		 */
		return (string) apply_filters( 'hoosh_seo_rest_capability', $cap );
	}

	/**
	 * Can this user use the API at all?
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::capability() );
	}

	/**
	 * Filter helper.
	 *
	 * @param bool $allowed Allowed.
	 * @param array $types Types.
	 * @return bool
	 */
	public static function allow_access( $allowed, $types ) {
		unset( $allowed, $types );
		return true;
	}

	/**
	 * Register everything.
	 */
	public function register() {
		$ns = self::namespace_();

		// ---- settings -------------------------------------------------
		register_rest_route(
			$ns,
			'/settings',
			array(
				'GET'  => array( 'callback' => array( __CLASS__, 'settings_get' ) ) + $this->read(),
				'POST' => array( 'callback' => array( __CLASS__, 'settings_save' ) ) + $this->write(),
			)
		);
		register_rest_route( $ns, '/settings/reset', array_merge( array( 'callback' => array( __CLASS__, 'settings_reset' ) ), $this->write() ) );
		register_rest_route( $ns, '/settings/export', array_merge( array( 'callback' => array( __CLASS__, 'settings_export' ) ), $this->read() ) );
		register_rest_route( $ns, '/settings/import', array_merge( array( 'callback' => array( __CLASS__, 'settings_import' ) ), $this->write() ) );
		register_rest_route( $ns, '/notices', array_merge( array( 'callback' => array( __CLASS__, 'notices' ) ), $this->read() ) );
		register_rest_route( $ns, '/notices/dismiss', array_merge( array( 'callback' => array( __CLASS__, 'notice_dismiss' ) ), $this->write() ) );

		// ---- content / analysis --------------------------------------
		register_rest_route( $ns, '/analyze', array_merge( array( 'callback' => array( __CLASS__, 'analyze' ) ), $this->write() ) );
		register_rest_route( $ns, '/content/scan', array_merge( array( 'callback' => array( __CLASS__, 'content_scan' ) ), $this->write() ) );
		register_rest_route( $ns, '/content/status', array_merge( array( 'callback' => array( __CLASS__, 'content_status' ) ), $this->read() ) );
		register_rest_route( $ns, '/content/pages', array_merge( array( 'callback' => array( __CLASS__, 'content_pages' ) ), $this->read() ) );
		register_rest_route( $ns, '/content/fix', array_merge( array( 'callback' => array( __CLASS__, 'content_fix' ) ), $this->write() ) );
		register_rest_route( $ns, '/snippet', array_merge( array( 'callback' => array( __CLASS__, 'snippet' ) ), $this->read() ) );

		// ---- links ----------------------------------------------------
		register_rest_route( $ns, '/links/rules', array(
			'GET'  => array( 'callback' => array( __CLASS__, 'links_rules' ) ) + $this->read(),
			'POST' => array( 'callback' => array( __CLASS__, 'links_rule_save' ) ) + $this->write(),
		) );
		register_rest_route( $ns, '/links/rule/delete', array_merge( array( 'callback' => array( __CLASS__, 'links_rule_delete' ) ), $this->write() ) );
		register_rest_route( $ns, '/links/suggest', array_merge( array( 'callback' => array( __CLASS__, 'links_suggest' ) ), $this->read() ) );
		register_rest_route( $ns, '/links/related', array_merge( array( 'callback' => array( __CLASS__, 'links_related' ) ), $this->read() ) );
		register_rest_route( $ns, '/links/insert', array_merge( array( 'callback' => array( __CLASS__, 'links_insert' ) ), $this->write() ) );
		register_rest_route( $ns, '/links/stats', array_merge( array( 'callback' => array( __CLASS__, 'links_stats' ) ), $this->read() ) );
		register_rest_route( $ns, '/links/report', array_merge( array( 'callback' => array( __CLASS__, 'links_report' ) ), $this->read() ) );
		register_rest_route( $ns, '/links/check', array_merge( array( 'callback' => array( __CLASS__, 'links_check' ) ), $this->read() ) );
		register_rest_route( $ns, '/links/scan', array_merge( array( 'callback' => array( __CLASS__, 'links_scan' ) ), $this->write() ) );
		register_rest_route( $ns, '/links/scan/reset', array_merge( array( 'callback' => array( __CLASS__, 'links_scan_reset' ) ), $this->write() ) );

		// ---- redirects / 404 -----------------------------------------
		register_rest_route( $ns, '/redirects', array(
			'GET'  => array( 'callback' => array( __CLASS__, 'redirects_list' ) ) + $this->read(),
			'POST' => array( 'callback' => array( __CLASS__, 'redirects_save' ) ) + $this->write(),
		) );
		register_rest_route( $ns, '/redirects/delete', array_merge( array( 'callback' => array( __CLASS__, 'redirects_delete' ) ), $this->write() ) );
		register_rest_route( $ns, '/redirects/import', array_merge( array( 'callback' => array( __CLASS__, 'redirects_import' ) ), $this->write() ) );
		register_rest_route( $ns, '/redirects/test', array_merge( array( 'callback' => array( __CLASS__, 'redirects_test' ) ), $this->read() ) );
		register_rest_route( $ns, '/redirects/prune', array_merge( array( 'callback' => array( __CLASS__, 'redirects_prune' ) ), $this->write() ) );

		register_rest_route( $ns, '/notfound', array_merge( array( 'callback' => array( __CLASS__, 'notfound_list' ) ), $this->read() ) );
		register_rest_route( $ns, '/notfound/resolve', array_merge( array( 'callback' => array( __CLASS__, 'notfound_resolve' ) ), $this->write() ) );
		register_rest_route( $ns, '/notfound/bulk', array_merge( array( 'callback' => array( __CLASS__, 'notfound_bulk' ) ), $this->write() ) );
		register_rest_route( $ns, '/notfound/prune', array_merge( array( 'callback' => array( __CLASS__, 'notfound_prune' ) ), $this->write() ) );

		// ---- index ----------------------------------------------------
		register_rest_route( $ns, '/index/items', array_merge( array( 'callback' => array( __CLASS__, 'index_items' ) ), $this->read() ) );
		register_rest_route( $ns, '/index/set', array_merge( array( 'callback' => array( __CLASS__, 'index_set' ) ), $this->write() ) );
		register_rest_route( $ns, '/index/bulk', array_merge( array( 'callback' => array( __CLASS__, 'index_bulk' ) ), $this->write() ) );
		register_rest_route( $ns, '/index/undo', array_merge( array( 'callback' => array( __CLASS__, 'index_undo' ) ), $this->write() ) );
		register_rest_route( $ns, '/index/summary', array_merge( array( 'callback' => array( __CLASS__, 'index_summary' ) ), $this->read() ) );
		register_rest_route( $ns, '/index/indexnow', array_merge( array( 'callback' => array( __CLASS__, 'index_indexnow' ) ), $this->write() ) );
		register_rest_route( $ns, '/index/key', array_merge( array( 'callback' => array( __CLASS__, 'index_key' ) ), $this->write() ) );

		// ---- sitemap / robots ----------------------------------------
		register_rest_route( $ns, '/sitemap', array_merge( array( 'callback' => array( __CLASS__, 'sitemap_status' ) ), $this->read() ) );
		register_rest_route( $ns, '/sitemap/ping', array_merge( array( 'callback' => array( __CLASS__, 'sitemap_ping' ) ), $this->write() ) );
		register_rest_route( $ns, '/robots', array(
			'GET'  => array( 'callback' => array( __CLASS__, 'robots_get' ) ) + $this->read(),
			'POST' => array( 'callback' => array( __CLASS__, 'robots_save' ) ) + $this->write(),
		) );
		register_rest_route( $ns, '/robots/test', array_merge( array( 'callback' => array( __CLASS__, 'robots_test' ) ), $this->read() ) );
		register_rest_route( $ns, '/bots', array_merge( array( 'callback' => array( __CLASS__, 'bots_log' ) ), $this->read() ) );

		// ---- schema ---------------------------------------------------
		register_rest_route( $ns, '/schema', array(
			'GET'  => array( 'callback' => array( __CLASS__, 'schema_list' ) ) + $this->read(),
			'POST' => array( 'callback' => array( __CLASS__, 'schema_save' ) ) + $this->write(),
		) );
		register_rest_route( $ns, '/schema/preview', array_merge( array( 'callback' => array( __CLASS__, 'schema_preview' ) ), $this->read() ) );
		register_rest_route( $ns, '/schema/validate', array_merge( array( 'callback' => array( __CLASS__, 'schema_validate' ) ), $this->read( array( 'GET', 'POST' ) ) ) );
		register_rest_route( $ns, '/schema/import', array_merge( array( 'callback' => array( __CLASS__, 'schema_import' ) ), $this->write() ) );
		register_rest_route( $ns, '/schema/delete', array_merge( array( 'callback' => array( __CLASS__, 'schema_delete' ) ), $this->write() ) );

		// ---- images ---------------------------------------------------
		register_rest_route( $ns, '/images', array_merge( array( 'callback' => array( __CLASS__, 'images_missing' ) ), $this->read() ) );
		register_rest_route( $ns, '/images/fill', array_merge( array( 'callback' => array( __CLASS__, 'images_fill' ) ), $this->write() ) );
		register_rest_route( $ns, '/images/ai', array_merge( array( 'callback' => array( __CLASS__, 'images_ai' ) ), $this->write() ) );
		register_rest_route( $ns, '/images/rename', array_merge( array( 'callback' => array( __CLASS__, 'images_rename' ) ), $this->write() ) );

		// ---- keywords / research / ranks -----------------------------
		register_rest_route( $ns, '/keywords', array(
			'GET'  => array( 'callback' => array( __CLASS__, 'keywords_list' ) ) + $this->read(),
			'POST' => array( 'callback' => array( __CLASS__, 'keywords_save' ) ) + $this->write(),
		) );
		register_rest_route( $ns, '/keywords/delete', array_merge( array( 'callback' => array( __CLASS__, 'keywords_delete' ) ), $this->write() ) );
		register_rest_route( $ns, '/research/start', array_merge( array( 'callback' => array( __CLASS__, 'research_start' ) ), $this->write() ) );
		register_rest_route( $ns, '/research/status', array_merge( array( 'callback' => array( __CLASS__, 'research_status' ) ), $this->read() ) );
		register_rest_route( $ns, '/research/stop', array_merge( array( 'callback' => array( __CLASS__, 'research_stop' ) ), $this->write() ) );
		register_rest_route( $ns, '/research/sessions', array_merge( array( 'callback' => array( __CLASS__, 'research_sessions' ) ), $this->read() ) );
		register_rest_route( $ns, '/rank/series', array_merge( array( 'callback' => array( __CLASS__, 'rank_series' ) ), $this->read() ) );
		register_rest_route( $ns, '/rank/check', array_merge( array( 'callback' => array( __CLASS__, 'rank_check' ) ), $this->write() ) );

		// ---- audit ----------------------------------------------------
		register_rest_route( $ns, '/audit', array_merge( array( 'callback' => array( __CLASS__, 'audit_list' ) ), $this->read() ) );
		register_rest_route( $ns, '/audit/revert', array_merge( array( 'callback' => array( __CLASS__, 'audit_revert' ) ), $this->write() ) );
		register_rest_route( $ns, '/audit/redo', array_merge( array( 'callback' => array( __CLASS__, 'audit_redo' ) ), $this->write() ) );
		register_rest_route( $ns, '/audit/stats', array_merge( array( 'callback' => array( __CLASS__, 'audit_stats' ) ), $this->read() ) );
		register_rest_route( $ns, '/audit/ignore', array_merge( array( 'callback' => array( __CLASS__, 'audit_ignore' ) ), $this->write() ) );
		register_rest_route( $ns, '/audit/files', array_merge( array( 'callback' => array( __CLASS__, 'audit_files' ) ), $this->read() ) );
		register_rest_route( $ns, '/audit/files/baseline', array_merge( array( 'callback' => array( __CLASS__, 'audit_files_baseline' ) ), $this->write() ) );

		// ---- AI -------------------------------------------------------
		register_rest_route( $ns, '/ai/overview', array_merge( array( 'callback' => array( __CLASS__, 'ai_overview' ) ), $this->read() ) );
		register_rest_route( $ns, '/ai/run', array_merge( array( 'callback' => array( __CLASS__, 'ai_run' ) ), $this->write() ) );
		register_rest_route( $ns, '/ai/queue', array_merge( array( 'callback' => array( __CLASS__, 'ai_queue' ) ), $this->write() ) );
		register_rest_route( $ns, '/ai/process', array_merge( array( 'callback' => array( __CLASS__, 'ai_process' ) ), $this->write() ) );
		register_rest_route( $ns, '/ai/jobs', array_merge( array( 'callback' => array( __CLASS__, 'ai_jobs' ) ), $this->read() ) );
		register_rest_route( $ns, '/ai/retry', array_merge( array( 'callback' => array( __CLASS__, 'ai_retry' ) ), $this->write() ) );
		register_rest_route( $ns, '/ai/cancel', array_merge( array( 'callback' => array( __CLASS__, 'ai_cancel' ) ), $this->write() ) );
		register_rest_route( $ns, '/ai/test', array_merge( array( 'callback' => array( __CLASS__, 'ai_test' ) ), $this->write() ) );
		register_rest_route( $ns, '/ai/keys', array_merge( array( 'callback' => array( __CLASS__, 'ai_keys' ) ), $this->write() ) );
		register_rest_route( $ns, '/ai/chat', array_merge( array( 'callback' => array( __CLASS__, 'ai_chat' ) ), $this->write() ) );

		// ---- geo / woo / analytics / speed ---------------------------
		register_rest_route( $ns, '/geo', array(
			'GET'  => array( 'callback' => array( __CLASS__, 'geo_get' ) ) + $this->read(),
			'POST' => array( 'callback' => array( __CLASS__, 'geo_save' ) ) + $this->write(),
		) );
		register_rest_route( $ns, '/woo', array_merge( array( 'callback' => array( __CLASS__, 'woo_summary' ) ), $this->read() ) );
		register_rest_route( $ns, '/woo/fix', array_merge( array( 'callback' => array( __CLASS__, 'woo_fix' ) ), $this->write() ) );
		register_rest_route( $ns, '/analytics', array_merge( array( 'callback' => array( __CLASS__, 'analytics_summary' ) ), $this->read() ) );
		register_rest_route( $ns, '/analytics/import', array_merge( array( 'callback' => array( __CLASS__, 'analytics_import' ) ), $this->write() ) );
		register_rest_route( $ns, '/analytics/refresh', array_merge( array( 'callback' => array( __CLASS__, 'analytics_refresh' ) ), $this->write() ) );
		register_rest_route( $ns, '/analytics/psi', array_merge( array( 'callback' => array( __CLASS__, 'analytics_psi' ) ), $this->read() ) );
		register_rest_route( $ns, '/speed', array_merge( array( 'callback' => array( __CLASS__, 'speed_check' ) ), $this->read() ) );
		register_rest_route( $ns, '/speed/toggle', array_merge( array( 'callback' => array( __CLASS__, 'speed_toggle' ) ), $this->write() ) );

		// ---- automation / migration / reports / tools ---------------
		register_rest_route( $ns, '/automation/run', array_merge( array( 'callback' => array( __CLASS__, 'automation_run' ) ), $this->write() ) );
		register_rest_route( $ns, '/automation/status', array_merge( array( 'callback' => array( __CLASS__, 'automation_status' ) ), $this->read() ) );
		register_rest_route( $ns, '/automation/pending', array_merge( array( 'callback' => array( __CLASS__, 'automation_pending' ) ), $this->read() ) );
		register_rest_route( $ns, '/automation/apply', array_merge( array( 'callback' => array( __CLASS__, 'automation_apply' ) ), $this->write() ) );
		register_rest_route( $ns, '/automation/undo', array_merge( array( 'callback' => array( __CLASS__, 'automation_undo' ) ), $this->write() ) );

		// ---- autonomous agent ---------------------------------------
		register_rest_route( $ns, '/agent/status', array_merge( array( 'callback' => array( __CLASS__, 'agent_status' ) ), $this->read() ) );
		register_rest_route( $ns, '/agent/diagnose', array_merge( array( 'callback' => array( __CLASS__, 'agent_diagnose' ) ), $this->read() ) );
		register_rest_route( $ns, '/agent/plan', array_merge( array( 'callback' => array( __CLASS__, 'agent_plan' ) ), $this->read() ) );
		register_rest_route( $ns, '/agent/run', array_merge( array( 'callback' => array( __CLASS__, 'agent_run' ) ), $this->write() ) );
		register_rest_route( $ns, '/agent/halt', array_merge( array( 'callback' => array( __CLASS__, 'agent_halt' ) ), $this->write() ) );
		register_rest_route( $ns, '/agent/runs', array_merge( array( 'callback' => array( __CLASS__, 'agent_runs' ) ), $this->read() ) );
		register_rest_route( $ns, '/agent/run/(?P<id>\d+)', array_merge( array( 'callback' => array( __CLASS__, 'agent_run_detail' ) ), $this->read() ) );
		register_rest_route( $ns, '/agent/revert/(?P<step>\d+)', array_merge( array( 'callback' => array( __CLASS__, 'agent_revert' ) ), $this->write() ) );
		register_rest_route( $ns, '/agent/articles', array_merge( array( 'callback' => array( __CLASS__, 'agent_articles' ) ), $this->read() ) );
		register_rest_route( $ns, '/agent/article', array_merge( array( 'callback' => array( __CLASS__, 'agent_article' ) ), $this->write() ) );
		register_rest_route( $ns, '/agent/opportunities', array_merge( array( 'callback' => array( __CLASS__, 'agent_opportunities' ) ), $this->read() ) );
		register_rest_route( $ns, '/migration', array_merge( array( 'callback' => array( __CLASS__, 'migration_list' ) ), $this->read() ) );
		register_rest_route( $ns, '/migration/preview', array_merge( array( 'callback' => array( __CLASS__, 'migration_preview' ) ), $this->read() ) );
		register_rest_route( $ns, '/migration/run', array_merge( array( 'callback' => array( __CLASS__, 'migration_run' ) ), $this->write() ) );
		register_rest_route( $ns, '/migration/undo', array_merge( array( 'callback' => array( __CLASS__, 'migration_undo' ) ), $this->write() ) );
		register_rest_route( $ns, '/migration/cleanup', array_merge( array( 'callback' => array( __CLASS__, 'migration_cleanup' ) ), $this->write() ) );
		register_rest_route( $ns, '/report', array_merge( array( 'callback' => array( __CLASS__, 'report_html' ) ), $this->read() ) );
		register_rest_route( $ns, '/report/email', array_merge( array( 'callback' => array( __CLASS__, 'report_email' ) ), $this->write() ) );
		register_rest_route( $ns, '/tools/flush', array_merge( array( 'callback' => array( __CLASS__, 'tools_flush' ) ), $this->write() ) );
		register_rest_route( $ns, '/tools/system', array_merge( array( 'callback' => array( __CLASS__, 'tools_system' ) ), $this->read() ) );
		register_rest_route( $ns, '/tools/cron', array_merge( array( 'callback' => array( __CLASS__, 'tools_cron' ) ), $this->write() ) );
		register_rest_route( $ns, '/docs/(?P<name>[a-zA-Z0-9_-]+)', array_merge( array( 'callback' => array( __CLASS__, 'docs' ) ), $this->read() ) );

		// ---- post-scoped editor writes -------------------------------
		register_rest_route(
			$ns,
			'/post/(?P<id>\d+)',
			array(
				'GET'  => array(
					'callback'            => array( __CLASS__, 'post_get' ),
					'permission_callback' => array( __CLASS__, 'can_read_post_check' ),
					'methods'             => 'READABLE',
				),
				'POST' => array(
					'callback'            => array( __CLASS__, 'post_save' ),
					'permission_callback' => array( __CLASS__, 'can_read_post_check' ),
					'methods'             => 'POST',
				),
			)
		);
	}

	/**
	 * Read args.
	 *
	 * @return array
	 */
	protected function read( $methods = 'READABLE' ) {
		return array(
			'methods'             => (array) $methods,
			'permission_callback' => array( __CLASS__, 'can_read_check' ),
			'args'                => array(),
		);
	}

	/**
	 * Write args.
	 *
	 * @return array
	 */
	protected function write() {
		return array(
			'methods'             => 'POST',
			'permission_callback' => array( __CLASS__, 'can_manage_check' ),
			'args'                => array(),
		);
	}

	/**
	 * Permission: manage.
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_manage_check() {
		if ( self::can_manage() ) {
			return true;
		}
		return new \WP_Error(
			'hs_forbidden',
			__( 'برای این کار اجازه ندارید.', 'hoosh-seo' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Permission: read (editors may read analysis data).
	 *
	 * @return bool|\WP_Error
	 */
	public static function can_read_check() {
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		return new \WP_Error( 'hs_forbidden', __( 'برای این کار اجازه ندارید.', 'hoosh-seo' ), array( 'status' => 403 ) );
	}

	/**
	 * Permission: read a post the user may edit (editors use the metabox).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public static function can_read_post_check( $request ) {
		return self::can_post( $request );
	}

	/**
	 * Permission for a single-post route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public static function can_post( $request ) {
		$post_id = (int) $request['id'];
		if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}
		return new \WP_Error( 'hs_forbidden', __( 'این نوشته را نمی‌توانید ویرایش کنید.', 'hoosh-seo' ), array( 'status' => 403 ) );
	}

	/* ------------------------------------------------------------------
	 * Handlers
	 * --------------------------------------------------------------- */

	/**
	 * Settings tree.
	 *
	 * @return \WP_REST_Response
	 */
	public static function settings_get() {
		$tree = hoosh_seo()->settings->all();
		unset( $tree['ai']['api_key'] );
		return rest_ensure_response(
			array(
				'settings' => $tree,
				'defaults' => Settings::defaults(),
				'keys'     => Providers::keys_masked(),
				'readonly' => array( 'general.site_type' => (string) hoosh_seo()->settings->get( 'general.site_type', 'site' ) ),
			)
		);
	}

	/**
	 * Save settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function settings_save( $request ) {
		$values = $request->get_json_params();
		$values = is_array( $values ) ? $values : (array) $request['settings'];
		if ( isset( $values['settings'] ) && is_array( $values['settings'] ) ) {
			$values = $values['settings'];
		}
		if ( ! $values ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'چیزی برای ذخیره نبود.', 'hoosh-seo' ) ) );
		}
		$flat = false;
		foreach ( array_keys( (array) $values ) as $key ) {
			if ( false !== strpos( (string) $key, '.' ) ) {
				$flat = true;
				break;
			}
		}

		$before = hoosh_seo()->settings->all();
		$ai_keys = array();
		if ( isset( $values['ai_keys'] ) && is_array( $values['ai_keys'] ) ) {
			$ai_keys = Providers::set_keys( (array) $values['ai_keys'] );
			unset( $values['ai_keys'] );
		}

		$ok = hoosh_seo()->settings->set( $values, ! $flat );

		Audit::log(
			'settings',
			'update',
			array(
				'before'  => self::narrow( $before, $values ),
				'after'   => self::narrow( hoosh_seo()->settings->all(), $values ),
				'label'   => sprintf( /* translators: %s groups */ __( 'ذخیره تنظیمات (%s)', 'hoosh-seo' ), implode( ', ', array_keys( (array) $values ) ) ),
			)
		);

		return rest_ensure_response(
			array(
				'ok'       => (bool) $ok,
				'settings' => hoosh_seo()->settings->all(),
				'keys'     => $ai_keys,
				'message'  => __( 'تنظیمات ذخیره شد.', 'hoosh-seo' ),
			)
		);
	}

	/**
	 * Keep only the changed branches for the audit diff.
	 *
	 * @param array $tree   Tree.
	 * @param array $values Changed groups.
	 * @return array
	 */
	protected static function narrow( $tree, $values ) {
		$out = array();
		foreach ( (array) $values as $group => $ignored ) {
			if ( isset( $tree[ $group ] ) ) {
				$out[ $group ] = $tree[ $group ];
			}
		}
		return $out;
	}

	/**
	 * Reset one group.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function settings_reset( $request ) {
		$group = sanitize_key( (string) $request['group'] );
		if ( ! $group ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'گروه مشخص نشد.', 'hoosh-seo' ) ) );
		}
		$before = (array) hoosh_seo()->settings->get( $group, array() );
		hoosh_seo()->settings->reset_group( $group );
		Audit::log( 'settings', 'reset', array( 'before' => array( $group => $before ), 'after' => array( $group => (array) hoosh_seo()->settings->get( $group, array() ) ), 'label' => $group ) );
		return rest_ensure_response( array( 'ok' => true, 'settings' => hoosh_seo()->settings->all(), 'message' => __( 'به حالت پیش‌فرض برگشت.', 'hoosh-seo' ) ) );
	}

	/**
	 * JSON export.
	 *
	 * @return \WP_REST_Response
	 */
	public static function settings_export() {
		return rest_ensure_response(
			array(
				'filename' => 'hoosh-seo-settings-' . gmdate( 'Ymd-His' ) . '.json',
				'json'     => wp_json_encode( hoosh_seo()->settings->export( (bool) current_user_can( 'manage_options' ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);
	}

	/**
	 * JSON import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function settings_import( $request ) {
		$raw   = (string) $request['json'];
		$tree  = json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'JSON معتبر نبود.', 'hoosh-seo' ) ) );
		}
		$before = hoosh_seo()->settings->all();
		hoosh_seo()->settings->replace( $tree );
		Audit::log( 'settings', 'import', array( 'before' => $before, 'after' => hoosh_seo()->settings->all(), 'label' => __( 'بازیابی تنظیمات از فایل', 'hoosh-seo' ) ) );
		return rest_ensure_response( array( 'ok' => true, 'settings' => hoosh_seo()->settings->all(), 'message' => __( 'تنظیمات جایگزین شد.', 'hoosh-seo' ) ) );
	}

	/**
	 * Action centre.
	 *
	 * @return \WP_REST_Response
	 */
	public static function notices() {
		$out = array();
		foreach ( (array) get_option( 'hoosh_seo_notices', array() ) as $key => $notice ) {
			if ( ! is_array( $notice ) ) {
				continue;
			}
			$notice['key']       = (string) $key;
			$notice['dismissed'] = (bool) get_user_meta( get_current_user_id(), 'hoosh_notice_' . $key, true );
			$out[]               = $notice;
		}
		return rest_ensure_response( array( 'rows' => $out ) );
	}

	/**
	 * Dismiss.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function notice_dismiss( $request ) {
		$key   = sanitize_key( (string) $request['key'] );
		$state = (bool) get_user_meta( get_current_user_id(), 'hoosh_notice_' . $key, true );
		update_user_meta( get_current_user_id(), 'hoosh_notice_' . $key, $state ? 0 : 1 );
		return rest_ensure_response( array( 'ok' => true, 'dismissed' => ! $state, 'key' => $key ) );
	}

	/**
	 * Live analysis.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function analyze( $request ) {
		$data = (array) $request->get_json_params();
		if ( ! $data ) {
			$data = (array) $request->get_params();
		}
		if ( ! empty( $data['post_id'] ) ) {
			$post_id = (int) $data['post_id'];
			if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_post', $post_id ) ) {
				return rest_ensure_response( array( 'ok' => false, 'message' => __( 'اجازه تحلیل این نوشته را ندارید.', 'hoosh-seo' ) ) );
			}
		}
		return rest_ensure_response( Content::live( $data ) );
	}

	/**
	 * Kick a content scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function content_scan( $request ) {
		$args = array(
			'type'   => sanitize_key( (string) $request['type'] ),
			'limit'  => (int) $request['limit'],
			'force'  => (bool) $request['force'],
			'filter' => sanitize_key( (string) $request['filter'] ),
		);
		if ( $args['filter'] ) {
			$pages = Content::pages( array( 'filter' => $args['filter'], 'per' => $args['limit'] ? $args['limit'] : 500, 'paged' => 1 ) );
			$args['ids'] = wp_list_pluck( (array) ( $pages['rows'] ?? array() ), 'post_id' );
		}
		return rest_ensure_response( Content::start_scan( $args ) );
	}

	/**
	 * Scan progress.
	 *
	 * @return \WP_REST_Response
	 */
	public static function content_status() {
		return rest_ensure_response( Content::scan_status() );
	}

	/**
	 * Analysed pages table.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function content_pages( $request ) {
		return rest_ensure_response(
			Content::pages(
				array(
					'type'    => sanitize_key( (string) $request['type'] ),
					'search'  => sanitize_text_field( (string) $request['search'] ),
					'filter'  => sanitize_key( (string) ( $request['filter'] ? $request['filter'] : 'all' ) ),
					'orderby' => sanitize_key( (string) ( $request['orderby'] ? $request['orderby'] : 'score' ) ),
					'order'   => sanitize_key( (string) ( $request['order'] ? $request['order'] : 'asc' ) ),
					'per'     => (int) $request['per'],
					'paged'   => (int) $request['paged'],
				)
			)
		);
	}

	/**
	 * Fix a single check (auto / manual / AI).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function content_fix( $request ) {
		$check   = sanitize_key( (string) $request['check'] );
		$post_id = (int) $request['post_id'];
		$mode    = sanitize_key( (string) $request['mode'] );

		if ( 'ai' === $mode ) {
			$tasks = Tasks::for_check( $check );
			if ( ! $tasks ) {
				return rest_ensure_response( array( 'ok' => false, 'message' => __( 'برای این بررسی کار هوش مصنوعی تعریف نشده است.', 'hoosh-seo' ) ) );
			}
			$result = Tasks::run( $tasks[0], $post_id, (array) $request['payload'] );
			$result['tasks'] = $tasks;
			return rest_ensure_response( $result );
		}

		$task = Content::fix_task( $check, $post_id );
		if ( ! $task ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'بررسی نامشخص بود.', 'hoosh-seo' ) ) );
		}
		if ( 'auto' === $mode ) {
			return rest_ensure_response( Content::apply_fix( $check, $post_id ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'task' => $task, 'manual' => true, 'message' => __( 'راهنمای اصلاح آماده است.', 'hoosh-seo' ) ) );
	}

	/**
	 * SERP preview data.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function snippet( $request ) {
		$post_id = (int) $request['post_id'];
		$data    = (array) $request->get_json_params();
		$title   = isset( $data['title'] ) ? (string) $data['title'] : ( $post_id ? Meta::resolve( $post_id, 'title' ) : '' );
		$desc    = isset( $data['description'] ) ? (string) $data['description'] : ( $post_id ? Meta::resolve( $post_id, 'description' ) : '' );
		$url     = isset( $data['url'] ) ? (string) $data['url'] : ( $post_id ? (string) get_permalink( $post_id ) : home_url( '/' ) );

		return rest_ensure_response(
			array(
				'desktop' => self::render_snippet( $title, $desc, $url, 'desktop' ),
				'mobile'  => self::render_snippet( $title, $desc, $url, 'mobile' ),
				'device'  => sanitize_key( (string) $request['device'] ),
			)
		);
	}

	/**
	 * Snippet render helper.
	 *
	 * @param string $title Title.
	 * @param string $desc Description.
	 * @param string $url URL.
	 * @param string $device Device.
	 * @return array
	 */
	protected static function render_snippet( $title, $desc, $url, $device ) {
		$title = wp_strip_all_tags( (string) $title );
		$desc  = wp_strip_all_tags( (string) $desc );
		$max_title = 'mobile' === $device ? 580 : 580;
		$max_desc  = 'mobile' === $device ? 160 : 185;
		$bytes     = function ( $text ) {
			return strlen( (string) $text );
		};

		return array(
			'title'      => $title,
			'description' => $desc,
			'url'        => $url,
			'display_url' => preg_replace( '#^https?://#i', '', rtrim( (string) $url, '/' ) ),
			'title_chars' => Helpers::strlen( $title ),
			'title_px'    => Helpers::pixel_width( $title, 'mobile' === $device ? 20 : 20 ),
			'desc_chars'  => Helpers::strlen( $desc ),
			'title_clamped' => mb_substr( $title, 0, 65 ),
			'desc_clamped'  => mb_substr( $desc, 0, $max_desc ),
			'limits'      => array( 'title_px' => $max_title, 'desc_chars' => $max_desc, 'title_chars' => 65 ),
			'overflow'    => array(
				'title' => Helpers::pixel_width( $title, 20 ) > $max_title,
				'desc'  => Helpers::strlen( $desc ) > $max_desc,
			),
			'sitename'    => get_bloginfo( 'name' ),
			'favicon'     => get_site_icon_url( 32 ),
			'size_kb'       => round( ( $bytes( $title ) + $bytes( $desc ) ) / 1024, 2 ),
		);
	}

	/* -------------------- links -------------------- */

	/**
	 * Rules list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function links_rules( $request ) {
		return rest_ensure_response(
			Links::listing(
				array(
					'search'  => sanitize_text_field( (string) $request['search'] ),
					'status'  => sanitize_key( (string) $request['status'] ),
					'per'     => (int) $request['per'],
					'paged'   => (int) $request['paged'],
					'orderby' => sanitize_key( (string) $request['orderby'] ),
				)
			)
		);
	}

	/**
	 * Save a rule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function links_rule_save( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response( Links::save_rule( $data ) );
	}

	/**
	 * Delete rules.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function links_rule_delete( $request ) {
		return rest_ensure_response( array( 'ok' => true, 'deleted' => Links::remove( (array) $request['ids'] ) ) );
	}

	/**
	 * Suggestions for a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function links_suggest( $request ) {
		return rest_ensure_response( Links::suggest( (int) $request['post_id'], (int) $request['limit'] ) );
	}

	/**
	 * Related posts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function links_related( $request ) {
		return rest_ensure_response( array( 'rows' => Links::related( (int) $request['post_id'], (int) $request['limit'] ) ) );
	}

	/**
	 * Insert links into a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function links_insert( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response( Links::insert_into_content( (int) ( $data['post_id'] ?? 0 ), (array) ( $data['links'] ?? array() ) ) );
	}

	/**
	 * Link stats.
	 *
	 * @return \WP_REST_Response
	 */
	public static function links_stats() {
		return rest_ensure_response( Links::stats() );
	}

	/**
	 * Link report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function links_report( $request ) {
		$type = sanitize_key( (string) $request['type'] );
		return rest_ensure_response( Links::report( $type ? $type : 'top', (int) $request['days'] ) );
	}

	/**
	 * Check one URL.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function links_check( $request ) {
		return rest_ensure_response( Links::check_url( (string) $request['url'] ) );
	}

	/**
	 * Start/continue a broken-link sweep.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function links_scan( $request ) {
		return rest_ensure_response( Links::scan_queued( (int) $request['budget'] ) );
	}

	/**
	 * Reset the sweep.
	 *
	 * @return \WP_REST_Response
	 */
	public static function links_scan_reset() {
		return rest_ensure_response( Links::scan_reset() );
	}

	/* -------------------- redirects / 404 -------------------- */

	/**
	 * Redirect list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function redirects_list( $request ) {
		return rest_ensure_response(
			Redirects::listing(
				array(
					'search'  => sanitize_text_field( (string) $request['search'] ),
					'status'  => sanitize_key( (string) $request['status'] ),
					'group'   => sanitize_key( (string) $request['group'] ),
					'orderby' => sanitize_key( (string) $request['orderby'] ),
					'order'   => sanitize_key( (string) $request['order'] ),
					'per'     => (int) $request['per'],
					'paged'   => (int) $request['paged'],
				)
			)
		);
	}

	/**
	 * Save redirect.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function redirects_save( $request ) {
		$data = (array) $request->get_json_params();
		if ( empty( $data['id'] ) ) {
			return rest_ensure_response( Redirects::create( $data ) );
		}
		return rest_ensure_response( Redirects::update( (int) $data['id'], $data ) );
	}

	/**
	 * Delete redirects.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function redirects_delete( $request ) {
		return rest_ensure_response( array( 'ok' => true, 'deleted' => Redirects::remove( (array) $request['ids'] ) ) );
	}

	/**
	 * CSV import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function redirects_import( $request ) {
		$csv = (string) $request['csv'];
		if ( '' === $csv && isset( $_FILES['csv'] ) ) { // phpcs:ignore WordPress.Security
			$csv = (string) file_get_contents( sanitize_text_field( (string) $_FILES['csv']['tmp_name'] ) ); // phpcs:ignore
		}
		return rest_ensure_response( Redirects::import_csv( $csv, (bool) $request['dry_run'] ) );
	}

	/**
	 * Test a path.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function redirects_test( $request ) {
		return rest_ensure_response( Redirects::test( (string) $request['path'] ) );
	}

	/**
	 * Prune hits.
	 *
	 * @return \WP_REST_Response
	 */
	public static function redirects_prune() {
		return rest_ensure_response( array( 'ok' => true, 'pruned' => Redirects::prune() ) );
	}

	/**
	 * 404 list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function notfound_list( $request ) {
		return rest_ensure_response(
			NotFound::listing(
				array(
					'search'  => sanitize_text_field( (string) $request['search'] ),
					'state'   => sanitize_key( (string) $request['state'] ),
					'orderby' => sanitize_key( (string) $request['orderby'] ),
					'order'   => sanitize_key( (string) $request['order'] ),
					'per'     => (int) $request['per'],
					'paged'   => (int) $request['paged'],
					'since'   => (int) $request['since'],
				)
			)
		);
	}

	/**
	 * Resolve a 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function notfound_resolve( $request ) {
		return rest_ensure_response(
			NotFound::resolve(
				(int) $request['id'],
				(string) $request['target'],
				(string) $request['type']
			)
		);
	}

	/**
	 * Bulk 404 action.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function notfound_bulk( $request ) {
		return rest_ensure_response( NotFound::bulk( (array) $request['ids'], sanitize_key( (string) $request['action'] ) ) );
	}

	/**
	 * Prune 404s.
	 *
	 * @return \WP_REST_Response
	 */
	public static function notfound_prune() {
		return rest_ensure_response( array( 'ok' => true, 'pruned' => NotFound::prune() ) );
	}

	/* -------------------- index -------------------- */

	/**
	 * Index manager items.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function index_items( $request ) {
		return rest_ensure_response(
			IndexManager::items(
				array(
					'search'  => sanitize_text_field( (string) $request['search'] ),
					'type'    => sanitize_key( (string) $request['type'] ),
					'state'   => sanitize_key( (string) $request['state'] ),
					'per'     => (int) $request['per'],
					'paged'   => (int) $request['paged'],
				)
			)
		);
	}

	/**
	 * Set state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function index_set( $request ) {
		return rest_ensure_response( IndexManager::set_state( (int) $request['post_id'], sanitize_key( (string) $request['state'] ) ) );
	}

	/**
	 * Bulk state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function index_bulk( $request ) {
		return rest_ensure_response( IndexManager::bulk( (array) $request['ids'], sanitize_key( (string) $request['state'] ) ) );
	}

	/**
	 * Undo a batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function index_undo( $request ) {
		return rest_ensure_response( IndexManager::undo_batch( sanitize_text_field( (string) $request['batch'] ) ) );
	}

	/**
	 * Summary.
	 *
	 * @return \WP_REST_Response
	 */
	public static function index_summary() {
		return rest_ensure_response( array( 'summary' => IndexManager::summary(), 'matrix' => IndexManager::archive_matrix() ) );
	}

	/**
	 * IndexNow submit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function index_indexnow( $request ) {
		$urls = (array) $request['urls'];
		if ( ! $urls ) {
			$ids  = array_map( 'absint', (array) $request['ids'] );
			foreach ( $ids as $id ) {
				$link = get_permalink( $id );
				if ( $link ) {
					$urls[] = $link;
				}
			}
		}
		if ( ! $urls ) {
			$urls = IndexManager::recent_urls( 100 );
		}
		return rest_ensure_response( IndexManager::indexnow( array_slice( array_map( 'esc_url_raw', $urls ), 0, 10000 ) ) );
	}

	/**
	 * Regenerate the IndexNow key.
	 *
	 * @return \WP_REST_Response
	 */
	public static function index_key() {
		return rest_ensure_response( IndexManager::regenerate_key() );
	}

	/* -------------------- sitemap / robots -------------------- */

	/**
	 * Sitemap status.
	 *
	 * @return \WP_REST_Response
	 */
	public static function sitemap_status() {
		return rest_ensure_response(
			array(
				'children' => Sitemap::children(),
				'url'      => Sitemap::url(),
				'status'   => Sitemap::status(),
			)
		);
	}

	/**
	 * Ping search engines.
	 *
	 * @return \WP_REST_Response
	 */
	public static function sitemap_ping() {
		return rest_ensure_response( Sitemap::ping_all() );
	}

	/**
	 * Robots content.
	 *
	 * @return \WP_REST_Response
	 */
	public static function robots_get() {
		return rest_ensure_response(
			array(
				'content'  => Robots::content(),
				'status'   => Robots::status(),
				'custom'   => (string) get_option( 'hoosh_robots_txt', '' ),
				'override' => (bool) hoosh_seo()->settings->get( 'robots.override_file', false ),
				'override_file' => (bool) hoosh_seo()->settings->get( 'robots.override_file', false ),
				'bots'     => Robots::bot_catalog(),
				'virtual'  => ! file_exists( untrailingslashit( ABSPATH ) . '/robots.txt' ),
			)
		);
	}

	/**
	 * Save robots.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function robots_save( $request ) {
		$content = (string) $request['content'];
		update_option( 'hoosh_robots_txt', $content );

		$override = $request->get_param( 'override' );
		$written  = null;
		$state    = (bool) hoosh_seo()->settings->get( 'robots.override_file', false );
		if ( null !== $override ) {
			$state = (bool) $override;
			hoosh_seo()->settings->set( array( 'robots.override_file' => $state ) );
			if ( $state ) {
				$written = Robots::write_file();
			}
		}

		Audit::log(
			'robots',
			'save',
			array(
				'after'  => array(
					'content'  => mb_substr( $content, 0, 4000 ),
					'override' => $state,
				),
			)
		);
		return rest_ensure_response(
			array(
				'ok'       => true,
				'content'  => Robots::content(),
				'override' => $state,
				'written'  => $written,
				'message'  => __( 'رباتز ذخیره شد.', 'hoosh-seo' ),
			)
		);
	}

	/**
	 * Test a bot against robots.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function robots_test( $request ) {
		return rest_ensure_response( Robots::test( (string) $request['bot'], (string) $request['path'] ) );
	}

	/**
	 * Crawler log.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function bots_log( $request ) {
		return rest_ensure_response(
			array(
				'recent' => Geo::recent_crawlers( (int) $request['days'] ),
				'sitemap' => Robots::status(),
			)
		);
	}

	/* -------------------- schema -------------------- */

	/**
	 * Custom schema rows.
	 *
	 * @return \WP_REST_Response
	 */
	public static function schema_list() {
		return rest_ensure_response(
			array(
				'rows'      => Schema::listing(),
				'types'     => Schema::types(),
				'templates' => Schema::templates(),
				'foreign'   => Schema::foreign_types(),
			)
		);
	}

	/**
	 * Save a row.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function schema_save( $request ) {
		$data = (array) $request->get_json_params();
		if ( isset( $data['json'] ) && is_string( $data['json'] ) ) {
			$decoded = json_decode( (string) $data['json'], true );
			if ( is_array( $decoded ) ) {
				$data['data'] = $decoded;
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => Schema::save_row( $data ) ) );
	}

	/**
	 * Preview the graph.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function schema_preview( $request ) {
		return rest_ensure_response( Schema::preview( (int) $request['post_id'] ) );
	}

	/**
	 * Validate a graph.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function schema_validate( $request ) {
		$nodes = $request['nodes'];
		if ( is_string( $nodes ) ) {
			$decoded = json_decode( $nodes, true );
			$nodes   = is_array( $decoded ) ? ( $decoded['@graph'] ?? array( $decoded ) ) : array();
		}
		return rest_ensure_response( Schema::validate( (array) $nodes ) );
	}

	/**
	 * Import from URL.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function schema_import( $request ) {
		return rest_ensure_response( Schema::import_from_url( (string) $request['url'] ) );
	}

	/**
	 * Delete schema rows.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function schema_delete( $request ) {
		return rest_ensure_response( array( 'ok' => true, 'deleted' => Schema::delete_rows( (array) $request['ids'] ) ) );
	}

	/* -------------------- images -------------------- */

	/**
	 * Missing alts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function images_missing( $request ) {
		return rest_ensure_response(
			array_merge(
				Images::missing(
					array(
						'type'     => sanitize_key( (string) ( $request['type'] ? $request['type'] : 'all' ) ),
						'per_page' => (int) $request['per'],
						'page'     => (int) $request['paged'],
					)
				),
				array( 'summary' => Images::summary() )
			)
		);
	}

	/**
	 * Fill alts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function images_fill( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response( Images::apply_bulk( (array) ( $data['ids'] ?? array() ), (array) ( $data['args'] ?? array() ) ) );
	}

	/**
	 * Queue AI alts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function images_ai( $request ) {
		return rest_ensure_response( Images::queue_ai_alt( (array) $request['ids'] ) );
	}

	/**
	 * Rename one file.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function images_rename( $request ) {
		return rest_ensure_response( Images::maybe_rename( (int) $request['id'], sanitize_text_field( (string) $request['slug'] ) ) );
	}

	/* -------------------- keywords / research / ranks -------------------- */

	/**
	 * Saved keywords.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function keywords_list( $request ) {
		return rest_ensure_response(
			KeywordResearch::all(
				array(
					'search'  => sanitize_text_field( (string) $request['search'] ),
					'status'  => sanitize_key( (string) $request['status'] ),
					'post_id' => (int) $request['post_id'],
					'orderby' => sanitize_key( (string) $request['orderby'] ),
					'order'   => sanitize_key( (string) $request['order'] ),
					'per'     => (int) $request['per'],
					'paged'   => (int) $request['paged'],
				)
			)
		);
	}

	/**
	 * Save keywords.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function keywords_save( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response( KeywordResearch::save( $data ) );
	}

	/**
	 * Delete keywords.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function keywords_delete( $request ) {
		return rest_ensure_response( KeywordResearch::delete( (array) $request['ids'] ) );
	}

	/**
	 * Start a research run.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function research_start( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response( KeywordResearch::start( $data ) );
	}

	/**
	 * Research progress.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function research_status( $request ) {
		return rest_ensure_response( KeywordResearch::status( (int) $request['session'] ) );
	}

	/**
	 * Stop research.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function research_stop( $request ) {
		return rest_ensure_response( KeywordResearch::stop( (int) $request['session'] ) );
	}

	/**
	 * Past sessions.
	 *
	 * @return \WP_REST_Response
	 */
	public static function research_sessions() {
		return rest_ensure_response( array( 'rows' => KeywordResearch::sessions() ) );
	}

	/**
	 * Rank series.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rank_series( $request ) {
		return rest_ensure_response(
			array(
				'series'  => RankTracker::series( (int) $request['days'] ),
				'keywords' => RankTracker::keywords( (int) $request['limit'] ),
				'summary' => RankTracker::summary(),
			)
		);
	}

	/**
	 * Check ranks now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function rank_check( $request ) {
		return rest_ensure_response( RankTracker::check( (array) $request['ids'], (int) $request['limit'] ) );
	}

	/* -------------------- audit -------------------- */

	/**
	 * Audit list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function audit_list( $request ) {
		return rest_ensure_response(
			Audit::listing(
				array(
					'scope'   => sanitize_key( (string) $request['scope'] ),
					'action'  => sanitize_key( (string) $request['action'] ),
					'search'  => sanitize_text_field( (string) $request['search'] ),
					'post_id' => (int) $request['post_id'],
					'user_id' => (int) $request['user_id'],
					'from'    => sanitize_text_field( (string) $request['from'] ),
					'to'      => sanitize_text_field( (string) $request['to'] ),
					'batch'   => sanitize_text_field( (string) $request['batch'] ),
					'per'     => (int) $request['per'],
					'paged'   => (int) $request['paged'],
				)
			)
		);
	}

	/**
	 * Revert.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function audit_revert( $request ) {
		return rest_ensure_response( Audit::revert( (int) $request['id'] ) );
	}

	/**
	 * Redo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function audit_redo( $request ) {
		return rest_ensure_response( Audit::redo( (int) $request['id'] ) );
	}

	/**
	 * Audit stats.
	 *
	 * @return \WP_REST_Response
	 */
	public static function audit_stats() {
		return rest_ensure_response( Audit::stats() );
	}

	/**
	 * Save ignore rules.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function audit_ignore( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response( array( 'ok' => true, 'rules' => Audit::set_ignore_rules( (array) ( $data['rules'] ?? array() ) ) ) );
	}

	/**
	 * File integrity diff.
	 *
	 * @return \WP_REST_Response
	 */
	public static function audit_files() {
		return rest_ensure_response( Audit::file_diff() );
	}

	/**
	 * Store a new baseline.
	 *
	 * @return \WP_REST_Response
	 */
	public static function audit_files_baseline() {
		$files = Audit::file_manifest( true );
		return rest_ensure_response( array( 'ok' => true, 'count' => count( $files ), 'message' => sprintf( /* translators: %d */ __( 'وضعیت %d فایل به‌عنوان مرجع ثبت شد.', 'hoosh-seo' ), count( $files ) ) ) );
	}

	/* -------------------- AI -------------------- */

	/**
	 * AI overview.
	 *
	 * @return \WP_REST_Response
	 */
	public static function ai_overview() {
		return rest_ensure_response(
			array_merge(
				Gateway::overview(),
				array(
					'providers' => Providers::ready(),
					'tasks'     => Tasks::grouped(),
					'spend'     => Gateway::spend_summary(),
				)
			)
		);
	}

	/**
	 * Run one task now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ai_run( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response(
			Tasks::run(
				(string) ( $data['task'] ?? 'titles' ),
				(int) ( $data['post_id'] ?? 0 ),
				(array) ( $data['payload'] ?? array() ),
				! empty( $data['dry_run'] )
			)
		);
	}

	/**
	 * Queue tasks for many posts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ai_queue( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response(
			Gateway::queue_many(
				(string) ( $data['task'] ?? 'titles' ),
				(array) ( $data['post_ids'] ?? array() ),
				(array) ( $data['payload'] ?? array() )
			)
		);
	}

	/**
	 * Process the queue.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ai_process( $request ) {
		return rest_ensure_response(
			Gateway::process_queue(
				array(
					'limit'      => (int) $request['limit'],
					'task'       => sanitize_key( (string) $request['task'] ),
					'only_batch' => sanitize_text_field( (string) $request['batch'] ),
				)
			)
		);
	}

	/**
	 * Jobs table.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ai_jobs( $request ) {
		return rest_ensure_response(
			Gateway::jobs(
				array(
					'status'  => sanitize_key( (string) $request['status'] ),
					'task'    => sanitize_key( (string) $request['task'] ),
					'post_id' => (int) $request['post_id'],
					'per'     => (int) $request['per'],
					'paged'   => (int) $request['paged'],
				)
			)
		);
	}

	/**
	 * Retry failed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ai_retry( $request ) {
		return rest_ensure_response( Gateway::retry_failed( array( 'ids' => (array) $request['ids'] ) ) );
	}

	/**
	 * Cancel jobs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ai_cancel( $request ) {
		return rest_ensure_response( array( 'ok' => true, 'canceled' => Gateway::cancel( (array) $request['ids'] ) ) );
	}

	/**
	 * Provider connection test.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ai_test( $request ) {
		return rest_ensure_response( Providers::test( sanitize_key( (string) $request['driver'] ) ) );
	}

	/**
	 * Store provider keys.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ai_keys( $request ) {
		$data = (array) $request->get_json_params();
		$keys = Providers::set_keys( (array) ( $data['keys'] ?? $data ) );
		return rest_ensure_response( array( 'ok' => true, 'keys' => $keys, 'message' => __( 'کلیدها ذخیره شدند.', 'hoosh-seo' ) ) );
	}

	/**
	 * Assistant chat.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function ai_chat( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response(
			Gateway::chat(
				(string) ( $data['message'] ?? '' ),
				(array) ( $data['history'] ?? array() ),
				(array) ( $data['context'] ?? array() )
			)
		);
	}

	/* -------------------- geo / woo / analytics / speed -------------------- */

	/**
	 * GEO payload.
	 *
	 * @return \WP_REST_Response
	 */
	public static function geo_get() {
		return rest_ensure_response( Geo::preview() );
	}

	/**
	 * Save llms.txt.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function geo_save( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response( Geo::save( $data ) );
	}

	/**
	 * WooCommerce summary.
	 *
	 * @return \WP_REST_Response
	 */
	public static function woo_summary() {
		return rest_ensure_response( Woo::summary() );
	}

	/**
	 * Woo bulk fix.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function woo_fix( $request ) {
		$data = (array) $request->get_json_params();
		$action = sanitize_key( (string) ( $data['action'] ?? '' ) );
		$ids    = array_values( array_filter( array_map( 'absint', (array) ( $data['ids'] ?? array() ) ) ) );

		if ( $ids ) {
			$results = array();
			$changed = 0;
			foreach ( array_slice( $ids, 0, 200 ) as $id ) {
				$out       = Woo::fix( (int) $id, $action );
				$results[] = array( 'post_id' => (int) $id, 'ok' => ! empty( $out['ok'] ), 'message' => (string) ( $out['message'] ?? '' ) );
				$changed  += empty( $out['ok'] ) ? 0 : 1;
			}
			return rest_ensure_response(
				array(
					'ok'      => (bool) $changed,
					'changed' => $changed,
					'results' => $results,
					'message' => sprintf( /* translators: %d count */ __( '%d محصول به‌روز شد.', 'hoosh-seo' ), $changed ),
				)
			);
		}

		return rest_ensure_response( Woo::fix( (int) ( $data['post_id'] ?? 0 ), $action ) );
	}

	/**
	 * Search console / GA summary.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function analytics_summary( $request ) {
		$days   = (int) ( $request['days'] ? $request['days'] : 28 );
		$search = sanitize_text_field( (string) $request['search'] );
		$which  = sanitize_key( (string) $request['view'] );

		if ( 'queries' === $which ) {
			return rest_ensure_response( array_merge( array( 'ok' => true ), Analytics::top_queries( array( 'days' => $days, 'limit' => 200, 'search' => $search ) ) ) );
		}
		if ( 'pages' === $which ) {
			return rest_ensure_response( array_merge( array( 'ok' => true ), Analytics::top_pages( array( 'days' => $days, 'limit' => 200, 'sort' => sanitize_key( (string) $request['sort'] ) ) ) ) );
		}
		if ( 'alerts' === $which ) {
			return rest_ensure_response( array_merge( array( 'ok' => true ), Analytics::alerts() ) );
		}

		$summary = Analytics::summary( $days );
		return rest_ensure_response(
			array_merge(
				(array) $summary,
				array(
					'ok'      => true,
					'days'    => $days,
					'status'  => Analytics::status(),
					'ga4'     => Analytics::ga4( $days ),
					'queries' => array_slice( Analytics::top( 'query', $days, 12 ), 0, 12 ),
					'pages'   => array_slice( Analytics::top( 'page', $days, 12 ), 0, 12 ),
					'devices' => Analytics::dimension( 'device' ),
					'countries' => Analytics::dimension( 'country' ),
					'alerts'  => Analytics::alerts(),
					'setup'   => array(
						'connect_url'   => wp_nonce_url( admin_url( 'admin-post.php?action=hoosh_gsc_connect' ), 'hoosh_seo_tools' ),
						'disconnect_url' => wp_nonce_url( admin_url( 'admin-post.php?action=hoosh_gsc_disconnect' ), 'hoosh_seo_tools' ),
					),
				)
			)
		);
	}

	/**
	 * Import a Search Console CSV export (raw text from the Studio).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function analytics_import( $request ) {
		$data = (array) $request->get_json_params();
		if ( ! empty( $data['content'] ) ) {
			$data['content'] = (string) $data['content'];
		}
		return rest_ensure_response( Analytics::import( $data ) );
	}

	/**
	 * Refresh analytics caches.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function analytics_refresh( $request ) {
		return rest_ensure_response(
			Analytics::refresh(
				array(
					'days'  => (int) ( $request['days'] ? $request['days'] : 28 ),
					'force' => true,
				)
			)
		);
	}

	/**
	 * PageSpeed run for one URL or a sample.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function analytics_psi( $request ) {
		$url = (string) $request['url'];
		if ( $url ) {
			return rest_ensure_response( Analytics::psi( esc_url_raw( $url ), sanitize_key( (string) $request['device'] ) ) );
		}
		return rest_ensure_response(
			Analytics::psi_sample(
				array(
					'limit'    => (int) ( $request['limit'] ? $request['limit'] : 10 ),
					'strategy' => sanitize_key( (string) $request['device'] ),
				)
			)
		);
	}

	/**
	 * Speed checks.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function speed_check( $request ) {
		unset( $request );
		return rest_ensure_response(
			array_merge(
				Speed::checks(),
				array(
					'ok'       => true,
					'summary'  => Speed::summary(),
					'estimate' => Speed::estimate(),
				)
			)
		);
	}

	/**
	 * Toggle speed tweaks.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function speed_toggle( $request ) {
		$data = (array) $request->get_json_params();
		if ( isset( $data['run'] ) && $data['run'] ) {
			return rest_ensure_response( Speed::run( array( 'scope' => sanitize_key( (string) ( $data['scope'] ?? 'safe' ) ) ) ) );
		}
		return rest_ensure_response( Speed::toggle( $data ) );
	}

	/* -------------------- automation / migration / reports / tools -------------------- */

	/**
	 * Run autopilot now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function automation_run( $request ) {
		$data = (array) $request->get_json_params();
		$jobs = array_values( array_filter( array_map( 'sanitize_key', (array) ( $data['jobs'] ?? $request['jobs'] ) ) ) );

		if ( $jobs ) {
			$out = array();
			foreach ( $jobs as $job ) {
				$out[ $job ] = Automation::run_job(
					$job,
					array(
						'limit' => (int) ( $data['limit'] ?? 0 ),
						'scope' => 'manual',
						'dry'   => (bool) ( $data['dry_run'] ?? false ),
					)
				);
			}
			return rest_ensure_response( array( 'ok' => true, 'results' => $out, 'status' => Automation::status() ) );
		}

		$scope = sanitize_key( (string) ( $data['scope'] ?? $request['scope'] ) );
		return rest_ensure_response( Automation::run( $scope ? $scope : 'manual' ) );
	}

	/**
	 * Autopilot status.
	 *
	 * @return \WP_REST_Response
	 */
	public static function automation_status() {
		return rest_ensure_response(
			array_merge(
				Automation::status(),
				array(
					'ok'      => true,
					'pending' => Automation::pending(),
				)
			)
		);
	}

	/**
	 * Suggested changes awaiting review.
	 *
	 * @return \WP_REST_Response
	 */
	public static function automation_pending() {
		return rest_ensure_response( array( 'ok' => true, 'rows' => Automation::pending() ) );
	}

	/**
	 * Apply or reject one suggestion.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function automation_apply( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response(
			Automation::review(
				(int) ( $data['job'] ?? 0 ),
				! isset( $data['apply'] ) || ! empty( $data['apply'] ),
				(array) ( $data['fields'] ?? array() )
			)
		);
	}

	/**
	 * Undo a whole automation batch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function automation_undo( $request ) {
		return rest_ensure_response( Automation::undo( sanitize_text_field( (string) $request['batch'] ) ) );
	}

	/* -------------------- autonomous agent -------------------- */

	/**
	 * Agent overview for Mission Control.
	 *
	 * @return \WP_REST_Response
	 */
	public static function agent_status() {
		Agent::instance();
		return rest_ensure_response( array( 'ok' => true ) + Agent::status() );
	}

	/**
	 * Measure the site without changing anything.
	 *
	 * @return \WP_REST_Response
	 */
	public static function agent_diagnose() {
		$out = Insight::diagnose();
		return rest_ensure_response( array( 'ok' => true ) + $out );
	}

	/**
	 * Show what the agent would do, without doing it.
	 *
	 * @return \WP_REST_Response
	 */
	public static function agent_plan() {
		$ctx  = Guard::context( 0, 'preview' );
		$plan = Planner::plan( Insight::diagnose(), $ctx );
		return rest_ensure_response( array( 'ok' => true ) + $plan );
	}

	/**
	 * Run the agent now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function agent_run( $request ) {
		Agent::instance();
		$data   = (array) $request->get_json_params();
		$report = Orchestrator::run(
			array(
				'triggered_by' => 'manual',
				'mode'         => 'manual',
				'dry_run'      => empty( $data['dry_run'] ) ? null : true,
				'goal'         => isset( $data['goal'] ) ? (string) $data['goal'] : '',
			)
		);
		return rest_ensure_response( ( array( 'ok' => ! empty( $report['ok'] ) ) ) + $report );
	}

	/**
	 * Kill switch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function agent_halt( $request ) {
		$data = (array) $request->get_json_params();
		$halt = empty( $data['halt'] ) ? false : true;
		Guard::set_halted( $halt );
		if ( $halt ) {
			$next = wp_next_scheduled( Agent::HOOK );
			if ( $next ) {
				wp_unschedule_event( $next, Agent::HOOK );
			}
		} else {
			Agent::sync_schedule();
		}
		return rest_ensure_response( array( 'ok' => true, 'halted' => $halt ) );
	}

	/**
	 * Run history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function agent_runs( $request ) {
		$limit = max( 1, min( 100, (int) $request->get_param( 'limit' ) ) );
		return rest_ensure_response( array( 'ok' => true, 'runs' => Memory::runs( $limit ) ) );
	}

	/**
	 * One run with its step ledger.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function agent_run_detail( $request ) {
		return rest_ensure_response( Agent::run_detail( (int) $request['id'] ) );
	}

	/**
	 * Undo one recorded step.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function agent_revert( $request ) {
		return rest_ensure_response( Agent::revert( (int) $request['step'] ) );
	}

	/**
	 * Articles the pipeline produced.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function agent_articles( $request ) {
		return rest_ensure_response(
			array(
				'ok'    => true,
				'rows'  => Pipeline::ledger( (string) $request->get_param( 'status' ), 40 ),
				'skill' => Skills::get( 'write_article' ),
			)
		);
	}

	/**
	 * Write one article on demand.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function agent_article( $request ) {
		$data    = (array) $request->get_json_params();
		$keyword = isset( $data['keyword'] ) ? sanitize_text_field( (string) $data['keyword'] ) : '';
		if ( mb_strlen( $keyword ) < 3 ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'کلیدواژه را وارد کنید.', 'hoosh-seo' ) ) );
		}
		$out = Pipeline::article(
			array(
				'keyword' => $keyword,
				'intent'  => isset( $data['intent'] ) ? sanitize_key( (string) $data['intent'] ) : '',
				'words'   => isset( $data['words'] ) ? (int) $data['words'] : 0,
			),
			0
		);
		return rest_ensure_response( $out );
	}

	/**
	 * Content opportunities the agent found.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function agent_opportunities( $request ) {
		$limit = max( 1, min( 40, (int) $request->get_param( 'limit' ) ) );
		return rest_ensure_response( array( 'ok' => true, 'rows' => Insight::opportunities( $limit ), 'sources' => Insight::last_sources() ) );
	}

	/**
	 * Importable plugins.
	 *
	 * @return \WP_REST_Response
	 */
	public static function migration_list() {
		return rest_ensure_response( Migration::sources() );
	}

	/**
	 * Run an import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function migration_run( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response( Migration::run( $data ) );
	}

	/**
	 * What an import would change.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function migration_preview( $request ) {
		return rest_ensure_response( Migration::preview( sanitize_key( (string) $request['source'] ), (int) $request['limit'] ) );
	}

	/**
	 * Undo an import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function migration_undo( $request ) {
		return rest_ensure_response( Migration::undo( sanitize_text_field( (string) $request['batch'] ) ) );
	}

	/**
	 * Delete the meta the *other* plugin wrote, after a verified import.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function migration_cleanup( $request ) {
		$key     = sanitize_key( (string) $request['source'] );
		$catalog = Migration::sources();
		if ( ! is_array( $catalog ) || ! $catalog ) {
			return new \WP_Error( 'hs-no-sources', __( 'فهرست مبدأ خالی است.', 'hoosh-seo' ), array( 'status' => 400 ) );
		}
		if ( $key && ! isset( $catalog[ $key ] ) ) {
			return new \WP_Error( 'hs-no-source', __( 'این مبدأ شناخته شده نیست.', 'hoosh-seo' ), array( 'status' => 400 ) );
		}
		$counts = array();
		foreach ( $catalog as $name => $def ) {
			if ( $key && $key !== $name ) {
				continue;
			}
			$counts[ $name ] = (int) Migration::cleanup( (array) $def );
		}
		$total = array_sum( $counts );
		return rest_ensure_response(
			array(
				'ok'         => true,
				'deleted'    => $total,
				'per_source' => $counts,
				'message'    => $total
					? sprintf( /* translators: %d number of rows. */ __( '%d ردیف از داده افزونه مبدأ پاک شد.', 'hoosh-seo' ), $total )
					: __( 'چیزی پاک نشد. برای این کار، گزینهٔ «حذف داده مبدأ» را در تنظیمات → مهاجرت روشن کنید.', 'hoosh-seo' ),
			)
		);
	}

	/**
	 * HTML report (string, rendered by the Studio in an iframe/srcdoc).
	 *
	 * @return \WP_REST_Response
	 */
	public static function report_html() {
		$payload = Reports::report_payload();
		return rest_ensure_response(
			array(
				'html'    => Reports::render_html( $payload ),
				'payload' => $payload,
				'url'     => add_query_arg( 'hoosh_export', 'report', home_url( '/' ) ),
			)
		);
	}

	/**
	 * Email the report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function report_email( $request ) {
		$data = (array) $request->get_json_params();
		return rest_ensure_response( Reports::email( $data ) );
	}

	/**
	 * Flush caches.
	 *
	 * @return \WP_REST_Response
	 */
	public static function tools_flush() {
		Helpers::cache_flush_all();
		Sitemap::flush();
		Redirects::flush();
		Links::flush();
		return rest_ensure_response( array( 'ok' => true, 'message' => __( 'کش‌ها پاک شدند.', 'hoosh-seo' ) ) );
	}

	/**
	 * Server info.
	 *
	 * @return \WP_REST_Response
	 */
	public static function tools_system() {
		global $wpdb;
		return rest_ensure_response(
			array(
				'php'      => PHP_VERSION,
				'wp'       => $GLOBALS['wp_version'],
				'mysql'    => $wpdb->db_version(),
				'multibyte' => function_exists( 'mb_strlen' ),
				'xml'      => class_exists( 'DOMDocument' ),
				'curl'     => function_exists( 'curl_init' ),
				'exif'     => function_exists( 'exif_read_data' ),
				'timezone' => wp_timezone_string(),
				'locale'   => get_locale(),
				'memory'   => (string) ini_get( 'memory_limit' ),
				'timeout'  => (int) ini_get( 'max_execution_time' ),
				'uploads'  => wp_upload_dir()['path'] ?? '',
				'object_cache' => wp_using_ext_object_cache(),
				'cron'     => Cron::status(),
				'tables'   => Database::stats(),
				'mode'     => array(
					'debug'     => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
					'async'     => (bool) hoosh_seo()->settings->get( 'advanced.async_analysis', true ),
					'health'    => (array) get_option( 'hoosh_site_health', array() ),
				),
			)
		);
	}

	/**
	 * Cron detail + manual trigger.
	 *
	 * GET (or no action) returns the schedule. POST runs a job now or
	 * re-installs the tables, which is what the Tools screen buttons send.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function tools_cron( $request = null ) {
		$action = $request ? sanitize_key( (string) $request->get_param( 'action' ) ) : '';
		$job    = $request ? sanitize_key( (string) $request->get_param( 'job' ) ) : '';

		if ( 'install' === $action ) {
			Database::install();
			Cron::schedule_all();
			return rest_ensure_response(
				array(
					'ok'     => true,
					'action' => 'install',
					'tables' => Database::stats(),
					'status' => Cron::status(),
				)
			);
		}

		if ( 'schedule' === $action ) {
			Cron::clear_all();
			Cron::schedule_all();
			return rest_ensure_response( array( 'ok' => true, 'action' => 'schedule', 'status' => Cron::status() ) );
		}

		if ( 'run' === $action ) {
			$ran = array();
			$map = array(
				'hoosh_seo_batch'  => 'run_batch',
				'hoosh_seo_hourly' => 'run_hourly',
				'hoosh_seo_daily'  => 'run_daily',
				'hoosh_seo_weekly' => 'run_weekly',
			);
			if ( '' === $job ) {
				$job = 'hoosh_seo_batch';
			}
			// Tolerate the "daily_batch" spelling used by older builds of the app.
			$job = str_replace( 'hoosh_seo_daily_batch', 'hoosh_seo_batch', $job );

			if ( isset( $map[ $job ] ) && method_exists( 'HooshSEO\Cron', $map[ $job ] ) ) {
				call_user_func( array( 'HooshSEO\Cron', $map[ $job ] ) );
				$ran[] = $job;
			}

			return rest_ensure_response(
				array(
					'ok'     => ! empty( $ran ),
					'action' => 'run',
					'ran'    => $ran,
					'status' => Cron::status(),
				)
			);
		}

		return rest_ensure_response( Cron::status() );
	}

	/* -------------------- post-scoped -------------------- */

	/**
	 * Read the SEO store for a post (block editor + Studio drawer).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function post_get( $request ) {
		$post_id = (int) $request['id'];
		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'hs-no-post', __( 'نوشته پیدا نشد.', 'hoosh-seo' ), array( 'status' => 404 ) );
		}
		$data = Meta::for_post( $post_id );
		$data['analysis'] = Content::analyze( $post_id );
		$data['links']    = array(
			'suggestions' => Links::suggest( $post_id, 8 ),
			'related'     => Links::related( $post_id, 6 ),
		);
		$data['schema']   = Schema::preview( $post_id );
		$data['index']    = IndexManager::state( $post_id );
		$data['og']       = OpenGraph::preview_data( $post_id );
		$data['redirect'] = Redirects::for_post( $post_id );
		$data['keywords'] = KeywordResearch::for_post( $post_id );
		$data['audit']    = array( 'recent' => Audit::listing( array( 'post_id' => $post_id, 'per' => 10 ) ) );

		/**
		 * Filter the per-post REST payload.
		 *
		 * @param array $data Payload.
		 * @param int   $post_id Post ID.
		 */
		return rest_ensure_response( apply_filters( 'hoosh_seo_rest_post_payload', $data, $post_id ) );
	}

	/**
	 * Save the SEO store for a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function post_save( $request ) {
		$post_id = (int) $request['id'];
		$data    = (array) $request->get_json_params();
		$stored  = Meta::save( $post_id, $data );
		return rest_ensure_response(
			array(
				'ok'       => true,
				'post_id'  => $post_id,
				'saved'    => array_keys( (array) $stored ),
				'analysis' => Content::analyze( $post_id ),
				'store'    => Meta::for_post( $post_id ),
			)
		);
	}
	/**
	 * Serve a bundled markdown doc as HTML (Studio → راهنما).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function docs( $request ) {
		$name = sanitize_key( (string) $request['name'] );
		$allowed = array( 'install', 'readme' );
		if ( ! in_array( $name, $allowed, true ) ) {
			return new \WP_Error( 'hs-no-doc', __( 'این سند موجود نیست.', 'hoosh-seo' ), array( 'status' => 404 ) );
		}
		$file = 'readme' === $name ? HOOSH_SEO_DIR . 'readme.txt' : HOOSH_SEO_DIR . 'docs/INSTALL.md';
		if ( ! is_readable( $file ) ) {
			return new \WP_Error( 'hs-no-doc', __( 'فایل راهنما روی سرور نیست.', 'hoosh-seo' ), array( 'status' => 404 ) );
		}
		$raw = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return rest_ensure_response(
			array(
				'ok'      => true,
				'name'    => $name,
				'bytes'   => strlen( $raw ),
				'updated' => (string) date_i18n( 'c', (int) filemtime( $file ) ),
				'html'    => self::markdown( $raw ),
			)
		);
	}

	/**
	 * Very small markdown subset: headings, lists, tables, fenced code, inline
	 * code, bold, links. Enough for the bundled docs, no dependency.
	 *
	 * @param string $text Markdown.
	 * @return string HTML (escaped).
	 */
	protected static function markdown( $text ) {
		$text   = str_replace( array( "\r\n", "\t" ), array( "\n", '    ' ), (string) $text );
		$lines  = explode( "\n", $text );
		$out    = array();
		$type   = '';
		$in_code = false;
		$close = function () use ( &$type, &$out ) {
			if ( 'ul' === $type || 'ol' === $type ) {
				$out[] = '</' . $type . '>';
			} elseif ( 'p' === $type ) {
				$out[] = '</p>';
			} elseif ( 'table' === $type ) {
				$out[] = '</tbody></table>';
			}
			$type = '';
		};
		foreach ( $lines as $line ) {
			if ( 0 === strpos( trim( $line ), '```' ) ) {
				if ( $in_code ) {
					$out[]  = '</code></pre>';
					$in_code = false;
				} else {
					$close();
					$out[]   = '<pre><code>';
					$in_code = true;
				}
				continue;
			}
			if ( $in_code ) {
				$out[] = esc_html( $line );
				continue;
			}
			$trim = trim( $line );
			if ( '' === $trim ) {
				$close();
				continue;
			}
			if ( preg_match( '/^(#{1,4})\s+(.*)$/', $trim, $m ) ) {
				$close();
				$lvl = strlen( $m[1] );
				$out[] = '<h' . $lvl . '>' . self::md_inline( $m[2] ) . '</h' . $lvl . '>';
				continue;
			}
			if ( preg_match( '/^[-*]\s+(.*)$/', $trim, $m ) ) {
				if ( 'ul' !== $type ) {
					$close();
					$out[] = '<ul>';
					$type  = 'ul';
				}
				$out[] = '<li>' . self::md_inline( $m[1] ) . '</li>';
				continue;
			}
			if ( preg_match( '/^\d+[.)]\s+(.*)$/', $trim, $m ) ) {
				if ( 'ol' !== $type ) {
					$close();
					$out[] = '<ol>';
					$type  = 'ol';
				}
				$out[] = '<li>' . self::md_inline( $m[1] ) . '</li>';
				continue;
			}
			if ( false !== strpos( $trim, '|' ) && ! preg_match( '/^\|?[-: |]+\|?$/', $trim ) ) {
				$cells = array_values( array_filter( array_map( 'trim', explode( '|', $trim ) ), 'strlen' ) );
				if ( 'table' !== $type ) {
					$close();
					$out[] = '<table class="hs-table__simple"><thead><tr>';
					foreach ( $cells as $cell ) {
						$out[] = '<th>' . self::md_inline( $cell ) . '</th>';
					}
					$out[] = '</tr></thead><tbody>';
					$type  = 'table';
					continue;
				}
				$out[] = '<tr>';
				foreach ( $cells as $cell ) {
					$out[] = '<td>' . self::md_inline( $cell ) . '</td>';
				}
				$out[] = '</tr>';
				continue;
			}
			if ( preg_match( '/^\[([^\]]+)\]:\s*(\S+)/', $trim, $m ) ) {
				continue;
			}
			if ( 'p' !== $type ) {
				$close();
				$out[] = '<p>';
				$type  = 'p';
			}
			$out[] = self::md_inline( $trim );
		}
		$close();
		if ( $in_code ) {
			$out[] = '</code></pre>';
		}
		return implode( "\n", $out );
	}

	/**
	 * Inline markdown: `code`, **bold**, [text](url).
	 *
	 * @param string $line Raw line.
	 * @return string
	 */
	protected static function md_inline( $line ) {
		$line = (string) $line;
		$line = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $line );
		$line = preg_replace( '/\*\*([^*]+)\*\*/', '<b>$1</b>', $line );
		$line = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $line );
		// Keep anything that looks like markup out, then re-allow the tags we just made.
		$line = wp_kses(
			$line,
			array(
				'code' => array(),
				'b'    => array(),
				'a'    => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		);
		return $line;
	}
}

