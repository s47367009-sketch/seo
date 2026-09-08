<?php
/**
 * Per-object SEO store + the whole <head> composition.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

use HooshSEO\Modules\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Class Meta
 */
final class Meta {

	/**
	 * Singleton.
	 *
	 * @var Meta|null
	 */
	private static $instance = null;

	/**
	 * Postmeta keys we own.
	 *
	 * @var array
	 */
	private static $keys = array(
		'_hs_title',
		'_hs_description',
		'_hs_keywords',
		'_hs_robots',
		'_hs_canonical',
		'_hs_redirect',
		'_hs_slug',
		'_hs_primary_cat',
		'_hs_social',
		'_hs_schema',
		'_hs_faq',
		'_hs_howto',
		'_hs_breadcrumb',
		'_hs_score',
		'_hs_analysis',
		'_hs_ai_notes',
		'_hs_wrapped',
		'_hs_reading_time',
		'_hs_noindex_reason',
	);

	/**
	 * Get instance.
	 *
	 * @return Meta
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Hooks that shape the front-end head.
	 */
	public function bootstrap() {
		add_filter( 'pre_get_document_title', array( $this, 'document_title' ), 20 );
		add_filter( 'document_title_parts', array( $this, 'title_parts' ), 20 );
		add_action( 'wp_head', array( $this, 'head' ), 2 );
		add_action( 'wp_head', array( $this, 'head_robots' ), 3 );
		add_action( 'template_redirect', array( $this, 'page_redirect' ), 3 );
		add_filter( 'wp_head_filter', array( $this, 'debug_comment' ) );
		add_action( 'save_post', array( $this, 'flush_cache' ), 99, 2 );
		add_filter( 'redirect_canonical', array( $this, 'maybe_disable_canonical_redirect' ), 10, 2 );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/**
	 * All keys owned by the plugin (used by uninstall + migration).
	 *
	 * @return array
	 */
	public static function keys() {
		return self::$keys;
	}

	/**
	 * Focus keywords for a post (primary first).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function focus_keywords( $post_id ) {
		$raw = get_post_meta( (int) $post_id, '_hs_keywords', true );
		if ( is_string( $raw ) && '' !== $raw ) {
			$raw = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}
		$out = array();
		foreach ( (array) $raw as $keyword ) {
			$keyword = trim( (string) $keyword );
			if ( '' !== $keyword && ! in_array( $keyword, $out, true ) ) {
				$out[] = $keyword;
			}
		}
		return array_slice( $out, 0, 5 );
	}

	/**
	 * Robots directives for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function robots( $post_id ) {
		$stored = get_post_meta( (int) $post_id, '_hs_robots', true );
		$robots = wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'index'        => true,
				'follow'       => true,
				'nocache'      => false,
				'noarchive'    => false,
				'nosnippet'    => false,
				'indexifembedded' => false,
				'max-snippet'  => -1,
				'max-image-preview' => 'large',
				'max-video-preview' => -1,
			)
		);

		/**
		 * Filter the robots directives of a single post.
		 *
		 * @param array $robots  Directives.
		 * @param int   $post_id Post ID.
		 */
		return apply_filters( 'hoosh_seo_post_robots', $robots, (int) $post_id );
	}

	/**
	 * Robots string for the head tag.
	 *
	 * @param array $robots Directives.
	 * @return string
	 */
	public static function robots_string( $robots ) {
		$parts = array();
		$parts[] = empty( $robots['index'] ) ? 'noindex' : 'index';
		$parts[] = empty( $robots['follow'] ) ? 'nofollow' : 'follow';
		if ( ! empty( $robots['nocache'] ) ) {
			$parts[] = 'nocache';
		}
		if ( ! empty( $robots['noarchive'] ) ) {
			$parts[] = 'noarchive';
		}
		if ( ! empty( $robots['nosnippet'] ) ) {
			$parts[] = 'nosnippet';
		}
		if ( ! empty( $robots['indexifembedded'] ) ) {
			$parts[] = 'indexifembedded';
		}
		if ( isset( $robots['max-snippet'] ) && (int) $robots['max-snippet'] >= 0 ) {
			$parts[] = 'max-snippet:' . (int) $robots['max-snippet'];
		}
		if ( ! empty( $robots['max-image-preview'] ) ) {
			$parts[] = 'max-image-preview:' . sanitize_key( $robots['max-image-preview'] );
		}
		if ( isset( $robots['max-video-preview'] ) && (int) $robots['max-video-preview'] >= 0 ) {
			$parts[] = 'max-video-preview:' . (int) $robots['max-video-preview'];
		}
		return implode( ', ', $parts );
	}

