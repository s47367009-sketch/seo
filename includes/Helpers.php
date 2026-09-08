<?php
/**
 * Shared helpers: Persian text, Jalali calendar, template variables, HTTP, cache.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Helpers
 */
class Helpers {

	/**
	 * Persian month names.
	 *
	 * @var array
	 */
	public static $j_months = array(
		'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
		'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
	);

	/**
	 * Persian weekday names (index 0 = Sunday, matching WP $w).
	 *
	 * @var array
	 */
	public static $weekdays = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' );

	/**
	 * Integer division.
	 *
	 * @param int $a Dividend.
	 * @param int $b Divisor.
	 * @return int
	 */
	protected static function div( $a, $b ) {
		return (int) ( $a / $b );
	}

	/**
	 * Gregorian to Jalali.
	 *
	 * @param int $g_y Year.
	 * @param int $g_m Month.
	 * @param int $g_d Day.
	 * @return array [ y, m, d ]
	 */
	public static function gregorian_to_jalali( $g_y, $g_m, $g_d ) {
		$g_days = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$j_days = array( 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 );

		$gy       = (int) $g_y - 1600;
		$jm       = (int) $g_m - 1;
		$jd       = (int) $g_d - 1;
		$j_day_no = 365 * $gy + self::div( $gy + 3, 4 ) - self::div( $gy + 99, 100 ) + self::div( $gy + 399, 400 );

		for ( $i = 0; $i < $jm; ++$i ) {
			$j_day_no += $g_days[ $i ];
		}

		if ( $jm > 1 && ( ( $gy % 4 === 0 && $gy % 100 !== 0 ) || ( $gy % 400 === 0 ) ) ) {
			++$j_day_no;
		}

		$j_day_no += $jd;
		$j_day_no  -= 79;
		$j_np       = self::div( $j_day_no, 12053 );
		$j_day_no   = $j_day_no % 12053;
		$jy         = 979 + 33 * $j_np + 4 * self::div( $j_day_no, 1461 );
		$j_day_no   = $j_day_no % 1461;

		if ( $j_day_no >= 366 ) {
			$jy       += self::div( $j_day_no, 365 );
			$j_day_no  = $j_day_no % 365;
		}

		for ( $i = 0; $i < 11 && $j_day_no >= $j_days[ $i ]; ++$i ) {
			$j_day_no -= $j_days[ $i ];
		}

		return array( $jy, $i + 1, $j_day_no + 1 );
	}

	/**
	 * Jalali to Gregorian.
	 *
	 * @param int $j_y Year.
	 * @param int $j_m Month.
	 * @param int $j_d Day.
	 * @return array [ y, m, d ]
	 */
	public static function jalali_to_gregorian( $j_y, $j_m, $j_d ) {
		$g_days = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		$j_days = array( 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 );

		$jy       = (int) $j_y - 979;
		$jm       = (int) $j_m - 1;
		$jd       = (int) $j_d - 1;
		$j_day_no = 365 * $jy + self::div( $jy, 33 ) * 8 + self::div( $jy % 33 + 3, 4 );

		for ( $i = 0; $i < $jm; ++$i ) {
			$j_day_no += $j_days[ $i ];
		}

		$j_day_no += $jd;
		$g_day_no  = $j_day_no + 79;
		$gy        = 1600 + 400 * self::div( $g_day_no, 146097 );
		$g_day_no  = $g_day_no % 146097;

		if ( $g_day_no >= 36524 ) {
			--$g_day_no;
			$gy       += 100 * self::div( $g_day_no, 36524 );
			$g_day_no  = $g_day_no % 36524;
			if ( $g_day_no >= 365 ) {
				++$g_day_no;
			}
		}

		$gy       += 4 * self::div( $g_day_no, 1461 );
		$g_day_no  = $g_day_no % 1461;

		if ( $g_day_no >= 366 ) {
			--$g_day_no;
			$gy       += self::div( $g_day_no, 365 );
			$g_day_no  = $g_day_no % 365;
		}

		for ( $i = 0; $i < 11 && $g_day_no >= $g_days[ $i ]; ++$i ) {
			$g_day_no -= $g_days[ $i ];
		}

		return array( $gy, $i + 1, $g_day_no + 1 );
	}

