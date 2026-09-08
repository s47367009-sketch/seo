<?php
/**
 * XML + HTML sitemaps: posts, pages, CPTs, taxonomies, images, video, news, authors.
 *
 * Routes: /sitemap_index.xml, /sitemap-{key}[-N].xml, and the query-var
 * fallback ?hoosh_sitemap=key&paged=N for hosts without pretty permalinks.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sitemap
 */
final class Sitemap {

	/**
	 * Singleton.
	 *
	 * @var Sitemap|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Sitemap
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
		add_shortcode( 'hoosh_sitemap', array( $this, 'html_shortcode' ) );
		add_action( 'transition_post_status', array( $this, 'on_publish' ), 20, 3 );
		add_filter( 'wp_sitemaps_enabled', array( $this, 'disable_core_sitemaps' ) );
		add_action( 'hoosh_seo_head', array( $this, 'print_href' ) );
	}

	/**
	 * Turn WP's built-in sitemap off when ours is enabled.
	 *
	 * @param bool $enabled Enabled.
	 * @return bool
	 */
	public function disable_core_sitemaps( $enabled ) {
		if ( \hoosh_seo()->settings->get( 'index.strip_wp_sitemap', false ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Advertise the sitemap in the head.
	 */
	public function print_href() {
		if ( ! $this->enabled() ) {
			return;
		}
		echo '<link rel="alternate" type="application/xml" title="' . esc_attr__( 'نقشه سایت XML', 'hoosh-seo' ) . '" href="' . esc_url( home_url( '/sitemap_index.xml' ) ) . '">' . "\n";
	}

	/**
	 * Is the sitemap feature on?
	 *
	 * @return bool
	 */
	public function enabled() {
		return (bool) \hoosh_seo()->settings->get( 'sitemap.enabled', true );
	}

	/**
	 * Every child sitemap descriptor.
	 *
	 * @return array key => [ label, count, loc ]
	 */
	public static function children() {
		$settings = \hoosh_seo()->settings;
		$out      = array();
		$excluded = (array) $settings->get( 'advanced.exclude_post_types', array() );

		$types = self::sitemap_post_types();
		foreach ( $types as $type ) {
			$count = self::count_type( $type );
			if ( ! $count ) {
				continue;
			}
			$pages = (int) ceil( $count / max( 50, (int) $settings->get( 'sitemap.per_page', 1000 ) ) );
			$out[ $type ] = array(
			'label'   => $type,
				'object'  => get_post_type_object( $type ),
				'count'   => (int) $count,
				'pages'   => $pages,
				'loc'     => self::url( $type ),
				'source'  => 'post_type',
			);
		}

		foreach ( self::sitemap_taxonomies() as $tax ) {
			$count = self::count_taxonomy( $tax );
			if ( ! $count ) {
				continue;
			}
			$out[ 'tax-' . $tax ] = array(
				'label'   => $tax,
				'object'  => get_taxonomy( $tax ),
				'count'   => (int) $count,
				'pages'   => (int) ceil( $count / max( 50, (int) $settings->get( 'sitemap.per_page', 1000 ) ) ),
				'loc'     => self::url( 'tax-' . $tax ),
				'source'  => 'taxonomy',
			);
		}

		if ( $settings->get( 'sitemap.image_sitemap.enabled', true ) ) {
			$image_count = (int) self::count_images();
			$per_page    = max( 50, (int) $settings->get( 'sitemap.per_page', 1000 ) );
			$out['images'] = array(
				'label'  => __( 'تصاویر', 'hoosh-seo' ),
				'count'  => $image_count,
				'pages'  => $image_count ? (int) ceil( $image_count / $per_page ) : 1,
				'loc'    => self::url( 'images' ),
				'source' => 'image',
			);
		}
		if ( $settings->get( 'sitemap.video_sitemap.enabled', false ) ) {
			$out['video'] = array(
				'label'  => __( 'ویدیو', 'hoosh-seo' ),
				'count'  => (int) self::count_type( 'video' ) ?: (int) self::count_videos(),
				'pages'  => 1,
				'loc'    => self::url( 'video' ),
				'source' => 'video',
			);
		}
		if ( $settings->get( 'sitemap.news_sitemap.enabled', false ) ) {
			$out['news'] = array(
				'label'  => __( 'اخبار', 'hoosh-seo' ),
				'count'  => (int) self::count_news(),
				'pages'  => 1,
				'loc'    => self::url( 'news' ),
				'source' => 'news',
			);
		}
		if ( $settings->get( 'sitemap.author_sitemap.enabled', false ) ) {
			$out['authors'] = array(
				'label'  => __( 'نویسندگان', 'hoosh-seo' ),
				'count'  => (int) count( self::authors() ),
				'pages'  => 1,
				'loc'    => self::url( 'authors' ),
				'source' => 'users',
			);
		}

		unset( $excluded );
		return $out;
	}

	/**
	 * Sitemap URL (pretty when available, query-var otherwise).
	 *
	 * @param string $key   Child key.
	 * @param int    $paged Page.
	 * @return string
	 */
	public static function url( $key = 'index', $paged = 0 ) {
		$pretty = (bool) get_option( 'permalink_structure' );
		if ( 'index' === $key ) {
			return $pretty ? home_url( '/sitemap_index.xml' ) : add_query_arg( 'hoosh_sitemap', 'index', home_url( '/' ) );
		}
		if ( $pretty ) {
			$name = 'index' === $key ? 'sitemap_index.xml' : 'sitemap-' . $key . ( $paged > 1 ? '-' . $paged : '' ) . '.xml';
			return home_url( '/' . $name );
		}
		$url = add_query_arg( 'hoosh_sitemap', $key, home_url( '/' ) );
		return $paged > 1 ? add_query_arg( 'paged', $paged, $url ) : $url;
	}

	/**
	 * Post types that belong in the sitemap.
	 *
	 * @return array
	 */
	public static function sitemap_post_types() {
		$settings = \hoosh_seo()->settings;
		$chosen   = (array) $settings->get( 'sitemap.object_types', array() );
		$all      = Helpers::post_types();

		if ( $chosen ) {
			return array_values( array_intersect( array_keys( $all ), $chosen ) );
		}
		return array_keys( $all );
	}

	/**
	 * Taxonomies in the sitemap.
	 *
	 * @return array
	 */
	public static function sitemap_taxonomies() {
		$settings = \hoosh_seo()->settings;
		$chosen   = (array) $settings->get( 'sitemap.taxonomies', array() );
		$all      = array_keys( get_taxonomies( array( 'public' => true ), 'objects' ) );
		if ( $chosen ) {
			return array_values( array_intersect( $all, $chosen ) );
		}
		if ( $settings->get( 'index.index_categories', true ) ) {
			$out = array( 'category' );
		} else {
			$out = array();
		}
		if ( $settings->get( 'index.index_tags', true ) ) {
			$out[] = 'post_tag';
		}
		return array_values( array_intersect( $all, $out ) );
	}

	/**
	 * Count public, indexable posts of a type.
	 *
	 * @param string $type Post type.
	 * @return int
	 */
	public static function count_type( $type ) {
		return (int) Helpers::cache(
			'sitemap-count-' . $type,
			function () use ( $type ) {
				$query = new \WP_Query(
					array(
						'post_type'      => $type,
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'no_found_rows'  => false,
						'meta_query'     => array(
							'relation' => 'OR',
							array(
								'relation' => 'AND',
								array( 'key' => '_hs_robots', 'compare' => 'NOT EXISTS' ),
							),
							array(
								'key'     => '_hs_robots',
								'value'   => 's:5:"index";b:0',
								'compare' => 'NOT LIKE',
							),
						),
					)
				);
				return (int) $query->found_posts;
			},
			600
		);
	}

	/**
	 * Count terms.
	 *
	 * @param string $tax Taxonomy.
	 * @return int
	 */
	public static function count_taxonomy( $tax) {
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => true,
				'fields'     => 'count',
			)
		);
		return (int) $terms;
	}

	/**
	 * Count attachments with images.
	 *
	 * @return int
	 */
	public static function count_images() {
		global $wpdb;
		$types = (array) \hoosh_seo()->settings->get( 'sitemap.image_sitemap.types', array( 'post', 'page' ) );
		$types = array_values( array_filter( $types, 'post_type_exists' ) );
		if ( ! $types ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql          = "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($placeholders)"; // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $types ) );
	}

	/**
	 * Videos registered for the video sitemap.
	 *
	 * @return int
	 */
	public static function count_videos() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key='_hs_video'" ); // phpcs:ignore
	}

	/**
	 * News items in the window.
	 *
	 * @return int
	 */
	public static function count_news() {
		$type  = (string) \hoosh_seo()->settings->get( 'sitemap.news_sitemap.post_type', 'post' );
		$days  = (int) \hoosh_seo()->settings->get( 'sitemap.news_sitemap.days', 2 );
		$query = new \WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'date_query'     => array( array( 'after' => $days . ' days ago' ) ),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Authors eligible for the author sitemap.
	 *
	 * @return array
	 */
	public static function authors() {
		$settings = \hoosh_seo()->settings->get( 'sitemap.author_sitemap', array() );
		$min      = isset( $settings['min_posts'] ) ? (int) $settings['min_posts'] : 5;
		$roles    = isset( $settings['roles'] ) ? (array) $settings['roles'] : array( 'administrator', 'editor' );

		$authors = array();
		foreach ( get_users( array( 'who' => 'authors' ) ) as $user ) {
			if ( $roles && ! array_intersect( $roles, (array) $user->roles ) ) {
				continue;
			}
			if ( (int) count_user_posts( $user->ID ) < $min ) {
				continue;
			}
			$authors[ $user->ID ] = $user;
		}
		return $authors;
	}

	/**
	 * Serve a sitemap (called from App::maybe_serve).
	 *
	 * @param string $key   Child key or "index".
	 * @param int    $paged Page number.
	 */
	public static function serve( $key = 'index', $paged = 0 ) {
		if ( ! self::instance()->enabled() ) {
			status_header( 404 );
			return;
		}

		$paged = max( 0, (int) $paged );
		$cache_key = 'sitemap-' . $key . '-' . $paged;
		$body      = Helpers::cache(
			$cache_key,
			function () use ( $key, $paged ) {
				return 'index' === $key || '' === $key ? self::render_index() : self::render_child( $key, $paged );
			},
			(int) \hoosh_seo()->settings->get( 'sitemap.cache_ttl', 3600 )
		);

		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Sitemap: HooshSEO' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * The sitemap index.
	 *
	 * @return string
	 */
	protected static function render_index() {
		$children = self::children();
		$xml      = self::header( 'sitemapindex' );
		foreach ( $children as $key => $child ) {
			for ( $page = 1; $page <= max( 1, (int) $child['pages'] ); $page++ ) {
				$loc      = 1 === $page ? $child['loc'] : self::url( $key, $page );
				$lastmod  = self::lastmod_for( $key );
				$xml     .= "\t<sitemap>\n\t\t<loc>" . self::cdata( $loc ) . "</loc>\n";
				if ( $lastmod ) {
					$xml .= "\t\t<lastmod>" . esc_html( $lastmod ) . "</lastmod>\n";
				}
				$xml .= "\t</sitemap>\n";
			}
		}
		$children = apply_filters( 'hoosh_seo_sitemap_children', $children );
		foreach ( $children as $key => $child ) {
			if ( ! isset( $child['external'] ) || ! $child['external'] ) {
				continue;
			}
			$xml .= "\t<sitemap>\n\t\t<loc>" . self::cdata( $child['loc'] ) . "</loc>\n\t</sitemap>\n";
		}
		$xml .= '</sitemapindex>';
		return $xml;
	}

	/**
	 * A child sitemap.
	 *
	 * @param string $key   Key.
	 * @param int    $paged Page.
	 * @return string
	 */
	protected static function render_child( $key, $paged = 1 ) {
		$settings = \hoosh_seo()->settings;
		$per_page = max( 50, (int) $settings->get( 'sitemap.per_page', 1000 ) );
		$paged    = max( 1, (int) $paged );
		$entries  = array();

		if ( 'images' === $key ) {
			$entries = self::image_entries( $per_page, $paged );
		} elseif ( 'video' === $key ) {
			$entries = self::entries( self::query_posts_by_type( $settings->get( 'sitemap.video_sitemap.types', array( 'post' ) ), $per_page, $paged ), 'video' );
		} elseif ( 'news' === $key ) {
			$entries = self::entries( self::query_news(), 'news' );
		} elseif ( 'authors' === $key ) {
			$entries = self::author_entries();
		} elseif ( 0 === strpos( $key, 'tax-' ) ) {
			$entries = self::query_terms( substr( $key, 4 ), $per_page, $paged );
		} else {
			$entries = self::entries( self::query_posts( $key, $per_page, $paged ), 'post' );
		}

		$xml = self::header( 'urlset' );
		foreach ( $entries as $entry ) {
			$xml .= self::url_tag( $entry );
		}

		/**
		 * Filter sitemap entries for a key.
		 *
		 * @param array  $entries Entries.
		 * @param string $key     Sitemap key.
		 * @param int    $paged   Page.
		 */
		$extra = apply_filters( 'hoosh_seo_sitemap_entries', array(), $key, $paged );
		foreach ( $extra as $entry ) {
			$xml .= self::url_tag( $entry );
		}

		$xml .= '</urlset>';
		return $xml;
	}

	/**
	 * XML prologue.
	 *
	 * @param string $root urlset|sitemapindex.
	 * @return string
	 */
	protected static function header( $root ) {
		$ns = 'urlset' === $root
			? 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"'
			: 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<!-- generated by HooshSEO ' . esc_html( HOOSH_SEO_VERSION ) . ' -->' . "\n" . '<' . $root . ' ' . $ns . '>' . "\n";
	}

	/**
	 * One <url> block.
	 *
	 * @param array $entry Entry data.
	 * @return string
	 */
	protected static function url_tag( $entry ) {
		if ( empty( $entry['loc'] ) ) {
			return '';
		}
		$xml = "\t<url>\n\t\t<loc>" . self::cdata( $entry['loc'] ) . "</loc>\n";

		if ( ! empty( $entry['lastmod'] ) && \hoosh_seo()->settings->get( 'sitemap.lastmod', true ) ) {
			$xml .= "\t\t<lastmod>" . esc_html( $entry['lastmod'] ) . "</lastmod>\n";
		}
		if ( ! empty( $entry['changefreq'] ) && 'auto' !== \hoosh_seo()->settings->get( 'sitemap.changefreq', 'auto' ) ) {
			$xml .= "\t\t<changefreq>" . esc_html( $entry['changefreq'] ) . "</changefreq>\n";
		}
		if ( ! empty( $entry['priority'] ) && 'auto' !== \hoosh_seo()->settings->get( 'sitemap.priority', 'auto' ) ) {
			$xml .= "\t\t<priority>" . esc_html( number_format_i18n( (float) $entry['priority'], 1 ) ) . "</priority>\n";
		} elseif ( 'auto' === \hoosh_seo()->settings->get( 'sitemap.priority', 'auto' ) && isset( $entry['priority'] ) ) {
			$xml .= "\t\t<priority>" . esc_html( number_format_i18n( (float) $entry['priority'], 1 ) ) . "</priority>\n";
		}

		foreach ( (array) ( $entry['images'] ?? array() ) as $image ) {
			$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . self::cdata( $image['url'] ) . "</image:loc>\n";
			if ( ! empty( $image['title'] ) ) {
				$xml .= "\t\t\t<image:title>" . esc_html( $image['title'] ) . "</image:title>\n";
			}
			if ( ! empty( $image['caption'] ) ) {
				$xml .= "\t\t\t<image:caption>" . esc_html( $image['caption'] ) . "</image:caption>\n";
			}
			$xml .= "\t\t</image:image>\n";
		}

		foreach ( (array) ( $entry['videos'] ?? array() ) as $video ) {
			$xml .= "\t\t<video:video>\n";
			foreach ( $video as $tag => $value ) {
				if ( '' === $value ) {
					continue;
				}
				$xml .= "\t\t\t<video:" . esc_html( $tag ) . ">" . ( 'description' === $tag || 'title' === $tag ? esc_html( $value ) : self::cdata( $value ) ) . "</video:" . esc_html( $tag ) . ">\n";
			}
			$xml .= "\t\t</video:video>\n";
		}

		if ( ! empty( $entry['news'] ) ) {
			$news = $entry['news'];
			$xml .= "\t\t<news:news>\n\t\t\t<news:publication>\n\t\t\t\t<news:name>" . esc_html( $news['name'] ) . "</news:name>\n\t\t\t\t<news:language>" . esc_html( $news['language'] ) . "</news:language>\n\t\t\t</news:publication>\n\t\t\t<news:publication_date>" . esc_html( $news['date'] ) . "</news:publication_date>\n\t\t\t<news:title>" . esc_html( $news['title'] ) . "</news:title>\n\t\t\t<news:keywords>" . esc_html( $news['keywords'] ) . "</news:keywords>\n\t\t</news:news>\n";
		}

		foreach ( (array) ( $entry['alternates'] ?? array() ) as $alt ) {
			$xml .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $alt['hreflang'] ) . '" href="' . esc_url( $alt['href'] ) . "\" />\n";
		}

		return $xml . "\t</url>\n";
	}

	/**
	 * CDATA with escaping for the closing marker.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	protected static function cdata( $value ) {
		return '<![CDATA[' . str_replace( ']]>', ']]&gt;', (string) $value ) . ']]>';
	}

	/**
	 * Fetch + hydrate post entries.
	 *
	 * @param array  $ids   Post IDs.
	 * @param string $mode  post|image|video|news|term.
	 * @return array
	 */
	protected static function entries( $ids, $mode = 'post' ) {
		$out = array();
		foreach ( (array) $ids as $id ) {
			$id     = (int) $id;
			$robots = Meta::robots( $id );
			if ( empty( $robots['index'] ) && \hoosh_seo()->settings->get( 'sitemap.strip_noindex', true ) ) {
				continue;
			}
			$loc = Meta::canonical( $id );
			if ( ! $loc ) {
				continue;
			}
			$entry = array(
				'loc'        => $loc,
				'lastmod'    => get_post_modified_time( 'c', true, $id ),
				'changefreq' => self::changefreq( $id ),
				'priority'   => self::priority( $id ),
			);

			if ( 'image' === $mode || ( in_array( get_post_type( $id ), (array) \hoosh_seo()->settings->get( 'sitemap.image_sitemap.types', array( 'post', 'page' ) ), true ) && \hoosh_seo()->settings->get( 'sitemap.image_sitemap.enabled', true ) ) ) {
				$entry['images'] = self::images_for( $id );
			}
			if ( 'video' === $mode ) {
				$entry['videos'] = self::videos_for( $id );
			}
			if ( 'news' === $mode ) {
				$entry['news'] = self::news_for( $id );
			}
			if ( \hoosh_seo()->settings->get( 'sitemap.alternate', true ) ) {
				$entry['alternates'] = self::alternates( $id );
			}

			$out[ $id ] = $entry;
		}
		return $out;
	}

	/**
	 * Term entries.
	 *
	 * @param array $ids Term IDs.
	 * @return array
	 */
	protected static function term_entries( $ids ) {
		$out = array();
		foreach ( (array) $ids as $term_id ) {
			$term = get_term( (int) $term_id );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$noindex = (array) get_option( 'hs_term_' . (int) $term->term_id . '_robots', array() );
			if ( ! empty( $noindex['noindex'] ) ) {
				continue;
			}
			$out[ (int) $term_id ] = array(
				'loc'     => get_term_link( $term ),
				'lastmod' => '',
				'images'  => array(),
			);
		}
		return $out;
	}

	/**
	 * Author entries.
	 *
	 * @return array
	 */
	protected static function author_entries() {
		$out = array();
		foreach ( self::authors() as $user ) {
			$out[ $user->ID ] = array(
				'loc'     => get_author_posts_url( $user->ID ),
				'lastmod' => '',
				'priority' => 0.3,
			);
		}
		return $out;
	}

	/**
	 * Query posts of one type.
	 *
	 * @param string $type     Post type.
	 * @param int    $per_page Per page.
	 * @param int    $paged    Page.
	 * @return array
	 */
	protected static function query_posts( $type, $per_page, $paged ) {
		$exclude = array_map( 'absint', (array) \hoosh_seo()->settings->get( 'sitemap.excluded_posts', array() ) );
		$query   = new \WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'fields'         => 'ids',
				'orderby'        => array( 'modified' => 'DESC', 'ID' => 'DESC' ),
				'post__not_in'   => $exclude,
				'no_found_rows'  => true,
			)
		);
		return $query->posts ? $query->posts : array();
	}

