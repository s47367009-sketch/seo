<?php
/**
 * Settings: one wp_options entry, dot-notation access, safe defaults.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings
 */
final class Settings {

	const OPTION = 'hoosh_seo_settings';

	/**
	 * Cached options.
	 *
	 * @var array|null
	 */
	private $data = null;

	/**
	 * Singleton.
	 *
	 * @var Settings|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Full defaults tree.
	 *
	 * This tree is the single source of truth for every module: anything listed
	 * here is exposed in the Studio UI and the REST settings endpoint.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'general'    => array(
				'site_type'         => 'site', // site|shop|blog|news|business.
				'site_name'         => '',
				'logo_id'           => 0,
				'social_default_id' => 0,
				'fallback_image'    => '',
				'separator'         => '|',
				'twitter_card'      => 'summary_large_image',
				'twitter_site'      => '',
				'facebook_app_id'   => '',
				'knowledge_graph'   => 'organization', // organization|person|none.
				'org_name'          => '',
				'org_alt_name'      => '',
				'org_about'         => '',
				'org_email'         => '',
				'org_phone'         => '',
				'org_tax_id'        => '',
				'org_founded'       => '',
				'org_area_served'   => 'IR',
				'org_logo_id'       => 0,
				'org_slogan'        => '',
				'org_price_range'   => '',
				'social_profiles'   => array(),
				'person_name'       => '',
				'person_url'        => '',
				'hide_shortlink'    => true,
				'hide_rss'          => false,
				'hide_generator'    => true,
				'hide_react_embed'  => true,
				'hide_emoji_script' => true,
				'hide_rest_links'   => false,
				'disable_xmlrpc'    => false,
				'strip_query_args'  => array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid' ),
				'admin_bar'         => true,
				'show_score_columns' => true,
				'render'            => array(),
				'org_address'  => array( 'street' => '', 'city' => '', 'province' => '', 'zip' => '', 'country' => 'IR' ),
				'org_hours'    => array(),
				'org_lat'      => '',
				'org_lng'      => '',
			),
			'titles'     => array(
				'post_title'            => '%%title%% %%sep%% %%sitename%%',
				'post_description'      => '%%excerpt%%',
				'page_title'            => '%%title%% %%sep%% %%sitename%%',
				'archive_title'         => '%%term_title%% %%sep%% %%sitename%%',
				'archive_description'   => '%%term_description%%',
				'author_title'          => 'آرشیو %%name%% %%sep%% %%sitename%%',
				'date_title'            => '%%date%% %%sep%% %%sitename%%',
				'search_title'          => 'نتایج جستجو برای %%query%% %%sep%% %%sitename%%',
				'404_title'             => 'صفحه پیدا نشد %%sep%% %%sitename%%',
				'front_title'           => '%%sitename%% %%sep%% %%tagline%%',
				'front_description'     => '',
				'product_title'         => '%%title%% %%sep%% %%price%% %%sep%% %%sitename%%',
				'product_description'   => '%%excerpt%%',
				'category_title'        => '%%term_title%% %%sep%% %%sitename%%',
				'tag_title'             => '%%term_title%% %%sep%% %%sitename%%',
				'cap_titles'            => true,
				'truncate_title'        => 600,
				'truncate_description'  => 160,
				'auto_description'      => true,
				'remove_stopwords_slug' => true,
				'slug_transliterate'    => 'fa', // fa|en|keep.
			),
			'sitemap'    => array(
				'enabled'         => true,
				'cache_ttl'       => 3600,
				'per_page'        => 1000,
				'index_max'       => 5000,
				'nav_include'     => true,
				'strip_noindex'   => true,
				'ping_on_update'  => true,
				'ping_service'    => 'google', // google|bing|both|none.
				'excluded_posts'  => array(),
				'excluded_terms'  => array(),
				'video_sitemap'   => array(
					'enabled' => false,
					'types'   => array( 'post' ),
				),
				'news_sitemap'    => array(
					'enabled'  => false,
					'post_type' => 'post',
					'days'     => 2,
					'keywords' => '',
					'publisher' => '',
					'genres'   => '',
					'language' => 'fa',
				),
				'image_sitemap'   => array(
					'enabled' => true,
					'featured' => true,
					'content'  => false,
					'product'  => true,
					'types'    => array( 'post', 'page' ),
				),
				'author_sitemap'  => array(
					'enabled' => false,
					'min_posts' => 5,
					'roles'   => array( 'administrator', 'editor' ),
				),
				'html_sitemap'    => array(
					'enabled' => false,
					'orderby' => 'title',
					'style'   => 'list',
				),
				'object_types'    => array(),
				'taxonomies'      => array(),
				'priority'        => 'auto',
				'changefreq'      => 'auto',
				'lastmod'         => true,
				'alternate'       => true,
				'news_all'        => false,
				'video_all'       => false,
			),
			'robots'     => array(
				'blog_public'      => 1,
				'virtual'          => true,
				'override_file'    => false,
				'custom'           => '',
				'protect_config'   => false,
				'block_dirs'       => array( '/wp-admin/', '/wp-json/hoosh/' ),
				'allow_uploads'    => true,
				'sitemap_line'     => true,
				'ai_bots'          => array(
					'GPTBot'           => true,
					'ChatGPT-User'     => true,
					'OAI-SearchBot'    => true,
					'ClaudeBot'        => true,
					'Claude-User'      => true,
					'anthropic-ai'     => true,
					'PerplexityBot'    => true,
					'Perplexity-User'  => true,
					'Google-Extended'  => true,
					'GoogleOther'      => true,
					'Bingbot'          => true,
					'Applebot'         => true,
					'Amazonbot'        => true,
					'Bytespider'       => false,
					'CCBot'            => false,
					'DuckAssistBot'    => true,
					'FacebookBot'      => true,
					'Magpie-Crawler'   => false,
					'MistralAI-User'   => true,
					'cohere-ai'        => true,
					'yandex'           => true,
				),
				'masterbots'       => array(
					'googlebot' => 'allow',
					'bingbot'   => 'allow',
					'yahoo'     => 'allow',
					'yandex'    => 'allow',
				),
				'log_bots'         => true,
				'log_retention'    => 90,
				'block_empty_ua'   => false,
				'rate_limit_bots'  => 0,
			),
			'redirects'  => array(
				'enabled'          => true,
				'log_enabled'      => true,
				'log_bots'         => false,
				'log_limit'        => 10000,
				'log_ignore'       => array( '/favicon.ico', '/robots.txt', '/sitemap.xml', '/xmlrpc.php' ),
				'auto_post_redirect' => true,
				'auto_trashed'     => true,
				'auto_case'        => false,
				'auto_slash'       => true,
				'strip_get'        => false,
				'fallback_home'    => false,
				'fallback_search'  => true,
				'attachments'      => 'redirect', // redirect|noindex|ignore.
				'attachment_target' => 'parent',
				'cache_ttl'        => 300,
				'header'           => true,
				'force_https'      => false,
				'www_mode'         => 'keep', // keep|force_www|strip_www.
				'passthrough_args' => array(),
				'404_status'       => 404,
				'auto_404_redirect'  => false,
				'trashed_target'     => '',
			),
			'schema'     => array(
				'enabled'          => true,
				'location'         => 'head', // head|body_start|footer.
				'use_cache'        => true,
				'auto'             => array(
					'post'      => 'Article',
					'page'      => 'WebPage',
					'product'   => 'Product',
					'category'  => 'CollectionPage',
					'post_tag'  => 'CollectionPage',
					'author'    => 'ProfilePage',
					'front'     => 'WebSite',
				),
				'types'            => array(
					'WebSite'        => true,
					'Organization'   => true,
					'Person'         => false,
					'Article'        => true,
					'BlogPosting'    => false,
					'NewsArticle'    => false,
					'WebPage'        => true,
					'Breadcrumb'     => true,
					'SiteNavigation' => false,
					'FAQ'            => false,
					'HowTo'          => false,
					'Product'        => true,
					'Recipe'         => false,
					'VideoObject'    => false,
					'AudioObject'    => false,
					'Event'          => false,
					'Course'         => false,
					'Book'           => false,
					'Review'         => false,
					'AggregateRating' => false,
					'LocalBusiness'  => false,
					'SoftwareApp'    => false,
					'JobPosting'     => false,
					'QAPage'         => false,
					'ImageObject'    => false,
					'Speakable'      => false,
					'WPArticle'      => false,
					'ClaimReview'    => false,
					'Dataset'        => false,
					'DiscussionForumPosting' => false,
					'PropertyValue'  => false,
				),
				'defaults'         => array(
					'language'   => 'fa-IR',
					'region'     => 'IR',
					'currency'   => 'IRR',
					'timezone'   => 'Asia/Tehran',
					'rating_min' => 1,
					'rating_max' => 5,
				),
				'import'           => array(
					'allow_remote' => true,
				),
				'suppress'         => array(), // Types printed by other plugins -> skip ours.
				'validate'         => true,
				'rich_results'     => true,
			),
			'opengraph'  => array(
				'enabled'        => true,
				'twitter'        => true,
				'facebook_prefix' => 'og',
				'default_type'   => 'website',
				'generate_alt'   => true,
				'share_image_id' => 0,
				'show_price'     => true,
				'price_currency' => 'IRT',
				'sitename_prefix' => true,
				'pin_id'         => '',
				'app_id'         => '',
			),
			'index'      => array(
				// Which archives are indexable. Namayeban parity.
				'index_posts'        => true,
				'index_pages'        => true,
				'index_attachments'  => false,
				'index_authors'      => false,
				'index_date'         => false,
				'index_categories'   => true,
				'index_tags'         => true,
				'index_format'       => false,
				'index_search'       => false,
				'index_404'          => false,
				'noindex_woo_archive' => false,
				'noindex_paged'      => false,
				'noindex_empty_terms' => true,
				'noindex_tag_count'  => 2,
				'max_page_index'     => 0,
				'archive_robots'     => array(),
				'strip_wp_sitemap'   => false,
				'indexnow'           => array(
					'enabled'   => true,
					'auto'      => true,
					'key'       => '',
					'providers' => array( 'bing', 'yandex', 'indexnow' ),
					'history'   => 200,
				),

			),
			'links'      => array(
				'auto_link'        => true,
				'max_per_post'     => 6,
				'link_nth'         => 1,
				'min_words'        => 100,
				'skip_headings'    => true,
				'skip_existing'    => true,
				'skip_own'         => true,
				'skip_shortcode'   => true,
				'skip_code'        => true,
				'longest_first'    => true,
				'whole_word'       => true,
				'class'            => 'hs-internal',
				'direct'           => true,
				'nofollow_external' => false,
				'newtab_external'  => true,
				'relabel_external' => false,
				'external_exclude' => array( get_option( 'home', '' ) ),
				'track_clicks'     => true,
				'click_limit_ip'   => 0,
				'click_limit_min'  => 10,
				'notify_email'     => false,
				'email_subject'    => '[هوش‌سئو] آستانه کلیک برای «%%keyword%%» رد شد',
				'email_body'       => "کلمه کلیدی: %%keyword%%\nتعداد کلیک: %%clicks%%\nمقصد: %%url%%\nآخرین کلیک: %%last_click%%",
				'email_max'        => 3,
				'broken_scan'      => true,
				'broken_schedule'  => 'weekly',
				'broken_timeout'   => 8,
				'broken_concurrency' => 4,
				'broken_ignore'    => array(),
				'elementor'        => true,
				'suggest_min_score' => 55,
				'suggest_max'      => 10,
				'bold'  => false,
			),
			'images'     => array(
				'auto_alt'        => true,
				'alt_source'      => 'title', // title|filename|keyword|ai.
				'auto_title'      => true,
				'auto_caption'    => false,
				'auto_description' => true,
				'fill_missing'    => true,
				'rename_upload'   => true,
				'rename_lang'     => 'keep', // en|fa|keep.
				'strip_exif'      => false,
				'alt_keyword'     => true,
				'overwrite'       => false,
				'ai_alt'          => false,
				'strip_query'     => true,
				'preload_lcp'     => true,
				'enabled'                => true,
				'alt_max_words'          => 12,
				'alt_in_content'         => false,
				'alt_in_content_max'     => 8,
				'alt_on_render'          => false,
				'alt_taxonomy_fallback'  => false,
				'dimensions'             => true,
				'lazy'                   => true,
				'strip_title_attribute'  => false,
				'strip_exif_sizes'       => false,
				'serve_webp_fallback'    => false,
				'quality'                => 85,
				'max_size_kb'            => 300,
				'display_widths'         => array( 1200 ),
				'scan_limit'             => 100,
				'rename'                 => true,
				'rename_strip_hash'      => true,
				'rename_suffix'          => false,
				'rename_bulk'            => false,
				'redirect_old'           => true,
				'strip_exif_bulk'        => false,
			),
			'content'    => array(
				'min_words'          => 600,
				'paragraph_min_words' => 40,
				'paragraph_max_words' => 150,
				'sentence_max_words'  => 24,
				'density_min'         => 0.4,
				'density_max'         => 3.0,
				'density_ideal'       => 1.2,
				'title_min'           => 30,
				'title_max'           => 60,
				'title_min_px'        => 320,
				'title_max_px'        => 580,
				'desc_min'            => 110,
				'desc_max'            => 158,
				'url_max'             => 75,
				'external_links_min'  => 0,
				'internal_links_per'  => 300, // one internal link per N words.
				'heading_min'         => 2,
				'subhead_distribute'  => true,
				'transition_min'      => 20,
				'passive_max'         => 10,
				'adverb_max'          => 8,
				'sentence_start_max'  => 30,
				'repeat_word_max'     => 6,
				'simple_words'        => true,
				'stopwords_enabled'   => true,
				'scores'              => array(
					'seo'          => 60,
					'readability'  => 40,
				),
				'weights'             => array(
					'keyword_title'      => 10,
					'keyword_start'      => 5,
					'keyword_desc'       => 8,
					'keyword_content'    => 8,
					'keyword_density'    => 6,
					'keyword_headings'   => 5,
					'keyword_url'        => 4,
					'content_length'     => 8,
					'internal_links'     => 6,
					'external_links'     => 3,
					'images_alt'         => 4,
					'schema'             => 5,
					'title_length'       => 4,
					'desc_length'        => 4,
					'sentence_length'    => 6,
					'paragraph_length'   => 4,
					'transition_words'   => 6,
					'passive_voice'      => 4,
					'subheading_density' => 4,
					'readability_flow'   => 4,
					'word_repetition'    => 3,
					'simple_words'       => 3,
					'url_length'         => 2,
					'social_image'       => 2,
					'outbound_nofollow'  => 1,
					'heading_structure'  => 3,
				),
			),
			'geo'        => array(
				'llms_txt'        => true,
				'llms_full_txt'   => false,
				'ai_intro'        => '',
				'answer_box'      => true,
				'answer_auto'     => true,
				'speakable'       => true,
				'crawler_brief'   => true,
				'pages_count'     => 40,
				'sections'        => array( 'about', 'products', 'services', 'top_posts', 'contact', 'faq' ),
				'exclude_posts'   => array(),
				'refresh'         => 'daily',
				'ai_visible'      => true,
				'schema_graph'    => true,
				'faq_auto'        => true,
				'summary_prompt'  => '',
				'retain'          => 180,
			),
			'analytics'  => array(
				'gsc'             => array(
					'enabled'    => false,
					'property'   => '',
					'client_id'  => '',
					'client_secret' => '',
					'credentials' => '',
					'token'      => '',
					'refresh'    => '',
					'connected'  => false,
					'days'       => 90,
					'sync'       => 'daily',
					'row_limit'  => 25000,
				),
				'ga4'             => array(
					'enabled'    => false,
					'property'   => '',
					'client_id'  => '',
					'client_secret' => '',
					'dataportal_id' => '',
					'credentials' => '',
					'token'      => '',
					'refresh'    => '',
					'connected'  => false,
					'days'       => 28,
					'sync'       => 'daily',
				),
				'pagespeed'       => array(
					'enabled' => true,
					'key'     => '',
					'sample'  => 20,
					'schedule' => 'weekly',
				),
				'serper'          => array(
					'enabled' => false,
					'key'     => '',
				),
				'serpapi'         => array(
					'enabled' => false,
					'key'     => '',
				),
				'dataforseo'      => array(
					'enabled'  => false,
					'login'    => '',
					'password' => '',
				),
				'semrush'         => array(
					'enabled' => false,
					'key'     => '',
				),
				'rank_tracker'    => array(
					'enabled'   => true,
					'source'    => 'auto', // auto|gsc|serper|dataforseo|serpapi|scraper|bing|yahoo.
					'depth'     => 20,
					'cost_limit' => 2,
					'personalize' => false,
					'notify'    => '',
					'retain'    => 365,
					'max_kw'    => 500,
					'schedule'  => 'daily',
					'device'    => 'desktop',
					'country'   => 'ir',
					'lang'      => 'fa',
					'sleep'     => 2,
				),
				'alerts'          => array(
					'email'        => true,
					'drop_position' => 5,
					'drop_percent'  => 30,
					'critical_only' => false,
					'weekly_report' => true,
				),
			),
			'keywords'   => array(
				'sources'          => array(
					'google'     => true,
					'google_trends' => true,
					'bing'       => true,
					'youtube'    => true,
					'digikala'   => true,
					'torob'      => true,
					'basalam'    => true,
					'snappshop'  => true,
					'divar'      => true,
					'shopper'    => true,
					'shitor'     => true,
					'aparat'     => true,
					'cafebazaar' => true,
					'zerebin'    => true,
					'bertina'    => true,
					'duckduckgo' => true,
				),
				'depth'            => 1,
				'delay'            => 2,
				'lang'             => 'fa',
				'max_results'      => 1500,
				'min_length'       => 2,
				'max_length'       => 80,
				'dedupe'           => true,
				'remove_numbers'   => false,
				'remove_duplicates_words' => true,
				'rotate_ua'        => true,
				'prefixes'         => array( 'بهترین', 'خرید', 'قیمت', 'آموزش', 'راهنمای' ),
				'suffixes'         => array( 'ارزان', 'آنلاین', 'چیست', 'چگونه', 'موبایل' ),
				'injections'       => array( 'چگونه', 'چرا', 'کجا', 'چند', 'آیا' ),
				'alphabet'         => true,
				'intent_tags'      => true,
				'auto_tags'        => false,
				'max_tags'         => 8,
				'volume_provider'  => 'auto', // auto|serper|dataforseo|semrush|estimate.
				'ai_estimate'     => true,
				'retain'           => 60,
				'per_source'       => 10,
				'sources_config'   => array(),
			),
			'ai'         => array(
				'enabled'       => true,
				'driver'        => 'openai',
				'fallback'      => '',
				'providers'     => array(),
				'gapgpt_base'   => '',
				'tasks'         => array(
					'meta'        => '',
					'keywords'    => '',
					'summary'     => '',
					'rewrite'     => '',
					'schema'      => '',
					'alt'         => '',
					'report'      => '',
					'chat'        => '',
				),
				'model'         => 'gpt-4o-mini',
				'temperature'   => 0.4,
				'max_tokens'    => 1200,
				'top_p'         => 1,
				'timeout'       => 45,
				'retries'       => 2,
				'stream'        => false,
				'json_mode'     => true,
				'system_prompt' => '',
				'tone'          => 'professional', // professional|friendly|formal|sales|technical.
				'length'        => 'medium', // short|medium|long.
				'language'      => 'fa',
				'brand'         => '',
				'audience'      => '',
				'cache_ttl'     => 72, // hours
				'budget_usd'    => 0, // 0 = unlimited
				'daily_calls'   => 0,
				'batch_size'    => 5,
				'redact'        => true,
				'send_content'  => true,
				'strip_pii'     => true,
				'max_chars'     => 9000,
				'log_retention' => 60,
				'chat_memory'   => 12,
				'rewrite_rules' => array(),
				'self_host'     => array(
					'ollama_url'  => 'http://127.0.0.1:11434',
					'lmstudio_url' => 'http://127.0.0.1:1234/v1',
				),
				'test'          => '',
				'custom_base'   => '',
				'auto_apply'    => array( 'titles', 'description', 'keywords', 'alt', 'vision', 'faq', 'social', 'summary', 'bulk_meta' ),
				'vision_size'   => array( 'width' => 512, 'quality' => 70 ),
				'custom_model'  => '',
				'cache'         => true,
				'user_rate_limit' => 20,
			),
			'automation' => array(
				'enabled'        => true,
				'mode'           => 'suggest', // suggest|apply.
				'hour'           => 3,
				'schedule'       => 'daily',
				'cron_driver'    => 'wp', // wp|system|cli.
				'cron_key'       => '',
				'batch'          => 20,
				'pages_per_run'  => 25,
				'link_budget'    => 400,
				'analysis_threshold' => 70,
				'jobs'           => array(
					'meta_fill'    => true,
					'alt_fill'     => true,
					'audit'        => true,
					'links'        => true,
					'indexnow'     => true,
					'sitemap_ping' => true,
					'rank_sync'    => true,
					'optimize'     => false,
					'summary'      => true,
					'broken'       => true,
				),
				'notify'         => true,
				'notify_email'   => '',
				'report_attach'  => false,
				'keep_history'   => 90,
				'review_before_apply' => true,
				'undo_enabled'   => true,
			),
			'agent'      => array(
				'enabled'          => false,
				'autonomy'         => 'supervised', // off|supervised|assisted|autonomous.
				'schedule'         => 'daily', // hourly|daily|weekly|off.
				'hour'             => 3,
				'max_steps'        => 25,
				'max_minutes'      => 8,
				'budget_usd'       => 2.0,
				'parallel'         => 4,
				'skills'           => array(
					'audit_fix'    => true,
					'meta_fill'    => true,
					'internal_link' => true,
					'alt_fill'     => true,
					'redirect_404' => true,
					'schema_fill'  => true,
					'index_submit' => true,
					'content_gap'  => true,
					'write_article' => false,
					'optimize_post' => true,
				),
				'publish'          => 'draft', // draft|review|publish.
				'articles_per_run' => 1,
				'min_quality'      => 70,
				'target_words'     => 1400,
				'tone'             => 'professional',
				'language'         => 'fa',
				'allow_external'   => false,
				'max_post_edits'   => 30,
				'cooldown_hours'   => 24,
				'notify'           => true,
				'notify_email'     => '',
				'keep_runs'        => 60,
				'memory'           => true,
				'dry_run'          => false,
			),
			'appearance' => array(
				'theme'         => 'auto', // light|dark|auto.
				'accent'        => 'firouzeh', // firouzeh|lajevard|zahresh|anabi|sormeh|tajalli.
				'density'       => 'comfortable', // compact|comfortable|spacious.
				'font'          => 'vazirmatn',
				'remote_font'   => true,
				'font_url'      => '',
				'persian_digits' => true,
				'shamsi'        => true,
				'sidebar'       => 'pinned',
				'widgets'       => array( 'score', 'issues', 'traffic', 'keywords', 'ai', 'actions' ),
				'show_welcome'  => true,
				'custom_css'    => '',
			),
			'security'   => array(
				'min_capability'  => 'manage_options',
				'allow_editors'   => true,
				'editor_meta_cap' => 'edit_posts',
				'token_ttl'       => 43200,
				'anonymize_ip'    => true,
				'retention'       => 180,
				'disable_htaccess' => false,
				'audit_trail'     => true,
			),
			'advanced'   => array(
				'object_cache'      => true,
				'persist_cache'     => true,
				'async_analysis'    => true,
				'analysis_on_save'  => true,
				'autoload_index'    => true,
				'delete_on_uninstall' => true,
				'clear_postmeta'    => false,
				'debug'             => false,
				'exclude_post_types' => array(),
				'exclude_urls'      => array(),
				'rest_namespace'    => 'hoosh/v1',
				'bing_key'          => '',
				'baidu_key'         => '',
				'yandex_key'        => '',
				'app_path'          => 'hoosh-seo-studio',
				'site_health'       => true,
				'heartbeat'         => true,
				'indexing_batch'    => 100,
			),
			'breadcrumbs' => array(
				'enabled'             => true,
				'source'              => 'hoosh',
				'position'            => 'none',
				'separator'           => '\u00bb',
				'home_label'          => 'خانه',
				'home_link'           => true,
				'show_home_icon'      => false,
				'show_current'        => true,
				'show_count'          => false,
				'prefix'              => 'شما اینجا هستید:',
				'rich_snippet'        => true,
				'taxonomy_hierarchy'  => true,
				'archive_format'      => 'آرشیو %%title%%',
				'search_format'       => 'جستجو برای: %%query%%',
				'404_label'           => '404',
				'current_class'       => 'is-current',
				'size'                => 'medium',
				'post_types'          => array(),
			),
			'audit' => array(
				'enabled'         => true,
				'retain'          => 90,
				'retention_days'  => 90,
				'snapshot_limit'  => 20000,
				'log_auth'        => true,
				'log_updates'     => true,
				'ignore'          => array(),
				'file_paths'      => array(),
				'file_watch'      => false,
				'mail_digest'     => false,
				'mail_files'      => false,
				'mail_to'         => '',
				'ping_on_menu'    => false,
			),
			'woo' => array(
				'enabled'                 => true,
				'schema'                  => true,
				'gtin_field'              => true,
				'mpn_field'               => true,
				'isbn_field'              => true,
				'brand_taxonomy'          => 'product_brand',
				'brand_index'             => false,
				'hide_wc_notices_seo'     => true,
				'noindex_cart'            => true,
				'noindex_checkout'        => true,
				'noindex_account'         => true,
				'noindex_wishlist'        => false,
				'noindex_authorized'      => true,
				'remove_wc_version'       => true,
				'image_alt_from_title'    => true,
				'short_description_meta'  => true,
				'stock_in_schema'         => true,
				'rating_in_schema'        => true,
				'shipping_in_schema'      => false,
				'return_policy'           => '',
				'breadcrumb_cat'          => true,
				'strip_pagination'        => true,
				'variations_meta'         => true,
			),
			'reports' => array(
				'enabled'           => true,
				'email_to'          => array(),
				'email_subject'     => '',
				'email_format'      => 'inline',
				'schedule'          => 'weekly',
				'day'               => 6,
				'hour'              => 9,
				'include_sections'  => array( 'health', 'issues', 'worst', 'links', 'rank' ),
				'logo_id'           => 0,
				'footer_note'       => '',
				'public_tokens'     => 1,
			),
			'speed' => array(
				'enabled'               => false,
				'disable_emojis'        => true,
				'disable_oembed'        => true,
				'remove_query_strings'  => false,
				'defer_js'              => false,
				'remove_unused_css'     => false,
				'inline_critical_css'   => false,
				'preload_fonts'         => true,
				'avatar_size'           => 0,
				'gravatar_cache'        => false,
				'heartbeat'             => 'reduce',
				'revisions_limit'       => 0,
				'autosave_interval'     => 60,
				'remove_jquery_migrate' => true,
				'preload_lcp'           => true,
				'lazy_iframes'          => true,
				'minify_html'           => false,
				'admin_bar'             => true,
				'dns_prefetch'          => true,
				'image_placeholders'    => false,
				'js_delay_ms'           => 0,
				'excluded_scripts'      => array(),
				'cdn_url'               => '',
			),
			'migration' => array(
				'enabled'                => true,
				'last_batch'             => '',
				'keep_originals'         => true,
				'delete_source'          => false,
				'redirect_after_import'  => false,
				'map_post_types'         => array(),
			),

		);
	}

	/**
	 * Read everything merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->data ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			$this->data = $this->merge( self::defaults(), $stored );
			$this->data = $this->apply_constants( $this->data );
		}
		return $this->data;
	}

	/**
	 * Read a dot-notation key.
	 *
	 * @param string $key     Dot key, e.g. "sitemap.enabled".
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all  = $this->all();
		$node = $all;

		foreach ( explode( '.', $key ) as $part ) {
			if ( is_array( $node ) && array_key_exists( $part, $node ) ) {
				$node = $node[ $part ];
			} elseif ( is_array( $node ) && isset( $node[0] ) && is_array( $node[0] ) && ctype_digit( $part ) ) {
				$node = $node[ (int) $part ];
			} else {
				return $default;
			}
		}

		return $node;
	}

	/**
	 * Is a boolean toggle on?
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function on( $key ) {
		return (bool) $this->get( $key, false );
	}

	/**
	 * Persist one or more keys.
	 *
	 * @param array $values Flat dot-key => value map, or nested tree when $nested = true.
	 * @param bool  $nested Whether $values is a partial tree.
	 * @return array The full updated tree.
	 */
	public function set( $values, $nested = false ) {
		$current = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		if ( $nested ) {
			$current = $this->merge( $current, $values );
		} else {
			foreach ( $values as $key => $value ) {
				$this->set_nested( $current, explode( '.', $key ), $value );
			}
		}

		$sanitized = $this->sanitize_tree( $current );
		update_option( self::OPTION, $sanitized, false );
		$this->data = null;

		/**
		 * Fires after HooshSEO settings are saved.
		 *
		 * @param array $sanitized New settings tree.
		 */
		do_action( 'hoosh_seo_settings_saved', $sanitized );

		return $this->all();
	}

