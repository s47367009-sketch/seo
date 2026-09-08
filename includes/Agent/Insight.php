<?php
/**
 * Site insight — the agent's eyes.
 *
 * Turns whatever data the site actually has (Search Console, the local
 * keyword table, the rank tracker, the content audit, PageSpeed) into two
 * things the planner can act on:
 *
 *   - diagnose()    a prioritised list of what is wrong and why
 *   - opportunities() keywords worth writing for that nobody covers yet
 *
 * Every source is optional. A site with no Google connection still gets a
 * usable diagnosis from its own database, which is what lets the agent "find
 * nothing to fix" and move on to producing content instead.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Agent;

use HooshSEO\Helpers;
use HooshSEO\Modules\Analytics;
use HooshSEO\Modules\Content;
use HooshSEO\Modules\KeywordResearch;
use HooshSEO\Modules\RankTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Class Insight
 */
final class Insight {

	/**
	 * Which data sources answered on the last call. Exposed so the UI can be
	 * honest about what the diagnosis is based on.
	 *
	 * @var array
	 */
	protected static $sources = array();

	/**
	 * Sources used by the most recent diagnose()/opportunities() call.
	 *
	 * @return array
	 */
	public static function last_sources() {
		return self::$sources;
	}

	/**
	 * Full site diagnosis.
	 *
	 * @param int $days Look-back window for analytics.
	 * @return array {score, health, findings[], sources[], stats{}}
	 */
	public static function diagnose( $days = 28 ) {
		self::$sources = array();
		$findings      = array();
		$stats         = array();

		$counts   = self::counts();
		$stats['posts']      = $counts['posts'];
		$stats['indexed']    = $counts['indexed'];
		$stats['noindex']    = $counts['noindex'];
		$stats['no_meta']    = $counts['no_meta'];
		$stats['thin']       = $counts['thin'];
		$stats['orphans']    = $counts['orphans'];
		$stats['notfound']   = $counts['notfound'];
		$stats['avg_score']  = $counts['avg_score'];

		// --- Traffic -------------------------------------------------------
		$analytics = self::analytics( $days );
		if ( ! empty( $analytics['available'] ) ) {
			self::$sources[] = 'search-console';
			$stats['clicks']      = (int) $analytics['clicks'];
			$stats['impressions'] = (int) $analytics['impressions'];
			$stats['ctr']         = (float) $analytics['ctr'];
			$stats['position']    = (float) $analytics['position'];

			// Pages with impressions but a poor CTR: the cheapest wins in SEO.
			foreach ( (array) ( isset( $analytics['pages'] ) ? $analytics['pages'] : array() ) as $page ) {
				$impr = isset( $page['impressions'] ) ? (int) $page['impressions'] : 0;
				$ctr  = isset( $page['ctr'] ) ? (float) $page['ctr'] : 0;
				if ( $impr >= 100 && $ctr < 0.02 ) {
					$findings[] = self::finding(
						'ctr',
						'high',
						sprintf( /* translators: 1: page, 2: impressions, 3: ctr */ __( '«%1$s» با %2$s نمایش فقط %3$s کلیک می‌گیرد؛ عنوان و توضیحات متا را جذاب‌تر بنویسید.', 'hoosh-seo' ), mb_substr( (string) ( isset( $page['page'] ) ? $page['page'] : '' ), 0, 60 ), number_format_i18n( $impr ), round( $ctr * 100, 1 ) . '%' ),
						'meta_fill',
						array( 'url' => isset( $page['page'] ) ? $page['page'] : '', 'impressions' => $impr, 'ctr' => $ctr ),
						min( 100, $impr / 20 )
					);
				}
			}

			// Queries stuck on page two are one nudge away from traffic.
			foreach ( (array) ( isset( $analytics['queries'] ) ? $analytics['queries'] : array() ) as $q ) {
				$pos  = isset( $q['position'] ) ? (float) $q['position'] : 0;
				$impr = isset( $q['impressions'] ) ? (int) $q['impressions'] : 0;
				if ( $pos >= 8 && $pos <= 20 && $impr >= 20 ) {
					$findings[] = self::finding(
						'striking-distance',
						'high',
						sprintf( /* translators: 1: query, 2: position */ __( '«%1$s» در جایگاه %2$s است؛ با لینک داخلی و تقویت محتوا به صفحهٔ اول می‌رسد.', 'hoosh-seo' ), mb_substr( (string) ( isset( $q['query'] ) ? $q['query'] : '' ), 0, 50 ), round( $pos, 1 ) ),
						'internal_link',
						array( 'query' => isset( $q['query'] ) ? $q['query'] : '', 'position' => $pos ),
						min( 100, $impr )
					);
				}
			}
		}

		// --- Content health ------------------------------------------------
		if ( $counts['no_meta'] > 0 ) {
			$findings[] = self::finding(
				'missing-meta',
				$counts['no_meta'] > 20 ? 'critical' : 'high',
				sprintf( /* translators: %d: count */ __( '%d صفحه عنوان یا توضیحات متا ندارد.', 'hoosh-seo' ), $counts['no_meta'] ),
				'meta_fill',
				array( 'count' => $counts['no_meta'] ),
				90
			);
		}
		if ( $counts['thin'] > 0 ) {
			$findings[] = self::finding(
				'thin-content',
				$counts['thin'] > 20 ? 'high' : 'medium',
				sprintf( /* translators: %d: count */ __( '%d صفحه محتوای کوتاه دارد و ممکن است رتبه نگیرد.', 'hoosh-seo' ), $counts['thin'] ),
				'optimize_post',
				array( 'count' => $counts['thin'] ),
				75
			);
		}
		if ( $counts['orphans'] > 0 ) {
			$findings[] = self::finding(
				'orphans',
				'medium',
				sprintf( /* translators: %d: count */ __( '%d صفحه هیچ لینک ورودی داخلی ندارد (صفحهٔ یتیم).', 'hoosh-seo' ), $counts['orphans'] ),
				'internal_link',
				array( 'count' => $counts['orphans'] ),
				70
			);
		}
		if ( $counts['noindex'] > 0 ) {
			$findings[] = self::finding(
				'noindex',
				'critical',
				sprintf( /* translators: %d: count */ __( '%d صفحهٔ منتشرشده noindex است و اصلاً ایندکس نمی‌شود.', 'hoosh-seo' ), $counts['noindex'] ),
				'audit_fix',
				array( 'count' => $counts['noindex'] ),
				95
			);
		}
		if ( $counts['self_canonical'] > 0 ) {
			$findings[] = self::finding(
				'redundant-canonical',
				'medium',
				sprintf( /* translators: %d: count */ __( '%d صفحه کنونیکال دستیِ هم‌نشانی با خودش دارد؛ افزونه خودش این را چاپ می‌کند و تکراری آن فقط نویز است.', 'hoosh-seo' ), $counts['self_canonical'] ),
				'audit_fix',
				array( 'count' => $counts['self_canonical'] ),
				62
			);
		}
		if ( $counts['notfound'] > 0 ) {
			$findings[] = self::finding(
				'notfound',
				$counts['notfound'] > 30 ? 'high' : 'medium',
				sprintf( /* translators: %d: count */ __( '%d نشانی خطای ۴۰۴ ثبت شده که باید ریدایرکت شود.', 'hoosh-seo' ), $counts['notfound'] ),
				'redirect_404',
				array( 'count' => $counts['notfound'] ),
				65
			);
		}
		if ( $counts['avg_score'] > 0 && $counts['avg_score'] < 60 ) {
			$findings[] = self::finding(
				'low-score',
				'high',
				sprintf( /* translators: %s: score */ __( 'میانگین امتیاز محتوای سایت %s از ۱۰۰ است.', 'hoosh-seo' ), round( $counts['avg_score'] ) ),
				'optimize_post',
				array( 'avg' => $counts['avg_score'] ),
				80
			);
		}

		// --- Technical -----------------------------------------------------
		$psi = self::speed();
		if ( ! empty( $psi['score'] ) ) {
			self::$sources[] = 'pagespeed';
			$stats['speed_score'] = (float) $psi['score'];
			if ( (float) $psi['score'] < 50 ) {
				$findings[] = self::finding(
					'speed',
					'high',
					sprintf( /* translators: %s: score */ __( 'امتیاز سرعت موبایل %s از ۱۰۰ است؛ روی رتبه و نرخ تبدیل اثر مستقیم دارد.', 'hoosh-seo' ), round( (float) $psi['score'] ) ),
					'audit_fix',
					array( 'score' => (float) $psi['score'] ),
					60
				);
			}
		}

		// --- Coverage ------------------------------------------------------
		$opportunities = self::opportunities( 5 );
		if ( $opportunities ) {
			self::$sources[] = 'keywords';
			$findings[] = self::finding(
				'content-gap',
				'medium',
				sprintf( /* translators: %s: list */ __( 'فرصت‌های محتوایی بی‌پوشش: %s', 'hoosh-seo' ), implode( '، ', wp_list_pluck( array_slice( $opportunities, 0, 4 ), 'keyword' ) ) ),
				'write_article',
				array( 'gaps' => array_slice( $opportunities, 0, 5 ) ),
				85
			);
		}

		usort( $findings, array( __CLASS__, 'by_impact' ) );

		return array(
			'score'    => self::health_score( $findings, $counts ),
			'findings' => $findings,
			'sources'  => array_values( array_unique( self::$sources ) ),
			'stats'    => $stats,
			'gaps'     => $opportunities,
		);
	}