	/**
	 * Canonical URL for a post/term/archive.
	 *
	 * @param int   $post_id Post ID (0 for archives).
	 * @param array $extra   Overrides (e.g. 'paged').
	 * @return string
	 */
	public static function canonical( $post_id = 0, $extra = array() ) {
		if ( $post_id ) {
			$custom = get_post_meta( (int) $post_id, '_hs_canonical', true );
			if ( $custom ) {
				return esc_url_raw( $custom );
			}
			$url = get_permalink( (int) $post_id );
		} else {
			$url = self::current_url();
		}

		$paged = isset( $extra['paged'] ) ? (int) $extra['paged'] : get_query_var( 'paged' );
		$paged = $paged ? $paged : (int) get_query_var( 'page' );
		if ( $paged > 1 ) {
			$url = user_trailingslashit( trailingslashit( untrailingslashit( (string) $url ) ) . 'page/' . $paged );
		}

		$url = self::strip_tracking( (string) $url );

		return apply_filters( 'hoosh_seo_canonical', $url, $post_id, $extra );
	}

	/**
	 * Current request URL (for archives).
	 *
	 * @return string
	 */
	public static function current_url() {
		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : wp_parse_url( home_url(), PHP_URL_HOST ); // phpcs:ignore
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/'; // phpcs:ignore
		return esc_url_raw( $scheme . '://' . $host . $path );
	}

	/**
	 * Remove tracking args configured in settings so the canonical stays clean.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function strip_tracking( $url ) {
		$strip = (array) hoosh_seo()->settings->get( 'general.strip_query_args', array() );
		if ( ! $strip || false !== strpos( $url, 'hoosh' ) ) {
			return $url;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['query'] ) ) {
			return $url;
		}
		parse_str( $parts['query'], $query );
		$changed = false;
		foreach ( $strip as $arg ) {
			if ( isset( $query[ $arg ] ) ) {
				unset( $query[ $arg ] );
				$changed = true;
			}
		}
		if ( ! $changed ) {
			return $url;
		}
		$base = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['path'] ) ? $parts['path'] : '/' );
		return $query ? add_query_arg( $query, $base ) : $base;
	}

	/**
	 * Resolve a stored (possibly templated) field to its final value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key (without _hs_).
	 * @param array  $extra   Template context overrides.
	 * @return string
	 */
	public static function resolve( $post_id, $key, $extra = array() ) {
		$stored = get_post_meta( (int) $post_id, '_hs_' . $key, true );
		$ctx    = Helpers::variable_context( (int) $post_id, $extra );

		if ( '' === $stored || false === $stored ) {
			$fallback = self::fallback( $post_id, $key, $ctx );
			return wp_strip_all_tags( strip_shortcodes( (string) $fallback ) );
		}

		$value = Helpers::render_vars( (string) $stored, $ctx );

		if ( 'description' === $key ) {
			/**
			 * Filter the resolved meta description (Woo, translations, …).
			 *
			 * @param string $value   Description.
			 * @param int    $post_id Post ID.
			 */
			$value = apply_filters( 'hoosh_seo_meta_description', $value, (int) $post_id );
			$max   = (int) hoosh_seo()->settings->get( 'titles.truncate_description', 158 );
			$value = self::clamp_text( (string) $value, $max );
		}

		return wp_strip_all_tags( strip_shortcodes( $value ) );
	}

	/**
	 * Automatic values when the field was never filled.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Field.
	 * @param array  $ctx     Context.
	 * @return string
	 */
	protected static function fallback( $post_id, $key, $ctx ) {
		$settings = hoosh_seo()->settings;

		if ( 'title' === $key ) {
			$post = get_post( $post_id );
			$tpl  = $post && 'page' === $post->post_type ? 'titles.page_title' : 'titles.post_title';
			$tpl  = $post && 'product' === $post->post_type ? 'titles.product_title' : $tpl;
			$title = Helpers::render_vars( (string) $settings->get( $tpl, '%%title%% %%sep%% %%sitename%%' ), $ctx );
			$max   = (int) $settings->get( 'titles.truncate_title', 600 );
			return self::clamp_text( $title, $max, true );
		}

		if ( 'description' === $key ) {
			if ( ! $settings->get( 'titles.auto_description', true ) ) {
				return '';
			}
			$keywords = self::focus_keywords( $post_id );
			$excerpt  = (string) $ctx['excerpt'];
			if ( '' === $excerpt ) {
				$post = get_post( $post_id );
				$excerpt = $post ? wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '' ) : '';
			}
			if ( $keywords && false === stripos( $excerpt, $keywords[0] ) ) {
				$excerpt = sprintf(
					/* translators: 1: keyword, 2: excerpt */
					__( '%1$s: %2$s', 'hoosh-seo' ),
					$keywords[0],
					$excerpt
				);
			}
			return self::clamp_text( $excerpt, (int) $settings->get( 'titles.truncate_description', 158 ) );
		}

