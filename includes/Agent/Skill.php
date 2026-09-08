<?php
/**
 * Skill implementations — the agent's hands.
 *
 * Every method here reuses an existing module rather than re-implementing it,
 * so an agent action is always exactly the same code path a human click would
 * take. That matters for two reasons: the changelog records it either way, and
 * a bug fixed in the module is fixed for the agent too.
 *
 * Each handler returns:
 *   ok, title, reason[], result, cost_usd, post_id, needs_review, message
 *
 * @package HooshSEO
 */

namespace HooshSEO\Agent;

use HooshSEO\AI\Gateway;
use HooshSEO\Helpers;
use HooshSEO\Meta;
use HooshSEO\Modules\Content;
use HooshSEO\Modules\Images;
use HooshSEO\Modules\IndexManager;
use HooshSEO\Modules\Links;
use HooshSEO\Modules\NotFound;
use HooshSEO\Modules\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Class Skill
 */
final class Skill {

	/**
	 * Fix the technical SEO of the weakest indexed pages.
	 *
	 * @param array $target {post_id?}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function audit_fix( $target = array(), $ctx = array() ) {
		$post_id = isset( $target['post_id'] ) ? (int) $target['post_id'] : 0;

		if ( ! $post_id ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'صفحه‌ای برای اصلاح مشخص نشد.', 'hoosh-seo' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'نوشته پیدا نشد.', 'hoosh-seo' ) );
		}

		$fixed  = array();
		$before = array();

		// 1. A published post that is noindexed cannot rank. Un-noindex it.
		$meta    = Meta::for_post( $post_id );
		$robots  = isset( $meta['robots'] ) ? (array) $meta['robots'] : array();
		$noindex = ! empty( $robots['noindex'] );
		if ( $noindex && 'publish' === $post->post_status ) {
			$before['robots'] = $robots;
			$robots['noindex'] = false;
			Meta::save( $post_id, array( 'robots' => $robots ) );
			$fixed[] = 'noindex-removed';
		}

		// 2. Duplicate or missing canonical.
		$canonical = isset( $meta['canonical'] ) ? (string) $meta['canonical'] : '';
		if ( $canonical && untrailingslashit( $canonical ) === untrailingslashit( get_permalink( $post_id ) ) ) {
			// A self-referencing canonical stored per-post is redundant and a
			// common source of "duplicate canonical" audit noise.
			$before['canonical'] = $canonical;
			Meta::save( $post_id, array( 'canonical' => '' ) );
			$fixed[] = 'canonical-normalised';
		}

		// 3. A slug that does not match the focus keyword hurts relevance.
		$kw = Meta::focus_keywords( $post_id );
		if ( $kw && ! empty( $kw[0] ) ) {
			$slug = (string) $post->post_name;
			$want = sanitize_title( Helpers::normalize_fa( (string) $kw[0] ) );
			if ( $want && mb_strlen( $want ) > 3 && false === mb_strpos( $slug, $want ) && mb_strlen( $slug ) > 60 ) {
				$before['slug'] = $slug;
				$fixed[]        = 'slug-flagged';
			}
		}

		if ( ! $fixed ) {
			return array(
				'ok'      => true,
				'skipped' => true,
				'title'   => sprintf( /* translators: %s: title */ __( 'بررسی فنی «%s» — ایرادی نبود', 'hoosh-seo' ), mb_substr( $post->post_title, 0, 40 ) ),
				'post_id' => $post_id,
				'result'  => array( 'fixed' => array() ),
			);
		}

		return array(
			'ok'      => true,
			'title'   => sprintf( /* translators: %s: title */ __( 'اصلاح فنی «%s»', 'hoosh-seo' ), mb_substr( $post->post_title, 0, 40 ) ),
			'post_id' => $post_id,
			'reason'  => array( 'post_id' => $post_id, 'fixes' => $fixed ),
			'result'  => array( 'fixed' => $fixed, 'before' => $before ),
		);
	}

	/**
	 * Write title + meta description for a page that has neither.
	 *
	 * @param array $target {post_id}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function meta_fill( $target = array(), $ctx = array() ) {
		$post_id = isset( $target['post_id'] ) ? (int) $target['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'نوشته پیدا نشد.', 'hoosh-seo' ) );
		}
		if ( Guard::in_cooldown( $post_id ) ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'این صفحه در دورهٔ استراحت است.', 'hoosh-seo' ), 'post_id' => $post_id );
		}

		$current = Meta::for_post( $post_id );
		if ( trim( (string) $current['title'] ) && trim( (string) $current['description'] ) ) {
			return array( 'ok' => true, 'skipped' => true, 'message' => __( 'متا کامل بود.', 'hoosh-seo' ), 'post_id' => $post_id );
		}

		$excerpt = mb_substr( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 0, 1400 );
		$kw      = Meta::focus_keywords( $post_id );

		$ai = Gateway::complete(
			array(
				'task'    => 'meta',
				'post_id' => $post_id,
				'json'    => array(
					'title'       => 'string — عنوان سئو، حداکثر ۵۸ کاراکتر',
					'description' => 'string — توضیحات متا، بین ۱۲۰ تا ۱۵۵ کاراکتر',
					'keywords'    => 'array of string — ۳ تا ۵ کلمهٔ کلیدی',
				),
				'prompt'  => sprintf(
					"برای این صفحه عنوان سئو و توضیحات متا بنویس.\nعنوان فعلی: %s\nکلمهٔ کلیدی هدف: %s\nخلاصهٔ محتوا:\n%s",
					$post->post_title,
					$kw ? implode( '، ', array_slice( (array) $kw, 0, 3 ) ) : '—',
					$excerpt
				),
			)
		);

		if ( empty( $ai['ok'] ) || empty( $ai['json'] ) ) {
			return array(
				'ok'      => false,
				'post_id' => $post_id,
				'message' => isset( $ai['error'] ) ? $ai['error'] : __( 'هوش مصنوعی پاسخی نداد.', 'hoosh-seo' ),
			);
		}

		$data = (array) $ai['json'];
		$save = array();

		$title = isset( $data['title'] ) ? Meta::clamp_text( (string) $data['title'], 58 ) : '';
		if ( $title ) {
			$save['title'] = $title;
		}
		$desc = isset( $data['description'] ) ? Meta::clamp_text( (string) $data['description'], 158 ) : '';
		if ( $desc ) {
			$save['description'] = $desc;
		}
		if ( empty( $current['keywords'] ) && ! empty( $data['keywords'] ) && is_array( $data['keywords'] ) ) {
			$save['keywords'] = array_slice( array_map( 'sanitize_text_field', $data['keywords'] ), 0, 5 );
		}
		if ( ! $save ) {
			return array( 'ok' => false, 'post_id' => $post_id, 'message' => __( 'خروجی هوش مصنوعی قابل استفاده نبود.', 'hoosh-seo' ) );
		}

		Meta::save( $post_id, $save );
		Content::queue_analysis( $post_id );

		return array(
			'ok'       => true,
			'title'    => sprintf( /* translators: %s: title */ __( 'متای «%s» نوشته شد', 'hoosh-seo' ), mb_substr( $post->post_title, 0, 40 ) ),
			'post_id'  => $post_id,
			'reason'   => array( 'post_id' => $post_id, 'had_title' => (bool) $current['title'], 'had_desc' => (bool) $current['description'] ),
			'result'   => array( 'saved' => array_keys( $save ), 'title' => $title, 'description' => $desc ),
			'cost_usd' => isset( $ai['cost'] ) ? (float) $ai['cost'] : 0.0,
		);
	}

	/**
	 * Give an orphan page inbound links from related posts.
	 *
	 * @param array $target {post_id}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function internal_link( $target = array(), $ctx = array() ) {
		$post_id = isset( $target['post_id'] ) ? (int) $target['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'نوشته پیدا نشد.', 'hoosh-seo' ) );
		}

		$suggestions = Links::suggest( $post_id, 6 );
		$keywords    = isset( $suggestions['keywords'] ) ? (array) $suggestions['keywords'] : array();

		$picked = array();
		foreach ( $keywords as $kw ) {
			if ( ! empty( $kw['existing'] ) || empty( $kw['target'] ) ) {
				continue;
			}
			$picked[] = array(
				'keyword' => isset( $kw['norm'] ) ? (string) $kw['norm'] : '',
				'target'  => (int) $kw['target'],
			);
			if ( count( $picked ) >= 3 ) {
				break;
			}
		}

		if ( ! $picked ) {
			return array(
				'ok'      => true,
				'skipped' => true,
				'title'   => sprintf( /* translators: %s: title */ __( 'لینک تازه‌ای برای «%s» پیدا نشد', 'hoosh-seo' ), mb_substr( $post->post_title, 0, 40 ) ),
				'post_id' => $post_id,
			);
		}

		$out = Links::insert_into_content( $post_id, $picked );

		return array(
			'ok'      => ! empty( $out['ok'] ),
			'title'   => sprintf( /* translators: %d: count */ __( '%d لینک داخلی افزوده شد', 'hoosh-seo' ), count( $picked ) ),
			'post_id' => $post_id,
			'reason'  => array( 'post_id' => $post_id, 'links' => $picked ),
			'result'  => $out,
			'message' => isset( $out['message'] ) ? $out['message'] : '',
		);
	}

	/**
	 * Fill missing alt text on images.
	 *
	 * @param array $target {limit?}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function alt_fill( $target = array(), $ctx = array() ) {
		$limit = isset( $target['limit'] ) ? (int) $target['limit'] : 8;
		$limit = max( 1, min( 40, $limit ) );

		$out = Images::backfill_alt( array( 'limit' => $limit, 'use_ai' => true ) );

		$done = 0;
		if ( is_array( $out ) ) {
			$done = isset( $out['done'] ) ? (int) $out['done'] : ( isset( $out['count'] ) ? (int) $out['count'] : 0 );
		}

		return array(
			'ok'      => true,
			'skipped' => 0 === $done,
			'title'   => $done
				? sprintf( /* translators: %d: count */ __( 'alt %d تصویر پر شد', 'hoosh-seo' ), $done )
				: __( 'تصویر بدون alt باقی نمانده بود', 'hoosh-seo' ),
			'reason'  => array( 'limit' => $limit ),
			'result'  => is_array( $out ) ? $out : array( 'raw' => (string) $out ),
		);
	}

	/**
	 * Add schema markup to a post that has none.
	 *
	 * @param array $target {post_id}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function schema_fill( $target = array(), $ctx = array() ) {
		$post_id = isset( $target['post_id'] ) ? (int) $target['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'نوشته پیدا نشد.', 'hoosh-seo' ) );
		}

		$nodes = Schema::for_post( $post_id );
		$types = array();
		foreach ( (array) $nodes as $node ) {
			if ( ! empty( $node['@type'] ) ) {
				$types[] = is_array( $node['@type'] ) ? implode( '/', $node['@type'] ) : (string) $node['@type'];
			}
		}
		$has_article = false;
		foreach ( $types as $t ) {
			if ( false !== stripos( $t, 'article' ) || false !== stripos( $t, 'blogposting' ) ) {
				$has_article = true;
			}
		}

		if ( $has_article ) {
			return array(
				'ok'      => true,
				'skipped' => true,
				'title'   => sprintf( /* translators: %s: title */ __( '«%s» اسکیما دارد', 'hoosh-seo' ), mb_substr( $post->post_title, 0, 40 ) ),
				'post_id' => $post_id,
				'result'  => array( 'types' => array_values( array_unique( $types ) ) ),
			);
		}

		// Turn on the article node for this type instead of hand-writing JSON —
		// the module owns the graph and keeps it valid.
		$settings = \hoosh_seo()->settings;
		$types_on = (array) $settings->get( 'schema.types', array() );
		$node_key = 'post' === $post->post_type ? 'Article' : 'WebPage';

		if ( empty( $types_on[ $node_key ] ) ) {
			$types_on[ $node_key ] = true;
			$settings->set( array( 'schema.types' => $types_on ) );
		}

		return array(
			'ok'      => true,
			'title'   => sprintf( /* translators: %s: type */ __( 'اسکیمای %s فعال شد', 'hoosh-seo' ), $node_key ),
			'post_id' => $post_id,
			'reason'  => array( 'post_id' => $post_id, 'had' => array_values( array_unique( $types ) ) ),
			'result'  => array( 'enabled' => $node_key ),
		);
	}

	/**
	 * 301 the most-hit 404s to the closest live page.
	 *
	 * @param array $target {id?, path?}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function redirect_404( $target = array(), $ctx = array() ) {
		$id   = isset( $target['id'] ) ? (int) $target['id'] : 0;
		$path = isset( $target['path'] ) ? (string) $target['path'] : '';

		if ( ! $id ) {
			$list = NotFound::listing( array( 'per' => 1, 'orderby' => 'hits', 'order' => 'DESC', 'state' => 'open' ) );
			$rows = isset( $list['rows'] ) ? (array) $list['rows'] : array();
			if ( ! $rows ) {
				return array( 'ok' => true, 'skipped' => true, 'title' => __( '۴۰۴ بازی نمانده بود', 'hoosh-seo' ) );
			}
			$row  = reset( $rows );
			$id   = (int) $row['id'];
			$path = (string) $row['url'];
		}

		$guess = NotFound::guess_target( $path );
		if ( empty( $guess['url'] ) && empty( $guess['target'] ) ) {
			return array(
				'ok'      => false,
				'skipped' => true,
				'title'   => sprintf( /* translators: %s: path */ __( 'مقصدی برای «%s» پیدا نشد', 'hoosh-seo' ), mb_substr( $path, 0, 40 ) ),
				'reason'  => array( 'path' => $path ),
			);
		}

		$to   = ! empty( $guess['url'] ) ? (string) $guess['url'] : home_url( (string) $guess['target'] );
		$done = NotFound::resolve( $id, $to, '301' );

		return array(
			'ok'      => ! ( is_array( $done ) && isset( $done['ok'] ) && ! $done['ok'] ),
			'title'   => sprintf( /* translators: 1: path, 2: target */ __( '۴۰۴ «%1$s» → «%2$s»', 'hoosh-seo' ), mb_substr( $path, 0, 30 ), mb_substr( $to, 0, 30 ) ),
			'reason'  => array( 'notfound_id' => $id, 'path' => $path, 'confidence' => isset( $guess['confidence'] ) ? $guess['confidence'] : 0 ),
			'result'  => array( 'to' => $to, 'raw' => $done ),
		);
	}

	/**
	 * Announce fresh URLs to the search engines.
	 *
	 * @param array $target {urls?[], limit?}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function index_submit( $target = array(), $ctx = array() ) {
		$urls = isset( $target['urls'] ) ? (array) $target['urls'] : array();

		if ( ! $urls ) {
			$limit = isset( $target['limit'] ) ? (int) $target['limit'] : 20;
			$urls  = IndexManager::recent_urls( max( 1, min( 200, $limit ) ) );
		}
		$urls = array_values( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) );
		if ( ! $urls ) {
			return array( 'ok' => true, 'skipped' => true, 'title' => __( 'نشانی تازه‌ای برای ثبت نبود', 'hoosh-seo' ) );
		}

		$out      = IndexManager::indexnow( $urls );
		$google   = array();
		$indexing = '\HooshSEO\Google\Indexing';
		if ( class_exists( $indexing ) && method_exists( $indexing, 'submit' ) ) {
			$google = call_user_func( array( $indexing, 'submit' ), array_slice( $urls, 0, 50 ) );
		}

		return array(
			'ok'     => true,
			'title'  => sprintf( /* translators: %d: count */ __( '%d نشانی به موتورهای جستجو اعلام شد', 'hoosh-seo' ), count( $urls ) ),
			'reason' => array( 'count' => count( $urls ) ),
			'result' => array( 'indexnow' => $out, 'google' => $google ),
		);
	}

	/**
	 * Rewrite a low-scoring post.
	 *
	 * @param array $target {post_id}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function optimize_post( $target = array(), $ctx = array() ) {
		$post_id = isset( $target['post_id'] ) ? (int) $target['post_id'] : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'نوشته پیدا نشد.', 'hoosh-seo' ) );
		}
		if ( Guard::in_cooldown( $post_id ) ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'این صفحه در دورهٔ استراحت است.', 'hoosh-seo' ), 'post_id' => $post_id );
		}

		$analysis = Content::analyze( $post_id );
		$score    = isset( $analysis['score'] ) ? (float) $analysis['score'] : 0;
		$issues   = isset( $analysis['issues'] ) ? (array) $analysis['issues'] : array();

		$threshold = (float) \hoosh_seo()->settings->get( 'automation.analysis_threshold', 70 );
		if ( $score >= $threshold ) {
			return array(
				'ok'      => true,
				'skipped' => true,
				'title'   => sprintf( /* translators: 1: title, 2: score */ __( '«%1$s» امتیاز %2$s دارد؛ دست نخورد', 'hoosh-seo' ), mb_substr( $post->post_title, 0, 30 ), round( $score ) ),
				'post_id' => $post_id,
				'result'  => array( 'score' => $score ),
			);
		}

		$problems = array();
		foreach ( $issues as $issue ) {
			if ( ! empty( $issue['label'] ) ) {
				$problems[] = (string) $issue['label'];
			}
		}

		$ai = Gateway::complete(
			array(
				'task'       => 'rewrite',
				'post_id'    => $post_id,
				'max_tokens' => 2400,
				'json'       => array(
					'title'   => 'string — عنوان بهتر',
					'excerpt' => 'string — چکیدهٔ کوتاه',
					'content' => 'string — بازنویسی HTML کامل با ساختار H2/H3',
				),
				'prompt'     => sprintf(
					"این نوشته امتیاز سئوی %s از ۱۰۰ دارد. ایرادهای گزارش‌شده: %s\n\nعنوان: %s\nکلمهٔ کلیدی: %s\nمتن فعلی:\n%s\n\nبدون حذف اطلاعات مفید بازنویسی کن: مقدمهٔ شفاف، زیرتیترهای H2/H3، پاراگراف‌های کوتاه، و یک بخش پرسش‌های متداول در پایان.",
					round( $score ),
					$problems ? implode( '؛ ', array_slice( $problems, 0, 8 ) ) : '—',
					$post->post_title,
					implode( '، ', array_slice( (array) Meta::focus_keywords( $post_id ), 0, 3 ) ),
					mb_substr( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 0, 6000 )
				),
			)
		);

		if ( empty( $ai['ok'] ) || empty( $ai['json']['content'] ) ) {
			return array(
				'ok'      => false,
				'post_id' => $post_id,
				'message' => isset( $ai['error'] ) ? $ai['error'] : __( 'بازنویسی ناموفق بود.', 'hoosh-seo' ),
				'result'  => array( 'score' => $score ),
			);
		}

		$data = (array) $ai['json'];
		$edit = array( 'ID' => $post_id );
		if ( ! empty( $data['content'] ) ) {
			$edit['post_content'] = wp_kses_post( (string) $data['content'] );
		}
		if ( ! empty( $data['title'] ) && mb_strlen( (string) $data['title'] ) > 8 ) {
			$edit['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( ! empty( $data['excerpt'] ) ) {
			$edit['post_excerpt'] = sanitize_text_field( (string) $data['excerpt'] );
		}

		$updated = wp_update_post( $edit, true );
		if ( is_wp_error( $updated ) ) {
			return array( 'ok' => false, 'post_id' => $post_id, 'message' => $updated->get_error_message() );
		}

		Content::queue_analysis( $post_id );
		$after = Content::analyze( $post_id );

		return array(
			'ok'       => true,
			'title'    => sprintf( /* translators: 1: title, 2: from, 3: to */ __( '«%1$s» بازنویسی شد (%2$s ← %3$s)', 'hoosh-seo' ), mb_substr( $post->post_title, 0, 30 ), round( $score ), isset( $after['score'] ) ? round( (float) $after['score'] ) : '—' ),
			'post_id'  => $post_id,
			'reason'   => array( 'post_id' => $post_id, 'score_before' => $score, 'issues' => array_slice( $problems, 0, 6 ) ),
			'result'   => array(
				'score_before' => $score,
				'score_after'  => isset( $after['score'] ) ? (float) $after['score'] : null,
				'words'        => Helpers::word_count( $edit['post_content'] ),
			),
			'cost_usd' => isset( $ai['cost'] ) ? (float) $ai['cost'] : 0.0,
		);
	}

	/**
	 * Find the next content opportunity worth writing.
	 *
	 * Delegates to Insight, which merges Search Console queries with local
	 * keyword research and drops anything the site already covers.
	 *
	 * @param array $target {limit?}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function content_gap( $target = array(), $ctx = array() ) {
		$limit = isset( $target['limit'] ) ? (int) $target['limit'] : 5;
		$gaps  = Insight::opportunities( max( 1, min( 20, $limit ) ) );

		foreach ( $gaps as $gap ) {
			Memory::remember( 'site', 'gap:' . ( isset( $gap['norm'] ) ? $gap['norm'] : '' ), 'opportunity', $gap );
		}

		return array(
			'ok'      => ! empty( $gaps ),
			'skipped' => empty( $gaps ),
			'title'   => $gaps
				? sprintf( /* translators: %d: count */ __( '%d فرصت محتوایی پیدا شد', 'hoosh-seo' ), count( $gaps ) )
				: __( 'فرصت بی‌پوشش تازه‌ای پیدا نشد', 'hoosh-seo' ),
			'reason'  => array( 'sources' => Insight::last_sources() ),
			'result'  => array( 'gaps' => array_slice( $gaps, 0, 10 ) ),
		);
	}

	/**
	 * Write a full article for an opportunity.
	 *
	 * @param array $target {keyword|gap}.
	 * @param array $ctx    Run context.
	 * @return array
	 */
	public static function write_article( $target = array(), $ctx = array() ) {
		$gap = isset( $target['gap'] ) ? (array) $target['gap'] : array();
		if ( ! $gap && ! empty( $target['keyword'] ) ) {
			$gap = array( 'keyword' => (string) $target['keyword'] );
		}
		if ( ! $gap || empty( $gap['keyword'] ) ) {
			// No target supplied: take the best remembered opportunity.
			$found = Insight::opportunities( 1 );
			$gap   = $found ? (array) reset( $found ) : array();
		}
		if ( empty( $gap['keyword'] ) ) {
			return array( 'ok' => false, 'skipped' => true, 'message' => __( 'موضوعی برای نوشتن پیدا نشد.', 'hoosh-seo' ) );
		}

		$run_id = isset( $ctx['run_id'] ) ? (int) $ctx['run_id'] : 0;
		$out    = Pipeline::article( $gap, $run_id );

		if ( empty( $out['ok'] ) ) {
			return array(
				'ok'      => false,
				'title'   => sprintf( /* translators: %s: keyword */ __( 'نگارش «%s» ناموفق بود', 'hoosh-seo' ), mb_substr( (string) $gap['keyword'], 0, 30 ) ),
				'message' => isset( $out['message'] ) ? $out['message'] : '',
				'result'  => $out,
			);
		}

		Memory::remember( 'site', 'wrote:' . Helpers::normalize_fa( (string) $gap['keyword'] ), 'article', array(
			'keyword' => (string) $gap['keyword'],
			'post_id' => isset( $out['post_id'] ) ? (int) $out['post_id'] : 0,
			'at'      => time(),
		) );

		return array(
			'ok'           => true,
			'title'        => sprintf( /* translators: %s: keyword */ __( 'مقالهٔ «%s» نوشته شد', 'hoosh-seo' ), mb_substr( (string) $gap['keyword'], 0, 30 ) ),
			'post_id'      => isset( $out['post_id'] ) ? (int) $out['post_id'] : 0,
			'needs_review' => isset( $out['status'] ) && 'publish' !== $out['status'],
			'reason'       => array( 'keyword' => (string) $gap['keyword'], 'intent' => isset( $gap['intent'] ) ? $gap['intent'] : '' ),
			'result'       => array(
				'post_id' => isset( $out['post_id'] ) ? (int) $out['post_id'] : 0,
				'status'  => isset( $out['status'] ) ? $out['status'] : '',
				'quality' => isset( $out['quality'] ) ? (float) $out['quality'] : 0,
				'words'   => isset( $out['words'] ) ? (int) $out['words'] : 0,
				'title'   => isset( $out['title'] ) ? $out['title'] : '',
			),
			'cost_usd'     => isset( $out['cost_usd'] ) ? (float) $out['cost_usd'] : 0.0,
		);
	}
}
