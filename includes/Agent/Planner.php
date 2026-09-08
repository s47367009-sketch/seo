<?php
/**
 * The planner — decides what the agent should do next.
 *
 * Input is the site diagnosis, output is an ordered list of concrete steps.
 * Two rules shape it:
 *
 *  1. Fix what is broken before creating anything new. A site with 40 noindexed
 *     pages does not need another article.
 *  2. If there is nothing broken, the answer is not "do nothing" — it is
 *     produce content, which is the only way a small site grows.
 *
 * Every step names a real target (a post id, a 404 row), so a step is either
 * executed or it fails loudly; there is no "sort of did it".
 *
 * @package HooshSEO
 */

namespace HooshSEO\Agent;

use HooshSEO\Helpers;
use HooshSEO\Modules\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Class Planner
 */
final class Planner {

	/**
	 * Build an ordered plan.
	 *
	 * @param array $diagnosis Insight::diagnose() output.
	 * @param array $ctx       Run context.
	 * @return array {steps[], rationale[], phase}
	 */
	public static function plan( $diagnosis, $ctx = array() ) {
		$max      = isset( $ctx['max_steps'] ) ? (int) $ctx['max_steps'] : 25;
		$allowed  = isset( $ctx['allowed_skills'] ) ? (array) $ctx['allowed_skills'] : array();
		$findings = isset( $diagnosis['findings'] ) ? (array) $diagnosis['findings'] : array();

		$steps     = array();
		$rationale = array();

		// --- Pass 1: blocking technical problems --------------------------
		foreach ( $findings as $finding ) {
			if ( ! in_array( $finding['severity'], array( 'critical', 'high' ), true ) ) {
				continue;
			}
			$expanded = self::expand( $finding, $allowed, $ctx );
			if ( $expanded ) {
				$steps     = array_merge( $steps, $expanded );
				$rationale[] = sprintf(
					/* translators: 1: severity, 2: message */
					__( 'اولویت %1$s: %2$s', 'hoosh-seo' ),
					self::severity_label( $finding['severity'] ),
					$finding['message']
				);
			}
		}

		// --- Pass 2: medium issues ----------------------------------------
		if ( count( $steps ) < $max ) {
			foreach ( $findings as $finding ) {
				if ( 'medium' !== $finding['severity'] ) {
					continue;
				}
				$expanded = self::expand( $finding, $allowed, $ctx );
				if ( $expanded ) {
					$steps     = array_merge( $steps, $expanded );
					$rationale[] = $finding['message'];
				}
			}
		}

		// --- Pass 3: if the site is healthy, produce ----------------------
		// This is the deliberate "nothing to fix" branch. A clean site should
		// still get new content every run, otherwise the agent looks idle.
		$phase = 'fix';
		if ( count( $steps ) < 3 ) {
			$phase    = 'grow';
			$articles = self::content_steps( $diagnosis, $allowed, $ctx, max( 1, $max - count( $steps ) ) );
			if ( $articles ) {
				$steps       = array_merge( $steps, $articles );
				$rationale[] = __( 'ایراد فنی مهمی پیدا نشد؛ بودجهٔ این اجرا صرف تولید محتوا شد.', 'hoosh-seo' );
			}
		}

		$steps = self::prioritise( $steps );
		$steps = array_slice( $steps, 0, max( 1, $max ) );

		// Renumber so the ledger reads 1..N.
		foreach ( $steps as $i => $step ) {
			$steps[ $i ]['seq'] = $i + 1;
		}

		return array(
			'steps'     => $steps,
			'rationale' => array_values( array_unique( $rationale ) ),
			'phase'     => $phase,
			'count'     => count( $steps ),
		);
	}

