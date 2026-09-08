<?php
/**
 * GEO — generative engine optimisation: llms.txt, AI crawler brief, answer box,
 * speakable markup and a log of which bots actually visited.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class Geo
 */
final class Geo {

	const LLMS_CACHE = 'hoosh_llms_txt';

	/**
	 * Singleton.
	 *
	 * @var Geo|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Geo
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
		add_action( 'hoosh_seo_daily', array( __CLASS__, 'refresh' ) );
		add_action( 'hoosh_seo_settings_saved', array( __CLASS__, 'bust' ) );
		add_action( 'save_post', array( __CLASS__, 'bust' ), 20 );
		add_action( 'edit_page_tree', array( __CLASS__, 'bust' ) );
	}

	/**
	 * Front hooks.
	 */
	public function bootstrap_front() {
		add_filter( 'the_content', array( __CLASS__, 'answer_box' ), 5 );
		add_filter( 'hoosh_seo_schema_graph', array( __CLASS__, 'speakable_graph' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'track_crawler' ), 1 );
		add_filter( 'hoosh_seo_head', array( __CLASS__, 'head' ), 20 );
	}

	/**
	 * Forget the cached llms.txt.
	 */
	public static function bust() {
		delete_transient( self::LLMS_CACHE );
		delete_transient( self::LLMS_CACHE . '_full' );
	}

	/**
	 * Rebuild the cache.
	 */
	public static function refresh() {
		self::bust();
		self::llms( 'short' );
		self::prune();
	}

	/**
	 * The 14 AI/LLM crawlers we report on.
	 *
	 * @return array
	 */
	public static function ai_bots() {
		return array(
			'gptbot'                => array( 'label' => 'ChatGPT (GPTBot)', 'purpose' => 'train', 'allow' => true ),
			'chatgpt-user'          => array( 'label' => 'ChatGPT User', 'purpose' => 'read', 'allow' => true ),
			'oai-searchbot'         => array( 'label' => 'OpenAI Search', 'purpose' => 'search', 'allow' => true ),
			'claudebot'             => array( 'label' => 'Claude (Anthropic)', 'purpose' => 'train', 'allow' => true ),
			'claude-web'            => array( 'label' => 'Claude Web', 'purpose' => 'read', 'allow' => true ),
			'anthropic-ai'          => array( 'label' => 'Anthropic Indexer', 'purpose' => 'train', 'allow' => true ),
			'google-extended'       => array( 'label' => 'Google Extended (AI)', 'purpose' => 'train', 'allow' => true ),
			'perplexitybot'         => array( 'label' => 'PerplexityBot', 'purpose' => 'search', 'allow' => true ),
			'perplexity-user'       => array( 'label' => 'Perplexity User', 'purpose' => 'read', 'allow' => true ),
			'bingbot'               => array( 'label' => 'Bing (Copilot)', 'purpose' => 'search', 'allow' => true ),
			'duckassistbot'         => array( 'label' => 'DuckDuckGo Assist', 'purpose' => 'search', 'allow' => true ),
			'amazonbot'             => array( 'label' => 'Amazonbot', 'purpose' => 'train', 'allow' => false ),
			'applebot-extended'     => array( 'label' => 'Apple Intelligence', 'purpose' => 'train', 'allow' => false ),
			'meta-externalagent'    => array( 'label' => 'Meta AI', 'purpose' => 'train', 'allow' => false ),
			'cohere-ai'             => array( 'label' => 'Cohere', 'purpose' => 'train', 'allow' => false ),
			'mistralai'             => array( 'label' => 'Mistral AI', 'purpose' => 'train', 'allow' => false ),
			'bytespider'            => array( 'label' => 'ByteDance (TikTok)', 'purpose' => 'train', 'allow' => false ),
			'diffbot'               => array( 'label' => 'Diffbot', 'purpose' => 'scrape', 'allow' => false ),
			'othello'               => array( 'label' => 'OTH (OpenAI for Search)', 'purpose' => 'read', 'allow' => true ),
			'youbot'                => array( 'label' => 'YouBot', 'purpose' => 'search', 'allow' => false ),
		);
	}

