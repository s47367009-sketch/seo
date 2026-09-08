<?php
/**
 * Internal linking: keyword rules, render-time linker, suggestions,
 * related-content assistant, broken-link scanner, click tracking.
 *
 * Persian-first: word boundaries use Unicode properties and tolerate ZWNJ,
 * so «طلا» never links inside «اطلاعات».
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class Links
 */
final class Links {

	/**
	 * Singleton.
	 *
	 * @var Links|null
	 */
	private static $instance = null;

	/**
	 * Cached rules.
	 *
	 * @var array|null
	 */
	private static $cached = null;

	/**
	 * Get instance.
	 *
	 * @return Links
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
		add_action( 'hoosh_seo_daily', array( __CLASS__, 'scan_queued' ) );
		add_action( 'wp_ajax_hoosh_seo_link_click', array( __CLASS__, 'ajax_click' ) );
		add_shortcode( 'hoosh_related', array( $this, 'related_shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'auto_link' ), 30 );
	}

	/**
	 * Front hooks (kept separate so admin previews are untouched).
	 */
	public function bootstrap_front() {
		// Rendering the links happens on the_content above; this only warms the cache.
		self::rules();
	}

	/**
	 * Active rules, longest keyword first.
	 *
	 * @return array
	 */
	public static function rules() {
		if ( null !== self::$cached ) {
			return self::$cached;
		}
		$rules = (array) Helpers::cache(
			'link-rules',
			function () {
				if ( ! Database::exists( 'links' ) ) {
					return array();
				}
				global $wpdb;
				$rows = $wpdb->get_results( 'SELECT * FROM ' . Database::table( 'links' ) . " WHERE status = 'active' ORDER BY CHAR_LENGTH(keyword) DESC, id ASC", ARRAY_A ); // phpcs:ignore
				return is_array( $rows ) ? $rows : array();
			},
			300
		);

		$out = array();
		foreach ( $rules as $rule ) {
			$pattern = self::pattern( (string) $rule['keyword'], ! empty( $rule['case_sensitive'] ) );
			if ( ! $pattern ) {
				continue;
			}
			$rule['pattern']   = $pattern;
			$rule['conditions'] = array(
				'types'    => array_filter( (array) json_decode( (string) $rule['include_types'], true ) ),
				'include'  => array_filter( (array) json_decode( (string) $rule['include_terms'], true ) ),
				'exclude'  => array_filter( (array) json_decode( (string) $rule['exclude_terms'], true ) ),
			);
			$out[] = $rule;
		}

		self::$cached = $out;
		return $out;
	}

	/**
	 * Flush the rule cache.
	 */
	public static function flush() {
		self::$cached = null;
		Helpers::cache_flush( 'link-rules' );
	}

