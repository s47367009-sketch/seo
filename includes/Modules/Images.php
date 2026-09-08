<?php
/**
 * Image SEO: alt/title/caption automation, filename hygiene, EXIF handling,
 * dimension/CLW injection, and a missing-alt work queue.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Class Images
 */
final class Images {

	/**
	 * Singleton.
	 *
	 * @var Images|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Images
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
		$settings = \hoosh_seo()->settings;

		if ( $settings->on( 'images.enabled' ) ) {
			add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'after_upload' ), 20, 2 );
			add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'maybe_strip_meta' ), 20, 1 );
			if ( $settings->on( 'images.alt_taxonomy_fallback' ) ) {
				add_filter( 'get_image_alt', array( __CLASS__, 'filter_alt' ), 5, 2 );
			}
			if ( $settings->on( 'images.rename' ) ) {
				add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'sanitize_file_name_upload' ) );
			}
			if ( $settings->on( 'images.dimensions' ) ) {
				add_filter( 'the_content', array( __CLASS__, 'add_image_dimensions' ), 5 );
			}
			if ( $settings->on( 'images.strip_title_attribute' ) ) {
				add_filter( 'the_content', array( __CLASS__, 'strip_title_attr' ), 9 );
			}
			if ( $settings->on( 'images.serve_webp_fallback' ) && function_exists( 'wp_get_image_editor' ) ) {
				add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'webp_source' ), 10, 3 );
			}
		}
	}

	/**
	 * Called after an upload: fill alt/title/caption and clean the filename.
	 *
	 * @param array $metadata Metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public static function after_upload( $metadata, $attachment_id ) {
		$settings = \hoosh_seo()->settings;
		$post     = get_post( $attachment_id );
		if ( ! $post ) {
			return $metadata;
		}

		$suggest = self::suggest_for( $attachment_id );

		if ( $settings->on( 'images.auto_alt' ) && ! trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ) {
			self::set_alt( $attachment_id, $suggest['alt'] );
		}

		$fields = array();
		if ( $settings->on( 'images.auto_title' ) && ! trim( (string) $post->post_title ) ) {
			$fields['post_title'] = $suggest['title'];
		}
		if ( $settings->on( 'images.auto_caption' ) && ! trim( (string) $post->post_excerpt ) ) {
			$fields['post_excerpt'] = $suggest['caption'];
		}
		if ( $fields ) {
			$fields['ID'] = (int) $attachment_id;
			wp_update_post( wp_slash( $fields ) );
		}

		if ( $settings->on( 'images.strip_exif' ) ) {
			self::strip_exif( $attachment_id );
		}

		// Descriptive filename at upload time.
		if ( $settings->on( 'images.rename_upload' ) ) {
			self::maybe_rename( $attachment_id, $suggest['slug'] );
		}

		/**
		 * Fires after automatic image SEO has run for an upload.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $suggest Suggested values.
		 */
		do_action( 'hoosh_seo_image_processed', (int) $attachment_id, $suggest );

