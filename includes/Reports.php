<?php
/**
 * CSV / HTML exports and the shareable report.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Reports
 */
final class Reports {

	/**
	 * Rows for an export type. Row 0 is the header for CSV types.
	 *
	 * @param string $type Type.
	 * @return array
	 */
	public static function export_rows( $type ) {
		$type = sanitize_key( (string) $type );

		switch ( $type ) {
			case 'report':
				return self::report_payload();
			case 'keywords':
				return self::rows_keywords();
			case 'audit':
				return self::rows_audit();
			case 'pages':
				return self::rows_pages();
			case 'redirects':
				return self::rows_redirects();
			case 'notfound':
				return self::rows_notfound();
			case 'links':
				return self::rows_links();
			case 'clicks':
				return self::rows_clicks();
			case 'positions':
				return self::rows_positions();
			case 'research':
				return self::rows_research();
			case 'images':
				return self::rows_images();
			case 'schema':
				return self::rows_schema();
			case 'social':
				return self::rows_social();
			case 'settings':
				return self::rows_settings();
		}

		return array( array( __( 'نوع خروجی نامعتبر است', 'hoosh-seo' ) ) );
	}

	/**
	 * Keywords + focus terms table.
	 *
	 * @return array
	 */
	protected static function rows_keywords() {
		global $wpdb;
		$header = array(
			__( 'کلمه کلیدی', 'hoosh-seo' ),
			__( 'نوع', 'hoosh-seo' ),
			__( 'وضعیت', 'hoosh-seo' ),
			__( 'حجم جستجو', 'hoosh-seo' ),
			__( 'سختی', 'hoosh-seo' ),
			__( 'CPC', 'hoosh-seo' ),
			__( 'رقابت', 'hoosh-seo' ),
			__( 'نوشته مرتبط', 'hoosh-seo' ),
			__( 'آخرین رتبه', 'hoosh-seo' ),
			__( 'تاریخ', 'hoosh-seo' ),
		);
		$rows = array( $header );

		$items = Modules\KeywordResearch::all( array( 'per' => 5000, 'status' => 'all' ) );
		foreach ( (array) ( $items['rows'] ?? array() ) as $row ) {
			$rows[] = array(
				$row['keyword'],
				$row['kind'],
				$row['state'],
				$row['volume'],
				$row['difficulty'],
				$row['cpc'],
				$row['competition'],
				$row['post_id'] ? get_the_title( (int) $row['post_id'] ) : '',
				$row['position'],
				$row['updated'],
			);
		}
		unset( $wpdb );
		return $rows;
	}

	/**
	 * Audit changelog.
	 *
	 * @return array
	 */
	protected static function rows_audit() {
		$header = array( 'id', __( 'زمان', 'hoosh-seo' ), __( 'ماژول', 'hoosh-seo' ), __( 'کنش', 'hoosh-seo' ), __( 'توضیح', 'hoosh-seo' ), __( 'کاربر', 'hoosh-seo' ), __( 'آدرس', 'hoosh-seo' ), __( 'بازگردانی', 'hoosh-seo' ) );
		$rows   = array( $header );
		$list   = Modules\Audit::listing( array( 'per' => 1000 ) );
		foreach ( (array) ( $list['rows'] ?? array() ) as $row ) {
			$rows[] = array(
				$row['id'],
				$row['created_at'],
				$row['scope'],
				$row['action'],
				$row['label'],
				$row['user'],
				$row['url'],
				(int) $row['reverted'] ? '1' : '0',
			);
		}
		return $rows;
	}

	/**
	 * Analysed pages.
	 *
	 * @return array
	 */
	protected static function rows_pages() {
		$header = array(
			__( 'آدرس', 'hoosh-seo' ),
			__( 'عنوان', 'hoosh-seo' ),
			__( 'نوع', 'hoosh-seo' ),
			__( 'کلمه کلیدی', 'hoosh-seo' ),
			__( 'واژگان', 'hoosh-seo' ),
			__( 'امتیاز سئو', 'hoosh-seo' ),
			__( 'خوانایی', 'hoosh-seo' ),
			__( 'کل امتیاز', 'hoosh-seo' ),
			__( 'لینک ورودی', 'hoosh-seo' ),
			__( 'لینک خروجی', 'hoosh-seo' ),
			__( 'وضعیت', 'hoosh-seo' ),
			__( 'مشکلات', 'hoosh-seo' ),
		);
		$rows   = array( $header );
		$list   = Modules\Content::pages( array( 'per' => 5000, 'filter' => 'all' ) );
		foreach ( (array) ( $list['rows'] ?? array() ) as $row ) {
			$rows[] = array(
				$row['url'],
				$row['title'],
				$row['type'],
				implode( ', ', (array) ( $row['keywords'] ?? array() ) ),
				$row['word_count'],
				$row['seo_score'],
				$row['readability'],
				$row['score'],
				$row['links_in'],
				$row['links_out'],
				$row['state'],
				$row['issues'] ? implode( ' | ', array_map( array( __CLASS__, 'issue_text' ), (array) $row['issues'] ) ) : '',
			);
		}
		return $rows;
	}