	/**
	 * Turn one finding into concrete, targeted steps.
	 *
	 * @param array $finding Finding.
	 * @param array $allowed Enabled skills.
	 * @param array $ctx     Run context.
	 * @return array
	 */
	protected static function expand( $finding, $allowed, $ctx ) {
		$skill = isset( $finding['skill'] ) ? (string) $finding['skill'] : '';
		$code  = isset( $finding['code'] ) ? (string) $finding['code'] : '';

		if ( ! $skill || empty( $allowed[ $skill ] ) || ! Skills::runnable( $skill ) ) {
			return array();
		}

		$out  = array();
		$data = isset( $finding['data'] ) ? (array) $finding['data'] : array();

		switch ( $code ) {
			case 'missing-meta':
				foreach ( self::posts_needing_meta( 6 ) as $post_id ) {
					$out[] = self::step( $skill, array( 'post_id' => $post_id ), $finding, 90 );
				}
				break;

			case 'thin-content':
			case 'low-score':
				foreach ( self::weakest_posts( 5 ) as $post_id ) {
					if ( Guard::in_cooldown( $post_id ) ) {
						continue;
					}
					$out[] = self::step( 'optimize_post', array( 'post_id' => $post_id ), $finding, 78 );
				}
				break;

			case 'orphans':
				foreach ( self::orphan_posts( 5 ) as $post_id ) {
					$out[] = self::step( 'internal_link', array( 'post_id' => $post_id ), $finding, 70 );
				}
				break;

			case 'noindex':
				foreach ( self::noindexed_posts( 6 ) as $post_id ) {
					$out[] = self::step( 'audit_fix', array( 'post_id' => $post_id ), $finding, 95 );
				}
				break;

			case 'redundant-canonical':
				foreach ( self::self_canonical_posts( 5 ) as $post_id ) {
					$out[] = self::step( 'audit_fix', array( 'post_id' => $post_id ), $finding, 62 );
				}
				break;

			case 'notfound':
				$budget = max( 1, (int) ( isset( $ctx['budget_404'] ) ? $ctx['budget_404'] : 5 ) );
				for ( $i = 0; $i < $budget; $i++ ) {
					$out[] = self::step( 'redirect_404', array( 'pick' => 'top' ), $finding, 65 );
				}
				break;

			case 'ctr':
				$post_id = isset( $data['url'] ) ? self::post_from_url( (string) $data['url'] ) : 0;
				if ( $post_id && ! Guard::in_cooldown( $post_id ) ) {
					$out[] = self::step( 'meta_fill', array( 'post_id' => $post_id, 'force' => true ), $finding, 88 );
				}
				break;

			case 'striking-distance':
				$post_id = isset( $data['query'] ) ? self::post_for_query( (string) $data['query'] ) : 0;
				if ( $post_id ) {
					$out[] = self::step( 'internal_link', array( 'post_id' => $post_id, 'query' => $data['query'] ), $finding, 82 );
				}
				break;

			case 'content-gap':
				$out = array_merge( $out, self::content_steps( array( 'gaps' => isset( $data['gaps'] ) ? $data['gaps'] : array() ), $allowed, $ctx, 2 ) );
				break;

			case 'speed':
				// Speed fixes are configuration, not per-post edits: record it
				// as a finding for the human rather than silently changing
				// site-wide behaviour.
				$out[] = self::step( 'audit_fix', array( 'post_id' => 0, 'note' => 'speed' ), $finding, 40 );
				break;

			default:
				$out[] = self::step( $skill, array(), $finding, 50 );
		}

		return $out;
	}

	/**
	 * Content production steps from remembered or fresh opportunities.
	 *
	 * @param array $diagnosis Diagnosis.
	 * @param array $allowed   Enabled skills.
	 * @param array $ctx       Run context.
	 * @param int   $budget    How many articles at most.
	 * @return array
	 */
	protected static function content_steps( $diagnosis, $allowed, $ctx, $budget = 1 ) {
		$out = array();

		if ( empty( $allowed['content_gap'] ) && empty( $allowed['write_article'] ) ) {
			return $out;
		}

		$gaps = isset( $diagnosis['gaps'] ) ? (array) $diagnosis['gaps'] : array();
		if ( ! $gaps && Skills::runnable( 'content_gap' ) && ! empty( $allowed['content_gap'] ) ) {
			// Nothing remembered yet: go look.
			$out[] = self::step(
				'content_gap',
				array( 'limit' => 6 ),
				array( 'message' => __( 'جست‌وجوی فرصت‌های محتوایی', 'hoosh-seo' ), 'severity' => 'medium' ),
				85
			);
		}

		if ( empty( $allowed['write_article'] ) ) {
			return $out;
		}

		$per_run = max( 0, (int) \hoosh_seo()->settings->get( 'agent.articles_per_run', 1 ) );
		$budget  = min( (int) $budget, $per_run );

		for ( $i = 0; $i < $budget; $i++ ) {
			$gap = isset( $gaps[ $i ] ) ? (array) $gaps[ $i ] : array();
			$out[] = self::step(
				'write_article',
				$gap ? array( 'gap' => $gap ) : array( 'pick' => 'best' ),
				array(
					'message'  => $gap
						? sprintf( /* translators: %s: keyword */ __( 'نگارش مقاله برای «%s»', 'hoosh-seo' ), mb_substr( (string) $gap['keyword'], 0, 40 ) )
						: __( 'نگارش مقاله برای بهترین فرصت پیدا‌شده', 'hoosh-seo' ),
					'severity' => 'medium',
				),
				84
			);
		}

		return $out;
	}