	/**
	 * Keywords worth writing for that the site does not cover yet.
	 *
	 * Ranking rule, in plain words: real demand, low competition, and nothing
	 * on the site already answers it.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function opportunities( $limit = 8 ) {
		$candidates = array();
		$used       = array();

		// 1. Local keyword research table.
		if ( class_exists( 'HooshSEO\Modules\KeywordResearch' ) ) {
			$rows = KeywordResearch::all( array( 'per' => 120, 'orderby' => 'volume', 'order' => 'desc' ) );
			$rows = isset( $rows['rows'] ) ? (array) $rows['rows'] : (array) $rows;
			foreach ( $rows as $row ) {
				$candidates[] = array(
					'keyword'    => isset( $row['keyword'] ) ? (string) $row['keyword'] : '',
					'volume'     => isset( $row['volume'] ) ? (int) $row['volume'] : 0,
					'difficulty' => isset( $row['difficulty'] ) ? (float) $row['difficulty'] : 50,
					'intent'     => isset( $row['intent'] ) ? (string) $row['intent'] : '',
					'source'     => 'research',
				);
			}
		}

		// 2. Search Console queries that get impressions but no clicks.
		$analytics = self::analytics( 28 );
		if ( ! empty( $analytics['available'] ) ) {
			foreach ( (array) ( isset( $analytics['queries'] ) ? $analytics['queries'] : array() ) as $q ) {
				$candidates[] = array(
					'keyword'     => isset( $q['query'] ) ? (string) $q['query'] : '',
					'volume'      => isset( $q['impressions'] ) ? (int) $q['impressions'] : 0,
					'difficulty'  => 30,
					'intent'      => '',
					'source'      => 'search-console',
					'position'    => isset( $q['position'] ) ? (float) $q['position'] : 0,
				);
			}
		}

		// 3. Rank tracker rows that are tracked but have no post behind them.
		if ( class_exists( 'HooshSEO\Modules\RankTracker' ) && method_exists( 'HooshSEO\Modules\RankTracker', 'keywords' ) ) {
			$tracked = RankTracker::keywords( array( 'limit' => 100 ) );
			$tracked = isset( $tracked['rows'] ) ? (array) $tracked['rows'] : (array) $tracked;
			foreach ( $tracked as $row ) {
				if ( ! empty( $row['post_id'] ) ) {
					continue;
				}
				$candidates[] = array(
					'keyword'    => isset( $row['keyword'] ) ? (string) $row['keyword'] : '',
					'volume'     => isset( $row['volume'] ) ? (int) $row['volume'] : 0,
					'difficulty' => isset( $row['difficulty'] ) ? (float) $row['difficulty'] : 40,
					'intent'     => '',
					'source'     => 'rank-tracker',
				);
			}
		}

		// Score, dedupe by normalised form, drop what the site already covers.
		$scored = array();
		foreach ( $candidates as $c ) {
			$keyword = trim( (string) $c['keyword'] );
			if ( mb_strlen( $keyword ) < 4 ) {
				continue;
			}
			$norm = Helpers::normalize_fa( $keyword );
			if ( isset( $used[ $norm ] ) ) {
				continue;
			}
			$used[ $norm ] = true;

			if ( self::already_covered( $keyword ) ) {
				continue;
			}

			$volume     = max( 0, (int) $c['volume'] );
			$difficulty = Helpers::clamp( (float) $c['difficulty'], 0, 100 );
			// Demand matters, but competition matters more for a small site.
			$score      = ( min( 100, log( 1 + $volume ) * 18 ) ) + ( ( 100 - $difficulty ) * 0.6 );
			if ( ! empty( $c['position'] ) && $c['position'] > 0 && $c['position'] < 30 ) {
				$score += 15; // Already visible — easier to push up.
			}

			$c['norm']     = $norm;
			$c['score']    = round( $score, 1 );
			$c['intent']   = $c['intent'] ? $c['intent'] : self::guess_intent( $keyword );
			$c['words']    = self::target_words( $c['intent'] );
			$scored[]      = $c;
		}

		usort( $scored, array( __CLASS__, 'by_score' ) );

		return array_slice( $scored, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Does the site already have content for this keyword?
	 *
	 * @param string $keyword Keyword.
	 * @return bool
	 */
	protected static function already_covered( $keyword ) {
		$norm = Helpers::normalize_fa( (string) $keyword );
		if ( '' === $norm ) {
			return true;
		}

		// A post whose focus keyword matches.
		$posts = get_posts(
			array(
				'post_type'      => Helpers::managed_post_types(),
				'post_status'    => 'any',
				'posts_per_page' => 400,
				'fields'         => 'ids',
				's'              => $keyword,
			)
		);
		foreach ( (array) $posts as $pid ) {
			$kws = array_map( array( 'HooshSEO\Helpers', 'normalize_fa' ), (array) \HooshSEO\Meta::focus_keywords( $pid ) );
			if ( in_array( $norm, $kws, true ) ) {
				return true;
			}
		}

		// Or the agent has already written it.
		$facts = Memory::recall( 'site', 'wrote:' . $norm, 1 );
		if ( $facts ) {
			return true;
		}

		return false;
	}