	/**
	 * Format a datetime string in the Jalali calendar.
	 *
	 * Supported tokens: Y (سال), m (month number), d, F (month name), j, n,
	 * l (weekday), g/i/a (time), c (iso), "تاریخ شمسی".
	 *
	 * @param string $format PHP-ish format with Jalali semantics.
	 * @param mixed  $local  Timestamp or datetime string.
	 * @return string
	 */
	public static function jalali_date( $format, $local = 'now' ) {
		$ts = is_numeric( $local ) ? (int) $local : strtotime( (string) $local );
		if ( ! $ts ) {
			return '';
		}

		$j = self::gregorian_to_jalali(
			(int) gmdate( 'Y', $ts ),
			(int) gmdate( 'n', $ts ),
			(int) gmdate( 'j', $ts )
		);

		list( $jy, $jm, $jd ) = $j;

		$replacements = array(
			'Y'   => (string) $jy,
			'y'   => substr( (string) $jy, -2 ),
			'm'   => sprintf( '%02d', $jm ),
			'n'   => (string) $jm,
			'd'   => sprintf( '%02d', $jd ),
			'j'   => (string) $jd,
			'F'   => self::$j_months[ $jm - 1 ],
			'M'   => mb_substr( self::$j_months[ $jm - 1 ], 0, 3 ),
			'l'   => self::$weekdays[ (int) gmdate( 'w', $ts ) ],
			'D'   => mb_substr( self::$weekdays[ (int) gmdate( 'w', $ts ) ], 0, 3 ),
			'H:i' => gmdate( 'H:i', $ts ),
			'g:i a' => trim( str_replace( array( 'am', 'pm' ), array( 'ق.ظ', 'ب.ظ' ), gmdate( 'g:i a', $ts ) ) ),
		);

		$out = $format;
		// Longest tokens first so "H:i" is not eaten by "H".
		uksort(
			$replacements,
			function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		foreach ( $replacements as $token => $value ) {
			$out = str_replace( $token, $value, $out );
		}

		// Anything left untouched (like literal text) is returned as-is.
		return $out;
	}

	/**
	 * Convert Latin digits to Persian digits when enabled in the app settings.
	 *
	 * @param string|int|float $value Value.
	 * @param bool|null        $force Force on/off; null = follow settings.
	 * @return string
	 */
	public static function digits( $value, $force = null ) {
		$value = (string) $value;
		$on    = null === $force ? hoosh_seo()->settings->get( 'appearance.persian_digits', true ) : (bool) $force;
		if ( ! $on ) {
			return $value;
		}
		return str_replace(
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ),
			$value
		);
	}