	/**
	 * Reset one group to defaults.
	 *
	 * @param string $group Group key.
	 * @return array
	 */
	public function reset_group( $group ) {
		$defaults = self::defaults();
		$current  = get_option( self::OPTION, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		unset( $current[ $group ] );
		update_option( self::OPTION, $current, false );
		$this->data = null;
		return isset( $defaults[ $group ] ) ? $defaults[ $group ] : array();
	}

	/**
	 * Replace the whole tree (import).
	 *
	 * @param array $tree Tree.
	 * @return array
	 */
	public function replace( $tree ) {
		$tree = $this->sanitize_tree( $this->merge( self::defaults(), (array) $tree ) );
		update_option( self::OPTION, $tree, false );
		$this->data = null;
		return $this->all();
	}

	/**
	 * Export the settings (for JSON download) minus secrets.
	 *
	 * @param bool $include_secrets Keep API keys.
	 * @return array
	 */
	public function export( $include_secrets = false ) {
		$tree = $this->all();
		if ( ! $include_secrets ) {
			$walk = function ( &$node, $key = '' ) use ( &$walk ) {
				if ( is_array( $node ) ) {
					foreach ( $node as $k => &$v ) {
						$walk( $v, $k );
					}
					unset( $v );
					return;
				}
				if ( is_string( $key ) && preg_match( '/(key|token|secret|password|credentials|refresh)/i', $key ) ) {
					$node = '';
				}
			};
			$walk( $tree );
		}
		return $tree;
	}

	/**
	 * Constants override (for staging lockdowns).
	 *
	 * @param array $data Data.
	 * @return array
	 */
	protected function apply_constants( $data ) {
		if ( defined( 'HOOSH_SEO_AI_DISABLED' ) && HOOSH_SEO_AI_DISABLED ) {
			$data['ai']['enabled'] = false;
		}
		if ( defined( 'HOOSH_SEO_APP_PATH' ) ) {
			$data['advanced']['app_path'] = HOOSH_SEO_APP_PATH;
		}
		return $data;
	}

	/**
	 * Recursively merge stored values over defaults (defaults win on key set only).
	 *
	 * @param array $default Default tree.
	 * @param array $stored  Stored tree.
	 * @return array
	 */
	protected function merge( $default, $stored ) {
		foreach ( $stored as $key => $value ) {
			if ( is_array( $value ) && isset( $default[ $key ] ) && is_array( $default[ $key ] ) && ! $this->is_list( $value ) ) {
				$default[ $key ] = $this->merge( $default[ $key ], $value );
			} else {
				$default[ $key ] = $value;
			}
		}
		return $default;
	}

	/**
	 * Is an array a sequential list?
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	protected function is_list( $arr ) {
		if ( ! is_array( $arr ) || array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Assign a nested value by path.
	 *
	 * @param array $tree Tree (by reference).
	 * @param array $path Path parts.
	 * @param mixed $value Value.
	 */
	protected function set_nested( &$tree, $path, $value ) {
		$node = &$tree;
		foreach ( $path as $i => $part ) {
			if ( $i === count( $path ) - 1 ) {
				$node[ $part ] = $value;
				break;
			}
			if ( ! isset( $node[ $part ] ) || ! is_array( $node[ $part ] ) ) {
				$node[ $part ] = array();
			}
			$node = &$node[ $part ];
		}
		unset( $node );
	}

	/**
	 * Coerce types so a hand-edited option can never break the app.
	 *
	 * @param array $tree Tree.
	 * @return array
	 */
	protected function sanitize_tree( $tree ) {
		$defaults = self::defaults();

		$clean = function ( $default, $value ) use ( &$clean ) {
			if ( is_array( $default ) ) {
				if ( ! is_array( $value ) ) {
					return $default;
				}
				if ( array() === $default ) {
					return array_values( $value );
				}
				$out = array();
				foreach ( $value as $k => $v ) {
					$out[ is_int( $k ) ? $k : sanitize_key( (string) $k ) ] = $v;
				}
				return $out;
			}

			if ( is_bool( $default ) ) {
				return (bool) $value;
			}
			if ( is_int( $default ) ) {
				return (int) $value;
			}
			if ( is_float( $default ) ) {
				return (float) $value;
			}

			return is_scalar( $value ) ? (string) $value : '';
		};

		$walk = function ( $default, $value ) use ( &$walk, $clean ) {
			if ( ! is_array( $default ) ) {
				return $clean( $default, $value );
			}
			if ( ! is_array( $value ) ) {
				return $default;
			}
			// Lists of scalars (or of arrays) are kept as submitted.
			if ( array() === $default || isset( $default[0] ) ) {
				return $clean( $default, $value );
			}
			$out = array();
			foreach ( $default as $key => $sub ) {
				$out[ $key ] = array_key_exists( $key, $value ) ? $walk( $sub, $value[ $key ] ) : $sub;
			}
			// Preserve unknown sub-groups (e.g. custom post types keyed by name).
			foreach ( $value as $key => $sub ) {
				if ( ! array_key_exists( $key, $out ) ) {
					$out[ $key ] = $sub;
				}
			}
			return $out;
		};

		return $walk( $defaults, $tree );
	}
}