	/**
	 * Query by several types (video sitemap).
	 *
	 * @param array $types    Types.
	 * @param int   $per_page Per page.
	 * @param int   $paged    Page.
	 * @return array
	 */
	protected static function query_posts_by_type( $types, $per_page, $paged ) {
		$query = new \WP_Query(
			array(
				'post_type'      => (array) $types,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => '_hs_video', 'compare' => 'EXISTS' ) ),
			)
		);
		return $query->posts ? $query->posts : array();
	}

	/**
	 * News window.
	 *
	 * @return array
	 */
	protected static function query_news() {
		$type = (string) \hoosh_seo()->settings->get( 'sitemap.news_sitemap.post_type', 'post' );
		$days = (int) \hoosh_seo()->settings->get( 'sitemap.news_sitemap.days', 2 );
		$query = new \WP_Query(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'date_query'     => array( array( 'after' => $days . ' days ago' ) ),
				'no_found_rows'  => true,
			)
		);
		return $query->posts ? $query->posts : array();
	}

	/**
	 * Image sitemap entries.
	 *
	 * Google's image sitemap pairs a page URL (<loc>) with one or more
	 * <image:image> blocks, so entries are built from the pages that carry
	 * images rather than from attachment rows.
	 *
	 * @param int $per_page Entries per page.
	 * @param int $paged    Page number.
	 * @return array
	 */
	protected static function image_entries( $per_page = 1000, $paged = 1 ) {
		$ids     = self::query_images( $per_page, $paged );
		$entries = self::entries( $ids, 'image' );

		// Pages with nothing to advertise would only add empty <url> blocks.
		foreach ( $entries as $key => $entry ) {
			if ( empty( $entry['images'] ) ) {
				unset( $entries[ $key ] );
			}
		}
		return $entries;
	}

	/**
	 * Images query.
	 *
	 * @param int $per_page Per page.
	 * @param int $paged    Page.
	 * @return array
	 */
	protected static function query_images( $per_page, $paged ) {
		$types   = (array) \hoosh_seo()->settings->get( 'sitemap.image_sitemap.types', array( 'post', 'page' ) );
		$types   = array_values( array_filter( $types, 'post_type_exists' ) );
		if ( ! $types ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, (int) $per_page ),
				'paged'          => max( 1, (int) $paged ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Terms for a taxonomy.
	 *
	 * @param string $tax      Taxonomy.
	 * @param int    $per_page Per page.
	 * @param int    $paged    Page.
	 * @return array
	 */
	protected static function query_terms( $tax, $per_page, $paged ) {
		$exclude = array_map( 'absint', (array) \hoosh_seo()->settings->get( 'sitemap.excluded_terms', array() ) );
		$terms   = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => true,
				'fields'     => 'ids',
				'number'     => $per_page,
				'offset'     => ( $paged - 1 ) * $per_page,
				'exclude'    => $exclude,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return self::term_entries( $terms );
	}

	/**
	 * Images to advertise for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected static function images_for( $post_id ) {
		$out = array();
		if ( \hoosh_seo()->settings->get( 'sitemap.image_sitemap.featured', true ) && has_post_thumbnail( $post_id ) ) {
			$id  = get_post_thumbnail_id( $post_id );
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( $src ) {
				$out[] = array(
					'url'     => $src[0],
					'title'   => get_the_title( $id ),
					'caption' => wp_strip_all_tags( get_post_field( 'post_excerpt', $id ) ),
				);
			}
		}
		if ( \hoosh_seo()->settings->get( 'sitemap.image_sitemap.content', false ) ) {
			$content = get_post_field( 'post_content', $post_id );
			if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $m ) ) {
				foreach ( array_slice( $m[1], 0, 10 ) as $src ) {
					$out[] = array( 'url' => $src, 'title' => get_the_title( $post_id ) );
				}
			}
		}
		return array_slice( $out, 0, 10 );
	}

	/**
	 * Video meta for the video sitemap.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected static function videos_for( $post_id ) {
		$video = (array) get_post_meta( $post_id, '_hs_video', true );
		if ( ! $video ) {
			return array();
		}
		return array(
			array(
				'thumbnail_loc' => $video['thumbnail'] ?? '',
				'title'         => $video['title'] ?? get_the_title( $post_id ),
				'description'   => $video['description'] ?? Meta::resolve( $post_id, 'description' ),
				'content_loc'   => $video['content_loc'] ?? '',
				'player_loc'    => $video['player_loc'] ?? '',
				'duration'      => $video['duration'] ?? '',
				'publication_date' => get_the_date( 'c', $post_id ),
				'family_friendly' => isset( $video['family_friendly'] ) ? ( $video['family_friendly'] ? 'yes' : 'no' ) : 'yes',
			),
		);
	}

	/**
	 * News tags.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected static function news_for( $post_id ) {
		$keywords  = Meta::focus_keywords( $post_id );
		$publisher = trim( (string) \hoosh_seo()->settings->get( 'sitemap.news_sitemap.publisher', '' ) );
		return array(
			'name'      => '' !== $publisher ? $publisher : get_bloginfo( 'name' ),
			'language'  => substr( get_locale(), 0, 2 ),
			'date'      => get_the_date( 'c', $post_id ),
			'title'     => get_the_title( $post_id ),
			'keywords'  => $keywords ? implode( ', ', $keywords ) : '',
			'genres'    => (string) ( \hoosh_seo()->settings->get( 'sitemap.news_sitemap.genres', '' ) ),
		);
	}

	/**
	 * hreflang alternates via a filter (WPML/Polylang plug in here).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected static function alternates( $post_id ) {
		$alts = apply_filters( 'hoosh_seo_sitemap_alternates', array(), $post_id );
		return is_array( $alts ) ? $alts : array();
	}

	/**
	 * changefreq heuristic.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected static function changefreq( $post_id ) {
		$forced = (string) \hoosh_seo()->settings->get( 'sitemap.changefreq', 'auto' );
		if ( 'auto' !== $forced ) {
			return $forced;
		}
		$age = ( time() - (int) get_post_modified_time( 'U', true, $post_id ) ) / DAY_IN_SECONDS;
		if ( $age < 7 ) {
			return 'daily';
		}
		if ( $age < 60 ) {
			return 'weekly';
		}
		return 'monthly';
	}

	/**
	 * priority heuristic: front page > pages > fresh posts.
	 *
	 * @param int $post_id Post ID.
	 * @return float
	 */
	protected static function priority( $post_id ) {
		$forced = (string) \hoosh_seo()->settings->get( 'sitemap.priority', 'auto' );
		if ( 'auto' !== $forced ) {
			return (float) $forced;
		}
		if ( (int) get_option( 'page_on_front' ) === (int) $post_id ) {
			return 1.0;
		}
		$type = get_post_type( $post_id );
		if ( 'page' === $type ) {
			return 0.8;
		}
		if ( 'product' === $type ) {
			return 0.9;
		}
		$age = ( time() - (int) get_post_time( 'U', true, $post_id ) ) / DAY_IN_SECONDS;
		if ( $age < 30 ) {
			return 0.7;
		}
		if ( $age < 365 ) {
			return 0.6;
		}
		return 0.5;
	}

	/**
	 * Latest modified date of a sitemap key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	protected static function lastmod_for( $key ) {
		global $wpdb;
		if ( 0 === strpos( $key, 'tax-' ) || in_array( $key, array( 'images', 'authors', 'news', 'video' ), true ) ) {
			return '';
		}
		$date = $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY post_modified_gmt DESC LIMIT 1", $key ) ); // phpcs:ignore
		return $date ? mysql2date( 'c', $date . ' +00:00', false ) : '';
	}

	/**
	 * Ping Google/Bing/IndexNow after publishing.
	 *
	 * @param string   $new   New status.
	 * @param string   $old   Old status.
	 * @param \WP_Post $post  Post.
	 */
	public function on_publish( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old ) {
			return;
		}
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->get( 'sitemap.enabled', true ) || ! $settings->get( 'sitemap.ping_on_update', true ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::sitemap_post_types(), true ) ) {
			return;
		}
		wp_schedule_single_event( time() + 20, 'hoosh_seo_ping_sitemaps' );
		add_action( 'hoosh_seo_ping_sitemaps', array( __CLASS__, 'ping_all' ) );

		if ( $settings->get( 'index.indexnow.auto', true ) ) {
			IndexManager::indexnow( array( Meta::canonical( $post->ID ) ) );
		}
	}

	/**
	 * Ping search engines.
	 *
	 * @return array
	 */
	public static function ping_all() {
		$service = \hoosh_seo()->settings->get( 'sitemap.ping_service', 'google' );
		$urls    = array();
		$index   = self::url( 'index' );
		if ( in_array( $service, array( 'google', 'both' ), true ) ) {
			$urls[] = 'https://www.google.com/ping?sitemap=' . rawurlencode( $index );
		}
		if ( in_array( $service, array( 'bing', 'both' ), true ) ) {
			$urls[] = 'https://www.bing.com/ping?sitemap=' . rawurlencode( $index );
		}
		$results = array();
		foreach ( $urls as $url ) {
			$r = Helpers::http( $url, array( 'timeout' => 10 ) );
			$results[] = array( 'url' => $url, 'ok' => $r['ok'], 'code' => $r['code'] );
		}
		update_option( 'hoosh_seo_last_ping', array( 'time' => time(), 'results' => $results ), false );
		return $results;
	}

	/**
	 * Last ping info for the app.
	 *
	 * @return array
	 */
	public static function ping_status() {
		$last = get_option( 'hoosh_seo_last_ping', array() );
		return array(
			'ago'    => isset( $last['time'] ) ? Helpers::time_ago_fa( (int) $last['time'] ) : __( 'هرگز', 'hoosh-seo' ),
			'results' => $last['results'] ?? array(),
			'url'    => self::url( 'index' ),
			'children' => array(),
		);
	}

	/**
	 * Status payload for the Studio sitemap screen.
	 *
	 * @return array
	 */
	public static function status() {
		$children = array();
		foreach ( self::children() as $key => $child ) {
			$label = $child['object'] && isset( $child['object']->labels->name )
				? $child['object']->labels->name
				: $child['label'];
			$children[] = array(
				'key'    => $key,
				'label'  => is_string( $label ) ? $label : $key,
				'count'  => (int) $child['count'],
				'pages'  => (int) $child['pages'],
				'url'    => $child['loc'],
				'source' => isset( $child['source'] ) ? $child['source'] : 'post_type',
			);
		}
		$ping = get_option( 'hoosh_seo_last_ping', array() );
		return array(
			'enabled'  => self::instance()->enabled(),
			'index'    => self::url( 'index' ),
			'children' => $children,
			'total'    => array_sum( wp_list_pluck( $children, 'count' ) ),
			'ping'     => array(
				'time'  => isset( $ping['time'] ) ? (int) $ping['time'] : 0,
				'ago'   => isset( $ping['time'] ) ? Helpers::time_ago_fa( (int) $ping['time'] ) : __( 'هنوز پینگ نشده', 'hoosh-seo' ),
				'ok'    => ! empty( $ping['results'] ),
			),
			'robots'   => array( 'sitemap_line' => (bool) \hoosh_seo()->settings->get( 'robots.sitemap_line', true ) ),
		);
	}

	/**
	 * [hoosh_sitemap] — HTML sitemap for humans.
	 *
	 * @param array $atts Shortcode atts.
	 * @return string
	 */
	public function html_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'type'    => 'all',
				'orderby' => 'title',
				'style'   => 'list',
				'title'   => '',
			),
			$atts,
			'hoosh_sitemap'
		);

		$html  = '<div class="hoosh-html-sitemap">';
		$html .= $atts['title'] ? '<h2>' . esc_html( $atts['title'] ) . '</h2>' : '';

		$types = 'all' === $atts['type'] ? self::sitemap_post_types() : array_map( 'trim', explode( ',', $atts['type'] ) );

		foreach ( $types as $type ) {
			$object = get_post_type_object( $type );
			$posts  = get_posts(
				array(
					'post_type'      => $type,
					'posts_per_page' => 500,
					'orderby'        => $atts['orderby'],
					'order'          => 'ASC',
				)
			);
			if ( ! $posts ) {
				continue;
			}
			$html .= '<section class="hoosh-html-sitemap__group"><h3>' . esc_html( $object ? $object->labels->name : $type ) . '</h3><ul>';
			foreach ( $posts as $post ) {
				$html .= '<li><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></li>';
			}
			$html .= '</ul></section>';
		}

		foreach ( array( 'category', 'post_tag' ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'number' => 200 ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$label = get_taxonomy( $tax )->labels->name;
			$html .= '<section class="hoosh-html-sitemap__group"><h3>' . esc_html( $label ) . '</h3><ul>';
			foreach ( $terms as $term ) {
				$html .= '<li><a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a> <span>(' . (int) $term->count . ')</span></li>';
			}
			$html .= '</ul></section>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Drop every cached sitemap artefact.
	 *
	 * @return bool
	 */
	public static function flush() {
		Helpers::cache_flush_all();
		foreach ( array( 'sitemap-index', 'sitemap-children' ) as $key ) {
			delete_transient( 'hoosh_' . $key );
		}
		/**
		 * Fires after the sitemap caches are flushed.
		 */
		do_action( 'hoosh_seo_sitemap_flush' );
		return true;
	}

}