	/**
	 * Convert Persian/Arabic digits back to Latin.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function undigits( $value ) {
		return str_replace(
			array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' ),
			array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
			(string) $value
		);
	}

	/**
	 * Normalise a Persian string for matching / indexing.
	 *
	 * Arabic yeh/keh → Persian, tatweel removed, diacritics removed,
	 * ZWNJ removed, digits unified, whitespace collapsed, lowercased.
	 *
	 * @param string $text Input.
	 * @return string
	 */
	public static function normalize_fa( $text ) {
		$text = (string) $text;
		$text = str_replace( array( "\u{200c}", "\u{200b}", "\u{200e}", "\u{200f}", "\u{0640}" ), array( '', '', '', '', '' ), $text );
		$text = str_replace( array( 'ي', 'ك', 'ٰ', 'أ', 'إ', 'آ', 'ة', 'ؤ', 'ئ' ), array( 'ی', 'ک', '', 'ا', 'ا', 'ا', 'ه', 'و', 'ی' ), $text );
		$text = self::undigits( $text );
		$text = preg_replace( '/[\x{064B}-\x{0652}]/u', '', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Does the string contain Persian script?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_persian( $text ) {
		return (bool) preg_match( '/[\x{06FA}-\x{06FF}\x{0626}-\x{0627}]/u', (string) $text );
	}

	/**
	 * Count Persian/English words in HTML content.
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	public static function word_count( $html ) {
		$text = trim( wp_strip_all_tags( (string) $html ) );
		if ( '' === $text ) {
			return 0;
		}
		$text = preg_replace( '/[^\p{L}\p{N}\s\-ڀ-ﻯ]/u', ' ', $text );
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Split text into sentences (Persian-friendly).
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	public static function sentences( $text ) {
		$text = preg_replace( '/\s+/u', ' ', trim( wp_strip_all_tags( (string) $text ) ) );
		if ( '' === $text ) {
			return array();
		}
		$parts = preg_split( '/(?<=[.!?؛:])\s+|\n+/u', $text );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * Strip non-breakable spaces, keep words.
	 *
	 * @param string $text Text.
	 * @return array Words.
	 */
	public static function words( $text ) {
		$text = preg_replace( '/[^\p{L}\p{N}\s\-]/u', ' ', wp_strip_all_tags( (string) $text ) );
		$words = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? array_values( $words ) : array();
	}

	/**
	 * Common Persian + English stop words.
	 *
	 * @return array
	 */
	public static function stopwords() {
		$fa = array(
			'و', 'در', 'به', 'از', 'که', 'این', 'است', 'را', 'با', 'для', 'خود', 'برای', 'روی', 'زیر', 'بین', 'شده', 'شد', 'می', 'من', 'تو', 'او', 'ما', 'شما', 'آنها', 'هر', 'هم', 'بله', 'خیر', 'نیست', 'بود', 'هست', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'یا', 'اما', 'ولی', 'اگر', 'چون', 'پس', 'حتی', 'نیز', 'همچنین', 'بیشتر', 'کمتر', 'مثل', 'مانند', 'درباره', 'تا', 'توی', 'پایین', 'بالا', 'چند', 'کدام', 'اینکه', 'آن', 'آن', 'اینها', 'چیزی', 'چیز', 'هستم', 'هستند', 'شود', 'شدن', 'گرفت', 'کرد', 'کردن', 'کرد', 'نمونه', 'توجه', 'لطفا', 'سلام', 'با', 'دارای', 'دارد', 'دارن', 'ندارد', 'خواهد', 'خواه', 'میشود', 'می‌شود', 'میتوان', 'می‌توان', 'باید', 'توان', 'که',
		);
		$en = array(
			'the', 'a', 'an', 'and', 'or', 'but', 'if', 'while', 'of', 'at', 'by', 'for', 'with', 'about', 'against', 'between', 'into', 'through', 'during', 'before', 'after', 'above', 'below', 'to', 'from', 'up', 'down', 'in', 'out', 'on', 'off', 'over', 'under', 'again', 'further', 'then', 'once', 'here', 'there', 'all', 'any', 'both', 'each', 'few', 'more', 'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very', 'can', 'will', 'just', 'don', 'should', 'now', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'doing', 'this', 'that', 'these', 'those', 'it', 'its',
		);
		return array_values( array_unique( array_merge( $fa, $en ) ) );
	}

	/**
	 * Persian transition / linking words used by the readability engine.
	 *
	 * @return array
	 */
	public static function transition_words() {
		return array(
			'بنابراین', 'در نتیجه', 'به همین دلیل', 'با این حال', 'هرچند', 'اما', 'ولی', 'زیرا', 'چون', 'چرا که', 'علاوه بر این', 'همچنین', 'در ضمن', 'به عبارت دیگر', 'مثلاً', 'از جمله', 'به ویژه', 'در نهایت', 'اول از همه', 'پس از آن', 'از یک سو', 'از سوی دیگر', 'به طور کلی', 'در واقع', 'متاسفانه', 'خوشبختانه', 'بدیهی است', 'لازم به ذکر است', 'نکته اینجاست', 'به همین منظور', 'در ادامه', 'با توجه به', 'مقابل', 'در حالی که', 'هرچند که', 'زیرا که', 'چنانچه', 'در صورتی که',
		);
	}

	/**
	 * Render template variables such as %%title%% inside title/desc patterns.
	 *
	 * @param string $template Template.
	 * @param array  $context  Variables.
	 * @return string
	 */
	public static function render_vars( $template, $context = array() ) {
		$out = (string) $template;
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$out = str_replace( '%%' . $key . '%%', (string) $value, $out );
			}
		}
		// Unknown variables collapse so a broken template never leaks %%x%%.
		$out = preg_replace( '/%%[a-z_\-]+%%/i', '', $out );
		return trim( preg_replace( '/\s+/', ' ', $out ) );
	}

	/**
	 * Build the variable context for a post (or an archive).
	 *
	 * @param int   $post_id Post ID (0 for the current query).
	 * @param array $extra   Overrides.
	 * @return array
	 */
	public static function variable_context( $post_id = 0, $extra = array() ) {
		$settings = hoosh_seo()->settings;
		$site     = get_bloginfo( 'name' );
		$ctx      = array(
			'sitename'  => $site,
			'sitedescription' => get_bloginfo( 'description' ),
			'tagline'   => get_bloginfo( 'description' ),
			'separator' => $settings->get( 'general.separator', '|' ),
			'currentdate' => date_i18n( 'Y-m-d' ),
			'currentday'  => date_i18n( 'j' ),
			'currentmonth' => date_i18n( 'F' ),
			'currentyear' => date_i18n( 'Y' ),
			'currenttime' => date_i18n( 'H:i' ),
			'shamsi_date' => self::jalali_date( 'j F Y' ),
			'shamsi_year' => self::jalali_date( 'Y' ),
			'shamsi_month' => self::jalali_date( 'F' ),
			'page'        => 1,
			'pagenumber'  => 1,
			'page_total'  => 1,
			'searchquery' => '',
			'primary_category' => '',
			'category'    => '',
			'term'        => '',
			'excerpt'     => '',
			'content'     => '',
			'id'          => 0,
		);

		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$cats = get_the_category( $post->ID );
				$tags = get_the_tags( $post->ID );
				$excerpt = has_excerpt( $post->ID ) ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 28, '' );

