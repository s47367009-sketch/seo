<?php
/**
 * WooCommerce integration: barcode/GTIN fields, brand, shop hygiene for crawlers
 * and product-schema knobs.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class Woo
 */
final class Woo {

	/**
	 * Singleton.
	 *
	 * @var Woo|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Woo
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Is Woo present and the module on?
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'WooCommerce' ) && \hoosh_seo()->settings->on( 'woo.enabled', true );
	}

	/**
	 * Hooks (both admin and front-end live here, guarded by WooCommerce checks).
	 */
	public function bootstrap() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$settings = \hoosh_seo()->settings;

		if ( $settings->on( 'woo.gtin_field', true ) || $settings->on( 'woo.mpn_field', true ) || $settings->on( 'woo.isbn_field', true ) ) {
			add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_fields' ) );
			add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_fields' ) );
		}
		add_filter( 'hoosh_seo_post_robots', array( __CLASS__, 'shop_robots' ), 20, 2 );
		add_filter( 'hoosh_seo_schema_graph', array( __CLASS__, 'schema_tweaks' ), 25 );

		if ( $settings->on( 'woo.remove_wc_version', true ) ) {
			add_filter( 'style_loader_src', array( __CLASS__, 'strip_version' ), 20 );
			add_filter( 'script_loader_src', array( __CLASS__, 'strip_version' ), 20 );
		}
		if ( $settings->on( 'woo.short_description_meta', true ) ) {
			add_filter( 'hoosh_seo_meta_description_source', array( __CLASS__, 'description_source' ), 20, 2 );
		}
		if ( $settings->on( 'woo.strip_pagination', true ) ) {
			add_filter( 'hoosh_seo_canonical', array( __CLASS__, 'canonical_archive' ), 20, 2 );
		}
		if ( $settings->on( 'woo.variations_meta', true ) ) {
			add_filter( 'hoosh_seo_title_templates', array( __CLASS__, 'title_vars' ), 20 );
		}
	}

	/**
	 * Front-end only hooks.
	 */
	public function bootstrap_front() {
		if ( ! self::available() ) {
			return;
		}
		if ( \hoosh_seo()->settings->on( 'woo.hide_wc_notices_seo', true ) ) {
			add_action( 'wp_head', array( __CLASS__, 'hide_notices' ), 1 );
		}
		if ( \hoosh_seo()->settings->on( 'woo.image_alt_from_title', true ) ) {
			add_filter( 'woocommerce_product_get_image', array( __CLASS__, 'product_image_alt' ), 20, 2 );
		}
	}

	/**
	 * Notices are noise for crawlers; drop them for bots only.
	 */
	public static function hide_notices() {
		if ( ! Helpers::detect_bot() ) {
			return;
		}
		remove_action( 'wp_head', 'woocommerce_output_all_notices', 10 );
		add_filter( 'woocommerce_show_page_title', '__return_true' );
	}

	/**
	 * Product image alt from title when empty.
	 *
	 * @param array $image Image data.
	 * @param mixed $product Product.
	 * @return array
	 */
	public static function product_image_alt( $image, $product = null ) {
		if ( ! is_array( $image ) ) {
			return $image;
		}
		if ( ! empty( $image['alt'] ) || ! $product ) {
			return $image;
		}
		$image['alt'] = mb_substr( wp_strip_all_tags( (string) $product->get_name() ), 0, 120 );
		return $image;
	}

	/**
	 * Fields shown in the general tab.
	 */
	public static function render_fields() {
		global $post;
		$post_id = $post ? (int) $post->ID : 0;
		$fields  = self::field_defs();

		echo '<div class="options_group" id="hoosh-woo-fields">';
		foreach ( $fields as $key => $def ) {
			if ( ! \hoosh_seo()->settings->on( 'woo.' . $key . '_field', true ) && 'brand' !== $key ) {
				continue;
			}
			$value = 'brand' === $key
				? self::brand_of( $post_id )
				: (string) get_post_meta( $post_id, '_hs_' . $key, true );
			?>
			<p class="form-field">
				<label for="hoosh_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $def['label'] ); ?></label>
				<?php if ( 'brand' === $key ) : ?>
					<select id="hoosh_brand" name="hoosh_brand">
						<option value=""><?php esc_html_e( '— بدون برند —', 'hoosh-seo' ); ?></option>
						<?php foreach ( self::brand_terms() as $term ) : ?>
							<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $value, $term->term_id ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="woocommerce-help-tip" data-tip="<?php esc_attr_e( 'برند در اسکیمای Product و در فیلترهای فروشگاه استفاده می‌شود.', 'hoosh-seo' ); ?>"></span>
				<?php else : ?>
					<input type="text" id="hoosh_<?php echo esc_attr( $key ); ?>" name="hoosh_<?php echo esc_attr( $key ); ?>"
						value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $def['placeholder'] ); ?>" />
					<span class="description"><?php echo esc_html( $def['help'] ); ?></span>
				<?php endif; ?>
			</p>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Field definitions.
	 *
	 * @return array
	 */
	public static function field_defs() {
		return array(
			'gtin13' => array(
				'label'       => __( 'شناسه کالا / GTIN-13', 'hoosh-seo' ),
				'placeholder' => '6261234567890',
				'help'        => __( '۱۳ رقم؛ برای گوگل شاپینگ و اسکیما ضروری است.', 'hoosh-seo' ),
			),
			'gtin8'  => array(
				'label'       => __( 'GTIN-8', 'hoosh-seo' ),
				'placeholder' => '',
				'help'        => __( 'اگر شناسه کوتاه دارید.', 'hoosh-seo' ),
			),
			'gtin14' => array(
				'label'       => __( 'GTIN-14 (بسته)', 'hoosh-seo' ),
				'placeholder' => '',
				'help'        => __( 'برای بسته‌های عمده.', 'hoosh-seo' ),
			),
			'mpn'    => array(
				'label'       => __( 'MPN (کد سازنده)', 'hoosh-seo' ),
				'placeholder' => '',
				'help'        => __( 'Manufacturer Part Number.', 'hoosh-seo' ),
			),
			'isbn'   => array(
				'label'       => __( 'ISBN', 'hoosh-seo' ),
				'placeholder' => '',
				'help'        => __( 'فقط برای کتاب.', 'hoosh-seo' ),
			),
			'brand'  => array(
				'label' => __( 'برند', 'hoosh-seo' ),
				'help'  => __( 'از taxonomy برند انتخاب کنید.', 'hoosh-seo' ),
			),
		);
	}

	/**
	 * Persist the fields.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save_fields( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		foreach ( array( 'gtin13', 'gtin8', 'gtin14', 'mpn', 'isbn' ) as $key ) {
			if ( ! isset( $_POST[ 'hoosh_' . $key ] ) ) { // phpcs:ignore
				continue;
			}
			$value = trim( (string) wp_unslash( $_POST[ 'hoosh_' . $key ] ) ); // phpcs:ignore
			$value = preg_replace( '/[^\w\-]/u', '', $value );
			if ( '' === $value ) {
				delete_post_meta( $post_id, '_hs_' . $key );
			} else {
				update_post_meta( $post_id, '_hs_' . $key, mb_substr( $value, 0, 40 ) );
			}
		}

		$brand = isset( $_POST['hoosh_brand'] ) ? absint( wp_unslash( $_POST['hoosh_brand'] ) ) : 0; // phpcs:ignore
		if ( $brand ) {
			$tax = self::brand_taxonomy();
			if ( $tax && taxonomy_exists( $tax ) ) {
				wp_set_object_terms( $post_id, array( $brand ), $tax, false );
			}
			$term = get_term( $brand, $tax );
			if ( $term && ! is_wp_error( $term ) ) {
				update_post_meta( $post_id, '_hs_brand', (string) $term->name );
			}
		}
		Helpers::cache_flush( 'hoosh_pages_' . $post_id );
	}

	/**
	 * Brand taxonomy in use (Woo Brands or the plugin's own).
	 *
	 * @return string
	 */
	public static function brand_taxonomy() {
		$settings = \hoosh_seo()->settings;
		$candidates = array_filter( array( (string) $settings->get( 'woo.brand_taxonomy', 'product_brand' ), 'pa_brand', 'brand' ) );
		foreach ( $candidates as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				return $tax;
			}
		}
		return '';
	}

	/**
	 * Brand terms for the select.
	 *
	 * @return array
	 */
	public static function brand_terms() {
		$tax = self::brand_taxonomy();
		if ( ! $tax ) {
			return array();
		}
		return (array) get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'number'     => 300,
				'orderby'    => 'name',
			)
		);
	}

	/**
	 * Brand id of a product.
	 *
	 * @param int $post_id Product ID.
	 * @return int
	 */
	public static function brand_of( $post_id ) {
		$tax = self::brand_taxonomy();
		if ( ! $tax ) {
			return 0;
		}
		$terms = wp_get_post_terms( (int) $post_id, $tax, array( 'fields' => 'ids' ) );
		return ( $terms && ! is_wp_error( $terms ) ) ? (int) reset( $terms ) : 0;
	}

	/**
	 * Cart / checkout / account / wishlist / product-add-to-cart noindex rules.
	 *
	 * @param array $robots Robots.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	public static function shop_robots( $robots, $post_id = 0 ) {
		$settings = \hoosh_seo()->settings;
		$checks   = array(
			'cart'      => 'woo.noindex_cart',
			'checkout'  => 'woo.noindex_checkout',
			'myaccount' => 'woo.noindex_account',
			'wishlist'  => 'woo.noindex_wishlist',
		);
		foreach ( $checks as $page => $key ) {
			if ( function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( $page ) === (int) $post_id && $settings->on( $key, true ) ) {
				$robots['index']  = false;
				$robots['follow'] = 'checkout' === $page ? false : true;
				$robots['source'] = 'woo';
			}
		}
		if ( $settings->on( 'woo.noindex_authorized', true ) && is_user_logged_in() && function_exists( 'is_account_page' ) && is_account_page() ) {
			$robots['index'] = false;
		}
		// Term archives of the brand taxonomy: index them only if they carry copy.
		$tax = self::brand_taxonomy();
		if ( $tax && is_tax( $tax ) && ! $settings->get( 'woo.brand_index', true ) ) {
			$robots['index'] = false;
		}
		return $robots;
	}

	/**
	 * Schema tweaks driven by the woo.* settings.
	 *
	 * @param array $graph Graph.
	 * @return array
	 */
	public static function schema_tweaks( $graph ) {
		$settings = \hoosh_seo()->settings;
		if ( ! is_singular( 'product' ) ) {
			return $graph;
		}
		$post_id = (int) get_queried_object_id();
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
		if ( ! $product ) {
			return $graph;
		}

		foreach ( $graph as $index => $node ) {
			if ( ! is_array( $node ) || ! in_array( 'Product', (array) ( $node['@type'] ?? array() ), true ) ) {
				continue;
			}
			if ( ! $settings->on( 'woo.rating_in_schema', true ) ) {
				unset( $graph[ $index ]['aggregateRating'] );
			}
			if ( ! $settings->on( 'woo.stock_in_schema', true ) && isset( $graph[ $index ]['offers'] ) ) {
				unset( $graph[ $index ]['offers']['availability'] );
			}
			if ( $settings->on( 'woo.shipping_in_schema', false ) ) {
				$graph[ $index ]['shippingDetails'] = array(
					'@type'        => 'OfferShippingDetails',
					'shippingRate' => array(
						'@type'         => 'ShippingRateSettings',
						'label'         => __( 'ارسال', 'hoosh-seo' ),
						'shippingDestination' => array( '@type' => 'DefinedRegion', 'addressCountry' => 'IR' ),
					),
				);
			}
			$return = trim( (string) $settings->get( 'woo.return_policy', '' ) );
			if ( $return ) {
				$graph[ $index ]['hasMerchantReturnPolicy'] = array(
					'@type'             => 'MerchantReturnPolicy',
					'applicableCountry' => 'IR',
					'returnFees'        => 'https://schema.org/FreeReturn',
					'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
					'returnPolicyCountry' => 'IR',
					'description'       => mb_substr( wp_strip_all_tags( $return ), 0, 400 ),
				);
			} else {
				unset( $graph[ $index ]['hasMerchantReturnPolicy'] );
			}
			unset( $index, $node );
			break;
		}
		return $graph;
	}

	/**
	 * Strip `?ver=` from Woo assets.
	 *
	 * @param string $src Source.
	 * @return string
	 */
	public static function strip_version( $src ) {
		if ( is_string( $src ) && false !== strpos( $src, 'woocommerce' ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/**
	 * Short description beats the excerpt for products.
	 *
	 * @param string $description Description.
	 * @param int    $post_id     Post ID.
	 * @return string
	 */
	public static function description_source( $description, $post_id ) {
		if ( 'product' !== get_post_type( (int) $post_id ) ) {
			return $description;
		}
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $post_id ) : null;
		if ( ! $product ) {
			return $description;
		}
		$short = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );
		if ( $short && Helpers::strlen( $short ) > 40 ) {
			return mb_substr( $short, 0, 160 );
		}
		return $description;
	}

	/**
	 * Paged shop archives canonicalise to page 1.
	 *
	 * @param string $url     Canonical.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function canonical_archive( $url, $post_id = 0 ) {
		if ( $post_id || ! is_archive() ) {
			return $url;
		}
		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 2 ) {
			return $url;
		}
		return preg_replace( '#/page/\d+/?$#', '/', (string) $url );
	}

	/**
	 * Extra title variables for variations.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function title_vars( $vars ) {
		$vars['variation_attrs'] = __( 'ویژگی‌های گونه', 'hoosh-seo' );
		$vars['stock_status']    = __( 'وضعیت موجودی', 'hoosh-seo' );
		$vars['brand']           = __( 'برند', 'hoosh-seo' );
		$vars['sku']             = 'SKU';
		return $vars;
	}

	/**
	 * Shop health summary.
	 *
	 * @return array
	 */
	public static function summary() {
		$out = array(
			'available'    => class_exists( 'WooCommerce' ),
			'enabled'      => \hoosh_seo()->settings->on( 'woo.enabled', true ),
			'products'     => 0,
			'no_gtin'      => 0,
			'no_brand'     => 0,
			'no_short'     => 0,
			'no_image'     => 0,
			'no_alt'       => 0,
			'out_of_stock' => 0,
			'hidden'       => 0,
			'catalog'      => array(),
			'brand_taxonomy' => self::brand_taxonomy(),
			'score'        => 100,
		);
		if ( ! $out['available'] ) {
			$out['message'] = __( 'ووکامرس روی این سایت فعال نیست؛ این تب تا نصب ووکامرس غیرفعال است.', 'hoosh-seo' );
			return $out;
		}

		global $wpdb;
		$out['products'] = (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
		);
		$out['no_gtin']  = (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ('_hs_gtin13','_hs_gtin8','_hs_gtin14','_hs_isbn') AND m.meta_value <> '')"
		);
		$out['no_brand'] = self::count_no_brand();
		$out['no_short'] = (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND ( p.post_excerpt = '' OR p.post_excerpt IS NULL )"
		);
		$out['no_image'] = (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_thumbnail_id')"
		);
		$out['hidden']   = (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_hs_robots' AND m.meta_value LIKE '%noindex%')"
		);

		$audit = Images::missing( array( 'per_page' => 1, 'page' => 1, 'type' => 'all' ) );
		$out['no_alt'] = (int) ( $audit['total'] ?? 0 );


		$total = max( 1, $out['products'] );
		$penalty = ( $out['no_gtin'] * 1.2 + $out['no_brand'] * 0.8 + $out['no_image'] * 1.5 + $out['no_short'] * 0.4 ) / $total * 100;
		$out['score'] = (int) max( 0, min( 100, round( 100 - $penalty ) ) );

		$out['catalog'] = self::catalog_health();

		return $out;
	}

	/**
	 * Products without a brand term.
	 *
	 * @return int
	 */
	public static function count_no_brand() {
		global $wpdb;
		$tax = self::brand_taxonomy();
		if ( ! $tax ) {
			return 0;
		}
		return (int) $wpdb->get_var( // phpcs:ignore
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} tr
					JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tr.object_id = p.ID AND tt.taxonomy = '{$tax}'
			   )"
		);
	}

	/**
	 * Catalog-wide checks worth automating.
	 *
	 * @return array
	 */
	public static function catalog_health() {
		global $wpdb;
		$rows = array();
		$totals = array(
			'draft'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='draft'" ), // phpcs:ignore
			'pending'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='pending'" ), // phpcs:ignore
			'onsale'   => 0,
			'variable' => 0,
			'no_price' => (int) $wpdb->get_var( // phpcs:ignore
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_price' AND m.meta_value = ''
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'"
			),
		);
		foreach ( $totals as $key => $value ) {
			$rows[] = array(
				'label' => self::catalog_label( $key ),
				'count' => (int) $value,
				'state' => $value ? 'warning' : 'ok',
			);
		}
		return $rows;
	}

	/**
	 * Label.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function catalog_label( $key ) {
		$map = array(
			'draft'    => __( 'محصول پیش‌نویس', 'hoosh-seo' ),
			'pending'  => __( 'در انتظار بررسی', 'hoosh-seo' ),
			'onsale'   => __( 'تخفیدار', 'hoosh-seo' ),
			'variable' => __( 'متغیر', 'hoosh-seo' ),
			'no_price' => __( 'بدون قیمت', 'hoosh-seo' ),
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : $key;
	}

	/**
	 * One-click fixes.
	 *
	 * @param int    $post_id Product ID.
	 * @param string $what    Fix key.
	 * @return array
	 */
	public static function fix( $post_id, $what ) {
		$post_id = (int) $post_id;
		$what    = sanitize_key( (string) $what );
		if ( ! $post_id || ! function_exists( 'wc_get_product' ) ) {
			return array( 'ok' => false, 'message' => __( 'ووکامرس در دسترس نیست.', 'hoosh-seo' ) );
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return array( 'ok' => false, 'message' => __( 'محصول پیدا نشد.', 'hoosh-seo' ) );
		}

		switch ( $what ) {
			case 'brand':
				$guess = self::guess_brand( $post_id );
				if ( ! $guess ) {
					return array( 'ok' => false, 'message' => __( 'برندی برای این عنوان پیدا نشد.', 'hoosh-seo' ) );
				}
				$tax = self::brand_taxonomy();
				if ( ! $tax ) {
					return array( 'ok' => false, 'message' => __( 'taxonomy برند (product_brand یا pa_brand) وجود ندارد.', 'hoosh-seo' ) );
				}
				wp_set_object_terms( $post_id, array( (int) $guess['term_id'] ), $tax, false );
				update_post_meta( $post_id, '_hs_brand', (string) $guess['name'] );
				return array(
					'ok'     => true,
					'brand'  => (string) $guess['name'],
					'message' => sprintf( /* translators: %s brand */ __( 'برند «%s» اعمال شد.', 'hoosh-seo' ), $guess['name'] ),
				);

			case 'alt':
				$images  = array_merge( array( $product->get_image_id() ), (array) $product->get_gallery_image_ids() );
				$changed = 0;
				foreach ( array_filter( array_map( 'absint', $images ) ) as $attachment ) {
					if ( (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true ) ) {
						continue;
					}
					$alt = mb_substr( wp_strip_all_tags( (string) $product->get_name() ), 0, 120 );
					if ( '' === $alt ) {
						continue;
					}
					update_post_meta( $attachment, '_wp_attachment_image_alt', $alt );
					$changed++;
				}
				return array(
					'ok'      => (bool) $changed,
					'changed' => $changed,
					'message' => $changed
						? sprintf( /* translators: %d count */ __( 'برای %d تصویر جایگزین نوشته شد.', 'hoosh-seo' ), $changed )
						: __( 'تصویرها alt داشتند.', 'hoosh-seo' ),
				);

			case 'excerpt':
				$short = trim( wp_strip_all_tags( (string) $product->get_short_description() ) );
				if ( ! $short ) {
					return array( 'ok' => false, 'message' => __( 'توضیح کوتاه محصول خالی است؛ اول آن را بنویسید.', 'hoosh-seo' ) );
				}
				$excerpt = mb_substr( $short, 0, 160 );
				wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_excerpt' => $excerpt ) ) );
				return array( 'ok' => true, 'message' => __( 'چکیده از توضیح کوتاه ساخته شد.', 'hoosh-seo' ) );

			case 'meta':
				$result = \HooshSEO\AI\Tasks::run( 'product', $post_id, array( 'auto' => true ), true );
				if ( empty( $result['ok'] ) ) {
					return array( 'ok' => false, 'message' => (string) ( $result['message'] ?? __( 'هوش مصنوعی آماده نیست.', 'hoosh-seo' ) ) );
				}
				$data    = (array) ( $result['data'] ?? array() );
				$payload = array(
					'title'       => mb_substr( (string) ( $data['title'] ?? get_the_title( $post_id ) ), 0, 120 ),
					'description' => mb_substr( (string) ( $data['description'] ?? '' ), 0, 180 ),
				);
				if ( ! empty( $data['keywords'] ) ) {
					$payload['keywords'] = (array) $data['keywords'];
				}
				Meta::save( $post_id, $payload );
				return array( 'ok' => true, 'applied' => array_keys( $payload ), 'message' => __( 'متا ساخته شد.', 'hoosh-seo' ) );

			case 'schema':
				update_post_meta( $post_id, '_hs_schema_types', array( 'Product', 'Offer', 'BreadcrumbList' ) );
				Helpers::cache_flush_all();
				return array( 'ok' => true, 'message' => __( 'اسکیمای محصول برای این صفحه فعال شد.', 'hoosh-seo' ) );

			case 'indexnow':
				$url = (string) get_permalink( $post_id );
				$out = IndexManager::indexnow( array( $url ) );
				return array(
					'ok'      => ! empty( $out['ok'] ),
					'message' => ! empty( $out['ok'] ) ? __( 'آدرس محصول به IndexNow داده شد.', 'hoosh-seo' ) : __( 'ارسال نشد؛ کلید IndexNow را بررسی کنید.', 'hoosh-seo' ),
				);
		}

		return array( 'ok' => false, 'message' => __( 'اصلاح ناشناخته.', 'hoosh-seo' ) );
	}

	/**
	 * Guess the brand from the title against existing terms.
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	public static function guess_brand( $post_id ) {
		$title = Helpers::normalize_fa( (string) get_the_title( (int) $post_id ) );
		foreach ( self::brand_terms() as $term ) {
			$name = Helpers::normalize_fa( (string) $term->name );
			if ( strlen( $name ) < 2 ) {
				continue;
			}
			if ( false !== mb_strpos( $title, $name ) ) {
				return array( 'term_id' => (int) $term->term_id, 'name' => (string) $term->name );
			}
		}
		return array();
	}

	/**
	 * Bulk pass over the catalog.
	 *
	 * @param string $what Fix key.
	 * @param array  $args {limit, dry}.
	 * @return array
	 */
	public static function bulk( $what, $args = array() ) {
		$args = wp_parse_args( (array) $args, array( 'limit' => 50, 'dry' => false ) );
		$what = sanitize_key( (string) $what );

		global $wpdb;
		$limit = max( 1, min( 500, (int) $args['limit'] ) );
		$ids   = (array) $wpdb->get_col( // phpcs:ignore
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID DESC LIMIT %d", $limit )
		);

		$done = 0;
		$changed = 0;
		foreach ( $ids as $id ) {
			if ( ! empty( $args['dry'] ) ) {
				$done++;
				continue;
			}
			$out = self::fix( (int) $id, $what );
			$done++;
			if ( ! empty( $out['changed'] ) || ! empty( $out['ok'] ) ) {
				$changed++;
			}
			if ( time() % 25 === 0 ) {
				wp_defer_term_counting( true );
			}
		}

		return array(
			'ok'      => true,
			'processed' => $done,
			'changed' => $changed,
			'message' => sprintf( /* translators: 1: processed, 2: changed */ __( '%1$d محصول بررسی شد، %2$d مورد اصلاح گردید.', 'hoosh-seo' ), $done, $changed ),
		);
	}
}