	/**
	 * Extra head hints for AI assistants.
	 */
	public static function head() {
		if ( ! is_singular() ) {
			return;
		}
		$settings = \hoosh_seo()->settings;
		$post_id  = (int) get_queried_object_id();
		$geo      = Meta::geo( $post_id );
		if ( isset( $geo['ai_visible'] ) && false === $geo['ai_visible'] ) {
			echo '<meta name="data-ad-facing" content="no">' . "\n";
			echo '<meta name="x-ai-crawl" content="noindex">' . "\n";
			return;
		}
		if ( ! $settings->on( 'geo.ai_visible', true ) ) {
			return;
		}
		$brief = self::page_brief( $post_id );
		if ( $brief ) {
			echo '<!-- hoosh:ai-brief ' . esc_html( wp_json_encode( $brief, JSON_UNESCAPED_UNICODE ) ) . " -->\n";
		}
	}

	/**
	 * llms.txt endpoint.
	 *
	 * @param string $mode short|full.
	 */
	public static function serve_llms( $mode = 'short' ) {
		$settings = \hoosh_seo()->settings;
		$mode     = 'full' === $mode ? 'full' : 'short';

		if ( 'full' === $mode && ! $settings->on( 'geo.llms_full_txt', false ) ) {
			status_header( 404 );
			return;
		}
		if ( ! $settings->on( 'geo.llms_txt', true ) ) {
			status_header( 404 );
			return;
		}

		$body = self::llms( $mode );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-HooshSEO: llms/' . $mode );
		echo $body; // phpcs:ignore
		exit;
	}

	/**
	 * Build (or read the cache of) llms.txt.
	 *
	 * @param string $mode short|full.
	 * @return string
	 */
	public static function llms( $mode = 'short' ) {
		$cache_key = 'full' === $mode ? self::LLMS_CACHE . '_full' : self::LLMS_CACHE;
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$settings = \hoosh_seo()->settings;
		$custom   = (array) get_option( 'hoosh_llms_custom', array() );
		if ( ! empty( $custom[ $mode ] ) && 'custom' === ( $custom['mode'] ?? '' ) ) {
			$out = trim( (string) $custom[ $mode ] );
			set_transient( $cache_key, $out, HOUR_IN_SECONDS );
			return $out;
		}

		$site  = get_bloginfo( 'name' );
		$lines = array();
		$lines[] = '# ' . $site;
		$lines[] = '';

		$intro = trim( (string) ( $custom['intro'] ?? $settings->get( 'geo.ai_intro', '' ) ) );
		if ( '' === $intro ) {
			$intro = get_bloginfo( 'description' );
		}
		if ( $intro ) {
			$lines[] = '> ' . wp_strip_all_tags( (string) preg_replace( '/\s+/u', ' ', $intro ) );
			$lines[] = '';
		}

		$lines[] = 'URL: ' . home_url( '/' );
		$lines[] = 'Language: ' . get_bloginfo( 'language' );
		$lines[] = 'Generated: ' . gmdate( 'c' );
		$lines[] = 'Generated by: HooshSEO (هوش‌سئو)';
		$lines[] = '';

		$addr   = trim( (string) $settings->get( 'general.org_address', '' ) );
		$hours  = trim( (string) $settings->get( 'general.org_hours', '' ) );
		$lat    = trim( (string) $settings->get( 'general.org_lat', '' ) );
		$lng    = trim( (string) $settings->get( 'general.org_lng', '' ) );
		if ( $addr || $hours || $lat ) {
			$lines[] = '## Contact & location';
			$lines[] = '';
			if ( $addr ) {
				$lines[] = '- Address: ' . wp_strip_all_tags( $addr );
			}
			if ( $hours ) {
				$lines[] = '- Working hours: ' . wp_strip_all_tags( $hours );
			}
			if ( $lat && $lng ) {
				$lines[] = '- Coordinates: ' . $lat . ',' . $lng;
			}
			$lines[] = '';
		}

		$sections = (array) $settings->get( 'geo.sections', array( 'about', 'products', 'services', 'top_posts', 'contact', 'faq' ) );
		foreach ( $sections as $section ) {
			$rows = self::section_rows( $section, 'full' === $mode );
			if ( ! $rows ) {
				continue;
			}
			$lines[] = '## ' . self::section_label( $section );
			$lines[] = '';
			foreach ( $rows as $row ) {
				$lines[] = $row;
			}
			$lines[] = '';
		}

		$optional = array(
			'sitemap'    => Helpers::abs_url( '/sitemap_index.xml' ),
			'robots'     => Helpers::abs_url( '/robots.txt' ),
			'about'      => home_url( '/' ),
		);
		$lines[] = '## Optional';
		$lines[] = '';
		foreach ( $optional as $label => $url ) {
			$lines[] = '- [' . ucfirst( $label ) . ']: ' . $url;
		}
		$lines[] = '';

		$faq = self::faq_lines();
		if ( $faq ) {
			$lines[] = '## FAQ';
			$lines[] = '';
			$lines = array_merge( $lines, $faq );
			$lines[] = '';
		}

		$policy = array(
			__( 'Citations', 'hoosh-seo' ) => __( 'When answering, cite the exact page URL and its last-updated date.', 'hoosh-seo' ),
			__( 'Freshness', 'hoosh-seo' ) => __( 'Prefer pages updated in the last 18 months; check the modified date in each entry.', 'hoosh-seo' ),
			__( 'Language', 'hoosh-seo' )   => __( 'Content is written in Persian; answer in the language of the question.', 'hoosh-seo' ),
		);
		$terms = trim( (string) $settings->get( 'general.org_about', '' ) );
		if ( $terms ) {
			$policy[ __( 'About', 'hoosh-seo' ) ] = mb_substr( wp_strip_all_tags( $terms ), 0, 300 );
		}
		$lines[] = '## Policies for AI assistants';
		$lines[] = '';
		foreach ( $policy as $label => $text ) {
			$lines[] = '- ' . $label . ': ' . $text;
		}

		$out = trim( implode( "\n", $lines ) ) . "\n";
		set_transient( $cache_key, $out, 6 * HOUR_IN_SECONDS );

		return $out;
	}