	/**
	 * Cheap intent guess when the source does not supply one.
	 *
	 * @param string $keyword Keyword.
	 * @return string
	 */
	protected static function guess_intent( $keyword ) {
		$k = Helpers::normalize_fa( mb_strtolower( (string) $keyword ) );
		$buy = array( 'خرید', 'قیمت', 'سفارش', 'فروش', 'ارزان', 'buy', 'price', 'order' );
		$how = array( 'چطور', 'چگونه', 'آموزش', 'راهنما', 'how', 'guide', 'tutorial', 'چه طور' );
		$cmp = array( 'بهترین', 'مقایسه', 'vs', 'versus', 'بررسی', 'best', 'review' );
		foreach ( $buy as $w ) {
			if ( false !== mb_strpos( $k, $w ) ) {
				return 'transactional';
			}
		}
		foreach ( $cmp as $w ) {
			if ( false !== mb_strpos( $k, $w ) ) {
				return 'commercial';
			}
		}
		foreach ( $how as $w ) {
			if ( false !== mb_strpos( $k, $w ) ) {
				return 'informational';
			}
		}
		return 'informational';
	}

	/**
	 * Sensible length per intent — transactional pages do not need 2000 words.
	 *
	 * @param string $intent Intent.
	 * @return int
	 */
	protected static function target_words( $intent ) {
		$map = array(
			'transactional' => 900,
			'commercial'    => 1400,
			'informational' => 1600,
			'navigational'  => 700,
		);
		return isset( $map[ $intent ] ) ? $map[ $intent ] : 1200;
	}

