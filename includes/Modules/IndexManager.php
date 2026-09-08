<?php
/**
 * Index manager: bulk index/noindex control, archive robots, IndexNow.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class IndexManager
 */
final class IndexManager {

	/**
	 * Singleton.
	 *
	 * @var IndexManager|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return IndexManager
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
		add_filter( 'wp_robots', array( $this, 'archive_robots' ), 20 );
		add_action( 'wp_ajax_hoosh_seo_indexnow_key_regenerate', array( $this, 'ajax_regenerate_key' ) );
	}

	/**
	 * Archive-level robots (the "which archives may be indexed" switches).
	 *
	 * @param array $robots Robot directives.
	 * @return array
	 */
	public function archive_robots( $robots ) {
		$settings = \hoosh_seo()->settings;

		if ( is_author() && ! $settings->get( 'index.index_authors', false ) ) {
			$robots['index'] = 'noindex';
		}
		if ( is_date() && ! $settings->get( 'index.index_date', false ) ) {
			$robots['index'] = 'noindex';
		}
		if ( is_search() && ! $settings->get( 'index.index_search', false ) ) {
			$robots['index'] = 'noindex';
		}
		if ( is_category() && ! $settings->get( 'index.index_categories', true ) ) {
			$robots['index'] = 'noindex';
		}
		if ( is_tag() && ! $settings->get( 'index.index_tags', true ) ) {
			$robots['index'] = 'noindex';
		}
		if ( is_post_type_archive() ) {
			$type = get_query_var( 'post_type' );
			$type = is_array( $type ) ? reset( $type ) : $type;
			$map  = (array) $settings->get( 'index.archive_robots', array() );
			if ( $type && isset( $map[ $type ] ) && 'index' === $map[ $type ] ) {
				$robots['index'] = 'index';
			}
		}
		if ( is_tag() && $settings->get( 'index.noindex_empty_terms', true ) ) {
			$term  = get_queried_object();
			$limit = (int) $settings->get( 'index.noindex_tag_count', 2 );
			if ( $term && isset( $term->count ) && (int) $term->count < $limit ) {
				$robots['index'] = 'noindex';
			}
		}
		$paged = (int) get_query_var( 'paged' );
		$max   = (int) $settings->get( 'index.max_page_index', 0 );
		if ( $paged && $max && $paged > $max ) {
			$robots['index'] = 'noindex';
		}
		if ( is_attachment() && ! $settings->get( 'index.index_attachments', false ) ) {
			$robots['index'] = 'noindex';
		}
		return $robots;
	}

	/**
	 * Current state of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string index|noindex
	 */
	public static function state( $post_id ) {
		$robots = Meta::robots( $post_id );
		return empty( $robots['index'] ) ? 'noindex' : 'index';
	}

	/**
	 * Set the state of one post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $state   index|noindex|inherit.
	 * @return void
	 */
	public static function set_state( $post_id, $state ) {
		$post_id = (int) $post_id;
		if ( 'inherit' === $state ) {
			delete_post_meta( $post_id, '_hs_robots' );
			delete_post_meta( $post_id, '_hs_noindex_reason' );
			return;
		}
		$robots               = Meta::robots( $post_id );
		$robots['index']      = 'index' === $state;
		update_post_meta( $post_id, '_hs_robots', Meta::sanitize_robots( $robots ) );
		Helpers::cache_flush( 'sitemap' );
	}

