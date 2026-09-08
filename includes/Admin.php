<?php
/**
 * wp-admin integration: launcher, menu, admin bar, columns, notices, dashboard widget.
 *
 * The real UI lives in the standalone Studio (new tab). wp-admin only keeps the
 * entry points, the per-post metabox and lightweight columns.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin
 */
final class Admin {

	/**
	 * Singleton.
	 *
	 * @var Admin|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Admin
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
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
		add_action( 'load-toplevel_page_hoosh-seo', array( $this, 'maybe_redirect_to_studio' ) );
		add_filter( 'manage_posts_columns', array( $this, 'columns' ), 20 );
		add_filter( 'manage_pages_columns', array( $this, 'columns' ), 20 );
		add_filter( 'manage_product_posts_columns', array( $this, 'columns' ), 20 );
		add_action( 'manage_posts_custom_column', array( $this, 'column_value' ), 20, 2 );
		add_action( 'manage_pages_custom_column', array( $this, 'column_value' ), 20, 2 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'column_value' ), 20, 2 );
		add_filter( 'manage_edit-post_sortable_columns', array( $this, 'sortable' ) );
		add_filter( 'manage_edit-page_sortable_columns', array( $this, 'sortable' ) );
		add_action( 'pre_get_posts', array( $this, 'column_sort' ) );
		add_filter( 'debug_information', array( $this, 'site_health' ) );
		add_action( 'admin_init', array( $this, 'wake_up' ) );
	}

	/**
	 * Settings URL used everywhere in wp-admin.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=hoosh-seo' );
	}

	/**
	 * Register the top-level page. The slug doubles as a launcher.
	 */
	public function menu() {
		$cap = 'manage_options';

		add_menu_page(
			__( 'هوش‌سئو', 'hoosh-seo' ),
			__( 'هوش‌سئو', 'hoosh-seo' ),
			$cap,
			'hoosh-seo',
			array( $this, 'render_launcher' ),
			'data:image/svg+xml;base64,' . base64_encode( $this->menu_icon() ), // phpcs:ignore
			3
		);

		add_submenu_page( 'hoosh-seo', __( 'استودیوی هوش‌سئو', 'hoosh-seo' ), __( 'باز کردن استودیو', 'hoosh-seo' ), $cap, 'hoosh-seo' );

		foreach ( array(
			array( 'content', __( 'تحلیل محتوا', 'hoosh-seo' ) ),
			array( 'keywords', __( 'تحقیق کلمات کلیدی', 'hoosh-seo' ) ),
			array( 'audit', __( 'ممیزی فنی', 'hoosh-seo' ) ),
			array( 'schema', __( 'استودیوی اسکیما', 'hoosh-seo' ) ),
			array( 'redirects', __( 'ریدایرکت‌ها', 'hoosh-seo' ) ),
			array( 'autopilot', __( 'خلبانی سئو', 'hoosh-seo' ) ),
			array( 'settings', __( 'تنظیمات', 'hoosh-seo' ) ),
		) as $item ) {
			add_submenu_page( 'hoosh-seo', $item[1], $item[1], $cap, 'hoosh-seo-' . $item[0], array( $this, 'render_launcher' ) );
		}
	}

	/**
	 * Inline menu icon (18x18, single colour so WP can tint it).
	 *
	 * @return string
	 */
	protected function menu_icon() {
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="9" cy="9" r="5.3"/><path d="M13.1 13.1 17 17"/><path d="M6.4 9.2l1.9 1.9 3.3-3.6"/></svg>';
	}