	/**
	 * Site-wide counters used by the diagnosis.
	 *
	 * @return array
	 */
	protected static function counts() {
		global $wpdb;
		$out = array(
			'posts'     => 0,
			'indexed'   => 0,
			'noindex'   => 0,
			'self_canonical' => 0,
			'no_meta'   => 0,
			'thin'      => 0,
			'orphans'   => 0,
			'notfound'  => 0,
			'avg_score' => 0,
		);

		$ids = get_posts(
			array(
				'post_type'      => Helpers::managed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out['posts'] = count( (array) $ids );

		$noindex = 0;
		$selfcan = 0;
		$no_meta = 0;
		$thin    = 0;
		$total   = 0;
		$scored  = 0;

		foreach ( (array) $ids as $pid ) {
			$robots = (array) get_post_meta( $pid, '_hs_robots', true );
			if ( ! empty( $robots['noindex'] ) ) {
				$noindex++;
			}
			$canonical = untrailingslashit( (string) get_post_meta( $pid, '_hs_canonical', true ) );
			if ( $canonical && $canonical === untrailingslashit( (string) get_permalink( $pid ) ) ) {
				$selfcan++;
			}
			$title = (string) get_post_meta( $pid, '_hs_title', true );
			$desc  = (string) get_post_meta( $pid, '_hs_description', true );
			if ( '' === trim( $title ) || '' === trim( $desc ) ) {
				$no_meta++;
			}
			$post = get_post( $pid );
			if ( $post && Helpers::word_count( $post->post_content ) < 300 ) {
				$thin++;
			}
			$score = (float) get_post_meta( $pid, '_hs_score', true );
			if ( $score > 0 ) {
				$total += $score;
				$scored++;
			}
		}

		$out['noindex']   = $noindex;
		$out['self_canonical'] = $selfcan;
		$out['indexed']   = $out['posts'] - $noindex;
		$out['no_meta']   = $no_meta;
		$out['thin']      = $thin;
		$out['avg_score'] = $scored ? $total / $scored : 0;

		if ( \HooshSEO\Database::exists( 'pages' ) ) {
			$out['orphans'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \HooshSEO\Database::table( 'pages' ) . ' WHERE inbound = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( \HooshSEO\Database::exists( 'notfound' ) ) {
			$out['notfound'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . \HooshSEO\Database::table( 'notfound' ) . " WHERE state = 'open'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		return $out;
	}

	/**
	 * Search Console data, cached for the length of a run.
	 *
	 * @param int $days Window.
	 * @return array
	 */
	protected static function analytics( $days = 28 ) {
		static $cache = array();
		if ( isset( $cache[ $days ] ) ) {
			return $cache[ $days ];
		}
		$out = array( 'available' => false );
		if ( class_exists( 'HooshSEO\Modules\Analytics' ) && method_exists( 'HooshSEO\Modules\Analytics', 'summary' ) ) {
			$summary = Analytics::summary( max( 7, (int) $days ) );
			if ( is_array( $summary ) && ! empty( $summary['available'] ) ) {
				$out = $summary;
			}
		}
		$cache[ $days ] = $out;
		return $out;
	}

	/**
	 * PageSpeed score, cached.
	 *
	 * @return array
	 */
	protected static function speed() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array( 'score' => 0 );
		// PSI lives on the Analytics module and is already transient-cached, so
		// an unconfigured PageSpeed key costs nothing here.
		if ( class_exists( 'HooshSEO\Modules\Analytics' ) && method_exists( 'HooshSEO\Modules\Analytics', 'psi' ) ) {
			$psi = Analytics::psi( home_url( '/' ), 'mobile' );
			if ( is_array( $psi ) && ! empty( $psi['ok'] ) && isset( $psi['perf'] ) ) {
				$cache['score'] = (float) $psi['perf'];
			}
		}
		return $cache;
	}

	/**
	 * Roll findings into one 0-100 health number.
	 *
	 * @param array $findings Findings.
	 * @param array $counts   Counters.
	 * @return float
	 */
	protected static function health_score( $findings, $counts ) {
		$penalty = 0;
		foreach ( (array) $findings as $f ) {
			$weight = array( 'critical' => 12, 'high' => 7, 'medium' => 3, 'low' => 1 );
			$sev    = isset( $f['severity'] ) ? $f['severity'] : 'low';
			$penalty += isset( $weight[ $sev ] ) ? $weight[ $sev ] : 1;
		}
		$score = 100 - $penalty;
		if ( ! empty( $counts['avg_score'] ) ) {
			// Blend with the real content score so the number is not invented.
			$score = ( $score * 0.4 ) + ( (float) $counts['avg_score'] * 0.6 );
		}
		return round( Helpers::clamp( $score, 0, 100 ), 1 );
	}

	/**
	 * One finding row.
	 *
	 * @param string $code     Machine code.
	 * @param string $severity critical|high|medium|low.
	 * @param string $message  Human text.
	 * @param string $skill    Skill that would fix it.
	 * @param array  $data     Evidence.
	 * @param float  $impact   0-100.
	 * @return array
	 */
	protected static function finding( $code, $severity, $message, $skill, $data = array(), $impact = 50 ) {
		return array(
			'code'     => (string) $code,
			'severity' => (string) $severity,
			'message'  => (string) $message,
			'skill'    => (string) $skill,
			'data'     => (array) $data,
			'impact'   => round( (float) $impact, 1 ),
		);
	}

	/**
	 * Sort findings by impact, descending.
	 *
	 * @param array $a A.
	 * @param array $b B.
	 * @return int
	 */
	protected static function by_impact( $a, $b ) {
		return (int) ( ( isset( $b['impact'] ) ? $b['impact'] : 0 ) - ( isset( $a['impact'] ) ? $a['impact'] : 0 ) );
	}

	/**
	 * Sort opportunities by score, descending.
	 *
	 * @param array $a A.
	 * @param array $b B.
	 * @return int
	 */
	protected static function by_score( $a, $b ) {
		return (int) ( ( isset( $b['score'] ) ? $b['score'] : 0 ) - ( isset( $a['score'] ) ? $a['score'] : 0 ) );
	}
}
