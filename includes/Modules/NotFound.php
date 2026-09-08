<?php
/**
 * 404 monitor: log, dedupe, score, one-click redirect, prune.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Class NotFound
 */
final class NotFound {

	/**
	 * Singleton.
	 *
	 * @var NotFound|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return NotFound
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Admin hooks.
	 */
	public function bootstrap() {
		add_action( 'hoosh_seo_prune_logs', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Front hooks.
	 */
	public function bootstrap_front() {
		if ( ! \hoosh_seo()->settings->get( 'redirects.log_enabled', true ) ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'maybe_log' ), 99 );
	}

	/**
	 * Log the hit.
	 */
	public function maybe_log() {
		if ( ! is_404() ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $path || Redirects::owns( $path ) ) {
			return;
		}

		foreach ( (array) \hoosh_seo()->settings->get( 'redirects.log_ignore', array() ) as $needle ) {
			if ( '' !== $needle && false !== strpos( $path, (string) $needle ) ) {
				return;
			}
		}
		// Any registered shortcode-less junk path.
		if ( preg_match( '#\.(php|asp|jsp|env|sql|bak|zip|tar|gz)$#i', $path ) ) {
			return;
		}

		$bot = Helpers::detect_bot();
		if ( $bot && ! \hoosh_seo()->settings->get( 'redirects.log_bots', false ) ) {
			return;
		}

		self::record( $path, $bot );
	}

	/**
	 * Insert or bump a row.
	 *
	 * @param string     $path Path with query.
	 * @param array|bool $bot  Detected bot.
	 * @return int Row id.
	 */
	public static function record( $path, $bot = false ) {
		global $wpdb;
		if ( ! Database::exists( 'notfound' ) ) {
			return 0;
		}
		$hash = md5( Helpers::rel_url( (string) $path ) );

		// Throttle: one write per URL per minute.
		if ( get_transient( 'hoosh_404_' . $hash ) ) {
			return 0;
		}
		set_transient( 'hoosh_404_' . $hash, 1, MINUTE_IN_SECONDS );

		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Database::table( 'notfound' ) . ' WHERE hash = %s', $hash ) ); // phpcs:ignore
		$now      = current_time( 'mysql', true );

		if ( $existing ) {
			$wpdb->query( // phpcs:ignore
				$wpdb->prepare(
					'UPDATE ' . Database::table( 'notfound' ) . ' SET hits = hits + 1, last_seen = %s WHERE id = %d',
					$now,
					$existing
				)
			);
			return $existing;
		}

		$wpdb->insert( // phpcs:ignore
			Database::table( 'notfound' ),
			array(
				'url'         => mb_substr( (string) $path, 0, 500 ),
				'hash'        => $hash,
				'status_code' => 404,
				'user_agent'  => mb_substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', 0, 255 ),
				'referer'     => mb_substr( wp_get_referer() ? esc_url_raw( wp_get_referer() ) : ( isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '' ), 0, 500 ),
				'ip'          => Helpers::ip_anon( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ),
				'state'       => $bot ? 'bot' : 'new',
				'hits'         => 1,
				'first_seen'  => $now,
				'last_seen'   => $now,
			)
		);

		$id = (int) $wpdb->insert_id;

		// Auto-fix: a near-exact content match gets a 301 without asking.
		if ( \hoosh_seo()->settings->get( 'redirects.auto_404_redirect', false ) ) {
			$guess = self::guess_target( $path );
			if ( $guess && ! empty( $guess['score'] ) && $guess['score'] >= 90 ) {
				Redirects::create(
					array(
						'source' => Helpers::rel_url( (string) $path ),
						'target' => $guess['url'],
						'type'   => '301',
						'group'  => 'auto-404',
						'notes'  => __( 'ساخته‌شده خودکار از خطای ۴۰۴', 'hoosh-seo' ),
					)
				);
				self::resolve( $id, $guess['url'] );
			}
		}

