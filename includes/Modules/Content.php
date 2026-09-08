<?php
/**
 * On-page analysis engine: SEO + Persian readability, live and in bulk.
 *
 * Everything here is deterministic and offline-first (no API calls); AI is only
 * asked for suggestions through the Gateway, never to decide a pass/fail.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class Content
 */
final class Content {

	/**
	 * Singleton.
	 *
	 * @var Content|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Content
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
		add_action( 'hoosh_seo_batch', array( __CLASS__, 'drain' ) );
		add_action( 'save_post', array( __CLASS__, 'mark_stale' ), 50, 1 );
		add_filter( 'display_post_states', array( $this, 'post_state' ), 20, 2 );
	}

	/**
	 * The check catalogue — one source of truth for scoring, UI copy and settings.
	 *
	 * @return array
	 */
	public static function check_catalog() {
		return array(
			'keyword_title'      => array(
				'group'    => 'seo',
				'label'    => __( 'کلمه کلیدی در عنوان سئو', 'hoosh-seo' ),
				'severity' => 'critical',
				'why'      => __( 'عنوان قوی‌ترین سیگنال موضوع صفحه است؛ نبودن کلمه کلیدی در آن، شانس رتبه را کم می‌کند.', 'hoosh-seo' ),
				'how'      => __( 'کلمه کلیدی اصلی را در عنوان بیاورید، ترجیحاً در ابتدای آن.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'keyword_start'      => array(
				'group'    => 'seo',
				'label'    => __( 'کلمه کلیدی در ابتدای عنوان', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'گوگل به ابتدای عنوان وزن بیشتری می‌دهد و کاربر هم زودتر آن را می‌بیند.', 'hoosh-seo' ),
				'how'      => __( 'عنوان را با کلمه کلیدی شروع کنید و نام برند را به انتها ببرید.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'keyword_desc'       => array(
				'group'    => 'seo',
				'label'    => __( 'کلمه کلیدی در توضیحات متا', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'توضیحات متا مستقیم رتبه را بالا نمی‌برد اما نرخ کلیک را تغییر می‌دهد.', 'hoosh-seo' ),
				'how'      => __( 'یک جمله کامل و ترغیب‌کننده بنویسید که کلمه کلیدی در آن باشد.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'keyword_content'    => array(
				'group'    => 'seo',
				'label'    => __( 'پراکندگی کلمه کلیدی در متن', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'محتوای پراکنده بهتر از محتوای انباشته عمل می‌کند؛ بخش‌های بدون اشاره به موضوع، اعتبار صفحه را کم می‌کنند.', 'hoosh-seo' ),
				'how'      => __( 'کلمه کلیدی یا مترادف‌هایش را در ابتدا، میانه و انتهای متن به کار ببرید.', 'hoosh-seo' ),
				'fix'      => 'manual',
			),
			'keyword_density'    => array(
				'group'    => 'seo',
				'label'    => __( 'چگالی کلمه کلیدی', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'چگالی خیلی کم یعنی موضوع مبهم، خیلی زیاد یعنی انباشت کلیدواژه.', 'hoosh-seo' ),
				'how'      => __( 'چگالی را در بازه‌ای که در تنظیمات تعریف کرده‌اید نگه دارید (معمولاً ۰٫۵ تا ۳ درصد).', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'keyword_headings'   => array(
				'group'    => 'seo',
				'label'    => __( 'کلمه کلیدی در تیترهای میانی', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'H2 و H3 ساختار موضوع را به گوگل و کاربر نشان می‌دهند.', 'hoosh-seo' ),
				'how'      => __( 'دست‌کم یک تیتر میانی شامل کلمه کلیدی یا مترادف آن باشد.', 'hoosh-seo' ),
				'fix'      => 'manual',
			),
			'keyword_url'        => array(
				'group'    => 'seo',
				'label'    => __( 'کلمه کلیدی در پیوند یکتا', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'نشانی کوتاه و معنادار هم برای کاربر و هم برای گوگل بهتر است.', 'hoosh-seo' ),
				'how'      => __( 'از نگارش لاتین کلمه کلیدی در slug استفاده کنید.', 'hoosh-seo' ),
				'fix'      => 'auto',
			),
			'content_length'     => array(
				'group'    => 'content',
				'label'    => __( 'بلندی محتوا', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'صفحه‌ای که پاسخ کامل نمی‌دهد، رتبه نمی‌گیرد و کاربر را نگه نمی‌دارد.', 'hoosh-seo' ),
				'how'      => __( 'به مقدار پیشنهادی متن برسانید؛ کیفیت مهم‌تر از تعداد کلمه است.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'internal_links'     => array(
				'group'    => 'seo',
				'label'    => __( 'لینک داخلی', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'لینک داخلی، اعتبار را بین صفحات می‌چرخاند و مسیر خزیدن را می‌سازد.', 'hoosh-seo' ),
				'how'      => __( 'به صفحات مرتبط خود لینک بدهید؛ می‌توانید از «لینک داخلی» خودکار استفاده کنید.', 'hoosh-seo' ),
				'fix'      => 'auto',
			),
			'external_links'     => array(
				'group'    => 'seo',
				'label'    => __( 'لینک به منابع معتبر', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'ارجاع به منبع دست‌اول، اعتبار معنایی صفحه را بالا می‌برد.', 'hoosh-seo' ),
				'how'      => __( 'یک یا دو لینک به منبع معتبر غیررقیب اضافه کنید.', 'hoosh-seo' ),
				'fix'      => 'manual',
			),
			'images_alt'         => array(
				'group'    => 'content',
				'label'    => __( 'متن جایگزین تصاویر', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'Alt به خوانایی، دسترس‌پذیری و جستجوی تصویر کمک می‌کند.', 'hoosh-seo' ),
				'how'      => __( 'برای هر تصویر یک توصیف کوتاه و واقعی بنویسید.', 'hoosh-seo' ),
				'fix'      => 'auto',
			),
			'schema'             => array(
				'group'    => 'technical',
				'label'    => __( 'داده ساختاریافته', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'اسکیما شانس نمایش نتایج غنی و درک هویت سایت را بالا می‌برد.', 'hoosh-seo' ),
				'how'      => __( 'نوع اسکیما را برای این نوع محتوا فعال کنید.', 'hoosh-seo' ),
				'fix'      => 'auto',
			),
			'title_length'       => array(
				'group'    => 'seo',
				'label'    => __( 'طول عنوان در نتایج جستجو', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'عنوان بلند با «…» بریده می‌شود و نرخ کلیک را کم می‌کند.', 'hoosh-seo' ),
				'how'      => __( 'عنوان را کوتاه کنید تا در دسکتاپ و موبایل کامل دیده شود.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'desc_length'        => array(
				'group'    => 'seo',
				'label'    => __( 'طول توضیحات متا', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'متن خیلی کوتاه یا خیلی بلند، در SERP ناقص نمایش داده می‌شود.', 'hoosh-seo' ),
				'how'      => __( 'بین دو تا چهار جمله مفید بنویسید.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'sentence_length'    => array(
				'group'    => 'readability',
				'label'    => __( 'جمله‌های کوتاه', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'جمله‌های طولی خواندن را سخت می‌کنند و کاربر صفحه را ول می‌کند.', 'hoosh-seo' ),
				'how'      => __( 'جمله‌های بلند را بشکنید؛ هر جمله یک مفهوم، و جمله بعدی مفهوم بعدی.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'paragraph_length'   => array(
				'group'    => 'readability',
				'label'    => __( 'پاراگراف‌های قابل‌هضم', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'دیوارِ متن روی موبایل خوانده نمی‌شود.', 'hoosh-seo' ),
				'how'      => __( 'پاراگراف‌ها را به ۴۰ تا ۱۵۰ کلمه برسانید.', 'hoosh-seo' ),
				'fix'      => 'manual',
			),
			'transition_words'   => array(
				'group'    => 'readability',
				'label'    => __( 'کلمات ربط و پیوستگی متن', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'متن بدون حرف ربط، لیست‌وار و خسته‌کننده است.', 'hoosh-seo' ),
				'how'      => __( 'بین جمله‌ها از «بنابراین»، «با این حال»، «علاوه بر این» و… استفاده کنید.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'passive_voice'      => array(
				'group'    => 'readability',
				'label'    => __( 'جمله‌های مجهول', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'مجهول، متن را اداری و کند می‌کند.', 'hoosh-seo' ),
				'how'      => __( 'فاعل را مشخص کنید: «انجام شد» → «تیم پشتیبانی انجام داد».', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'subheading_density' => array(
				'group'    => 'readability',
				'label'    => __( 'پراکندگی تیترها', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'تیترها متن را برای چشم اسکن‌پذیر می‌کنند.', 'hoosh-seo' ),
				'how'      => __( 'هر ۱۵۰ تا ۳۰۰ کلمه یک تیتر بگذارید.', 'hoosh-seo' ),
				'fix'      => 'manual',
			),
			'heading_structure'  => array(
				'group'    => 'content',
				'label'    => __( 'سلسله‌مراتب تیترها', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'پرش بین H1/H2/H3 نشانه ساختار شل است.', 'hoosh-seo' ),
				'how'      => __( 'یک H1، سپس زیرمجموعه‌ها را به ترتیب بچینید.', 'hoosh-seo' ),
				'fix'      => 'manual',
			),
			'readability_flow'    => array(
				'group'    => 'readability',
				'label'    => __( ' روانی کلی متن', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'نمای کلی از سختی خواندن، ترکیب جمله، واژه و تکرار.', 'hoosh-seo' ),
				'how'      => __( 'متن را بلند بخوانید؛ هر جا نفس کم آوردید، بشکنید.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'word_repetition'    => array(
				'group'    => 'readability',
				'label'    => __( 'تکرار واژه', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'تکرار یک واژه در چند جمله پشت‌سرهم، متن را ماشینی می‌کند.', 'hoosh-seo' ),
				'how'      => __( 'از مترادف یا ضمیر استفاده کنید.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'simple_words'       => array(
				'group'    => 'readability',
				'label'    => __( 'واژگان ساده', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'واژه سخت، فهم را کم می‌کند؛ مخاطب عمومی مهم‌تر از مخاطب تخصصی است.', 'hoosh-seo' ),
				'how'      => __( 'واژه‌های عربی/تخصصی سنگین را با معادل رایج بنویسید.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'url_length'         => array(
				'group'    => 'technical',
				'label'    => __( 'کوتاهی نشانی صفحه', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'نشانی بلند در موبایل و اشتراک‌گذاری زشت است.', 'hoosh-seo' ),
				'how'      => __( 'پیشوندهای بی‌فایده را حذف کنید.', 'hoosh-seo' ),
				'fix'      => 'auto',
			),
			'social_image'       => array(
				'group'    => 'social',
				'label'    => __( 'تصویر اشتراک‌گذاری', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'بدون تصویر، لینک شما در شبکه‌های اجتماعی دیده نمی‌شود.', 'hoosh-seo' ),
				'how'      => __( 'تصویر شاخص ۱۲۰۰×۶۳ بگذارید.', 'hoosh-seo' ),
				'fix'      => 'manual',
			),
			'outbound_nofollow'  => array(
				'group'    => 'seo',
				'label'    => __( 'مدیریت rel لینک‌های بیرونی', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'لینک تبلیغاتی بدون nofollow می‌تواند مشکل‌ساز شود.', 'hoosh-seo' ),
				'how'      => __( 'برای لینک‌های پرداختی sponsored/nofollow بگذارید.', 'hoosh-seo' ),
				'fix'      => 'auto',
			),
			'focus_present'      => array(
				'group'    => 'seo',
				'label'    => __( 'انتخاب کلمه کلیدی', 'hoosh-seo' ),
				'severity' => 'critical',
				'why'      => __( 'بی‌کلمه کلیدی، هیچ تحلیلی معنا ندارد.', 'hoosh-seo' ),
				'how'      => __( 'یک کلمه اصلی و حداکثر چهار کلمه پشتیبان انتخاب کنید.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'keyword_duplication' => array(
				'group'    => 'seo',
				'label'    => __( 'تکرار کلمه کلیدی در سایت', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'دو صفحه روی یک کلمه، همدیگر را می‌خورند (کانیبالیزیشن).', 'hoosh-seo' ),
				'how'      => __( 'یکی را ادغام یا هدایت کنید یا کلمه کلیدی را تغییر دهید.', 'hoosh-seo' ),
				'fix'      => 'manual',
			),
			'first_paragraph'    => array(
				'group'    => 'content',
				'label'    => __( 'پاسخ در پاراگراف نخست', 'hoosh-seo' ),
				'severity' => 'warning',
				'why'      => __( 'هم کاربر و هم موتورهای پاسخ، اول دو جمله اول را می‌خوانند.', 'hoosh-seo' ),
				'how'      => __( 'تعریف یا پاسخ اصلی را در ۴۰ کلمه اول بگویید.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
			'faq_present'        => array(
				'group'    => 'content',
				'label'    => __( 'پرسش‌های متداول', 'hoosh-seo' ),
				'severity' => 'notice',
				'why'      => __( 'FAQ شانس گرفتن People Also Ask و اسنیپ گسترده را زیاد می‌کند.', 'hoosh-seo' ),
				'how'      => __( '۳ تا ۵ پرسش واقعی کاربر را با پاسخ کامل بنویسید.', 'hoosh-seo' ),
				'fix'      => 'ai',
			),
		);
	}

	/**
	 * Queue a cheap re-analysis after a save (no AI).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function queue_analysis( $post_id ) {
		if ( ! \hoosh_seo()->settings->get( 'advanced.async_analysis', true ) ) {
			self::store( $post_id );
			return;
		}
		wp_schedule_single_event( time() + 3, 'hoosh_seo_analyze_post', array( (int) $post_id ) );
		add_action( 'hoosh_seo_analyze_post', array( __CLASS__, 'store' ) );
	}

	/**
	 * Mark the stored score stale.
	 *
	 * @param int $post_id Post ID.
	 */
	public function mark_stale( $post_id ) {
		delete_post_meta( (int) $post_id, '_hs_analysis_checked' );
	}

	/**
	 * Post states column ("سئو: ۷۲").
	 *
	 * @param array    $states States.
	 * @param \WP_Post $post   Post.
	 * @return array
	 */
	public function post_state( $states, $post ) {
		$score = (float) get_post_meta( $post->ID, '_hs_score', true );
		if ( $score ) {
			$states['hoosh'] = sprintf( /* translators: %s score */ __( 'هوش‌سئو: %s', 'hoosh-seo' ), number_format_i18n( $score, 0 ) );
		}
		return $states;
	}

	/**
	 * Analyse a post and persist the result.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function store( $post_id ) {
		$post_id = (int) $post_id;
		$result  = self::analyze( $post_id );

		update_post_meta( $post_id, '_hs_score', $result['score'] );
		update_post_meta( $post_id, '_hs_analysis', Meta::sanitize_analysis( $result ) );

		self::upsert_page_row( $post_id, $result );

		/**
		 * Fires after a post is analysed.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $result   Result.
		 */
		do_action( 'hoosh_seo_analyzed', $post_id, $result );

		return $result;
	}

	/**
	 * Write/update the audit cache row.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $result    Analysis.
	 */
	protected static function upsert_page_row( $post_id, $result ) {
		global $wpdb;
		if ( ! Database::exists( 'pages' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$table = Database::table( 'pages' );
		$data  = array(
			'post_id'      => $post_id,
			'url'          => mb_substr( (string) get_permalink( $post_id ), 0, 500 ),
			'hash'         => md5( (string) Helpers::rel_url( get_permalink( $post_id ) ) ),
			'type'         => $post->post_type,
			'title'        => mb_substr( (string) get_the_title( $post_id ), 0, 255 ),
			'description'  => (string) Meta::resolve( $post_id, 'description' ),
			'keywords'     => wp_json_encode( Meta::focus_keywords( $post_id ) ),
			'word_count'   => (int) $result['words'],
			'links_out'    => (int) $result['metrics']['external'],
			'links_in'     => (int) self::incoming_links( $post_id ),
			'score'        => (float) $result['score'],
			'seo_score'    => (float) $result['seo'],
			'readability'  => (float) $result['readability'],
			'content_hash' => md5( (string) $post->post_content . $post->post_title ),
			'issues'       => wp_json_encode( $result['issues'] ),
			'suggestions'  => wp_json_encode( array_slice( (array) $result['suggestions'], 0, 12 ) ),
			'state'        => 'done',
			'scanned_at'   => current_time( 'mysql', true ),
		);

		$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $table . ' WHERE post_id = %d', $post_id ) ); // phpcs:ignore
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing ) ); // phpcs:ignore
			return;
		}
		$wpdb->insert( $table, $data ); // phpcs:ignore
	}

	/**
	 * Count links from other posts to this post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function incoming_links( $post_id ) {
		return (int) Helpers::cache(
			'incoming-' . (int) $post_id,
			function () use ( $post_id ) {
				global $wpdb;
				$url  = Helpers::rel_url( (string) get_permalink( $post_id ) );
				$like = '%' . $wpdb->esc_like( $url ) . '%';
				return (int) $wpdb->get_var( // phpcs:ignore
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = ''
						WHERE p.post_status = 'publish' AND p.ID <> %d AND p.post_content LIKE %s",
						$post_id,
						$like
					)
				);
			},
			3600
		);
	}

	/**
	 * Live analysis for arbitrary draft data (editor preview).
	 *
	 * @param array $data Draft fields.
	 * @return array
	 */
	public static function live( $data ) {
		$data = wp_parse_args(
			(array) $data,
			array(
				'post_id'     => 0,
				'title'       => '',
				'content'     => '',
				'excerpt'     => '',
				'description' => '',
				'keywords'    => array(),
				'slug'        => '',
			)
		);

		$result = self::score(
			(string) $data['title'],
			(string) $data['content'],
			(string) $data['description'],
			(array) $data['keywords'],
			(string) $data['slug'],
			(int) $data['post_id'],
			(string) $data['excerpt']
		);

		return $result;
	}

	/**
	 * Analyse a stored post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function analyze( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return self::empty_result();
		}

		$title = Meta::resolve( $post_id, 'title' );
		$desc  = Meta::resolve( $post_id, 'description' );

		$result = self::score(
			$title,
			$post->post_content,
			$desc,
			Meta::focus_keywords( $post_id ),
			$post->post_name,
			$post_id,
			$post->post_excerpt
		);

		return $result;
	}

	/**
	 * The engine. Deterministic, Persian-aware, no network.
	 *
	 * @param string $title      SEO title.
	 * @param string $content    Post content (HTML).
	 * @param string $desc       Meta description.
	 * @param array  $keywords   Focus keywords.
	 * @param string $slug       URL slug.
	 * @param int    $post_id    Post ID.
	 * @param string $excerpt    Excerpt.
	 * @return array
	 */
	public static function score( $title, $content, $desc, $keywords, $slug, $post_id = 0, $excerpt = '' ) {
		$settings  = \hoosh_seo()->settings;
		$catalog   = self::check_catalog();
		$weights   = (array) $settings->get( 'content.weights', array() );
		$primary   = $keywords ? (string) reset( $keywords ) : '';
		$text      = trim( wp_strip_all_tags( strip_shortcodes( (string) $content ) ) );
		$text_norm = Helpers::normalize_fa( $text );
		$title_norm = Helpers::normalize_fa( $title );
		$desc_norm   = Helpers::normalize_fa( $desc );
		$words       = Helpers::word_count( $content );
		$primary_norm = Helpers::normalize_fa( $primary );

		$issues = array();
		$got     = array();

		$mark = function ( $id, $status, $value = null, $extra = array() ) use ( &$issues, $catalog, $primary ) {
			if ( ! isset( $catalog[ $id ] ) ) {
				return;
			}
			$def = $catalog[ $id ];
			$issues[] = array_merge(
				array(
					'id'       => $id,
					'label'    => $def['label'],
					'group'    => $def['group'],
					'severity' => 'pass' === $status ? 'ok' : $def['severity'],
					'status'   => $status,
					'why'      => $def['why'],
					'how'      => $def['how'],
					'fix'      => $def['fix'],
					'keyword'  => $primary,
				),
				$extra,
				array( 'value' => $value )
			);
		};

		// Focus keyword presence.
		if ( ! $primary ) {
			$mark( 'focus_present', 'fail' );
		} else {
			$mark( 'focus_present', 'pass' );
		}

		$in_title = $primary_norm && false !== strpos( $title_norm, $primary_norm );
		$in_desc  = $primary_norm && false !== strpos( $desc_norm, $primary_norm );
		$in_first = $primary_norm && self::in_first_paragraph( $text_norm, $primary_norm );
		$in_any   = $primary_norm && false !== strpos( $text_norm, $primary_norm );

		$mark( 'keyword_title', $in_title ? 'pass' : 'fail', $in_title ? __( 'در عنوان هست', 'hoosh-seo' ) : __( 'در عنوان نیست', 'hoosh-seo' ) );

		$start_ok = $in_title && 0 === mb_strpos( $title_norm, $primary_norm );
		$mark( 'keyword_start', $in_title ? ( $start_ok ? 'pass' : 'warn' ) : 'fail' );

		$mark( 'keyword_desc', $in_desc ? 'pass' : ( '' === $desc ? 'fail' : 'warn' ) );

		// Distribution: in how many content blocks does the keyword (or a synonym) appear?
		$blocks = preg_split( '/<\/(p|h[1-6]|li|blockquote|div)>/u', $text );
		$blocks = array_filter( (array) $blocks, 'trim' );
		$hits   = 0;
		foreach ( $blocks as $block ) {
			if ( $primary_norm && false !== strpos( Helpers::normalize_fa( (string) $block ), $primary_norm ) ) {
				$hits++;
			}
		}
		$ratio = $blocks ? $hits / count( $blocks ) : 0;
		$mark(
			'keyword_content',
			! $in_any ? 'fail' : ( $ratio >= 0.25 ? 'pass' : ( $ratio >= 0.1 ? 'warn' : 'fail' ) ),
			(array) array( 'blocks' => count( $blocks ), 'hits' => $hits, 'ratio' => round( $ratio * 100 ) )
		);

		// Density.
		$density = 0.0;
		if ( $primary_norm && $words ) {
			$count      = max( 1, substr_count( $text_norm, $primary_norm ) );
			$density    = round( ( $count * max( 1, str_word_count( $primary_norm ) )) / $words * 100, 2 );
		}
		$dmin = (float) $settings->get( 'content.density_min', 0.4 );
		$dmax = (float) $settings->get( 'content.density_max', 3.0 );
		$dstatus = ! $primary ? 'fail' : ( ( $density >= $dmin && $density <= $dmax ) ? 'pass' : ( $density > $dmax ? 'fail' : 'warn' ) );
		$mark( 'keyword_density', $dstatus, array( 'density' => $density, 'range' => array( $dmin, $dmax ) ) );

		// Headings.
		preg_match_all( '/<h([2-6])[^>]*>(.*?)<\/h\1>/is', (string) $content, $heads, PREG_SET_ORDER );
		$head_hits = 0;
		$levels    = array();
		foreach ( $heads as $head ) {
			$levels[] = (int) $head[1];
			if ( $primary_norm && false !== strpos( Helpers::normalize_fa( wp_strip_all_tags( $head[2] ) ), $primary_norm ) ) {
				$head_hits++;
			}
		}
		$mark(
			'keyword_headings',
			! $primary ? 'fail' : ( $head_hits ? 'pass' : 'warn' ),
			array( 'found' => $head_hits, 'total' => count( $heads ) )
		);

		// Slug.
		$slug_norm = Helpers::normalize_fa( str_replace( '-', ' ', (string) $slug ) );
		$slug_ok   = $primary_norm && ( false !== strpos( $slug_norm, $primary_norm ) || false !== strpos( $slug_norm, str_replace( ' ', '-', $primary_norm ) ) || Helpers::safe_slug( $primary, 'en' ) && false !== strpos( (string) $slug, Helpers::safe_slug( $primary, 'en' ) ) );
		$mark( 'keyword_url', $slug_ok ? 'pass' : 'warn' );

		// Content length.
		$min_words = (int) $settings->get( 'content.min_words', 600 );
		$mark( 'content_length', $words >= $min_words ? 'pass' : ( $words >= $min_words * 0.6 ? 'warn' : 'fail' ), array( 'words' => $words, 'min' => $min_words ) );

		// Links.
		$links = self::analyse_links( $content, $post_id );
		$need_internal = max( 1, (int) ceil( $words / max( 100, (int) $settings->get( 'content.internal_links_per', 300 ) ) ) );
		$mark( 'internal_links', $links['internal'] >= $need_internal ? 'pass' : ( $links['internal'] ? 'warn' : 'fail' ), array( 'found' => $links['internal'], 'need' => $need_internal ) );
		$mark( 'external_links', $links['external'] >= (int) $settings->get( 'content.external_links_min', 0 ) ? 'pass' : 'notice', array( 'found' => $links['external'] ) );
		if ( $links['unmanaged'] > 0 ) {
			$mark( 'outbound_nofollow', 'warn', array( 'unmanaged' => $links['unmanaged'] ) );
		}

		// Images.
		$images = self::analyse_images( $content, $post_id );
		$mark( 'images_alt', $images['missing'] ? 'fail' : 'pass', array( 'total' => $images['total'], 'missing' => $images['missing'] ) );

		// Title length (chars + px).
		$tlen  = Helpers::strlen( $title );
		$tpx   = Helpers::pixel_width( $title, 20 );
		$tmin  = (int) $settings->get( 'content.title_min', 30 );
		$tmax  = (int) $settings->get( 'content.title_max', 60 );
		$tpx_max = (int) $settings->get( 'content.title_max_px', 580 );
		$tstatus = ( $tlen >= $tmin && $tlen <= $tmax && $tpx <= $tpx_max ) ? 'pass' : ( ( $tlen < $tmin ) ? 'warn' : 'fail' );
		$mark( 'title_length', $tstatus, array( 'chars' => $tlen, 'px' => $tpx, 'range' => array( $tmin, $tmax ) ) );

		// Description length.
		$dlen = Helpers::strlen( $desc );
		$dmin_len = (int) $settings->get( 'content.desc_min', 110 );
		$dmax_len = (int) $settings->get( 'content.desc_max', 158 );
		$dstatus = ( $dlen >= $dmin_len && $dlen <= $dmax_len ) ? 'pass' : ( 0 === $dlen ? 'fail' : 'warn' );
		$mark( 'desc_length', $dstatus, array( 'chars' => $dlen, 'range' => array( $dmin_len, $dmax_len ) ) );

		// Readability: sentences.
		$sentences = Helpers::sentences( $text );
		$long_max  = (int) $settings->get( 'content.sentence_max_words', 24 );
		$long      = 0;
		$lengths   = array();
		foreach ( $sentences as $sentence ) {
			$count = count( Helpers::words( $sentence ) );
			$lengths[] = $count;
			if ( $count > $long_max ) {
				$long++;
			}
		}
		$long_ratio = $sentences ? round( $long / count( $sentences ) * 100, 1 ) : 0;
		$avg        = $lengths ? (int) round( array_sum( $lengths ) / count( $lengths ) ) : 0;
		$mark( 'sentence_length', $long_ratio <= 15 ? 'pass' : ( $long_ratio <= 30 ? 'warn' : 'fail' ), array( 'long' => $long, 'ratio' => $long_ratio, 'avg' => $avg ) );

		// Paragraphs.
		preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', (string) $content, $paragraphs );
		$plines    = array();
		foreach ( (array) $paragraphs[1] as $paragraph ) {
			$plines[] = count( Helpers::words( $paragraph ) );
		}
		$pmax = (int) $settings->get( 'content.paragraph_max_words', 150 );
		$pmin = (int) $settings->get( 'content.paragraph_min_words', 40 );
		$too_long = 0;
		foreach ( $plines as $len ) {
			if ( $len > $pmax ) {
				$too_long++;
			}
		}
		$mark( 'paragraph_length', $too_long ? 'warn' : 'pass', array( 'long' => $too_long, 'paragraphs' => count( $plines ) ) );

		// Transition words.
		$transitions = 0;
		foreach ( Helpers::transition_words() as $word ) {
			$transitions += substr_count( $text_norm, Helpers::normalize_fa( $word ) );
		}
		$tneed = (int) $settings->get( 'content.transition_min', 20 );
		$mark( 'transition_words', $transitions >= (int) ceil( $tneed / 3 ) ? 'pass' : ( $transitions ? 'warn' : 'fail' ), array( 'found' => $transitions, 'want' => (int) ceil( $tneed / 3 ) ) );

		// Passive voice (Persian: شد/گردد/می‌شود/شود with a preceding verb-ish noun, plus "توسط").
		$passive = preg_match_all( '/(\b(?:شد|گردد|شود|گردید|می‌شود|می‌شود|خواهد شد)\b)/u', $text, $mm );
		$passive = (int) $passive;
		$by      = preg_match_all( '/(\bتوسط\s+\S+\s+(?:شد|انجام شد|گردید)\b)/u', $text, $mm2 );
		$passive_ratio = $sentences ? round( ( $passive + (int) $by ) / max( 1, count( $sentences ) ) * 100, 1 ) : 0;
		$mark( 'passive_voice', $passive_ratio <= (float) $settings->get( 'content.passive_max', 10 ) ? 'pass' : 'warn', array( 'found' => $passive, 'ratio' => $passive_ratio ) );

		// Subheading distribution: longest stretch without a heading.
		$gap     = self::longest_heading_gap( $content );
		$gap_max = 300;
		$mark( 'subheading_density', $gap <= $gap_max ? 'pass' : ( $gap <= $gap_max * 1.6 ? 'warn' : 'fail' ), array( 'gap' => $gap ) );

		// Heading structure.
		$structure = self::heading_structure( $content );
		$mark( 'heading_structure', $structure['ok'] ? 'pass' : 'warn', $structure );

		// Word repetition.
		$stop      = Helpers::stopwords();
		$freq      = array();
		foreach ( Helpers::words( $text ) as $word ) {
			$clean = Helpers::normalize_fa( $word );
			if ( in_array( $clean, $stop, true ) || Helpers::strlen( $clean ) < 4 ) {
				continue;
			}
			$freq[ $clean ] = isset( $freq[ $clean ] ) ? $freq[ $clean ] + 1 : 1;
		}
		arsort( $freq );
		$top_word  = $freq ? (string) key( $freq ) : '';
		$max_ratio = $words && $top_word ? round( ( (int) reset( $freq ) / $words ) * 100, 1 ) : 0;
		$mark( 'word_repetition', $max_ratio <= (float) $settings->get( 'content.repeat_word_max', 6 ) ? 'pass' : 'warn', array( 'word' => $top_word, 'ratio' => $max_ratio ) );

		// Simple words: long, heavy Persian/Arabic words.
		$hard = 0;
		foreach ( array_keys( array_slice( $freq, 0, 60, true ) ) as $candidate ) {
			if ( preg_match( '/(می‌باشد|به‌طور|مربوط|عبارت|ذکر|مورد|انجام شده|قابلیت دارد)/u', (string) $candidate ) ) {
				$hard++;
			}
		}
		$mark( 'simple_words', $hard <= 3 ? 'pass' : 'warn', array( 'hard' => $hard ) );

		// Overall flow score (readability).
		$flow = self::readability_index( $sentences, $words, $freq );
		$mark( 'readability_flow', $flow >= 60 ? 'pass' : ( $flow >= 40 ? 'warn' : 'fail' ), array( 'index' => $flow ) );

		// URL length.
		$full = Helpers::rel_url( (string) get_permalink( $post_id ) );
		if ( ! $full ) {
			$full = '/' . trim( (string) $slug, '/' ) . '/';
		}
		$mark( 'url_length', Helpers::strlen( $full ) <= (int) $settings->get( 'content.url_max', 75 ) ? 'pass' : 'warn', array( 'length' => Helpers::strlen( $full ) ) );

		// Social image.
		$social       = $post_id ? (array) get_post_meta( $post_id, '_hs_social', true ) : array();
		$has_social   = ! empty( $social['image_id'] )
			|| ( $post_id && has_post_thumbnail( $post_id ) )
			|| ( $post_id && OpenGraph::first_content_image( $post_id ) );
		$mark( 'social_image', $has_social ? 'pass' : 'warn' );

		// First paragraph answers.
		$mark( 'first_paragraph', $in_first ? 'pass' : 'warn' );

		// FAQ.
		$faq = $post_id ? (array) get_post_meta( $post_id, '_hs_faq', true ) : array();
		$mark( 'faq_present', $faq ? 'pass' : 'notice' );

		// Keyword duplication.
		$dupes = $post_id && $primary ? self::keyword_conflicts( $primary, $post_id ) : array();
		$mark( 'keyword_duplication', $dupes ? 'warn' : 'pass', array( 'conflicts' => $dupes ) );

		// Structured data for this post (auto module or a manual block).
		$schema_present = $post_id
			? ( (array) get_post_meta( $post_id, '_hs_schema', true ) || (array) get_post_meta( $post_id, '_hs_faq', true ) || Schema::auto_enabled( $post_id ) )
			: false;
		$mark( 'schema', $schema_present ? 'pass' : 'warn' );

		// Score.
		$seo_weight = 0;
		$seo_got    = 0;
		$read_weight = 0;
		$read_got   = 0;
		foreach ( $issues as $issue ) {
			$weight = isset( $weights[ $issue['id'] ] ) ? (float) $weights[ $issue['id'] ] : 4;
			$is_read = in_array( $issue['group'], array( 'readability' ), true );
			if ( $is_read ) {
				$read_weight += $weight;
				if ( 'pass' === $issue['status'] ) {
					$read_got += $weight;
				} elseif ( 'warn' === $issue['status'] ) {
					$read_got += $weight * 0.5;
				}
			} else {
				$seo_weight += $weight;
				if ( 'pass' === $issue['status'] ) {
					$seo_got += $weight;
				} elseif ( 'warn' === $issue['status'] ) {
					$seo_got += $weight * 0.5;
				}
			}
			$got[ $issue['id'] ] = $issue['status'];
		}

		$seo         = $seo_weight ? round( $seo_got / $seo_weight * 100, 1 ) : 0;
		$readability = $read_weight ? round( $read_got / $read_weight * 100, 1 ) : 0;
		$w_seo       = (float) $settings->get( 'content.scores.seo', 60 );
		$w_read      = (float) $settings->get( 'content.scores.readability', 40 );
		$total       = round( ( $seo * $w_seo + $readability * $w_read ) / max( 1, ( $w_seo + $w_read ) ), 1 );

		// A missing focus keyword caps the score: no keyword, no claim.
		if ( ! $primary ) {
			$total = min( $total, 55 );
		}

		usort(
			$issues,
			function ( $a, $b ) {
				$order = array( 'fail' => 0, 'warn' => 1, 'notice' => 2, 'pass' => 3 );
				$ra    = isset( $order[ $a['status'] ] ) ? $order[ $a['status'] ] : 2;
				$rb    = isset( $order[ $b['status'] ] ) ? $order[ $b['status'] ] : 2;
				return $ra <=> $rb;
			}
		);

		$suggestions = self::suggest( $issues, $primary, $title, $desc, $words, $post_id );

		return array(
			'score'       => $total,
			'seo_score'   => $seo,
			'readability' => $readability,
			'grade'       => Helpers::grade( $total ),
			'words'       => (int) $words,
			'reading_time' => max( 1, (int) ceil( $words / 220 ) ),
			'issues'      => $issues,
			'suggestions' => $suggestions,
			'status'      => $got,
			'metrics'     => array(
				'title_chars'   => $tlen,
				'title_px'      => $tpx,
				'desc_chars'    => $dlen,
				'density'       => $density,
				'sentences'     => count( $sentences ),
				'avg_sentence'  => $avg,
				'long_sentences' => $long,
				'headings'      => count( $heads ),
				'internal'      => (int) $links['internal'],
				'external'      => (int) $links['external'],
				'images'        => (int) $images['total'],
				'images_missing' => (int) $images['missing'],
				'transitions'   => (int) $transitions,
				'flow'          => (int) $flow,
				'url'           => $full,
			),
			'checked_at'  => time(),
		);
	}

	/**
	 * Empty result shape.
	 *
	 * @return array
	 */
	protected static function empty_result() {
		return array(
			'score' => 0, 'seo_score' => 0, 'readability' => 0, 'grade' => '—',
			'words' => 0, 'issues' => array(), 'suggestions' => array(), 'metrics' => array(),
		);
	}

	/**
	 * Is the keyword in the first ~120 words?
	 *
	 * @param string $normalized Text.
	 * @param string $keyword    Normalised keyword.
	 * @return bool
	 */
	protected static function in_first_paragraph( $normalized, $keyword ) {
		$head = implode( ' ', array_slice( preg_split( '/\s+/u', (string) $normalized ), 0, 120 ) );
		return '' !== $keyword && false !== strpos( (string) $head, (string) $keyword );
	}

	/**
	 * Classify anchors in the content.
	 *
	 * @param string $content HTML.
	 * @param int    $post_id Post ID.
	 * @return array
	 */
	protected static function analyse_links( $content, $post_id = 0 ) {
		preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', (string) $content, $links, PREG_SET_ORDER );
		$internal = 0;
		$external = 0;
		$unmanaged = 0;
		$home     = wp_parse_url( home_url(), PHP_URL_HOST );
		$targets  = array();

		foreach ( $links as $link ) {
			$href = (string) $link[1];
			if ( 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) || 0 === strpos( $href, 'javascript:' ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			$is_internal = ! $host || $host === $home;
			if ( $is_internal ) {
				$internal++;
				$targets[] = $href;
			} else {
				$external++;
				$rel = isset( $link[0] ) && preg_match( '/rel=["\']([^"\']*)["\']/i', $link[0], $r ) ? $r[1] : '';
				if ( ! $rel ) {
					$unmanaged++;
				}
			}
		}

		unset( $post_id );

		return array(
			'internal'  => $internal,
			'external'  => $external,
			'unmanaged' => $unmanaged,
			'targets'   => array_slice( array_unique( $targets ), 0, 10 ),
		);
	}

	/**
	 * Image inventory.
	 *
	 * @param string $content HTML.
	 * @param int    $post_id Post ID.
	 * @return array
	 */
	protected static function analyse_images( $content, $post_id = 0 ) {
		preg_match_all( '/<img\b[^>]*>/i', (string) $content, $imgs );
		$total   = 0;
		$missing = 0;
		foreach ( (array) $imgs[0] as $tag ) {
			if ( preg_match( '/data:image/i', $tag ) ) {
				continue;
			}
			$total++;
			if ( ! preg_match( '/\balt=["\'][^"\']+["\']/i', $tag ) ) {
				$missing++;
			}
		}
		if ( $post_id && has_post_thumbnail( $post_id ) ) {
			$total++;
			if ( ! trim( (string) get_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', true ) ) ) {
				$missing++;
			}
		}
		return array( 'total' => $total, 'missing' => $missing );
	}

	/**
	 * Longest run of words without a heading.
	 *
	 * @param string $content HTML.
	 * @return int
	 */
	protected static function longest_heading_gap( $content ) {
		$parts = preg_split( '/<(?:h[1-6]|\/h[1-6])[^>]*>/i', (string) $content );
		$max   = 0;
		foreach ( (array) $parts as $part ) {
			$max = max( $max, Helpers::word_count( $part ) );
		}
		return $max;
	}

	/**
	 * Heading order sanity.
	 *
	 * @param string $content HTML.
	 * @return array
	 */
	protected static function heading_structure( $content ) {
		preg_match_all( '/<h([1-6])/i', (string) $content, $m );
		$levels = array_map( 'intval', (array) $m[1] );
		$problems = array();
		if ( ! $levels ) {
			return array( 'ok' => false, 'problems' => array( __( 'هیچ تیتری وجود ندارد.', 'hoosh-seo' ) ), 'levels' => $levels );
		}
		$prev = 0;
		foreach ( $levels as $level ) {
			if ( $prev && $level > $prev + 1 ) {
				$problems[] = sprintf( /* translators: 1: level */ __( 'پرش به H%d بدون تیتر میانی.', 'hoosh-seo' ), $level );
			}
			$prev = $level;
		}
		if ( ! in_array( 1, $levels, true ) && ! is_singular( 'page' ) ) {
			$problems[] = __( 'تگ H1 در محتوا نیست (ممکن است قالب آن را چاپ کند).', 'hoosh-seo' );
		}
		if ( count( array_keys( $levels, 1, true ) ) > 1 ) {
			$problems[] = __( 'بیش از یک H1 در محتوا وجود دارد.', 'hoosh-seo' );
		}
		return array( 'ok' => ! $problems, 'problems' => array_slice( $problems, 0, 3 ), 'levels' => array_slice( $levels, 0, 12 ) );
	}

	/**
	 * A readability index adapted for Persian: sentence + word length driven.
	 *
	 * @param array $sentences Sentences.
	 * @param int   $words     Total words.
	 * @param array $freq      Frequency map.
	 * @return int 0-100
	 */
	protected static function readability_index( $sentences, $words, $freq ) {
		if ( ! $words || ! $sentences ) {
			return 0;
		}
		$syllables = 0;
		$complex   = 0;
		foreach ( array_keys( $freq ) as $term) {
			$len = Helpers::strlen( (string) $term );
			$syllables += $len;
			if ( $len >= 9 ) {
				$complex++;
			}
		}
		$avg_sentence = $words / max( 1, count( $sentences ) );
		$complex_ratio = $complex / max( 1, count( $freq ) );

		$score = 100;
		$score -= max( 0, ( $avg_sentence - 17 ) * 2.5 );
		$score -= $complex_ratio * 60;
		$score -= max( 0, ( $words / max( 1, count( $sentences ) ) - 25 ) );
		$score  = Helpers::clamp( $score, 0, 100 );

		return (int) round( $score );
	}

	/**
	 * Turn failing checks into actionable, ordered suggestions.
	 *
	 * @param array  $issues   Issues.
	 * @param string $keyword  Primary keyword.
	 * @param string $title    Title.
	 * @param string $desc     Description.
	 * @param int    $words    Words.
	 * @param int    $post_id  Post ID.
	 * @return array
	 */
	protected static function suggest( $issues, $keyword, $title, $desc, $words, $post_id ) {
		$out = array();
		foreach ( $issues as $issue ) {
			if ( in_array( $issue['status'], array( 'pass' ), true ) ) {
				continue;
			}
			$rank     = array( 'critical' => 90, 'error' => 80, 'warning' => 60, 'notice' => 35, 'ok' => 10 );
			$severity = isset( $issue['severity'] ) ? (string) $issue['severity'] : 'notice';
			$priority = isset( $rank[ $severity ] ) ? $rank[ $severity ] : 30;
			$out[] = array(
				'id'       => $issue['id'],
				'label'    => $issue['label'],
				'severity' => $issue['severity'],
				'how'      => $issue['how'],
				'fix'      => $issue['fix'],
				'priority' => (int) $priority,
				'keyword'  => $keyword,
				'post_id'  => $post_id,
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['priority'] <=> $a['priority'];
			}
		);
		unset( $words, $title, $desc );
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Posts using the same focus keyword (cannibalisation detection).
	 *
	 * @param string $keyword Keyword.
	 * @param int    $exclude Excluded post id.
	 * @return array
	 */
	public static function keyword_conflicts( $keyword, $exclude = 0 ) {
		return (array) Helpers::cache(
			'kw-conflict-' . md5( Helpers::normalize_fa( $keyword ) ),
			function () use ( $keyword, $exclude ) {
				global $wpdb;
				$norm  = Helpers::normalize_fa( $keyword );
				$like  = '%' . $wpdb->esc_like( $keyword ) . '%';
				$rows  = $wpdb->get_results( // phpcs:ignore
					$wpdb->prepare(
						"SELECT pm.post_id, p.post_title, p.post_type, p.post_status
						 FROM {$wpdb->postmeta} pm
						 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						 WHERE pm.meta_key = '_hs_keywords' AND pm.meta_value LIKE %s AND p.ID <> %d
						 ORDER BY p.post_modified DESC LIMIT 6",
						$like,
						$exclude
					),
					ARRAY_A
				);
				$out = array();
				foreach ( (array) $rows as $row ) {
					$out[] = array(
						'post_id' => (int) $row['post_id'],
						'title'   => $row['post_title'],
						'type'    => $row['post_type'],
						'status'  => $row['post_status'],
						'url'     => get_permalink( (int) $row['post_id'] ),
						'same'    => true,
						'norm'    => $norm,
					);
				}
				return $out;
			},
			900
		);
	}

	/**
	 * Bulk scan queue handling.
	 */
	public static function drain() {
		$state = (array) get_option( 'hoosh_content_queue', array() );
		if ( empty( $state['queue'] ) ) {
			return;
		}
		$per = (int) \hoosh_seo()->settings->get( 'advanced.indexing_batch', 100 );
		$ids = array_slice( (array) $state['queue'], 0, $per );
		foreach ( $ids as $id ) {
			self::store( (int) $id );
		}
		$rest = array_slice( (array) $state['queue'], $per );
		update_option( 'hoosh_content_queue', array( 'queue' => $rest, 'total' => (int) $state['total'], 'done' => (int) ( $state['done'] + count( $ids ) ), 'last' => time() ), false );
	}

	/**
	 * Start a bulk scan of the site.
	 *
	 * @param array $args Filters (type, limit, force).
	 * @return array
	 */
	public static function start_scan( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'  => '',
				'limit' => 0,
				'force' => false,
				'status' => 'publish',
			)
		);

		$query = new \WP_Query(
			array(
				'post_type'      => $args['type'] ? $args['type'] : Helpers::managed_post_types(),
				'post_status'    => $args['status'],
				'posts_per_page' => $args['limit'] ? (int) $args['limit'] : -1,
				'fields'         => 'ids',
				'no_found_rows'  => ! $args['limit'],
			)
		);

		$queue = array_map( 'intval', (array) $query->posts );
		update_option(
			'hoosh_content_queue',
			array( 'queue' => $queue, 'total' => count( $queue ), 'done' => 0, 'started' => time(), 'last' => time() ),
			false
		);

		// Process synchronously in bounded slices so the UI can poll progress.
		self::drain();

		return self::scan_status();
	}

	/**
	 * Scan progress.
	 *
	 * @return array
	 */
	public static function scan_status() {
		$state = (array) get_option( 'hoosh_content_queue', array() );
		$total = isset( $state['total'] ) ? (int) $state['total'] : 0;
		$done  = isset( $state['done'] ) ? (int) $state['done'] : 0;
		$left  = isset( $state['queue'] ) ? count( (array) $state['queue'] ) : 0;
		return array(
			'running' => $left > 0,
			'total'   => $total,
			'done'    => $total ? $total - $left : $done,
			'remaining' => $left,
			'percent' => $total ? (int) round( ( ( $total - $left ) / max( 1, $total ) ) * 100 ) : ( $done ? 100 : 0 ),
			'last'    => isset( $state['last'] ) ? Helpers::time_ago_fa( (int) $state['last'] ) : '',
		);
	}

	/**
	 * Paginated, filterable page report for the Studio table.
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function pages( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'type'   => '',
				'search' => '',
				'filter' => 'all', // all|low|nokeyword|missing-meta|thin|orphan.
				'orderby' => 'score',
				'order'   => 'ASC',
				'per'     => 30,
				'paged'   => 1,
			)
		);

		$table  = Database::table( 'pages' );
		$where  = '1=1';
		$params = array();

		if ( '' !== $args['type'] ) {
			$where   .= ' AND type = %s';
			$params[] = $args['type'];
		}
		if ( '' !== $args['search'] ) {
			$where   .= ' AND (title LIKE %s OR url LIKE %s OR keywords LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		switch ( $args['filter'] ) {
			case 'low':
				$where .= ' AND score < 70';
				break;
			case 'critical':
				$where .= " AND issues LIKE %s";
				$params[] = '%"critical"%';
				break;
			case 'thin':
				$where .= ' AND word_count < 400';
				break;
			case 'orphan':
				$where .= ' AND links_in = 0';
				break;
			case 'missing-meta':
				$where .= " AND (description = '' OR description IS NULL)";
				break;
			case 'nokeyword':
				$where .= " AND (keywords IS NULL OR keywords = '[]' OR keywords = '')";
				break;
		}

		$orderby = in_array( $args['orderby'], array( 'score', 'word_count', 'title', 'scanned_at', 'links_in', 'links_out' ), true ) ? $args['orderby'] : 'score';
		$order   = 'DESC' === strtoupper( (string) $args['order'] ) ? 'DESC' : 'ASC';
		$per     = max( 5, min( 200, (int) $args['per'] ) );
		$offset  = ( max( 1, (int) $args['paged'] ) - 1 ) * $per;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		$rows_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT {$per} OFFSET {$offset}";
		$rows     = (array) ( $params ? $wpdb->get_results( $wpdb->prepare( $rows_sql, $params ), ARRAY_A ) : $wpdb->get_results( $rows_sql, ARRAY_A ) ); // phpcs:ignore

		foreach ( $rows as &$row ) {
			$row['issues']     = (array) json_decode( (string) $row['issues'], true );
			$row['keywords']   = (array) json_decode( (string) $row['keywords'], true );
			$row['grade']      = Helpers::grade( (float) $row['score'] );
			$row['critical']   = (int) count( array_filter( $row['issues'], function ( $i ) {
				return isset( $i['severity'] ) && in_array( $i['severity'], array( 'critical', 'error' ), true );
			} ) );
			$row['scanned_ago'] = $row['scanned_at'] ? Helpers::time_ago_fa( strtotime( (string) $row['scanned_at'] . ' UTC' ) ) : '';
		}
		unset( $row );

		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
			'distribution' => self::distribution(),
		);
	}

	/**
	 * Score distribution buckets for the dashboard chart.
	 *
	 * @return array
	 */
	public static function distribution() {
		global $wpdb;
		if ( ! Database::exists( 'pages' ) ) {
			return array();
		}
		$table = Database::table( 'pages' );
		$rows  = (array) $wpdb->get_results( "SELECT FLOOR(score/10)*10 bucket, COUNT(*) n FROM {$table} GROUP BY bucket ORDER BY bucket", ARRAY_A ); // phpcs:ignore
		$map   = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row['bucket'] ] = (int) $row['n'];
		}
		$out = array();
		for ( $b = 0; $b <= 90; $b += 10 ) {
			$out[] = array(
				'label' => Helpers::number( $b ) . '–' . Helpers::number( $b + 9 ),
				'value' => isset( $map[ $b ] ) ? $map[ $b ] : 0,
				'bucket' => $b,
			);
		}
		return $out;
	}

	/**
	 * Per-check failure counts (what to fix first, site-wide).
	 *
	 * @param int $limit Items.
	 * @return array
	 */
	public static function top_issues( $limit = 8 ) {
		global $wpdb;
		if ( ! Database::exists( 'pages' ) ) {
			return array();
		}
		$table = Database::table( 'pages' );
		$rows  = (array) $wpdb->get_results( "SELECT issues FROM {$table} ORDER BY score ASC LIMIT 400", ARRAY_A ); // phpcs:ignore
		$counts = array();
		$catalog = self::check_catalog();
		foreach ( $rows as $row ) {
			foreach ( (array) json_decode( (string) $row['issues'], true ) as $issue ) {
				if ( empty( $issue['id'] ) || 'pass' === ( $issue['status'] ?? '' ) ) {
					continue;
				}
				$key = $issue['id'];
				if ( ! isset( $counts[ $key ] ) ) {
					$counts[ $key ] = array(
						'id'       => $key,
						'label'    => isset( $catalog[ $key ] ) ? $catalog[ $key ]['label'] : $key,
						'severity' => $issue['severity'] ?? 'notice',
						'count'    => 0,
						'how'      => isset( $catalog[ $key ] ) ? $catalog[ $key ]['how'] : '',
						'fix'      => isset( $catalog[ $key ] ) ? $catalog[ $key ]['fix'] : 'manual',
					);
				}
				$counts[ $key ]['count']++;
			}
		}
		usort(
			$counts,
			function ( $a, $b ) {
				$rank = array( 'critical' => 3, 'error' => 3, 'warning' => 2, 'notice' => 1, 'ok' => 0 );
				$wa   = ( $rank[ $a['severity'] ] ?? 1 ) * 1000 + $a['count'];
				$wb   = ( $rank[ $b['severity'] ] ?? 1 ) * 1000 + $b['count'];
				return $wb <=> $wa;
			}
		);
		return array_slice( $counts, 0, $limit );
	}

	/**
	 * AI-prompted rewrite brief for a failing check (returns the task spec).
	 *
	 * @param string $check_id Check id.
	 * @param int    $post_id  Post ID.
	 * @return array
	 */
	public static function fix_task( $check_id, $post_id ) {
		$catalog = self::check_catalog();
		if ( ! isset( $catalog[ $check_id ] ) ) {
			return array();
		}
		return array(
			'check'   => $check_id,
			'label'   => $catalog[ $check_id ]['label'],
			'how'     => $catalog[ $check_id ]['how'],
			'mode'    => $catalog[ $check_id ]['fix'],
			'post_id' => (int) $post_id,
			'payload' => array(
				'title'   => Meta::resolve( $post_id, 'title' ),
				'desc'    => Meta::resolve( $post_id, 'description' ),
				'keywords' => Meta::focus_keywords( $post_id ),
			),
		);
	}

	/**
	 * Deterministic fixes (no AI): things we can safely do ourselves.
	 *
	 * @param string $check_id Check id.
	 * @param int    $post_id  Post ID.
	 * @return array
	 */
	public static function apply_fix( $check_id, $post_id ) {
		$post_id = (int) $post_id;
		$check   = sanitize_key( (string) $check_id );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array( 'ok' => false, 'message' => __( 'نوشته پیدا نشد.', 'hoosh-seo' ) );
		}

		$before = (string) $post->post_content;

		switch ( $check ) {
			case 'images_alt':
				$filled = Images::maybe_fill_alt( $post_id );
				$content = Images::fill_content_alt( $before, $post_id );
				if ( $content !== $before ) {
					wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content ) ) );
					$filled++;
				}
				return array(
					'ok'      => (bool) $filled,
					'changed' => $filled,
					'message' => $filled
						? sprintf( /* translators: %d count */ __( 'برای %d تصویر متن جایگزین نوشته شد.', 'hoosh-seo' ), $filled )
						: __( 'تصویر بدون alt نبود.', 'hoosh-seo' ),
				);

			case 'internal_links':
				$suggestions = Links::suggest( $post_id, 5 );
				$rows        = (array) ( $suggestions['rows'] ?? array() );
				$rows        = array_values( array_filter( $rows, 'is_array' ) );
				if ( ! $rows ) {
					return array( 'ok' => false, 'message' => __( 'پیشنهاد لینکی پیدا نشد.', 'hoosh-seo' ) );
				}
				$links = array();
				foreach ( array_slice( $rows, 0, 3 ) as $row ) {
					if ( empty( $row['target']['id'] ) || empty( $row['phrase'] ) ) {
						continue;
					}
					$links[] = array(
						'rule_id'  => 0,
						'post_id'  => (int) $row['target']['id'],
						'anchor'   => (string) $row['phrase'],
						'url'      => (string) ( $row['target']['url'] ?? '' ),
						'keyword'  => (string) $row['phrase'],
					);
				}
				if ( ! $links ) {
					return array( 'ok' => false, 'message' => __( 'چیزی برای درج نبود.', 'hoosh-seo' ) );
				}
				$result = Links::insert_into_content( $post_id, $links );
				$result['links'] = $links;
				return $result;

			case 'outbound_nofollow':
				$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
				$content = preg_replace_callback(
					'/<a\b[^>]*>/i',
					function ( $m ) use ( $host ) {
						$tag = $m[0];
						if ( ! preg_match( '/href\s*=\s*["\']https?:\/\//i', $tag ) ) {
							return $tag;
						}
						if ( $host && preg_match( '#href\s*=\s*["\']https?://(?:www\.)?' . preg_quote( $host, '#' ) . '/#i', $tag ) ) {
							return $tag;
						}
						if ( preg_match( '/\srel\s*=\s*(["\'])([^"\']*)\1/i', $tag, $r ) ) {
							if ( preg_match( '/nofollow|sponsored|ugc/i', $r[2] ) ) {
								return $tag;
							}
							$rel = trim( $r[2] ) . ' nofollow';
							return str_replace( $r[0], ' rel="' . $r[1] . trim( $r[2] ) . ' nofollow' . $r[1] . '"', $tag );
						}
						return rtrim( $tag, '>' ) . ' rel="nofollow ugc">';
					},
					$before
				);
				if ( trim( (string) $content ) === trim( $before ) ) {
					return array( 'ok' => false, 'message' => __( 'لینک بیرونی بدون rel پیدا نشد.', 'hoosh-seo' ) );
				}
				wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content ) ) );
				return array( 'ok' => true, 'message' => __( 'به لینک‌های بیرونی rel اضافه شد.', 'hoosh-seo' ), 'changed' => 1 );

			case 'schema':
				$force = 'product' === $post->post_type ? array( 'Product' ) : array( 'Article' );
				$force[] = 'BreadcrumbList';
				update_post_meta( $post_id, '_hs_schema_types', $force );
				\HooshSEO\Helpers::cache_flush_all();
				return array( 'ok' => true, 'message' => __( 'اسکیما برای این صفحه روشن شد.', 'hoosh-seo' ), 'changed' => 1 );

			case 'social_image':
				$image = OpenGraph::first_content_image( $post_id );
				if ( ! $image ) {
					return array( 'ok' => false, 'message' => __( 'تصویری در محتوا نبود که شاخص شود.', 'hoosh-seo' ) );
				}
				$social = (array) get_post_meta( $post_id, '_hs_social', true );
				$social['image_id'] = (int) $image;
				update_post_meta( $post_id, '_hs_social', $social );
				return array( 'ok' => true, 'message' => __( 'نخستین تصویر محتوا به‌عنوان تصویر اجتماعی انتخاب شد.', 'hoosh-seo' ), 'changed' => 1 );

			case 'keyword_duplication':
				$conflicts = self::keyword_conflicts( (string) ( Meta::focus_keywords( $post_id )[0] ?? '' ), $post_id );
				return array(
					'ok'      => true,
					'review'  => true,
					'rows'    => $conflicts,
					'message' => $conflicts
						? __( 'صفحه‌های درگیر را ببینید؛ ادغام یا تغییر کلمه کلیدی را خودتان انتخاب کنید.', 'hoosh-seo' )
						: __( 'تکراری پیدا نشد.', 'hoosh-seo' ),
				);
		}

		return array(
			'ok'      => false,
			'manual'  => true,
			'message' => __( 'این مورد اصلاح خودکار ندارد؛ راهنمای دستی را ببینید.', 'hoosh-seo' ),
		);
	}


	/**
	 * Batch entry point used by automation (alias of start_scan).
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function scan( $args = array() ) {
		$status = self::start_scan( (array) $args );
		return array_merge(
			(array) $status,
			array(
				'ok'        => true,
				'scanned'   => (int) ( $status['done'] ?? 0 ),
				'updated'   => (int) ( $status['done'] ?? 0 ),
				'progress'  => (array) $status,
				'message'   => ! empty( $status['running'] )
					? sprintf( /* translators: 1: done, 2: total */ __( 'ممیزی در جریان است: %1$s از %2$s.', 'hoosh-seo' ), (int) $status['done'], (int) $status['total'] )
					: sprintf( /* translators: %d pages */ __( 'ممیزی %d صفحه تمام شد.', 'hoosh-seo' ), (int) ( $status['done'] ?? 0 ) ),
			)
		);
	}

}
