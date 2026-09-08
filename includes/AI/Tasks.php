<?php
/**
 * AI tasks: prompt templates, output contracts and appliers.
 *
 * @package HooshSEO
 */

namespace HooshSEO\AI;

use HooshSEO\Helpers;
use HooshSEO\Meta;
use HooshSEO\Modules\Audit;
use HooshSEO\Modules\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tasks
 */
final class Tasks {

	/**
	 * Task catalog. Every entry is a job the Studio can run.
	 *
	 * Keys: label, group, desc, needs, inputs, outputs, apply, temp, tokens, checks.
	 *
	 * @return array
	 */
	public static function catalog() {
		return array(
			'titles'       => array(
				'label'   => __( 'عنوان سئو', 'hoosh-seo' ),
				'group'   => 'متا',
				'desc'    => __( 'پنج عنوان جایگزین با طول مناسب و کلمه کلیدی.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'titles' => 'array<string>' ),
				'apply'   => array( 'title' ),
				'temp'    => 0.8,
				'tokens'  => 500,
				'checks'  => array( 'title_length', 'keyword_title', 'focus_present' ),
				'prompt'  => "Write 5 SEO title options for the content below.\nRules: max 60 chars, start with the focus keyword when natural, no clickbait, Persian, use half-space.\nReturn JSON: {\"titles\":[\"...\"]}",
			),
			'description'  => array(
				'label'   => __( 'توضیحات متا', 'hoosh-seo' ),
				'group'   => 'متا',
				'desc'    => __( 'توضیح ۱۵۰ کاراکتری با دعوت به کلیک.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'descriptions' => 'array<string>' ),
				'apply'   => array( 'description' ),
				'temp'    => 0.7,
				'tokens'  => 500,
				'checks'  => array( 'desc_length', 'keyword_desc' ),
				'prompt'  => "Write 3 meta descriptions (120-155 chars each) for the content below.\nInclude the focus keyword once, end with a soft call to action, no quotes.\nReturn JSON: {\"descriptions\":[\"...\"]}",
			),
			'keywords'     => array(
				'label'   => __( 'کلمه کلیدی', 'hoosh-seo' ),
				'group'   => 'متا',
				'desc'    => __( 'پیشنهاد کلمه اصلی و واژه‌های پشتیبان از متن.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'primary' => 'string', 'secondary' => 'array<string>', 'why' => 'string' ),
				'apply'   => array( 'keywords' ),
				'temp'    => 0.3,
				'tokens'  => 400,
				'checks'  => array( 'focus_present', 'keyword_density' ),
				'prompt'  => "Pick the single best focus keyword and up to 4 supporting keywords for the content below, in Persian.\nReturn JSON: {\"primary\":\"...\",\"secondary\":[\"...\"],\"why\":\"یک جمله\"}",
			),
			'outline'      => array(
				'label'   => __( 'ساختار و تیترها', 'hoosh-seo' ),
				'group'   => 'محتوا',
				'desc'    => __( 'نقشه راه H2/H3 برای پوشش کامل موضوع.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'headings' => 'array<{h2:string,h3:array<string>}>' ),
				'apply'   => array(),
				'temp'    => 0.6,
				'tokens'  => 800,
				'checks'  => array( 'keyword_headings', 'subheading_density', 'heading_structure' ),
				'prompt'  => "Propose an article outline in Persian for the topic below: 5-8 H2 headings, each with 0-3 H3 sub-headings.\nEvery H2 must be a real question or promise, not a filler word.\nReturn JSON: {\"headings\":[{\"h2\":\"...\",\"h3\":[\"...\"]}]}",
			),
			'expand'       => array(
				'label'   => __( 'بازکردن متن', 'hoosh-seo' ),
				'group'   => 'محتوا',
				'desc'    => __( 'افزودن بخش‌های لازم تا رسیدن به طول مطلوب.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'content' => 'html', 'added' => 'array<string>' ),
				'apply'   => array( 'content' ),
				'temp'    => 0.6,
				'tokens'  => 3000,
				'checks'  => array( 'content_length', 'first_paragraph', 'keyword_start' ),
				'prompt'  => "Expand the article below to about TARGET words while keeping the existing text and voice.\nAdd only sections that a reader of this topic actually needs. Persian, HTML with h2/h3/p/ul.\nReturn JSON: {\"content\":\"...\",\"added\":[\"عنوان بخش‌های اضافه‌شده\"]}",
			),
			'rewrite'      => array(
				'label'   => __( 'بازنویسی روان', 'hoosh-seo' ),
				'group'   => 'محتوا',
				'desc'    => __( 'جمله‌های کوتاه‌تر، فعل فعال، واژه ساده‌تر.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'content' => 'html', 'changes' => 'array<string>' ),
				'apply'   => array( 'content' ),
				'temp'    => 0.5,
				'tokens'  => 3000,
				'checks'  => array( 'passive_voice', 'sentence_length', 'transition_words', 'word_repetition', 'simple_words', 'readability_flow' ),
				'prompt'  => "Rewrite the text below in smoother Persian: sentences under 20 words, active voice, add transition words, replace hard words with simple ones. Keep meaning, links and headings.\nReturn JSON: {\"content\":\"...\",\"changes\":[\"what changed\"]}",
			),
			'alt'          => array(
				'label'   => __( 'متن جایگزین تصویر', 'hoosh-seo' ),
				'group'   => 'تصویر',
				'desc'    => __( 'توصیف واقعی تصویر برای alt.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'alts' => 'array<{file:string,alt:string}>' ),
				'apply'   => array( 'alt' ),
				'temp'    => 0.3,
				'tokens'  => 900,
				'checks'  => array( 'images_alt' ),
				'prompt'  => "Write alt text for each image listed below. Describe what is actually visible in 3-10 Persian words; never use the words 'تصویر' or 'image of'.\nReturn JSON: {\"alts\":[{\"file\":\"...\",\"alt\":\"...\"}]}",
			),
			'vision'       => array(
				'label'   => __( 'توصیف تصویر با بینایی', 'hoosh-seo' ),
				'group'   => 'تصویر',
				'desc'    => __( 'وقتی اسم فایل چیزی نمی‌گوید، خود تصویر خوانده می‌شود.', 'hoosh-seo' ),
				'needs'   => array( 'attachment_id' ),
				'outputs' => array( 'alt' => 'string', 'caption' => 'string' ),
				'apply'   => array( 'alt' ),
				'temp'    => 0.3,
				'tokens'  => 300,
				'vision'  => true,
				'checks'  => array( 'images_alt' ),
				'prompt'  => "Describe this image for an alt attribute: 4-12 Persian words, literal, no opinion.\nReturn JSON: {\"alt\":\"...\",\"caption\":\"...\"}",
			),
			'faq'          => array(
				'label'   => __( 'پرسش‌های متداول', 'hoosh-seo' ),
				'group'   => 'محتوا',
				'desc'    => __( 'پرسش‌هایی که واقعاً جستجو می‌شوند.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'faq' => 'array<{question:string,answer:string}>' ),
				'apply'   => array( 'faq' ),
				'temp'    => 0.5,
				'tokens'  => 1400,
				'checks'  => array( 'faq_present' ),
				'prompt'  => "Write 5 FAQs people really ask about this topic, answerable from the content. Answers 30-70 words, plain Persian, no fluff.\nReturn JSON: {\"faq\":[{\"question\":\"...\",\"answer\":\"...\"}]}",
			),
			'schema'       => array(
				'label'   => __( 'داده ساختاریافته', 'hoosh-seo' ),
				'group'   => 'اسکیما',
				'desc'    => __( 'بلوک JSON-LD آماده برای این صفحه.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'blocks' => 'array<schema.org node>' ),
				'apply'   => array( 'schema' ),
				'temp'    => 0.2,
				'tokens'  => 1200,
				'checks'  => array( 'schema' ),
				'prompt'  => "Suggest schema.org JSON-LD nodes for the page below using only facts present in the text (never invent prices, ratings or dates).\nReturn JSON: {\"blocks\":[{\"@type\":\"FAQPage\",\"mainEntity\":[...]}]}",
			),
			'slug'         => array(
				'label'   => __( 'نامک', 'hoosh-seo' ),
				'group'   => 'متا',
				'desc'    => __( 'نامک کوتاه و خوانا، فارسی یا لاتین.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'slugs' => 'array<{slug:string,why:string}>' ),
				'apply'   => array( 'slug' ),
				'temp'    => 0.4,
				'tokens'  => 400,
				'checks'  => array( 'keyword_url', 'url_length' ),
				'prompt'  => "Suggest 3 short URL slugs for this page. Prefer Latin transliteration of the Persian words, 2-5 tokens, no stop-words, no dates.\nReturn JSON: {\"slugs\":[{\"slug\":\"...\",\"why\":\"یک جمله\"}]}",
			),
			'internal'     => array(
				'label'   => __( 'پیشنهاد لینک داخلی', 'hoosh-seo' ),
				'group'   => 'لینک',
				'desc'    => __( 'لنگرهای دقیق برای صفحه‌های مرتبط سایت.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'links' => 'array<{anchor:string,url:string,reason:string}>' ),
				'apply'   => array( 'links' ),
				'temp'    => 0.3,
				'tokens'  => 900,
				'checks'  => array( 'internal_links' ),
				'prompt'  => "Given the article and the list of site URLs below, choose up to 5 internal links. Anchor must be a phrase that exists verbatim in the article.\nReturn JSON: {\"links\":[{\"anchor\":\"...\",\"url\":\"...\",\"reason\":\"...\"}]}",
			),
			'excerpt'      => array(
				'label'   => __( 'چکیده', 'hoosh-seo' ),
				'group'   => 'محتوا',
				'desc'    => __( 'چکیده‌ای که در فهرست‌ها دیده می‌شود.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'excerpt' => 'string' ),
				'apply'   => array( 'excerpt' ),
				'temp'    => 0.5,
				'tokens'  => 300,
				'checks'  => array(),
				'prompt'  => "Write a 35-word Persian excerpt for this post that makes the reader want to continue.\nReturn JSON: {\"excerpt\":\"...\"}",
			),
			'social'       => array(
				'label'   => __( 'متن شبکه اجتماعی', 'hoosh-seo' ),
				'group'   => 'شبکه اجتماعی',
				'desc'    => __( 'عنوان و توضیح برای اینستاگرام/توییتر/تلگرام.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'og_title' => 'string', 'og_description' => 'string', 'caption' => 'string', 'hashtags' => 'array<string>' ),
				'apply'   => array( 'social' ),
				'temp'    => 0.75,
				'tokens'  => 600,
				'checks'  => array( 'social_image' ),
				'prompt'  => "Write social sharing copy for this page in Persian: og title (max 70 chars), og description (max 200 chars), one short image caption, 3 hashtags.\nReturn JSON: {\"og_title\":\"...\",\"og_description\":\"...\",\"caption\":\"...\",\"hashtags\":[\"...\"]}",
			),
			'summary'      => array(
				'label'   => __( 'خلاصه صفحه', 'hoosh-seo' ),
				'group'   => 'محتوا',
				'desc'    => __( 'یک پاراگراف خلاصه برای فهرست‌ها و AI.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'summary' => 'string', 'key_points' => 'array<string>' ),
				'apply'   => array( 'summary' ),
				'temp'    => 0.3,
				'tokens'  => 600,
				'checks'  => array(),
				'prompt'  => "Summarize the article for a machine-readable answer box: 1 direct answer sentence, then 3-5 key points, Persian.\nReturn JSON: {\"summary\":\"...\",\"key_points\":[\"...\"]}",
			),
			'answer'       => array(
				'label'   => __( 'پاسخ مستقیم (GEO)', 'hoosh-seo' ),
				'group'   => 'AI و صوت',
				'desc'    => __( 'پاراگرافی که ChatGPT/Perplexity می‌تواند عینکاً نقل کند.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'answer' => 'string', 'sources' => 'array<string>' ),
				'apply'   => array( 'answer' ),
				'temp'    => 0.2,
				'tokens'  => 500,
				'checks'  => array(),
				'prompt'  => "Write a 40-70 word direct answer to the question this page targets. Self-contained, states the numbers/facts from the text, no 'در این مقاله' intro.\nReturn JSON: {\"answer\":\"...\",\"sources\":[\"...\"]}",
			),
			'speakable'    => array(
				'label'   => __( 'متن قابل‌تلفظ', 'hoosh-seo' ),
				'group'   => 'AI و صوت',
				'desc'    => __( 'بخش‌هایی که برای دستیار صوتی مناسب‌اند.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'selectors' => 'array<string>', 'intro' => 'string' ),
				'apply'   => array(),
				'temp'    => 0.3,
				'tokens'  => 500,
				'checks'  => array(),
				'prompt'  => "Which parts of this article should a voice assistant read aloud? Give CSS selectors of the paragraphs and a 25-word spoken intro.\nReturn JSON: {\"selectors\":[\"...\"],\"intro\":\"...\"}",
			),
			'product'      => array(
				'label'   => __( 'توضیح محصول', 'hoosh-seo' ),
				'group'   => 'فروشگاه',
				'desc'    => __( 'توضیح کوتاه + بلند با کلمات کلیدی.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'short' => 'string', 'long' => 'html', 'bullets' => 'array<string>' ),
				'apply'   => array( 'product' ),
				'temp'    => 0.55,
				'tokens'  => 1600,
				'checks'  => array(),
				'prompt'  => "Write WooCommerce product copy in Persian from the specs below: short description (max 30 words), 4 benefit bullets, long description HTML (150-250 words). Never invent specs.\nReturn JSON: {\"short\":\"...\",\"long\":\"...\",\"bullets\":[\"...\"]}",
			),
			'tone'         => array(
				'label'   => __( 'تن و لحن', 'hoosh-seo' ),
				'group'   => 'ویرایش',
				'desc'    => __( 'یک متن، سه لحن.', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'formal' => 'string', 'friendly' => 'string', 'sales' => 'string' ),
				'apply'   => array( 'content' ),
				'temp'    => 0.7,
				'tokens'  => 1600,
				'checks'  => array(),
				'prompt'  => "Rewrite the text below in three Persian tones: formal, friendly, sales. Same meaning, same length +/- 10%.\nReturn JSON: {\"formal\":\"...\",\"friendly\":\"...\",\"sales\":\"...\"}",
			),
			'translate'    => array(
				'label'   => __( 'ترجمه متا', 'hoosh-seo' ),
				'group'   => 'ویرایش',
				'desc'    => __( 'عنوان و توضیح برای نسخه انگلیسی (WPML/Polylang).', 'hoosh-seo' ),
				'needs'   => array( 'post_id' ),
				'outputs' => array( 'title' => 'string', 'description' => 'string', 'lang' => 'string' ),
				'apply'   => array(),
				'temp'    => 0.2,
				'tokens'  => 500,
				'checks'  => array(),
				'prompt'  => "Translate the SEO title and meta description below into English, keeping SEO length limits.\nReturn JSON: {\"title\":\"...\",\"description\":\"...\",\"lang\":\"en\"}",
			),
			'ideas'        => array(
				'label'   => __( 'ایده محتوایی', 'hoosh-seo' ),
				'group'   => 'کیورد',
				'desc'    => __( '۱۰ موضوع با فاصله محتوایی از صفحه‌های موجود.', 'hoosh-seo' ),
				'needs'   => array( 'seed' ),
				'outputs' => array( 'ideas' => 'array<{title:string,intent:string,angle:string}>' ),
				'apply'   => array(),
				'temp'    => 0.85,
				'tokens'  => 1200,
				'checks'  => array(),
				'prompt'  => "For the seed topic and the existing articles listed, propose 10 Persian article ideas that do NOT overlap the existing ones. Mark intent (informational/commercial/transactional/navigational).\nReturn JSON: {\"ideas\":[{\"title\":\"...\",\"intent\":\"...\",\"angle\":\"...\"}]}",
			),
			'research'     => array(
				'label'   => __( 'برآورد حجم جستجو', 'hoosh-seo' ),
				'group'   => 'کیورد',
				'desc'    => __( 'وقتی API حجم نداریم: برآورد حدودی + نیت.', 'hoosh-seo' ),
				'needs'   => array( 'keywords' ),
				'outputs' => array( 'items' => 'array<{keyword:string,volume:int,difficulty:int,intent:string,cpc:float}>' ),
				'apply'   => array(),
				'temp'    => 0.1,
				'tokens'  => 1600,
				'checks'  => array(),
				'prompt'  => "Estimate for the Iranian market: monthly search volume (integer, rough), difficulty 1-100, search intent, CPC in USD. Mark clearly low-confidence numbers with volume 0 if you truly do not know.\nReturn JSON: {\"items\":[{\"keyword\":\"...\",\"volume\":0,\"difficulty\":0,\"intent\":\"...\",\"cpc\":0}]}",
			),
			'report'       => array(
				'label'   => __( 'تحلیل گزارش', 'hoosh-seo' ),
				'group'   => 'گزارش',
				'desc'    => __( 'تفسیر انسانیِ اعداد این هفته.', 'hoosh-seo' ),
				'needs'   => array( 'snapshot' ),
				'outputs' => array( 'narrative' => 'string', 'actions' => 'array<string>' ),
				'apply'   => array(),
				'temp'    => 0.4,
				'tokens'  => 900,
				'checks'  => array(),
				'prompt'  => "You are reporting to a busy site owner. From the numbers below write a 90-word Persian summary, then 3 concrete actions ordered by impact.\nReturn JSON: {\"narrative\":\"...\",\"actions\":[\"...\"]}",
			),
			'bulk_meta'    => array(
				'label'   => __( 'پرکردن دسته‌جمعی متا', 'hoosh-seo' ),
				'group'   => 'متا',
				'desc'    => __( 'چند صفحه در یک درخواست: عنوان + توضیح.', 'hoosh-seo' ),
				'needs'   => array( 'posts' ),
				'outputs' => array( 'items' => 'array<{post_id:int,title:string,description:string}>' ),
				'apply'   => array( 'bulk' ),
				'temp'    => 0.4,
				'tokens'  => 2500,
				'checks'  => array( 'title_length', 'desc_length' ),
				'prompt'  => "For each post below write one SEO title (max 60 chars) and one meta description (120-155 chars) in Persian. Return the same post_id values you were given.\nReturn JSON: {\"items\":[{\"post_id\":0,\"title\":\"...\",\"description\":\"...\"}]}",
			),
		);
	}

	/**
	 * Human label for a task key.
	 *
	 * @param string $task Task.
	 * @return string
	 */
	public static function label( $task ) {
		$catalog = self::catalog();
		$task    = sanitize_key( (string) $task );
		return isset( $catalog[ $task ] ) ? (string) $catalog[ $task ]['label'] : ucwords( str_replace( '_', ' ', $task ) );
	}

	/**
	 * Tasks grouped for the Studio sidebar.
	 *
	 * @return array
	 */
	public static function grouped() {
		$out = array();
		foreach ( self::catalog() as $key => $task ) {
			$group = (string) $task['group'];
			if ( ! isset( $out[ $group ] ) ) {
				$out[ $group ] = array( 'label' => $group, 'tasks' => array() );
			}
			$out[ $group ]['tasks'][] = array(
				'id'    => $key,
				'label' => $task['label'],
				'desc'  => $task['desc'],
				'needs' => (array) $task['needs'],
				'apply' => (array) $task['apply'],
			);
		}
		return $out;
	}

	/**
	 * Which task can fix a given content check.
	 *
	 * @param string $check Check id.
	 * @return array
	 */
	public static function for_check( $check ) {
		$out = array();
		foreach ( self::catalog() as $key => $task ) {
			if ( in_array( $check, (array) $task['checks'], true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Run a task for a post (or ad-hoc payload).
	 *
	 * @param string $task    Task key.
	 * @param int    $post_id Post ID.
	 * @param array  $payload Extra inputs.
	 * @param bool   $dry     Do not write anything.
	 * @return array
	 */
	public static function run( $task, $post_id = 0, $payload = array(), $dry = false ) {
		$catalog = self::catalog();
		$task    = sanitize_key( (string) $task );
		if ( ! isset( $catalog[ $task ] ) ) {
			return array( 'ok' => false, 'error' => __( 'کار ناشناخته است.', 'hoosh-seo' ), 'message' => __( 'کار ناشناخته است.', 'hoosh-seo' ) );
		}
		$spec = $catalog[ $task ];

		$built = self::build_prompt( $task, $post_id, (array) $payload, $spec );
		if ( is_wp_error( $built ) ) {
			return array( 'ok' => false, 'error' => $built->get_error_message(), 'message' => $built->get_error_message() );
		}

		$result = Gateway::complete(
			array(
				'task'        => $task,
				'system'      => $built['system'],
				'prompt'      => $built['prompt'],
				'post_id'     => (int) $post_id,
				'json'        => true,
				'temperature' => isset( $spec['temp'] ) ? (float) $spec['temp'] : 0.5,
				'max_tokens'  => isset( $spec['tokens'] ) ? (int) $spec['tokens'] : 1000,
				'force'       => ! empty( $payload['force'] ),
				'job_id'      => isset( $payload['job_id'] ) ? (int) $payload['job_id'] : 0,
				'image'       => isset( $built['image'] ) ? (string) $built['image'] : '',
			)
		);

		if ( empty( $result['ok'] ) ) {
			return array(
				'ok'      => false,
				'error'   => (string) ( $result['error'] ?? '' ),
				'message' => (string) ( $result['error'] ?? __( 'خطا در ارتباط با سرویس.', 'hoosh-seo' ) ),
				'hint'    => (string) ( $result['hint'] ?? '' ),
				'task'    => $task,
			);
		}

		$data = is_array( $result['json'] ) ? $result['json'] : array( 'text' => (string) $result['text'] );
		$data = self::normalize_output( $task, $data );

		$out = array(
			'ok'      => true,
			'task'    => $task,
			'label'   => $spec['label'],
			'data'    => $data,
			'text'    => (string) $result['text'],
			'usage'   => (array) $result['usage'],
			'cost'    => (float) $result['cost'],
			'ms'      => (int) $result['ms'],
			'cached'  => (bool) $result['cached'],
			'driver'  => (string) $result['driver'],
			'model'   => (string) $result['model'],
			'apply'   => self::apply_targets( $task, $data, (int) $post_id, $dry ),
		);

		if ( ! $dry && empty( $payload['no_apply'] ) ) {
			$applied = self::apply( $task, (int) $post_id, $data );
			$out['applied'] = $applied;
			if ( $applied['count'] ) {
				$out['message'] = sprintf( /* translators: 1: task, 2: count */ __( '%1$s انجام و روی %2$d فیلد اعمال شد.', $spec['label'], $applied['count'] ) );
			} else {
				$out['message'] = __( 'خروجی آماده است؛ اعمال خودکار انجام نشد.', 'hoosh-seo' );
			}
		} else {
			$out['message'] = __( 'پیش‌نمایش آماده است؛ روی «اعمال» بزنید.', 'hoosh-seo' );
		}

		return $out;
	}

	/**
	 * Which fields a task would write.
	 *
	 * @param string $task Task.
	 * @param array  $data Data.
	 * @param int    $post_id Post ID.
	 * @param bool   $dry Dry run.
	 * @return array
	 */
	protected static function apply_targets( $task, $data, $post_id, $dry ) {
		$catalog = self::catalog();
		$fields  = isset( $catalog[ $task ]['apply'] ) ? (array) $catalog[ $task ]['apply'] : array();
		$out     = array();
		foreach ( $fields as $field ) {
			$out[] = array(
				'field'  => $field,
				'label'  => self::field_label( $field ),
				'has_data' => self::has_for( $field, $data ),
				'will_write' => ( ! $dry && $post_id ) && self::has_for( $field, $data ),
			);
		}
		return $out;
	}

	/**
	 * Field label map.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	public static function field_label( $field ) {
		$labels = array(
			'title'       => __( 'عنوان سئو', 'hoosh-seo' ),
			'description'  => __( 'توضیحات متا', 'hoosh-seo' ),
			'keywords'    => __( 'کلمات کلیدی', 'hoosh-seo' ),
			'content'     => __( 'محتوا', 'hoosh-seo' ),
			'excerpt'     => __( 'چکیده', 'hoosh-seo' ),
			'faq'         => __( 'پرسش‌های متداول', 'hoosh-seo' ),
			'schema'      => __( 'اسکیما', 'hoosh-seo' ),
			'slug'        => __( 'نامک', 'hoosh-seo' ),
			'social'      => __( 'متا شبکه اجتماعی', 'hoosh-seo' ),
			'summary'     => __( 'خلاصه', 'hoosh-seo' ),
			'answer'      => __( 'پاسخ مستقیم', 'hoosh-seo' ),
			'alt'         => __( 'متن جایگزین تصویر', 'hoosh-seo' ),
			'links'       => __( 'لینک داخلی', 'hoosh-seo' ),
			'product'     => __( 'توضیح محصول', 'hoosh-seo' ),
			'bulk'        => __( 'چند صفحه', 'hoosh-seo' ),
		);
		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	/**
	 * Does the payload contain something for this field?
	 *
	 * @param string $field Field.
	 * @param array  $data  Data.
	 * @return bool
	 */
	protected static function has_for( $field, $data ) {
		switch ( $field ) {
			case 'title':
				return ! empty( $data['titles'][0] );
			case 'description':
				return ! empty( $data['descriptions'][0] );
			case 'keywords':
				return ! empty( $data['primary'] );
			case 'content':
				return ! empty( $data['content'] );
			case 'excerpt':
				return ! empty( $data['excerpt'] );
			case 'faq':
				return ! empty( $data['faq'] );
			case 'schema':
				return ! empty( $data['blocks'] );
			case 'slug':
				return ! empty( $data['slugs'][0]['slug'] );
			case 'social':
				return ! empty( $data['og_title'] ) || ! empty( $data['og_description'] );
			case 'summary':
				return ! empty( $data['summary'] );
			case 'answer':
				return ! empty( $data['answer'] );
			case 'alt':
				return ! empty( $data['alts'] ) || ! empty( $data['alt'] );
			case 'links':
				return ! empty( $data['links'] );
			case 'product':
				return ! empty( $data['short'] ) || ! empty( $data['long'] );
			case 'bulk':
				return ! empty( $data['items'] );
		}
		return false;
	}

	/**
	 * Tidy model output: drop empties, enforce types.
	 *
	 * @param string $task Task.
	 * @param array  $data Data.
	 * @return array
	 */
	public static function normalize_output( $task, $data ) {
		$data = (array) $data;
		$out  = array();

		$strings = array( 'title', 'description', 'excerpt', 'summary', 'answer', 'alt', 'caption', 'narrative', 'og_title', 'og_description', 'primary', 'content', 'short', 'long', 'text', 'why', 'intro', 'lang', 'model' );
		foreach ( $strings as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$out[ $key ] = in_array( $key, array( 'content', 'long', 'answer', 'narrative', 'excerpt', 'summary' ), true )
					? wp_kses_post( trim( $data[ $key ] ) )
					: trim( wp_strip_all_tags( $data[ $key ] ) );
			}
		}

		// Titles / descriptions lists.
		foreach ( array( 'titles', 'descriptions', 'secondary', 'slugs', 'ideas', 'changes', 'added', 'key_points', 'hashtags', 'actions', 'sources', 'selectors', 'bullets' ) as $key ) {
			if ( empty( $data[ $key ] ) ) {
				continue;
			}
			$list = array();
			foreach ( (array) $data[ $key ] as $item ) {
				if ( is_array( $item ) ) {
					$clean = array();
					foreach ( $item as $k => $v ) {
						$clean[ sanitize_key( (string) $k ) ] = is_scalar( $v ) ? trim( wp_strip_all_tags( (string) $v ) ) : $v;
					}
					if ( $clean ) {
						$list[] = $clean;
					}
					continue;
				}
				$text = trim( wp_strip_all_tags( (string) $item ) );
				if ( '' !== $text ) {
					$list[] = $text;
				}
			}
			$out[ $key ] = array_values( array_unique( $list ) );
		}

		if ( isset( $data['faq'] ) && is_array( $data['faq'] ) ) {
			$out['faq'] = Schema::sanitize_faq( $data['faq'] );
		}
		if ( isset( $data['alts'] ) && is_array( $data['alts'] ) ) {
			$alts = array();
			foreach ( (array) $data['alts'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$alts[] = array(
					'file' => sanitize_text_field( (string) ( $row['file'] ?? '' ) ),
					'alt'  => sanitize_text_field( (string) ( $row['alt'] ?? '' ) ),
				);
			}
			$out['alts'] = $alts;
		}
		if ( isset( $data['links'] ) && is_array( $data['links'] ) ) {
			$links = array();
			foreach ( (array) $data['links'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['url'] ) ) {
					continue;
				}
				$links[] = array(
					'anchor' => sanitize_text_field( (string) ( $row['anchor'] ?? '' ) ),
					'url'    => esc_url_raw( (string) $row['url'] ),
					'reason' => sanitize_text_field( (string) ( $row['reason'] ?? '' ) ),
				);
			}
			$out['links'] = $links;
		}
		if ( isset( $data['blocks'] ) && is_array( $data['blocks'] ) ) {
			$out['blocks'] = Schema::sanitize_blocks(
				array_map(
					function ( $block ) {
						return is_array( $block ) ? array( 'type' => (string) ( $block['@type'] ?? 'WebPage' ), 'data' => $block ) : array();
					},
					(array) $data['blocks']
				)
			);
		}
		if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$items = array();
			foreach ( (array) $data['items'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$items[] = array(
					'post_id'     => isset( $row['post_id'] ) ? (int) $row['post_id'] : 0,
					'keyword'     => sanitize_text_field( (string) ( $row['keyword'] ?? '' ) ),
					'title'       => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
					'description' => sanitize_text_field( (string) ( $row['description'] ?? '' ) ),
					'volume'      => isset( $row['volume'] ) ? (int) $row['volume'] : 0,
					'difficulty'  => isset( $row['difficulty'] ) ? (int) $row['difficulty'] : 0,
					'intent'      => sanitize_text_field( (string) ( $row['intent'] ?? '' ) ),
					'cpc'         => isset( $row['cpc'] ) ? (float) $row['cpc'] : 0,
				);
			}
			$out['items'] = $items;
		}

		$titles = $out['titles'] ?? array();
		$out['lengths'] = array(
			'title' => $titles ? Helpers::strlen( (string) $titles[0] ) : ( isset( $out['title'] ) ? Helpers::strlen( $out['title'] ) : 0 ),
		);
		unset( $task );

		return $out + array( '_raw' => $data );
	}

	/**
	 * Apply task output to the post.
	 *
	 * @param string $task    Task.
	 * @param int    $post_id Post ID.
	 * @param array  $data    Data.
	 * @return array
	 */
	public static function apply( $task, $post_id, $data ) {
		$data    = (array) $data;
		$post_id = (int) $post_id;
		$done    = array();
		$failed  = array();

		if ( ! $post_id ) {
			return array( 'count' => 0, 'applied' => $done, 'failed' => $failed, 'message' => __( 'چیزی برای ذخیره نبود.', 'hoosh-seo' ) );
		}

		$settings = \hoosh_seo()->settings;
		$auto     = (array) $settings->get( 'ai.auto_apply', array( 'titles', 'description', 'keywords', 'alt', 'faq', 'social', 'summary' ) );
		$task     = sanitize_key( (string) $task );
		if ( ! in_array( $task, $auto, true ) ) {
			return array(
				'count'   => 0,
				'applied' => array(),
				'failed'  => array(),
				'skipped' => true,
				'message' => __( 'اعمال خودکار برای این کار خاموش است؛ از دکمه «اعمال» استفاده کنید.', 'hoosh-seo' ),
			);
		}

		$payload = array();
		$before  = Meta::for_post( $post_id );

		switch ( $task ) {
			case 'titles':
				if ( ! empty( $data['titles'][0] ) ) {
					$payload['title'] = (string) $data['titles'][0];
				}
				break;
			case 'description':
				if ( ! empty( $data['descriptions'][0] ) ) {
					$payload['description'] = (string) $data['descriptions'][0];
				}
				break;
			case 'keywords':
				if ( ! empty( $data['primary'] ) ) {
					$secondary = array_slice( (array) ( $data['secondary'] ?? array() ), 0, 4 );
					$payload['keywords'] = array_values( array_merge( array( (string) $data['primary'] ), array_map( 'strval', $secondary ) ) );
				}
				break;
			case 'content':
			case 'expand':
			case 'rewrite':
			case 'tone':
				if ( ! empty( $data['content'] ) ) {
					if ( wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => (string) $data['content'] ) ) ) ) {
						$done[] = 'post_content';
					} else {
						$failed[] = 'post_content';
					}
				}
				break;
			case 'excerpt':
				if ( ! empty( $data['excerpt'] ) ) {
					wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_excerpt' => (string) $data['excerpt'] ) ) );
					$done[] = 'post_excerpt';
				}
				break;
			case 'faq':
				if ( ! empty( $data['faq'] ) ) {
					$payload['faq'] = (array) $data['faq'];
				}
				break;
			case 'schema':
				if ( ! empty( $data['blocks'] ) ) {
					$payload['schema'] = (array) $data['blocks'];
				}
				break;
			case 'slug':
				if ( ! empty( $data['slugs'][0]['slug'] ) ) {
					$payload['slug'] = (string) $data['slugs'][0]['slug'];
				}
				break;
			case 'social':
				$social = (array) get_post_meta( $post_id, '_hs_social', true );
				$social['title']       = isset( $data['og_title'] ) ? $data['og_title'] : ( $social['title'] ?? '' );
				$social['description'] = isset( $data['og_description'] ) ? $data['og_description'] : ( $social['description'] ?? '' );
				$social['caption']     = isset( $data['caption'] ) ? $data['caption'] : ( $social['caption'] ?? '' );
				update_post_meta( $post_id, '_hs_social', $social );
				$done[] = 'social';
				break;
			case 'summary':
				if ( ! empty( $data['summary'] ) ) {
					update_post_meta( $post_id, '_hs_ai_summary', (string) $data['summary'] );
					self::update_page_summary( $post_id, (string) $data['summary'] );
					$done[] = 'summary';
				}
				break;
			case 'answer':
				if ( ! empty( $data['answer'] ) ) {
					update_post_meta( $post_id, '_hs_answer', (string) $data['answer'] );
					$done[] = 'answer';
				}
				break;
			case 'alt':
			case 'vision':
				$alts = array();
				if ( ! empty( $data['alt'] ) ) {
					$alts[] = array( 'id' => $post_id, 'alt' => (string) $data['alt'] );
				}
				foreach ( (array) ( $data['alts'] ?? array() ) as $row ) {
					$id = self::attachment_by_file( (string) ( $row['file'] ?? '' ) );
					if ( $id ) {
						$alts[] = array( 'id' => $id, 'alt' => (string) $row['alt'] );
					}
				}
				foreach ( $alts as $alt ) {
					update_post_meta( (int) $alt['id'], '_wp_attachment_image_alt', sanitize_text_field( (string) $alt['alt'] ) );
					$done[] = 'alt:' . (int) $alt['id'];
				}
				break;
			case 'internal':
				if ( ! empty( $data['links'] ) ) {
					$result = \HooshSEO\Modules\Links::insert_into_content( $post_id, (array) $data['links'] );
					if ( ! empty( $result['ok'] ) ) {
						$done[] = 'links';
					} else {
						$failed[] = 'links';
					}
				}
				break;
			case 'product':
				if ( ! empty( $data['short'] ) ) {
					$short = (string) $data['short'];
					$long  = isset( $data['long'] ) ? (string) $data['long'] : '';
					if ( function_exists( 'wc_get_product' ) ) {
						$product = wc_get_product( $post_id );
						if ( $product ) {
							$product->set_short_description( wp_kses_post( $short ) );
							$product->save();
							$done[] = 'product_short';
						}
					}
					unset( $long );
				}
				break;
			case 'bulk_meta':
				foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
					$target = (int) ( $item['post_id'] ?? 0 );
					if ( ! $target || ! get_post( $target ) ) {
						$failed[] = 'post:' . $target;
						continue;
					}
					Meta::save(
						$target,
						array(
							'title'       => (string) ( $item['title'] ?? '' ),
							'description' => (string) ( $item['description'] ?? '' ),
						)
					);
					$done[] = 'post:' . $target;
				}
				break;
		}

		if ( $payload ) {
			Meta::save( $post_id, $payload );
			foreach ( array_keys( $payload ) as $key ) {
				$done[] = $key;
			}
		}

		if ( $done ) {
			Audit::log(
				'ai',
				'apply-' . $task,
				array(
					'post_id' => $post_id,
					'before'  => $before,
					'after'   => Meta::for_post( $post_id ),
					'label'   => sprintf( /* translators: 1: task label, 2: title */ __( 'اعمال خروجی %1$s روی «%2$s»', 'hoosh-seo' ), self::label( $task ), get_the_title( $post_id ) ),
				)
			);
			clean_post_cache( $post_id );
			\HooshSEO\Modules\Content::store( $post_id );
		}

		return array(
			'count'   => count( $done ),
			'applied' => $done,
			'failed'  => $failed,
		);
	}

	/**
	 * Keep the analysis table in sync with an AI summary.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $summary Summary.
	 */
	protected static function update_page_summary( $post_id, $summary ) {
		global $wpdb;
		if ( ! \HooshSEO\Database::exists( 'pages' ) ) {
			return;
		}
		$wpdb->update( // phpcs:ignore
			\HooshSEO\Database::table( 'pages' ),
			array( 'ai_summary' => mb_substr( (string) $summary, 0, 1000 ) ),
			array( 'post_id' => (int) $post_id )
		);
	}

	/**
	 * Find an attachment by file name.
	 *
	 * @param string $file File name.
	 * @return int
	 */
	protected static function attachment_by_file( $file ) {
		$file = sanitize_file_name( (string) $file );
		if ( '' === $file ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $file ) )
		); // phpcs:ignore
	}

	/**
	 * Build the prompt for a task.
	 *
	 * @param string $task Task.
	 * @param int    $post_id Post ID.
	 * @param array  $payload Payload.
	 * @param array  $spec Spec.
	 * @return array|\WP_Error
	 */
	public static function build_prompt( $task, $post_id, $payload, $spec ) {
		$settings = \hoosh_seo()->settings;
		$post     = $post_id ? get_post( $post_id ) : null;

		$context = array();
		$image   = '';

		if ( $post ) {
			$keywords = Meta::focus_keywords( $post_id );
			$context[] = 'TITLE: ' . Meta::resolve( $post_id, 'title' );
			$context[] = 'META_DESCRIPTION: ' . Meta::resolve( $post_id, 'description' );
			$context[] = 'FOCUS_KEYWORD: ' . ( $keywords ? implode( ', ', $keywords ) : '(none yet)' );
			$context[] = 'SLUG: ' . (string) $post->post_name;
			$context[] = 'TYPE: ' . get_post_type( $post_id );
			$cats = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $cats ) && $cats ) {
				$context[] = 'CATEGORIES: ' . implode( ', ', (array) $cats );
			}
			$body = apply_filters( 'the_content', $post->post_content );
			$context[] = "CONTENT:\n" . wp_strip_all_tags( (string) $body );

			if ( ! empty( $spec['vision'] ) ) {
				$image = self::attachment_data_uri( $post_id );
				if ( '' === $image ) {
					return new \WP_Error( 'hs-no-image', __( 'تصویری برای تحلیل پیدا نشد.', 'hoosh-seo' ) );
				}
			}
		}

		// Task specific context.
		if ( 'alt' === $task && $post ) {
			$files = self::post_images( $post_id );
			if ( ! $files ) {
				return new \WP_Error( 'hs-no-images', __( 'این نوشته تصویری بدون alt ندارد.', 'hoosh-seo' ) );
			}
			$context[] = "IMAGES (file -> current alt):\n" . implode( "\n", $files );
		}

		if ( 'internal' === $task ) {
			$candidates = self::link_candidates( $post_id );
			$context[] = "SITE URLS (url -> title):\n" . implode( "\n", $candidates );
		}

		if ( 'bulk_meta' === $task ) {
			$ids = array_slice( array_map( 'absint', (array) ( $payload['post_ids'] ?? array() ) ), 0, 12 );
			$lines = array();
			foreach ( $ids as $id ) {
				$p = get_post( $id );
				if ( ! $p ) {
					continue;
				}
				$lines[] = sprintf(
					"post_id=%d | title=%s | excerpt=%s | words=%d",
					$id,
					get_the_title( $id ),
					wp_strip_all_tags( (string) $p->post_excerpt ),
					Helpers::word_count( $p->post_content )
				);
			}
			if ( ! $lines ) {
				return new \WP_Error( 'hs-no-posts', __( 'نوشته‌ای برای این دسته پیدا نشد.', 'hoosh-seo' ) );
			}
			$context[] = "POSTS:\n" . implode( "\n", $lines );
		}

		if ( 'research' === $task ) {
			$keywords = (array) ( $payload['keywords'] ?? array() );
			if ( ! $keywords ) {
				return new \WP_Error( 'hs-no-keywords', __( 'فهرست کلمه کلیدی داده نشد.', 'hoosh-seo' ) );
			}
			$context[] = "KEYWORDS (" . strtoupper( (string) $settings->get( 'keywords.lang', 'fa' ) ) . " market):\n" . implode( "\n", array_slice( array_map( 'strval', $keywords ), 0, 40 ) );
		}

		if ( 'ideas' === $task ) {
			$existing = (array) ( $payload['existing'] ?? array() );
			$context[] = 'SEED: ' . (string) ( $payload['seed'] ?? ( $post ? get_the_title( $post_id ) : '' ) );
			$context[] = "EXISTING ARTICLES (avoid overlap):\n" . implode( "\n", array_slice( array_map( 'strval', $existing ), 0, 40 ) );
		}

		if ( 'report' === $task ) {
			$snapshot = isset( $payload['snapshot'] ) ? (array) $payload['snapshot'] : \HooshSEO\Modules\Audit::quick_snapshot();
			$context[] = "NUMBERS:\n" . wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		}

		if ( ! empty( $payload['extra'] ) ) {
			$context[] = (string) $payload['extra'];
		}
		if ( ! empty( $payload['context'] ) ) {
			$context[] = (string) $payload['context'];
		}

		$prompt = (string) ( $payload['prompt'] ?? $spec['prompt'] );
		$prompt = str_replace( 'TARGET words', (string) (int) $settings->get( 'content.min_words', 600 ) . ' words', $prompt );

		if ( ! $post && empty( $payload['skip_post'] ) && in_array( 'post_id', (array) $spec['needs'], true ) ) {
			return new \WP_Error( 'hs-no-post', __( 'این کار به یک نوشته نیاز دارد.', 'hoosh-seo' ) );
		}

		return array(
			'system' => Gateway::system_prompt(),
			'prompt' => $prompt . "\n\n---\n" . implode( "\n", $context ),
			'image'  => $image,
		);
	}

	/**
	 * Images in a post with no alt text.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected static function post_images( $post_id ) {
		$out = array();
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $out;
		}
		if ( preg_match_all( '/<img\b[^>]*>/i', $post->post_content, $m ) ) {
			foreach ( (array) $m[0] as $tag ) {
				$src = '';
				$alt = '';
				preg_match( '/\ssrc\s*=\s*["\']([^"\']+)/i', $tag, $s );
				if ( isset( $s[1] ) ) {
					$src = $s[1];
				}
				preg_match( '/\salt\s*=\s*["\']([^"\']*)/i', $tag, $a );
				if ( isset( $a[1] ) ) {
					$alt = trim( (string) $a[1] );
				}
				if ( '' === $alt && $src ) {
					$out[] = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) . ' -> (empty)';
				}
			}
		}
		if ( ! $out && has_post_thumbnail( $post_id ) ) {
			$id  = get_post_thumbnail_id( $post_id );
			$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			if ( '' === $alt ) {
				$out[] = basename( (string) get_attached_file( $id ) ) . ' -> (empty, featured)';
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, 25 );
	}

	/**
	 * Candidate internal link targets for the internal-links task.
	 *
	 * @param int $exclude_post_id Post ID.
	 * @return array
	 */
	protected static function link_candidates( $exclude_post_id ) {
		$posts = get_posts(
			array(
				'post_type'      => Helpers::managed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 40,
				'orderby'        => 'modified',
				'post__not_in'   => array( (int) $exclude_post_id ),
				'fields'         => 'ids',
			)
		);
		$out = array();
		foreach ( (array) $posts as $id ) {
			$out[] = get_permalink( $id ) . ' -> ' . get_the_title( $id );
		}
		return $out;
	}

	/**
	 * Base64 data URI for vision models (small, resized).
	 *
		* @param int $attachment_id Attachment or post ID.
	 * @return string
	 */
	public static function attachment_data_uri( $attachment_id ) {
		$id = (int) $attachment_id;
		if ( 'attachment' !== get_post_type( $id ) ) {
			if ( ! has_post_thumbnail( $id ) ) {
				return '';
			}
			$id = get_post_thumbnail_id( $id );
		}
		$size = (array) \hoosh_seo()->settings->get( 'ai.vision_size', array( 'width' => 512, 'quality' => 70 ) );
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}
		$raw = @file_get_contents( $file ); // phpcs:ignore
		if ( ! $raw ) {
			return '';
		}
		$type = wp_check_filetype( $file );
		$mime = ! empty( $type['type'] ) ? $type['type'] : 'image/jpeg';

		// Downscale to keep the request cheap.
		if ( function_exists( 'imagecreatefromstring' ) ) {
			$image = @imagecreatefromstring( $raw ); // phpcs:ignore
			if ( $image ) {
				$limit  = max( 256, min( 1200, (int) ( $size['width'] ?? 512 ) ) );
				$width  = (int) imagesx( $image );
				$height = (int) imagesy( $image );
				if ( $width > $limit ) {
					$resized = imagescale( $image, $limit, (int) round( $height * ( $limit / $width ) ) );
					ob_start();
					imagejpeg( $resized, null, (int) ( $size['quality'] ?? 70 ) );
					$raw = (string) ob_get_clean();
					imagedestroy( $resized );
					$mime = 'image/jpeg';
				}
				imagedestroy( $image );
			}
		}

		if ( strlen( $raw ) > 400000 ) {
			return '';
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $raw );
	}
}