		return $id;
	}

	/**
	 * Paginated list with filters.
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function listing( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'search' => '',
				'state'  => '',
				'orderby' => 'hits',
				'order'  => 'DESC',
				'per'    => 30,
				'paged'  => 1,
				'since'  => 0,
			)
		);

		$table  = Database::table( 'notfound' );
		$where  = '1=1';
		$params = array();

		if ( '' !== $args['search'] ) {
			$where   .= ' AND (url LIKE %s OR referer LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $args['state'] ) {
			$where   .= ' AND state = %s';
			$params[] = $args['state'];
		}
		if ( (int) $args['since'] > 0 ) {
			$where   .= ' AND last_seen >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - ( (int) $args['since'] * DAY_IN_SECONDS ) );
		}

		$orderby = in_array( $args['orderby'], array( 'hits', 'last_seen', 'first_seen', 'url' ), true ) ? $args['orderby'] : 'hits';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per     = max( 5, min( 200, (int) $args['per'] ) );
		$offset  = ( max( 1, (int) $args['paged'] ) - 1 ) * $per;

		$count_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where;
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		$rows_sql = 'SELECT * FROM ' . $table . ' WHERE ' . $where . " ORDER BY {$orderby} {$order} LIMIT {$per} OFFSET {$offset}";
		$rows     = (array) ( $params ? $wpdb->get_results( $wpdb->prepare( $rows_sql, $params ), ARRAY_A ) : $wpdb->get_results( $rows_sql, ARRAY_A ) ); // phpcs:ignore

		foreach ( $rows as &$row ) {
			$row['url']        = (string) $row['url'];
			$row['hits']       = (int) $row['hits'];
			$row['suggestion'] = self::guess_target( $row['url'] );
		}
		unset( $row );

		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
			'stats' => array(
				'open'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE state IN ('new','bot')" ), // phpcs:ignore
				'resolved' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE state='resolved'" ), // phpcs:ignore
				'hits'   => (int) $wpdb->get_var( "SELECT COALESCE(SUM(hits),0) FROM {$table}" ), // phpcs:ignore
				'series' => self::series(),
			),
		);
	}

	/**
	 * Daily 404 counts for the chart.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function series( $days = 14 ) {
		global $wpdb;
		$table = Database::table( 'notfound' );
		$rows  = (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT DATE(last_seen) d, SUM(hits) h FROM {$table} WHERE last_seen >= %s GROUP BY DATE(last_seen) ORDER BY d ASC",
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			),
			ARRAY_A
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['d'] ] = (int) $row['h'];
		}
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day   = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$out[] = array(
				'date'  => $day,
				'label' => Helpers::jalali_date( 'j M', strtotime( $day ) ),
				'value' => isset( $map[ $day ] ) ? $map[ $day ] : 0,
			);
		}
		return $out;
	}

	/**
	 * Find the most likely intended target for a 404 path.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	public static function guess_target( $path ) {
		$slug = basename( (string) wp_parse_url( $path, PHP_URL_PATH ) );
		$slug = preg_replace( '/\.(html?|php)$/i', '', $slug );
		$slug = trim( (string) $slug, '/' );
		$terms = preg_split( '/[-_+]+/', Helpers::normalize_fa( $slug ) );
		$terms = array_values( array_filter( $terms, 'strlen' ) );

		$best = array(
			'url'   => '',
			'title' => '',
			'score' => 0,
		);

		if ( $terms ) {
			$query = new \WP_Query(
				array(
					's'              => implode( ' ', $terms ),
					'post_type'      => Helpers::managed_post_types(),
					'post_status'    => 'publish',
					'posts_per_page' => 8,
					'no_found_rows'  => true,
				)
			);
			foreach ( $query->posts as $post ) {
				$title = get_the_title( $post );
				$score = 0;
				similar_text( Helpers::normalize_fa( $slug ), Helpers::normalize_fa( $title ), $score );
				$score = (int) round( $score );
				if ( false !== stripos( (string) $post->post_name, (string) $slug ) ) {
					$score = 100;
				}
				if ( $score > $best['score'] ) {
					$best = array(
						'url'   => get_permalink( $post ),
						'title' => $title,
						'score' => $score,
						'post_id' => (int) $post->ID,
					);
				}
			}
		}

		if ( ! $best['url'] ) {
			$best['url']   = home_url( '/' );
			$best['title'] = __( 'صفحه نخست', 'hoosh-seo' );
			$best['score'] = 40;
		}

		return $best;
	}

	/**
	 * Mark resolved + create the redirect.
	 *
	 * @param int    $id     Row id.
	 * @param string $target Target URL.
	 * @param string $type   Code.
	 * @return array
	 */
	public static function resolve( $id, $target, $type = '301' ) {
		global $wpdb;
		$id     = (int) $id;
		$target = (string) $target;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT url FROM ' . Database::table( 'notfound' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return array( 'ok' => false, 'message' => __( 'رکورد پیدا نشد.', 'hoosh-seo' ) );
		}

		$redirect_id = Redirects::create(
			array(
				'source' => Helpers::rel_url( (string) $row['url'] ),
				'target' => $target,
				'type'   => $type,
				'group'  => '404',
				'notes'  => __( 'از گزارش خطای ۴۰', 'hoosh-seo' ),
			)
		);

		$wpdb->update( // phpcs:ignore
			Database::table( 'notfound' ),
			array(
				'state'       => 'resolved',
				'redirect_id' => is_wp_error( $redirect_id ) ? 0 : (int) $redirect_id,
			),
			array( 'id' => $id )
		);

		return array( 'ok' => true, 'redirect' => $redirect_id );
	}

	/**
	 * Bulk state change / delete.
	 *
	 * @param array  $ids  IDs.
	 * @param string $action ignore|resolve|delete|new.
	 * @return int
	 */
	public static function bulk( $ids, $action ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		if ( 'delete' === $action ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( 'notfound' ) . ' WHERE id IN (' . $in . ')', $ids ) ); // phpcs:ignore
			return count( $ids );
		}

		$state = in_array( $action, array( 'ignored', 'resolved', 'new' ), true ) ? $action : 'ignored';
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::table( 'notfound' ) . ' SET state = %s WHERE id IN (' . $in . ')', array_merge( array( $state ), $ids ) ) ); // phpcs:ignore
		return count( $ids );
	}

	/**
	 * Trim the log to the configured size, oldest hits first.
	 */
	public static function prune() {
		global $wpdb;
		$max = (int) \hoosh_seo()->settings->get( 'redirects.log_limit', 10000 );
		if ( $max <= 0 || ! Database::exists( 'notfound' ) ) {
			return 0;
		}
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'notfound' ) ); // phpcs:ignore
		if ( $count <= $max ) {
			return 0;
		}
		$excess = $count - $max;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( 'notfound' ) . ' WHERE state IN (%s, %s) ORDER BY last_seen ASC LIMIT %d', array( 'ignored', 'resolved', $excess ) ) ); // phpcs:ignore
		return (int) $wpdb->rows_affected;
	}
}
