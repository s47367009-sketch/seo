<?php
/**
 * Social meta: Open Graph, Twitter/X cards, Pinterest, plus schema-friendly price tags.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class OpenGraph
 */
final class OpenGraph {

	/**
	 * Singleton.
	 *
	 * @var OpenGraph|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return OpenGraph
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
		add_action( 'wp_head', array( $this, 'print_tags' ), 4 );
		add_action( 'wp_head', array( $this, 'print_oembed_image' ), 5 );
	}

	/**
	 * Emit the social card.
	 */
	public function print_tags() {
		if ( ! hoosh_seo()->settings->get( 'opengraph.enabled', true ) ) {
			return;
		}

		$post_id = is_singular() ? get_queried_object_id() : 0;
		$social  = $post_id ? (array) get_post_meta( $post_id, '_hs_social', true ) : array();
		if ( ! empty( $social['skip'] ) ) {
			return;
		}

		$settings = hoosh_seo()->settings;
		$title    = ! empty( $social['title'] ) ? $social['title'] : Meta::resolve( $post_id ?: 0, 'title' );
		if ( ! $title ) {
			$title = get_bloginfo( 'name' );
		}
		$description = ! empty( $social['description'] ) ? $social['description'] : Meta::resolve( $post_id, 'description' );
		if ( ! $description ) {
			$description = get_bloginfo( 'description' );
		}

		$image = $this->image( $post_id, $social );
		$url   = $post_id ? Meta::canonical( $post_id ) : Meta::current_url();
		$type  = $this->type( $post_id );

		$tags = array(
			'og:site_name'       => $settings->get( 'general.site_name' ) ? $settings->get( 'general.site_name' ) : get_bloginfo( 'name' ),
			'og:locale'          => $this->og_locale(),
			'og:type'            => $type,
			'og:title'           => $title,
			'og:description'     => $description,
			'og:url'             => $url,
			'og:image'           => $image['url'],
			'og:image:width'     => $image['width'],
			'og:image:height'    => $image['height'],
			'og:image:alt'       => $image['alt'],
			'og:image:secure_url' => $image['url'],
			'og:updated_time'    => $post_id ? get_post_modified_time( 'c', true, $post_id ) : current_time( 'c' ),
		);

		if ( $settings->get( 'opengraph.app_id' ) ) {
			$tags['fb:app_id'] = $settings->get( 'opengraph.app_id' );
		}
		if ( $settings->get( 'general.facebook_app_id' ) ) {
			$tags['fb:admins'] = $settings->get( 'general.facebook_app_id' );
		}

		if ( 'article' === $type && $post_id ) {
			$tags['article:published_time'] = get_the_date( 'c', $post_id );
			$tags['article:modified_time']  = get_the_modified_date( 'c', $post_id );
			$tags['article:author']         = get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) );
			foreach ( $this->article_sections( $post_id ) as $section ) {
				echo '<meta property="article:section" content="' . esc_attr( $section ) . '">' . "\n";
			}
			$tags['article:tag'] = $this->article_tags( $post_id );
		}

		if ( 'product' === $type && function_exists( 'wc_get_product' ) ) {
			$tags += $this->product_tags( $post_id );
		}

		foreach ( $tags as $property => $content ) {
			if ( '' === $content || null === $content ) {
				continue;
			}
			if ( is_array( $content ) ) {
				foreach ( $content as $one ) {
					echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $one ) . '">' . "\n";
				}
				continue;
			}
			echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( (string) $content ) . '">' . "\n";
		}

		// Twitter / X card.
		if ( $settings->get( 'opengraph.twitter', true ) ) {
			$card = $settings->get( 'general.twitter_card', 'summary_large_image' );
			echo '<meta name="twitter:card" content="' . esc_attr( $card ) . '">' . "\n";
			if ( $settings->get( 'general.twitter_site' ) ) {
				echo '<meta name="twitter:site" content="' . esc_attr( $this->handle( $settings->get( 'general.twitter_site' ) ) ) . '">' . "\n";
				echo '<meta name="twitter:creator" content="' . esc_attr( $this->handle( $settings->get( 'general.twitter_site' ) ) ) . '">' . "\n";
			}
			echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
			echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
			if ( $image['url'] ) {
				echo '<meta name="twitter:image" content="' . esc_url( $image['url'] ) . '">' . "\n";
				echo '<meta name="twitter:image:alt" content="' . esc_attr( $image['alt'] ) . '">' . "\n";
			}
		}

		// Pinterest + WhatsApp/Telegram fallbacks.
		if ( $image['url'] ) {
			echo '<meta name="pinterest:richpin:picture" content="' . esc_url( $image['url'] ) . '">' . "\n";
			echo '<link rel="image_src" href="' . esc_url( $image['url'] ) . '">' . "\n";
		}
	}

	/**
	 * og:image: choose per-post, then featured, then content image, then the default.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $social  Social overrides.
	 * @return array
	 */
	public function image( $post_id = 0, $social = array() ) {
		$url = '';
		$width = 1200;
		$height = 630;
		$alt  = get_bloginfo( 'name' );

		if ( ! empty( $social['image_id'] ) ) {
			$src = wp_get_attachment_image_src( (int) $social['image_id'], 'full' );
			if ( $src ) {
				list( $url, $width, $height ) = $src;
				$alt = trim( (string) get_post_meta( (int) $social['image_id'], '_wp_attachment_image_alt', true ) );
			}
		}

		if ( '' === $url && $post_id && has_post_thumbnail( $post_id ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
			if ( $src ) {
				list( $url, $width, $height ) = $src;
				$alt = trim( (string) get_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', true ) );
				if ( '' === $alt ) {
					$alt = get_the_title( $post_id );
				}
			}
		}

		if ( '' === $url && $post_id && hoosh_seo()->settings->get( 'opengraph.generate_alt', true ) ) {
			$found = self::first_content_image( $post_id );
			if ( $found ) {
				$url = $found;
			}
		}

		if ( '' === $url ) {
			$default = (int) hoosh_seo()->settings->get( 'opengraph.share_image_id', 0 );
			if ( $default ) {
				$src = wp_get_attachment_image_src( $default, 'full' );
				if ( $src ) {
					list( $url, $width, $height ) = $src;
				}
			}
		}

		if ( '' === $url ) {
			$logo = (int) hoosh_seo()->settings->get( 'general.logo_id', 0 );
			if ( $logo ) {
				$src = wp_get_attachment_image_src( $logo, 'full' );
				if ( $src ) {
					list( $url, $width, $height ) = $src;
				}
			}
		}

		if ( '' === $url ) {
			$fallback = (string) hoosh_seo()->settings->get( 'general.fallback_image', '' );
			if ( $fallback ) {
				$url = Helpers::abs_url( $fallback );
			}
		}

		return array(
			'url'    => $url ? esc_url_raw( $url ) : '',
			'width'  => $width ? (int) $width : 1200,
			'height' => $height ? (int) $height : 630,
			'alt'    => $alt ? wp_strip_all_tags( $alt ) : get_bloginfo( 'name' ),
		);
	}

	/**
	 * First <img> inside post content (also Elementor-friendly via the raw HTML).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function first_content_image( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$content = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			$src = $m[1];
			if ( 0 === strpos( $src, 'data:' ) ) {
				return '';
			}
			return esc_url_raw( $src );
		}
		return '';
	}

	/**
	 * Open Graph type for the request.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected function type( $post_id = 0 ) {
		if ( ! $post_id ) {
			return 'website';
		}
		$post_type = get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			return 'product';
		}
		if ( in_array( $post_type, (array) apply_filters( 'hoosh_seo_article_types', array( 'post' ) ), true ) ) {
			return 'article';
		}
		return 'website';
	}

	/**
	 * Categories as article:section.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected function article_sections( $post_id ) {
		$cats = get_the_category( $post_id );
		$out  = array();
		foreach ( (array) $cats as $cat ) {
			$out[] = $cat->name;
		}
		return array_slice( $out, 0, 3 );
	}

	/**
	 * Tags as article:tag.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected function article_tags( $post_id ) {
		$tags = get_the_tags( $post_id );
		$out  = array();
		foreach ( (array) $tags as $tag ) {
			$out[] = $tag->name;
		}
		if ( ! $out ) {
			$out = Meta::focus_keywords( $post_id );
		}
		return array_slice( $out, 0, 6 );
	}

	/**
	 * WooCommerce product extras (price, availability, retailer).
	 *
	 * @param int $post_id Product ID.
	 * @return array
	 */
	protected function product_tags( $post_id ) {
		$product = \wc_get_product( $post_id );
		if ( ! $product ) {
			return array();
		}
		$currency = get_woocommerce_currency();
		$tags     = array(
			'product:price:amount'   => (string) $product->get_price(),
			'product:price:currency' => $currency,
			'product:retailer_item_id' => (string) ( $product->get_sku() ? $product->get_sku() : $product->get_id() ),
			'product:condition'      => 'new',
			'product:availability'   => $product->is_in_stock() ? 'in stock' : 'out of stock',
		);
		if ( $product->is_on_sale() && $product->get_regular_price() ) {
			$tags['product:price:original_amount'] = (string) $product->get_regular_price();
		}
		$rating = (float) $product->get_average_rating();
		if ( $rating > 0 ) {
			$tags['product:rating']       = (string) $rating;
			$tags['product:rating_count'] = (string) $product->get_review_count();
		}
		return $tags;
	}

	/**
	 * Ensure the featured image is discoverable by social crawlers on attachments.
	 */
	public function print_oembed_image() {
		if ( ! is_singular() || ! current_theme_supports( 'post-thumbnails' ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! has_post_thumbnail( $post_id ) ) {
			return;
		}
		echo '<link rel="og:image:width" content="1200">' . "\n";
	}

	/**
	 * Locale, e.g. fa_IR.
	 *
	 * @return string
	 */
	protected function og_locale() {
		$locale = get_locale();
		return str_replace( '-', '_', $locale );
	}

	/**
	 * Normalise @handle.
	 *
	 * @param string $value Raw.
	 * @return string
	 */
	protected function handle( $value ) {
		$value = ltrim( (string) $value, '@' );
		$value = preg_replace( '/[^A-Za-z0-9_]/', '', $value );
		return $value ? '@' . $value : '';
	}

	/**
	 * Data for the Studio social preview (no scraping needed).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function preview_data( $post_id ) {
		$settings = hoosh_seo()->settings;
		$social   = (array) get_post_meta( $post_id, '_hs_social', true );
		$image    = self::instance()->image( $post_id, $social );
		return array(
			'title'    => ! empty( $social['title'] ) ? $social['title'] : Meta::resolve( $post_id, 'title' ),
			'desc'     => ! empty( $social['description'] ) ? $social['description'] : Meta::resolve( $post_id, 'description' ),
			'url'      => Meta::canonical( $post_id ),
			'site'     => $settings->get( 'general.site_name' ) ? $settings->get( 'general.site_name' ) : get_bloginfo( 'name' ),
			'image'    => $image['url'],
			'imageAlt' => $image['alt'],
			'card'     => $settings->get( 'general.twitter_card', 'summary_large_image' ),
			'handle'   => $settings->get( 'general.twitter_site', '' ),
		);
	}
}
