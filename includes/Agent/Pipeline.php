<?php
/**
 * The content pipeline.
 *
 * Turns one opportunity into one publishable article:
 *
 *   plan  → outline, headings, FAQ, target length
 *   draft → every section written concurrently
 *   seo   → meta, focus keyword, internal links, schema
 *   gate  → refuse to save anything that is not good enough
 *   save  → post + agent_content ledger row
 *
 * Drafting is the slow part and the sections are independent, so they are
 * sent as one concurrent batch instead of one at a time.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Agent;

use HooshSEO\AI\Gateway;
use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Meta;
use HooshSEO\Modules\Content;
use HooshSEO\Modules\Links;

defined( 'ABSPATH' ) || exit;

/**
 * Class Pipeline
 */
final class Pipeline {

	/**
	 * Produce one article from one opportunity.
	 *
	 * @param array $gap    Opportunity row from Insight.
	 * @param int   $run_id Agent run id.
	 * @return array {ok, post_id, title, status, quality, words, cost_usd, message}
	 */
	public static function article( $gap, $run_id = 0 ) {
		$settings = \hoosh_seo()->settings;
		$keyword  = trim( (string) ( isset( $gap['keyword'] ) ? $gap['keyword'] : '' ) );

		if ( mb_strlen( $keyword ) < 3 ) {
			return array( 'ok' => false, 'message' => __( 'کلیدواژه معتبر نیست.', 'hoosh-seo' ) );
		}

		$intent   = isset( $gap['intent'] ) && $gap['intent'] ? (string) $gap['intent'] : 'informational';
		$words    = isset( $gap['words'] ) && $gap['words'] ? (int) $gap['words'] : (int) $settings->get( 'agent.target_words', 1400 );
		$tone     = (string) $settings->get( 'agent.tone', 'professional' );
		$language = 'en' === $settings->get( 'agent.language', 'fa' ) ? 'انگلیسی' : 'فارسی';
		$cost     = 0.0;

		$row_id = self::ledger_open( $gap, $run_id, $intent );

		// --- 1. Plan -------------------------------------------------------
		$plan = Gateway::complete(
			array(
				'task'       => 'keywords',
				'max_tokens' => 1400,
				'json'       => array(
					'title'    => 'string — عنوان سئو، حداکثر ۵۸ کاراکتر',
					'slug'     => 'string — نامک انگلیسی کوتاه با خط تیره',
					'angle'    => 'string — زاویهٔ یکتای این مقاله در یک جمله',
					'sections' => 'array of {h2:string, points:array of string} — ۵ تا ۸ بخش',
					'faq'      => 'array of {q:string, a:string} — ۳ تا ۵ پرسش',
					'meta'     => 'string — توضیحات متا، ۱۲۰ تا ۱۵۵ کاراکتر',
				),
				'prompt'     => sprintf(
					"یک مقالهٔ %s دربارهٔ «%s» می‌خواهیم. نیت جستجو: %s. طول هدف: %d کلمه. لحن: %s.\nسایت: %s\n\nساختار مقاله را طراحی کن. بخش‌ها باید واقعاً متفاوت باشند و با هم تکراری نباشند.",
					$language,
					$keyword,
					$intent,
					$words,
					$tone,
					home_url( '/' )
				),
			)
		);
		$cost += isset( $plan['cost'] ) ? (float) $plan['cost'] : 0.0;

		if ( empty( $plan['ok'] ) || empty( $plan['json']['sections'] ) ) {
			self::ledger_update( $row_id, array( 'status' => 'failed', 'author_note' => __( 'طراحی ساختار ناموفق بود.', 'hoosh-seo' ) ) );
			return array(
				'ok'      => false,
				'message' => isset( $plan['error'] ) ? $plan['error'] : __( 'طراحی ساختار مقاله ناموفق بود.', 'hoosh-seo' ),
				'cost_usd' => $cost,
			);
		}

		$blueprint = (array) $plan['json'];
		$sections  = array_slice( (array) $blueprint['sections'], 0, 8 );
		$faq       = isset( $blueprint['faq'] ) ? array_slice( (array) $blueprint['faq'], 0, 5 ) : array();
		$title     = isset( $blueprint['title'] ) ? Meta::clamp_text( (string) $blueprint['title'], 60 ) : mb_substr( $keyword, 0, 55 );
		$slug      = isset( $blueprint['slug'] ) ? sanitize_title( (string) $blueprint['slug'] ) : '';
		if ( ! $slug ) {
			$slug = sanitize_title( Helpers::normalize_fa( $keyword ) );
		}
		if ( ! $slug ) {
			$slug = 'post-' . wp_generate_password( 6, false, false );
		}

		self::ledger_update( $row_id, array(
			'title'   => $title,
			'slug'    => $slug,
			'outline' => wp_json_encode( array( 'sections' => $sections, 'faq' => $faq ), JSON_UNESCAPED_UNICODE ),
			'brief'   => wp_json_encode( $blueprint, JSON_UNESCAPED_UNICODE ),
			'status'  => 'drafting',
		) );

		// --- 2. Draft every section concurrently ---------------------------
		$jobs = array();
		foreach ( $sections as $section ) {
			$h2     = isset( $section['h2'] ) ? (string) $section['h2'] : '';
			$points = isset( $section['points'] ) ? (array) $section['points'] : array();
			if ( '' === trim( $h2 ) ) {
				continue;
			}
			$jobs[] = array(
				'task'       => 'rewrite',
				'max_tokens' => max( 500, (int) round( $words / max( 1, count( $sections ) ) * 1.4 ) ),
				'temperature' => 0.6,
				'prompt'     => sprintf(
					"فقط همین یک بخش از مقالهٔ «%s» را بنویس. زیرتیتر: %s\nنکته‌هایی که باید پوشش داده شود: %s\n\nخروجی را با <h2> شروع کن و بعد پاراگراف‌های کوتاه (۲ تا ۴ جمله) بنویس. از لیست و جدول در جای مناسب استفاده کن. دربارهٔ بخش‌های دیگر چیزی ننویس و مقدمه یا نتیجه‌گیری اضافه نکن.",
					$keyword,
					$h2,
					$points ? implode( '؛ ', array_slice( $points, 0, 6 ) ) : '—'
				),
			);
		}

		if ( ! $jobs ) {
			self::ledger_update( $row_id, array( 'status' => 'failed', 'author_note' => __( 'بخشی برای نوشتن نماند.', 'hoosh-seo' ) ) );
			return array( 'ok' => false, 'message' => __( 'ساختار مقاله خالی بود.', 'hoosh-seo' ), 'cost_usd' => $cost );
		}

		$drafts     = Gateway::complete_many( $jobs, max( 1, (int) $settings->get( 'agent.parallel', 4 ) ) );
		$pieces     = array();
		$failed     = 0;

		foreach ( $drafts as $i => $draft ) {
			$cost += isset( $draft['cost'] ) ? (float) $draft['cost'] : 0.0;
			if ( empty( $draft['ok'] ) || '' === trim( (string) ( isset( $draft['text'] ) ? $draft['text'] : '' ) ) ) {
				$failed++;
				continue;
			}
			$pieces[] = self::clean_section( (string) $draft['text'] );
		}

		// Refuse to publish something half-written.
		if ( $failed && count( $pieces ) < max( 2, (int) ceil( count( $jobs ) * 0.6 ) ) ) {
			self::ledger_update( $row_id, array( 'status' => 'failed', 'author_note' => sprintf( /* translators: %d: count */ __( '%d بخش نوشته نشد.', 'hoosh-seo' ), $failed ) ) );
			return array( 'ok' => false, 'message' => __( 'نگارش بخش‌ها ناموفق بود.', 'hoosh-seo' ), 'cost_usd' => $cost );
		}

		// --- 3. Assemble ---------------------------------------------------
		$intro = self::intro( $keyword, $intent, isset( $blueprint['angle'] ) ? (string) $blueprint['angle'] : '' );
		$cost += isset( $intro['cost'] ) ? (float) $intro['cost'] : 0.0;

		$body = ( isset( $intro['text'] ) ? $intro['text'] : '' ) . "\n" . implode( "\n", $pieces );
		if ( $faq ) {
			$body .= "\n" . self::faq_html( $faq );
		}
		$body = self::clean_section( $body );

		// --- 4. Quality gate ----------------------------------------------
		$quality = self::quality( $body, $keyword, $words );
		$minimum = (float) $settings->get( 'agent.min_quality', 70 );

		if ( $quality['score'] < $minimum ) {
			self::ledger_update( $row_id, array(
				'draft'       => $body,
				'quality'     => $quality['score'],
				'words'       => $quality['words'],
				'status'      => 'rejected',
				'author_note' => implode( '؛ ', $quality['issues'] ),
			) );
			return array(
				'ok'       => false,
				'title'    => $title,
				'quality'  => $quality['score'],
				'words'    => $quality['words'],
				'message'  => sprintf( /* translators: 1: score, 2: min */ __( 'کیفیت %1$s کمتر از حد مجاز %2$s بود؛ منتشر نشد.', 'hoosh-seo' ), round( $quality['score'] ), round( $minimum ) ),
				'issues'   => $quality['issues'],
				'cost_usd' => $cost,
			);
		}

		// --- 5. Save -------------------------------------------------------
		$mode = (string) $settings->get( 'agent.publish', 'draft' );
		$status = 'publish' === $mode ? 'publish' : 'draft';
		if ( Guard::needs_review( 'write_article', array( 'keyword' => $keyword ) ) ) {
			$status = 'draft';
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $body,
				'post_excerpt' => isset( $blueprint['meta'] ) ? sanitize_text_field( (string) $blueprint['meta'] ) : '',
				'post_status'  => $status,
				'post_type'    => 'post',
				'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			self::ledger_update( $row_id, array( 'status' => 'failed', 'draft' => $body, 'author_note' => is_wp_error( $post_id ) ? $post_id->get_error_message() : '' ) );
			return array( 'ok' => false, 'message' => is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'ذخیرهٔ نوشته ناموفق بود.', 'hoosh-seo' ), 'cost_usd' => $cost );
		}
		$post_id = (int) $post_id;