	/**
	 * Redirects.
	 *
	 * @return array
	 */
	protected static function rows_redirects() {
		$header = array( 'id', __( 'مبدأ', 'hoosh-seo' ), __( 'مقصد', 'hoosh-seo' ), __( 'نوع', 'hoosh-seo' ), __( 'وضعیت', 'hoosh-seo' ), __( 'بازدید', 'hoosh-seo' ), __( 'گروه', 'hoosh-seo' ), __( 'یادداشت', 'hoosh-seo' ), __( 'ساخته', 'hoosh-seo' ) );
		$rows   = array( $header );
		$list   = Modules\Redirects::listing( array( 'per' => 10000, 'status' => '' ) );
		foreach ( (array) ( $list['rows'] ?? array() ) as $row ) {
			$rows[] = array(
				$row['id'],
				$row['source'],
				$row['target'],
				$row['type'],
				$row['status'],
				$row['hits'],
				$row['group_name'],
				$row['notes'],
				$row['created_at'],
			);
		}
		return $rows;
	}

	/**
	 * 404 log.
	 *
	 * @return array
	 */
	protected static function rows_notfound() {
		$header = array( 'id', __( 'آدرس', 'hoosh-seo' ), __( 'تعداد', 'hoosh-seo' ), __( 'وضعیت', 'hoosh-seo' ), __( 'منبع', 'hoosh-seo' ), __( 'وضعیت پرونده', 'hoosh-seo' ), __( 'اولین مشاهده', 'hoosh-seo' ), __( 'آخرین مشاهده', 'hoosh-seo' ), __( 'پیشنهاد', 'hoosh-seo' ) );
		$rows   = array( $header );
		$list   = Modules\NotFound::listing( array( 'per' => 2000, 'state' => '' ) );
		foreach ( (array) ( $list['rows'] ?? array() ) as $row ) {
			$guess = isset( $row['suggestion'] ) ? $row['suggestion'] : Modules\NotFound::guess_target( (string) $row['url'] );
			$rows[] = array(
				$row['id'],
				$row['url'],
				$row['hits'],
				$row['status_code'],
				$row['referer'],
				$row['state'],
				$row['first_seen'],
				$row['last_seen'],
				$guess['url'] ?? '',
			);
		}
		return $rows;
	}

	/**
	 * Internal link rules.
	 *
	 * @return array
	 */
	protected static function rows_links() {
		$header = array( 'id', __( 'کلمه کلیدی', 'hoosh-seo' ), __( 'مقصد', 'hoosh-seo' ), __( 'عنوان مقصد', 'hoosh-seo' ), __( 'حداکثر', 'hoosh-seo' ), __( 'نوبت', 'hoosh-seo' ), __( 'جدید', 'hoosh-seo' ), __( 'nofollow', 'hoosh-seo' ), __( 'کلیک', 'hoosh-seo' ), __( 'وضعیت', 'hoosh-seo' ) );
		$rows   = array( $header );
		$list   = Modules\Links::listing( array( 'per' => 2000 ) );
		foreach ( (array) ( $list['rows'] ?? array() ) as $row ) {
			$rows[] = array(
				$row['id'],
				$row['keyword'],
				$row['url'],
				$row['target_title'],
				$row['max_links'],
				$row['link_nth'],
				empty( $row['new_tab'] ) ? '0' : '1',
				empty( $row['nofollow'] ) ? '0' : '1',
				$row['clicks'],
				$row['status'],
			);
		}
		return $rows;
	}

	/**
	 * Link clicks.
	 *
	 * @return array
	 */
	protected static function rows_clicks() {
		$header = array( 'id', __( 'قانون', 'hoosh-seo' ), __( 'نوشته', 'hoosh-seo' ), __( 'کلیک', 'hoosh-seo' ), __( 'معتبر', 'hoosh-seo' ), __( 'منبع', 'hoosh-seo' ), __( 'زمان', 'hoosh-seo' ) );
		$rows   = array( $header );
		global $wpdb;
		if ( ! Database::exists( 'link_log' ) ) {
			return $rows;
		}
		$items = (array) $wpdb->get_results(
			'SELECT l.id, l.keyword, ll.post_id, ll.created_at, ll.referer, ll.valid,
				(SELECT COUNT(*) FROM ' . Database::table( 'link_log' ) . ' x WHERE x.link_id = l.id) clicks
			 FROM ' . Database::table( 'links' ) . ' l
			 INNER JOIN ' . Database::table( 'link_log' ) . ' ll ON ll.link_id = l.id
			 ORDER BY ll.id DESC LIMIT 5000',
			ARRAY_A
		); // phpcs:ignore
		foreach ( $items as $row ) {
			$rows[] = array(
				$row['id'],
				$row['keyword'],
				$row['post_id'],
				$row['clicks'],
				(int) $row['valid'] ? '1' : '0',
				$row['referer'],
				$row['created_at'],
			);
		}
		return $rows;
	}