	/**
	 * Rows for the Studio table.
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function items( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'   => '',
				'search' => '',
				'state'  => '',
				'status' => 'publish',
				'paged'  => 1,
				'per'    => 40,
				'orderby' => 'modified',
			)
		);

		$query_args = array(
			'post_type'      => $args['type'] ? $args['type'] : Helpers::managed_post_types(),
			'post_status'    => $args['status'] ? $args['status'] : array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => (int) $args['per'],
			'paged'          => (int) $args['paged'],
			'orderby'        => $args['orderby'],
			'order'          => 'DESC',
			's'              => $args['search'],
		);

		if ( in_array( $args['state'], array( 'index', 'noindex' ), true ) ) {
			$meta = array(
				'relation' => 'AND',
				array(
					'key'     => '_hs_robots',
					'value'   => 's:5:"index";b:' . ( 'index' === $args['state'] ? '1' : '0' ),
					'compare' => 'LIKE',
				),
			);
			$query_args['meta_query'] = $meta; // phpcs:ignore
		}

		$query = new \WP_Query( $query_args );
		$rows  = array();
		foreach ( $query->posts as $post ) {
			$rows[] = array(
				'id'        => (int) $post->ID,
				'title'     => get_the_title( $post ),
				'type'      => $post->post_type,
				'status'    => $post->post_status,
				'url'       => get_permalink( $post ),
				'state'     => self::state( $post->ID ),
				'managed'   => (bool) get_post_meta( $post->ID, '_hs_robots', true ),
				'words'     => Helpers::word_count( $post->post_content ),
				'modified'  => $post->post_modified_gmt,
				'score'     => (float) get_post_meta( $post->ID, '_hs_score', true ),
			);
		}

		return array(
			'rows'  => $rows,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Bulk update states + write an undoable log.
	 *
	 * @param array  $ids   Post IDs.
	 * @param string $state index|noindex|inherit.
	 * @return array
	 */
	public static function bulk( $ids, $state ) {
		$batch = substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 32 );
		$done  = array();
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$before = self::state( $id );
			self::set_state( $id, $state );
			$done[] = $id;
			global $wpdb;
			$wpdb->insert( // phpcs:ignore
				Database::table( 'changelog' ),
				array(
					'scope'       => 'index',
					'action'      => 'bulk-' . $state,
					'post_id'     => $id,
					'url'         => get_permalink( $id ),
					'before_data' => wp_json_encode( array( 'state' => $before ) ),
					'after_data'  => wp_json_encode( array( 'state' => $state ) ),
					'batch_id'    => $batch,
					'user_id'     => get_current_user_id(),
					'created_at'  => current_time( 'mysql', true ),
				)
			);
		}

		if ( $done && \hoosh_seo()->settings->get( 'index.indexnow.auto', true ) && 'index' === $state ) {
			self::indexnow( array_values( array_filter( array_map( 'get_permalink', $done ) ) ) );
		}

		return array(
			'updated' => count( $done ),
			'batch'   => $batch,
			'ids'     => $done,
		);
	}

	/**
	 * Undo a batch by its id.
	 *
	 * @param string $batch Batch id.
	 * @return int
	 */
	public static function undo_batch( $batch ) {
		global $wpdb;
		$table = Database::table( 'changelog' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %s AND reverted = 0 ORDER BY id DESC", $batch ) ); // phpcs:ignore
		$count = 0;
		foreach ( (array) $rows as $row ) {
			$before = json_decode( (string) $row->before_data, true );
			if ( $row->post_id && isset( $before['state'] ) ) {
				self::set_state( (int) $row->post_id, $before['state'] );
				$wpdb->update( $table, array( 'reverted' => 1 ), array( 'id' => (int) $row->id ) ); // phpcs:ignore
				$count++;
			}
		}
		return $count;
	}

	/**
	 * The IndexNow key (generated locally, no signup needed).
	 *
	 * @return string
	 */
	public static function key() {
		$key = (string) \hoosh_seo()->settings->get( 'index.indexnow.key', '' );
		if ( ! $key ) {
			$key = get_option( 'hoosh_seo_indexnow_key' );
			if ( ! $key ) {
				$key = wp_generate_password( 32, false, false );
				update_option( 'hoosh_seo_indexnow_key', $key, false );
			}
		}
		return preg_replace( '/[^a-f0-9]/', '', strtolower( $key ) );
	}

	/**
	 * Regenerate the key and keep a physical file when the root is writable.
	 */
	public function ajax_regenerate_key() {
		check_ajax_referer( 'hoosh_seo_notice', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die();
		}
		$out = self::regenerate_key();
		wp_send_json_success( $out );
	}

	/**
	 * Issue a fresh IndexNow key.
	 *
	 * @return array
	 */
	public static function regenerate_key() {
		$key = preg_replace( '/[^a-f0-9]/', '', strtolower( wp_generate_password( 32, false, false ) ) );
		update_option( 'hoosh_seo_indexnow_key', $key, false );
		\HooshSEO\Settings::instance()->set( array( 'index' => array( 'indexnow' => array( 'key' => '' ) ) ), true );
		delete_transient( 'hoosh_indexnow_verified' );
		$physical = self::write_key_file();

		\HooshSEO\Helpers::cache_flush_all();

		return array(
			'key'      => $key,
			'url'      => home_url( '/' . $key . '.txt' ),
			'physical' => (bool) $physical,
			'message'  => $physical
				? __( 'کلید جدید ساخته و روی ریشه هاست نوشته شد.', 'hoosh-seo' )
				: __( 'کلید جدید ساخته شد؛ فایل آن را از همین‌جا با مسیر /{کلید}.txt سرو می‌کنیم.', 'hoosh-seo' ),
		);
	}

	/**
	 * Recently modified public URLs (for a manual IndexNow push).
	 *
	 * @param int $limit Max URLs.
	 * @return array
	 */
	public static function recent_urls( $limit = 100 ) {
		$limit = max( 1, min( 10000, (int) $limit ) );
		$posts = get_posts(
			array(
				'post_type'      => \HooshSEO\Helpers::managed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( (array) $posts as $id ) {
			if ( 'noindex' === self::state( (int) $id ) ) {
				continue;
			}
			$link = get_permalink( (int) $id );
			if ( $link ) {
				$out[] = $link;
			}
		}
		return $out;
	}

	/**
	 * Key file URL + whether it is reachable.
	 *
	 * @return array
	 */
	public static function key_status() {
		$key   = self::key();
		$file  = untrailingslashit( ABSPATH ) . '/' . $key . '.txt';
		return array(
			'key'        => $key,
			'url'        => home_url( '/' . $key . '.txt' ),
			'physical'   => file_exists( $file ),
			'virtual'    => true,
			'verified'   => (bool) get_transient( 'hoosh_indexnow_verified' ),
		);
	}

	/**
	 * Try to also write a physical key file (some engines check it strictly).
	 *
	 * @return bool
	 */
	public static function write_key_file() {
		$key  = self::key();
		$file = untrailingslashit( ABSPATH ) . '/' . $key . '.txt';
		if ( ! is_writable( untrailingslashit( ABSPATH ) ) ) {
			return false;
		}
		return (bool) file_put_contents( $file, $key ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Submit URLs to IndexNow (Bing, Yandex, Seznam, IndexNow).
	 *
	 * @param array $urls Absolute URLs.
	 * @return array
	 */
	public static function indexnow( $urls ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->get( 'index.indexnow.enabled', true ) ) {
			return array( 'skipped' => true, 'reason' => __( 'IndexNow غیرفعال است.', 'hoosh-seo' ) );
		}
		$urls = array_values( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) );
		if ( ! $urls ) {
			return array( 'skipped' => true, 'reason' => __( 'نشانی معتبری ارسال نشد.', 'hoosh-seo' ) );
		}

		$key = self::key();
		$providers = (array) $settings->get( 'index.indexnow.providers', array( 'bing', 'yandex', 'indexnow' ) );

		$endpoint = 'https://api.indexnow.org/indexnow';
		$hosts    = array(
			'indexnow' => 'https://api.indexnow.org/indexnow',
			'bing'     => 'https://www.bing.com/indexnow',
			'yandex'   => 'https://yandex.com/indexnow',
			'seznam'   => 'https://search.seznam.cz/indexnow',
			'naver'    => 'https://searchadvisor.naver.com/indexnow',
			'yesik'    => 'https://indexnow.yep.com/indexnow',
		);

		$payload = array(
			'host'    => wp_parse_url( home_url(), PHP_URL_HOST ),
			'key'     => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList' => array_slice( $urls, 0, 10000 ),
		);

		$results = array();
		foreach ( $providers as $name ) {
			if ( ! isset( $hosts[ $name ] ) ) {
				continue;
			}
			$response = wp_remote_post(
				$hosts[ $name ],
				array(
					'timeout'     => 15,
					'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'        => wp_json_encode( $payload ),
				)
			);
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$results[ $name ] = array(
				'ok'   => in_array( $code, array( 200, 201, 202 ), true ),
				'code' => $code,
				'error' => is_wp_error( $response ) ? $response->get_error_message() : '',
				'endpoint' => $hosts[ $name ],
			);
		}

		self::record_history( $urls, $results );
		set_transient( 'hoosh_indexnow_verified', 1, DAY_IN_SECONDS );

		/**
		 * Fires after an IndexNow submission.
		 *
		 * @param array $urls    URLs.
		 * @param array $results Per-engine results.
		 */
		do_action( 'hoosh_seo_indexnow', $urls, $results );

		return array(
			'submitted' => count( $urls ),
			'results'   => $results,
			'endpoint'  => $endpoint,
		);
	}

	/**
	 * Store a rolling history of submissions.
	 *
	 * @param array $urls    URLs.
	 * @param array $results Results.
	 */
	protected static function record_history( $urls, $results ) {
		$max  = (int) \hoosh_seo()->settings->get( 'index.indexnow.history', 200 );
		$hist = (array) get_option( 'hoosh_seo_indexnow_history', array() );
		array_unshift(
			$hist,
			array(
				'time'   => time(),
				'count'  => count( $urls ),
				'ok'     => (bool) array_filter( wp_list_pluck( $results, 'ok' ) ),
				'urls'   => array_slice( $urls, 0, 20 ),
				'codes'  => wp_list_pluck( $results, 'code' ),
			)
		);
		update_option( 'hoosh_seo_indexnow_history', array_slice( $hist, 0, max( 10, $max ) ), false );
	}

	/**
	 * History for the Studio.
	 *
	 * @return array
	 */
	public static function history() {
		$hist = (array) get_option( 'hoosh_seo_indexnow_history', array() );
		foreach ( $hist as &$row ) {
			$row['time_fa'] = Helpers::time_ago_fa( (int) $row['time'] );
		}
		unset( $row );
		return $hist;
	}

	/**
	 * Archive switches, normalised for the UI.
	 *
	 * @return array
	 */
	public static function archive_matrix() {
		$settings = \hoosh_seo()->settings;
		$out      = array();
		$labels   = array(
			'posts'       => __( 'نوشته‌ها', 'hoosh-seo' ),
			'pages'       => __( 'برگه‌ها', 'hoosh-seo' ),
			'attachments' => __( 'پیوست‌ها', 'hoosh-seo' ),
			'authors'     => __( 'بایگانی نویسندگان', 'hoosh-seo' ),
			'date'        => __( 'بایگانی تاریخ', 'hoosh-seo' ),
			'categories'  => __( 'دسته‌ها', 'hoosh-seo' ),
			'tags'        => __( 'برچسب‌ها', 'hoosh-seo' ),
			'search'      => __( 'صفحات جستجو', 'hoosh-seo' ),
			'format'      => __( 'فرمت‌های نوشته', 'hoosh-seo' ),
		);
		foreach ( $labels as $key => $label ) {
			$out[] = array(
				'key'   => 'index.index_' . $key,
				'label' => $label,
				'value' => (bool) $settings->get( 'index.index_' . $key, in_array( $key, array( 'posts', 'pages', 'categories', 'tags' ), true ) ),
			);
		}
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}
			$types[] = array(
				'key'   => $type->name,
				'label' => $type->labels->singular_name,
				'value' => (string) $settings->get( 'index.archive_robots.' . $type->name, 'index' ),
			);
		}
		return array(
			'core'  => $out,
			'types' => $types,
			'extra' => array(
				'noindex_paged'      => (bool) $settings->get( 'index.noindex_paged', false ),
				'noindex_empty_terms' => (bool) $settings->get( 'index.noindex_empty_terms', true ),
				'noindex_tag_count'  => (int) $settings->get( 'index.noindex_tag_count', 2 ),
				'max_page_index'     => (int) $settings->get( 'index.max_page_index', 0 ),
				'strip_wp_sitemap'   => (bool) $settings->get( 'index.strip_wp_sitemap', false ),
			),
		);
	}

	/**
	 * Noindex terms with fewer posts than the threshold (auto job).
	 *
	 * @param bool $dry Run in preview mode.
	 * @return array
	 */
	public static function noindex_thin_terms( $dry = true ) {
		$limit = (int) \hoosh_seo()->settings->get( 'index.noindex_tag_count', 2 );
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'post_tag' ),
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'count',
				'order'      => 'ASC',
			)
		);
		$affected = array();
		foreach ( (array) $terms as $term ) {
			if ( (int) $term->count >= $limit ) {
				continue;
			}
			$affected[] = array(
				'id'    => (int) $term->term_id,
				'name'  => $term->name,
				'count' => (int) $term->count,
				'url'   => get_term_link( $term ),
			);
			if ( ! $dry ) {
				$robots = (array) get_option( 'hs_term_' . (int) $term->term_id . '_robots', array() );
				$robots['noindex'] = true;
				update_option( 'hs_term_' . (int) $term->term_id . '_robots', $robots, false );
			}
		}
		return array( 'count' => count( $affected ), 'terms' => $affected, 'dry' => $dry );
	}

	/**
	 * Indexable / non-indexable counts for the dashboard.
	 *
	 * @return array
	 */
	public static function summary() {
		$counts = array( 'post' => wp_count_posts( 'post' ), 'page' => wp_count_posts( 'page' ) );

		/**
		 * Filter the counts considered when summarising the index state.
		 *
		 * @param array $counts post_type => WP_Post_Counts.
		 */
		$counts = apply_filters( 'hoosh_seo_index_summary_counts', $counts );

		$total = 0;
		foreach ( $counts as $type_counts ) {
			foreach ( (array) $type_counts as $value ) {
				$total += (int) $value;
			}
		}

		global $wpdb;
		$noindex = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_hs_robots' AND meta_value LIKE '%s:5:\"index\";b:0%'" ); // phpcs:ignore

		return array(
			'total'     => $total,
			'noindex'   => $noindex,
			'indexable' => max( 0, $total - $noindex ),
			'indexnow'  => array(
				'enabled' => (bool) \hoosh_seo()->settings->get( 'index.indexnow.enabled', true ),
				'last'    => self::history() ? Helpers::time_ago_fa( (int) self::history()[0]['time'] ) : __( 'هنوز ارسال نشده', 'hoosh-seo' ),
			),
		);
	}
}