	/**
	 * Open the Studio directly on the main entry (single click, new tab).
	 */
	public function maybe_redirect_to_studio() {
		if ( isset( $_GET['keep'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_redirect( App::studio_url() ); // phpcs:ignore
		exit;
	}

	/**
	 * Launcher screen: a compact, honest bridge into the app.
	 */
	public function render_launcher() {
		$view   = '';
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( $screen->id, 'hoosh-seo-' ) ) {
			$parts = explode( '-', $screen->id, 3 );
			$view  = isset( $parts[2] ) ? $parts[2] : '';
		}
		$studio     = App::studio_url( $view );
		$score      = Modules\Audit::quick_snapshot();
		$ai_ready   = AI\Gateway::is_configured();
		?>
		<div class="wrap hs-launch">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'هوش‌سئو', 'hoosh-seo' ); ?></h1>
			<p>
				<?php esc_html_e( 'استودیوی هوش‌سئو یک برنامه تمام‌صفحه است که در تب جدید باز می‌شود؛ همه ابزارها، نمودارها و هوش مصنوعی آنجاست.', 'hoosh-seo' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $studio ); ?>" target="_blank" rel="noopener" class="button button-primary button-hero">
					<?php esc_html_e( 'باز کردن استودیو در تب جدید', 'hoosh-seo' ); ?>
				</a>
				<a href="<?php echo esc_url( App::studio_url( 'settings' ) ); ?>" target="_blank" rel="noopener" class="button button-hero">
					<?php esc_html_e( 'تنظیمات افزونه', 'hoosh-seo' ); ?>
				</a>
			</p>

			<table class="widefat striped" style="max-width:760px;margin-top:18px">
				<tbody>
					<tr>
						<th scope="row" style="width:220px"><?php esc_html_e( 'امتیاز سلامت سایت', 'hoosh-seo' ); ?></th>
						<td>
							<strong><?php echo esc_html( Helpers::number( $score['score'] ) ); ?></strong> / ۱۰
							<span class="hs-grade-badge"><?php echo esc_html( Helpers::grade( $score['score'] ) ); ?></span>
							&nbsp;·&nbsp; <?php echo esc_html( sprintf( /* translators: %d number of pages. */ __( '%d صفحه بررسی‌شده', 'hoosh-seo' ), (int) $score['scanned'] ) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'موارد بحرانی باز', 'hoosh-seo' ); ?></th>
						<td><?php echo esc_html( Helpers::number( $score['critical'] ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'هوش مصنوعی', 'hoosh-seo' ); ?></th>
						<td>
							<?php if ( $ai_ready ) : ?>
								<?php esc_html_e( 'آماده است. می‌توانید تولید خودکار متا، کلمه کلیدی و خلاصه را اجرا کنید.', 'hoosh-seo' ); ?>
							<?php else : ?>
								<a href="<?php echo esc_url( App::studio_url( 'ai' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'یک سرویس هوش مصنوعی وصل کنید (کلید API)', 'hoosh-seo' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'نسخه', 'hoosh-seo' ); ?></th>
						<td><?php echo esc_html( HOOSH_SEO_VERSION ); ?> · PHP <?php echo esc_html( PHP_VERSION ); ?> · WP <?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<style>
				.hs-grade-badge{display:inline-block;padding:1px 8px;border-radius:999px;background:#227d6c;color:#fff;font-weight:600}
			</style>
		</div>
		<?php
	}

	/**
	 * Admin bar node with the current score.
	 *
	 * @param \WP_Admin_Bar $bar Bar.
	 */
	public function admin_bar( $bar ) {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! hoosh_seo()->settings->get( 'general.admin_bar', true ) ) {
			return;
		}

		$score = Modules\Audit::quick_snapshot();
		$bar->add_node(
			array(
				'id'    => 'hoosh-seo',
				/* translators: %s score. */
				'title' => sprintf( __( 'هوش‌سئو %s', 'hoosh-seo' ), '<span style="color:#22c55e">' . esc_html( Helpers::grade( $score['score'] ) ) . '</span>' ),
				'href'  => App::studio_url(),
				'meta'  => array( 'target' => '_blank' ),
			)
		);

		foreach ( array(
			'content'  => __( 'تحلیل محتوا', 'hoosh-seo' ),
			'keywords' => __( 'تحقیق کلمات کلیدی', 'hoosh-seo' ),
			'audit'    => __( 'ممیزی فنی', 'hoosh-seo' ),
			'autopilot' => __( 'خلبانی سئو', 'hoosh-seo' ),
		) as $view => $label ) {
			$bar->add_node(
				array(
					'parent' => 'hoosh-seo',
					'id'     => 'hoosh-seo-' . $view,
					'title'  => $label,
					'href'   => App::studio_url( $view ),
					'meta'   => array( 'target' => '_blank' ),
				)
			);
		}

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$bar->add_node(
				array(
					'parent' => 'hoosh-seo',
					'id'     => 'hoosh-seo-edit',
					'title'  => __( 'ویرایش سئوی همین صفحه', 'hoosh-seo' ),
					'href'   => App::studio_url( 'snippet' ) . '&post=' . (int) $post_id,
					'meta'   => array( 'target' => '_blank' ),
				)
			);
		}
	}

	/**
	 * Admin assets.
	 *
	 * @param string $hook Hook.
	 */
	public function assets( $hook ) {
		wp_register_style( 'hoosh-seo-admin', HOOSH_SEO_URL . 'assets/admin.css', array(), HOOSH_SEO_VERSION );
		wp_enqueue_style( 'hoosh-seo-admin' );
	}

	/**
	 * One-time welcome + setup nudge.
	 */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_transient( 'hoosh_seo_activated' ) ) {
			delete_transient( 'hoosh_seo_activated' );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s <a href="%s" target="_blank" rel="noopener" class="button button-primary">%s</a></p></div>',
				esc_html__( 'هوش‌سئو نصب شد. جدول‌ها ساخته شدند؛ حالا ۴ دقیقه وقت بگذارید و اتصال هوش مصنوعی را وصل کنید.', 'hoosh-seo' ),
				esc_url( App::studio_url( 'welcome' ) ),
				esc_html__( 'شروع راه‌اندازی', 'hoosh-seo' )
			);
		}

		$conflicts = get_option( 'hoosh_seo_conflicts', array() );
		if ( $conflicts && ! get_user_meta( get_current_user_id(), 'hoosh_notice_conflicts', true ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s <strong>%s</strong></p></div>',
				esc_html__( 'هوش‌سئو این افزونه‌ها را شناسایی کرد و ممکن است در متا/اسکیما/نقشه سایت تداخل ایجاد کنند. توصیه می‌شود یک افزونه سئو فعال بماند:', 'hoosh-seo' ),
				esc_html( implode( '، ', $conflicts ) )
			);
			echo '<script>jQuery(function($){$(".notice").on("click",".notice-dismiss",function(){$.post(ajaxurl,{action:"hoosh_seo_dismiss_notice",nonce:"' . esc_js( wp_create_nonce( 'hoosh_seo_notice' ) ) . '",id:"conflicts"})});});</script>';
		}
	}

	/**
	 * Dashboard widget.
	 */
	public function dashboard_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'hoosh_seo_dashboard', __( 'هوش‌سئو — نمای کلی', 'hoosh-seo' ), array( $this, 'render_dashboard_widget' ) );
	}

	/**
	 * Dashboard widget body.
	 */
	public function render_dashboard_widget() {
		$snapshot = Modules\Audit::quick_snapshot();
		echo '<div class="hs-dash">';
		echo '<div class="hs-dash-score"><span>' . esc_html( Helpers::number( $snapshot['score'] ) ) . '</span><small>/ ۱۰۰</small><em>' . esc_html( Helpers::grade( $snapshot['score'] ) ) . '</em></div>';
		echo '<ul class="hs-dash-list">';
		printf( '<li>%s <strong>%s</strong></li>', esc_html__( 'موارد بحرانی:', 'hoosh-seo' ), esc_html( Helpers::number( $snapshot['critical'] ) ) );
	printf( '<li>%s <strong>%s</strong></li>', esc_html__( 'هشدارها:', 'hoosh-seo' ), esc_html( Helpers::number( $snapshot['warnings'] ) ) );
		printf( '<li>%s <strong>%s</strong></li>', esc_html__( 'ریدایرکت فعال:', 'hoosh-seo' ), esc_html( Helpers::number( $snapshot['redirects'] ) ) );
		printf( '<li>%s <strong>%s</strong></li>', esc_html__( 'خطای ۴۰۴ باز:', 'hoosh-seo' ), esc_html( Helpers::number( $snapshot['404'] ) ) );
		printf( '<li>%s <strong>%s</strong></li>', esc_html__( 'کلمه در ردیابی:', 'hoosh-seo' ), esc_html( Helpers::number( $snapshot['keywords'] ) ) );
		echo '</ul>';
		printf( '<p><a class="button button-primary" href="%s" target="_blank" rel="noopener">%s</a></p>', esc_url( App::studio_url() ), esc_html__( 'باز کردن استودیو', 'hoosh-seo' ) );
		echo '</div>';
	}

	/**
	 * Score / focus keyword columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public function columns( $cols ) {
		if ( ! hoosh_seo()->settings->get( 'general.show_score_columns', true ) ) {
			return $cols;
		}
		$cols['hoosh_score']    = __( 'سئو', 'hoosh-seo' );
		$cols['hoosh_keywords'] = __( 'کلمه کلیدی', 'hoosh-seo' );
		return $cols;
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public function column_value( $column, $post_id ) {
		if ( 'hoosh_score' === $column ) {
			$score = (float) get_post_meta( $post_id, '_hs_score', true );
			$cls   = $score >= 80 ? 'ok' : ( $score >= 60 ? 'warn' : 'bad' );
			printf(
				'<span class="hs-col-score hs-col-score--%s" title="%s">%s</span>',
				esc_attr( $cls ),
				esc_attr__( 'امتیاز سئوی این صفحه', 'hoosh-seo' ),
				esc_html( $score ? number_format_i18n( $score, 0 ) . ' · ' . Helpers::grade( $score ) : '—' )
			);
			return;
		}

		if ( 'hoosh_keywords' === $column ) {
			$kws = Meta::focus_keywords( $post_id );
			echo $kws ? esc_html( implode( '، ', array_slice( $kws, 0, 3 ) ) ) : '—';
		}
	}

	/**
	 * Sortable columns.
	 *
	 * @param array $cols Sortable.
	 * @return array
	 */
	public function sortable( $cols ) {
		$cols['hoosh_score'] = 'hoosh_score';
		return $cols;
	}

	/**
	 * Order by score.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function column_sort( $query ) {
		if ( ! is_admin() || 'hoosh_score' !== $query->get( 'orderby' ) ) {
			return;
		}
		$query->set( 'meta_key', '_hs_score' );
		$query->set( 'orderby', 'meta_value_num' );
	}

	/**
	 * Site Health information.
	 *
	 * @param array $info Info.
	 * @return array
	 */
	public function site_health( $info ) {
		if ( ! hoosh_seo()->settings->get( 'advanced.site_health', true ) ) {
			return $info;
		}
		$snapshot = Modules\Audit::quick_snapshot();
		$stats    = Database::stats();

		$info['hoosh-seo'] = array(
			'label'  => __( 'هوش‌سئو', 'hoosh-seo' ),
			'fields' => array(
				'version'   => array( 'label' => __( 'نسخه', 'hoosh-seo' ), 'value' => HOOSH_SEO_VERSION ),
				'ai'        => array( 'label' => __( 'سرویس هوش مصنوعی', 'hoosh-seo' ), 'value' => AI\Gateway::is_configured() ? AI\Gateway::active_driver() : __( 'پیکربندی نشده', 'hoosh-seo' ) ),
				'score'     => array( 'label' => __( 'امتیاز سلامت', 'hoosh-seo' ), 'value' => $snapshot['score'] . ' / ۱۰' ),
				'sitemap'   => array( 'label' => __( 'نقشه سایت', 'hoosh-seo' ), 'value' => home_url( '/sitemap_index.xml' ) ),
				'llms'      => array( 'label' => __( 'فایل llms.txt', 'hoosh-seo' ), 'value' => home_url( '/llms.txt' ) ),
				'crawlers'  => array( 'label' => __( 'خزنده‌های ثبت‌شده (۷ روز)', 'hoosh-seo' ), 'value' => Modules\Geo::recent_crawlers() ),
				'queue'     => array( 'label' => __( 'صف هوش مصنوعی', 'hoosh-seo' ), 'value' => (int) $stats['ai_jobs'] . __( ' کار', 'hoosh-seo' ) ),
				'db'        => array( 'label' => __( 'رکوردهای جدول‌ها', 'hoosh-seo' ), 'value' => array_sum( $stats ) ),
				'cron'      => array( 'label' => __( 'زمان‌بندی', 'hoosh-seo' ), 'value' => wp_get_environment_type() . ' / ' . hoosh_seo()->settings->get( 'automation.cron_driver', 'wp' ) ),
			),
		);

		return $info;
	}

	/**
	 * Keep cron/events alive when the site is idle (only on admin loads).
	 */
	public function wake_up() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		Cron::schedule_all();
	}

	/**
	 * Queue a Studio/admin notice (deduped by key, capped).
	 *
	 * @param string $key     Stable key.
	 * @param string $level   info|warning|error|success.
	 * @param string $title   Title.
	 * @param string $message Body.
	 * @param array  $args    {action_label, action_url, ttl}.
	 * @return bool
	 */
	public static function push_notice( $key, $level, $title, $message, $args = array() ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return false;
		}
		$notices = (array) get_option( 'hoosh_seo_notices', array() );
		if ( isset( $notices[ $key ] ) ) {
			return false;
		}
		$notices[ $key ] = array(
			'level'        => in_array( $level, array( 'info', 'warning', 'error', 'success' ), true ) ? $level : 'info',
			'title'        => mb_substr( (string) $title, 0, 90 ),
			'message'      => mb_substr( (string) $message, 0, 400 ),
			'action_label' => mb_substr( (string) ( $args['action_label'] ?? '' ), 0, 40 ),
			'action_url'   => (string) ( $args['action_url'] ?? '' ),
			'created'      => time(),
		);
		if ( count( $notices ) > 12 ) {
			$notices = array_slice( $notices, -12, null, true );
		}
		update_option( 'hoosh_seo_notices', $notices, false );

		$ttl = (int) ( $args['ttl'] ?? 0 );
		if ( $ttl > 0 ) {
			set_transient( 'hoosh_notice_seen_' . $key, 1, $ttl );
		}
		return true;
	}

	/**
	 * Remove a queued notice.
	 *
	 * @param string $key Key.
	 */
	public static function clear_notice( $key ) {
		$notices = (array) get_option( 'hoosh_seo_notices', array() );
		unset( $notices[ sanitize_key( (string) $key ) ] );
		update_option( 'hoosh_seo_notices', $notices, false );
	}

	/**
	 * Notices for the Studio (skips anything dismissed by this user).
	 *
	 * @return array
	 */
	public static function pull_notices() {
		$out = array();
		foreach ( (array) get_option( 'hoosh_seo_notices', array() ) as $key => $notice ) {
			if ( ! is_array( $notice ) ) {
				continue;
			}
			if ( get_user_meta( get_current_user_id(), 'hoosh_notice_' . $key, true ) ) {
				continue;
			}
			$notice['key'] = (string) $key;
			$out[]         = $notice;
		}
		return $out;
	}

}