				$ctx = array_merge( $ctx, array(
					'title'        => get_the_title( $post->ID ),
					'name'         => get_the_title( $post->ID ),
					'sitename'     => $site,
					'excerpt'      => $excerpt,
					'content'      => wp_trim_words( wp_strip_all_tags( do_shortcode( $post->post_content ) ), 60, '' ),
					'date'         => get_the_date( '', $post->ID ),
					'shamsi_date'  => self::jalali_date( 'j F Y', get_post_timestamp( $post ) ),
					'month'        => get_the_date( 'F', $post->ID ),
					'year'         => get_the_date( 'Y', $post->ID ),
					'day'          => get_the_date( 'j', $post->ID ),
					'monthnum'     => get_the_date( 'n', $post->ID ),
					'modified'     => get_the_modified_date( '', $post->ID ),
					'author'       => get_the_author_meta( 'display_name', (int) $post->post_author ),
					'author_url'   => get_author_posts_url( (int) $post->post_author ),
					'id'           => $post->ID,
					'post_id'      => $post->ID,
					'post_type'    => $post->post_type,
					'permalink'    => get_permalink( $post->ID ),
					'slug'         => $post->post_name,
					'primary_category' => $cats ? $cats[0]->name : '',
					'category'     => $cats ? $cats[0]->name : '',
					'categories'   => implode( ', ', wp_list_pluck( $cats ? $cats : array(), 'name' ) ),
					'tags'         => $tags ? implode( ', ', wp_list_pluck( $tags, 'name' ) ) : '',
					'primary_tag'  => $tags ? $tags[0]->name : '',
					'wordcount'    => self::word_count( $post->post_content ),
					'reading_time' => max( 1, (int) ceil( self::word_count( $post->post_content ) / 220 ) ),
				) );

				$focus = Meta::focus_keywords( $post->ID );
				$ctx['keyword']     = $focus ? $focus[0] : '';
				$ctx['keywords']    = implode( ', ', $focus );
				$ctx['focuskw']     = $ctx['keyword'];

