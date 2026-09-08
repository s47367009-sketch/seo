<?php
/**
 * Breadcrumbs: trail builder, renderer and BreadcrumbList schema.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Class Breadcrumbs
 */
final class Breadcrumbs {

	/**
	 * Singleton.
	 *
	 * @var Breadcrumbs|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Breadcrumbs
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
		add_shortcode( 'hoosh_breadcrumbs', array( $this, 'shortcode' ) );

		$position = (string) \hoosh_seo()->settings->get( 'breadcrumbs.position', 'none' );
		if ( 'none' !== $position && $position ) {
			add_action( $position, array( __CLASS__, 'the_breadcrumbs' ), 5 );
		}
	}

	/**
	 * Settings.
	 *
	 * @return array
	 */
	protected static function args() {
		$settings = \hoosh_seo()->settings;
		return array(
			'separator'      => (string) $settings->get( 'breadcrumbs.separator', '›' ),
			'sep_class'      => 'hoosh-crumb__sep',
			'home_label'     => (string) $settings->get( 'breadcrumbs.home_label', __( 'خانه', 'hoosh-seo' ) ),
			'home_link'      => (bool) $settings->get( 'breadcrumbs.home_link', true ),
			'show_home_icon' => (bool) $settings->get( 'breadcrumbs.show_home_icon', false ),
			'show_current'   => (bool) $settings->get( 'breadcrumbs.show_current', true ),
			'show_count'     => (bool) $settings->get( 'breadcrumbs.show_count', false ),
			'prefix'         => (string) $settings->get( 'breadcrumbs.prefix', __( 'شما اینجا هستید:', 'hoosh-seo' ) ),
			'rich_snippet'   => (bool) $settings->get( 'breadcrumbs.rich_snippet', true ),
			'source'         => (string) $settings->get( 'breadcrumbs.source', 'hoosh' ),
			'hierarchy'      => (bool) $settings->get( 'breadcrumbs.taxonomy_hierarchy', true ),
			'archive_format' => (string) $settings->get( 'breadcrumbs.archive_format', '%%title%%' ),
			'search_format'  => (string) $settings->get( 'breadcrumbs.search_format', __( 'جستجو برای: %%query%%', 'hoosh-seo' ) ),
			'404_label'      => (string) $settings->get( 'breadcrumbs.404_label', __( 'صفحه پیدا نشد', 'hoosh-seo' ) ),
			'size'           => (string) $settings->get( 'breadcrumbs.size', 'medium' ),
			'post_types'     => (array) $settings->get( 'breadcrumbs.post_types', array() ),
		);
	}