	/**
	 * Build a boundary-safe regex for a keyword.
	 *
	 * @param string $keyword Keyword.
	 * @param bool   $case    Case sensitive.
	 * @return string
	 */
	public static function pattern( $keyword, $case = false ) {
		$keyword = trim( (string) $keyword );
		if ( '' === $keyword ) {
			return '';
		}
		$normalized = Helpers::normalize_fa( wp_strip_all_tags( $keyword ) );
		$normalized = preg_replace( '/\s+/u', ' ', $normalized );

		$chars = preg_split( '//u', $normalized );
		$body  = '';
		foreach ( (array) $chars as $char ) {
			if ( ' ' === $char ) {
				$body .= '[\s\x{200c}]*';
				continue;
			}
			$body .= preg_quote( $char, '#' );
		}

		if ( '' === $body ) {
			return '';
		}

		$flags = $case ? 'u' : 'iu';
		// Unicode-aware boundaries so Persian words do not match inside other words.
		$pattern = '#(?<![\p{L}\p{M}])(' . $body . ')(?![\p{L}\p{M}])#' . $flags;

		$test = @preg_match( $pattern, 'test' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $test ? '' : $pattern;
	}

	/**
	 * Rule matches this post?
	 *
	 * @param array $rule    Rule.
	 * @param int   $post_id Post ID.
	 * @return bool
	 */
	protected static function rule_applies( $rule, $post_id ) {
		$conditions = isset( $rule['conditions'] ) ? $rule['conditions'] : array();
		$type       = get_post_type( $post_id );

		if ( ! empty( $conditions['types'] ) && ! in_array( $type, (array) $conditions['types'], true ) ) {
			return false;
		}

		if ( ! empty( $rule['skip_own'] ) && (int) $rule['post_id'] && (int) $rule['post_id'] === (int) $post_id ) {
			return false;
		}

		foreach ( (array) ( $conditions['include'] ?? array() ) as $entry ) {
			$tax = (string) $entry;
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'slugs' ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				return false;
			}
		}

		foreach ( (array) ( $conditions['exclude'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$tax   = (string) key( $entry );
			$slug  = (string) current( $entry );
			$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $terms ) && in_array( $slug, (array) $terms, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * the_content filter: insert the anchors.
	 *
	 * @param string $html Content HTML.
	 * @return string
	 */
	public static function auto_link( $html ) {
		if ( ! is_singular() || ! \hoosh_seo()->settings->get( 'links.auto_link', true ) ) {
			return $html;
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $html;
		}
		$rules = self::rules();
		if ( ! $rules ) {
			return $html;
		}

		// Elementor / page-builder content is plain HTML here, so nothing special is needed.
		$applicable = array();
		foreach ( $rules as $rule ) {
			if ( self::rule_applies( $rule, $post_id ) ) {
				$applicable[] = $rule;
			}
		}
		if ( ! $applicable ) {
			return $html;
		}

		return self::apply( $html, $applicable, $post_id );
	}

	/**
	 * Insert anchors into HTML without touching tags, code or existing links.
	 *
	 * @param string $html    HTML.
	 * @param array  $rules   Rules to honour.
	 * @param int    $post_id Post ID (for the max-per-post budget).
	 * @return string
	 */
	public static function apply( $html, $rules, $post_id = 0 ) {
		$settings = \hoosh_seo()->settings;
		$budget   = (int) $settings->get( 'links.max_per_post', 6 );
		$skip_h   = (bool) $settings->get( 'links.skip_headings', true );
		$skip_a   = (bool) $settings->get( 'links.skip_existing', true );
		$only_nth = max( 1, (int) $settings->get( 'links.min_words', 100 ) );
		$words    = Helpers::word_count( $html );

		if ( $budget <= 0 || $words < $only_nth ) {
			return $html;
		}

		// Used keyword counters, so a keyword is never linked more than max_links times.
		$used = array();

		// Tokenise: split on HTML tags, keep them.
		$segments = preg_split( '/(<[^>]+>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $segments ) ) {
			return $html;
		}

		$skip_stack = 0;
		$inside_a   = false;
		$inside_pre = false;
		$class      = sanitize_html_class( (string) $settings->get( 'links.class', 'hs-internal' ) );
		$direct     = (bool) $settings->get( 'links.direct', true );
		$bold       = (bool) $settings->get( 'links.bold', false );

		foreach ( $segments as $index => $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			if ( '<' === $segment[0] ) {
				$lower = strtolower( $segment );
				if ( $skip_a && preg_match( '#^<a[\s>]#i', $lower ) ) {
					$inside_a = 0 === strpos( $lower, '</' );
				} elseif ( preg_match( '#^</a#i', $lower ) ) {
					$inside_a = false;
				}
				if ( $skip_h && preg_match( '#^<h[1-6][\s>]#i', $lower ) ) {
					$skip_stack++;
				} elseif ( $skip_h && preg_match( '#^</h[1-6]>#i', $lower ) ) {
					$skip_stack = max( 0, $skip_stack - 1 );
				}
				if ( preg_match( '#^<(pre|code|script|style|textarea)[\s>]#i', $lower ) ) {
					$inside_pre = true;
				} elseif ( preg_match( '#^</(pre|code|script|style|textarea)>#i', $lower ) ) {
					$inside_pre = false;
				}
				continue;
			}

			if ( $skip_stack > 0 || $inside_a || $inside_pre ) {
				continue;
			}

			foreach ( $rules as $rule ) {
				if ( $budget <= 0 ) {
					break;
				}
				$id = (int) $rule['id'];
				$cap = max( 1, (int) $rule['max_links'] );
				if ( ! isset( $used[ $id ] ) ) {
					$used[ $id ] = 0;
				}
				if ( $used[ $id ] >= $cap ) {
					continue;
				}

				$pattern = $rule['pattern'];
				if ( ! $pattern || ! preg_match( $pattern, $segment ) ) {
					continue;
				}

				$target = (string) $rule['url'];
				$href   = $direct || empty( $rule['direct'] ) ? $target : home_url( '/hoosh-link/' . $id );
				$attrs  = ' href="' . esc_url( $href ) . '"';
				$attrs .= $class ? ' class="' . esc_attr( $class ) . '"' : '';
				if ( ! empty( $rule['new_tab'] ) ) {
					$attrs .= ' target="_blank" rel="noopener"';
				}
				if ( ! empty( $rule['nofollow'] ) ) {
					$attrs .= ' rel="nofollow"';
				}
				if ( ! empty( $rule['target_title'] ) ) {
					$attrs .= ' title="' . esc_attr( $rule['target_title'] ) . '"';
				}

				$replacement = '<a' . $attrs . '>$1</a>';
				if ( $bold ) {
					$replacement = '<a' . $attrs . '><strong>$1</strong></a>';
				}

				$limit = max( 1, (int) $rule['link_nth'] );
				$count = 0;
				$segment = preg_replace_callback(
					$pattern,
					function ( $m ) use ( $replacement, $id, &$used, $cap, &$count, $limit ) {
						$count++;
						if ( $count < $limit || $used[ $id ] >= $cap ) {
							return $m[0];
						}
						$used[ $id ]++;
						// Manual replacement so $1 is exactly the matched text.
						return str_replace( '$1', $m[0], $replacement );
					},
					$segment,
					1
				);

				if ( $used[ $id ] > 0 ) {
					$budget--;
				}
			}

			$segments[ $index ] = $segment;
		}

		$html = implode( '', $segments );

		/**
		 * Filter the linked content.
		 *
		 * @param string $html    Linked HTML.
		 * @param int    $post_id Post ID.
		 * @param array  $rules   Applied rules.
		 */
		return apply_filters( 'hoosh_seo_linked_content', $html, $post_id, $rules );
	}

	/**
	 * Create or update a rule.
	 *
	 * @param array $data Data.
	 * @return int|WP_Error
	 */
	public static function save_rule( $data ) {
		global $wpdb;
		$keyword = sanitize_text_field( (string) ( $data['keyword'] ?? '' ) );
		if ( '' === $keyword ) {
			return new \WP_Error( 'no_keyword', __( 'کلمه کلیدی لازم است.', 'hoosh-seo' ) );
		}
		if ( ! self::pattern( $keyword, ! empty( $data['case_sensitive'] ) ) ) {
			return new \WP_Error( 'bad_keyword', __( 'این عبارت برای جست‌وجوی خودکار مناسب نیست (کاراکتر نامعتبر).', 'hoosh-seo' ) );
		}

		$url      = Helpers::sanitize_url( (string) ( $data['url'] ?? '' ) );
		$post_id  = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
		if ( ! $url && $post_id ) {
			$url = get_permalink( $post_id );
		}
		if ( ! $url ) {
			return new \WP_Error( 'no_target', __( 'مقصد را انتخاب یا وارد کنید.', 'hoosh-seo' ) );
		}

		$row = array(
			'keyword'        => mb_substr( $keyword, 0, 255 ),
			'norm'           => Helpers::normalize_fa( $keyword ),
			'url'            => mb_substr( $url, 0, 500 ),
			'post_id'        => $post_id,
			'target_title'   => sanitize_text_field( (string) ( $data['target_title'] ?? ( $post_id ? get_the_title( $post_id ) : '' ) ) ),
			'max_links'      => max( 1, min( 30, (int) ( $data['max_links'] ?? 1 ) ) ),
			'link_nth'       => max( 1, min( 20, (int) ( $data['link_nth'] ?? 1 ) ) ),
			'case_sensitive' => empty( $data['case_sensitive'] ) ? 0 : 1,
			'skip_own'       => isset( $data['skip_own'] ) && ! $data['skip_own'] ? 0 : 1,
			'new_tab'        => empty( $data['new_tab'] ) ? 0 : 1,
			'direct'         => isset( $data['direct'] ) && ! $data['direct'] ? 0 : 1,
			'nofollow'       => empty( $data['nofollow'] ) ? 0 : 1,
			'bold'           => empty( $data['bold'] ) ? 0 : 1,
			'include_types'  => wp_json_encode( array_values( (array) ( $data['include_types'] ?? array() ) ) ),
			'include_terms'  => wp_json_encode( array_values( (array) ( $data['include_terms'] ?? array() ) ) ),
			'exclude_terms'  => wp_json_encode( array_values( (array) ( $data['exclude_terms'] ?? array() ) ) ),
			'status'         => in_array( (string) ( $data['status'] ?? 'active' ), array( 'active', 'inactive' ), true ) ? (string) $data['status'] : 'active',
			'click_threshold' => max( 0, (int) ( $data['click_threshold'] ?? 0 ) ),
			'notify_email'   => sanitize_email( (string) ( $data['notify_email'] ?? '' ) ),
			'updated_at'     => current_time( 'mysql', true ),
		);

		self::flush();

		if ( ! empty( $data['id'] ) ) {
			$id = (int) $data['id'];
			$wpdb->update( Database::table( 'links' ), $row, array( 'id' => $id ) ); // phpcs:ignore
			return $id;
		}

		$row['created_at'] = current_time( 'mysql', true );
		$wpdb->insert( Database::table( 'links' ), $row ); // phpcs:ignore
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete rules.
	 *
	 * @param array $ids IDs.
	 * @return int
	 */
	public static function remove( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( 'links' ) . ' WHERE id IN (' . $in . ')', $ids ) ); // phpcs:ignore
		self::flush();
		return count( $ids );
	}

	/**
	 * Listing for the Studio.
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
				'status' => '',
				'per'    => 40,
				'paged'  => 1,
				'orderby' => 'clicks',
			)
		);
		$where  = '1=1';
		$params = array();
		if ( '' !== $args['search'] ) {
			$where   .= ' AND (keyword LIKE %s OR url LIKE %s OR target_title LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $args['status'] ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		$orderby = in_array( $args['orderby'], array( 'clicks', 'id', 'keyword', 'updated_at' ), true ) ? $args['orderby'] : 'clicks';
		$per     = max( 5, min( 200, (int) $args['per'] ) );
		$offset  = ( max( 1, (int) $args['paged'] ) - 1 ) * $per;
		$table   = Database::table( 'links' );

		$count_sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where;
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore
		$rows_sql  = 'SELECT * FROM ' . $table . ' WHERE ' . $where . " ORDER BY {$orderby} DESC LIMIT {$per} OFFSET {$offset}";
		$rows      = (array) ( $params ? $wpdb->get_results( $wpdb->prepare( $rows_sql, $params ), ARRAY_A ) : $wpdb->get_results( $rows_sql, ARRAY_A ) ); // phpcs:ignore

		foreach ( $rows as &$row ) {
			$row['clicks']      = (int) $row['clicks'];
			$row['status']      = (string) $row['status'];
			$row['http_status'] = (int) get_transient( 'hoosh_link_code_' . (int) $row['id'] );
			$row['http_label']  = $row['http_status'] ? self::status_label( $row['http_status'] ) : '';
		}
		unset( $row );

		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
			'stats' => self::stats(),
		);
	}

	/**
	 * Aggregate numbers.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		if ( ! Database::exists( 'links' ) ) {
			return array();
		}
		$links = Database::table( 'links' );
		$log   = Database::table( 'link_log' );
		return array(
			'rules'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$links}" ), // phpcs:ignore
			'active'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$links} WHERE status='active'" ), // phpcs:ignore
			'clicks'   => (int) $wpdb->get_var( "SELECT COALESCE(SUM(clicks),0) FROM {$links}" ), // phpcs:ignore
			'today'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE created_at >= %s", gmdate( 'Y-m-d 00:00:00' ) ) ), // phpcs:ignore
			'broken'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$links} WHERE status='broken'" ), // phpcs:ignore
			'rejected' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log} WHERE valid = 0" ), // phpcs:ignore
		);
	}

	/**
	 * Suggest keywords from a post body + related content from the same site.
	 *
	 * @param int $post_id Post ID.
	 * @param int $limit   Max suggestions.
	 * @return array
	 */
	public static function suggest( $post_id, $limit = 12 ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$text    = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$sentences = preg_split( '/[.!?؛\n]+/u', $text );
		$stop      = Helpers::stopwords();
		$counts    = array();

		foreach ( (array) $sentences as $sentence ) {
			$sentence = preg_replace( '/\s+/u', ' ', trim( (string) $sentence ) );
			if ( '' === $sentence ) {
				continue;
			}
			$words = preg_split( '/\s+/u', $sentence );
			$words = array_values( array_filter( $words, 'strlen' ) );
			for ( $n = 1; $n <= 3; $n++ ) {
				for ( $i = 0; $i + $n <= count( $words ); $i++ ) {
					$chunk = array_slice( $words, $i, $n );
					$clean = array_filter( $chunk, function ( $word ) use ( $stop ) {
						return ! in_array( Helpers::normalize_fa( $word ), $stop, true );
					} );
					if ( count( $clean ) !== count( $chunk ) ) {
						continue;
					}
					$phrase = trim( implode( ' ', $chunk ) );
					if ( Helpers::strlen( $phrase ) < 3 || $n > 1 && Helpers::strlen( $phrase ) < 6 ) {
						continue;
					}
					if ( preg_match( '/^\d+$/', $phrase ) ) {
						continue;
					}
					$key = Helpers::normalize_fa( $phrase );
					$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] : array( 'phrase' => $phrase, 'n' => 0, 'len' => $n );
					$counts[ $key ]['n']++;
				}
			}
		}

		uasort(
			$counts,
			function ( $a, $b ) {
				$sa = $a['n'] * ( 1 + $a['len'] );
				$sb = $b['n'] * ( 1 + $b['len'] );
				return $sb <=> $sa;
			}
		);

		$suggestions = array();
		foreach ( array_slice( $counts, 0, $limit, true ) as $key => $data ) {
			if ( $data['n'] < 2 && 1 === $data['len'] ) {
				continue;
			}
			$match = self::find_target( $data['phrase'], $post_id );
			$suggestions[] = array(
				'keyword'  => $data['phrase'],
				'norm'     => $key,
				'count'    => (int) $data['n'],
				'words'    => (int) $data['len'],
				'target'   => $match,
				'existing' => self::keyword_already_rule( $key ),
			);
		}

		return array(
			'keywords' => $suggestions,
			'related'  => self::related( $post_id, (int) \hoosh_seo()->settings->get( 'links.suggest_max', 10 ) ),
		);
	}

	/**
	 * Is a keyword already a rule?
	 *
	 * @param string $norm Normalised keyword.
	 * @return bool
	 */
	protected static function keyword_already_rule( $norm ) {
		foreach ( self::rules() as $rule ) {
			if ( (string) $rule['norm'] === $norm ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find the best internal target for a phrase.
	 *
	 * @param string $phrase Phrase.
	 * @param int    $exclude_post_id Skip this post.
	 * @return array
	 */
	public static function find_target( $phrase, $exclude_post_id = 0 ) {
		$query = new \WP_Query(
			array(
				's'              => $phrase,
				'post_type'      => Helpers::managed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 4,
				'no_found_rows'  => true,
				'exclude'        => $exclude_post_id ? array( $exclude_post_id ) : array(),
			)
		);
		foreach ( $query->posts as $candidate ) {
			$title = Helpers::normalize_fa( get_the_title( $candidate ) );
			$needle = Helpers::normalize_fa( $phrase );
			$score = 60;
			if ( $title === $needle ) {
				$score = 100;
			} elseif ( false !== strpos( $title, $needle ) ) {
				$score = 85;
			} elseif ( false !== strpos( Helpers::normalize_fa( $candidate->post_name ), str_replace( ' ', '-', $needle ) ) ) {
				$score = 78;
			}
			return array(
				'post_id' => (int) $candidate->ID,
				'title'   => get_the_title( $candidate ),
				'url'     => get_permalink( $candidate ),
				'score'   => $score,
			);
		}
		return array(
			'post_id' => 0,
			'title'   => '',
			'url'     => '',
			'score'   => 0,
		);
	}

	/**
	 * Related posts, scored by shared signals.
	 *
	 * @param int $post_id Post ID.
	 * @param int $limit   Results.
	 * @return array
	 */
	public static function related( $post_id, $limit = 8 ) {
		$settings = \hoosh_seo()->settings;
		$min      = (int) $settings->get( 'links.suggest_min_score', 55 );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$keywords = Meta::focus_keywords( $post_id );
		$cats     = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
		$cats     = is_wp_error( $cats ) ? array() : (array) $cats;
		$tags     = wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'ids' ) );
		$tags     = is_wp_error( $tags ) ? array() : (array) $tags;

		$or = array();
		if ( $cats ) {
			$or[] = array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $cats, 'operator' => 'IN' );
		}
		if ( $tags ) {
			$or[] = array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $tags, 'operator' => 'IN' );
		}
		if ( ! $or ) {
			$or[] = array( 'taxonomy' => 'category', 'field' => 'name', 'terms' => array( '' ), 'operator' => 'EXISTS' );
		}

		$args = array(
			'post_type'      => get_post_type( $post_id ),
			'post_status'    => 'publish',
			'posts_per_page' => 40,
			'post__not_in'   => array( $post_id ),
			'tax_query'      => array( array_merge( array( 'relation' => 'OR' ), $or ) ), // phpcs:ignore
			'no_found_rows'  => true,
		);

		$query = new \WP_Query( $args );
		$scores = array();

		foreach ( $query->posts as $candidate ) {
			$score = 40;
			$why   = array( __( 'دسته/برچسب مشترک', 'hoosh-seo' ) );

			$cats_shared = array_intersect( (array) wp_get_object_terms( $candidate->ID, 'category', array( 'fields' => 'ids' ) ), $cats );
			if ( $cats_shared ) {
				$score += 15 * count( $cats_shared );
			}
			$tags_shared = array_intersect( (array) wp_get_object_terms( $candidate->ID, 'post_tag', array( 'fields' => 'ids' ) ), $tags );
			if ( $tags_shared ) {
				$score += 10 * count( $tags_shared );
			}

			$candidate_kws = Meta::focus_keywords( $candidate->ID );
			$kw_shared     = array_uintersect( $keywords, $candidate_kws, array( 'Meta', 'norm' ) );
			if ( $kw_shared ) {
				$score  += 25 * count( $kw_shared );
				$why[]   = __( 'کلمه کلیدی مشترک', 'hoosh-seo' );
			}

			$title = Helpers::normalize_fa( get_the_title( $candidate ) );
			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $title, Helpers::normalize_fa( $keyword ) ) ) {
					$score += 20;
					$why[]  = __( 'عنوان شامل کلمه کلیدی شما', 'hoosh-seo' );
					break;
				}
			}