	/**
	 * Rank positions.
	 *
	 * @return array
	 */
	protected static function rows_positions() {
		global $wpdb;
		$header = array( __( 'کلمه کلیدی', 'hoosh-seo' ), __( 'تاریخ', 'hoosh-seo' ), __( 'رتبه', 'hoosh-seo' ), __( 'رتبه قبلی', 'hoosh-seo' ), __( 'تغییر', 'hoosh-seo' ), __( 'صفحه', 'hoosh-seo' ), __( 'کلیک', 'hoosh-seo' ), __( 'بازدید', 'hoosh-seo' ), __( 'دستگاه', 'hoosh-seo' ) );
		$rows   = array( $header );
		if ( ! Database::exists( 'positions' ) ) {
			return $rows;
		}
		$items = (array) $wpdb->get_results( // phpcs:ignore
			'SELECT p.*, k.phrase FROM ' . Database::table( 'positions' ) . ' p
			 LEFT JOIN ' . Database::table( 'keywords' ) . ' k ON k.id = p.keyword_id
			 ORDER BY p.recorded_on DESC, p.id DESC LIMIT 20000',
			ARRAY_A
		);
		foreach ( $items as $row ) {
			$delta = ( (float) $row['prev_position'] ) - ( (float) $row['position'] );
			$rows[] = array(
				(string) ( $row['phrase'] ?? '' ),
				(string) $row['recorded_on'],
				(float) $row['position'],
				(float) $row['prev_position'],
				$delta > 0 ? '+' . round( $delta, 1 ) : ( $delta < 0 ? round( $delta, 1 ) : '0' ),
				(string) $row['url'],
				(int) $row['clicks'],
				(int) $row['impressions'],
				(string) $row['device'],
			);
		}
		return $rows;
	}