	/**
	 * One plan step.
	 *
	 * @param string $skill    Skill id.
	 * @param array  $target   Target.
	 * @param array  $finding  Source finding.
	 * @param float  $priority Higher runs first.
	 * @return array
	 */
	protected static function step( $skill, $target, $finding, $priority = 50 ) {
		return array(
			'skill'    => sanitize_key( $skill ),
			'target'   => (array) $target,
			'why'      => isset( $finding['message'] ) ? (string) $finding['message'] : '',
			'code'     => isset( $finding['code'] ) ? (string) $finding['code'] : '',
			'severity' => isset( $finding['severity'] ) ? (string) $finding['severity'] : 'low',
			'priority' => (float) $priority,
		);
	}

	/**
	 * Highest priority first; ties broken by severity.
	 *
	 * @param array $steps Steps.
	 * @return array
	 */
	protected static function prioritise( $steps ) {
		$rank = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
		usort(
			$steps,
			function ( $a, $b ) use ( $rank ) {
				$pa = (float) $a['priority'] + ( isset( $rank[ $a['severity'] ] ) ? $rank[ $a['severity'] ] : 0 );
				$pb = (float) $b['priority'] + ( isset( $rank[ $b['severity'] ] ) ? $rank[ $b['severity'] ] : 0 );
				if ( $pa === $pb ) {
					return 0;
				}
				return $pb > $pa ? 1 : -1;
			}
		);
		return $steps;
	}

	/**
	 * Posts with no title or description.
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	protected static function posts_needing_meta( $limit = 6 ) {
		$ids = get_posts(
			array(
				'post_type'      => Helpers::managed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, (int) $limit ) * 3,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( (array) $ids as $pid ) {
			$title = (string) get_post_meta( $pid, '_hs_title', true );
			$desc  = (string) get_post_meta( $pid, '_hs_description', true );
			if ( '' === trim( $title ) || '' === trim( $desc ) ) {
				$out[] = (int) $pid;
			}
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Lowest-scoring analysed posts.
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	protected static function weakest_posts( $limit = 5 ) {
		$pages = Content::pages( array( 'filter' => 'low', 'orderby' => 'score', 'order' => 'ASC', 'per' => max( 1, (int) $limit ) ) );
		$rows  = isset( $pages['rows'] ) ? (array) $pages['rows'] : array();
		$out   = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['post_id'] ) ) {
				$out[] = (int) $row['post_id'];
			}
		}
		return array_slice( $out, 0, (int) $limit );
	}

	/**
	 * Orphan posts — no inbound internal links.
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	protected static function orphan_posts( $limit = 5 ) {
		$pages = Content::pages( array( 'filter' => 'orphan', 'per' => max( 1, (int) $limit ) ) );
		$rows  = isset( $pages['rows'] ) ? (array) $pages['rows'] : array();
		$out   = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['post_id'] ) ) {
				$out[] = (int) $row['post_id'];
			}
		}
		return array_slice( $out, 0, (int) $limit );
	}

	/**
	 * Published posts that are marked noindex.
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	protected static function noindexed_posts( $limit = 6 ) {
		$ids = get_posts(
			array(
				'post_type'      => Helpers::managed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( (array) $ids as $pid ) {
			$robots = (array) get_post_meta( $pid, '_hs_robots', true );
			if ( ! empty( $robots['noindex'] ) ) {
				$out[] = (int) $pid;
			}
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Published posts carrying a manual canonical that equals their own URL.
	 *
	 * @param int $limit Max.
	 * @return int[]
	 */
	protected static function self_canonical_posts( $limit = 5 ) {
		$ids = get_posts(
			array(
				'post_type'      => Helpers::managed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( (array) $ids as $pid ) {
			$canonical = untrailingslashit( (string) get_post_meta( $pid, '_hs_canonical', true ) );
			if ( $canonical && $canonical === untrailingslashit( (string) get_permalink( $pid ) ) ) {
				$out[] = (int) $pid;
			}
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Post id behind a URL from Search Console.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	protected static function post_from_url( $url ) {
		$id = url_to_postid( (string) $url );
		return $id ? (int) $id : 0;
	}

	/**
	 * Best post matching a search query.
	 *
	 * @param string $query Query.
	 * @return int
	 */
	protected static function post_for_query( $query ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return 0;
		}
		$found = get_posts(
			array(
				'post_type'      => Helpers::managed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				's'              => $query,
				'no_found_rows'  => true,
			)
		);
		return $found ? (int) reset( $found ) : 0;
	}

	/**
	 * Persian label for a severity.
	 *
	 * @param string $severity Severity.
	 * @return string
	 */
	protected static function severity_label( $severity ) {
		$map = array(
			'critical' => __( 'بحرانی', 'hoosh-seo' ),
			'high'     => __( 'مهم', 'hoosh-seo' ),
			'medium'   => __( 'متوسط', 'hoosh-seo' ),
			'low'      => __( 'کم', 'hoosh-seo' ),
		);
		return isset( $map[ $severity ] ) ? $map[ $severity ] : $severity;
	}
}