			similar_text( Helpers::normalize_fa( $post->post_title ), $title, $sim );
			$score += (int) round( $sim / 5 );

			if ( $score >= $min ) {
				$scores[] = array(
					'post_id' => (int) $candidate->ID,
					'title'   => get_the_title( $candidate ),
					'url'     => get_permalink( $candidate ),
					'score'   => min( 100, (int) $score ),
					'reason'  => implode( ' · ', array_unique( $why ) ),
					'anchor'  => $candidate_kws ? $candidate_kws[0] : get_the_title( $candidate ),
					'date'    => get_the_date( 'Y-m-d', $candidate ),
				);
			}
		}

		usort(
			$scores,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scores, 0, (int) $limit );
	}

	/**
	 * Insert chosen suggestions into the post content (undoable).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $links   List of [keyword, url].
	 * @return array
	 */
	public static function insert_into_content( $post_id, $links ) {
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			return array( 'ok' => false, 'message' => __( 'اجازه ویرایش این نوشته را ندارید.', 'hoosh-seo' ) );
		}

		$rules  = array();
		$before = $post->post_content;
		foreach ( (array) $links as $item ) {
			$keyword = sanitize_text_field( (string) ( $item['keyword'] ?? '' ) );
			$url     = Helpers::sanitize_url( (string) ( $item['url'] ?? '' ) );
			if ( '' === $keyword || '' === $url ) {
				continue;
			}
			$pattern = self::pattern( $keyword, false );
			if ( ! $pattern ) {
				continue;
			}
			$rules[] = array(
				'id'            => 0,
				'keyword'       => $keyword,
				'pattern'       => $pattern,
				'url'           => $url,
				'max_links'     => 1,
				'link_nth'      => 1,
				'skip_own'      => 1,
				'direct'        => 1,
				'new_tab'       => 0,
				'nofollow'      => 0,
				'bold'          => 0,
				'target_title'  => '',
				'used'          => 0,
			);
		}

		if ( ! $rules ) {
			return array( 'ok' => false, 'message' => __( 'چیزی برای درج نبود.', 'hoosh-seo' ) );
		}

		$linked = self::apply( $post->post_content, $rules, $post_id );
		if ( trim( (string) $linked ) === trim( (string) $before ) ) {
			return array( 'ok' => false, 'message' => __( 'عبارتی در متن پیدا نشد؛ چیزی تغییر نکرد.', 'hoosh-seo' ) );
		}

		Audit::log(
			'links',
			'insert-content',
			array(
				'post_id' => $post_id,
				'before'  => array( 'content' => $before ),
				'after'   => array( 'content' => $linked ),
				'url'     => get_permalink( $post_id ),
			)
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $linked,
			),
			true,
			false
		);

		return array(
			'ok'      => true,
			'added'   => count( array_filter( wp_list_pluck( $rules, 'used' ) ) ),
			'message' => __( 'پیوندها به متن اضافه شدند.', 'hoosh-seo' ),
		);
	}

	/**
	 * Broken-link check for one URL, with three fallbacks.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	public static function check_url( $url ) {
		$start  = microtime( true );
		$args   = array(
			'timeout'     => (int) \hoosh_seo()->settings->get( 'links.broken_timeout', 8 ),
			'redirection' => 4,
			'method'      => 'HEAD',
			'user-agent'  => Helpers::random_ua(),
			'headers'     => array( 'Accept' => '*/*' ),
		);
		$code   = 0;
		$method = 'http-api';

		$response = wp_remote_request( $url, $args );
		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 405 === $code || 400 === $code || 501 === $code ) {
				$response = wp_remote_get( $url, array_merge( $args, array( 'method' => 'GET' ) ) );
				$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
				$method   = 'http-api-get';
			}
		}

		if ( ! $code && function_exists( 'curl_init' ) ) {
			$method = 'curl';
			$ch     = curl_init();
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_URL            => $url,
					CURLOPT_RETURNTRANSFER  => true,
					CURLOPT_NOBODY          => true,
					CURLOPT_FOLLOWLOCATION  => true,
					CURLOPT_TIMEOUT         => (int) $args['timeout'],
					CURLOPT_USERAGENT      => $args['user-agent'],
					CURLOPT_SSL_VERIFYPEER => true,
				)
			);
			curl_exec( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			curl_close( $ch );
		}

		if ( ! $code && function_exists( 'get_headers' ) ) {
			$method = 'get_headers';
			$headers = @get_headers( $url, false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $headers ) && isset( $headers[0] ) && preg_match( '/HTTP\/[\d.]+\s(\d{3})/', $headers[0], $m ) ) {
				$code = (int) $m[1];
			}
		}

		return array(
			'url'     => $url,
			'code'    => $code,
			'ok'      => $code >= 200 && $code < 400,
			'label'   => self::status_label( $code ),
			'ms'      => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'method'  => $method,
			'checked' => time(),
		);
	}

	/**
	 * HTTP status label in Persian.
	 *
	 * @param int $code Code.
	 * @return string
	 */
	public static function status_label( $code ) {
		$code = (int) $code;
		if ( 200 <= $code && $code < 300 ) {
			return __( 'سالم', 'hoosh-seo' );
		}
		if ( in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
			return __( 'تغییر مسیر', 'hoosh-seo' );
		}
		if ( 404 === $code ) {
			return __( 'یافت نشد', 'hoosh-seo' );
		}
		if ( 403 === $code ) {
			return __( 'مسدود', 'hoosh-seo' );
		}
		if ( $code >= 500 ) {
			return __( 'خطای سرور', 'hoosh-seo' );
		}
		if ( ! $code ) {
			return __( 'بدون پاسخ', 'hoosh-seo' );
		}
		return (string) $code;
	}

	/**
	 * Start / continue a broken-link sweep.
	 *
	 * @param int $budget URLs per run.
	 * @return array
	 */
	public static function scan_queued( $budget = 0 ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->get( 'links.broken_scan', true ) ) {
			return array( 'skipped' => true );
		}

		$state = (array) get_option( 'hoosh_broken_scan', array() );
		$queue = isset( $state['queue'] ) ? (array) $state['queue'] : array();

		if ( ! $queue ) {
			$ids = array();
			global $wpdb;
			foreach ( (array) $wpdb->get_col( 'SELECT id FROM ' . Database::table( 'links' ) . " WHERE status='active' ORDER BY id ASC LIMIT 4000" ) as $id ) { // phpcs:ignore
				$ids[] = (int) $id;
			}
			$queue = $ids;
			if ( ! $queue ) {
				return array( 'done' => true, 'checked' => 0 );
			}
		}

		$budget = $budget ? (int) $budget : (int) $settings->get( 'links.broken_concurrency', 4 );
		$checked = 0;
		$broken  = array();

		foreach ( array_slice( $queue, 0, $budget ) as $id ) {
			$rule = $wpdb->getRow( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'links' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
			if ( ! $rule || empty( $rule['url'] ) ) {
				continue;
			}
			$ignore = (array) $settings->get( 'links.broken_ignore', array() );
			$skip   = false;
			foreach ( $ignore as $needle ) {
				if ( '' !== $needle && false !== strpos( (string) $rule['url'], (string) $needle ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$result  = self::check_url( Helpers::abs_url( $rule['url'] ) );
			$checked++;
			set_transient( 'hoosh_link_code_' . (int) $id, $result['code'], DAY_IN_SECONDS );

			if ( ! $result['ok'] ) {
				$wpdb->update( Database::table( 'links' ), array( 'status' => 'broken' ), array( 'id' => (int) $id ) ); // phpcs:ignore
				$broken[] = array_merge( $result, array( 'id' => (int) $id, 'keyword' => $rule['keyword'] ) );
			} elseif ( 'broken' === $rule['status'] ) {
				$wpdb->update( Database::table( 'links' ), array( 'status' => 'active' ), array( 'id' => (int) $id ) ); // phpcs:ignore
			}
		}

		$queue = array_slice( $queue, $budget );
		update_option( 'hoosh_broken_scan', array( 'queue' => $queue, 'last' => time(), 'checked' => $checked, 'broken' => $broken ), false );
		self::flush();

		if ( $broken && $settings->get( 'links.notify_email', false ) ) {
			self::notify_broken( $broken );
		}

		return array(
			'done'    => ! $queue,
			'remaining' => count( $queue ),
			'checked' => $checked,
			'broken'  => $broken,
		);
	}

	/**
	 * Reset the sweep so it re-scans everything.
	 */
	public static function scan_reset() {
		delete_option( 'hoosh_broken_scan' );
		return self::scan_queued( (int) \hoosh_seo()->settings->get( 'links.broken_concurrency', 4 ) );
	}

	/**
	 * Scan progress for the UI.
	 *
	 * @return array
	 */
	public static function scan_status() {
		$state = (array) get_option( 'hoosh_broken_scan', array() );
		return array(
			'running'   => ! empty( $state['queue'] ),
			'remaining' => isset( $state['queue'] ) ? count( (array) $state['queue'] ) : 0,
			'last'      => isset( $state['last'] ) ? Helpers::time_ago_fa( (int) $state['last'] ) : '',
			'checked'   => isset( $state['checked'] ) ? (int) $state['checked'] : 0,
			'broken'    => isset( $state['broken'] ) ? (int) count( (array) $state['broken'] ) : 0,
		);
	}

	/**
	 * Email about broken links.
	 *
	 * @param array $broken Broken results.
	 */
	protected static function notify_broken( $broken ) {
		$to = \hoosh_seo()->settings->get( 'automation.notify_email' );
		if ( ! $to ) {
			$to = get_option( 'admin_email' );
		}
		$lines = array();
		foreach ( array_slice( $broken, 0, 40 ) as $item ) {
			$lines[] = sprintf( '- %s (%s) — %s', $item['url'], $item['label'], $item['keyword'] );
		}
		$body = sprintf(
			/* translators: 1: count, 2: list */
			__( "هوش‌سئو %1$d پیوند شکسته پیدا کرد:\n\n%2$s\n\nبرای بررسی به استودیوی هوش‌سئو بروید.", 'hoosh-seo' ),
			count( $broken ),
			implode( "\n", $lines )
		);
		wp_mail( $to, __( 'پیوندهای شکسته — هوش‌سئو', 'hoosh-seo' ), $body );
	}

	/**
	 * Log a click (from the tracked route or AJAX).
	 *
	 * @param int $id Link rule id.
	 * @return array
	 */
	public static function track( $id ) {
		global $wpdb;
		$id   = (int) $id;
		$rule = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Database::table( 'links' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $rule ) {
			return array( 'ok' => false, 'code' => 404 );
		}

		$settings = \hoosh_seo()->settings;
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$valid    = true;

		// Per-IP throttle to keep the stats honest.
		$window = (int) $settings->get( 'links.click_limit_min', 10 ) * MINUTE_IN_SECONDS;
		if ( $window > 0 && $ip ) {
			$recent = (int) $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Database::table( 'link_log' ) . ' WHERE link_id = %d AND ip = %s AND created_at >= %s',
					$id,
					$ip,
					gmdate( 'Y-m-d H:i:s', time() - $window )
				)
			);
			$limit = (int) $settings->get( 'links.click_limit_ip', 0 );
			if ( $recent > 0 && $limit && $recent >= $limit ) {
				$valid = false;
			}
		}

		$wpdb->insert( // phpcs:ignore
			Database::table( 'link_log' ),
			array(
				'link_id'    => $id,
				'post_id'    => (int) $rule['post_id'],
				'ip'         => Helpers::ip_anon( $ip ),
				'user_agent' => mb_substr( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '', 0, 255 ),
				'referer'    => mb_substr( wp_get_referer() ? esc_url_raw( wp_get_referer() ) : '', 0, 500 ),
				'valid'      => $valid ? 1 : 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);

		if ( $valid ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::table( 'links' ) . ' SET clicks = clicks + 1 WHERE id = %d', $id ) ); // phpcs:ignore
			self::maybe_notify_threshold( $rule, (int) $rule['clicks'] + 1 );
		}

		self::flush();

		return array(
			'ok'    => true,
			'url'   => Helpers::abs_url( (string) $rule['url'] ),
			'valid' => $valid,
		);
	}

	/**
	 * AJAX click beacon (used when direct links are on and JS tracking is enabled).
	 */
	public static function ajax_click() {
		$id = isset( $_POST['link'] ) ? absint( wp_unslash( $_POST['link'] ) ) : 0;
		nocache_headers();
		if ( ! $id ) {
			wp_die();
		}
		self::track( $id );
		wp_die();
	}

	/**
	 * Route handler: /hoosh-link/{id}
	 *
	 * @param int $id Rule id.
	 */
	public static function track_and_redirect( $id ) {
		$result = self::track( $id );
		if ( empty( $result['ok'] ) ) {
			status_header( 404 );
			echo esc_html__( 'این پیوند دیگر وجود ندارد.', 'hoosh-seo' );
			exit;
		}
		status_header( 302 );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Redirect-By: HooshSEO link-track' );
		wp_redirect( esc_url_raw( $result['url'] ) ); // phpcs:ignore
		exit;
	}

	/**
	 * Click-threshold email per keyword.
	 *
	 * @param array $rule  Rule row.
	 * @param int   $clicks New click count.
	 */
	protected static function maybe_notify_threshold( $rule, $clicks ) {
		$threshold = (int) $rule['click_threshold'];
		if ( ! $threshold || ! \hoosh_seo()->settings->get( 'links.notify_email', false ) ) {
			return;
		}
		if ( $clicks < $threshold || (int) $rule['notified'] ) {
			return;
		}

		$max   = (int) \hoosh_seo()->settings->get( 'links.email_max', 3 );
		$count = (int) get_post_meta( (int) $rule['id'], '_hs_email_count', true );
		if ( $count >= $max ) {
			return;
		}

		$to   = $rule['notify_email'] ? $rule['notify_email'] : get_option( 'admin_email' );
		$body = Helpers::render_vars(
			(string) \hoosh_seo()->settings->get( 'links.email_body', '' ),
			array(
				'keyword'    => $rule['keyword'],
				'clicks'     => Helpers::number( $clicks ),
				'url'        => $rule['url'],
				'last_click' => current_time( 'mysql' ),
			)
		);
		$subject = Helpers::render_vars( (string) \hoosh_seo()->settings->get( 'links.email_subject', 'آستانه کلیک رد شد' ), array( 'keyword' => $rule['keyword'], 'clicks' => Helpers::number( $clicks ) ) );

		if ( wp_mail( $to, $subject, $body ) ) {
			global $wpdb;
			$wpdb->update( Database::table( 'links' ), array( 'notified' => 1 ), array( 'id' => (int) $rule['id'] ) ); // phpcs:ignore
			update_post_meta( (int) $rule['id'], '_hs_email_count', $count + 1 );
		}
	}

	/**
	 * Click reports.
	 *
	 * @param string $type top|low|timeline|referrers|emails.
	 * @param int    $days Days.
	 * @return array
	 */
	public static function report( $type = 'top', $days = 30 ) {
		global $wpdb;
		$links = Database::table( 'links' );
		$log   = Database::table( 'link_log' );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		switch ( $type ) {
			case 'low':
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, keyword, url, clicks FROM {$links} WHERE status='active' ORDER BY clicks ASC LIMIT 20" ), ARRAY_A ); // phpcs:ignore
				return array( 'rows' => (array) $rows );
			case 'timeline':
				$rows = $wpdb->get_results( // phpcs:ignore
					$wpdb->prepare(
						"SELECT DATE(created_at) d, COUNT(*) c, SUM(valid) v FROM {$log} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY d ASC LIMIT 120",
						$since
					),
					ARRAY_A
				);
				$series = array();
				foreach ( (array) $rows as $row ) {
					$series[] = array(
						'date'  => $row['d'],
						'label' => Helpers::jalali_date( 'j M', strtotime( (string) $row['d'] ) ),
						'value' => (int) $row['c'],
						'valid' => (int) $row['v'],
					);
				}
				return array( 'series' => $series );
			case 'referrers':
				$rows = $wpdb->get_results( // phpcs:ignore
					$wpdb->prepare(
						"SELECT referer, COUNT(*) c FROM {$log} WHERE created_at >= %s AND referer <> '' GROUP BY referer ORDER BY c DESC LIMIT 20",
						$since
					),
					ARRAY_A
				);
				return array( 'rows' => (array) $rows );
			case 'emails':
				$rows = $wpdb->get_results( "SELECT id, keyword, clicks, click_threshold, notify_email, notified FROM {$links} WHERE click_threshold > 0 ORDER BY clicks DESC LIMIT 50", ARRAY_A ); // phpcs:ignore
				return array( 'rows' => (array) $rows );
			default:
				$rows = $wpdb->get_results( "SELECT id, keyword, url, clicks, target_title FROM {$links} ORDER BY clicks DESC LIMIT 20", ARRAY_A ); // phpcs:ignore
				return array( 'rows' => (array) $rows );
		}
	}

	/**
	 * Click log for the detail drawer.
	 *
	 * @param int   $id   Rule id.
	 * @param array $args Filters.
	 * @return array
	 */
	public static function clicks( $id, $args = array() ) {
		global $wpdb;
		$args  = wp_parse_args( $args, array( 'per' => 50, 'paged' => 1, 'ip' => '', 'keyword' => '' ) );
		$log   = Database::table( 'link_log' );
		$where = 'link_id = %d';
		$params = array( absint( $id ) );
		if ( '' !== $args['ip'] ) {
			$where   .= ' AND ip LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['ip'] ) . '%';
		}
		$per    = max( 5, min( 200, (int) $args['per'] ) );
		$offset = ( max( 1, (int) $args['paged'] ) - 1 ) * $per;

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE {$where}", $params ) ); // phpcs:ignore
		$rows  = (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( "SELECT * FROM {$log} WHERE {$where} ORDER BY id DESC LIMIT {$per} OFFSET {$offset}", $params ),
			ARRAY_A
		);
		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * Related-posts shortcode.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public function related_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'limit' => 5, 'title' => '', 'show_score' => 'no' ), $atts, 'hoosh_related' );
		if ( ! is_singular() ) {
			return '';
		}
		$items = self::related( get_the_ID(), (int) $atts['limit'] );
		if ( ! $items ) {
			return '';
		}
		$html = '<aside class="hoosh-related">';
		$html .= $atts['title'] ? '<h3>' . esc_html( $atts['title'] ) . '</h3>' : '<h3>' . esc_html__( 'مطالب مرتبط', 'hoosh-seo' ) . '</h3>';
		$html .= '<ul>';
		foreach ( $items as $item ) {
			$html .= '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['title'] ) . '</a>';
			if ( 'yes' === $atts['show_score'] ) {
				$html .= ' <span class="hoosh-related__score">' . (int) $item['score'] . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ul></aside>';
		return $html;
	}

	/**
	 * Broken-link sweep with a friendly summary (automation + REST).
	 *
	 * @param array $args {limit}.
	 * @return array
	 */
	public static function check_broken( $args = array() ) {
		$args   = wp_parse_args( (array) $args, array( 'limit' => 40 ) );
		$budget = max( 1, min( 400, (int) $args['limit'] ) );
		$out    = self::scan_queued( $budget );

		if ( ! empty( $out['skipped'] ) ) {
			return array(
				'ok'      => false,
				'skipped' => true,
				'checked' => 0,
				'broken'  => 0,
				'rows'    => array(),
				'message' => __( 'بررسی لینک شکسته در تنظیمات خاموش است.', 'hoosh-seo' ),
			);
		}

		$broken = (array) ( $out['broken'] ?? array() );
		return array(
			'ok'        => true,
			'checked'   => (int) ( $out['checked'] ?? 0 ),
			'broken'    => count( $broken ),
			'rows'      => $broken,
			'remaining' => (int) ( $out['remaining'] ?? 0 ),
			'done'      => ! empty( $out['done'] ),
			'message'   => sprintf( /* translators: 1: checked, 2: broken, 3: left */ __( '%1$s لینک بررسی شد؛ %2$s شکسته پیدا شد (٪3$s باقی مانده).', 'hoosh-seo' ), Helpers::number( (int) ( $out['checked'] ?? 0 ) ), Helpers::number( count( $broken ) ), Helpers::number( (int) ( $out['remaining'] ?? 0 ) ) ),
		);
	}

}