	/**
	 * Keyword research results.
	 *
	 * @return array
	 */
	protected static function rows_research() {
		global $wpdb;
		$header = array( __( 'کلمه', 'hoosh-seo' ), __( 'منبع', 'hoosh-seo' ), __( 'حجم', 'hoosh-seo' ), __( 'سختی', 'hoosh-seo' ), __( 'CPC', 'hoosh-seo' ), __( 'رقابت', 'hoosh-seo' ), __( 'نیّت', 'hoosh-seo' ), __( 'جلسه', 'hoosh-seo' ), __( 'تاریخ', 'hoosh-seo' ) );
		$rows   = array( $header );

		if ( ! Database::exists( 'kw_research' ) ) {
			return $rows;
		}

		$items = array();
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . Database::table( 'kw_research' ) . ' ORDER BY id DESC LIMIT 25', ARRAY_A ) as $session ) { // phpcs:ignore
			$decoded = json_decode( (string) $session['items'], true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $item ) {
				if ( ! is_array( $item ) ) {
					$item = array( 'keyword' => (string) $item );
				}
				$rows[] = array(
					(string) ( $item['keyword'] ?? '' ),
					(string) ( $item['source'] ?? '' ),
					isset( $item['volume'] ) ? (int) $item['volume'] : '',
					isset( $item['difficulty'] ) ? (int) $item['difficulty'] : '',
					isset( $item['cpc'] ) ? (float) $item['cpc'] : '',
					isset( $item['competition'] ) ? (string) $item['competition'] : '',
					(string) ( $item['intent'] ?? '' ),
					(int) $session['id'],
					(string) $session['created_at'],
				);
				if ( count( $rows ) > 20000 ) {
					break 2;
				}
			}
		}
		unset( $items );

		return $rows;
	}

	/**
	 * Image report.
	 *
	 * @return array
	 */
	protected static function rows_images() {
		$header = array( 'id', __( 'فایل', 'hoosh-seo' ), __( 'alt فعلی', 'hoosh-seo' ), __( 'alt پیشنهادی', 'hoosh-seo' ), __( 'KB', 'hoosh-seo' ), __( 'عرض', 'hoosh-seo' ), __( 'ارتفاع', 'hoosh-seo' ), __( 'مشکلات', 'hoosh-seo' ) );
		$rows   = array( $header );
		$list   = Modules\Images::missing( array( 'type' => 'all', 'per_page' => 500, 'page' => 1 ) );
		foreach ( (array) ( $list['rows'] ?? array() ) as $row ) {
			$rows[] = array(
				$row['id'],
				$row['file'],
				$row['alt'],
				$row['suggested'],
				$row['size_kb'],
				$row['width'],
				$row['height'],
				implode( ',', (array) $row['problems'] ),
			);
		}
		return $rows;
	}

	/**
	 * Schema rows.
	 *
	 * @return array
	 */
	protected static function rows_schema() {
		$header = array( 'id', __( 'نام', 'hoosh-seo' ), __( 'نوع', 'hoosh-seo' ), __( 'وضعیت', 'hoosh-seo' ), __( 'چاپ‌ها', 'hoosh-seo' ), __( 'JSON', 'hoosh-seo' ) );
		$rows   = array( $header );
		foreach ( (array) Modules\Schema::listing() as $row ) {
			$rows[] = array(
				$row['id'],
				$row['name'],
				$row['schema_type'],
				$row['status'],
				$row['print_count'],
				wp_json_encode( $row['data'], JSON_UNESCAPED_UNICODE ),
			);
		}
		return $rows;
	}

	/**
	 * Social/open-graph settings snapshot.
	 *
	 * @return array
	 */
	protected static function rows_social() {
		$rows = array( array( __( 'کلید', 'hoosh-seo' ), __( 'مقدار', 'hoosh-seo' ) ) );
		foreach ( array( 'social.facebook_app_id', 'social.twitter_card', 'social.pinterest', 'social.default_image_id', 'social.og_title_format' ) as $key ) {
			$rows[] = array( $key, (string) hoosh_seo()->settings->get( $key, '' ) );
		}
		return $rows;
	}

	/**
	 * Full settings tree (safe, no secrets).
	 *
	 * @return array
	 */
	protected static function rows_settings() {
		$rows = array( array( __( 'کلید', 'hoosh-seo' ), __( 'مقدار', 'hoosh-seo' ) ) );
		$tree = hoosh_seo()->settings->export( false );
		$flat = self::flatten( (array) $tree );
		foreach ( $flat as $key => $value ) {
			$rows[] = array( $key, is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) );
		}
		return $rows;
	}

	/**
	 * One issue row -> printable text.
	 *
	 * @param array $issue Issue.
	 * @return string
	 */
	public static function issue_text( $issue ) {
		if ( ! is_array( $issue ) ) {
			return (string) $issue;
		}
		$text = (string) ( $issue['label'] ?? $issue['key'] ?? '' );
		if ( isset( $issue['severity'] ) && in_array( (string) $issue['severity'], array( 'critical', 'error' ), true ) ) {
			$text .= ' (' . Helpers::severity_label( (string) $issue['severity'] ) . ')';
		}
		return $text;
	}

	/**
	 * Flatten nested arrays into dot keys.
	 *
	 * @param array  $data Data.
	 * @param string $prefix Prefix.
	 * @return array
	 */
	protected static function flatten( $data, $prefix = '' ) {
		$out = array();
		foreach ( (array) $data as $key => $value ) {
			$name = $prefix ? $prefix . '.' . $key : (string) $key;
			if ( is_array( $value ) ) {
				$out += self::flatten( $value, $name );
				continue;
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * The shareable report payload.
	 *
	 * @return array
	 */
	public static function report_payload() {
		$snapshot  = Modules\Audit::quick_snapshot();
		$pages     = Modules\Content::pages( array( 'per_page' => 10, 'order' => 'asc' ) );
		$distribution = Modules\Content::distribution();
		$top_issues   = Modules\Content::top_issues( 10 );
		$links        = Modules\Links::report( 'top' );
		$index        = Modules\IndexManager::summary();
		$images       = Modules\Images::summary();
		$keywords     = Modules\KeywordResearch::summary();
		$rank         = class_exists( __NAMESPACE__ . '\\Modules\\RankTracker' ) ? Modules\RankTracker::summary() : array();
		$ai           = \HooshSEO\AI\Gateway::spend_summary();
		$notfound     = Modules\NotFound::listing( array( 'per' => 10, 'orderby' => 'hits', 'order' => 'DESC' ) );

		$sections = array();

		$sections[] = array(
			'id'     => 'health',
			'title'  => __( 'سلامت کلی سایت', 'hoosh-seo' ),
			'kpis'   => array(
				array( 'label' => __( 'امتیاز میانگین', 'hoosh-seo' ), 'value' => $snapshot['score'], 'suffix' => '/۱۰۰' ),
				array( 'label' => __( 'موارد بحرانی', 'hoosh-seo' ), 'value' => $snapshot['critical'] ),
				array( 'label' => __( 'هشدارها', 'hoosh-seo' ), 'value' => $snapshot['warnings'] ),
				array( 'label' => __( 'پوشش سایت‌مپ', 'hoosh-seo' ), 'value' => $snapshot['coverage'], 'suffix' => '%' ),
				array( 'label' => __( 'تصاویر بدون alt', 'hoosh-seo' ), 'value' => $images['missing'] ),
				array( 'label' => __( '۴۰۴ باز', 'hoosh-seo' ), 'value' => $snapshot['404'] ),
				array( 'label' => __( 'ریدایرکت فعال', 'hoosh-seo' ), 'value' => $snapshot['redirects'] ),
				array( 'label' => __( 'لینک شکسته', 'hoosh-seo' ), 'value' => $snapshot['broken'] ),
			),
		);

		$sections[] = array(
			'id'     => 'distribution',
			'title'  => __( 'توزیع امتیازها', 'hoosh-seo' ),
			'bars'   => $distribution,
		);

		$sections[] = array(
			'id'      => 'issues',
			'title'   => __( 'شایع‌ترین مشکلات', 'hoosh-seo' ),
			'columns' => array( __( 'مشکل', 'hoosh-seo' ), __( 'تعداد', 'hoosh-seo' ), __( 'شدت', 'hoosh-seo' ) ),
			'rows'    => array_map(
				function ( $issue ) {
					return array( $issue['label'], $issue['count'], Helpers::severity_label( (string) ( $issue['severity'] ?? 'notice' ) ) );
				},
				(array) $top_issues
			),
		);

		$sections[] = array(
			'id'      => 'worst',
			'title'   => __( 'ضعیف‌ترین صفحه‌ها', 'hoosh-seo' ),
			'columns' => array( __( 'صفحه', 'hoosh-seo' ), __( 'امتیاز', 'hoosh-seo' ), __( 'کلمه', 'hoosh-seo' ), __( 'واژگان', 'hoosh-seo' ) ),
			'rows'    => array_map(
				function ( $row ) {
					return array( $row['title'], $row['score'], $row['keyword'], $row['word_count'] );
				},
				(array) ( $pages['rows'] ?? array() )
			),
		);

		$sections[] = array(
			'id'    => 'index',
			'title' => __( 'ایندکس', 'hoosh-seo' ),
			'kpis'  => array(
				array( 'label' => __( 'صفحه ایندکس‌پذیر', 'hoosh-seo' ), 'value' => (int) ( $index['indexable'] ?? 0 ) ),
				array( 'label' => __( 'noindex', 'hoosh-seo' ), 'value' => (int) ( $index['noindex'] ?? 0 ) ),
				array( 'label' => __( 'آخرین ارسال IndexNow', 'hoosh-seo' ), 'value' => (string) ( $index['indexnow']['last'] ?? '' ) ),
			),
		);

		if ( $notfound ) {
			$sections[] = array(
				'id'      => 'notfound',
				'title'   => __( 'پُرتکرارترین خطاهای ۴۰۴', 'hoosh-seo' ),
				'columns' => array( __( 'آدرس', 'hoosh-seo' ), __( 'تعداد', 'hoosh-seo' ), __( 'منبع', 'hoosh-seo' ) ),
				'rows'    => array_map(
					function ( $row ) {
						return array( $row['url'], $row['hits'], $row['referer'] );
					},
					(array) ( $notfound['top'] ?? array() )
				),
			);
		}

		if ( $links ) {
			$sections[] = array(
				'id'      => 'links',
				'title'   => __( 'لینک‌سازی داخلی', 'hoosh-seo' ),
				'columns' => array( __( 'کلمه', 'hoosh-seo' ), __( 'کلیک', 'hoosh-seo' ), __( 'مقصد', 'hoosh-seo' ) ),
				'rows'    => array_map(
					function ( $row ) {
						return array( $row['keyword'], $row['clicks'], (string) ( $row['url'] ?? '' ) );
					},
					(array) ( $links['rows'] ?? array() )
				),
			);
		}

		if ( $rank ) {
			$sections[] = array(
				'id'    => 'rank',
				'title' => __( 'وضعیت کلمات کلیدی', 'hoosh-seo' ),
				'kpis'  => array(
					array( 'label' => __( 'در صفحه اول', 'hoosh-seo' ), 'value' => (int) ( $rank['top10'] ?? 0 ) ),
					array( 'label' => __( 'بهبود ۳۰ روزه', 'hoosh-seo' ), 'value' => (int) ( $rank['improved'] ?? 0 ) ),
					array( 'label' => __( 'افت ۳۰ روزه', 'hoosh-seo' ), 'value' => (int) ( $rank['lost'] ?? 0 ) ),
					array( 'label' => __( 'میانگین رتبه', 'hoosh-seo' ), 'value' => (float) ( $rank['average'] ?? 0 ) ),
				),
			);
		}

		if ( $keywords ) {
			$sections[] = array(
				'id'    => 'keywords',
				'title' => __( 'پژوهش کلمه کلیدی', 'hoosh-seo' ),
				'kpis'  => array(
					array( 'label' => __( 'کلمات یافت‌شده', 'hoosh-seo' ), 'value' => (int) ( $keywords['found'] ?? 0 ) ),
					array( 'label' => __( 'ذخیره‌شده', 'hoosh-seo' ), 'value' => (int) ( $keywords['saved'] ?? 0 ) ),
					array( 'label' => __( 'در حال ردیابی', 'hoosh-seo' ), 'value' => (int) ( $keywords['tracking'] ?? 0 ) ),
				),
			);
		}

		$sections[] = array(
			'id'    => 'ai',
			'title' => __( 'هوش مصنوعی', 'hoosh-seo' ),
			'kpis'  => array(
				array( 'label' => __( 'تعداد درخواست', 'hoosh-seo' ), 'value' => (int) ( $ai['calls'] ?? 0 ) ),
				array( 'label' => __( 'توکن مصرفی', 'hoosh-seo' ), 'value' => (int) ( $ai['tokens'] ?? 0 ) ),
				array( 'label' => __( 'هزینه برآوردی', 'hoosh-seo' ), 'value' => (float) ( $ai['cost'] ?? 0 ), 'suffix' => '$' ),
			),
		);

		return array(
			'title'     => sprintf( /* translators: %s site */ __( 'گزارش سئو — %s', 'hoosh-seo' ), get_bloginfo( 'name' ) ),
			'subtitle'  => __( 'تهیه‌شده با هوش‌سئو', 'hoosh-seo' ),
			'generated' => current_time( 'mysql' ),
			'generated_fa' => Helpers::jalali_date( 'j F Y، H:i', time() ),
			'site'      => array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
				'lang' => get_locale(),
			),
			'snapshot'  => $snapshot,
			'sections'  => $sections,
		);
	}

	/**
	 * Render a printable standalone HTML report.
	 *
	 * @param array  $payload Report payload (or CSV rows).
	 * @param string $name    File name hint.
	 */
	public static function stream_html( $payload, $name = 'HooshSEO-report' ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'Content-Disposition: inline; filename="' . preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name ) . '.html"' );
			header( 'Cache-Control: no-store' );
			header( 'X-Content-Type-Options: nosniff' );
		}

		$sections = isset( $payload['sections'] ) ? (array) $payload['sections'] : array(
			array( 'title' => __( 'خروجی', 'hoosh-seo' ), 'columns' => array(), 'rows' => (array) $payload ),
		);

		echo self::render_html( $payload, $sections );
	}

	/**
	 * Build the HTML (used for streaming, emails and the Studio preview).
	 *
	 * @param array $payload  Report payload.
	 * @param array $sections Sections.
	 * @return string
	 */
	public static function render_html( $payload, $sections = null ) {
		$sections = null === $sections ? (array) ( $payload['sections'] ?? array() ) : (array) $sections;
		$title    = isset( $payload['title'] ) ? (string) $payload['title'] : __( 'گزارش سئو', 'hoosh-seo' );
		$subtitle = isset( $payload['subtitle'] ) ? (string) $payload['subtitle'] : '';
		$date     = isset( $payload['generated_fa'] ) ? (string) $payload['generated_fa'] : Helpers::jalali_date( 'j F Y', time() );
		$accent   = (string) hoosh_seo()->settings->get( 'appearance.accent', 'indigo' );
		$digits   = (bool) hoosh_seo()->settings->get( 'appearance.persian_digits', true );

		$css = self::report_css( $accent );

		ob_start();
		?>
<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $title ); ?></title>
<style><?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
</head>
<body class="hs-report">
<header class="rp-head">
	<div>
		<h1><?php echo esc_html( $title ); ?></h1>
		<?php if ( $subtitle ) : ?>
			<p class="rp-sub"><?php echo esc_html( $subtitle ); ?></p>
		<?php endif; ?>
	</div>
	<div class="rp-meta">
		<span><?php echo esc_html( $date ); ?></span>
		<?php if ( ! empty( $payload['site']['url'] ) ) : ?>
			<a href="<?php echo esc_url( $payload['site']['url'] ); ?>"><?php echo esc_html( wp_parse_url( $payload['site']['url'], PHP_URL_HOST ) ); ?></a>
		<?php endif; ?>
	</div>