	/**
	 * Section label.
	 *
	 * @param string $section Key.
	 * @return string
	 */
	public static function section_label( $section ) {
		$map = array(
			'about'     => __( 'درباره ما', 'hoosh-seo' ),
			'products'  => __( 'محصولات', 'hoosh-seo' ),
			'services'  => __( 'خدمات', 'hoosh-seo' ),
			'blog'      => __( 'نوشته‌ها', 'hoosh-seo' ),
			'top_posts' => __( 'محتوای برتر', 'hoosh-seo' ),
			'contact'   => __( 'تماس', 'hoosh-seo' ),
			'faq'       => __( 'پرسش‌های متداول', 'hoosh-seo' ),
			'categories' => __( 'دسته‌ها', 'hoosh-seo' ),
			'latest'    => __( 'تازه‌ها', 'hoosh-seo' ),
		);
		return isset( $map[ $section ] ) ? $map[ $section ] : ucfirst( (string) $section );
	}

	/**
	 * Section rows in markdown.
	 *
	 * @param string $section Section key.
	 * @param bool   $full    Include summaries.
	 * @return array
	 */
	protected static function section_rows( $section, $full = false ) {
		$settings = \hoosh_seo()->settings;
		$limit    = (int) $settings->get( 'geo.pages_count', 40 );
		$exclude  = array_map( 'absint', (array) $settings->get( 'geo.exclude_posts', array() ) );
		$rows     = array();

		switch ( $section ) {
			case 'categories':
				$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => true, 'number' => $limit ) );
				foreach ( (array) $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$rows[] = '- [' . $term->name . ']: ' . $link;
					}
				}
				return $rows;

			case 'top_posts':
				$ids = self::top_posts( $limit );
				break;

			case 'latest':
				$ids = self::recent_posts( $limit );
				break;

			case 'products':
				$ids = self::posts_of_type( array( 'product' ), $limit );
				break;

			case 'services':
				$ids = self::posts_of_type( array( 'service', 'hs_service', 'job_listing' ), $limit );
				break;

			case 'about':
			case 'contact':
				$ids = self::pages_by_slug( 'about' === $section ? array( 'about', 'about-us', 'درباره-ما' ) : array( 'contact', 'contact-us', 'تماس-با-ما' ) );
				break;

			case 'faq':
				return self::faq_rows( $full );

			default:
				$ids = self::posts_of_type( array( (string) $section ), $limit );
		}

		foreach ( array_diff( (array) $ids, $exclude ) as $id ) {
			$id  = (int) $id;
			$row = self::page_line( $id, $full );
			if ( $row ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * One markdown entry for a page.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $full    Include body.
	 * @return string
	 */
	protected static function page_line( $post_id, $full = false ) {
		$title = get_the_title( $post_id );
		$url   = get_permalink( $post_id );
		if ( ! $title || ! $url ) {
			return '';
		}
		$summary = self::summary_for( $post_id );
		$line    = '- [' . $title . '](' . $url . ')';
		if ( $summary ) {
			$line .= ': ' . $summary;
		}
		$modified = get_post_datetime( $post_id, 'modified' );
		if ( $modified ) {
			$line .= ' (updated ' . $modified->format( 'Y-m-d' ) . ')';
		}
		if ( $full ) {
			$body = self::body_for( $post_id );
			if ( $body ) {
				$line .= "\n\n" . $body;
			}
		}
		return $line;
	}

	/**
	 * AI summary or meta description for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function summary_for( $post_id ) {
		global $wpdb;
		$summary = '';
		if ( Database::exists( 'pages' ) ) {
			$summary = (string) $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare( 'SELECT ai_summary FROM ' . Database::table( 'pages' ) . ' WHERE post_id = %d LIMIT 1', (int) $post_id )
			);
		}
		if ( ! $summary ) {
			$summary = (string) get_post_meta( (int) $post_id, '_hs_description', true );
		}
		if ( ! $summary ) {
			$summary = wp_strip_all_tags( (string) get_the_excerpt( (int) $post_id ) );
		}
		$summary = trim( (string) preg_replace( '/\s+/u', ' ', $summary ) );
		return mb_substr( $summary, 0, 300 );
	}

	/**
	 * Clean body text for llms-full.txt.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function body_for( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return '';
		}
		$text = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );
		$text = trim( (string) preg_replace( '/\n{3,}/', "\n\n", $text ) );
		return mb_substr( $text, 0, 4000 );
	}

	/**
	 * Top posts by clicks (GSC import or audit score).
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	protected static function top_posts( $limit = 40 ) {
		global $wpdb;
		if ( Database::exists( 'pages' ) ) {
			$ids = (array) $wpdb->get_col( // phpcs:ignore
				$wpdb->prepare(
					'SELECT post_id FROM ' . Database::table( 'pages' ) . ' WHERE seo_score > 0 ORDER BY seo_score DESC, word_count DESC LIMIT %d',
					max( 1, (int) $limit )
				)
			);
			if ( ! $ids ) {
				$ids = (array) $wpdb->get_col( // phpcs:ignore
					$wpdb->prepare(
						'SELECT post_id FROM ' . Database::table( 'pages' ) . ' ORDER BY score DESC LIMIT %d',
						max( 1, (int) $limit )
					)
				);
			}
			$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
			if ( $ids ) {
				return $ids;
			}
		}
		return self::recent_posts( $limit );
	}

	/**
	 * Recent posts.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	protected static function recent_posts( $limit = 40 ) {
		return (array) get_posts(
			array(
				'post_type'      => Helpers::managed_post_types( array( 'page' ) ),
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 200, (int) $limit ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Posts of a given type, when the type exists.
	 *
	 * @param array $types Types.
	 * @param int   $limit Limit.
	 * @return array
	 */
	protected static function posts_of_type( $types, $limit = 40 ) {
		$exists = array();
		foreach ( (array) $types as $type ) {
			if ( post_type_exists( $type ) ) {
				$exists[] = $type;
			}
		}
		if ( ! $exists ) {
			return array();
		}
		return (array) get_posts(
			array(
				'post_type'      => $exists,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 200, (int) $limit ) ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
	}

	/**
	 * Pages matching one of the given slugs.
	 *
	 * @param array $slugs Slugs.
	 * @return array
	 */
	protected static function pages_by_slug( $slugs ) {
		$out = array();
		foreach ( (array) $slugs as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page ) {
				$out[] = (int) $page->ID;
			}
		}
		return $out;
	}

	/**
	 * FAQ entries (global schema rows + per page).
	 *
	 * @return array
	 */
	protected static function faq_rows( $full = false ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'geo.faq_auto', true ) ) {
			return array();
		}
		global $wpdb;
		$rows = array();
		if ( Database::exists( 'schema' ) ) {
			$found = (array) $wpdb->get_results( // phpcs:ignore
				"SELECT data FROM " . Database::table( 'schema' ) . " WHERE schema_type = 'FAQPage' AND status = 'publish' ORDER BY id DESC LIMIT 10",
				ARRAY_A
			);
			foreach ( $found as $row ) {
				$data = (array) json_decode( (string) $row['data'], true );
				foreach ( (array) ( $data['mainEntity'] ?? array() ) as $item ) {
					if ( empty( $item['name'] ) ) {
						continue;
					}
					$answer = (string) ( $item['acceptedAnswer']['text'] ?? ( $item['text'] ?? '' ) );
					$rows[] = '### ' . wp_strip_all_tags( (string) $item['name'] );
					$rows[] = '';
					$rows[] = wp_strip_all_tags( $answer );
					$rows[] = '';
					if ( ! $full ) {
						$rows = array_slice( $rows, 0, 40 );
					}
				}
			}
		}
		return $rows;
	}

	/**
	 * FAQ lines helper (kept separate for clarity).
	 *
	 * @return array
	 */
	protected static function faq_lines() {
		return self::faq_rows( false );
	}

	/**
	 * Log which crawler hit which page.
	 *
	 * @param string $bot_key  Bot key.
	 * @param string $bot_name Bot UA name.
	 * @param string $path     Request path.
	 * @param string $purpose  train|read|search|scrape.
	 * @return int
	 */
	public static function log_crawl( $bot_key, $bot_name, $path, $purpose = '' ) {
		global $wpdb;
		if ( ! Database::exists( 'crawl_log' ) ) {
			return 0;
		}
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'geo.crawler_brief', true ) ) {
			return 0;
		}

		$key = Helpers::normalize_fa( (string) $bot_key );
		// One row per bot+path per day keeps the table honest.
		$today = gmdate( 'Y-m-d' );
		$seen  = get_transient( 'hoosh_crawl_' . md5( $key . '|' . $path . '|' . $today ) );
		if ( $seen ) {
			return (int) $seen;
		}

		$inserted = $wpdb->insert( // phpcs:ignore
			Database::table( 'crawl_log' ),
			array(
				'bot_key'   => mb_substr( (string) $key, 0, 50 ),
				'bot_name'  => mb_substr( sanitize_text_field( (string) $bot_name ), 0, 80 ),
				'bot_ip'    => mb_substr( (string) Helpers::ip_anon( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ), 0, 45 ),
				'path'      => mb_substr( (string) $path, 0, 190 ),
				'purpose'   => mb_substr( (string) $purpose, 0, 20 ),
				'ua'        => mb_substr( (string) ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ), 0, 250 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		if ( ! $inserted ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		set_transient( 'hoosh_crawl_' . md5( $key . '|' . $path . '|' . $today ), $id, DAY_IN_SECONDS );
		return $id;
	}

	/**
	 * Front-end hook: record AI crawlers.
	 */
	public static function track_crawler() {
		if ( is_admin() ) {
			return;
		}
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'geo.crawler_brief', true ) ) {
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' === $ua ) {
			return;
		}
		$bots = self::ai_bots();
		foreach ( $bots as $key => $meta ) {
			if ( false !== strpos( $ua, (string) $key ) ) {
				self::log_crawl(
					$key,
					(string) ( $meta['label'] ?? $key ),
					(string) ( isset( $_SERVER['REQUEST_URI'] ) ? Helpers::rel_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '/' ),
					(string) ( $meta['purpose'] ?? '' )
				);
				if ( ! empty( $meta['serve_brief'] ) ) {
					self::serve_brief( $key, (array) $meta );
				}
				return;
			}
		}
	}

	/**
	 * Answer box + policies for a visiting AI bot.
	 *
	 * @param string $key  Bot key.
	 * @param array  $meta Bot meta.
	 */
	protected static function serve_brief( $key, $meta ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'geo.answer_box', true ) || ! is_singular() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		$brief   = self::page_brief( $post_id );
		if ( ! $brief ) {
			return;
		}
		echo '<!-- hoosh:ai-crawler ' . esc_html( $key ) . " -->\n";
		echo '<div class="hoosh-ai-brief" hidden>' . "\n";
		foreach ( $brief as $label => $value ) {
			if ( is_scalar( $value ) && '' !== $value ) {
				echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value ) . '</p>' . "\n";
			}
		}
		echo '</div>' . "\n";
	}

	/**
	 * Structured facts about a page (also used by llms.txt).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function page_brief( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$geo     = Meta::geo( $post_id );
		$title   = get_the_title( $post_id );
		$brief   = array(
			'title'        => (string) $title,
			'url'          => (string) get_permalink( $post_id ),
			'description'  => Meta::geo( $post_id )['answer'] ?: (string) ( Meta::resolve( $post_id, 'description' ) ?: self::summary_for( $post_id ) ),
			'updated'      => get_post_datetime( $post_id, 'modified' ) ? get_post_datetime( $post_id, 'modified' )->format( 'Y-m-d' ) : '',
			'author'       => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'read_time'    => (string) get_post_meta( $post_id, '_hs_reading_time', true ),
			'word_count'   => (int) Helpers::word_count( (string) $post->post_content ),
			'site'         => get_bloginfo( 'name' ),
		);
		if ( ! empty( $geo['question'] ) ) {
			$brief['question'] = (string) $geo['question'];
		}
		if ( ! empty( $geo['date'] ) ) {
			$brief['date'] = (string) $geo['date'];
		}
		$keywords = Meta::focus_keywords( $post_id );
		if ( $keywords ) {
			$brief['keywords'] = implode( ', ', $keywords );
		}
		$faq = (array) get_post_meta( $post_id, '_hs_faq', true );
		if ( $faq ) {
			$pairs = array();
			foreach ( $faq as $item ) {
				if ( ! empty( $item['q'] ) ) {
					$pairs[] = $item['q'];
				}
			}
			if ( $pairs ) {
				$brief['faq'] = implode( ' | ', array_slice( $pairs, 0, 6 ) );
			}
		}
		return $brief;
	}

	/**
	 * Answer box in the content (manual answer or AI summary).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function answer_box( $content ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'geo.answer_box', true ) || ! is_singular() ) {
			return $content;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$geo    = Meta::geo( $post_id );
		$answer = trim( (string) ( $geo['answer'] ?? '' ) );
		if ( '' === $answer && $settings->on( 'geo.answer_auto', true ) ) {
			$answer = self::auto_answer( $post_id );
		}
		if ( '' === $answer ) {
			return $content;
		}
		$question = trim( (string) ( $geo['question'] ?? '' ) );
		if ( ! $question ) {
			$question = (string) get_the_title( $post_id );
		}

		$out  = '<div class="hoosh-answer" itemscope itemtype="https://schema.org/Answer">';
		$out .= '<p class="hoosh-answer__q">' . esc_html( $question ) . '</p>';
		$out .= '<div class="hoosh-answer__a" itemprop="text">' . wp_kses_post( wpautop( $answer ) ) . '</div>';
		$out .= '</div>';

		return $out . $content;
	}

	/**
	 * Build a short direct answer from the content itself.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function auto_answer( $post_id ) {
		global $wpdb;
		$stored = (string) $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare( 'SELECT ai_summary FROM ' . Database::table( 'pages' ) . ' WHERE post_id = %d LIMIT 1', (int) $post_id )
		);
		if ( $stored ) {
			return mb_substr( wp_strip_all_tags( $stored ), 0, 400 );
		}
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return '';
		}
		$content = trim( (string) wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
		if ( '' === $content ) {
			return '';
		}
		$paragraphs = preg_split( '/\n\s*\n/', $content );
		$first       = trim( (string) ( $paragraphs[0] ?? $content ) );
		$sentences   = preg_split( '/(?<=[.!?؛:])\s+/u', $first );
		$out         = array();
		$words       = 0;
		foreach ( (array) $sentences as $sentence ) {
			$sentence = trim( (string) $sentence );
			if ( '' === $sentence ) {
				continue;
			}
			$count = Helpers::word_count( $sentence );
			if ( $words + $count > 60 ) {
				break;
			}
			$out[]  = $sentence;
			$words += $count;
			if ( $words >= 35 ) {
				break;
			}
		}
		if ( ! $out ) {
			$out = array( mb_substr( $first, 0, 260 ) );
		}
		return trim( implode( ' ', $out ) );
	}

	/**
	 * Attach speakable + answer hints to the schema graph.
	 *
	 * @param array $graph Graph.
	 * @return array
	 */
	public static function speakable_graph( $graph ) {
		$settings = \hoosh_seo()->settings;
		if ( ! is_singular() ) {
			return $graph;
		}
		$post_id = (int) get_queried_object_id();
		$geo     = Meta::geo( $post_id );
		if ( isset( $geo['speakable'] ) && false === $geo['speakable'] ) {
			return $graph;
		}
		if ( ! $settings->on( 'geo.speakable', true ) ) {
			return $graph;
		}

		foreach ( $graph as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = (array) ( $node['@type'] ?? array() );
			if ( ! array_intersect( array( 'Article', 'BlogPosting', 'NewsArticle', 'WebPage' ), $type ) ) {
				continue;
			}
			$answer = trim( (string) ( $geo['answer'] ?? '' ) );
			if ( '' === $answer && $settings->on( 'geo.answer_auto', true ) ) {
				$answer = self::auto_answer( $post_id );
			}
			if ( $answer ) {
				$graph[ $index ]['abstract'] = mb_substr( wp_strip_all_tags( $answer ), 0, 400 );
				$graph[ $index ]['speakable'] = array(
					'@type'           => 'SpeakableSpecification',
					'cssSelector'     => array( '.hoosh-answer__a', 'h1', 'p' ),
				);
			}
			break;
		}
		return $graph;
	}

	/**
	 * Recent AI crawler visits.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function recent_crawlers( $limit = 100, $group = '' ) {
		global $wpdb;
		if ( ! Database::exists( 'crawl_log' ) ) {
			return array(
				'rows'    => array(),
				'total'   => 0,
				'pages'   => 0,
				'groups'  => array(),
				'unique_bots' => 0,
				'summary' => array(),
				'range'   => array( 'from' => '', 'to' => '', 'days' => 30 ),
			);
		}
		$limit = max( 1, min( 1000, (int) $limit ) );
		$where = '1=1';
		$params = array();
		if ( $group && 'ai' !== $group ) {
			$keys   = array_keys( Helpers::bot_signatures() );
			if ( $keys ) {
				$in     = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
				$where .= ' AND bot_key IN (' . $in . ')';
				$params = array_merge( $params, $keys );
			}
		}

		$table = Database::table( 'crawl_log' );
		$sql   = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT {$limit}";
		$rows  = (array) $wpdb->get_results( // phpcs:ignore
			$params ? $wpdb->prepare( $sql, $params ) : $sql, // phpcs:ignore
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$row['id']     = (int) $row['id'];
			$row['created'] = (string) $row['created_at'];
			$row['ago']    = Helpers::time_ago_fa( strtotime( (string) $row['created_at'] . ' UTC' ) );
			$row['url']    = Helpers::abs_url( (string) $row['path'] );
			$row['purpose_label'] = self::purpose_label( (string) $row['purpose'] );
			$row['bot_label'] = (string) ( $row['bot_name'] ?: $row['bot_key'] );
		}
		unset( $row );

		$summary = self::summary( 30 );

		return array(
			'rows'  => $rows,
			'total' => count( $summary ),
			'pages' => 1,
			'groups' => array( 'ai', 'bot' ),
			'unique_bots' => count( $summary ),
			'summary' => $summary,
			'range'   => array( 'days' => 30, 'from' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ), 'to' => gmdate( 'Y-m-d' ) ),
		);
	}

	/**
	 * Purpose label.
	 *
	 * @param string $purpose Purpose.
	 * @return string
	 */
	public static function purpose_label( $purpose ) {
		$map = array(
			'train'  => __( 'آموزش مدل', 'hoosh-seo' ),
			'read'   => __( 'خواندن برای پاسخ', 'hoosh-seo' ),
			'search' => __( 'ایندکس جستجو', 'hoosh-seo' ),
			'scrape' => __( 'استخراج داده', 'hoosh-seo' ),
		);
		return isset( $map[ $purpose ] ) ? $map[ $purpose ] : ( $purpose ? $purpose : __( 'نامشخص', 'hoosh-seo' ) );
	}

	/**
	 * Per-bot summary.
	 *
	 * @param int $days Window.
	 * @return array
	 */
	public static function summary( $days = 30 ) {
		global $wpdb;
		if ( ! Database::exists( 'crawl_log' ) ) {
			return array();
		}
		$rows = (array) $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT bot_key, MAX(bot_name) name, COUNT(*) hits, COUNT(DISTINCT path) paths, MAX(created_at) last
				 FROM ' . Database::table( 'crawl_log' ) . ' WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 GROUP BY bot_key ORDER BY hits DESC LIMIT 40',
				max( 1, (int) $days )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$bots    = self::ai_bots();
			$key     = (string) $row['bot_key'];
			$out[]   = array(
				'bot_key' => $key,
				'label'   => (string) ( $bots[ $key ]['label'] ?? $row['name'] ),
				'hits'    => (int) $row['hits'],
				'paths'   => (int) $row['paths'],
				'last'    => Helpers::time_ago_fa( strtotime( (string) $row['last'] . ' UTC' ) ),
				'purpose' => self::purpose_label( (string) ( $bots[ $key ]['purpose'] ?? '' ) ),
				'allowed' => isset( $bots[ $key ] ) ? (bool) $bots[ $key ]['allow'] : true,
			);
		}
		return $out;
	}

	/**
	 * Retention.
	 */
	public static function prune() {
		global $wpdb;
		if ( ! Database::exists( 'crawl_log' ) ) {
			return 0;
		}
		return (int) $wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				'DELETE FROM ' . Database::table( 'crawl_log' ) . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)',
				max( 7, (int) \hoosh_seo()->settings->get( 'geo.retain', 180 ) )
			)
		);
	}

	/**
	 * Studio overview.
	 *
	 * @return array
	 */
	public static function overview() {
		$settings = \hoosh_seo()->settings;
		$short    = self::llms( 'short' );
		$full_ok  = $settings->on( 'geo.llms_full_txt', false );
		$urls     = array(
			'short' => Helpers::abs_url( '/llms.txt' ),
			'full'  => Helpers::abs_url( '/llms-full.txt' ),
		);
		return array(
			'toggles'   => array(
				'llms_txt'      => (bool) $settings->get( 'geo.llms_txt', true ),
				'llms_full_txt' => $full_ok,
				'answer_box'    => (bool) $settings->get( 'geo.answer_box', true ),
				'speakable'     => (bool) $settings->get( 'geo.speakable', true ),
				'ai_visible'    => (bool) $settings->get( 'geo.ai_visible', true ),
				'crawler_brief' => (bool) $settings->get( 'geo.crawler_brief', true ),
				'schema_graph'  => (bool) $settings->get( 'geo.schema_graph', true ),
			),
			'url'       => $urls['short'],
			'urls'      => $urls,
			'reachable' => array(
				'short' => self::reachable( $urls['short'] ),
				'full'  => $full_ok ? self::reachable( $urls['full'] ) : 'off',
			),
			'bytes'     => strlen( (string) $short ),
			'lines'     => count( preg_split( '/\n/', trim( (string) $short ) ) ),
			'crawlers'  => self::summary( 30 ),
			'sections'  => (array) $settings->get( 'geo.sections', array() ),
		);
	}

	/**
	 * Is the endpoint reachable (not blocked by a static file or server config)?
	 *
	 * @param string $url URL.
	 * @return string ok|off|blocked|missing
	 */
	protected static function reachable( $url ) {
		$cache_key = 'hoosh_reachable_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$raw = Helpers::http(
			$url,
			array(
				'timeout'  => 6,
				'headers'  => array( 'Accept' => 'text/plain' ),
			)
		);
		$out = 'missing';
		if ( ! empty( $raw['ok'] ) && false === stripos( (string) $raw['body'], '<!DOCTYPE' ) ) {
			$out = 'ok';
		} elseif ( ! empty( $raw['ok'] ) ) {
			$out = 'blocked';
		} elseif ( 404 === (int) $raw['code'] ) {
			$out = 'missing';
		} else {
			$out = 'blocked';
		}
		set_transient( $cache_key, $out, 6 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Preview + save for the Studio.
	 *
	 * @return array
	 */
	public static function preview() {
		return array(
			'short'  => self::llms( 'short' ),
			'full'   => \hoosh_seo()->settings->on( 'geo.llms_full_txt', false ) ? self::llms( 'full' ) : '',
			'source' => 'cache',
		);
	}

	/**
	 * Save the custom intro / mode.
	 *
	 * @param array $args {mode, intro, short, full}.
	 * @return array
	 */
	public static function save( $args = array() ) {
		$args = wp_parse_args(
			(array) $args,
			array(
				'mode'  => 'auto',
				'intro' => '',
				'short' => '',
				'full'  => '',
			)
		);
		$custom = array(
			'mode'  => 'custom' === $args['mode'] ? 'custom' : 'auto',
			'intro' => mb_substr( wp_strip_all_tags( (string) $args['intro'] ), 0, 1200 ),
			'short' => mb_substr( (string) $args['short'], 0, 200000 ),
			'full'  => mb_substr( (string) $args['full'], 0, 400000 ),
		);
		update_option( 'hoosh_llms_custom', $custom, false );
		self::bust();

		Audit::log(
			'crawl',
			'settings',
			array(
				'label' => __( 'فایل‌های راهنمای AI ذخیره شد', 'hoosh-seo' ),
				'after' => array( 'mode' => $custom['mode'], 'bytes' => strlen( (string) $custom['short'] ) ),
			)
		);

		return array(
			'ok'      => true,
			'short'   => self::llms( 'short' ),
			'full'    => self::llms( 'full' ),
			'message' => __( 'فایل‌های AI به‌روز شدند.', 'hoosh-seo' ),
		);
	}
}