	/**
	 * Build the trail.
	 *
	 * @return array
	 */
	public static function trail() {
		$args  = self::args();
		$items = array();

		$items[] = array(
			'name'    => $args['home_label'],
			'url'     => home_url( '/' ),
			'is_home' => true,
		);

		$front = (bool) get_option( 'show_on_front' ) && 'page' === get_option( 'show_on_front' ) ? false : false;
		unset( $front );

		if ( is_front_page() ) {
			$items[] = array( 'name' => __( 'صفحه نخست', 'hoosh-seo' ), 'url' => '' );
			return self::finalize( $items );
		}

		if ( is_singular() ) {
			$post_id   = (int) get_queried_object_id();
			$post_type = get_post_type( $post_id );

			$has_archive = get_post_type_object( $post_type );
			if ( $has_archive && ! empty( $has_archive->has_archive ) ) {
				$archive_link = get_post_type_archive_link( $post_type );
				if ( $archive_link ) {
					$items[] = array( 'name' => $has_archive->labels->name, 'url' => $archive_link );
				}
			}

			// Ancestors (hierarchical pages).
			$ancestors = array_values( array_reverse( get_ancestors( $post_id, $post_type ) ) );
			foreach ( $ancestors as $ancestor ) {
				$items[] = array(
					'name' => get_the_title( (int) $ancestor ),
					'url'  => get_permalink( (int) $ancestor ),
				);
			}

			// Category hierarchy for posts / products.
			if ( $args['hierarchy'] ) {
				$tax = 'product' === $post_type && taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category';
				if ( taxonomy_exists( $tax ) ) {
					$terms = get_the_terms( $post_id, $tax );
					if ( $terms && ! is_wp_error( $terms ) ) {
						$term  = self::pick_term( $terms );
						$chain = get_ancestors( (int) $term->term_id, $tax );
						$chain = array_reverse( $chain );
						foreach ( $chain as $parent_id ) {
							$parent = get_term( (int) $parent_id, $tax );
							if ( $parent && ! is_wp_error( $parent ) ) {
								$link = get_term_link( $parent );
								$items[] = array(
									'name' => $parent->name,
									'url'  => is_wp_error( $link ) ? '' : $link,
								);
							}
						}
						$link    = get_term_link( $term );
						$items[] = array(
							'name' => $term->name,
							'url'  => is_wp_error( $link ) ? '' : $link,
						);
					}
				}
			}

			$items[] = array( 'name' => get_the_title( $post_id ), 'url' => '' );
			return self::finalize( $items );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) ) {
				$tax_object = get_taxonomy( $term->taxonomy );
				if ( $tax_object && $tax_object->has_archive ) {
					// Nothing extra to add for most taxonomies; keep hierarchy only.
					unset( $tax_object );
				}
				foreach ( array_reverse( get_ancestors( (int) $term->term_id, $term->taxonomy ) ) as $parent_id ) {
					$parent = get_term( (int) $parent_id, $term->taxonomy );
					if ( $parent && ! is_wp_error( $parent ) ) {
						$link = get_term_link( $parent );
						$items[] = array( 'name' => $parent->name, 'url' => is_wp_error( $link ) ? '' : $link );
					}
				}
				$items[] = array(
					'name'  => Helpers::render_vars( $args['archive_format'], array( 'title' => $term->name ) ),
					'url'   => '',
					'count' => (int) $term->count,
				);
			}
			return self::finalize( $items );
		}

		if ( is_post_type_archive() ) {
			$object = get_queried_object();
			$items[] = array(
				'name' => $object && ! is_wp_error( $object ) ? $object->labels->name : __( 'بایگانی', 'hoosh-seo' ),
				'url'  => '',
			);
			return self::finalize( $items );
		}

		if ( is_author() ) {
			$user = get_queried_object();
			$items[] = array(
				'name' => $user ? sprintf( /* translators: %s author */ __( 'نوشته‌های %s', 'hoosh-seo' ), $user->display_name ) : __( 'نویسنده', 'hoosh-seo' ),
				'url'  => '',
			);
			return self::finalize( $items );
		}

		if ( is_date() ) {
			$label = '';
			if ( function_exists( 'ha_get_date_archive_label' ) ) {
				$label = ha_get_date_archive_label();
			} else {
				$label = preg_replace( '/^\d+([-–])/', '', wp_strip_all_tags( get_the_archive_title() ) );
			}
			$items[] = array( 'name' => $label ?: __( 'بایگانی تاریخ', 'hoosh-seo' ), 'url' => '' );
			return self::finalize( $items );
		}

		if ( is_search() ) {
			$items[] = array(
				'name'  => Helpers::render_vars( $args['search_format'], array( 'query' => get_search_query() ) ),
				'url'   => '',
				'count' => (int) $GLOBALS['wp_query']->found_posts,
			);
			return self::finalize( $items );
		}

		if ( is_404() ) {
			$items[] = array( 'name' => $args['404_label'], 'url' => '' );
			return self::finalize( $items );
		}

		if ( is_front_page() || is_home() ) {
			return self::finalize( $items );
		}

		$title = get_the_title();
		if ( $title ) {
			$items[] = array( 'name' => $title, 'url' => '' );
		}

		return self::finalize( $items );
	}

	/**
	 * Keep the deepest term when several are attached.
	 *
	 * @param array $terms Terms.
	 * @return object
	 */
	protected static function pick_term( $terms ) {
		$best = $terms[0];
		foreach ( $terms as $term ) {
			if ( count( get_ancestors( (int) $term->term_id, $term->taxonomy ) ) > count( get_ancestors( (int) $best->term_id, $best->taxonomy ) ) ) {
				$best = $term;
			}
		}
		/**
		 * Filter the term chosen for the breadcrumb hierarchy.
		 *
		 * @param object $best Chosen term.
		 * @param array  $terms All terms.
		 */
		return apply_filters( 'hoosh_seo_breadcrumb_term', $best, $terms );
	}

	/**
	 * Drop the trailing duplicate and enforce the "show current" option.
	 *
	 * @param array $items Raw items.
	 * @return array
	 */
	protected static function finalize( $items ) {
		$args  = self::args();
		$items = array_values( array_filter( (array) $items, array( __CLASS__, 'has_name' ) ) );

		if ( ! $args['show_home_link'] && isset( $items[0] ) && ! empty( $items[0]['is_home'] ) ) {
			array_shift( $items );
		}

		$last = end( $items );
		if ( is_array( $last ) && ! $args['show_current'] ) {
			array_pop( $items );
		}

		// Never two identical consecutive names.
		$clean = array();
		foreach ( $items as $item ) {
			$previous = end( $clean );
			if ( $previous && $previous['name'] === $item['name'] ) {
				continue;
			}
			$clean[] = $item;
		}

		/**
		 * Filter the breadcrumb trail.
		 *
		 * @param array $clean Items with name/url.
		 */
		$clean = apply_filters( 'hoosh_seo_breadcrumbs', $clean );

		return array_values( (array) $clean );
	}

	/**
	 * Filter helper.
	 *
	 * @param array $item Item.
	 * @return bool
	 */
	public static function has_name( $item ) {
		return is_array( $item ) && ! empty( $item['name'] );
	}

	/**
	 * Render HTML.
	 *
	 * @param array $args Overrides.
	 * @return string
	 */
	public static function render( $args = array() ) {
		$args  = wp_parse_args( $args, self::args() );
		$items = self::trail();
		if ( ! $items ) {
			return '';
		}

		$allowed = array(
			'nav'    => array( 'class' => array(), 'aria-label' => array() ),
			'ol'     => array(),
			'li'     => array( 'class' => array() ),
			'span'   => array( 'class' => array() ),
			'a'      => array( 'href' => array(), 'rel' => array(), 'class' => array() ),
			'svg'    => array( 'xmlns' => array(), 'viewBox' => array(), 'width' => array(), 'height' => array(), 'aria-hidden' => array(), 'focusable' => array(), 'class' => array() ),
			'path'   => array( 'd' => array(), 'fill' => array() ),
		);

		$out = '<nav class="hoosh-crumbs hoosh-crumbs--' . esc_attr( $args['size'] ) . '" aria-label="' . esc_attr__( 'مسیر راهنما', 'hoosh-seo' ) . '"' . ( $args['rich_snippet'] ? '' : ' itemscope="itemscope" itemtype="https://schema.org/BreadcrumbList"' ) . '>';
		if ( $args['prefix'] ) {
			$out .= '<span class="hoosh-crumbs__prefix">' . esc_html( $args['prefix'] ) . '</span>';
		}
		$out .= '<ol class="hoosh-crumbs__list">';

		$total = count( $items );
		foreach ( $items as $index => $item ) {
			$is_last = ( $index + 1 ) === $total;
			$out    .= '<li class="hoosh-crumbs__item' . ( $is_last ? ' is-current' : '' ) . '"' . ( $args['rich_snippet'] ? '' : ' itemprop="itemListElement" itemscope="itemscope" itemtype="https://schema.org/ListItem"' ) . '>';

			if ( $index > 0 ) {
				$out .= '<span class="' . esc_attr( $args['sep_class'] ) . '" aria-hidden="true">' . esc_html( $args['separator'] ) . '</span>';
			}

			$label = (string) $item['name'];
			if ( ! empty( $item['count'] ) && $args['show_count'] ) {
				$label .= ' <span class="hoosh-crumbs__count">(' . esc_html( Helpers::number( (int) $item['count'] ) ) . ')</span>';
			}

			$icon = '';
			if ( $args['show_home_icon'] && ! empty( $item['is_home'] ) ) {
				$icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path d="M12 3l9 8h-2v9h-5v-6H10v6H5v-9H3z" fill="currentColor"/></svg>';
			}

			if ( ! $is_last && ! empty( $item['url'] ) ) {
				$out .= $icon . '<a href="' . esc_url( $item['url'] ) . '"' . ( $args['rich_snippet'] ? '' : ' itemprop="item"' ) . '>' . wp_kses( $label, $allowed ) . '</a>';
			} else {
				$out .= $icon . '<span' . ( $args['rich_snippet'] ? ' aria-current="page"' : ' itemprop="item"' ) . ' class="hoosh-crumbs__current">' . wp_kses( $label, $allowed ) . '</span>';
			}

			if ( ! $args['rich_snippet'] ) {
				$out .= '<meta itemprop="position" content="' . esc_attr( (string) ( $index + 1 ) ) . '" />';
			}
			$out .= '</li>';
		}

		$out .= '</ol></nav>';

		return $out;
	}

	/**
	 * Echo the trail (template tag).
	 *
	 * @param array $args Overrides.
	 */
	public static function the_breadcrumbs( $args = array() ) {
		echo self::render( (array) $args ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Shortcode.
	 *
	 * @param array $atts Atts.
	 * @return string
	 */
	public function shortcode( $atts ) {
		unset( $atts );
		$args = self::args();
		if ( 'none' === $args['source'] ) {
			return '';
		}
		return self::render();
	}

	/**
	 * BreadcrumbList node for the schema graph.
	 *
	 * @param string $id Node @id.
	 * @return array
	 */
	public static function schema( $id = 'breadcrumb-#0' ) {
		$items = self::trail();
		if ( ! $items ) {
			return array();
		}
		$list = array();
		foreach ( $items as $index => $item ) {
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => (string) $item['name'],
			);
			if ( ! empty( $item['url'] ) && ( $index + 1 ) < count( $items ) ) {
				$entry['item'] = esc_url_raw( $item['url'] );
			}
			$list[] = $entry;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $id,
			'itemListElement' => $list,
		);
	}

	/**
	 * Studio preview: trail + markup + schema, for a chosen URL or post.
	 *
	 * @param array $query Query (post_id or url).
	 * @return array
	 */
	public static function preview( $query = array() ) {
		$post_id = isset( $query['post_id'] ) ? (int) $query['post_id'] : 0;
		if ( $post_id ) {
			$url = get_permalink( $post_id );
		} else {
			$url = isset( $query['url'] ) ? esc_url_raw( (string) $query['url'] ) : home_url( add_query_arg( array() ) );
		}

		$saved_global = $GLOBALS['wp_query'];
		$saved_post   = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		$trail_html = '';
		$schema     = array();
		$note       = '';

		if ( $url ) {
			$parsed = wp_parse_url( set_url_scheme( $url, is_ssl() ? 'https' : 'https' ) );
			$path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
			$post   = $post_id ? get_post( $post_id ) : null;
			if ( $post ) {
				$GLOBALS['post'] = $post;
				setup_postdata( $post );
			}
			$trail = self::trail_from( $path, $post );
			$trail_html = self::render_trail( $trail );
			$schema     = self::render_trail_schema( $trail );
			$note = $post ? __( 'پیش‌نمایش بر اساس ساختار واقعی نوشته.', 'hoosh-seo' ) : __( 'پیش‌نمایش بر اساس مسیر آدرس محاسبه شده است.', 'hoosh-seo' );
			if ( $post ) {
				wp_reset_postdata();
			}
		}

		$GLOBALS['wp_query'] = $saved_global;
		if ( $saved_post ) {
			$GLOBALS['post'] = $saved_post;
		}

		return array(
			'url'    => $url,
			'html'   => $trail_html,
			'trail'  => $trail_html ? self::decode_trail( $trail_html ) : array(),
			'schema' => $schema,
			'note'   => $note,
		);
	}

	/**
	 * Build a trail for an arbitrary path without the query stack.
	 *
	 * @param string      $path Path.
	 * @param \WP_Post|null $post Post.
	 * @return array
	 */
	protected static function trail_from( $path, $post = null ) {
		$args  = self::args();
		$items = array( array( 'name' => $args['home_label'], 'url' => home_url( '/' ) ) );

		if ( $post ) {
			$ancestors = array_reverse( get_ancestors( (int) $post->ID, $post->post_type ) );
			foreach ( $ancestors as $ancestor ) {
				$items[] = array( 'name' => get_the_title( (int) $ancestor ), 'url' => get_permalink( (int) $ancestor ) );
			}
			if ( $args['hierarchy'] && taxonomy_exists( 'category' ) && 'post' === $post->post_type ) {
				$terms = get_the_terms( (int) $post->ID, 'category' );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$term = self::pick_term( $terms );
					$link = get_term_link( $term );
					$items[] = array( 'name' => $term->name, 'url' => is_wp_error( $link ) ? '' : $link );
				}
			}
			$items[] = array( 'name' => get_the_title( $post ), 'url' => '' );
			return $items;
		}

		$parts = array_values( array_filter( explode( '/', trim( (string) $path, '/' ) ) ) );
		$acc   = '';
		foreach ( $parts as $part ) {
			$acc .= '/' . $part;
			$name = ucwords( str_replace( array( '-', '_' ), ' ', $part ) );
			if ( 'category' === $part || 'tag' === $part ) {
				continue;
			}
			$term = term_exists( $part );
			if ( is_array( $term ) ) {
				$term_obj = get_term( (int) $term['term_id'], (string) $term['taxonomy'] );
				if ( $term_obj && ! is_wp_error( $term_obj ) ) {
					$name = $term_obj->name;
				}
			}
			$items[] = array( 'name' => $name, 'url' => home_url( $acc ) );
		}
		if ( isset( $items[ count( $items ) - 1 ] ) ) {
			$items[ count( $items ) - 1 ]['url'] = '';
		}
		return $items;
	}

	/**
	 * Render a given trail (used by preview).
	 *
	 * @param array $trail Trail.
	 * @return string
	 */
	protected static function render_trail( $trail ) {
		$args = self::args();
		$out  = '<nav class="hoosh-crumbs"><ol class="hoosh-crumbs__list">';
		$total = count( $trail );
		foreach ( $trail as $index => $item ) {
			$is_last = ( $index + 1 ) === $total;
			$out    .= '<li class="hoosh-crumbs__item">';
			if ( $index > 0 ) {
				$out .= '<span class="' . esc_attr( $args['sep_class'] ) . '">' . esc_html( $args['separator'] ) . '</span>';
			}
			if ( ! $is_last && ! empty( $item['url'] ) ) {
				$out .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>';
			} else {
				$out .= '<span class="hoosh-crumbs__current">' . esc_html( $item['name'] ) . '</span>';
			}
			$out .= '</li>';
		}
		$out .= '</ol></nav>';
		return $out;
	}

	/**
	 * Schema for a given trail.
	 *
	 * @param array $trail Trail.
	 * @return array
	 */
	protected static function render_trail_schema( $trail ) {
		$list = array();
		foreach ( $trail as $index => $item ) {
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => (string) $item['name'],
			);
			if ( ! empty( $item['url'] ) ) {
				$entry['item'] = $item['url'];
			}
			$list[] = $entry;
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list,
		);
	}

	/**
	 * Extract readable items back out of the rendered markup (for the Studio).
	 *
	 * @param string $html Markup.
	 * @return array
	 */
	protected static function decode_trail( $html ) {
		$out = array();
		if ( preg_match_all( '#<(a|span)[^>]*>(.*?)</\\1>#i', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$out[] = array(
					'name' => wp_strip_all_tags( $match[2] ),
					'url'  => 'a' === strtolower( $match[1] ) ? $match[0] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * Whether we should render at all (source handling).
	 *
	 * @return bool
	 */
	public static function active() {
		$args = self::args();
		if ( 'none' === $args['source'] ) {
			return false;
		}
		if ( 'yoast' === $args['source'] ) {
			return function_exists( 'yoast_breadcrumb' );
		}
		if ( 'rankmath' === $args['source'] ) {
			return function_exists( 'rank_math_the_breadcrumbs' );
		}
		return true;
	}
}