</header>
		<?php
		foreach ( $sections as $section ) :
			if ( empty( $section ) || ! is_array( $section ) ) {
				continue;
			}
			$heading = isset( $section['title'] ) ? (string) $section['title'] : __( 'بخش', 'hoosh-seo' );
			?>
<section class="rp-card">
	<h2><?php echo esc_html( $heading ); ?></h2>
			<?php if ( ! empty( $section['kpis'] ) ) : ?>
	<div class="rp-kpis">
				<?php foreach ( (array) $section['kpis'] as $kpi ) : ?>
		<div class="rp-kpi">
			<b><?php echo esc_html( Helpers::digits( (string) ( $kpi['value'] ?? 0 ), $digits ) ); ?><?php echo isset( $kpi['suffix'] ) ? esc_html( $kpi['suffix'] ) : ''; ?></b>
			<span><?php echo esc_html( (string) ( $kpi['label'] ?? '' ) ); ?></span>
		</div>
				<?php endforeach; ?>
	</div>
			<?php endif; ?>

			<?php if ( ! empty( $section['bars'] ) ) : $max = 1; foreach ( (array) $section['bars'] as $bar ) { $max = max( $max, (int) ( $bar['value'] ?? $bar['count'] ?? 0 ) ); } ?>
	<div class="rp-bars">
				<?php foreach ( (array) $section['bars'] as $bar ) : ?>
		<div class="rp-bar">
			<span class="rp-bar__label"><?php echo esc_html( (string) ( $bar['label'] ?? '' ) ); ?></span>
			<span class="rp-bar__track"><i style="width:<?php echo esc_attr( (string) ( (int) ( $bar['value'] ?? $bar['count'] ?? 0 ) / max( 1, $max ) * 100 ) ); ?>%"></i></span>
			<span class="rp-bar__value"><?php echo esc_html( Helpers::number( (int) ( $bar['value'] ?? $bar['count'] ?? 0 ) ) ); ?></span>
		</div>
				<?php endforeach; ?>
	</div>
			<?php endif; ?>

			<?php if ( ! empty( $section['rows'] ) ) : ?>
		<?php $is_list = is_array( reset( $section['rows'] ) ); ?>
		<?php if ( $is_list ) : ?>
	<table class="rp-table">
		<?php if ( ! empty( $section['columns'] ) ) : ?>
		<thead><tr><?php foreach ( (array) $section['columns'] as $column ) : ?><th><?php echo esc_html( (string) $column ); ?></th><?php endforeach; ?></tr></thead>
		<?php endif; ?>
		<tbody>
			<?php foreach ( (array) $section['rows'] as $line ) : ?>
			<tr><?php foreach ( (array) $line as $cell ) : ?><td><?php echo esc_html( is_scalar( $cell ) ? (string) Helpers::digits( (string) $cell, $digits ) : wp_json_encode( $cell ) ); ?></td><?php endforeach; ?></tr>
			<?php endforeach; ?>
		</tbody>
	</table>
		<?php else : ?>
	<ul class="rp-list">
			<?php foreach ( (array) $section['rows'] as $line ) : ?>
		<li><?php echo esc_html( is_scalar( $line ) ? (string) Helpers::digits( (string) $line, $digits ) : wp_json_encode( $line ) ); ?></li>
			<?php endforeach; ?>
	</ul>
		<?php endif; ?>
			<?php endif; ?>
