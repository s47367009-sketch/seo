<?php
/**
 * Import from other SEO plugins: meta, redirects, 404 log, schema, roles and
 * settings — with a batch id so every row can be rolled back.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;
use HooshSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Migration
 */
final class Migration {

	/**
	 * Singleton.
	 *
	 * @var Migration|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Migration
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
		add_action( 'admin_post_hoosh_migration_run', array( __CLASS__, 'ajax_run' ) );
	}

	/**
	 * Admin-post runner.
	 */
	public static function ajax_run() {
		check_admin_referer( 'hoosh_seo_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازه ندارید.', 'hoosh-seo' ) );
		}
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$out    = self::run( array( 'source' => $source ) );
		wp_safe_redirect( add_query_arg( array( 'hs_msg' => $out['ok'] ? 'imported' : 'import-failed', 'batch' => $out['batch'] ), \HooshSEO\App::studio_url( 'tools' ) ) );
		exit;
	}

	/**
	 * Known sources.
	 *
	 * @return array
	 */
	public static function sources() {
		$catalog = array(
			'yoast'     => array(
				'label'    => 'Yoast SEO',
				'const'    => 'WPSEO_VERSION',
				'option'   => 'wpseo',
				'tables'   => array( 'wpseo_redirects', 'wpseo_links' ),
				'meta'     => array(
					'title'       => '_yoast_wpseo_title',
					'description' => '_yoast_wpseo_metadesc',
					'keywords'    => '_yoast_wpseo_focuskw',
					'canonical'   => '_yoast_wpseo_canonical',
					'redirect'    => '_yoast_wpseo_redirect',
					'breadcrumb'  => '_yoast_wpseo_bctitle',
					'social'      => array(
						'title'       => '_yoast_wpseo_opengraph-title',
						'description' => '_yoast_wpseo_opengraph-description',
						'image'       => '_yoast_wpseo_opengraph-image-id',
					),
					'robots'      => '_yoast_wpseo_meta-robots-noindex',
					'schema'      => '_yoast_wpseo_schema_article_type',
				),
				'primary'  => 'wpseo_primary_category',
				'notice'    => __( 'فقط متا و تنظیمات منتقل می‌شوند؛ جدول لینک‌های Yoast در این افزونه لازم نیست.', 'hoosh-seo' ),
			),
			'rankmath'  => array(
				'label'    => 'Rank Math',
				'const'    => 'RANK_MATH_VERSION',
				'option'   => 'rank-math-options-general',
				'tables'   => array( 'rm_redirections', 'rm_redirection_404' ),
				'meta'     => array(
					'title'       => 'rank_math_title',
					'description' => 'rank_math_description',
					'keywords'    => 'rank_math_focus_keyword',
					'canonical'   => 'rank_math_canonical_url',
					'redirect'    => 'rank_math_redirection',
					'breadcrumb'  => 'rank_math_breadcrumb_title',
					'social'      => array(
						'title'       => 'rank_math_facebook_title',
						'description' => 'rank_math_facebook_description',
						'image'       => 'rank_math_facebook_image_id',
					),
					'robots'      => 'rank_math_robots',
					'schema'      => 'rank_math_schema_FAQBlock',
				),
				'primary'  => 'rank_math_primary_category',
				'notice'    => __( 'اسکیمای FAQ را از بلاک‌های گوتنبرگ Rank Math می‌خوانیم.', 'hoosh-seo' ),
			),
			'aiosop'    => array(
				'label'    => 'All in One SEO Pack',
				'const'    => 'AIOSEOP_VERSION',
				'option'   => 'aioseop_options',
				'tables'   => array(),
				'meta'     => array(
					'title'       => '_aioseop_title',
					'description' => '_aioseop_description',
					'keywords'    => '_aioseop_keywords',
					'canonical'   => '_aioseop_canonical_url',
					'redirect'    => '',
					'breadcrumb'  => '',
					'social'      => array(
						'title'       => '_aioseop_opengraph_settings_title',
						'description' => '_aioseop_opengraph_settings_desc',
						'image'       => '_aioseop_opengraph_settings_image_id',
					),
					'robots'      => '_aioseop_noindex',
				),
				'notice'    => __( 'قوانین ریدایرکت AIOSEOP در گزینه افزونه است؛ همان را خوانده و منتقل می‌کنیم.', 'hoosh-seo' ),
			),
			'seopress'  => array(
				'label'    => 'SEOPress',
				'const'    => 'SEOPRESS_VERSION',
				'option'   => 'seopress_options',
				'tables'   => array( 'seopress_redirects', 'seopress_404' ),
				'meta'     => array(
					'title'       => '_seopress_titles_title',
					'description' => '_seopress_titles_desc',
					'keywords'    => '_seopress_analysis_target_keyphrase',
					'canonical'   => '_seopress_titles_canonical',
					'redirect'    => '',
					'breadcrumb'  => '_seopress_breadcrumbs',
					'social'      => array(
						'title'       => '_seopress_social_fb_title',
						'description' => '_seopress_social_fb_desc',
						'image'       => '',
					),
					'robots'      => '_seopress_robots_index',
				),
			),
			'wpseo'     => array(
				'label'    => 'The SEO Framework',
				'const'    => 'SEOP_FRAMEWORK_VERSION',
				'option'   => 'autodescription-site-settings',
				'tables'   => array(),
				'meta'     => array(
					'title'       => '_autodescription_title',
					'description' => '_autodescription_description',
					'canonical'   => '_autodescription_canonical_settings_url',
					'robots'      => '_autodescription_robots_noindex',
				),
				'hidden'   => true,
			),
			'redirection' => array(
				'label'    => 'Redirection',
				'const'    => 'REDIRECTION_VERSION',
				'plugin'   => 'redirection/redirection.php',
				'option'   => 'redirection_options',
				'tables'   => array( 'wp_redirection_items', 'wp_redirection_404' ),
				'special'  => array( 'redirects', '404' ),
				'meta'     => array(),
			),
			'squirrly'  => array(
				'label'    => 'Squirrly SEO',
				'plugin'   => 'squirrly-seo/master/squirrlyold.php',
				'option'   => 'sq_user_options',
				'tables'   => array(),
				'meta'     => array(
					'title'       => '_sq_seo_title',
					'description' => '_sq_seo_desc',
					'keywords'    => '_sq_focus',
				),
				'hidden'   => true,
			),
			'seo_press_pro' => array(
				'label'    => 'SEOPress Pro (بدون تغییر)',
				'const'    => 'SEOPRESSPRO_VERSION',
				'option'   => 'seopress_pro_options',
				'tables'   => array( 'seopress_redirections', 'seopress_logs' ),
				'meta'     => array(),
				'special'  => array( 'redirects', '404' ),
				'hidden'   => true,
			),
		);

		foreach ( $catalog as $key => &$source ) {
			$source['key']         = $key;
			$source['installed']   = self::is_installed( $source );
			$source['active']      = $source['installed'] && self::is_active( $source );
			$source['version']     = $source['installed'] ? self::version( $source ) : '';
			$source['counts']      = $source['installed'] ? self::counts( $source ) : array();
			$source['total']       = array_sum( (array) $source['counts'] );
			$source['has_meta']    = ! empty( $source['meta'] );
			$source['importable']  = $source['installed'] && \hoosh_seo()->settings->on( 'migration.enabled', true );
			unset( $source['const'] );
		}
		unset( $source );

		foreach ( $catalog as $key => $source ) {
			if ( ! empty( $source['hidden'] ) && ! $source['installed'] ) {
				unset( $catalog[ $key ] );
			}
		}

		return $catalog;
	}

	/**
	 * Is the plugin present?
	 *
	 * @param array $source Source.
	 * @return bool
	 */
	protected static function is_installed( $source ) {
		if ( ! empty( $source['const'] ) && defined( (string) $source['const'] ) ) {
			return true;
		}
		if ( ! empty( $source['plugin'] ) ) {
			$active = (array) get_option( 'active_plugins', array() );
			if ( in_array( (string) $source['plugin'], $active, true ) ) {
				return true;
			}
			return function_exists( 'is_plugin_active' ) ? (bool) is_plugin_active( (string) $source['plugin'] ) : false;
		}
		return false;
	}

	/**
	 * Is the plugin active?
	 *
	 * @param array $source Source.
	 * @return bool
	 */
	protected static function is_active( $source ) {
		if ( ! empty( $source['const'] ) && defined( (string) $source['const'] ) ) {
			return true;
		}
		if ( ! empty( $source['plugin'] ) && function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( (string) $source['plugin'] );
		}
		return false;
	}

	/**
	 * Version string.
	 *
	 * @param array $source Source.
	 * @return string
	 */
	protected static function version( $source ) {
		if ( ! empty( $source['const'] ) && defined( (string) $source['const'] ) ) {
			return (string) constant( (string) $source['const'] );
		}
		if ( ! empty( $source['plugin'] ) && function_exists( 'get_plugin_data' ) ) {
			$file = WP_PLUGIN_DIR . '/' . $source['plugin'];
			if ( file_exists( $file ) ) {
				$data = get_plugin_data( $file );
				return (string) ( $data['Version'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * How much data the source holds.
	 *
	 * @param array $source Source.
	 * @return array
	 */
	public static function counts( $source ) {
		global $wpdb;
		$out = array(
			'titles'       => 0,
			'descriptions' => 0,
			'keywords'     => 0,
			'canonical'    => 0,
			'redirects'    => 0,
			'404'          => 0,
			'schema'       => 0,
			'settings'     => 0,
		);

		$slots = array(
			'title'       => 'titles',
			'description' => 'descriptions',
			'keywords'    => 'keywords',
			'canonical'   => 'canonical',
			'schema'      => 'schema',
		);
		foreach ( (array) ( $source['meta'] ?? array() ) as $field => $key ) {
			if ( ! is_string( $key ) || '' === $key || ! isset( $slots[ $field ] ) ) {
				continue;
			}
			$out[ $slots[ $field ] ] = (int) $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
					$key
				)
			);
		}

		foreach ( (array) ( $source['tables'] ?? array() ) as $table ) {
			$full = $wpdb->prefix . $table;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) { // phpcs:ignore
				continue;
			}
			$n     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $full ); // phpcs:ignore
			$table = (string) $table;
			if ( false !== strpos( $table, 'redirect' ) || false !== strpos( $table, 'items' ) ) {
				$out['redirects'] += $n;
			} elseif ( false !== strpos( $table, '404' ) || false !== strpos( $table, 'logs' ) ) {
				$out['404'] += $n;
			}
		}

		if ( ! empty( $source['option'] ) && get_option( (string) $source['option'] ) ) {
			$out['settings'] = 1;
		}

		return array_filter( $out );
	}

	/**
	 * Preview: what would change.
	 *
	 * @param string $source_key Source key.
	 * @param int    $limit      Sample size.
	 * @return array
	 */
	public static function preview( $source_key, $limit = 12 ) {
		$catalog = self::sources();
		if ( ! isset( $catalog[ $source_key ] ) ) {
			return array( 'ok' => false, 'message' => __( 'منبع پیدا نشد.', 'hoosh-seo' ) );
		}
		$source = $catalog[ $source_key ];
		$rows   = self::sample_rows( $source, (int) $limit );

		return array(
			'ok'      => true,
			'source'  => $source_key,
			'label'   => (string) $source['label'],
			'counts'  => (array) $source['counts'],
			'notice'  => (string) ( $source['notice'] ?? '' ),
			'rows'    => $rows,
			'conflicts' => self::conflicts( $source ),
		);
	}

	/**
	 * Sample of source rows + where they will land.
	 *
	 * @param array $source Source.
	 * @param int   $limit  Limit.
	 * @return array
	 */
	protected static function sample_rows( $source, $limit = 12 ) {
		global $wpdb;
		$title_key = (string) ( $source['meta']['title'] ?? '' );
		if ( ! $title_key ) {
			return array();
		}
		$sql = $wpdb->prepare(
			"SELECT p.ID, p.post_title, m.meta_value AS title
			 FROM {$wpdb->postmeta} m
			 JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = %s AND m.meta_value <> ''
			 ORDER BY p.ID DESC LIMIT %d",
			$title_key,
			max( 1, (int) $limit )
		);
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $row ) {
			$post_id  = (int) $row['ID'];
			$ours     = (string) get_post_meta( $post_id, '_hs_title', true );
			$out[]    = array(
				'post_id'   => $post_id,
				'title'     => (string) $row['post_title'],
				'source'    => (string) $row['title'],
				'current'   => $ours,
				'state'     => $ours ? 'conflict' : 'new',
				'url'       => (string) get_permalink( $post_id ),
				'edit'      => (string) get_edit_post_link( $post_id, 'raw' ),
				'desc_source' => (string) get_post_meta( $post_id, (string) ( $source['meta']['description'] ?? '' ), true ),
			);
		}
		return $out;
	}

	/**
	 * Existing HooshSEO values that would be overwritten.
	 *
	 * @param array $source Source.
	 * @return array
	 */
	public static function conflicts( $source ) {
		global $wpdb;
		$out = array();
		$title_key = (string) ( $source['meta']['title'] ?? '' );
		if ( ! $title_key ) {
			return $out;
		}
		$ours = (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(DISTINCT m2.post_id) FROM {$wpdb->postmeta} m1
			 JOIN {$wpdb->postmeta} m2 ON m2.post_id = m1.post_id AND m2.meta_key = '_hs_title' AND m2.meta_value <> ''
			 WHERE m1.meta_key = '{$title_key}' AND m1.meta_value <> ''"
		);
		if ( $ours ) {
			$out[] = array(
				'type'    => 'overwrite',
				'count'   => $ours,
				'label'   => sprintf( /* translators: %d count */ __( '%d صفحه هم متای هوش‌سئو دارد و هم متای افزونه قبلی.', 'hoosh-seo' ), $ours ),
				'default' => 'skip',
				'options' => array( 'skip', 'overwrite', 'keep_longer' ),
			);
		}
		if ( \HooshSEO\AI\Gateway::is_configured() ) {
			$out[] = array(
				'type'  => 'ai',
				'label' => __( 'می‌توان به‌جای کپی، متا را با هوش مصنوعی از اول ساخت.', 'hoosh-seo' ),
				'count' => 0,
			);
		}
		return $out;
	}

	/**
	 * Run the import.
	 *
	 * @param array $args {source, map, settings, redirects, notfound, schema, roles, content, cleanup, limit, on_conflict}.
	 * @return array
	 */
	public static function run( $args = array() ) {
		$args = wp_parse_args(
			(array) $args,
			array(
				'source'     => '',
				'map'        => true,
				'settings'   => true,
				'redirects'  => true,
				'notfound'   => true,
				'schema'     => true,
				'roles'      => false,
				'content'    => true,
				'cleanup'    => false,
				'limit'      => 0,
				'on_conflict' => 'skip',
			)
		);

		$catalog = self::sources();
		$key     = sanitize_key( (string) $args['source'] );
		if ( ! isset( $catalog[ $key ] ) ) {
			return array( 'ok' => false, 'batch' => '', 'message' => __( 'منبع معتبر نیست.', 'hoosh-seo' ) );
		}
		$source = $catalog[ $key ];
		if ( empty( $source['installed'] ) ) {
			return array( 'ok' => false, 'batch' => '', 'message' => sprintf( /* translators: %s plugin */ __( '%s نصب نیست.', 'hoosh-seo' ), $source['label'] ) );
		}

		Automation::instance();
		$batch = Automation::new_batch();
		$tally = array(
			'meta'      => 0,
			'social'    => 0,
			'canonical' => 0,
			'redirects' => 0,
			'404'       => 0,
			'schema'    => 0,
			'settings'  => 0,
			'roles'     => 0,
			'skipped'   => 0,
			'processed' => 0,
		);
		$errors = array();

		if ( ! empty( $args['map'] ) ) {
			$tally = array_merge( $tally, self::import_meta( $source, $args, $batch ) );
		}
		if ( ! empty( $args['redirects'] ) ) {
			$tally = array_merge( $tally, self::import_redirects( $source, $batch ) );
		}
		if ( ! empty( $args['notfound'] ) ) {
			$tally = array_merge( $tally, self::import_404( $source, $batch ) );
		}
		if ( ! empty( $args['schema'] ) ) {
			$tally = array_merge( $tally, self::import_schema( $source, $batch ) );
		}
		if ( ! empty( $args['settings'] ) ) {
			$tally = array_merge( $tally, self::import_settings( $source, $batch, $errors ) );
		}
		if ( ! empty( $args['roles'] ) ) {
			$tally = array_merge( $tally, self::import_roles( $source, $batch ) );
		}

		if ( ! empty( $args['cleanup'] ) && \hoosh_seo()->settings->on( 'migration.delete_source', false ) ) {
			$tally['cleanup'] = self::cleanup( $source );
		}

		$tally['processed'] = (int) $tally['meta'];
		$changed = (int) ( $tally['meta'] + $tally['redirects'] + $tally['404'] + $tally['schema'] + $tally['settings'] + $tally['social'] + $tally['canonical'] );

		update_option( 'hoosh_migration_state', array( 'source' => $key, 'at' => time(), 'batch' => $batch, 'tally' => $tally ), false );
		Settings::instance()->set( array( 'migration' => array( 'last_batch' => $batch ) ), true );

		Audit::log(
			'migration',
			'import',
			array(
				'batch_id' => $batch,
				'label'    => sprintf( /* translators: %s source */ __( 'درون‌ریزی از %s', 'hoosh-seo' ), (string) $source['label'] ),
				'after'    => $tally,
			)
		);
		Geo::bust();
		Helpers::cache_flush_all();

		$lines = array();
		foreach ( array( 'meta' => __( 'متا', 'hoosh-seo' ), 'social' => __( 'اجتماعی', 'hoosh-seo' ), 'canonical' => __( 'کانونیکال', 'hoosh-seo' ), 'redirects' => __( 'ریدایرکت', 'hoosh-seo' ), '404' => __( 'لاگ ۴۰۴', 'hoosh-seo' ), 'schema' => __( 'اسکيما', 'hoosh-seo' ), 'settings' => __( 'تنظیمات', 'hoosh-seo' ) ) as $k => $label ) {
			if ( ! empty( $tally[ $k ] ) ) {
				$lines[] = sprintf( /* translators: 1: label, 2: count */ _x( '%1$s: %2$s', 'migration tally', 'hoosh-seo' ), $label, Helpers::number( (int) $tally[ $k ] ) );
			}
		}

		return array(
			'ok'      => true,
			'batch'   => $batch,
			'source'  => $key,
			'label'   => (string) $source['label'],
			'tally'   => $tally,
			'processed' => (int) $tally['meta'],
			'changed' => $changed,
			'errors'  => $errors,
			'undo'    => true,
			'message' => $changed
				? implode( ' | ', $lines )
				: __( 'چیزی برای درون‌ریزی نبود (شاید قبلاً آمده باشد).', 'hoosh-seo' ),
		);
	}

	/**
	 * Copy post meta.
	 *
	 * @param array  $source Source.
	 * @param array  $args   Args.
	 * @param string $batch  Batch id.
	 * @return array
	 */
	protected static function import_meta( $source, $args, $batch ) {
		global $wpdb;
		$tally = array( 'meta' => 0, 'social' => 0, 'canonical' => 0, 'skipped' => 0, 'primary' => 0 );
		$map   = (array) ( $source['meta'] ?? array() );
		$title_key = (string) ( $map['title'] ?? '' );
		if ( ! $title_key ) {
			return $tally;
		}

		$limit = (int) $args['limit'] ? (int) $args['limit'] : 2000;
		$ids   = (array) $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare(
				"SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m
				 JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key IN (" . self::in_keys( $map ) . ") AND p.post_status NOT IN ('auto-draft','trash')
				 ORDER BY m.post_id ASC LIMIT %d",
				$limit
			)
		);

		foreach ( $ids as $post_id ) {
			$post_id   = (int) $post_id;
			$existing  = (array) get_post_meta( $post_id, '_hs_title', true );
			$payload   = array();
			$title     = trim( (string) get_post_meta( $post_id, $title_key, true ) );
			$desc      = trim( (string) get_post_meta( $post_id, (string) ( $map['description'] ?? '' ), true ) );
			$keywords  = (string) get_post_meta( $post_id, (string) ( $map['keywords'] ?? '' ), true );
			$canonical = (string) get_post_meta( $post_id, (string) ( $map['canonical'] ?? '' ), true );
			$bctitle   = (string) get_post_meta( $post_id, (string) ( $map['breadcrumb'] ?? '' ), true );

			if ( '' !== $title ) {
				$payload['title'] = self::translate_vars( $title );
			}
			if ( '' !== $desc ) {
				$payload['description'] = self::translate_vars( $desc );
			}
			if ( '' !== $keywords ) {
				$payload['keywords'] = $keywords;
			}
			if ( '' !== $canonical ) {
				$payload['canonical'] = $canonical;
				$tally['canonical']++;
			}
			if ( '' !== $bctitle ) {
				$payload['breadcrumb'] = $bctitle;
			}

			$social = (array) ( $map['social'] ?? array() );
			$social_out = array();
			foreach ( $social as $field => $key ) {
				if ( ! $key ) {
					continue;
				}
				$value = get_post_meta( $post_id, (string) $key, true );
				if ( '' === $value ) {
					continue;
				}
				$social_out[ $field ] = 'image' === $field ? absint( $value ) : self::translate_vars( (string) $value );
			}
			if ( $social_out ) {
				$payload['social'] = $social_out;
				$tally['social']++;
			}

			$robots_key = (string) ( $map['robots'] ?? '' );
			if ( $robots_key ) {
				$raw = get_post_meta( $post_id, $robots_key, true );
				$robots = self::translate_robots( $source, $raw );
				if ( $robots ) {
					$payload['robots'] = $robots;
				}
			}

			$redirect_key = (string) ( $map['redirect'] ?? '' );
			if ( $redirect_key ) {
				$target = trim( (string) get_post_meta( $post_id, $redirect_key, true ) );
				if ( $target ) {
					$payload['redirect'] = array( 'url' => $target, 'type' => 301 );
				}
			}

			if ( ! $payload ) {
				continue;
			}

			if ( $existing && 'skip' === $args['on_conflict'] ) {
				$tally['skipped']++;
				continue;
			}
			if ( $existing && 'keep_longer' === $args['on_conflict'] ) {
				if ( strlen( (string) $existing ) >= strlen( (string) ( $payload['title'] ?? '' ) ) ) {
					$tally['skipped']++;
					continue;
				}
			}

			$before = Meta::for_post( $post_id );
			Meta::save( $post_id, $payload );
			update_post_meta( $post_id, '_hs_wrapped', 'import:' . $source['key'] );

			$tally['meta']++;
			Audit::log(
				'migration',
				'import',
				array(
					'post_id'  => $post_id,
					'batch_id' => $batch,
					'label'    => sprintf( /* translators: %s source */ __( 'انتقال متا از %s', 'hoosh-seo' ), (string) $source['label'] ),
					'before'   => array_intersect_key( $before, array_flip( array( 'title', 'description', 'keywords', 'canonical', 'social', 'robots' ) ) ),
					'after'    => $payload,
				)
			);

			$primary_key = (string) ( $source['primary'] ?? '' );
			if ( $primary_key ) {
				$term_id = (int) get_post_meta( $post_id, $primary_key, true );
				if ( $term_id ) {
					update_post_meta( $post_id, '_hs_primary_cat', $term_id );
					$tally['primary']++;
				}
			}
		}

		return $tally;
	}

	/**
	 * SQL-safe IN list of meta keys.
	 *
	 * @param array $map Meta map.
	 * @return string
	 */
	protected static function in_keys( $map ) {
		global $wpdb;
		$keys = array();
		foreach ( (array) $map as $field => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $nested ) {
					if ( $nested ) {
						$keys[] = $nested;
					}
				}
				continue;
			}
			if ( $value ) {
				$keys[] = $value;
			}
		}
		$out = array();
		foreach ( $keys as $key ) {
			$out[] = $wpdb->prepare( '%s', (string) $key );
		}
		return $out ? implode( ',', $out ) : "''";
	}

	/**
	 * Translate %%title%%-style variables into ours.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function translate_vars( $value ) {
		$map = array(
			'%%title%%'             => '[[title]]',
			'%%sitename%%'          => '[[site]]',
			'%%tagline%%'           => '[[tagline]]',
			'%%sep%%'               => '[[sep]]',
			'%%primary_category%%'  => '[[category]]',
			'%%category%%'          => '[[category]]',
			'%%term_title%%'        => '[[term]]',
			'%%term_description%%'  => '[[term_description]]',
			'%%excerpt%%'           => '[[excerpt]]',
			'%%excerpt_only%%'      => '[[excerpt]]',
			'%%searchphrase%%'      => '[[searchphrase]]',
			'%%date%%'              => '[[date]]',
			'%%modified%%'          => '[[modified]]',
			'%%author%%'            => '[[author]]',
			'%%name%%'              => '[[author]]',
			'%%page%%'              => '[[page]]',
			'%%pagetype%%'          => '[[post_type]]',
			'%%pagerecycled%%'      => '[[page]]',
			'%%currentyear%%'       => '[[year]]',
			'%%cf_%%'               => '[[cf:%%]]',
			'%title%'               => '[[title]]',
			'%sitename%'            => '[[site]]',
			'%tagline%'             => '[[tagline]]',
			'%sep%'                 => '[[sep]]',
			'%category%'            => '[[category]]',
			'%term_title%'          => '[[term]]',
			'%excerpt%'             => '[[excerpt]]',
			'%date%'                => '[[date]]',
			'%name%'                => '[[author]]',
			'%page%'                => '[[page]]',
			'%search_query%'        => '[[searchphrase]]',
			'%%product_price%%'     => '[[regular_price]]',
		);
		$value = strtr( (string) $value, $map );
		// Rank Math uses %%kt_single_1%% etc. — fold to focus keyword.
		$value = (string) preg_replace( '/%%kt_(single|plural)_\d%%/u', '[[keyword]]', $value );
		$value = (string) preg_replace( '/%%([a-z_0-9]+)%%/u', '[[$1]]', $value );
		return trim( $value );
	}

	/**
	 * Normalise robots flags from any source.
	 *
	 * @param array $source Source.
	 * @param mixed $raw    Raw value.
	 * @return array
	 */
	protected static function translate_robots( $source, $raw ) {
		$out = array();
		if ( '' === $raw || null === $raw ) {
			return $out;
		}
		if ( is_array( $raw ) ) {
			foreach ( $raw as $value ) {
				$value = strtolower( (string) $value );
				if ( false !== strpos( $value, 'noindex' ) ) {
					$out['index'] = false;
				}
				if ( false !== strpos( $value, 'nofollow' ) ) {
					$out['follow'] = false;
				}
				if ( false !== strpos( $value, 'noarchive' ) ) {
					$out['archive'] = false;
				}
				if ( false !== strpos( $value, 'nosnippet' ) ) {
					$out['snippet'] = 'none';
				}
			}
			return $out;
		}
		$value = strtolower( trim( (string) $raw ) );
		if ( 'yoast' === $source['key'] ) {
			// 1 = noindex, 2 = index, 0 = default.
			if ( '1' === $value ) {
				$out['index'] = false;
			} elseif ( '2' === $value ) {
				$out['index'] = true;
			}
			return $out;
		}
		if ( in_array( $value, array( 'on', '1', 'true', 'noindex' ), true ) ) {
			$out['index'] = false;
		} elseif ( in_array( $value, array( 'index', '0', 'false' ), true ) ) {
			$out['index'] = true;
		}
		return $out;
	}

	/**
	 * Redirect tables / options.
	 *
	 * @param array  $source Source.
	 * @param string $batch  Batch.
	 * @return array
	 */
	protected static function import_redirects( $source, $batch ) {
		global $wpdb;
		$tally = array( 'redirects' => 0, 'redirects_skipped' => 0 );
		$rows  = array();

		foreach ( (array) ( $source['tables'] ?? array() ) as $table ) {
			if ( false === strpos( $table, 'redirect' ) && false === strpos( $table, 'items' ) ) {
				continue;
			}
			$full = $wpdb->prefix . $table;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) { // phpcs:ignore
				continue;
			}
			$columns = (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . $full ); // phpcs:ignore
			$pick    = function ( $candidates ) use ( $columns ) {
				foreach ( $candidates as $candidate ) {
					if ( in_array( $candidate, $columns, true ) ) {
						return $candidate;
					}
				}
				return '';
			};

			$source_col = $pick( array( 'source', 'url', 'regex' ) );
			$target_col = $pick( array( 'target', 'url', 'action_data', 'match_to' ) );
			if ( $source_col && $target_col && $source_col === $target_col && 'url' === $source_col ) {
				// Redirection style: url is the source, action_data the target.
				$target_col = 'action_data';
			}
			$code_col  = $pick( array( 'status_code', 'redirection_code', 'action_code', 'code', 'type' ) );
			$regex_col = $pick( array( 'regex', 'is_regex', 'type' ) );
			$active    = $pick( array( 'status' ) );
			if ( ! $source_col ) {
				continue;
			}

			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . $full . ' ORDER BY 1 ASC LIMIT 4000', ARRAY_A ) as $row ) { // phpcs:ignore
				$code  = $code_col ? (string) $row[ $code_col ] : '301';
				$type  = preg_match( '/\d{3}/', $code, $m ) ? (string) $m[0] : '301';
				$state = strtolower( (string) ( $active ? $row[ $active ] : 'active' ) );
				if ( in_array( $state, array( 'inactive', 'disabled', 'paused', 'deleted' ), true ) ) {
					continue;
				}
				$target = $target_col ? (string) $row[ $target_col ] : '';
				if ( 'url' === $source_col && $target_col === 'url' ) {
					$target = '';
				}
				if ( function_exists( 'is_serialized' ) && is_serialized( $target ) ) {
					$decoded = maybe_unserialize( $target );
					$target  = is_array( $decoded ) ? (string) ( $decoded[0] ?? '' ) : (string) $decoded;
				}
				$rows[] = array(
					'source'    => (string) $row[ $source_col ],
					'target'    => $target,
					'regex'     => $regex_col ? (int) ( boolval( $row[ $regex_col ] ) ) : 0,
					'code'      => $type,
					'source_id' => isset( $row['id'] ) ? (int) $row['id'] : 0,
				);
			}
		}

		// AIOSEOP keeps them in an option.
		if ( ! $rows && 'aiosop' === $source['key'] ) {
			$stored = (array) get_option( '0301-redirections', array() );
			foreach ( $stored as $from => $to ) {
				$rows[] = array( 'source' => (string) $from, 'target' => (string) $to, 'regex' => 0, 'code' => '301', 'source_id' => 0 );
			}
		}

		foreach ( $rows as $row ) {
			$source_path = Helpers::rel_url( (string) $row['source'] );
			if ( '' === $source_path || ! preg_match( '#^[/\\^.]#u', $source_path ) ) {
				$tally['redirects_skipped']++;
				continue;
			}
			$type = in_array( (string) $row['code'], array( '301', '302', '307', '308', '410', '451' ), true ) ? (string) $row['code'] : '301';
			$exists = $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare( 'SELECT id FROM ' . Database::table( 'redirects' ) . ' WHERE source = %s LIMIT 1', $source_path )
			);
			if ( $exists ) {
				$tally['redirects_skipped']++;
				continue;
			}
			$id = Redirects::create(
				array(
					'source'   => $source_path,
					'target'   => (string) $row['target'],
					'type'     => $type,
					'is_regex' => ! empty( $row['regex'] ),
					'status'   => 'active',
					'notes'    => sprintf( /* translators: 1: source, 2: batch */ __( 'ورود از %1$s (بسته %2$s)', 'hoosh-seo' ), (string) $source['label'], $batch ),
					'group'    => 'import',
				)
			);
			if ( $id ) {
				$tally['redirects']++;
			}
		}
		Redirects::flush();
		return $tally;
	}

	/**
	 * 404 logs.
	 *
	 * @param array  $source Source.
	 * @param string $batch  Batch.
	 * @return array
	 */
	protected static function import_404( $source, $batch ) {
		global $wpdb;
		$tally = array( '404' => 0 );
		$rows  = array();

		foreach ( (array) ( $source['tables'] ?? array() ) as $table ) {
			if ( false === strpos( $table, '404' ) && false === strpos( $table, 'logs' ) ) {
				continue;
			}
			$full = $wpdb->prefix . $table;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) !== $full ) { // phpcs:ignore
				continue;
			}
			$columns = (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . $full ); // phpcs:ignore
			$pick    = function ( $candidates ) use ( $columns ) {
				foreach ( $candidates as $candidate ) {
					if ( in_array( $candidate, $columns, true ) ) {
						return $candidate;
					}
				}
				return '';
			};
			$url_col    = $pick( array( 'url', 'uri' ) );
			$status_col = $pick( array( 'status_code', 'status' ) );
			$agent_col  = $pick( array( 'user_agent', 'agent' ) );
			$ref_col    = $pick( array( 'referer', 'referrer' ) );
			$ip_col     = $pick( array( 'ip', 'user_ip' ) );
			$count_col  = $pick( array( 'total_access', 'hits', 'counter', 'count' ) );
			$date_col   = $pick( array( 'request_date', 'created', 'datetime' ) );
			if ( ! $url_col ) {
				continue;
			}

			foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . $full . ' ORDER BY 1 DESC LIMIT 4000', ARRAY_A ) as $row ) { // phpcs:ignore
				$path = (string) $row[ $url_col ];
				if ( 'Rank Math' === (string) $source['label'] && preg_match( '#^https?://#i', $path ) ) {
					$path = (string) wp_parse_url( $path, PHP_URL_PATH );
				}
				$rows[] = array(
					'url'     => Helpers::rel_url( $path ),
					'code'    => (int) ( $status_col ? $row[ $status_col ] : 404 ),
					'ua'      => $agent_col ? mb_substr( (string) $row[ $agent_col ], 0, 250 ) : '',
					'referer' => $ref_col ? (string) $row[ $ref_col ] : '',
					'ip'      => $ip_col ? (string) Helpers::ip_anon( (string) $row[ $ip_col ] ) : '',
					'hits'    => (int) ( $count_col ? $row[ $count_col ] : 1 ),
					'first'   => $date_col ? (string) $row[ $date_col ] : '',
				);
			}
		}

		$table = Database::table( 'notfound' );
		foreach ( $rows as $row ) {
			if ( '' === $row['url'] ) {
				continue;
			}
			$hash = md5( $row['url'] );
			$seen = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $table . ' WHERE hash = %s LIMIT 1', $hash ) ); // phpcs:ignore
			if ( $seen ) {
				$wpdb->query( // phpcs:ignore
					$wpdb->prepare( 'UPDATE ' . $table . ' SET hits = hits + %d, last_seen = UTC_TIMESTAMP() WHERE id = %d', max( 1, (int) $row['hits'] ), $seen )
				);
				$tally['404']++;
				continue;
			}
			$wpdb->insert( // phpcs:ignore
				$table,
				array(
					'url'         => mb_substr( $row['url'], 0, 190 ),
					'hash'        => $hash,
					'status_code' => $row['code'] ? $row['code'] : 404,
					'user_agent'  => mb_substr( $row['ua'], 0, 250 ),
					'referer'     => mb_substr( (string) $row['referer'], 0, 255 ),
					'ip'          => mb_substr( (string) $row['ip'], 0, 45 ),
					'hits'        => max( 1, (int) $row['hits'] ),
					'state'       => 'new',
					'first_seen'  => $row['first'] ? get_gmt_from_date( (string) $row['first'] ) : current_time( 'mysql', true ),
					'last_seen'   => current_time( 'mysql', true ),
				)
			);
			$tally['404']++;
		}

		if ( $tally['404'] ) {
			Audit::log(
				'migration',
				'import_404',
				array(
					'batch_id' => $batch,
					'label'    => sprintf( /* translators: %d count */ __( '%d ورودی ۴۰۴ منتقل شد', 'hoosh-seo' ), $tally['404'] ),
					'after'    => array( 'count' => $tally['404'] ),
				)
			);
		}
		return $tally;
	}

	/**
	 * Schema: FAQ blocks + article type hints.
	 *
	 * @param array  $source Source.
	 * @param string $batch  Batch.
	 * @return array
	 */
	protected static function import_schema( $source, $batch ) {
		global $wpdb;
		$tally = array( 'schema' => 0 );
		$key   = (string) ( $source['meta']['schema'] ?? '' );
		if ( ! $key ) {
			return $tally;
		}

		$rows = (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY post_id ASC LIMIT 1500",
				$key
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$raw     = maybe_unserialize( $row['meta_value'] );
			$faq     = self::extract_faq( $raw );
			if ( ! $faq ) {
				continue;
			}
			$existing = (array) get_post_meta( $post_id, '_hs_faq', true );
			if ( $existing ) {
				continue;
			}
			update_post_meta( $post_id, '_hs_faq', Schema::sanitize_faq( $faq ) );
			update_post_meta( $post_id, '_hs_wrapped', 'import:' . $source['key'] );
			$tally['schema']++;

			Audit::log(
				'migration',
				'import_schema',
				array(
					'post_id'  => $post_id,
					'batch_id' => $batch,
					'label'    => sprintf( /* translators: %d count */ __( 'FAQ منتقل شد (%d سؤال)', 'hoosh-seo' ), count( $faq ) ),
					'after'    => array( 'faq' => count( $faq ) ),
				)
			);
		}
		return $tally;
	}

	/**
	 * Pull {q,a} pairs out of a foreign schema blob.
	 *
	 * @param mixed $raw Raw meta.
	 * @return array
	 */
	protected static function extract_faq( $raw ) {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : $raw;
		}
		if ( is_string( $raw ) ) {
			// Maybe serialized blocks.
			$maybe = @unserialize( $raw ); // phpcs:ignore
			if ( is_array( $maybe ) ) {
				$raw = $maybe;
			} else {
				return array();
			}
		}
		$out = array();
		self::walk_faq( $raw, $out );
		return array_slice( $out, 0, 30 );
	}

	/**
	 * Recursive FAQ walker.
	 *
	 * @param mixed $node Node.
	 * @param array $out  Collector.
	 */
	protected static function walk_faq( $node, &$out ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		$question = '';
		$answer   = '';
		foreach ( array( 'question', 'name', 'q', 'title' ) as $key ) {
			if ( ! empty( $node[ $key ] ) && is_scalar( $node[ $key ] ) ) {
				$question = (string) $node[ $key ];
				break;
			}
		}
		foreach ( array( 'answer', 'text', 'a', 'acceptedAnswer', 'content' ) as $key ) {
			if ( empty( $node[ $key ] ) ) {
				continue;
			}
			if ( is_array( $node[ $key ] ) ) {
				$answer = isset( $node[ $key ]['text'] ) ? (string) $node[ $key ]['text'] : wp_json_encode( $node[ $key ] );
			} else {
				$answer = (string) $node[ $key ];
			}
			$answer = wp_strip_all_tags( strip_shortcodes( $answer ) );
			if ( $answer ) {
				break;
			}
		}
		if ( $question && $answer ) {
			$out[] = array(
				'q' => mb_substr( trim( $question ), 0, 250 ),
				'a' => mb_substr( trim( $answer ), 0, 3000 ),
			);
			return;
		}
		foreach ( $node as $value ) {
			self::walk_faq( $value, $out );
		}
	}

	/**
	 * Settings translation.
	 *
	 * @param array  $source Source.
	 * @param string $batch  Batch.
	 * @param array  $errors Collector.
	 * @return array
	 */
	protected static function import_settings( $source, $batch, &$errors = null ) {
		$tally = array( 'settings' => 0 );
		$option = (string) ( $source['option'] ?? '' );
		if ( ! $option ) {
			return $tally;
		}
		$data = (array) get_option( $option, array() );
		if ( ! $data ) {
			return $tally;
		}

		$tree = array();
		$err  = array();

		// Title templates.
		$templates = array(
			'post'   => array( 'title-post', 'title_single', 'titles-single' ),
			'page'   => array( 'title-page', 'title_single_page' ),
			'archive' => array( 'title-archive', 'title_auteur' ),
			'search' => array( 'title-search', 'title_search' ),
			'front'  => array( 'title-home-wpseo', 'title_front' ),
			'taxonomy' => array( 'title-tax-category', 'title_productcat' ),
		);
		foreach ( $templates as $key => $candidates ) {
			foreach ( $candidates as $candidate ) {
				$value = isset( $data[ $candidate ] ) ? $data[ $candidate ] : null;
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}
				$tree['titles']['templates'][ $key ] = self::translate_vars( $value );
				break;
			}
		}

		// Separator.
		$sep = '';
		foreach ( array( 'wpseo_sep', 'separator', 'title_separator', 'sep' ) as $candidate ) {
			if ( ! empty( $data[ $candidate ] ) ) {
				$sep = (string) $data[ $candidate ];
				break;
			}
		}
		if ( $sep ) {
			$tree['general']['separator'] = self::translate_sep( $sep );
		}

		// Sitemap switches.
		$sitemap = array(
			'max_entries' => array( 'sitemap_max_entries', 'sitemap_num_entries' ),
			'excluded_terms' => array( 'exclude_taxonomy' ),
		);
		foreach ( $sitemap as $field => $candidates ) {
			foreach ( $candidates as $candidate ) {
				if ( isset( $data[ $candidate ] ) && '' !== $data[ $candidate ] ) {
					$tree['sitemap'][ $field ] = $data[ $candidate ];
					break;
				}
			}
		}

		// Organization / knowledge graph.
		$org_map = array(
			'org_name'  => array( 'company_name', 'organization_name', 'title' ),
			'org_logo_id' => array( 'company_logo', 'organization_logo_id' ),
			'org_email' => array( 'company_email' ),
			'person_name' => array( 'person_name' ),
		);
		foreach ( $org_map as $field => $candidates ) {
			foreach ( $candidates as $candidate ) {
				if ( isset( $data[ $candidate ] ) && is_scalar( $data[ $candidate ] ) && '' !== $data[ $candidate ] ) {
					$tree['general'][ $field ] = sanitize_text_field( (string) $data[ $candidate ] );
					break;
				}
			}
		}

		if ( ! $tree ) {
			return $tally;
		}

		$before = hoosh_seo()->settings->all();
		Settings::instance()->set( $tree, true );
		$tally['settings'] = 1;

		Audit::log(
			'migration',
			'import_settings',
			array(
				'batch_id' => $batch,
				'label'    => sprintf( /* translators: %s source */ __( 'تنظیمات از %s منتقل شد', 'hoosh-seo' ), (string) $source['label'] ),
				'before'   => $before,
				'after'    => hoosh_seo()->settings->all(),
			)
		);

		if ( $err && is_array( $errors ) ) {
			$errors = array_merge( $errors, $err );
		}
		return $tally;
	}

	/**
	 * Separator token → ours.
	 *
	 * @param string $sep Raw.
	 * @return string
	 */
	public static function translate_sep( $sep ) {
		$map = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
			'sc-rarrow' => '»',
			'sc-colon'  => ':',
			'sc-bullet' => '•',
			'sc-pipe'   => '|',
			'–'         => '–',
		);
		$sep = trim( (string) $sep );
		if ( isset( $map[ $sep ] ) ) {
			return $map[ $sep ];
		}
		if ( preg_match( '/^[\p{P}\-\p{Zs}]{1,3}$/u', $sep ) ) {
			return $sep;
		}
		return $sep ? $sep : '–';
	}

	/**
	 * Roles / capabilities.
	 *
	 * @param array  $source Source.
	 * @param string $batch  Batch.
	 * @return array
	 */
	protected static function import_roles( $source, $batch ) {
		$tally = array( 'roles' => 0 );
		$map   = array(
			'yoast'    => 'wpseo_manage_options',
			'rankmath' => 'rank_math_update',
			'aiosop'   => 'aiowp_manage_options',
			'seopress' => 'seopress_manage_options',
		);
		$cap = isset( $map[ $source['key'] ] ) ? $map[ $source['key'] ] : '';
		if ( ! $cap ) {
			return $tally;
		}
		global $wp_roles;
		if ( ! $wp_roles ) {
			return $tally;
		}
		$roles = (array) $wp_roles->role_objects;
		$changed = array();
		foreach ( $roles as $role ) {
			if ( $role->has_cap( $cap ) ) {
				$role->add_cap( 'hoosh_seo_manage' );
				$changed[] = (string) $role->name;
			}
		}
		if ( $changed ) {
			$tally['roles'] = count( $changed );
			Audit::log(
				'migration',
				'import_roles',
				array(
					'batch_id' => $batch,
					'label'    => sprintf( /* translators: %s roles */ __( 'سطح دسترسی به %s منتقل شد', 'hoosh-seo' ), implode( ', ', $changed ) ),
					'after'    => array( 'roles' => $changed ),
				)
			);
		}
		return $tally;
	}

	/**
	 * Delete the old plugin's meta (only when explicitly enabled).
	 *
	 * @param array $source Source.
	 * @return int
	 */
	public static function cleanup( $source ) {
		global $wpdb;
		if ( ! \hoosh_seo()->settings->on( 'migration.delete_source', false ) ) {
			return 0;
		}
		$keys = array();
		foreach ( (array) ( $source['meta'] ?? array() ) as $value ) {
			if ( is_string( $value ) && $value ) {
				$keys[] = $value;
			} elseif ( is_array( $value ) ) {
				foreach ( $value as $nested ) {
					if ( $nested ) {
						$keys[] = (string) $nested;
					}
				}
			}
		}
		if ( ! empty( $source['primary'] ) ) {
			$keys[] = (string) $source['primary'];
		}
		if ( ! $keys ) {
			return 0;
		}
		$in    = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$in})", $keys ) ); // phpcs:ignore

		Audit::log(
			'migration',
			'cleanup',
			array(
				'label' => sprintf( /* translators: 1: count, 2: source */ __( '%1$d متای %2$s پاک شد', 'hoosh-seo' ), (int) $count, (string) $source['label'] ),
				'after' => array( 'deleted' => (int) $count, 'keys' => $keys ),
			)
		);
		Helpers::cache_flush_all();
		return (int) $count;
	}

	/**
	 * Undo an import batch.
	 *
	 * @param string $batch Batch id.
	 * @return array
	 */
	public static function undo( $batch ) {
		global $wpdb;
		$batch = sanitize_text_field( (string) $batch );
		if ( '' === $batch || ! Database::exists( 'changelog' ) ) {
			return array( 'ok' => false, 'message' => __( 'بسته‌ای برای بازگشت نیست.', 'hoosh-seo' ) );
		}

		$rows = (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT id FROM " . Database::table( 'changelog' ) . " WHERE batch_id = %s AND scope = 'migration' AND reverted = 0 AND action = 'import' ORDER BY id DESC",
				$batch
			),
			ARRAY_A
		);

		$undone = 0;
		foreach ( $rows as $row ) {
			$out = Audit::revert( (int) $row['id'] );
			if ( ! empty( $out['ok'] ) ) {
				$undone++;
			}
		}

		// Drop the redirects the import created.
		$redirects = 0;
		if ( Database::exists( 'redirects' ) ) {
			$redirects = (int) $wpdb->query( // phpcs:ignore
				$wpdb->prepare( 'DELETE FROM ' . Database::table( 'redirects' ) . ' WHERE notes LIKE %s', '%(' . $batch . ')%' )
			);
			Redirects::flush();
		}

		Helpers::cache_flush_all();
		Geo::bust();

		return array(
			'ok'        => (bool) ( $undone || $redirects ),
			'batch'     => $batch,
			'undone'    => $undone,
			'redirects' => $redirects,
			'message'   => sprintf( /* translators: 1: fields, 2: redirects */ __( '%1$s فیلد برگشت خورد و %2$s ریدایرکت حذف شد.', 'hoosh-seo' ), Helpers::number( $undone ), Helpers::number( $redirects ) ),
		);
	}

	/**
	 * Last run state for the Studio.
	 *
	 * @return array
	 */
	public static function state() {
		$state = (array) get_option( 'hoosh_migration_state', array() );
		if ( ! $state ) {
			return array( 'ran' => false );
		}
		$state['ago']  = ! empty( $state['at'] ) ? Helpers::time_ago_fa( (int) $state['at'] ) : '';
		$state['ran']  = true;
		return $state;
	}
}
