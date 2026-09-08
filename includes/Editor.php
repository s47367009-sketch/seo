<?php
/**
 * Editor integration: classic metabox, block-editor sidebar panel, REST fields.
 *
 * The heavy lifting (live analysis, previews, AI actions) is done by
 * assets/editor.js + the REST API, so classic and block editors share one brain.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Editor
 */
final class Editor {

	/**
	 * Singleton.
	 *
	 * @var Editor|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Editor
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Hooks.
	 */
	public function bootstrap() {
		add_action( 'add_meta_boxes', array( $this, 'meta_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 40, 3 );
		add_action( 'rest_api_init', array( $this, 'register_fields' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 20 );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'allow_block_editor' ), 5 );
	}

	/**
	 * Meta box for the classic editor.
	 *
	 * @param string $post_type Post type.
	 */
	public function meta_box( $post_type ) {
		if ( ! in_array( $post_type, Helpers::managed_post_types(), true ) ) {
			return;
		}
		if ( ! current_user_can( hoosh_seo()->settings->get( 'security.editor_meta_cap', 'edit_posts' ) ) ) {
			return;
		}
		add_meta_box(
			'hoosh-seo-metabox',
			__( 'هوش‌سئو', 'hoosh-seo' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'normal',
			'high'
		);
	}

	/**
	 * Meta box body. All interactivity comes from editor.js.
	 *
	 * @param \WP_Post $post   Post.
	 * @param array    $args   Args.
	 */
	public function render_meta_box( $post, $args = array() ) {
		$data = Meta::for_post( $post->ID );
		wp_nonce_field( 'hoosh_seo_meta', 'hoosh_seo_nonce' );
		?>
		<div
			id="hoosh-seo-classic"
			class="hs-mb"
			data-post="<?php echo esc_attr( $post->ID ); ?>"
			data-type="<?php echo esc_attr( $post->post_type ); ?>"
			data-initial="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"
		>
			<div class="hs-mb-tabs" role="tablist">
				<button type="button" class="hs-tab is-active" data-tab="seo" role="tab"><?php esc_html_e( 'سئو', 'hoosh-seo' ); ?></button>
				<button type="button" class="hs-tab" data-tab="readability" role="tab"><?php esc_html_e( 'خوانایی', 'hoosh-seo' ); ?></button>
				<button type="button" class="hs-tab" data-tab="social" role="tab"><?php esc_html_e( 'شبکه‌های اجتماعی', 'hoosh-seo' ); ?></button>
				<button type="button" class="hs-tab" data-tab="schema" role="tab"><?php esc_html_e( 'اسکیما', 'hoosh-seo' ); ?></button>
				<button type="button" class="hs-tab" data-tab="ai" role="tab"><?php esc_html_e( 'هوش مصنوعی', 'hoosh-seo' ); ?></button>
				<span class="hs-mb-score" data-score="0"><i></i><b>۰</b></span>
			</div>
			<div class="hs-mb-panes"></div>
			<div class="hs-mb-loading"><span></span><span></span><span></span></div>
		</div>
		<?php
	}

	/**
	 * Persist metabox values (classic editor).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Update.
	 */
	public function save( $post_id, $post = null, $update = false ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['hoosh_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hoosh_seo_nonce'] ) ), 'hoosh_seo_meta' ) ) {
			// Block editor saves through the REST fields; nothing to do here.
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$payload = isset( $_POST['hoosh_seo'] ) ? wp_unslash( $_POST['hoosh_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$payload = is_array( $payload ) ? $payload : json_decode( (string) $payload, true );

		Meta::save( $post_id, (array) $payload );

		/**
		 * Fires after per-post SEO data is stored.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $payload Saved payload.
		 */
		do_action( 'hoosh_seo_post_saved', $post_id, (array) $payload );

		// Re-analyse without blocking the save request.
		if ( hoosh_seo()->settings->get( 'advanced.analysis_on_save', true ) ) {
			Modules\Content::queue_analysis( $post_id );
		}
	}

	/**
	 * Expose `hoosh` meta in the REST posts controller (block editor + headless).
	 */
	public function register_fields() {
		foreach ( Helpers::managed_post_types() as $type ) {
			register_rest_field(
				$type,
				'hoosh',
				array(
					'get_callback'    => function ( $object ) {
						return Meta::for_post( (int) $object['id'] );
					},
					'update_callback' => function ( $value, $object ) {
						if ( ! is_array( $value ) ) {
							return;
						}
						Meta::save( (int) $object->ID, $value );
						Modules\Content::queue_analysis( (int) $object->ID );
					},
					'schema'          => array(
						'description' => __( 'داده‌های سئوی افزونه هوش‌سئو', 'hoosh-seo' ),
						'type'        => 'object',
						'context'     => array( 'edit' ),
					),
				)
			);
		}
	}

	/**
	 * Block editor assets.
	 */
	public function block_assets() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		wp_enqueue_script(
			'hoosh-seo-gutenberg',
			HOOSH_SEO_URL . 'assets/gutenberg.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-compose', 'wp-i18n', 'hoosh-seo-editor' ),
			HOOSH_SEO_VERSION,
			true
		);
		wp_localize_script( 'hoosh-seo-gutenberg', 'HOOSH_GUTENBERG', array( 'hasYoast' => defined( 'WPSEO_VERSION' ) ) );
	}

	/**
	 * Editor CSS + JS (classic and block).
	 *
	 * @param string $hook Hook.
	 */
	public function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook && 'edit.php' !== $hook ) {
			return;
		}
		global $post;

		wp_enqueue_style( 'hoosh-seo-editor', HOOSH_SEO_URL . 'assets/editor.css', array(), HOOSH_SEO_VERSION );
		wp_enqueue_script( 'hoosh-seo-editor', HOOSH_SEO_URL . 'assets/editor.js', array(), HOOSH_SEO_VERSION, true );

		$screen = get_current_screen();
		$post_id = $post ? (int) $post->ID : 0;

		wp_localize_script(
			'hoosh-seo-editor',
			'HOOSH_EDITOR',
			array(
				'rest'     => esc_url_raw( rest_url( hoosh_seo()->settings->get( 'advanced.rest_namespace', 'hoosh/v1' ) ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'postId'   => $post_id,
				'postType' => $screen ? $screen->post_type : '',
				'studio'   => App::studio_url( 'snippet' ) . ( $post_id ? '&post=' . $post_id : '' ),
				'aiReady'  => AI\Gateway::is_configured(),
				'i18n'     => $this->strings(),
				'settings' => array(
					'titleMax'   => (int) hoosh_seo()->settings->get( 'content.title_max', 60 ),
					'descMax'    => (int) hoosh_seo()->settings->get( 'content.desc_max', 158 ),
					'maxFocus'   => 5,
					'checks'     => Modules\Content::check_catalog(),
					'sep'        => hoosh_seo()->settings->get( 'general.separator', '|' ),
					'siteName'   => get_bloginfo( 'name' ),
					'shamsi'     => (bool) hoosh_seo()->settings->get( 'appearance.shamsi', true ),
					'digits'     => (bool) hoosh_seo()->settings->get( 'appearance.persian_digits', true ),
					'accent'     => hoosh_seo()->settings->get( 'appearance.accent', 'firouzeh' ),
					'theme'      => 'auto',
					'taxonomies' => array_keys( get_taxonomies( array( 'public' => true ), 'names' ) ),
				),
			)
		);
	}

	/**
	 * Translated strings for the editor bundle.
	 *
	 * @return array
	 */
	protected function strings() {
		return array(
			'seo'          => __( 'سئو', 'hoosh-seo' ),
			'readability'  => __( 'خوانایی', 'hoosh-seo' ),
			'social'       => __( 'شبکه‌های اجتماعی', 'hoosh-seo' ),
			'schema'       => __( 'اسکیما', 'hoosh-seo' ),
			'ai'           => __( 'هوش مصنوعی', 'hoosh-seo' ),
			'generate'     => __( 'تولید با هوش مصنوعی', 'hoosh-seo' ),
			'suggestions'  => __( 'پیشنهاد کلمه کلیدی', 'hoosh-seo' ),
			'summary'      => __( 'خلاصه سئو', 'hoosh-seo' ),
			'studio'       => __( 'باز کردن در استودیو', 'hoosh-seo' ),
			'saving'       => __( 'در حال ذخیره…', 'hoosh-seo' ),
			'saved'        => __( 'ذخیره شد', 'hoosh-seo' ),
			'error'        => __( 'خطا در ارتباط با سرور', 'hoosh-seo' ),
			'analysis'     => __( 'نتایج بررسی', 'hoosh-seo' ),
			'problems'     => __( 'مشکلات', 'hoosh-seo' ),
			'improvements' => __( 'قابل بهبود', 'hoosh-seo' ),
			'passed'       => __( 'قبول شده', 'hoosh-seo' ),
			'noKeywords'   => __( 'هنوز کلمه کلیدی انتخاب نکرده‌اید', 'hoosh-seo' ),
			'addKeyword'   => __( 'افزودن کلمه کلیدی', 'hoosh-seo' ),
			'researchKw'   => __( 'تحقیق کلمه کلیدی', 'hoosh-seo' ),
			'preview'      => __( 'پیش‌نمایش در گوگل', 'hoosh-seo' ),
			'mobile'       => __( 'موبایل', 'hoosh-seo' ),
			'desktop'      => __( 'دسکتاپ', 'hoosh-seo' ),
			'fixWithAI'    => __( 'اصلاح با هوش مصنوعی', 'hoosh-seo' ),
			'apply'        => __( 'اعمال', 'hoosh-seo' ),
			'ignore'       => __( 'نادیده گرفتن', 'hoosh-seo' ),
			'score'        => __( 'امتیاز', 'hoosh-seo' ),
			'words'        => __( 'کلمه', 'hoosh-seo' ),
			'readTime'     => __( 'زمان مطالعه', 'hoosh-seo' ),
			'min'          => __( 'دقیقه', 'hoosh-seo' ),
			'linksIn'      => __( 'لینک داخلی', 'hoosh-seo' ),
			'linksOut'     => __( 'لینک خارجی', 'hoosh-seo' ),
			'title'        => __( 'عنوان سئو', 'hoosh-seo' ),
			'description'  => __( 'توضیحات متا', 'hoosh-seo' ),
			'slug'         => __( 'پیوند یکتا', 'hoosh-seo' ),
			'robots'       => __( 'دستور ربات', 'hoosh-seo' ),
			'canonical'    => __( 'آدرس canonical', 'hoosh-seo' ),
			'socialTitle'  => __( 'عنوان در شبکه اجتماعی', 'hoosh-seo' ),
			'socialDesc'   => __( 'توضیح در شبکه اجتماعی', 'hoosh-seo' ),
			'socialImage'  => __( 'تصویر شبکه اجتماعی', 'hoosh-seo' ),
			'chooseImage'  => __( 'انتخاب تصویر', 'hoosh-seo' ),
			'removeImage'  => __( 'حذف تصویر', 'hoosh-seo' ),
			'schemaType'   => __( 'نوع اسکیما', 'hoosh-seo' ),
			'faq'          => __( 'پرسش‌های متداول', 'hoosh-seo' ),
			'addFaq'       => __( 'افزودن پرسش', 'hoosh-seo' ),
			'steps'        => __( 'مراحل (HowTo)', 'hoosh-seo' ),
			'addStep'      => __( 'افزودن مرحله', 'hoosh-seo' ),
			'progress'     => __( 'در حال پردازش', 'hoosh-seo' ),
			'queued'       => __( 'در صف', 'hoosh-seo' ),
			'done'         => __( 'انجام شد', 'hoosh-seo' ),
			'failed'       => __( 'ناموفق', 'hoosh-seo' ),
			'self_host_hint' => __( 'می‌توانید به Ollama یا سرویس ایرانی وصل شوید.', 'hoosh-seo' ),
		);
	}

	/**
	 * Always allow the block editor: our panel is built for it.
	 *
	 * @param bool $use Use it.
	 * @return bool
	 */
	public function allow_block_editor( $use ) {
		return $use;
	}
}