</section>
		<?php endforeach; ?>
<footer class="rp-foot">
	<span><?php esc_html_e( 'ساخته‌شده با افزونه هوش‌سئو', 'hoosh-seo' ); ?></span>
	<button type="button" onclick="window.print()"><?php esc_html_e( 'چاپ / ذخیره PDF', 'hoosh-seo' ); ?></button>
</footer>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Report stylesheet.
	 *
	 * @param string $accent Accent key.
	 * @return string
	 */
	protected static function report_css( $accent ) {
		$accents = array(
			'firouzeh' => '#14a58f',
			'lajevard' => '#7c4dff',
			'zahresh'  => '#10b981',
			'anabi'    => '#2f6fed',
			'sormeh'   => '#3f5170',
			'tajalli'  => '#e0870b',
			/* legacy aliases — older stored values still resolve. */
			'indigo'   => '#4f46e5',
			'blue'     => '#2563eb',
			'violet'   => '#7c3aed',
			'teal'     => '#0d9488',
			'green'    => '#16a34a',
			'orange'   => '#ea580c',
			'rose'     => '#e11d48',
		);
		$color = isset( $accents[ $accent ] ) ? $accents[ $accent ] : $accents['firouzeh'];

		return ':root{--a:' . $color . ';--ink:#0f172a;--mut:#64748b;--line:#e6e8ef;--bg:#f6f7fb;--ok:#16a34a;--warn:#d97706;--bad:#dc2626}'
			. '*{box-sizing:border-box}body{margin:0;padding:24px;background:var(--bg);color:var(--ink);font:14px/1.7 Vazirmatn,Tahoma,"Segoe UI",sans-serif}'
			. '.rp-head{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;padding:20px 22px;border-radius:16px;background:linear-gradient(135deg,var(--a),#111827);color:#fff;margin-bottom:18px;flex-wrap:wrap}'
			. '.rp-head h1{margin:0;font-size:22px}.rp-sub{margin:4px 0 0;opacity:.85}.rp-meta{display:flex;flex-direction:column;align-items:flex-start;font-size:12px;opacity:.9}.rp-meta a{color:#fff;text-decoration:underline}'
			. '.rp-card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-bottom:16px;break-inside:avoid}'
			. '.rp-card h2{margin:0 0 12px;font-size:16px;color:var(--a)}'
			. '.rp-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px}'
			. '.rp-kpi{border:1px solid var(--line);border-radius:12px;padding:12px;background:#fbfcfe}.rp-kpi b{display:block;font-size:20px}.rp-kpi span{color:var(--mut);font-size:12px}'
			. '.rp-bars{display:grid;gap:8px}.rp-bar{display:grid;grid-template-columns:150px 1fr 60px;align-items:center;gap:10px}.rp-bar__label{color:var(--mut);font-size:12px}.rp-bar__track{display:block;height:10px;border-radius:99px;background:#eef0f6;overflow:hidden}.rp-bar__track i{display:block;height:100%;background:var(--a);border-radius:99px}.rp-bar__value{text-align:left;font-variant-numeric:tabular-nums;font-size:12px}'
			. '.rp-table{width:100%;border-collapse:collapse;margin-top:6px;font-size:13px}.rp-table th,.rp-table td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:right}.rp-table th{background:#f8fafc;color:var(--mut);font-weight:600;font-size:12px}'
			. '.rp-list{margin:0;padding-inline-start:18px}.rp-foot{display:flex;justify-content:space-between;align-items:center;color:var(--mut);font-size:12px;padding:6px 4px}.rp-foot button{border:1px solid var(--line);background:#fff;border-radius:10px;padding:7px 12px;font:inherit;cursor:pointer}'
			. '@media print{body{background:#fff;padding:0}.rp-card{border:none;padding:8px 0}.rp-head{border-radius:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}.rp-foot button{display:none}}';
	}

	/**
	 * Email the report to a list of addresses.
	 *
	 * @param array $args {to, subject, attachments}.
	 * @return array
	 */
	public static function email( $args = array() ) {
		$settings = hoosh_seo()->settings;
		$args = wp_parse_args(
			(array) $args,
			array(
				'to'      => (array) $settings->get( 'reports.email_to', array( get_option( 'admin_email' ) ) ),
				'subject' => (string) $settings->get( 'reports.email_subject', __( 'گزارش هفتگی سئوی شما', 'hoosh-seo' ) ),
				'format'  => (string) $settings->get( 'reports.email_format', 'inline' ),
			)
		);

		$payload = self::report_payload();
		$to      = array_values( array_filter( array_map( 'sanitize_email', (array) $args['to'] ) ) );
		if ( ! $to ) {
			return array( 'ok' => false, 'message' => __( 'گیرنده‌ای معتبر نیست.', 'hoosh-seo' ) );
		}

		$html = self::render_html( $payload );
		$subject = trim( (string) $args['subject'] ) ?: $payload['title'];

		$files = array();
		if ( 'attach' === $args['format'] ) {
			$dir = wp_upload_dir();
			$path = trailingslashit( $dir['path'] ) . 'hoosh-report-' . gmdate( 'Ymd-His' ) . '.html';
			if ( file_put_contents( $path, $html ) ) { // phpcs:ignore
				$files[] = $path;
			}
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>' );
		$sent    = wp_mail( $to, $subject, 'attach' === $args['format'] ? __( 'گزارش در فایل پیوست است.', 'hoosh-seo' ) : $html, $headers, $files );

		foreach ( $files as $file ) {
			@unlink( $file ); // phpcs:ignore
		}

		Modules\Audit::log( 'automation', 'report_email', array( 'label' => __( 'گزارش ایمیلی ارسال شد', 'hoosh-seo' ), 'after' => array( 'sent' => (bool) $sent, 'to' => count( $to ) ) ) );

		return array(
			'ok'      => (bool) $sent,
			'sent'    => (bool) $sent,
			'recipients' => count( $to ),
			'message' => $sent ? __( 'گزارش ارسال شد.', 'hoosh-seo' ) : __( 'ارسال ناموفق بود (wp_mail را بررسی کنید).', 'hoosh-seo' ),
		);
	}

	/**
	 * Convenience wrapper used by automation.
	 *
	 * @param array $args {to, subject, attach}.
	 * @return array
	 */
	public static function email_report( $args = array() ) {
		$args = wp_parse_args( (array) $args, array( 'attach' => false ) );
		if ( ! empty( $args['attach'] ) ) {
			$args['format'] = 'attach';
		}
		return self::email( $args );
	}

}