		return '';
	}

	/**
	 * Trim text to a length without cutting words, adding an ellipsis.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Max chars.
	 * @param bool   $words Count words instead of chars (titles).
	 * @return string
	 */
	public static function clamp_text( $text, $limit, $words = false ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( $limit <= 0 || '' === $text ) {
			return $text;
		}
		if ( $words ) {
			$parts = preg_split( '/\s+/u', $text );
			if ( count( $parts ) > $limit ) {
				$text = implode( ' ', array_slice( $parts, 0, max( 1, (int) ceil( $limit / 2 ) ) ) ) . '…';
			}
			return $text;
		}
		if ( Helpers::strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit, 'UTF-8' ) : substr( $text, 0, $limit );
		$last = strrpos( $cut, ' ' );
		if ( false !== $last && $last > $limit * 0.5 ) {
			$cut = substr( $cut, 0, $last );
		}
		return rtrim( $cut, " ،.:-؛" ) . '…';
	}

	/**
	 * Filter: fully own the document title.
	 *
	 * @param string $title Coming title.
	 * @return string
	 */
	public function document_title( $title ) {
		$parts = $this->title_parts( array(
			'title' => '',
			'site'  => '',
			'tagline' => '',
		) );

		if ( empty( $parts['title'] ) ) {
			return $title;
		}

		$out = $parts['title'];
		if ( ! empty( $parts['page'] ) ) {
			$out .= ' ' . $parts['page'];
		}
		if ( ! empty( $parts['site']) ) {
			$out .= ' ' . hoosh_seo()->settings->get( 'general.separator', '|' ) . ' ' . $parts['site'];
		}
		return $out;
	}

	/**
	 * Compute the title parts for the current request.
	 *
	 * @param array $parts Incoming parts.
	 * @return array
	 */
	public function title_parts( $parts ) {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$title   = self::resolve( $post_id, 'title' );
			if ( $title ) {
				$parts['title'] = $title;
				$parts['site']  = '';
				// A template already carries the site name → do not append.
				if ( false === strpos( $title, get_bloginfo( 'name' ) ) && false === strpos( (string) get_post_meta( $post_id, '_hs_title', true ), '%%sitename%%' ) ) {
					$parts['site'] = get_bloginfo( 'name' );
					$parts['title'] = $title;
				}
			}
			return $parts;
		}

		if ( is_front_page() ) {
			$tpl = (string) hoosh_seo()->settings->get( 'titles.front_title', '%%sitename%% %%sep%% %%tagline%%' );
			$ctx = Helpers::variable_context( 0, array( 'title' => get_bloginfo( 'name' ) ) );
			$parts['title'] = Helpers::render_vars( $tpl, $ctx );
			$parts['site']  = '';
			return $parts;
		}

		$parts = $this->archive_title( $parts );
		return $parts;
	}

	/**
	 * Archive / taxonomy / author / date titles.
	 *
	 * @param array $parts Parts.
	 * @return array
	 */
	protected function archive_title( $parts ) {
		$settings = hoosh_seo()->settings;
		$ctx      = Helpers::variable_context( 0 );

		$term = null;
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			$id   = 'term_' . (int) $term->term_id;
			$custom_title = get_option( 'hs_term_' . (int) $term->term_id . '_title' );
			$ctx['term_title'] = $term->name;
			$ctx['term']       = $term->name;
			$ctx['term_description'] = wp_strip_all_tags( (string) term_description( $term ) );
			$ctx['term_count'] = (string) $term->count;
			$tpl = is_category() ? 'titles.category_title' : ( is_tag() ? 'titles.tag_title' : 'titles.archive_title' );
			$parts['title'] = $custom_title ? $custom_title : Helpers::render_vars( (string) $settings->get( $tpl, '%%term_title%% %%sep%% %%sitename%%' ), $ctx );
			$parts['site']  = $custom_title ? '' : '';
			return $parts;
		}

		if ( is_author() ) {
			$author = get_queried_object();
			$ctx['name']   = $author->display_name;
			$ctx['author'] = $author->display_name;
			$parts['title'] = Helpers::render_vars( (string) $settings->get( 'titles.author_title', '%%name%% %%sep%% %%sitename%%' ), $ctx );
			return $parts;
		}

		if ( is_date() ) {
			$ctx['date'] = get_the_date();
			$parts['title'] = Helpers::render_vars( (string) $settings->get( 'titles.date_title', '%%date%% %%sep%% %%sitename%%' ), $ctx );
			return $parts;
		}

		if ( is_search() ) {
			$ctx['query'] = get_search_query();
			$parts['title'] = Helpers::render_vars( (string) $settings->get( 'titles.search_title', '%%searchquery%% %%sep%% %%sitename%%' ), $ctx );
			return $parts;
		}

		if ( is_404() ) {
			$parts['title'] = (string) $settings->get( 'titles.404_title', '404 %%sep%% %%sitename%%' );
			$parts['title'] = Helpers::render_vars( $parts['title'], $ctx );
			return $parts;
		}

		$parts['title'] = Helpers::render_vars( (string) $settings->get( 'titles.archive_title', '%%term_title%% %%sep%% %%sitename%%' ), $ctx );
		return $parts;
	}

	/**
	 * Print the head block: description, canonical, shortlink removal, misc.
	 */
	public function head() {
		$post_id = get_queried_object_id();

		if ( is_singular() ) {
			$description = self::resolve( $post_id, 'description' );
			if ( '' !== $description ) {
				echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
			}
		} else {
			$meta = $this->archive_description();
			if ( $meta ) {
				echo '<meta name="description" content="' . esc_attr( $meta ) . '">' . "\n";
			}
		}

		$canonical = self::canonical( is_singular() ? $post_id : 0 );
		if ( $canonical && ! is_singular( 'attachment' ) ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}

		// Pagination rel prev/next for archives.
		global $wp_query;
		if ( is_archive() || is_home() || is_search() ) {
			$max = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 1;
			$paged = max( 1, (int) get_query_var( 'paged' ) );
			if ( $paged > 1 ) {
				echo '<link rel="prev" href="' . esc_url( get_pagenum_link( $paged - 1 ) ) . '">' . "\n";
			}
			if ( $paged < $max ) {
				echo '<link rel="next" href="' . esc_url( get_pagenum_link( $paged + 1 ) ) . '">' . "\n";
			}
		}

		if ( hoosh_seo()->settings->get( 'general.hide_shortlink', true ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head', 1 );
		}

		// Robots meta for the index page (singular handled in head_robots).
		$robots = $this->global_robots();
		if ( $robots ) {
			echo '<meta name="robots" content="' . esc_attr( $robots ) . '">' . "\n";
		}

		/**
		 * Fires inside the HooshSEO head block.
		 *
		 * @param int $post_id Queried object id.
		 */
		do_action( 'hoosh_seo_head', $post_id );
	}

	/**
	 * Robots meta for singular content (index/noindex etc.).
	 */
	public function head_robots() {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$robots  = self::robots( $post_id );
			$string  = self::robots_string( $robots );
			$public  = (bool) get_option( 'blog_public' );
			if ( ! $public ) {
				$string = 'noindex, nofollow, max-snippet:-1, max-image-preview:large';
			}
			echo '<meta name="robots" content="' . esc_attr( $string ) . '">' . "\n";

			/**
			 * Filter the per-post robots string.
			 *
			 * @param string $string  Directives.
			 * @param int    $post_id Post ID.
			 */
			apply_filters( 'hoosh_seo_robots_string', $string, $post_id );
			return;
		}

		if ( is_search() || is_404() ) {
			echo '<meta name="robots" content="noindex, follow">' . "\n";
		}
	}

	/**
	 * Sitewide robots (privacy, archives).
	 *
	 * @return string
	 */
	protected function global_robots() {
		if ( is_singular() ) {
			return '';
		}
		if ( ! get_option( 'blog_public' ) ) {
			return 'noindex, nofollow';
		}

		$noindex = array();
		if ( is_author() && ! hoosh_seo()->settings->get( 'index.index_authors', false ) ) {
			$noindex[] = 'author';
		}
		if ( ( is_date() ) && ! hoosh_seo()->settings->get( 'index.index_date', false ) ) {
			$noindex[] = 'date';
		}
		if ( ! empty( $noindex ) ) {
			return 'noindex, follow';
		}
		if ( is_category() && ! hoosh_seo()->settings->get( 'index.index_categories', true ) ) {
			return 'noindex, follow';
		}
		if ( is_tag() && ! hoosh_seo()->settings->get( 'index.index_tags', true ) ) {
			return 'noindex, follow';
		}
		if ( is_tax() && ! empty( hoosh_seo()->settings->get( 'index.archive_robots', array() ) ) ) {
			$tax = get_queried_object();
			$map = (array) hoosh_seo()->settings->get( 'index.archive_robots', array() );
			if ( $tax && isset( $map[ $tax->taxonomy ] ) && 'index' !== $map[ $tax->taxonomy ] ) {
				return 'noindex, follow';
			}
		}
		$paged = (int) get_query_var( 'paged' );
		if ( $paged > 1 && hoosh_seo()->settings->get( 'index.noindex_paged', false ) ) {
			return 'noindex, follow';
		}
		return '';
	}

	/**
	 * Archive description from term/user meta or settings template.
	 *
	 * @return string
	 */
	protected function archive_description() {
		$settings = hoosh_seo()->settings;
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			$desc = get_option( 'hs_term_' . (int) $term->term_id . '_description' );
			if ( ! $desc ) {
				$desc = term_description( $term );
			}
			$desc = wp_strip_all_tags( (string) $desc );
			return self::clamp_text( $desc, (int) $settings->get( 'titles.truncate_description', 158 ) );
		}
		if ( is_author() ) {
			$author = get_queried_object();
			$desc   = get_the_author_meta( 'hs_description', (int) $author->ID );
			if ( ! $desc ) {
				$desc = get_the_author_meta( 'description', (int) $author->ID );
			}
			return self::clamp_text( wp_strip_all_tags( (string) $desc ), (int) $settings->get( 'titles.truncate_description', 158 ) );
		}
		if ( is_search() ) {
			return '';
		}
		return self::clamp_text( wp_strip_all_tags( (string) $settings->get( 'titles.front_description', '' ) ), 158 );
	}

	/**
	 * Per-page redirect + custom slug support.
	 */
	public function page_redirect() {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();

		$redirect = get_post_meta( $post_id, '_hs_redirect', true );
		if ( is_array( $redirect ) && ! empty( $redirect['url'] ) ) {
			status_header( (int) ( isset( $redirect['type'] ) ? $redirect['type'] : 301 ) );
			wp_redirect( Helpers::abs_url( $redirect['url'] ) ); // phpcs:ignore
			exit;
		}
	}

	/**
	 * Let the plugin own canonical redirects when asked.
	 *
	 * @param string $redirect_url  Redirect.
	 * @param string $requested_url Requested.
	 * @return string|false
	 */
	public function maybe_disable_canonical_redirect( $redirect_url, $requested_url ) {
		if ( hoosh_seo()->settings->get( 'redirects.enabled', true ) && Modules\Redirects::owns( $requested_url ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Body class hook so themes can style around the plugin.
	 *
	 * @param array $classes Classes.
	 * @return array
	 */
	public function body_class( $classes ) {
		$classes[] = 'hoosh-seo';
		if ( hoosh_seo()->settings->get( 'geo.answer_box', true ) && is_singular() ) {
			$classes[] = 'hoosh-has-answer-box';
		}
		return $classes;
	}

	/**
	 * Debug comment for editors ("view source" troubleshooting).
	 */
	public function debug_comment() {
		if ( ! hoosh_seo()->settings->get( 'advanced.debug', false ) ) {
			return;
		}
		echo "<!--HooshSEO v" . esc_html( HOOSH_SEO_VERSION ) . " | powered-by=\"HooshSEO\" -->\n";
	}

	/**
	 * Flush cached head/schema output for a post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function flush_cache( $post_id, $post = null ) {
		Helpers::cache_flush( 'head-' . (int) $post_id );
		Helpers::cache_flush( 'schema-' . (int) $post_id );
		Helpers::cache_flush( 'sitemap' );
		unset( $post );
	}

	/**
	 * Read every stored value for a post (used by editor + app + exports).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function for_post( $post_id ) {
		$post_id = (int) $post_id;
		$out     = array(
			'title'        => (string) get_post_meta( $post_id, '_hs_title', true ),
			'description'  => (string) get_post_meta( $post_id, '_hs_description', true ),
			'keywords'     => self::focus_keywords( $post_id ),
			'canonical'    => (string) get_post_meta( $post_id, '_hs_canonical', true ),
			'slug'         => (string) get_post_meta( $post_id, '_hs_slug', true ),
			'redirect'     => (array) get_post_meta( $post_id, '_hs_redirect', true ),
			'primaryCat'   => (int) get_post_meta( $post_id, '_hs_primary_cat', true ),
			'score'        => (float) get_post_meta( $post_id, '_hs_score', true ),
			'social'       => wp_parse_args(
				(array) get_post_meta( $post_id, '_hs_social', true ),
				array( 'title' => '', 'description' => '', 'image_id' => 0, 'skip' => false )
			),
			'robots'       => self::robots( $post_id ),
			'schema'       => (array) get_post_meta( $post_id, '_hs_schema', true ),
			'faq'          => (array) get_post_meta( $post_id, '_hs_faq', true ),
			'howto'        => (array) get_post_meta( $post_id, '_hs_howto', true ),
			'breadcrumb'   => (string) get_post_meta( $post_id, '_hs_breadcrumb', true ),
			'analysis'     => (array) get_post_meta( $post_id, '_hs_analysis', true ),
			'ai_notes'     => (string) get_post_meta( $post_id, '_hs_ai_notes', true ),
			'geo'          => self::geo( $post_id ),
			'resolved'     => array(
				'title'       => self::resolve( $post_id, 'title' ),
				'description' => self::resolve( $post_id, 'description' ),
				'canonical'   => self::canonical( $post_id ),
				'robots'      => self::robots_string( self::robots( $post_id ) ),
			),
			'urls'         => array(
				'view'    => get_permalink( $post_id ),
				'edit'    => get_edit_post_link( $post_id, 'raw' ),
				'studio'  => App::studio_url( 'snippet' ) . '&post=' . $post_id,
			),
		);

		$post = get_post( $post_id );
		if ( $post ) {
			$out['post'] = array(
				'id'      => $post->ID,
				'type'    => $post->post_type,
				'status'  => $post->post_status,
				'title'   => get_the_title( $post ),
				'slug'    => $post->post_name,
				'content' => $post->post_content,
				'excerpt' => $post->post_excerpt,
				'modified' => $post->post_modified_gmt,
				'date'     => $post->post_date_gmt,
				'author'    => (int) $post->post_author,
				'thumbnail' => (int) get_post_thumbnail_id( $post ),
			);
		}

		return $out;
	}

	/**
	 * Save the editor payload.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $payload Payload from the editor or the Studio.
	 * @return array Normalised stored values.
	 */
	public static function save( $post_id, $payload ) {
		$post_id = (int) $post_id;
		$stored  = array();

		$before = self::for_post( $post_id );

		if ( array_key_exists( 'title', $payload ) ) {
			$title = sanitize_text_field( (string) $payload['title'] );
			$stored['title'] = $title;
			self::put( $post_id, '_hs_title', $title );
		}

		if ( array_key_exists( 'description', $payload ) ) {
			$desc = wp_kses_post( (string) $payload['description'] );
			$stored['description'] = $desc;
			self::put( $post_id, '_hs_description', $desc );
		}

		if ( array_key_exists( 'keywords', $payload ) ) {
			$keywords = self::sanitize_keywords( $payload['keywords'] );
			$stored['keywords'] = $keywords;
			self::put( $post_id, '_hs_keywords', $keywords );
			self::sync_tags( $post_id, $keywords );
		}

		if ( array_key_exists( 'canonical', $payload ) ) {
			self::put( $post_id, '_hs_canonical', esc_url_raw( (string) $payload['canonical'] ) );
		}

		if ( array_key_exists( 'slug', $payload ) ) {
			$slug = sanitize_title( (string) $payload['slug'] );
			self::put( $post_id, '_hs_slug', $slug );
			if ( $slug && ! has_filter( 'post_link', array( __CLASS__, 'apply_slug' ) ) ) {
				add_filter( 'post_link', array( __CLASS__, 'apply_slug' ), 20, 2 );
				add_filter( 'page_link', array( __CLASS__, 'apply_slug' ), 20, 2 );
			}
		}

		if ( array_key_exists( 'redirect', $payload ) ) {
			$redirect = (array) $payload['redirect'];
			if ( empty( $redirect['url'] ) ) {
				delete_post_meta( $post_id, '_hs_redirect' );
			} else {
				self::put(
					$post_id,
					'_hs_redirect',
					array(
						'url'  => Helpers::sanitize_url( $redirect['url'] ),
						'type' => in_array( (int) ( $redirect['type'] ?? 301 ), array( 301, 302, 307, 308 ), true ) ? (int) $redirect['type'] : 301,
					)
				);
			}
		}

		if ( array_key_exists( 'primaryCat', $payload ) ) {
			self::put( $post_id, '_hs_primary_cat', absint( $payload['primaryCat'] ) );
		}

		if ( array_key_exists( 'robots', $payload ) ) {
			self::put( $post_id, '_hs_robots', self::sanitize_robots( $payload['robots'] ) );
		}

		if ( array_key_exists( 'social', $payload ) ) {
			$social = (array) $payload['social'];
			self::put(
				$post_id,
				'_hs_social',
				array(
					'title'       => sanitize_text_field( (string) ( $social['title'] ?? '' ) ),
					'description' => sanitize_textarea_field( (string) ( $social['description'] ?? '' ) ),
					'image_id'    => absint( $social['image_id'] ?? 0 ),
					'skip'        => ! empty( $social['skip'] ),
				)
			);
		}

		if ( array_key_exists( 'schema', $payload ) ) {
			self::put( $post_id, '_hs_schema', Modules\Schema::sanitize_blocks( $payload['schema'] ) );
		}

		if ( array_key_exists( 'faq', $payload ) ) {
			self::put( $post_id, '_hs_faq', Modules\Schema::sanitize_faq( $payload['faq'] ) );
		}

		if ( array_key_exists( 'howto', $payload ) ) {
			self::put( $post_id, '_hs_howto', Modules\Schema::sanitize_howto( $payload['howto'] ) );
		}

		if ( array_key_exists( 'breadcrumb', $payload ) ) {
			self::put( $post_id, '_hs_breadcrumb', sanitize_text_field( (string) $payload['breadcrumb'] ) );
		}

		if ( array_key_exists( 'geo', $payload ) ) {
			self::put( $post_id, '_hs_geo', self::sanitize_geo( $payload['geo'] ) );
		}

		if ( array_key_exists( 'ai_notes', $payload ) ) {
			self::put( $post_id, '_hs_ai_notes', sanitize_textarea_field( (string) $payload['ai_notes'] ) );
		}

		if ( array_key_exists( 'analysis', $payload ) ) {
			self::put( $post_id, '_hs_analysis', self::sanitize_analysis( $payload['analysis'] ) );
		}

		// Auto title/description when the post has none and the setting is on.
		if ( hoosh_seo()->settings->get( 'titles.auto_description', true ) && ! get_post_meta( $post_id, '_hs_description', true ) ) {
			self::put( $post_id, '_hs_description', '' );
		}

		self::log_change( $post_id, $before, $stored );
		Modules\Images::maybe_fill_alt( $post_id );

		return $stored;
	}

	/**
	 * Write a meta value (autoloaded for speed).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Key.
	 * @param mixed  $value   Value.
	 */
	protected static function put( $post_id, $key, $value ) {
		if ( '' === $value || array() === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}
		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Sanitize the focus keyword list.
	 *
	 * @param mixed $input Raw.
	 * @return array
	 */
	public static function sanitize_keywords( $input ) {
		if ( is_string( $input ) ) {
			$input = preg_split( '/[,،\n]+/u', $input );
		}
		$out = array();
		foreach ( (array) $input as $keyword ) {
			if ( is_array( $keyword ) ) {
				$keyword = isset( $keyword['phrase'] ) ? $keyword['phrase'] : reset( $keyword );
			}
			$keyword = trim( (string) wp_strip_all_tags( $keyword ) );
			if ( '' === $keyword ) {
				continue;
			}
			$keyword = mb_substr( $keyword, 0, 120 );
			if ( ! in_array( Helpers::normalize_fa( $keyword ), array_map( array( __CLASS__, 'norm' ), $out ), true ) ) {
				$out[] = $keyword;
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Normalise a keyword for comparisons.
	 *
	 * @param string $value Keyword.
	 * @return string
	 */
	public static function norm( $value ) {
		return Helpers::normalize_fa( (string) $value );
	}

	/**
	 * Push focus keywords into post tags (WPKeyword parity).
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $keywords   Keywords.
	 */
	protected static function sync_tags( $post_id, $keywords ) {
		if ( ! hoosh_seo()->settings->get( 'keywords.auto_tags', false ) || ! $keywords ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || ! taxonomy_exists( 'post_tag' ) ) {
			return;
		}
		$existing = wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );
		$existing = is_wp_error( $existing ) ? array() : (array) $existing;
		$max      = (int) hoosh_seo()->settings->get( 'keywords.max_tags', 8 );

		foreach ( $keywords as $keyword ) {
			if ( count( $existing ) >= $max ) {
				break;
			}
			$found = false;
			foreach ( $existing as $tag ) {
				if ( Helpers::normalize_fa( $tag ) === Helpers::normalize_fa( $keyword ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$existing[] = $keyword;
			}
		}

		wp_set_object_terms( $post_id, $existing, 'post_tag' );
	}

	/**
	 * Sanitize robots payload.
	 *
	 * @param mixed $input Raw.
	 * @return array
	 */
	public static function sanitize_robots( $input ) {
		$input = (array) $input;
		return array(
			'index'           => ! isset( $input['index'] ) || ! empty( $input['index'] ),
			'follow'          => ! isset( $input['follow'] ) || ! empty( $input['follow'] ),
			'nocache'         => ! empty( $input['nocache'] ),
			'noarchive'       => ! empty( $input['noarchive'] ),
			'nosnippet'       => ! empty( $input['nosnippet'] ),
			'indexifembedded' => ! empty( $input['indexifembedded'] ),
			'max-snippet'     => isset( $input['max-snippet'] ) ? (int) $input['max-snippet'] : -1,
			'max-image-preview' => in_array( (string) ( $input['max-image-preview'] ?? 'large' ), array( 'none', 'standard', 'large' ), true ) ? (string) $input['max-image-preview'] : 'large',
			'max-video-preview' => isset( $input['max-video-preview'] ) ? (int) $input['max-video-preview'] : -1,
		);
	}

	/**
	 * Keep stored analysis small and JSON-safe.
	 *
	 * @param mixed $analysis Analysis array.
	 * @return array
	 */
	public static function sanitize_analysis( $analysis ) {
		$analysis = (array) $analysis;
		$out      = array(
			'score'       => isset( $analysis['score'] ) ? (float) $analysis['score'] : 0,
			'seo'         => isset( $analysis['seo_score'] ) ? (float) $analysis['seo_score'] : 0,
			'readability' => isset( $analysis['readability'] ) ? (float) $analysis['readability'] : 0,
			'grade'       => isset( $analysis['grade'] ) ? sanitize_text_field( (string) $analysis['grade'] ) : '',
			'words'       => isset( $analysis['words'] ) ? (int) $analysis['words'] : 0,
			'checked_at'  => time(),
			'issues'      => array(),
		);
		foreach ( (array) ( $analysis['issues'] ?? array() ) as $issue ) {
			if ( ! is_array( $issue ) || empty( $issue['id'] ) ) {
				continue;
			}
			$out['issues'][] = array(
				'id'       => sanitize_key( $issue['id'] ),
				'severity' => in_array( (string) ( $issue['severity'] ?? 'notice' ), array( 'critical', 'error', 'warning', 'notice', 'ok' ), true ) ? (string) $issue['severity'] : 'notice',
				'label'    => sanitize_text_field( (string) ( $issue['label'] ?? '' ) ),
				'hint'     => sanitize_text_field( (string) ( $issue['hint'] ?? '' ) ),
				'why'      => sanitize_text_field( (string) ( $issue['why'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * Apply a custom slug to permalinks (Permalink Manager parity).
	 *
	 * @param string  $permalink Permalink.
	 * @param \WP_Post $post     Post.
	 * @return string
	 */
	public static function apply_slug( $permalink, $post = null ) {
		if ( ! $post instanceof \WP_Post ) {
			return $permalink;
		}
		$slug = get_post_meta( $post->ID, '_hs_slug', true );
		if ( ! $slug ) {
			return $permalink;
		}
		$structure = (string) get_option( 'permalink_structure' );
		if ( '' === $structure ) {
			return add_query_arg( array( $post->post_type => $slug ), home_url( '/' ) );
		}
		$dir  = trailingslashit( dirname( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ? home_url( '/' ) : '/' ) );
		$tail = trailingslashit( $slug );
		$tail = str_replace( '%postname%', $slug, $tail );
		unset( $dir, $tail );

		// Rebuild from the structure with our slug substituted.
		$replaced = str_replace( array( '%postname%', '%pagename%' ), $slug, $structure );
		$replaced = str_replace( array( '%post_id%', '%category%', '%tag%', '%year%', '%monthnum%', '%day%', '%hour%', '%minute%', '%second%' ), '', $replaced );
		$replaced = preg_replace( '#/+#', '/', $replaced );
		if ( strpos( $replaced, '/index.php' ) === 0 ) {
			$replaced = substr( $replaced, strlen( '/index.php' ) );
		}
		$base = (string) $GLOBALS['wp_rewrite']->root;

		return home_url( $base . trim( $replaced, '/' ) . '/' );
	}

	/**
	 * Write an undoable changelog entry.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $before  Previous state.
	 * @param array $after   New state.
	 */
	protected static function log_change( $post_id, $before, $after ) {
		if ( ! hoosh_seo()->settings->get( 'security.audit_trail', true ) ) {
			return;
		}
		$diff = array();
		foreach ( $after as $key => $value ) {
			$old = isset( $before[ $key ] ) ? $before[ $key ] : '';
			if ( wp_json_encode( $old ) !== wp_json_encode( $value ) ) {
				$diff[ $key ] = array( $old, $value );
			}
		}
		if ( ! $diff ) {
			return;
		}
		Audit::log(
			'meta',
			'update',
			array(
				'post_id' => $post_id,
				'before'  => $diff,
				'after'   => $after,
				'url'     => get_permalink( $post_id ),
			)
		);
	}
	/**
	 * GEO / answer-box fields for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function geo( $post_id ) {
		return self::sanitize_geo( (array) get_post_meta( (int) $post_id, '_hs_geo', true ) );
	}

	/**
	 * Sanitize the GEO box.
	 *
	 * @param mixed $input Raw.
	 * @return array
	 */
	public static function sanitize_geo( $input ) {
		$input = (array) $input;
		$out   = array(
			'answer'     => mb_substr( trim( (string) ( $input['answer'] ?? '' ) ), 0, 600 ),
			'question'   => mb_substr( trim( (string) ( $input['question'] ?? '' ) ), 0, 200 ),
			'source'     => (string) ( $input['source'] ?? ( $input['answer'] ?? '' ? 'manual' : '' ) ),
			'ai_visible' => isset( $input['ai_visible'] ) ? (bool) $input['ai_visible'] : null,
			'speakable'  => isset( $input['speakable'] ) ? (bool) $input['speakable'] : null,
			'date'       => mb_substr( trim( (string) ( $input['date'] ?? '' ) ), 0, 40 ),
		);
		if ( '' === $out['source'] ) {
			$out['source'] = 'manual';
		}
		if ( ! in_array( $out['source'], array( 'manual', 'ai', 'first-paragraph', 'summary' ), true ) ) {
			$out['source'] = 'manual';
		}
		return $out;
	}

}