		return $metadata;
	}

	/**
	 * Suggest alt/title/caption for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public static function suggest_for( $attachment_id ) {
		$settings = \hoosh_seo()->settings;
		$post     = get_post( $attachment_id );
		if ( ! $post ) {
			return array( 'alt' => '', 'title' => '', 'caption' => '', 'slug' => '' );
		}

		$filename = pathinfo( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ), PATHINFO_FILENAME );
		$human    = ucwords( str_replace( array( '-', '_' ), ' ', preg_replace( '/\s*\d{6,}.*$/', '', $filename ) ) );

		$parent_id = (int) $post->post_parent;
		$parent    = $parent_id ? get_post( $parent_id ) : null;

		$keyword = '';
		if ( $parent ) {
			$keywords = Meta::focus_keywords( (int) $parent->ID );
			$keyword  = $keywords ? (string) $keywords[0] : '';
		}

		$title = $parent ? get_the_title( (int) $parent->ID ) : $human;
		$mode  = (string) $settings->get( 'images.alt_source', 'auto' );

		$alt = $human;
		switch ( $mode ) {
			case 'keyword':
				$alt = $keyword ?: $human;
				break;
			case 'title':
				$alt = $parent ? get_the_title( (int) $parent->ID ) : $human;
				break;
			case 'filename':
				$alt = $human;
				break;
			case 'caption':
				$alt = trim( (string) $post->post_excerpt ) ?: $human;
				break;
			case 'exif':
				$alt = self::exif_description( $attachment_id ) ?: $human;
				break;
			case 'mixed':
			default:
				$parts = array_filter( array( $keyword, $human ) );
				$alt   = $parts ? implode( ' — ', array_slice( array_unique( $parts ), 0, 2 ) ) : $human;
				break;
		}

		$alt = trim( (string) preg_replace( '/\s+/u', ' ', $alt ) );
		$alt = self::trim_alt( $alt, $settings );

		return array(
			'alt'     => $alt,
			'title'   => $title,
			'caption' => trim( (string) $post->post_content ) ? wp_strip_all_tags( wp_trim_words( $post->post_content, 20 ) ) : $human,
			'slug'    => Helpers::safe_slug( $alt ? $alt : $human, 'auto' ),
		);
	}

	/**
	 * Keep the alt text within sane length limits.
	 *
	 * @param string     $alt      Alt text.
	 * @param \HooshSEO\Settings $settings Settings.
	 * @return string
	 */
	protected static function trim_alt( $alt, $settings ) {
		$max = (int) $settings->get( 'images.alt_max_words', 12 );
		$alt = preg_replace( '/\.(jpe?g|png|gif|webp|avif)$/iu', '', $alt );
		$words = preg_split( '/\s+/u', trim( (string) $alt ) );
		if ( $words && count( $words ) > $max ) {
			$alt = implode( ' ', array_slice( $words, 0, $max ) );
		}
		$alt = trim( (string) $alt, " \t\n\r\0\x0B-–—.,؛،" );
		return mb_substr( $alt, 0, 150 );
	}

	/**
	 * Read a usable description out of EXIF/IPTC.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function exif_description( $attachment_id ) {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file ) {
			return '';
		}
		$path = dirname( (string) get_post( $attachment_id )->guid ) . '/' . basename( (string) $file );
		$path = str_replace( (string) wp_parse_url( content_url(), PHP_URL_PATH ), WP_CONTENT_DIR, $path );
		if ( ! function_exists( 'exif_read_data' ) ) {
			return '';
		}
		foreach ( array( $path, get_attached_file( $attachment_id ) ) as $candidate ) {
			if ( ! $candidate || ! @file_exists( $candidate ) ) {
				continue;
			}
			$data = @exif_read_data( $candidate, 'ANY_TAG', true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			foreach ( array( 'IFD0|ImageDescription', 'IFD0|Copyright', 'COMPUTED|Copyright', 'FILE|Comments' ) as $key ) {
				if ( ! empty( $data[ $key ] ) ) {
					$value = is_array( $data[ $key ] ) ? implode( ' ', $data[ $key ] ) : (string) $data[ $key ];
					$value = trim( preg_replace( '/\s+/u', ' ', $value ) );
					if ( $value && strlen( $value ) < 200 ) {
						return $value;
					}
				}
			}
		}
		return '';
	}

	/**
	 * set_alt
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt Alt text.
	 */
	public static function set_alt( $attachment_id, $alt ) {
		$alt = sanitize_text_field( (string) $alt );
		if ( '' === $alt ) {
			return;
		}
		update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', $alt );
	}

	/**
	 * get_image_alt filter: fill empty alts on the fly.
	 *
	 * @param string $alt Current alt.
	 * @param int    $id Attachment ID.
	 * @return string
	 */
	public static function filter_alt( $alt, $id ) {
		if ( trim( (string) $alt ) ) {
			return $alt;
		}
		if ( ! \hoosh_seo()->settings->on( 'images.alt_on_render' ) ) {
			return $alt;
		}
		$suggest = self::suggest_for( (int) $id );
		return $suggest['alt'];
	}

	/**
	 * Optionally drop EXIF from generated sizes.
	 *
	 * @param array $sizes Sizes.
	 * @return array
	 */
	public static function maybe_strip_meta( $sizes ) {
		if ( \hoosh_seo()->settings->on( 'images.strip_exif_sizes' ) ) {
			foreach ( (array) $sizes as $name => $data ) {
				$sizes[ $name ]['clear-meta'] = true;
			}
		}
		return $sizes;
	}

	/**
	 * Remove GPS/EXIF from the original file.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function strip_exif( $attachment_id ) {
		$file = get_attached_file( (int) $attachment_id );
		if ( ! $file || ! file_exists( $file ) || ! function_exists( 'imagecreatefromstring' ) ) {
			return false;
		}
		$raw = @file_get_contents( $file ); // phpcs:ignore
		if ( ! $raw ) {
			return false;
		}
		$image = @imagecreatefromstring( $raw ); // phpcs:ignore
		if ( ! $image ) {
			return false;
		}
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$out = false;
		if ( 'png' === $ext ) {
			$out = imagepng( $image, $file );
		} elseif ( 'gif' === $ext ) {
			$out = imagegif( $image, $file );
		} elseif ( 'webp' === $ext && function_exists( 'imagewebp' ) ) {
			$out = imagewebp( $image, $file, 85 );
		} else {
			$out = imagejpeg( $image, $file, (int) \hoosh_seo()->settings->get( 'images.quality', 85 ) );
		}
		imagedestroy( $image );

		if ( $out ) {
			$metadata = wp_generate_attachment_metadata( (int) $attachment_id, $file );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( (int) $attachment_id, $metadata );
			}
		}
		return (bool) $out;
	}

	/**
	 * Upload pre-filter: clean the incoming filename.
	 *
	 * @param array $file File.
	 * @return array
	 */
	public static function sanitize_file_name_upload( $file ) {
		if ( empty( $file['name'] ) ) {
			return $file;
		}
		$ext        = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		$base       = pathinfo( $file['name'], PATHINFO_FILENAME );
		$settings   = \hoosh_seo()->settings;
		$clean      = Helpers::safe_slug( $base, 'auto' );
		if ( $settings->on( 'images.rename_strip_hash' ) ) {
			$clean = preg_replace( '/[-_](\d{8}|\d{10,})(-\d+x\d+)?$/u', '', $clean );
		}
		if ( $clean && $clean !== $base ) {
			$file['name'] = $clean . ( $ext ? '.' . $ext : '' );
		}
		return $file;
	}

	/**
	 * Rename an existing attachment file, updating the meta and its content usage.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $slug          New slug (no extension).
	 * @return array
	 */
	public static function maybe_rename( $attachment_id, $slug ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'images.rename' ) || '' === $slug ) {
			return array( 'ok' => false, 'message' => __( 'تغییر نام غیرفعال است.', 'hoosh-seo' ) );
		}

		$attachment_id = (int) $attachment_id;
		$file          = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array( 'ok' => false, 'message' => __( 'فایل روی هاست پیدا نشد.', 'hoosh-seo' ) );
		}

		$dir  = dirname( $file );
		$ext  = pathinfo( $file, PATHINFO_EXTENSION );
		$name = preg_replace( '/[^a-z0-9_\x{0600}-\x{06FF}\-]/iu', '', (string) $slug );
		$name = trim( (string) preg_replace( '/[-_]{2,}/u', '-', $name ), '-_' );
		if ( '' === $name ) {
			return array( 'ok' => false, 'message' => __( 'نام جدید قابل استفاده نبود.', 'hoosh-seo' ) );
		}
		if ( $settings->on( 'images.rename_suffix' ) ) {
			$name .= '-' . mysql2date( 'Ymd', get_post_field( 'post_date', $attachment_id ), false );
		}

		$new = $dir . '/' . $name . '.' . $ext;
		if ( $new === $file ) {
			return array( 'ok' => false, 'message' => __( 'نام پرونده از قبل درست است.', 'hoosh-seo' ) );
		}

		$unique = $new;
		$i      = 1;
		while ( file_exists( $unique ) && $i < 30 ) {
			$unique = $dir . '/' . $name . '-' . $i . '.' . $ext;
			$i++;
		}
		if ( file_exists( $unique ) ) {
			return array( 'ok' => false, 'message' => __( 'هم‌نام موجود است؛ نام دیگری انتخاب کنید.', 'hoosh-seo' ) );
		}

		if ( ! @rename( $file, $unique ) ) { // phpcs:ignore
			return array( 'ok' => false, 'message' => __( 'اجازه تغییر نام فایل ندارید (مجوز پوشه uploads).', 'hoosh-seo' ) );
		}

		$uploads  = wp_get_upload_dir();
		$relative = $file;
		if ( 0 === strpos( wp_normalize_path( $unique ), wp_normalize_path( $uploads['basedir'] ) ) ) {
			$relative = ltrim( str_replace( wp_normalize_path( $uploads['basedir'] ), '', wp_normalize_path( $unique ) ), '/\\' );
		}
		update_post_meta( $attachment_id, '_wp_attached_file', $relative );

		$metadata = wp_generate_attachment_metadata( $attachment_id, $unique );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		$old_url = wp_get_attachment_url( $attachment_id );
		update_post_meta( $attachment_id, '_hoosh_old_url', $old_url );

		// Redirect the old file URL if the redirects module is on.
		if ( $relative && \hoosh_seo()->settings->on( 'images.redirect_old' ) && class_exists( __NAMESPACE__ . '\\Redirects' ) ) {
			Redirects::create(
				array(
					'source'     => Helpers::rel_url( (string) $old_url ),
					'target'     => Helpers::rel_url( wp_get_attachment_url( $attachment_id ) ),
					'type'       => 301,
					'notes'      => __( 'خودکار: تغییر نام تصویر', 'hoosh-seo' ),
					'group_name' => 'images',
				)
			);
		}

		Audit::log( 'images', 'rename', array( 'attachment_id' => $attachment_id, 'from' => $file, 'to' => $unique ) );

		return array(
			'ok'    => true,
			'url'   => wp_get_attachment_url( $attachment_id ),
			'file'  => $relative,
			'message' => __( 'نام فایل عوض شد و آدرس‌های قدیمی هدایت شدند.', 'hoosh-seo' ),
		);
	}

	/**
	 * Inject width/height for images in content (CLS win).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function add_image_dimensions( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '<img' ) ) {
			return $content;
		}
		$content = preg_replace_callback(
			'/<img\b([^>]*)>/i',
			array( __CLASS__, 'dimension_tag' ),
			$content
		);
		return $content;
	}

	/**
	 * One tag rewritten with dimensions if missing.
	 *
	 * @param array $m Match.
	 * @return string
	 */
	public static function dimension_tag( $m ) {
		$attrs = $m[1];
		if ( preg_match( '/\swidth\s*=/i', $attrs ) && preg_match( '/\sheight\s*=/i', $attrs ) ) {
			return $m[0];
		}
		$src = '';
		if ( preg_match( '/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $s ) ) {
			$src = $s[1];
		}
		if ( ! $src ) {
			return $m[0];
		}
		$sizes = self::size_from_url( $src );
		if ( ! $sizes ) {
			return $m[0];
		}
		$extra = '';
		if ( ! preg_match( '/\swidth\s*=/i', $attrs ) ) {
			$extra .= ' width="' . (int) $sizes[0] . '"';
		}
		if ( ! preg_match( '/\sheight\s*=/i', $attrs ) ) {
			$extra .= ' height="' . (int) $sizes[1] . '"';
		}
		if ( ! preg_match( '/\sloading\s*=/i', $attrs ) && \hoosh_seo()->settings->on( 'images.lazy' ) ) {
			$extra .= ' loading="lazy"';
		}
		if ( ! preg_match( '/\sdecoding\s*=/i', $attrs ) ) {
			$extra .= ' decoding="async"';
		}
		return '<img' . $attrs . $extra . '>';
	}

	/**
	 * Remove title attributes from inline images (Google ignores them, it adds bytes).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function strip_title_attr( $content ) {
		if ( ! is_string( $content ) || false === stripos( $content, '<img' ) ) {
			return $content;
		}
		return preg_replace( '/(<img\b[^>]*?)\stitle\s*=\s*("[^"]*"|\'[^\']*\')/i', '$1', $content );
	}

	/**
	 * Resolve pixel size for an image URL (from -WxH suffix or the real file).
	 *
	 * @param string $src Image URL.
	 * @return array|false
	 */
	public static function size_from_url( $src ) {
		if ( preg_match( '/-(\d+)x(\d+)\.(jpe?g|png|gif|webp|avif)$/i', $src, $m ) ) {
			return array( (int) $m[1], (int) $m[2] );
		}
		$file = Helpers::abs_url( $src );
		static $cache = array();
		if ( isset( $cache[ $file ] ) ) {
			return $cache[ $file ];
		}
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );
		$path    = wp_normalize_path( (string) ( isset( $uploads['baseurl'] ) ? str_replace( $uploads['baseurl'], $base, $file ) : '' ) );
		if ( $path && @file_exists( $path ) ) { // phpcs:ignore
			$size = @getimagesize( $path ); // phpcs:ignore
			if ( $size ) {
				$cache[ $file ] = array( (int) $size[0], (int) $size[1] );
				return $cache[ $file ];
			}
		}
		$cache[ $file ] = false;
		return false;
	}

	/**
	 * Add a WebP source when the file exists (progressive enhancement).
	 *
	 * @param array      $attrs Attributes.
	 * @param \WP_Post   $image Image.
	 * @param string     $size Size.
	 * @return array
	 */
	public static function webp_source( $attrs, $image, $size ) {
		unset( $size );
		if ( ! is_array( $attrs ) || empty( $attrs['src'] ) ) {
			return $attrs;
		}
		$candidate = preg_replace( '/\.(jpe?g|png)$/i', '.webp', (string) $attrs['src'] );
		if ( $candidate !== $attrs['src'] ) {
			$uploads = wp_get_upload_dir();
			$file    = str_replace( $uploads['baseurl'], $uploads['basedir'], (string) $candidate );
			if ( @file_exists( $file ) ) { // phpcs:ignore
				$attrs['data-hoosh-webp'] = $candidate;
			}
		}
		unset( $image );
		return $attrs;
	}

	/**
	 * Called after a post is saved: give the featured image (and unlabelled
	 * attachments of this post) an alt text if they have none.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function maybe_fill_alt( $post_id ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'images.enabled' ) || ! $settings->on( 'images.auto_alt' ) ) {
			return 0;
		}
		$post_id = (int) $post_id;
		$done    = 0;

		if ( has_post_thumbnail( $post_id ) ) {
			$image_id = get_post_thumbnail_id( $post_id );
			if ( $image_id && '' === trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) ) {
				self::set_alt( $image_id, self::suggest_for( $image_id )['alt'] );
				$done++;
			}
		}

		$children = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_parent'    => $post_id,
				'posts_per_page' => 10,
				'post_status'    => 'inherit',
				'fields'         => 'ids',
			)
		);
		foreach ( (array) $children as $child ) {
			if ( '' === trim( (string) get_post_meta( (int) $child, '_wp_attachment_image_alt', true ) ) ) {
				self::set_alt( (int) $child, self::suggest_for( (int) $child )['alt'] );
				$done++;
			}
		}

		return $done;
	}

	/**
	 * the_content wrapper.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function content_alt_filter( $content ) {
		if ( ! is_singular() ) {
			return $content;
		}
		return self::fill_content_alt( $content, (int) get_queried_object_id() );
	}

	/**
	 * Fill alt text for images inside a post that have none (content-level).
	 *
	 * @param string $content Content.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function fill_content_alt( $content, $post_id = 0 ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'images.enabled' ) || ! $settings->on( 'images.alt_in_content' ) ) {
			return $content;
		}
		if ( ! is_string( $content ) || false === stripos( $content, '<img' ) ) {
			return $content;
		}

		$keywords = $post_id ? Meta::focus_keywords( $post_id ) : array();
		$keyword  = $keywords ? $keywords[0] : ( $post_id ? get_the_title( $post_id ) : '' );
		$used     = 0;
		$limit    = (int) $settings->get( 'images.alt_in_content_max', 8 );

		$content = preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $m ) use ( $keyword, &$used, $limit ) {
				$tag = $m[0];
				if ( $used >= $limit ) {
					return $tag;
				}
				if ( preg_match( '/\salt\s*=\s*(["\'])\s*\1/i', $tag ) || ! preg_match( '/\salt\s*=/i', $tag ) ) {
					if ( '' === trim( (string) $keyword ) ) {
						return $tag;
					}
					$alt  = sanitize_text_field( (string) $keyword );
					$tag  = preg_match( '/\salt\s*=/i', $tag )
						? preg_replace( '/\salt\s*=\s*(["\']).*?\1/i', ' alt="' . esc_attr( $alt ) . '"', $tag )
						: preg_replace( '/^<img/i', '<img alt="' . esc_attr( $alt ) . '"', $tag );
					$used++;
				}
				return $tag;
			},
			$content
		);

		return $content;
	}

	/**
	 * Bulk: list attachments with missing alt text.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function missing( $args = array() ) {
		$settings = \hoosh_seo()->settings;
		$args = wp_parse_args(
			(array) $args,
			array(
				'per_page' => (int) $settings->get( 'images.scan_limit', 100 ),
				'page'     => 1,
				'type'     => 'all', // all|missing|empty|generic|huge|noref.
			)
		);

		$per_page = min( 500, max( 5, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$query = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'offset'         => $offset,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);

		if ( 'missing' === $args['type'] || 'empty' === $args['type'] ) {
			$query['meta_query'] = array(
				array(
					'relation' => 'OR',
					array(
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_wp_attachment_image_alt',
						'value'   => '',
						'compare' => '=',
					),
				),
			);
		}

		$ids       = get_posts( $query );
		$rows      = array();
		$max_kb    = (int) $settings->get( 'images.max_size_kb', 300 );
		$threshold = $max_kb * 1024;

		foreach ( (array) $ids as $id ) {
			$alt     = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			$file    = get_attached_file( (int) $id );
			$size    = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
			$dims    = $file && file_exists( $file ) ? @getimagesize( $file ) : false; // phpcs:ignore
			$title   = get_the_title( (int) $id );
			$parent  = (int) get_post_field( 'post_parent', (int) $id );
			$is_generic = $alt && 0 === strpos( $alt, 'IMG_' );

			$problems = array();
			if ( '' === $alt ) {
				$problems[] = 'no-alt';
			} elseif ( $is_generic ) {
				$problems[] = 'generic-alt';
			}
			if ( ! empty( $dims[0] ) && ! empty( $dims[1] ) ) {
				$display = (array) \hoosh_seo()->settings->get( 'images.display_widths', array( 1200 ) );
				$max_display = max( array_map( 'intval', $display ? $display : array( 1200 ) ) );
				if ( (int) $dims[0] > $max_display * 2 ) {
					$problems[] = 'oversized-dimensions';
				}
			}
			if ( $threshold && $size > $threshold ) {
				$problems[] = 'heavy';
			}
			if ( ! $parent ) {
				$problems[] = 'orphan';
			}
			if ( 'all' !== $args['type'] && ! in_array( $args['type'], $problems, true ) ) {
				continue;
			}

			$rows[] = array(
				'id'      => (int) $id,
				'title'   => $title,
				'url'     => wp_get_attachment_url( (int) $id ),
				'file'    => $file ? basename( $file ) : '',
				'alt'     => $alt,
				'suggested' => self::suggest_for( (int) $id )['alt'],
				'size_kb' => round( $size / 1024, 1 ),
				'width'   => ! empty( $dims[0] ) ? (int) $dims[0] : 0,
				'height'  => ! empty( $dims[1] ) ? (int) $dims[1] : 0,
				'problems' => $problems,
				'parent'  => $parent,
				'parent_title' => $parent ? get_the_title( $parent ) : '',
				'edit'    => get_edit_post_link( (int) $id, 'raw' ),
			);
		}

		$counts = wp_count_posts( 'attachment' );

		return array(
			'rows'  => $rows,
			'page'  => (int) $args['page'],
			'total' => isset( $counts->inherit ) ? (int) $counts->inherit : 0,
		);
	}

	/**
	 * Bulk apply suggestions to selected attachments.
	 *
	 * @param array $ids Attachment IDs.
	 * @param array $args What to apply.
	 * @return array
	 */
	public static function apply_bulk( $ids, $args = array() ) {
		$settings = \hoosh_seo()->settings;
		$args = wp_parse_args(
			(array) $args,
			array(
				'alt'     => true,
				'title'   => false,
				'caption' => false,
				'rename'  => (bool) $settings->get( 'images.rename_bulk', false ),
				'strip'   => (bool) $settings->get( 'images.strip_exif_bulk', false ),
				'only_empty' => (bool) ! $settings->on( 'images.overwrite' ),
				'source'  => (string) $settings->get( 'images.alt_source', 'auto' ),
			)
		);

		$done = array(
			'alt' => 0, 'title' => 0, 'caption' => 0, 'rename' => 0, 'strip' => 0, 'skipped' => 0,
		);

		foreach ( array_slice( array_map( 'absint', (array) $ids ), 0, 500 ) as $id ) {
			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				$done['skipped']++;
				continue;
			}
			$suggest = self::suggest_for( $id );

			if ( $args['alt'] ) {
				$current = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
				if ( ! $args['only_empty'] || '' === $current ) {
					self::set_alt( $id, $suggest['alt'] );
					$done['alt']++;
				}
			}

			if ( $args['title'] || $args['caption'] ) {
				$fields = array( 'ID' => $id );
				if ( $args['title'] && '' === trim( (string) get_the_title( $id ) ) ) {
					$fields['post_title'] = $suggest['title'];
				}
				if ( $args['caption'] && '' === trim( (string) get_post_field( 'post_excerpt', $id ) ) ) {
					$fields['post_excerpt'] = $suggest['caption'];
				}
				if ( count( $fields ) > 1 ) {
					wp_update_post( wp_slash( $fields ) );
					$done['title']   += isset( $fields['post_title'] ) ? 1 : 0;
					$done['caption'] += isset( $fields['post_excerpt'] ) ? 1 : 0;
				}
			}

			if ( $args['strip'] && self::strip_exif( $id ) ) {
				$done['strip']++;
			}

			if ( $args['rename'] ) {
				$result = self::maybe_rename( $id, $suggest['slug'] );
				if ( ! empty( $result['ok'] ) ) {
					$done['rename']++;
				}
			}
		}

		Audit::log( 'images', 'bulk_fill', $done );
		$done['message'] = sprintf(
			/* translators: 1: alt count, 2: rename count */
			__( '%1$d متن جایگزین و %2$d نام فایل به‌روز شد.', 'hoosh-seo' ),
			Helpers::number( (int) $done['alt'] ),
			Helpers::number( (int) $done['rename'] )
		);

		return $done;
	}

	/**
	 * Queue AI-generated alt text for attachments (uses the AI gateway when set).
	 *
	 * @param array $ids IDs.
	 * @return array
	 */
	public static function queue_ai_alt( $ids ) {
		if ( ! class_exists( __NAMESPACE__ . '\\..\\AI\\Gateway' ) && ! class_exists( 'HooshSEO\\AI\\Gateway' ) ) {
			return array( 'ok' => false, 'message' => __( 'موتور AI در دسترس نیست.', 'hoosh-seo' ) );
		}
		if ( ! \HooshSEO\AI\Gateway::is_configured() ) {
			return array( 'ok' => false, 'message' => __( 'ابتدا کلید AI را در بخش تنظیمات وارد کنید.', 'hoosh-seo' ) );
		}
		$queued = 0;
		foreach ( array_slice( array_map( 'absint', (array) $ids ), 0, 100 ) as $id ) {
			if ( $id ) {
				\HooshSEO\AI\Gateway::queue(
					array(
						'task'     => 'alt',
						'post_id'  => (int) $id,
						'priority' => 6,
					)
				);
				$queued++;
			}
		}
		return array(
			'ok'      => $queued > 0,
			'queued'  => $queued,
			'message' => sprintf( /* translators: %d count */ __( '%d تصویر در صف توضیح هوشمند قرار گرفت.', 'hoosh-seo' ), $queued ),
		);
	}

	/**
	 * Coverage summary for the dashboard.
	 *
	 * @return array
	 */
	public static function summary() {
		global $wpdb;
		$stats = Helpers::cache(
			'images-summary',
			function () use ( $wpdb ) {
				$total = (int) $wpdb->get_var( 'SELECT COUNT(ID) FROM ' . $wpdb->posts . " WHERE post_type='attachment' AND post_status='inherit'" ); // phpcs:ignore
				$noalt = (int) $wpdb->get_var(
					'SELECT COUNT(p.ID) FROM ' . $wpdb->posts . " p
					WHERE p.post_type='attachment' AND p.post_status='inherit'
					AND ( pm.meta_value IS NULL OR pm.meta_value = '' )" // phpcs:ignore
				);
				return array( 'total' => $total, 'noalt' => $noalt );
			},
			600
		);

		$total = max( 0, (int) $stats['total'] );
		$noalt = min( $total, max( 0, (int) $stats['noalt'] ) );

		return array(
			'total'     => $total,
			'missing'   => $noalt,
			'covered'   => $total ? $total - $noalt : 0,
			'coverage'  => $total ? (int) round( ( ( $total - $noalt ) / $total ) * 100 ) : 100,
			'severity'  => $total && ( ( $noalt / $total ) > .5 ) ? 'critical' : ( $noalt ? 'warning' : 'ok' ),
		);
	}

	/**
	 * Fill alt text for the oldest images still missing it (automation job).
	 *
	 * @param array $args {limit, use_ai}.
	 * @return array
	 */
	public static function backfill_alt( $args = array() ) {
		$args  = wp_parse_args( (array) $args, array( 'limit' => 25, 'use_ai' => false ) );
		$limit = max( 1, min( 200, (int) $args['limit'] ) );
		$list  = self::missing( array( 'per_page' => $limit, 'page' => 1, 'type' => 'all' ) );
		$ids   = array();
		foreach ( (array) ( $list['rows'] ?? array() ) as $row ) {
			if ( ! empty( $row['id'] ) ) {
				$ids[] = (int) $row['id'];
			}
		}
		if ( ! $ids ) {
			return array( 'ok' => true, 'processed' => 0, 'filled' => 0, 'message' => __( 'تصویر بدون متن جایگزین نمانده.', 'hoosh-seo' ) );
		}

		$out    = self::apply_bulk( $ids, array( 'alt' => true, 'only_empty' => true ) );
		$filled = (int) ( $out['alt'] ?? 0 );

		if ( ! empty( $args['use_ai'] ) && $filled < count( $ids ) && \HooshSEO\AI\Gateway::is_configured() ) {
			$rest = array_slice( $ids, $filled );
			if ( $rest ) {
				$queued = self::queue_ai_alt( $rest );
				$out['queued'] = (int) ( $queued['queued'] ?? 0 );
			}
		}

		return array(
			'ok'        => true,
			'processed' => count( $ids ),
			'filled'    => $filled,
			'message'   => (string) ( $out['message'] ?? __( 'متن جایگزین‌ها پر شد.', 'hoosh-seo' ) ),
		);
	}

}