				if ( function_exists( 'wc_get_product' ) && 'product' === $post->post_type ) {
					$product = wc_get_product( $post->ID );
					if ( $product ) {
						$ctx['price']       = wp_strip_all_tags( $product->get_price_html() );
						$ctx['regular_price'] = wc_price( $product->get_regular_price() );
						$ctx['sale_price']  = wc_price( $product->get_sale_price() );
						$ctx['sku']         = (string) $product->get_sku();
						$ctx['stock']       = $product->is_in_stock() ? 'موجود' : 'ناموجود';
						$ctx['rating']      = (string) $product->get_average_rating();
						$ctx['reviews']     = (string) $product->get_review_count();
						$ctx['brand']       = self::product_brand( $product );
					}
				}
			}
		}

		$ctx['tagline']   = get_bloginfo( 'description' );
		$ctx['term']      = isset( $ctx['category'] ) ? $ctx['category'] : '';
		$ctx['sitename']  = $settings->get( 'general.site_name' ) ? $settings->get( 'general.site_name' ) : $site;

		return array_merge( $ctx, array_filter( (array) $extra, 'strlen' ) );
	}

	/**
	 * Best-effort product brand from Yoast/Woo attributes.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function product_brand( $product ) {
		foreach ( array( 'pa_brand', 'brand', 'pa_brands', 'pa_markaz' ) as $tax ) {
			$terms = get_the_terms( $product->get_id(), $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				return $terms[0]->name;
			}
		}
		return '';
	}

	/**
	 * Turn a title into a safe URL slug (Persian transliterated when asked).
	 *
	 * @param string $title Title.
	 * @param string $mode  fa|en|keep.
	 * @return string
	 */
	public static function safe_slug( $title, $mode = 'en' ) {
		$title = self::normalize_fa( $title );
		if ( 'keep' === $mode ) {
			$slug = remove_accents( $title );
			$slug = preg_replace( '/[^\p{L}\p{N}\s-]/u', '', $slug );
			$slug = trim( preg_replace( '/[\s_-]+/u', '-', $slug ), '-' );
			return $slug ? $slug : sanitize_title( $title );
		}

		$map = array(
			'آ' => 'a', 'ا' => 'a', 'ب' => 'b', 'پ' => 'p', 'ت' => 't', 'ث' => 's', 'ج' => 'j', 'چ' => 'ch',
			'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'z', 'ر' => 'r', 'ز' => 'z', 'ژ' => 'zh', 'س' => 's',
			'ش' => 'sh', 'ص' => 's', 'ض' => 'z', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f',
			'ق' => 'gh', 'ک' => 'k', 'گ' => 'g', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'و' => 'v', 'ه' => 'h',
			'ی' => 'y', 'ئ' => 'y', 'ة' => 't', 'ع' => 'a', 'ً' => '', 'ٌ' => '', 'ٍ' => '', 'َ' => '', 'ُ' => '', 'ِ' => '', 'ّ' => '', 'ٰ' => '',
		);

		if ( 'fa' === $mode ) {
			$slug = str_replace( array( ' ', '‌' ), '-', $title );
			$slug = preg_replace( '/[^\p{L}\p{N}-]/u', '', $slug );
			return trim( (string) $slug, '-' );
		}

		$out = '';
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = function_exists( 'mb_substr' ) ? mb_substr( $title, $i, 1, 'UTF-8' ) : substr( $title, $i, 1 );
			if ( isset( $map[ $char ] ) ) {
				$out .= $map[ $char ];
			} elseif ( preg_match( '/[a-z0-9]/', $char ) ) {
				$out .= $char;
			} elseif ( preg_match( '/[\s_\-]/u', $char ) ) {
				$out .= '-';
			}
		}
		$out = preg_replace( '/-+/', '-', $out );
		$out = trim( $out, '-' );
		$out = preg_replace( '/(ey-|teh-)/', '', $out );
		return $out ? $out : sanitize_title( $title );
	}

	/**
	 * Score to letter grade, in the style of Iranian marketplaces (A+ … D).
	 *
	 * @param float $score 0-100.
	 * @return string
	 */
	public static function grade( $score ) {
		$score = (float) $score;
		if ( $score >= 90 ) {
			return 'A+';
		}
		if ( $score >= 80 ) {
			return 'A';
		}
		if ( $score >= 70 ) {
			return 'B+';
		}
		if ( $score >= 60 ) {
			return 'B';
		}
		if ( $score >= 50 ) {
			return 'C+';
		}
		if ( $score >= 40 ) {
			return 'C';
		}
		if ( $score >= 30 ) {
			return 'D';
		}
		return 'E';
	}

	/**
	 * Severity label in Persian.
	 *
	 * @param string $severity critical|warning|notice|ok.
	 * @return string
	 */
	public static function severity_label( $severity ) {
		$map = array(
			'critical' => 'بحرانی',
			'error'    => 'خطا',
			'warning'  => 'هشدار',
			'notice'   => 'قابل بهبود',
			'ok'       => 'سالم',
			'info'     => 'اطلاع',
		);
		return isset( $map[ $severity ] ) ? $map[ $severity ] : $severity;
	}

	/**
	 * Relative Persian time ("۳ روز پیش").
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	public static function time_ago_fa( $timestamp ) {
		$diff = time() - (int) $timestamp;
		if ( $diff < 60 ) {
			return 'همین حالا';
		}
		$map = array(
			'min'  => array( 60, 'دقیقه' ),
			'hour' => array( 3600, 'ساعت' ),
			'day'  => array( 86400, 'روز' ),
			'week' => array( 604800, 'هفته' ),
			'month' => array( 2592000, 'ماه' ),
			'year' => array( 31536000, 'سال' ),
		);
		$unit  = 'دقیقه';
		$count = (int) ( $diff / 60 );
		foreach ( $map as $label => $def ) {
			if ( $diff >= $def[0] ) {
				$unit  = $def[1];
				$count = (int) ( $diff / $def[0] );
			}
		}
		return self::digits( $count ) . ' ' . $unit . ' پیش';
	}

	/**
	 * Format a number with thousand separators + optional Persian digits.
	 *
	 * @param int|float $number Number.
	 * @param int       $decimals Decimals.
	 * @return string
	 */
	public static function number( $number, $decimals = 0 ) {
		return self::digits( number_format_i18n( (float) $number, $decimals ) );
	}

	/**
	 * Mask an API key for display.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function mask_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		if ( strlen( $key ) <= 10 ) {
			return str_repeat( '•', strlen( $key ) );
		}
		return substr( $key, 0, 6 ) . str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	/**
	 * Anonymised IP for logs.
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	public static function ip_anon( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip ) {
			return '';
		}
		if ( hoosh_seo()->settings->get( 'security.anonymize_ip', true ) ) {
			if ( false !== strpos( $ip, '.' ) ) {
				$parts = explode( '.', $ip );
				array_pop( $parts );
				$parts[] = '0';
				return implode( '.', $parts );
			}
			$parts = explode( ':', $ip );
			$parts = array_slice( $parts, 0, 4 );
			return implode( ':', $parts ) . ':/64';
		}
		return $ip;
	}

	/**
	 * Detect the current bot (search + AI crawlers).
	 *
	 * @return array|false [ key, name, purpose ]
	 */
	public static function detect_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return false;
		}
		$bots = self::bot_signatures();
		foreach ( $bots as $key => $bot ) {
			foreach ( $bot['match'] as $needle ) {
				if ( false !== stripos( $ua, $needle ) ) {
					return array( $key, $bot['name'], $bot['purpose'] );
				}
			}
		}
		return false;
	}

	/**
	 * Known crawler signatures.
	 *
	 * @return array
	 */
	public static function bot_signatures() {
		return array(
			'googlebot'      => array( 'name' => 'Googlebot', 'purpose' => 'search', 'match' => array( 'googlebot', 'GoogleOther', 'APIs-Google' ) ),
			'bingbot'        => array( 'name' => 'Bingbot', 'purpose' => 'search', 'match' => array( 'bingbot', 'adidxbot' ) ),
			'yahoo'          => array( 'name' => 'Yahoo Slurp', 'purpose' => 'search', 'match' => array( 'slurp' ) ),
			'yandex'         => array( 'name' => 'Yandex', 'purpose' => 'search', 'match' => array( 'yandexbot' ) ),
			'duckduckgo'     => array( 'name' => 'DuckDuckBot', 'purpose' => 'search', 'match' => array( 'duckduckbot' ) ),
			'baidu'          => array( 'name' => 'Baiduspider', 'purpose' => 'search', 'match' => array( 'baiduspider' ) ),
			'sogou'          => array( 'name' => 'Sogou', 'purpose' => 'search', 'match' => array( 'sogou' ) ),
			'petal'          => array( 'name' => 'PetalBot', 'purpose' => 'search', 'match' => array( 'petalbot' ) ),
			'seznam'         => array( 'name' => 'Seznam', 'purpose' => 'search', 'match' => array( 'napravozak' ) ),
			'indexnow'       => array( 'name' => 'IndexNow', 'purpose' => 'index', 'match' => array( 'indexnow' ) ),
			'gptbot'         => array( 'name' => 'ChatGPT (GPTBot)', 'purpose' => 'ai', 'match' => array( 'gptbot' ) ),
			'chatgpt_user'   => array( 'name' => 'ChatGPT-User', 'purpose' => 'ai', 'match' => array( 'chatgpt-user' ) ),
			'oai_search'     => array( 'name' => 'OAI-SearchBot', 'purpose' => 'ai', 'match' => array( 'oai-searchbot' ) ),
			'claudebot'      => array( 'name' => 'ClaudeBot', 'purpose' => 'ai', 'match' => array( 'claudebot' ) ),
			'claude_user'    => array( 'name' => 'Claude-User', 'purpose' => 'ai', 'match' => array( 'claude-user' ) ),
			'anthropic'      => array( 'name' => 'anthropic-ai', 'purpose' => 'ai', 'match' => array( 'anthropic-ai' ) ),
			'perplexity'     => array( 'name' => 'PerplexityBot', 'purpose' => 'ai', 'match' => array( 'perplexitybot' ) ),
			'perplexity_user' => array( 'name' => 'Perplexity-User', 'purpose' => 'ai', 'match' => array( 'perplexity-user' ) ),
			'google_extended' => array( 'name' => 'Google-Extended', 'purpose' => 'ai', 'match' => array( 'google-extended' ) ),
			'applebot'       => array( 'name' => 'Applebot', 'purpose' => 'ai', 'match' => array( 'applebot' ) ),
			'amazonbot'      => array( 'name' => 'Amazonbot', 'purpose' => 'ai', 'match' => array( 'amazonbot' ) ),
			'mistral'        => array( 'name' => 'MistralAI', 'purpose' => 'ai', 'match' => array( 'mistralai' ) ),
			'cohere'         => array( 'name' => 'Cohere', 'purpose' => 'ai', 'match' => array( 'cohere-training-data-crawler', 'cohere-ai' ) ),
			'bytedance'      => array( 'name' => 'Bytespider', 'purpose' => 'ai', 'match' => array( 'bytespider' ) ),
			'ccbot'          => array( 'name' => 'Common Crawl', 'purpose' => 'ai', 'match' => array( 'ccbot' ) ),
			'facebookbot'    => array( 'name' => 'FacebookBot', 'purpose' => 'ai', 'match' => array( 'facebookbot' ) ),
			'magpie'         => array( 'name' => 'Magpie-Crawler', 'purpose' => 'ai', 'match' => array( 'magpie-crawler' ) ),
			'others'         => array( 'name' => 'Other AI', 'purpose' => 'ai', 'match' => array( 'gpt', 'ai-crawler', 'cortexbot', 'diffbot', 'omgili' ) ),
			'ahrefs'         => array( 'name' => 'AhrefsBot', 'purpose' => 'seo', 'match' => array( 'ahrefsbot' ) ),
			'semrush'        => array( 'name' => 'SemrushBot', 'purpose' => 'seo', 'match' => array( 'semrushbot' ) ),
			'majestic'       => array( 'name' => 'MJ12bot', 'purpose' => 'seo', 'match' => array( 'mj12bot' ) ),
			'dotbot'         => array( 'name' => 'dotbot', 'purpose' => 'seo', 'match' => array( 'dotbot' ) ),
			'pulsepoint'     => array( 'name' => 'Pulsepoint', 'purpose' => 'ads', 'match' => array( 'pulsepoint' ) ),
			'pinterest'      => array( 'name' => 'Pinterest', 'purpose' => 'social', 'match' => array( 'pinterestbot' ) ),
			'facebook'       => array( 'name' => 'Facebook', 'purpose' => 'social', 'match' => array( 'facebookexternalhit' ) ),
			'twitter'        => array( 'name' => 'Twitter', 'purpose' => 'social', 'match' => array( 'twitterbot' ) ),
			'telegram'       => array( 'name' => 'TelegramBot', 'purpose' => 'social', 'match' => array( 'telegrambot' ) ),
			'whatsapp'       => array( 'name' => 'WhatsApp', 'purpose' => 'social', 'match' => array( 'whatsapp' ) ),
			'linkedin'       => array( 'name' => 'LinkedIn', 'purpose' => 'social', 'match' => array( 'linkedinbot' ) ),
			'skype'          => array( 'name' => 'Skype', 'purpose' => 'social', 'match' => array( 'skypeuripreview' ) ),
			'slack'          => array( 'name' => 'Slack', 'purpose' => 'social', 'match' => array( 'slackbot' ) ),
			'discord'        => array( 'name' => 'Discord', 'purpose' => 'social', 'match' => array( 'discordbot' ) ),
			'node'           => array( 'name' => 'Scraper', 'purpose' => 'other', 'match' => array( 'python-requests', 'curl/', 'wget', 'scrapy', 'go-http-client' ) ),
		);
	}

	/**
	 * HTTP GET/POST with sane defaults, UA rotation and a normalized error shape.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args for wp_remote_request.
	 * @return array [ ok, code, body, json, error ]
	 */
	public static function http( $url, $args = array() ) {
		$defaults = array(
			'timeout'     => 20,
			'redirection' => 3,
			'user-agent'  => self::random_ua(),
			'headers'     => array(),
			'sslverify'   => true,
			'httpversion' => '1.1',
		);
		$args = wp_parse_args( $args, $defaults );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'code'  => 0,
				'body'  => '',
				'json'  => array(),
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		return array(
			'ok'    => $code >= 200 && $code < 400,
			'code'  => $code,
			'body'  => $body,
			'json'  => is_array( $json ) ? $json : array(),
			'error' => ( $code >= 400 ) ? 'HTTP ' . $code : '',
			'headers' => wp_remote_retrieve_headers( $response )->getAll(),
		);
	}

	/**
	 * Rotate among a few realistic desktop UA strings (used by public scrapers).
	 *
	 * @return string
	 */
	public static function random_ua() {
		$list = array(
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
			'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0',
			'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
		);
		return $list[ array_rand( $list ) ];
	}

	/**
	 * Transient-backed cache with an object-cache short circuit.
	 *
	 * @param string $key  Key.
	 * @param callable $callback Producer (only called on miss).
	 * @param int    $ttl  Seconds.
	 * @return mixed
	 */
	public static function cache( $key, $callback, $ttl = 900 ) {
		$id = 'hoosh_' . md5( $key );
		if ( ! hoosh_seo()->settings->get( 'advanced.persist_cache', true ) ) {
			$cached = wp_cache_get( $id, 'hoosh' );
			if ( false !== $cached ) {
				return $cached;
			}
			$value = call_user_func( $callback );
			wp_cache_set( $id, $value, 'hoosh', $ttl );
			return $value;
		}

		$cached = get_transient( $id );
		if ( false !== $cached ) {
			return $cached;
		}
		$value = call_user_func( $callback );
		if ( null !== $value ) {
			set_transient( $id, $value, $ttl );
		}
		return $value;
	}

	/**
	 * Invalidate a cache entry.
	 *
	 * @param string $key Key.
	 */
	public static function cache_flush( $key ) {
		delete_transient( 'hoosh_' . md5( $key ) );
		wp_cache_delete( 'hoosh_' . md5( $key ), 'hoosh' );
	}

	/**
	 * Flush all hoosh transients (cheap: pattern delete when the option is on).
	 */
	public static function cache_flush_all() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_hoosh\\_%' OR option_name LIKE '\\_transient\\_timeout\\_hoosh\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Public post types we should manage.
	 *
	 * @param bool $exclude Whether to honor the "exclude" setting.
	 * @return array
	 */
	public static function post_types( $exclude = true ) {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$out   = array();
		$skip  = $exclude ? (array) hoosh_seo()->settings->get( 'advanced.exclude_post_types', array() ) : array();
		foreach ( $types as $type ) {
			if ( in_array( $type->name, $skip, true ) ) {
				continue;
			}
			if ( 'attachment' === $type->name && ! hoosh_seo()->settings->get( 'index.index_attachments', false ) ) {
				continue;
			}
			$out[ $type->name ] = $type->labels->singular_name;
		}
		return $out;
	}

	/**
	 * Which post types get a meta box / analysis.
	 *
	 * @return array
	 */
	public static function managed_post_types() {
		return array_keys( self::post_types() );
	}

	/**
	 * Clamp a value into a range.
	 *
	 * @param float $value Value.
	 * @param float $min   Min.
	 * @param float $max   Max.
	 * @return float
	 */
	public static function clamp( $value, $min, $max ) {
		return max( $min, min( $max, (float) $value ) );
	}

	/**
	 * UTF-8 aware strlen.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function strlen( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * Approximate Google snippet width in pixels for a font size.
	 *
	 * @param string $text Text.
	 * @param int    $font_size Font size in px.
	 * @return int
	 */
	public static function pixel_width( $text, $font_size = 20 ) {
		$text  = self::undigits( (string) $text );
		$width = 0;
		$len   = self::strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$char = function_exists( 'mb_substr' ) ? mb_substr( $text, $i, 1, 'UTF-8' ) : substr( $text, $i, 1 );
			if ( preg_match( '/[mw]/', $char ) ) {
				$width += $font_size * 0.95;
			} elseif ( preg_match( '/[ilj.,\'":;|\-\s]/u', $char ) ) {
				$width += $font_size * 0.32;
			} elseif ( preg_match( '/[\x{0600}-\x{06FF}]/u', $char ) ) {
				$width += $font_size * 0.5;
			} elseif ( preg_match( '/[A-Z]/', $char ) ) {
				$width += $font_size * 0.72;
			} else {
				$width += $font_size * 0.55;
			}
		}
		return (int) round( $width );
	}

	/**
	 * Get a meta value with a fallback, never throwing.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	public static function meta( $post_id, $key, $default = '' ) {
		$value = get_post_meta( (int) $post_id, $key, true );
		if ( '' === $value || null === $value || array() === $value ) {
			return $default;
		}
		return $value;
	}

	/**
	 * Absolute URL for a relative path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function abs_url( $path ) {
		$path = (string) $path;
		if ( preg_match( '#^https?://#i', $path ) ) {
			return $path;
		}
		return untrailingslashit( home_url( '/' ) ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * Relative URL (path) for an absolute one.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function rel_url( $url ) {
		$url  = (string) $url;
		$home = untrailingslashit( home_url( '/' ) );
		$home = set_url_scheme( $home, set_url_scheme( $url, $home ) );
		if ( 0 === strpos( $url, $home ) ) {
			$path = substr( $url, strlen( $home ) );
			return '/' . ltrim( (string) $path, '/' );
		}
		if ( 0 === strpos( $url, home_url( '/' ) ) ) {
			return '/' . ltrim( substr( $url, strlen( home_url( '/' ) ) ), '/' );
		}
		return $url;
	}

	/**
	 * Sanitize an incoming URL for storage (accepts relative or absolute).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function sanitize_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return esc_url_raw( $url );
		}
		return esc_url_raw( $url );
	}

	/**
	 * Read a scalar from the request in a null-safe way.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @param string $type    str|int|bool|arr|slug.
	 * @return mixed
	 */
	public static function input( $key, $default = '', $type = 'str' ) {
		$raw = null;
		if ( isset( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$raw = wp_unslash( $_REQUEST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( null === $raw ) {
			return $default;
		}
		switch ( $type ) {
			case 'int':
				return (int) $raw;
			case 'float':
				return (float) $raw;
			case 'bool':
				return in_array( is_string( $raw ) ? strtolower( $raw ) : $raw, array( true, 1, '1', 'true', 'yes', 'on' ), true );
			case 'arr':
				return is_array( $raw ) ? $raw : (array) json_decode( (string) $raw, true );
			case 'slug':
				return sanitize_key( (string) $raw );
			default:
				return is_array( $raw ) ? $raw : sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * Whether we are running under WP-CLI.
	 *
	 * @return bool
	 */
	public static function is_cli() {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Resolve a URL (absolute or relative) to a post ID.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	public static function url_to_post( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return 0;
		}
		$absolute = self::abs_url( $url );
		$id       = (int) url_to_postid( $absolute );
		if ( $id ) {
			return $id;
		}

		$path = (string) wp_parse_url( $absolute, PHP_URL_PATH );
		$path = trim( (string) $path, '/' );
		if ( '' === $path ) {
			return (int) get_option( 'page_on_front' );
		}
		$parts = explode( '/', $path );
		for ( $i = count( $parts ); $i > 0; $i-- ) {
			$slug   = implode( '/', array_slice( $parts, 0, $i ) );
			$page   = get_page_by_path( $slug );
			if ( $page && isset( $page->ID ) ) {
				return (int) $page->ID;
			}
		}
		return 0;
	}

}
