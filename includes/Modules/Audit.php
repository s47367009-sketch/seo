<?php
/**
 * Change tracker: audit log, snapshots, one-click rollback, file integrity.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Class Audit
 */
final class Audit {

	/**
	 * Singleton.
	 *
	 * @var Audit|null
	 */
	private static $instance = null;

	/**
	 * Post meta keys worth snapshotting.
	 *
	 * @var array
	 */
	protected static $tracked_meta = array(
		'_hs_title',
		'_hs_description',
		'_hs_focus_keywords',
		'_hs_canonical',
		'_hs_robots',
		'_hs_redirect',
		'_hs_schema',
		'_hs_faq',
		'_hs_social',
		'_hs_primary_keyword',
		'post_name',
		'post_title',
		'_thumbnail_id',
	);

	/**
	 * Options worth snapshotting.
	 *
	 * @var array
	 */
	protected static $tracked_options = array(
		'blogname',
		'description',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'blog_public',
		'permalink_structure',
		'hoosh_seo_settings',
		'hoosh_robots_txt',
		'hoosh_htaccess',
	);

	/**
	 * Get instance.
	 *
	 * @return Audit
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
		if ( ! \hoosh_seo()->settings->on( 'audit.enabled' ) ) {
			return;
		}

		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ) );
		add_action( 'wp_logout', array( __CLASS__, 'on_logout' ) );
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
		add_action( 'delete_user', array( __CLASS__, 'on_user_delete' ), 10, 3 );
		add_action( 'switch_theme', array( __CLASS__, 'on_switch_theme' ), 10, 3 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_changed' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_changed' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'on_update' ), 10, 2 );
		add_action( 'edit_page_tree', array( __CLASS__, 'on_page_tree' ) );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'on_nav_menu' ), 10, 1 );
		add_action( 'wp_loaded', array( __CLASS__, 'resume_interrupted' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_flag_interruption' ) );

		if ( \hoosh_seo()->settings->on( 'audit.mail_digest' ) ) {
			add_action( 'hoosh_seo_daily_digest', array( __CLASS__, 'email_digest' ) );
		}
	}

	/**
	 * Log one change.
	 *
	 * @param string $module Module slug (meta, settings, redirects, links...).
	 * @param string $action Action slug.
	 * @param array  $data   {post_id, url, before, after, batch_id, user_id, label}.
	 * @return int
	 */
	public static function log( $module, $action, $data = array() ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'audit.enabled' ) || self::ignored( $module, $data ) ) {
			return 0;
		}
		if ( ! Database::exists( 'changelog' ) ) {
			return 0;
		}

		$module = substr( sanitize_key( (string) $module ), 0, 40 );
		$action = substr( sanitize_key( (string) $action ), 0, 40 );

		$before = isset( $data['before'] ) ? $data['before'] : array();
		$after  = isset( $data['after'] ) ? $data['after'] : array();
		$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;

		if ( $post_id && ! $before ) {
			$before = self::snapshot_post( $post_id );
		}

		$row = array(
			'scope'       => $module,
			'action'      => $action,
			'post_id'     => $post_id,
			'url'         => isset( $data['url'] ) && $data['url']
				? mb_substr( esc_url_raw( (string) $data['url'] ), 0, 400 )
				: ( $post_id ? (string) get_permalink( $post_id ) : '' ),
			'before_data' => self::encode( $before, (int) $settings->get( 'audit.snapshot_limit', 20000 ) ),
			'after_data'  => self::encode( $after, (int) $settings->get( 'audit.snapshot_limit', 20000 ) ),
			'batch_id'    => isset( $data['batch_id'] ) ? substr( sanitize_text_field( (string) $data['batch_id'] ), 0, 64 ) : '',
			'user_id'     => isset( $data['user_id'] ) ? (int) $data['user_id'] : (int) get_current_user_id(),
			'created_at'  => current_time( 'mysql', true ),
			'reverted'    => 0,
			'applied'     => 1,
			'note'        => isset( $data['label'] ) ? mb_substr( sanitize_text_field( (string) $data['label'] ), 0, 190 ) : self::default_label( $module, $action, $post_id ),
		);

		global $wpdb;
		$wpdb->insert( Database::table( 'changelog' ), $row ); // phpcs:ignore
		$id = (int) $wpdb->insert_id;

		// Keep the table from growing forever.
		if ( $id % 200 === 0 ) {
			self::schedule_prune();
		}

		/**
		 * Fires after an audit row is written.
		 *
		 * @param int   $id   Row id.
		 * @param array $row  Row.
		 */
		do_action( 'hoosh_seo_audit_logged', $id, $row );

		return $id;
	}

	/**
	 * Human label for a change row.
	 *
	 * @param string $module Module.
	 * @param string $action Action.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	protected static function default_label( $module, $action, $post_id = 0 ) {
		$labels = array(
			'create'         => __( 'ایجاد شد', 'hoosh-seo' ),
			'update'         => __( 'به‌روزرسانی', 'hoosh-seo' ),
			'delete'         => __( 'حذف شد', 'hoosh-seo' ),
			'insert-content' => __( 'درج لینک در محتوا', 'hoosh-seo' ),
			'bulk_fill'      => __( 'پرکردن دسته‌جمعی متن جایگزین', 'hoosh-seo' ),
			'rename'         => __( 'تغییر نام فایل', 'hoosh-seo' ),
			'bulk'           => __( 'عملیات گروهی', 'hoosh-seo' ),
			'import'         => __( 'درون‌ریزی', 'hoosh-seo' ),
			'prune'          => __( 'پاک‌سازی', 'hoosh-seo' ),
		);
		$suffix = $post_id ? ' — ' . get_the_title( $post_id ) : '';
		$text   = isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
		return mb_substr( $module . ': ' . $text . $suffix, 0, 190 );
	}

	/**
	 * Encode + truncate a snapshot.
	 *
	 * @param mixed $value Value.
	 * @param int   $limit Bytes.
	 * @return string
	 */
	protected static function encode( $value, $limit = 20000 ) {
		if ( ! $value ) {
			return '';
		}
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return '';
		}
		if ( strlen( $json ) > $limit ) {
			$json = substr( $json, 0, $limit );
			$json = substr( $json, 0, (int) strrpos( $json, ',' ) );
			$json = $json ? $json . '],"_truncated":true]' : '';
		}
		return (string) $json;
	}

	/**
	 * Ignore rules: module / post type / meta key / user.
	 *
	 * @param string $module Module.
	 * @param array  $data   Payload.
	 * @return bool
	 */
	public static function ignored( $module, $data = array() ) {
		$settings = \hoosh_seo()->settings;
		$rules    = (array) $settings->get( 'audit.ignore', array() );
		if ( ! $rules ) {
			return false;
		}

		$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				if ( is_string( $rule ) && $rule === $module ) {
					return true;
				}
				continue;
			}
			if ( ! empty( $rule['module'] ) && $rule['module'] !== $module ) {
				continue;
			}
			if ( ! empty( $rule['action'] ) && ! empty( $data['action'] ) && $rule['action'] !== $data['action'] ) {
				continue;
			}
			if ( ! empty( $rule['post_type'] ) && $post_id && get_post_type( $post_id ) !== $rule['post_type'] ) {
				continue;
			}
			if ( ! empty( $rule['post_type'] ) && $post_id && get_post_type( $post_id ) === $rule['post_type'] ) {
				return true;
			}
			if ( ! empty( $rule['user_id'] ) && $post_id ) {
				continue;
			}
			if ( isset( $rule['module'] ) && '' === $rule['module'] ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * Ignore-rule CRUD.
	 *
	 * @param array $rules Rules.
	 * @return array
	 */
	public static function set_ignore_rules( $rules ) {
		$clean = array();
		foreach ( (array) $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$entry = array(
				'module'    => sanitize_key( (string) ( $rule['module'] ?? '' ) ),
				'action'    => sanitize_key( (string) ( $rule['action'] ?? '' ) ),
				'post_type' => sanitize_key( (string) ( $rule['post_type'] ?? '' ) ),
				'note'      => sanitize_text_field( (string) ( $rule['note'] ?? '' ) ),
			);
			if ( $entry['module'] || $entry['action'] || $entry['post_type'] ) {
				$clean[] = $entry;
			}
		}
		\hoosh_seo()->settings->set( array( 'audit' => array( 'ignore' => $clean ) ), true );
		return $clean;
	}

	/**
	 * Snapshot of tracked meta for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function snapshot_post( $post_id ) {
		$out = array();
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $out;
		}
		foreach ( self::$tracked_meta as $key ) {
			if ( 0 === strpos( $key, 'post_' ) ) {
				$out[ $key ] = (string) $post->{$key};
				continue;
			}
			$value = get_post_meta( (int) $post_id, $key, true );
			if ( '' === $value || array() === $value || null === $value ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Snapshot site-level options (before a settings save).
	 *
	 * @return array
	 */
	public static function quick_snapshot_options() {
		$out = array();
		foreach ( self::$tracked_options as $key ) {
			$value = get_option( $key );
			if ( is_array( $value ) ) {
				$value = array_slice( $value, 0, 20, true );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Dashboard/Studio KPI snapshot.
	 *
	 * @return array
	 */
	public static function quick_snapshot() {
		$cached = Helpers::cache(
			'kpi-snapshot',
			function () {
				global $wpdb;
				$out = array(
					'score'      => 0,
					'critical'   => 0,
					'warnings'   => 0,
					'redirects'  => 0,
					'404'        => 0,
					'keywords'   => 0,
					'changes_7d' => 0,
					'broken'     => 0,
					'coverage'   => 0,
					'theme'      => array(),
				);

				if ( Database::exists( 'pages' ) ) {
					$row = $wpdb->get_row( 'SELECT AVG(score) a, AVG(seo_score) s, COUNT(*) c FROM ' . Database::table( 'pages' ), ARRAY_A ); // phpcs:ignore
					$out['score'] = $row && $row['a'] !== null ? (int) round( (float) $row['a'] ) : 0;
					$out['pages'] = (int) ( $row['c'] ?? 0 );
					$out['critical'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'pages' ) . ' WHERE score < 40' ); // phpcs:ignore
					$out['warnings'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'pages' ) . ' WHERE score BETWEEN 40 AND 74' ); // phpcs:ignore
				}

				if ( Database::exists( 'redirects' ) ) {
					$out['redirects'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'redirects' ) . " WHERE status = 'active'" ); // phpcs:ignore
				}
				if ( Database::exists( 'notfound' ) ) {
					$out['404'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'notfound' ) . " WHERE state = 'new'" ); // phpcs:ignore
				}
				if ( Database::exists( 'keywords' ) ) {
					$out['keywords'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'keywords' ) . " WHERE state = 'tracking'" ); // phpcs:ignore
				}
				if ( Database::exists( 'links' ) ) {
					$out['broken'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'links' ) . " WHERE status = 'broken'" ); // phpcs:ignore
				}
				if ( Database::exists( 'changelog' ) ) {
					$out['changes_7d'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'changelog' ) . ' WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)' ); // phpcs:ignore
				}

				$sitemap = Modules\Sitemap::children();
				$urls    = 0;
				foreach ( $sitemap as $child ) {
					$urls += (int) ( $child['count'] ?? 0 );
				}
				$published = wp_count_posts( 'post' );
				$total     = $published ? (int) $published->publish : 0;
				$out['coverage'] = $total ? min( 100, (int) round( ( $urls / max( 1, $total ) ) * 100 ) ) : 0;

				$theme = wp_get_theme();
				$out['theme'] = array(
					'name'    => $theme->get( 'Name' ),
					'version' => $theme->get( 'Version' ),
					'blocks'  => function_exists( 'wp_is_full_site' ) && wp_is_full_site() ? 1 : 0,
				);

				return $out;
			},
			300
		);

		return is_array( $cached ) ? $cached : array(
			'score' => 0, 'critical' => 0, 'warnings' => 0, 'redirects' => 0,
			'404' => 0, 'keywords' => 0, 'changes_7d' => 0, 'broken' => 0, 'coverage' => 0, 'theme' => array(),
		);
	}

	/**
	 * Filtered log listing.
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function listing( $args = array() ) {
		global $wpdb;
		if ( ! Database::exists( 'changelog' ) ) {
			return array( 'rows' => array(), 'total' => 0, 'pages' => 0 );
		}

		$args = wp_parse_args(
			(array) $args,
			array(
				'per'   => 25,
				'paged' => 1,
				'scope'    => '',
				'action'   => '',
				'post_id'  => 0,
				'user_id'  => 0,
				'search'   => '',
				'from'     => '',
				'to'       => '',
				'batch'    => '',
				'reverted' => '',
			)
		);

		$where  = array( '1=1' );
		$params = array();
		$table  = Database::table( 'changelog' );

		if ( $args['scope'] ) {
			$where[]  = 'scope = %s';
			$params[] = sanitize_key( $args['scope'] );
		}
		if ( $args['action'] ) {
			$where[]  = 'action = %s';
			$params[] = sanitize_key( $args['action'] );
		}
		if ( (int) $args['post_id'] ) {
			$where[]  = 'post_id = %d';
			$params[] = (int) $args['post_id'];
		}
		if ( (int) $args['user_id'] ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
		}
		if ( $args['batch'] ) {
			$where[]  = 'batch_id = %s';
			$params[] = sanitize_text_field( $args['batch'] );
		}
		if ( '' !== $args['reverted'] ) {
			$where[]  = 'reverted = %d';
			$params[] = (int) (bool) $args['reverted'];
		}
		if ( $args['search'] ) {
			$where[]  = '(url LIKE %s OR label LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		if ( $args['from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( (string) $args['from'] ) );
		}
		if ( $args['to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( (string) $args['to'] ) );
		}

		$per_page = min( 500, max( 5, (int) $args['per'] ) );
		$page     = max( 1, (int) $args['paged'] );
		$offset   = ( $page - 1 ) * $per_page;

		$sql_where = implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $sql_where;
		$list_sql  = 'SELECT * FROM ' . $table . ' WHERE ' . $sql_where . ' ORDER BY id DESC LIMIT %d OFFSET %d';

		if ( $params ) {
			$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore
			$list_sql  = $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ); // phpcs:ignore
		}

		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore
		$rows  = (array) $wpdb->get_results( $list_sql, ARRAY_A ); // phpcs:ignore

		$map = array();
		foreach ( $rows as &$row ) {
			$row['id']        = (int) $row['id'];
			$row['label']     = (string) ( $row['note'] ?? '' );
			$row['post_id']   = (int) $row['post_id'];
			$row['reverted']  = (int) $row['reverted'];
			$row['can_revert'] = self::can_revert( $row );
			$row['user']      = $row['user_id'] ? get_the_author_meta( 'display_name', (int) $row['user_id'] ) : __( 'میزبان / سیستم', 'hoosh-seo' );
			$row['ago']       = Helpers::time_ago_fa( strtotime( (string) $row['created_at'] ) );
			$row['date_fa']   = Helpers::jalali_date( 'j F Y، H:i', strtotime( (string) $row['created_at'] ) );
			$diff             = self::diff( $row );
			$row['diff']      = $diff['rows'];
			$row['summary']   = $diff['summary'];
			$row['scope_label'] = self::scope_label( (string) $row['scope'] );
			$map[ (string) $row['scope'] ] = ( isset( $map[ (string) $row['scope'] ] ) ? $map[ (string) $row['scope'] ] : 0 ) + 1;
		}
		unset( $row );

		return array(
			'rows'   => $rows,
			'total'  => $total,
			'page'   => $page,
			'pages'  => (int) ceil( $total / $per_page ),
			'scopes' => $map,
		);
	}

	/**
	 * Nice module name.
	 *
	 * @param string $scope Scope.
	 * @return string
	 */
	public static function scope_label( $scope ) {
		$labels = array(
			'meta'       => __( 'متابوکس سئو', 'hoosh-seo' ),
			'settings'   => __( 'تنظیمات', 'hoosh-seo' ),
			'redirects'  => __( 'ریدایرکت‌ها', 'hoosh-seo' ),
			'links'      => __( 'لینک‌سازی داخلی', 'hoosh-seo' ),
			'images'     => __( 'تصاویر', 'hoosh-seo' ),
			'schema'     => __( 'اسکیما', 'hoosh-seo' ),
			'index'      => __( 'مدیریت ایندکس', 'hoosh-seo' ),
			'sitemap'    => __( 'سایت‌مپ', 'hoosh-seo' ),
			'robots'     => __( 'رباتز', 'hoosh-seo' ),
			'ai'         => __( 'هوش مصنوعی', 'hoosh-seo' ),
			'auth'       => __( 'ورود و کاربرها', 'hoosh-seo' ),
			'update'     => __( 'بروزرسانی‌ها', 'hoosh-seo' ),
			'menu'       => __( 'منو و ساختار', 'hoosh-seo' ),
			'notfound'   => __( 'خطای ۴۰۴', 'hoosh-seo' ),
			'woo'        => __( 'ووکامرس', 'hoosh-seo' ),
			'content'    => __( 'تحلیل محتوا', 'hoosh-seo' ),
			'analytics'  => __( 'تحلیلات', 'hoosh-seo' ),
			'automation' => __( 'اتوماسیون', 'hoosh-seo' ),
			'migration'  => __( 'مهاجرت', 'hoosh-seo' ),
		);
		return isset( $labels[ $scope ] ) ? $labels[ $scope ] : ucwords( str_replace( array( '-', '_' ), ' ', (string) $scope ) );
	}

	/**
	 * Diff two snapshots into rows.
	 *
	 * @param array $row Changelog row.
	 * @return array
	 */
	public static function diff( $row ) {
		$before = json_decode( (string) $row['before_data'], true );
		$after  = json_decode( (string) $row['after_data'], true );
		$before = is_array( $before ) ? $before : array();
		$after  = is_array( $after ) ? $after : array();

		$keys  = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		$rows  = array();
		$texts = array();

		foreach ( $keys as $key ) {
			$old = isset( $before[ $key ] ) ? $before[ $key ] : null;
			$new = isset( $after[ $key ] ) ? $after[ $key ] : null;
			if ( wp_json_encode( $old ) === wp_json_encode( $new ) ) {
				continue;
			}
			$rows[] = array(
				'key'   => (string) $key,
				'label' => self::field_label( (string) $key ),
				'from'  => self::flatten( $old ),
				'to'    => self::flatten( $new ),
			);
			$texts[] = self::field_label( (string) $key );
		}

		return array(
			'rows'    => array_slice( $rows, 0, 60 ),
			'summary' => $texts ? mb_substr( implode( '، ', array_slice( $texts, 0, 4 ) ) . ( count( $texts ) > 4 ? '…' : '' ), 0, 120 ) : '',
			'count'   => count( $rows ),
		);
	}

	/**
	 * Field label.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function field_label( $key ) {
		$labels = array(
			'_hs_title'           => __( 'عنوان سئو', 'hoosh-seo' ),
			'_hs_description'     => __( 'توضیحات متا', 'hoosh-seo' ),
			'_hs_focus_keywords'  => __( 'کلمات کلیدی', 'hoosh-seo' ),
			'_hs_canonical'       => __( 'لینک خود ارجاع', 'hoosh-seo' ),
			'_hs_robots'          => __( 'دستور ربات', 'hoosh-seo' ),
			'_hs_redirect'        => __( 'ریدایرکت این صفحه', 'hoosh-seo' ),
			'_hs_schema'          => __( 'اسکیما', 'hoosh-seo' ),
			'_hs_faq'             => __( 'پرسش‌های متداول', 'hoosh-seo' ),
			'_hs_social'          => __( 'شبکه اجتماعی', 'hoosh-seo' ),
			'post_title'          => __( 'عنوان نوشته', 'hoosh-seo' ),
			'post_name'           => __( 'نامک', 'hoosh-seo' ),
			'_thumbnail_id'       => __( 'تصویر شاخص', 'hoosh-seo' ),
			'blogname'            => __( 'نام سایت', 'hoosh-seo' ),
			'description'         => __( 'معرفی سایت', 'hoosh-seo' ),
			'show_on_front'       => __( 'صفحه نخست', 'hoosh-seo' ),
			'blog_public'         => __( 'تشویق موتور جستجو', 'hoosh-seo' ),
			'permalink_structure' => __( 'ساختار پیوند یکتا', 'hoosh-seo' ),
			'hoosh_seo_settings'  => __( 'تنظیمات هوش‌سئو', 'hoosh-seo' ),
			'hoosh_robots_txt'    => __( 'فایل رباتز', 'hoosh-seo' ),
		);
		if ( isset( $labels[ $key ] ) ) {
			return $labels[ $key ];
		}
		return ucwords( str_replace( array( '-', '_', 'hs ' ), ' ', trim( (string) $key, '_' ) ) );
	}

	/**
	 * Turn any value into a short printable string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	protected static function flatten( $value ) {
		if ( null === $value ) {
			return '—';
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'روشن', 'hoosh-seo' ) : __( 'خاموش', 'hoosh-seo' );
		}
		if ( is_array( $value ) ) {
			$flat = array();
			foreach ( $value as $k => $v ) {
				$flat[] = is_array( $v ) ? wp_json_encode( $v ) : (string) $v;
			}
			$text = implode( ' | ', $flat );
			return mb_substr( $text, 0, 300 );
		}
		return mb_substr( (string) $value, 0, 300 );
	}

	/**
	 * Can this row be reverted?
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	public static function can_revert( $row ) {
		if ( (int) $row['reverted'] ) {
			return false;
		}
		$before = json_decode( (string) $row['before_data'], true );
		if ( ! is_array( $before ) || ! $before ) {
			return false;
		}
		if ( 'settings' === $row['scope'] ) {
			return true;
		}
		if ( 'meta' === $row['scope'] || (int) $row['post_id'] ) {
			return (int) $row['post_id'] && get_post( (int) $row['post_id'] );
		}
		return false;
	}

	/**
	 * Revert a change.
	 *
	 * @param int $id Changelog id.
	 * @return array
	 */
	public static function revert( $id ) {
		global $wpdb;
		$id  = (int) $id;
		$table = Database::table( 'changelog' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return array( 'ok' => false, 'message' => __( 'رکورد پیدا نشد.', 'hoosh-seo' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'ok' => false, 'message' => __( 'اجازه بازگردانی ندارید.', 'hoosh-seo' ) );
		}
		if ( ! self::can_revert( $row ) ) {
			return array( 'ok' => false, 'message' => __( 'این تغییر قابل بازگردانی نیست (اسنپ‌شات کامل ندارد).', 'hoosh-seo' ) );
		}

		$before = json_decode( (string) $row['before_data'], true );
		$after  = json_decode( (string) $row['after_data'], true );
		$done   = array();
		$failed = array();

		if ( 'settings' === $row['scope'] ) {
			$current = get_option( 'hoosh_seo_settings', array() );
			$merged  = is_array( $current ) ? $current : array();
			foreach ( (array) $before as $key => $value ) {
				if ( 'hoosh_seo_settings' === $key && is_array( $value ) ) {
					$merged = $value;
					$done[] = $key;
					continue;
				}
				if ( update_option( $key, $value ) ) {
					$done[] = $key;
				} else {
					$failed[] = $key;
				}
			}
			if ( ! in_array( 'hoosh_seo_settings', $done, true ) && $merged ) {
				update_option( 'hoosh_seo_settings', $merged );
			}
		} else {
			$post_id = (int) $row['post_id'];
			// Content rollback needs a real post revision.
			if ( isset( $before['post_content'] ) && function_exists( 'wp_get_post_revisions' ) ) {
				$revisions = wp_get_post_revisions( $post_id );
				$found     = null;
				foreach ( $revisions as $revision ) {
					if ( (string) $revision->post_content === (string) $before['post_content'] ) {
						$found = $revision;
						break;
					}
				}
				if ( $found ) {
					wp_restore_post_revision( (int) $found->ID );
					$done[] = 'post_content';
				} else {
					$failed[] = 'post_content';
				}
				unset( $before['post_content'] );
			}

			foreach ( (array) $before as $key => $value ) {
				if ( 0 === strpos( $key, 'post_' ) ) {
					$fields = array( 'ID' => $post_id, $key => $value );
					if ( false === wp_update_post( wp_slash( $fields ), true ) ) {
						$failed[] = $key;
					} else {
						$done[] = $key;
					}
					continue;
				}
				if ( update_post_meta( $post_id, $key, $value ) ) {
					$done[] = $key;
				} else {
					$failed[] = $key;
				}
			}
			clean_post_cache( $post_id );
		}

		$wpdb->update( $table, array( 'reverted' => 1 ), array( 'id' => $id ) ); // phpcs:ignore
		self::log( 'audit', 'revert', array( 'label' => sprintf( /* translators: %d id */ __( 'بازگردانی رکورد #%d', 'hoosh-seo' ), $id ), 'after' => array( 'restored' => $done, 'failed' => $failed ) ) );

		Helpers::cache_flush_all();

		return array(
			'ok'      => (bool) $done,
			'restored' => $done,
			'failed'  => $failed,
			'count'   => count( $done ),
			'message' => $done
				? sprintf( /* translators: %d count */ __( '%d فیلد به حالت قبل برگشت.', 'hoosh-seo' ), count( $done ) )
				: __( 'چیزی برای بازگردانی پیدا نشد.', 'hoosh-seo' ),
			'undo'    => ( $done && is_array( $after ) ) ? array( 'from' => $done, 'to' => $after ) : null,
		);
	}

	/**
	 * Undo a revert (restore the "after" side of the original row).
	 *
	 * @param int $id Changelog id.
	 * @return array
	 */
	public static function redo( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'changelog' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return array( 'ok' => false, 'message' => __( 'رکورد پیدا نشد.', 'hoosh-seo' ) );
		}
		$after = json_decode( (string) $row['after_data'], true );
		if ( ! is_array( $after ) || ! $after ) {
			return array( 'ok' => false, 'message' => __( 'اسنپ‌شات بعد از تغییر موجود نیست.', 'hoosh-seo' ) );
		}
		$post_id = (int) $row['post_id'];
		$done    = array();
		if ( 'settings' === $row['scope'] ) {
			foreach ( $after as $key => $value ) {
				update_option( $key, $value );
				$done[] = $key;
			}
		} elseif ( $post_id ) {
			foreach ( $after as $key => $value ) {
				if ( 0 === strpos( $key, 'post_' ) ) {
					wp_update_post( wp_slash( array( 'ID' => $post_id, $key => $value ) ) );
				} else {
					update_post_meta( $post_id, $key, $value );
				}
				$done[] = $key;
			}
		}
		$wpdb->update( Database::table( 'changelog' ), array( 'reverted' => 0 ), array( 'id' => (int) $id ) ); // phpcs:ignore
		Helpers::cache_flush_all();
		return array( 'ok' => (bool) $done, 'count' => count( $done ), 'restored' => $done );
	}

	/**
	 * Stats for the audit view.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		if ( ! Database::exists( 'changelog' ) ) {
			return array( 'total' => 0, 'series' => array(), 'top_users' => array(), 'scopes' => array() );
		}
		$table = Database::table( 'changelog' );
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ); // phpcs:ignore

		$series = (array) $wpdb->get_results(
			'SELECT DATE(created_at) d, COUNT(*) c FROM ' . $table . '
			WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
			GROUP BY DATE(created_at) ORDER BY d ASC',
			ARRAY_A
		); // phpcs:ignore

		$users = (array) $wpdb->get_results(
			'SELECT user_id, COUNT(*) c FROM ' . $table . ' GROUP BY user_id ORDER BY c DESC LIMIT 8',
			ARRAY_A
		); // phpcs:ignore
		foreach ( $users as &$user ) {
			$user['name'] = $user['user_id'] ? get_the_author_meta( 'display_name', (int) $user['user_id'] ) : __( 'سیستم', 'hoosh-seo' );
		}
		unset( $user );

		$scopes = (array) $wpdb->get_results(
			'SELECT scope, COUNT(*) c FROM ' . $table . ' GROUP BY scope ORDER BY c DESC LIMIT 20',
			ARRAY_A
		); // phpcs:ignore
		foreach ( $scopes as &$scope ) {
			$scope['label'] = self::scope_label( (string) $scope['scope'] );
		}
		unset( $scope );

		return array(
			'total'     => $total,
			'series'    => array_map(
				function ( $row ) {
					return array(
						'date' => (string) $row['d'],
						'fa'   => Helpers::jalali_date( 'j M', strtotime( (string) $row['d'] ) ),
						'count' => (int) $row['c'],
					);
				},
				$series
			),
			'top_users' => $users,
			'scopes'    => $scopes,
		);
	}

	/**
	 * Prune old rows.
	 *
	 * @param int $days Days to keep.
	 * @return int
	 */
	public static function prune( $days = 0 ) {
		global $wpdb;
		$days = $days ? (int) $days : (int) \hoosh_seo()->settings->get( 'audit.retention_days', 90 );
		if ( $days < 1 ) {
			return 0;
		}
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Database::table( 'changelog' ) . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) AND reverted = 0',
				$days
			)
		); // phpcs:ignore
		if ( $deleted ) {
			self::log( 'audit', 'prune', array( 'label' => sprintf( /* translators: %d rows */ __( '%d رکورد قدیمی پاک شد', 'hoosh-seo' ), $deleted ) ) );
		}
		return $deleted;
	}

	/**
	 * Schedule a prune without spamming cron.
	 */
	protected static function schedule_prune() {
		if ( ! wp_next_scheduled( 'hoosh_seo_prune_audit' ) ) {
			wp_schedule_single_event( time() + 120, 'hoosh_seo_prune_audit' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Auth / system events
	 * ------------------------------------------------------------------ */

	/**
	 * Successful login.
	 *
	 * @param string  $user_login Login.
	 * @param WP_User $user User.
	 */
	public static function on_login( $user_login, $user ) {
		if ( ! \hoosh_seo()->settings->on( 'audit.log_auth' ) ) {
			return;
		}
		self::log(
			'auth',
			'login',
			array(
				'user_id' => (int) $user->ID,
				'label'   => sprintf( /* translators: %s user */ __( 'ورود موفق: %s', 'hoosh-seo' ), $user_login ),
				'after'   => array( 'ip' => Helpers::ip_anon( self::ip() ), 'ua' => mb_substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 120 ) ),
			)
		);
	}

	/**
	 * Failed login.
	 *
	 * @param string $username Attempted username.
	 */
	public static function on_login_failed( $username ) {
		if ( ! \hoosh_seo()->settings->on( 'audit.log_auth' ) ) {
			return;
		}
		self::log(
			'auth',
			'login_failed',
			array(
				'label' => sprintf( /* translators: %s user */ __( 'تلاش ناموفق ورود: %s', 'hoosh-seo' ), sanitize_user( (string) $username ) ),
				'after' => array( 'ip' => Helpers::ip_anon( self::ip() ) ),
			)
		);
	}

	/**
	 * Logout.
	 */
	public static function on_logout() {
		if ( ! \hoosh_seo()->settings->on( 'audit.log_auth' ) ) {
			return;
		}
		$user = wp_get_current_user();
		self::log( 'auth', 'logout', array( 'user_id' => (int) $user->ID, 'label' => sprintf( /* translators: %s user */ __( 'خروج: %s', 'hoosh-seo' ), $user->user_login ) ) );
	}

	/**
	 * New user.
	 *
	 * @param int $user_id User id.
	 */
	public static function on_user_register( $user_id ) {
		self::log( 'auth', 'user_register', array( 'user_id' => (int) $user_id, 'label' => sprintf( /* translators: %d id */ __( 'کاربر جدید شناسه %d', 'hoosh-seo' ), (int) $user_id ) ) );
	}

	/**
	 * Profile update.
	 *
	 * @param int   $user_id User id.
	 * @param array $old     Old data.
	 */
	public static function on_profile_update( $user_id, $old ) {
		$new = get_userdata( (int) $user_id );
		if ( ! $new ) {
			return;
		}
		$changed = array();
		foreach ( array( 'user_email', 'display_name', 'role' ) as $field ) {
			$old_value = is_array( $old ) ? ( $old[0]->{$field} ?? '' ) : ( $old->{$field} ?? '' );
			$new_value = 'role' === $field ? implode( ',', (array) $new->roles ) : $new->{$field};
			if ( (string) $old_value !== (string) $new_value ) {
				$changed[ $field ] = array( (string) $old_value, (string) $new_value );
			}
		}
		if ( $changed ) {
			self::log( 'auth', 'profile_update', array( 'user_id' => (int) $user_id, 'after' => $changed, 'label' => sprintf( /* translators: %s user */ __( 'ویرایش پروفایل %s', 'hoosh-seo' ), $new->user_login ) ) );
		}
	}

	/**
	 * User deleted.
	 *
	 * @param int     $user_id Id.
	 * @param int     $reassign Reassign.
	 * @param WP_User $user User object.
	 */
	public static function on_user_delete( $user_id, $reassign, $user ) {
		unset( $reassign );
		self::log(
			'auth',
			'user_delete',
			array(
				'user_id' => (int) $user_id,
				'label'   => sprintf( /* translators: %s user */ __( 'حذف کاربر %s', 'hoosh-seo' ), $user ? $user->user_login : (string) $user_id ),
			)
		);
	}

	/**
	 * Theme switch.
	 *
	 * @param string  $new_name New name.
	 * @param WP_Theme $new New theme.
	 * @param WP_Theme|null $old Old theme.
	 */
	public static function on_switch_theme( $new_name, $new, $old = null ) {
		self::log(
			'update',
			'theme_switch',
			array(
				'label'  => sprintf( /* translators: 1: new, 2: old */ __( 'تغییر قالب: %1$s (قبلاً %2$s)', 'hoosh-seo' ), $new_name, $old ? $old->get( 'Name' ) : '—' ),
				'before' => array( 'theme' => $old ? $old->get_stylesheet() : '' ),
				'after'  => array( 'theme' => $new ? $new->get_stylesheet() : $new_name ),
			)
		);
	}

	/**
	 * Plugin activation/deactivation.
	 *
	 * @param string $plugin Plugin file.
	 */
	public static function on_plugin_changed( $plugin ) {
		self::log( 'update', 'plugin_toggle', array( 'label' => sprintf( /* translators: %s plugin */ __( 'وضعیت افزونه %s عوض شد', 'hoosh-seo' ), $plugin ), 'after' => array( 'plugin' => (string) $plugin ) ) );
	}

	/**
	 * Core/plugin/theme updates.
	 *
	 * @param mixed $updater Updater.
	 * @param array $args Args.
	 */
	public static function on_update( $updater, $args ) {
		unset( $updater );
		if ( ! is_array( $args ) || empty( $args['type'] ) ) {
			return;
		}
		$items = array();
		if ( ! empty( $args['items'] ) ) {
			foreach ( (array) $args['items'] as $item ) {
				$items[] = is_array( $item ) ? (string) ( $item['slug'] ?? '' ) : (string) $item;
			}
		}
		self::log(
			'update',
			sanitize_key( (string) $args['action'] ) . '_' . sanitize_key( (string) $args['type'] ),
			array(
				'label' => sprintf( /* translators: 1: type, 2: count */ __( 'بروزرسانی %1$s برای %2$d آیتم', 'hoosh-seo' ), $args['type'], count( $items ) ),
				'after' => array( 'items' => $items ),
			)
		);
	}

	/**
	 * Page parent reorganised.
	 */
	public static function on_page_tree() {
		self::log( 'menu', 'page_tree', array( 'label' => __( 'چیدمان درخت صفحه‌ها تغییر کرد', 'hoosh-seo' ) ) );
	}

	/**
	 * Nav menu updated.
	 *
	 * @param int $menu_id Menu id.
	 */
	public static function on_nav_menu( $menu_id ) {
		$locations = array_keys( (array) get_nav_menu_locations() );
		self::log(
			'menu',
			'nav_update',
			array(
				'label' => sprintf( /* translators: %d menu */ __( 'منوی %d ویرایش شد', 'hoosh-seo' ), (int) $menu_id ),
				'after' => array( 'menu_id' => (int) $menu_id, 'locations' => $locations ),
			)
		);
		if ( \hoosh_seo()->settings->on( 'audit.ping_on_menu' ) ) {
			Modules\Sitemap::ping_all();
		}
	}

	/**
	 * Client IP (anonymised on read).
	 *
	 * @return string
	 */
	protected static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Interrupted-batch guard + file integrity
	 * ------------------------------------------------------------------ */

	/**
	 * Mark long-running jobs so a timeout is visible after the fact.
	 */
	public static function maybe_flag_interruption() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$running = (array) get_option( 'hoosh_running_job', array() );
		if ( ! $running ) {
			return;
		}
		foreach ( $running as $job => $started ) {
			if ( is_array( $started ) ) {
				$started = isset( $started['started'] ) ? (int) $started['started'] : 0;
			}
			if ( $started && ( time() - (int) $started ) > 900 ) {
				update_option(
					'hoosh_audit_interrupted',
					array(
						'job'     => (string) $job,
						'since'   => (int) $started,
						'flagged' => time(),
					),
					false
				);
				self::log( 'audit', 'interrupted', array( 'label' => sprintf( /* translators: %s job */ __( 'فرایند %s نیمه‌کاره متوقف شد (مصرف حافظه یا تایم‌اوت؟)', 'hoosh-seo' ), $job ) ) );
			}
		}
	}

	/**
	 * Resume/report interrupted jobs on load.
	 */
	public static function resume_interrupted() {
		$flag = get_option( 'hoosh_audit_interrupted', false );
		if ( ! $flag ) {
			return;
		}
		$job = is_array( $flag ) ? (string) ( $flag['job'] ?? '' ) : '';

		if ( in_array( $job, array( 'content-scan', 'broken-scan', 'ai-queue' ), true ) ) {
			// Re-arm the queue so the next cron tick continues where it stopped.
			\HooshSEO\Cron::soft_drain();
		}

		add_action(
			'all_admin_notices',
			function () use ( $flag ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				$job = is_array( $flag ) ? (string) ( $flag['job'] ?? '' ) : '';
				echo '<div class="notice notice-warning"><p>';
				echo esc_html(
					sprintf(
						/* translators: %s job name */
						__( 'هوش‌سئو: فرایند «%s» در اجرا قبلی نیمه‌کاره ماند و از سر گرفته شد. اگر زیاد تکرار شد، اندازه دسته را در تنظیمات پیشرفته کم کنید.', 'hoosh-seo' ),
						$job ? $job : __( 'نامشخص', 'hoosh-seo' )
					)
				);
				echo ' <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hoosh_clear_interrupted' ), 'hoosh_seo_notice' ) ) . '">' . esc_html__( 'پاک کردن این اعلان', 'hoosh-seo' ) . '</a>';
				echo '</p></div>';
			}
		);
	}

	/**
	 * Clear the interruption flag (admin-post handler).
	 *
	 * @return int
	 */
	public static function clear_interrupted() {
		delete_option( 'hoosh_audit_interrupted' );
		return 1;
	}

	/**
	 * File manifest for integrity checks.
	 *
	 * @param bool $write Save as baseline.
	 * @return array
	 */
	public static function file_manifest( $write = false ) {
		$limits = (array) \hoosh_seo()->settings->get(
			'audit.file_paths',
			array( WP_CONTENT_DIR . '/themes', WP_CONTENT_DIR . '/plugins', ABSPATH . '.htaccess', wp_normalize_path( \HOOSH_SEO_DIR ) )
		);

		$files = array();
		foreach ( $limits as $path ) {
			$path = (string) $path;
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}
			if ( is_file( $path ) ) {
				$files[ $path ] = self::fingerprint( $path );
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			$count = 0;
			foreach ( $iterator as $file ) {
				if ( $count++ > 6000 ) {
					break;
				}
				if ( ! $file->isFile() ) {
					continue;
				}
				$ext = strtolower( (string) $file->getExtension() );
				if ( ! in_array( $ext, array( 'php', 'htaccess', 'js', 'css' ), true ) ) {
					continue;
				}
				$files[ $file->getPathname() ] = self::fingerprint( $file->getPathname() );
			}
		}

		if ( $write ) {
			update_option( 'hoosh_file_manifest', $files, false );
			self::log( 'audit', 'file_baseline', array( 'label' => sprintf( /* translators: %d count */ __( 'نقطه مرجوع فایل‌ها ثبت شد (%d فایل)', 'hoosh-seo' ), count( $files ) ) ) );
		}

		return $files;
	}

	/**
	 * Cheap fingerprint (size + mtime + hash of first 4 KB).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected static function fingerprint( $path ) {
		$size = (int) @filesize( $path ); // phpcs:ignore
		$mtime = (int) @filemtime( $path ); // phpcs:ignore
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore
		$head  = '';
		if ( $handle ) {
			$head = (string) fread( $handle, 4096 );
			fclose( $handle );
		}
		return md5( $size . '|' . $mtime . '|' . $head );
	}

	/**
	 * Compare current files with the baseline.
	 *
	 * @return array
	 */
	public static function file_diff() {
		$baseline = (array) get_option( 'hoosh_file_manifest', array() );
		if ( ! $baseline ) {
			return array(
				'ok'     => false,
				'message' => __( 'هنوز نقطه مرجوعی ثبت نشده؛ یک بار «ثبت وضعیت فعلی» را بزنید.', 'hoosh-seo' ),
				'added'  => array(),
				'modified' => array(),
				'removed' => array(),
			);
		}
		$current = self::file_manifest( false );

		$added    = array_keys( array_diff_key( $current, $baseline ) );
		$removed  = array_keys( array_diff_key( $baseline, $current ) );
		$modified = array();
		foreach ( $current as $file => $hash ) {
			if ( isset( $baseline[ $file ] ) && $baseline[ $file ] !== $hash ) {
				$modified[] = $file;
			}
		}

		$short = function ( $list ) {
			$out = array();
			foreach ( (array) $list as $file ) {
				$out[] = array(
					'file' => str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $file ) ),
					'mtime' => (int) @filemtime( (string) $file ), // phpcs:ignore
					'size'  => (int) @filesize( (string) $file ), // phpcs:ignore
				);
			}
			return array_slice( $out, 0, 200 );
		};

		$found = count( $added ) + count( $removed ) + count( $modified );
		if ( $found && \hoosh_seo()->settings->on( 'audit.mail_files' ) ) {
			self::notify_files( $added, $modified, $removed );
		}

		return array(
			'ok'       => true,
			'checked'  => count( $current ),
			'added'    => $short( $added ),
			'modified' => $short( $modified ),
			'removed'  => $short( $removed ),
			'count'    => $found,
			'message'  => $found
				? sprintf( /* translators: %d count */ __( '%d فایل نسبت به نقطه مرجوع تغییر کرده است.', 'hoosh-seo' ), $found )
				: __( 'هیچ تغییری در فایل‌ها دیده نشد.', 'hoosh-seo' ),
		);
	}

	/**
	 * Email the file diff.
	 *
	 * @param array $added Added.
	 * @param array $modified Modified.
	 * @param array $removed Removed.
	 */
	protected static function notify_files( $added, $modified, $removed ) {
		if ( get_transient( 'hoosh_file_mail' ) ) {
			return;
		}
		set_transient( 'hoosh_file_mail', 1, 6 * HOUR_IN_SECONDS );
		$to  = \hoosh_seo()->settings->get( 'audit.mail_to', get_option( 'admin_email' ) );
		$body = sprintf(
			"%s\n\n%s\n\n%s",
			__( 'بررسی فایل‌های هوش‌سئو تغییری پیدا کرد:', 'hoosh-seo' ),
			__( 'افزوده:', 'hoosh-seo' ) . ' ' . implode( ', ', array_slice( (array) $added, 0, 20 ) ),
			__( 'تغییر:', 'hoosh-seo' ) . ' ' . implode( ', ', array_slice( (array) $modified, 0, 20 ) ) . "\n" .
			__( 'حذف:', 'hoosh-seo' ) . ' ' . implode( ', ', array_slice( (array) $removed, 0, 20 ) )
		);
		wp_mail( (string) $to, sprintf( /* translators: %s site */ __( '[%s] تغییر فایل غیرمنتظره', 'hoosh-seo' ), get_bloginfo( 'name' ) ), $body );
	}

	/**
	 * Daily digest of changes.
	 */
	public static function email_digest() {
		if ( ! \hoosh_seo()->settings->on( 'audit.mail_digest' ) ) {
			return;
		}
		global $wpdb;
		if ( ! Database::exists( 'changelog' ) ) {
			return;
		}
		$rows = (array) $wpdb->get_results(
			'SELECT scope, action, COUNT(*) c FROM ' . Database::table( 'changelog' ) . '
			WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) GROUP BY scope, action ORDER BY c DESC LIMIT 40',
			ARRAY_A
		); // phpcs:ignore
		if ( ! $rows ) {
			return;
		}
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = sprintf( '%s / %s: %d', self::scope_label( (string) $row['scope'] ), $row['action'], (int) $row['c'] );
		}
		wp_mail(
			(string) \hoosh_seo()->settings->get( 'audit.mail_to', get_option( 'admin_email' ) ),
			sprintf( /* translators: %s site */ __( '[%s] خلاصه تغییرات روزانه', 'hoosh-seo' ), get_bloginfo( 'name' ) ),
			implode( "\n", $lines )
		);
	}

	/**
	 * One-line changelog note (no diff).
	 *
	 * @param array $args {scope, action, label, post_id, url, after, batch_id}.
	 * @return int
	 */
	public static function note( $args = array() ) {
		$args  = (array) $args;
		$scope = isset( $args['scope'] ) ? (string) $args['scope'] : 'general';
		$action = isset( $args['action'] ) ? (string) $args['action'] : 'note';
		unset( $args['scope'], $args['action'] );

		return self::log( $scope, $action, $args );
	}

}