		// Meta, focus keyword, FAQ schema.
		Meta::save( $post_id, array(
			'title'       => $title,
			'description' => isset( $blueprint['meta'] ) ? Meta::clamp_text( (string) $blueprint['meta'], 158 ) : '',
			'keywords'    => array( $keyword ),
		) );
		update_post_meta( $post_id, '_hs_agent', array( 'run' => (int) $run_id, 'keyword' => $keyword, 'quality' => $quality['score'], 'at' => time() ) );

		if ( $faq ) {
			self::save_faq( $post_id, $faq );
		}
		self::add_internal_links( $post_id );

		Content::queue_analysis( $post_id );

		self::ledger_update( $row_id, array(
			'post_id'    => $post_id,
			'draft'      => $body,
			'quality'    => $quality['score'],
			'words'      => $quality['words'],
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		) );

		/**
		 * Fires after the agent publishes or drafts an article.
		 *
		 * @param int   $post_id  New post.
		 * @param array $gap      Opportunity.
		 * @param array $quality  Quality report.
		 */
		do_action( 'hoosh_seo_agent_article', $post_id, $gap, $quality );

		return array(
			'ok'       => true,
			'post_id'  => $post_id,
			'title'    => $title,
			'status'   => $status,
			'quality'  => $quality['score'],
			'words'    => $quality['words'],
			'cost_usd' => $cost,
			'url'      => get_permalink( $post_id ),
		);
	}

	/**
	 * Articles the pipeline has produced.
	 *
	 * @param string $status Filter.
	 * @param int    $limit  Max rows.
	 * @return array
	 */
	public static function ledger( $status = '', $limit = 30 ) {
		global $wpdb;
		if ( ! Database::exists( 'agent_content' ) ) {
			return array();
		}
		$sql  = 'SELECT * FROM ' . Database::table( 'agent_content' );
		$args = array();
		if ( '' !== $status ) {
			$sql   .= ' WHERE status = %s';
			$args[] = sanitize_key( $status );
		}
		$sql   .= ' ORDER BY id DESC LIMIT %d';
		$args[] = max( 1, (int) $limit );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Write the intro — it sets the tone for the whole piece.
	 *
	 * @param string $keyword Keyword.
	 * @param string $intent  Intent.
	 * @param string $angle   Unique angle.
	 * @return array {text, cost}
	 */
	protected static function intro( $keyword, $intent, $angle = '' ) {
		$out = Gateway::complete(
			array(
				'task'       => 'rewrite',
				'max_tokens' => 320,
				'temperature' => 0.5,
				'prompt'     => sprintf(
					"مقدمهٔ یک مقاله دربارهٔ «%s» بنویس. نیت جستجو: %s. زاویه: %s\n\nسه پاراگراف کوتاه: مشکل یا سؤال خواننده، قول مقاله، و نقشهٔ راه. زیرتیتر نگذار و مستقیم سر اصل مطلب برو.",
					$keyword,
					$intent,
					$angle ? $angle : '—'
				),
			)
		);
		if ( empty( $out['ok'] ) ) {
			return array( 'text' => '', 'cost' => isset( $out['cost'] ) ? (float) $out['cost'] : 0.0 );
		}
		return array(
			'text' => wpautop( wp_kses_post( trim( (string) $out['text'] ) ) ),
			'cost' => isset( $out['cost'] ) ? (float) $out['cost'] : 0.0,
		);
	}

	/**
	 * Normalise a drafted section.
	 *
	 * Models like to wrap output in markdown fences or repeat the instruction;
	 * both break the HTML.
	 *
	 * @param string $text Raw model output.
	 * @return string HTML.
	 */
	protected static function clean_section( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^\s*```(?:html)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```\s*$/', '', $text );

		// Turn leftover markdown into real HTML rather than letting it ship raw.
		if ( false === strpos( $text, '<' ) ) {
			$lines = preg_split( '/\R/', $text );
			$html  = array();
			$list  = array();
			foreach ( (array) $lines as $line ) {
				$line = rtrim( $line );
				if ( preg_match( '/^#{2,3}\s+(.+)$/', $line, $m ) ) {
					$tag    = substr( $m[0], 0, strpos( $m[0], ' ' ) );
					$level  = strlen( $tag );
					$html[] = '<h' . $level . '>' . esc_html( trim( $m[1] ) ) . '</h' . $level . '>';
					continue;
				}
				if ( preg_match( '/^\s*[-*]\s+(.+)$/', $line, $m ) ) {
					$list[] = '<li>' . esc_html( trim( $m[1] ) ) . '</li>';
					continue;
				}
				if ( $list ) {
					$html[] = '<ul>' . implode( '', $list ) . '</ul>';
					$list   = array();
				}
				if ( '' !== trim( $line ) ) {
					$html[] = '<p>' . esc_html( trim( $line ) ) . '</p>';
				}
			}
			if ( $list ) {
				$html[] = '<ul>' . implode( '', $list ) . '</ul>';
			}
			return implode( "\n", $html );
		}

		return wp_kses_post( $text );
	}

	/**
	 * FAQ block as HTML.
	 *
	 * @param array $faq {q,a} rows.
	 * @return string
	 */
	protected static function faq_html( $faq ) {
		$out = array( '<h2>' . esc_html__( 'پرسش‌های متداول', 'hoosh-seo' ) . '</h2>' );
		foreach ( (array) $faq as $item ) {
			$q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
			$a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out[] = '<h3>' . esc_html( $q ) . '</h3>' . wpautop( esc_html( $a ) );
		}
		return count( $out ) > 1 ? implode( "\n", $out ) : '';
	}

	/**
	 * Store FAQ entities so the schema module can emit FAQPage.
	 *
	 * @param int   $post_id Post.
	 * @param array $faq     {q,a} rows.
	 */
	protected static function save_faq( $post_id, $faq ) {
		$entities = array();
		foreach ( (array) $faq as $item ) {
			if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
				continue;
			}
			$entities[] = array(
				'question' => sanitize_text_field( (string) $item['q'] ),
				'answer'   => sanitize_textarea_field( (string) $item['a'] ),
			);
		}
		if ( $entities ) {
			update_post_meta( (int) $post_id, '_hs_faq', $entities );
		}
	}

	/**
	 * Give a brand-new post its first inbound internal links.
	 *
	 * @param int $post_id Post.
	 */
	protected static function add_internal_links( $post_id ) {
		$suggestions = Links::suggest( (int) $post_id, 6 );
		$keywords    = isset( $suggestions['keywords'] ) ? (array) $suggestions['keywords'] : array();
		$picked      = array();

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
		if ( $picked ) {
			Links::insert_into_content( (int) $post_id, $picked );
		}
	}

	/**
	 * Score a draft before it is allowed near the site.
	 *
	 * Deliberately mechanical — no AI judging its own work.
	 *
	 * @param string $body    HTML.
	 * @param string $keyword Focus keyword.
	 * @param int    $target  Target word count.
	 * @return array {score, words, issues[]}
	 */
	protected static function quality( $body, $keyword, $target ) {
		$words  = Helpers::word_count( $body );
		$text   = wp_strip_all_tags( $body );
		$norm   = Helpers::normalize_fa( mb_strtolower( $text ) );
		$kwnorm = Helpers::normalize_fa( mb_strtolower( (string) $keyword ) );
		$issues = array();
		$score  = 100;

		$ratio = $target > 0 ? $words / $target : 0;
		if ( $ratio < 0.6 ) {
			$score  -= 30;
			$issues[] = sprintf( /* translators: 1: words, 2: target */ __( 'متن کوتاه است (%1$d از %2$d کلمه)', 'hoosh-seo' ), $words, $target );
		} elseif ( $ratio < 0.85 ) {
			$score  -= 12;
			$issues[] = __( 'متن کمی کوتاه‌تر از هدف است.', 'hoosh-seo' );
		}

		$h2 = preg_match_all( '/<h2[\s>]/i', (string) $body );
		if ( $h2 < 3 ) {
			$score  -= 15;
			$issues[] = sprintf( /* translators: %d: count */ __( 'فقط %d زیرتیتر H2 دارد.', 'hoosh-seo' ), $h2 );
		}

		$density = $words > 0 && '' !== $kwnorm ? ( substr_count( $norm, $kwnorm ) * 100 / max( 1, $words ) ) : 0;
		if ( 0 === substr_count( $norm, $kwnorm ) ) {
			$score  -= 20;
			$issues[] = __( 'کلیدواژهٔ هدف در متن نیست.', 'hoosh-seo' );
		} elseif ( $density > 4 ) {
			$score  -= 15;
			$issues[] = sprintf( /* translators: %s: density */ __( 'چگالی کلیدواژه بیش از حد است (%s٪).', 'hoosh-seo' ), round( $density, 1 ) );
		}

		$sentences = max( 1, count( preg_split( '/[.!?؟۔]\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ) );
		if ( $words / $sentences > 32 ) {
			$score  -= 8;
			$issues[] = __( 'جمله‌ها بلندند؛ خوانایی کم است.', 'hoosh-seo' );
		}

		return array(
			'score'  => round( Helpers::clamp( $score, 0, 100 ), 1 ),
			'words'  => (int) $words,
			'h2'     => (int) $h2,
			'issues' => $issues,
		);
	}

	/**
	 * Open an agent_content row.
	 *
	 * @param array  $gap    Opportunity.
	 * @param int    $run_id Run.
	 * @param string $intent Intent.
	 * @return int Row id.
	 */
	protected static function ledger_open( $gap, $run_id, $intent ) {
		global $wpdb;
		if ( ! Database::exists( 'agent_content' ) ) {
			return 0;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			Database::table( 'agent_content' ),
			array(
				'run_id'     => (int) $run_id,
				'keyword'    => mb_substr( (string) ( isset( $gap['keyword'] ) ? $gap['keyword'] : '' ), 0, 250 ),
				'norm'       => mb_substr( Helpers::normalize_fa( (string) ( isset( $gap['keyword'] ) ? $gap['keyword'] : '' ) ), 0, 250 ),
				'intent'     => sanitize_key( $intent ),
				'status'     => 'brief',
				'sources'    => wp_json_encode( $gap, JSON_UNESCAPED_UNICODE ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an agent_content row.
	 *
	 * @param int   $row_id Row.
	 * @param array $data   Columns.
	 */
	protected static function ledger_update( $row_id, $data ) {
		global $wpdb;
		if ( ! $row_id || ! Database::exists( 'agent_content' ) ) {
			return;
		}
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( Database::table( 'agent_content' ), $data, array( 'id' => (int) $row_id ) );
	}
}
