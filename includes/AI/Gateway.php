<?php
/**
 * AI gateway: cache, budget guard, queue, logging, provider fallback.
 *
 * @package HooshSEO
 */

namespace HooshSEO\AI;

use HooshSEO\Database;
use HooshSEO\Helpers;
use HooshSEO\Modules\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Class Gateway
 */
final class Gateway {

	/**
	 * Singleton.
	 *
	 * The gateway is a stateless facade over static methods, but the service
	 * container in Plugin::boot() resolves every module through instance(),
	 * so it needs one to stay loadable.
	 *
	 * @var Gateway|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Gateway
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Settings handle, null-safe (the gateway is callable before boot finishes).
	 *
	 * @return \HooshSEO\Settings|null
	 */
	protected static function settings() {
		$plugin = \hoosh_seo();
		return $plugin ? $plugin->settings : null;
	}

	/**
	 * Is a usable provider configured?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$settings = self::settings();
		if ( ! $settings || ! $settings->on( 'ai.enabled' ) ) {
			return false;
		}
		$driver = self::active_driver();
		if ( in_array( $driver, array( 'ollama', 'lmstudio' ), true ) ) {
			return true;
		}
		return '' !== Providers::key( $driver );
	}

	/**
	 * Active driver key (with fallback resolution).
	 *
	 * @return string
	 */
	public static function active_driver() {
		$settings = \hoosh_seo()->settings;
		$driver   = (string) $settings->get( 'ai.driver', 'openai' );
		$driver   = sanitize_key( $driver );
		$catalog  = Providers::catalog();
		if ( ! isset( $catalog[ $driver ] ) ) {
			$driver = 'openai';
		}
		if ( '' !== Providers::key( $driver ) || in_array( $driver, array( 'ollama', 'lmstudio' ), true ) ) {
			return $driver;
		}
		$fallback = sanitize_key( (string) $settings->get( 'ai.fallback', '' ) );
		if ( $fallback && ( '' !== Providers::key( $fallback ) || in_array( $fallback, array( 'ollama', 'lmstudio' ), true ) ) ) {
			return $fallback;
		}
		// Anything that has a key at all.
		foreach ( Providers::ready() as $key => $meta ) {
			if ( ! empty( $meta['ready'] ) ) {
				return (string) $key;
			}
		}
		return $driver;
	}

	/**
	 * Main completion entry point.
	 *
	 * @param array $args {task, system, prompt, post_id, max_tokens, temperature, json, image, force, user}.
	 * @return array
	 */
	public static function complete( $args = array() ) {
		$settings = \hoosh_seo()->settings;
		$args = wp_parse_args(
			(array) $args,
			array(
				'task'        => 'generic',
				'system'      => '',
				'prompt'      => '',
				'post_id'     => 0,
				'max_tokens'  => (int) $settings->get( 'ai.max_tokens', 1200 ),
				'temperature' => (float) $settings->get( 'ai.temperature', 0.4 ),
				'json'        => null,
				'image'       => '',
				'force'       => false,
				'job_id'      => 0,
				'cache_key'   => '',
				'user'        => '',
			)
		);

		$start = microtime( true );

		if ( ! self::is_configured() ) {
			return self::error( __( 'هوش مصنوعی تنظیم نشده است؛ در بخش تنظیمات یک سرویس و کلید اضافه کنید.', 'hoosh-seo' ), 'not-configured' );
		}
		if ( ! $args['prompt'] && ! $args['user'] ) {
			return self::error( __( 'چیزی برای پرسیدن فرستاده نشد.', 'hoosh-seo' ), 'empty-prompt' );
		}

		if ( ! self::budget_allows() ) {
			return self::error( __( 'سقف هزینه ماهانه پر شده است؛ در تنظیمات مقدارش را بالا ببرید.', 'hoosh-seo' ), 'budget' );
		}
		if ( ! self::rate_allows() ) {
			return self::error( __( 'تعداد درخواست در این دقیقه زیاد است؛ چند لحظه دیگر دوباره امتحان کنید.', 'hoosh-seo' ), 'rate' );
		}

		$args['prompt'] = self::prepare( (string) $args['prompt'], (int) $settings->get( 'ai.max_chars', 9000 ) );
		$system         = $args['system'] ? (string) $args['system'] : self::system_prompt();

		$cache_key = $args['cache_key'] ?: 'ai-' . md5( wp_json_encode( array( $args['task'], $system, $args['prompt'], $args['max_tokens'], $args['temperature'], self::active_driver() ) ) );
		if ( ! $args['force'] && $settings->on( 'ai.cache' ) && $settings->get( 'ai.cache_ttl', 72 ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached['ok'] ) ) {
				$cached['cached'] = true;
				$cached['ms']     = (int) round( ( microtime( true ) - $start ) * 1000 );
				self::log_call( 0, $args['task'], $cached, true, $cache_key );
				return $cached;
			}
		}

		$messages = array();
		if ( $system ) {
			$messages[] = array( 'role' => 'system', 'content' => $system );
		}
		if ( $args['prompt'] ) {
			$messages[] = array( 'role' => 'user', 'content' => $args['prompt'] );
		}
		if ( $args['user'] ) {
			$messages[] = array( 'role' => 'user', 'content' => self::prepare( (string) $args['user'], 4000 ) );
		}

		$driver  = self::active_driver();
		$fallback = sanitize_key( (string) $settings->get( 'ai.fallback', '' ) );
		$attempts = array();

		$result = Providers::request(
			$messages,
			array(
				'driver'      => $driver,
				'model'       => self::model_for( $args['task'] ),
				'temperature' => (float) $args['temperature'],
				'max_tokens'  => (int) $args['max_tokens'],
				'json'        => $args['json'],
				'image'       => (string) $args['image'],
				'task'        => (string) $args['task'],
				'job_id'      => (int) $args['job_id'],
			)
		);
		$attempts[] = array( 'driver' => $driver, 'ok' => (bool) $result['ok'], 'error' => (string) $result['error'] );

		if ( ! $result['ok'] && $fallback && $fallback !== $driver ) {
			$result = Providers::request(
				$messages,
				array(
					'driver'      => $fallback,
					'model'       => self::model_for( $args['task'] ),
					'temperature' => (float) $args['temperature'],
					'max_tokens'  => (int) $args['max_tokens'],
					'json'        => $args['json'],
					'image'       => (string) $args['image'],
					'task'        => (string) $args['task'],
					'job_id'      => (int) $args['job_id'],
				)
			);
			$attempts[] = array( 'driver' => $fallback, 'ok' => (bool) $result['ok'], 'error' => (string) $result['error'] );
		}

		$result['attempts_chain'] = $attempts;
		$result['ms']             = (int) round( ( microtime( true ) - $start ) * 1000 );
		$result['task']           = (string) $args['task'];
		$result['cached']         = false;

		if ( $result['ok'] ) {
			$result['json']   = Providers::parse_json( (string) $result['text'] );
			$result['cost']   = Providers::cost( Providers::config( (string) $result['driver'] ), (int) $result['usage']['prompt'], (int) $result['usage']['completion'] );
			$result['tokens'] = (int) $result['usage']['prompt'] + (int) $result['usage']['completion'];
			if ( $settings->on( 'ai.cache' ) && $settings->get( 'ai.cache_ttl', 72 ) ) {
				set_transient( $cache_key, $result, (int) $settings->get( 'ai.cache_ttl', 72 ) * HOUR_IN_SECONDS );
			}
		} else {
			$result['hint'] = Providers::hint( $result );
		}

		self::log_call( (int) $args['job_id'], $args['task'], $result, false, $cache_key );

		/**
		 * Fires after every AI call.
		 *
		 * @param array $result Result.
		 * @param array $args   Request args.
		 */
		do_action( 'hoosh_seo_ai_complete', $result, $args );

		return $result;
	}

	/**
	 * Chat with the site assistant (multi-turn, uses ai.chat_memory).
	 *
	 * @param string $message User message.
	 * @param array  $history Previous turns.
	 * @param array  $context Extra context (post_id, view).
	 * @return array
	 */
	public static function chat( $message, $history = array(), $context = array() ) {
		$settings = \hoosh_seo()->settings;
		$memory   = (int) $settings->get( 'ai.chat_memory', 12 );
		$post_id  = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;

		$system = self::system_prompt();
		if ( $post_id ) {
			$system .= "\n" . self::post_context( $post_id );
		}
		if ( ! empty( $context['snapshot'] ) && is_array( $context['snapshot'] ) ) {
			$system .= "\n" . __( 'داده‌های فعلی سایت برای پاسخ:', 'hoosh-seo' ) . "\n"
				. wp_json_encode( array_slice( $context['snapshot'], 0, 12 ), JSON_UNESCAPED_UNICODE );
		}

		$messages = array( array( 'role' => 'system', 'content' => $system ) );
		foreach ( array_slice( (array) $history, -1 * max( 0, $memory - 1 ) ) as $turn ) {
			if ( ! is_array( $turn ) || empty( $turn['content'] ) ) {
				continue;
			}
			$role = in_array( (string) ( $turn['role'] ?? 'user' ), array( 'user', 'assistant' ), true ) ? (string) $turn['role'] : 'user';
			$messages[] = array( 'role' => $role, 'content' => self::prepare( (string) $turn['content'], 3000 ) );
		}
		$messages[] = array( 'role' => 'user', 'content' => self::prepare( (string) $message, 6000 ) );

		$raw = Providers::request(
			$messages,
			array(
				'driver'      => self::active_driver(),
				'temperature' => 0.5,
				'max_tokens'  => (int) $settings->get( 'ai.max_tokens', 1200 ),
				'json'        => false,
				'task'        => 'chat',
			)
		);

		if ( ! $raw['ok'] ) {
			return array( 'ok' => false, 'error' => $raw['error'], 'hint' => Providers::hint( $raw ) );
		}

		self::log_call( 0, 'chat', $raw, false, 'chat' );

		return array(
			'ok'     => true,
			'text'   => (string) $raw['text'],
			'driver' => (string) $raw['driver'],
			'ms'     => (int) $raw['latency'],
			'usage'  => $raw['usage'],
			'token'  => (int) $raw['usage']['prompt'] + (int) $raw['usage']['completion'],
			'ctx'    => array( 'post_id' => $post_id, 'turns' => count( $messages ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Prompt helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Base system prompt (brand voice + rules).
	 *
	 * @return string
	 */
	public static function system_prompt() {
		$settings = \hoosh_seo()->settings;
		$custom   = trim( (string) $settings->get( 'ai.system_prompt', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		$tone = (string) $settings->get( 'ai.tone', 'professional' );
		$tones = array(
			'professional' => __( 'رسمی و حرفه‌ای', 'hoosh-seo' ),
			'friendly'     => __( 'صمیمی و خودمانی', 'hoosh-seo' ),
			'formal'       => __( 'بسیار رسمی', 'hoosh-seo' ),
			'sales'        => __( 'تبلیغاتی با دعوت به اقدام', 'hoosh-seo' ),
			'technical'    => __( 'فنی و دقیق', 'hoosh-seo' ),
		);
		$tone_label = isset( $tones[ $tone ] ) ? $tones[ $tone ] : $tones['professional'];

		$lines = array();
		$lines[] = 'You are the SEO copywriter inside the HooshSEO WordPress plugin.';
		$lines[] = sprintf( /* translators: %s site name */ __( 'سایت: «%s». لحن: %s.', 'hoosh-seo' ), get_bloginfo( 'name' ), $tone_label );
		if ( $settings->get( 'ai.brand' ) ) {
			$lines[] = __( 'برند و محدودیت‌ها:', 'hoosh-seo' ) . ' ' . $settings->get( 'ai.brand' );
		}
		if ( $settings->get( 'ai.audience' ) ) {
			$lines[] = __( 'مخاطب:', 'hoosh-seo' ) . ' ' . $settings->get( 'ai.audience' );
		}
		$lines[] = __( 'خروجی فقط JSON معتبر، بدون توضیح اضافه و بدون markdown.', 'hoosh-seo' );
		$lines[] = __( 'متن فارسی طبیعی و روان بنویس؛ نیم‌فاصله را رعایت کن و از قلم مو و کلمه‌های ترجمه‌شده ماشینی پرهیز کن.', 'hoosh-seo' );
		$lines[] = __( 'از ادعای دروغ، عدد ساختگی و کلمات ممنوعه تبلیغاتی (بهترین، تضمینی، ارزان‌ترین در ایران) پرهیز کن.', 'hoosh-seo' );

		$rules = (array) $settings->get( 'ai.rewrite_rules', array() );
		foreach ( $rules as $rule ) {
			if ( is_string( $rule ) && trim( $rule ) ) {
				$lines[] = '- ' . trim( $rule );
			} elseif ( is_array( $rule ) && ! empty( $rule['text'] ) ) {
				$lines[] = '- ' . trim( (string) $rule['text'] );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Per-task model override.
	 *
	 * @param string $task Task.
	 * @return string
	 */
	public static function model_for( $task ) {
		$tasks = (array) \hoosh_seo()->settings->get( 'ai.tasks', array() );
		$task  = sanitize_key( (string) $task );
		return isset( $tasks[ $task ] ) ? (string) $tasks[ $task ] : '';
	}

	/**
	 * Trim + redact the text we send out.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max chars.
	 * @return string
	 */
	public static function prepare( $text, $max = 9000 ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->on( 'ai.send_content' ) ) {
			return __( 'ارسال محتوا غیرفعال است (تنظیمات → هوش مصنوعی).', 'hoosh-seo' );
		}
		$text = wp_strip_all_tags( str_replace( array( "\xc2\xa0" ), ' ', $text ) );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );

		if ( $settings->on( 'ai.strip_pii' ) ) {
			$text = preg_replace( '/\b(?:0\d{2}|9\d{2})[- ]?\d{3}[- ]?\d{4}\b/u', '[شماره حذف شد]', $text );
			$text = preg_replace( '/[\w\.\-\+]+@[\w\.\-]+\.\w{2,}/u', '[ایمیل حذف شد]', $text );
			$text = preg_replace( '/\b(?:\d[\s-]?){16}\b/', '[کارت حذف شد]', $text );
			$text = preg_replace( '/\b(?:\d{3,10})(?:\s?[\u06f0-\u06f9]{10})?\b(?=\s*(?:کد ملی|national))/', '[کد حذف شد]', $text );
		}

		if ( $max > 0 && mb_strlen( $text ) > $max ) {
			$text = mb_substr( $text, 0, (int) ( $max * 0.65 ) )
				. "\n\n…\n\n"
				. mb_substr( $text, -1 * (int) ( $max * 0.3 ) );
		}
		return $text;
	}

	/**
	 * Compact post context for prompts.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function post_context( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$keywords = \HooshSEO\Meta::focus_keywords( $post_id );
		$lines    = array(
			__( 'عنوان فعلی:', 'hoosh-seo' ) . ' ' . \HooshSEO\Meta::resolve( $post_id, 'title' ),
			__( 'توضیحات فعلی:', 'hoosh-seo' ) . ' ' . \HooshSEO\Meta::resolve( $post_id, 'description' ),
			__( 'کلمه کلیدی:', 'hoosh-seo' ) . ' ' . ( $keywords ? implode( '، ', $keywords ) : __( 'تنظیم نشده', 'hoosh-seo' ) ),
			__( 'دسته‌ها:', 'hoosh-seo' ) . ' ' . wp_strip_all_tags( get_the_term_list( $post_id, 'category', '', '، ', '' ) ?: __( '—', 'hoosh-seo' ) ),
			__( 'تعداد واژگان:', 'hoosh-seo' ) . ' ' . Helpers::number( Helpers::word_count( $post->post_content ) ),
		);
		return implode( "\n", $lines );
	}

	/* ---------------------------------------------------------------------
	 * Budget / rate
	 * ------------------------------------------------------------------ */

	/**
	 * Monthly spend vs budget.
	 *
	 * @return bool
	 */
	public static function budget_allows() {
		$budget = (float) \hoosh_seo()->settings->get( 'ai.budget_usd', 0 );
		if ( $budget <= 0 ) {
			return true;
		}
		return self::spend_summary()['month_cost'] < $budget;
	}

	/**
	 * Soft per-minute limiter.
	 *
	 * @return bool
	 */
	public static function rate_allows() {
		$limit = (int) \hoosh_seo()->settings->get( 'ai.user_rate_limit', 20 );
		if ( $limit <= 0 ) {
			return true;
		}
		$key   = 'ai_rl_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, 120 );
		return true;
	}

	/**
	 * Daily call cap.
	 *
	 * @return bool
	 */
	public static function daily_allows() {
		$cap = (int) \hoosh_seo()->settings->get( 'ai.daily_calls', 0 );
		if ( $cap <= 0 ) {
			return true;
		}
		return self::spend_summary()['today'] < $cap;
	}

	/* ---------------------------------------------------------------------
	 * Queue (ai_jobs)
	 * ------------------------------------------------------------------ */

	/**
	 * Queue one AI job.
	 *
	 * @param array $args {task, post_id, payload, priority, batch_id, created_by}.
	 * @return int
	 */
	public static function queue( $args ) {
		global $wpdb;
		if ( ! Database::exists( 'ai_jobs' ) ) {
			return 0;
		}
		$args = wp_parse_args(
			(array) $args,
			array(
				'task'      => 'generic',
				'post_id'   => 0,
				'payload'   => array(),
				'priority'  => 5,
				'batch_id'  => '',
				'dedupe'    => true,
				'created_by' => (int) get_current_user_id(),
			)
		);

		$task = sanitize_key( (string) $args['task'] );

		if ( $args['dedupe'] ) {
			$existing = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . Database::table( 'ai_jobs' ) . " WHERE status IN ('queued','running') AND type = %s AND post_id = %d LIMIT 1",
					$task,
					(int) $args['post_id']
				)
			); // phpcs:ignore
			if ( $existing ) {
				return $existing;
			}
		}

		$wpdb->insert( // phpcs:ignore
			Database::table( 'ai_jobs' ),
			array(
				'type'       => $task,
				'post_id'    => (int) $args['post_id'],
				'payload'    => wp_json_encode( (array) $args['payload'] ),
				'result'     => '',
				'status'     => 'queued',
				'progress'   => 0,
				'total'      => 0,
				'error'      => '',
				'attempts'   => 0,
				'batch_id'   => substr( sanitize_text_field( (string) $args['batch_id'] ), 0, 32 ),
				'created_by' => (int) $args['created_by'],
				'created_at' => current_time( 'mysql', true ),
			)
		);

		$id = (int) $wpdb->insert_id;
		\HooshSEO\Cron::soft_drain();

		/**
		 * Fires after a job is queued.
		 *
		 * @param int   $id   Job id.
		 * @param array $args Args.
		 */
		do_action( 'hoosh_seo_ai_queued', $id, $args );

		return $id;
	}

	/**
	 * Queue many posts for a task.
	 *
	 * @param string $task    Task.
	 * @param array  $post_ids Post IDs.
	 * @param array  $payload Payload.
	 * @return array
	 */
	public static function queue_many( $task, $post_ids, $payload = array() ) {
		$batch = substr( md5( uniqid( 'hs', true ) ), 0, 32 );
		$ids   = array();
		foreach ( array_slice( array_map( 'absint', (array) $post_ids ), 0, 2000 ) as $post_id ) {
			if ( ! $post_id ) {
				continue;
			}
			$id = self::queue(
				array(
					'task'     => $task,
					'post_id'  => $post_id,
					'payload'  => (array) $payload,
					'batch_id' => $batch,
				)
			);
			if ( $id ) {
				$ids[] = $id;
			}
		}
		return array(
			'ok'     => (bool) $ids,
			'queued' => count( $ids ),
			'batch'  => $batch,
			'ids'    => array_slice( $ids, 0, 50 ),
			'message' => sprintf( /* translators: %d count */ __( '%d کار در صف هوش مصنوعی قرار گرفت.', 'hoosh-seo' ), count( $ids ) ),
		);
	}

	/**
	 * Process queued jobs. Called from cron/Studio.
	 *
	 * @param array $args {limit, task, only_batch, time_budget}.
	 * @return array
	 */
	public static function process_queue( $args = array() ) {
		global $wpdb;
		$settings = \hoosh_seo()->settings;
		if ( ! Database::exists( 'ai_jobs' ) ) {
			return array( 'ok' => false, 'message' => __( 'جدول کارها موجود نیست.', 'hoosh-seo' ) );
		}
		$args = wp_parse_args(
			(array) $args,
			array(
				'limit'       => (int) $settings->get( 'ai.batch_size', 5 ),
				'task'        => '',
				'only_batch'  => '',
				'time_budget' => 20,
				'study'       => false,
			)
		);

		if ( ! self::is_configured() ) {
			return array( 'ok' => false, 'message' => __( 'سرویس هوش مصنوعی تنظیم نشده است.', 'hoosh-seo' ) );
		}
		if ( ! self::budget_allows() || ! self::daily_allows() ) {
			return array( 'ok' => false, 'paused' => true, 'message' => __( 'سقف هزینه/تعداد روزانه پر شده؛ صف متوقف است.', 'hoosh-seo' ) );
		}

		$limit = max( 1, min( 50, (int) $args['limit'] ) );
		$table = Database::table( 'ai_jobs' );
		$sql   = "SELECT * FROM {$table} WHERE status = 'queued'";
		$params = array();
		if ( $args['task'] ) {
			$sql     .= ' AND type = %s';
			$params[] = sanitize_key( $args['task'] );
		}
		if ( $args['only_batch'] ) {
			$sql     .= ' AND batch_id = %s';
			$params[] = $args['only_batch'];
		}
		$sql .= ' ORDER BY id ASC LIMIT %d';
		$params[] = $limit;

		$jobs = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore

		update_option( 'hoosh_running_job', array( 'ai-queue' => time() ), false );

		$done    = 0;
		$failed  = 0;
		$started = time();
		$results = array();

		foreach ( $jobs as $job ) {
			if ( ( time() - $started ) > (int) $args['time_budget'] ) {
				break;
			}
			$result = self::run_job( (int) $job['id'], (bool) $args['study'] );
			if ( $result['ok'] ) {
				$done++;
			} else {
				$failed++;
			}
			$results[] = $result;
		}

		delete_option( 'hoosh_running_job' );

		$remaining = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . " WHERE status = 'queued'" ); // phpcs:ignore

		return array(
			'ok'        => true,
			'done'      => $done,
			'failed'    => $failed,
			'remaining' => $remaining,
			'results'   => array_slice( $results, 0, 20 ),
			'message'   => $remaining
				? sprintf( /* translators: 1: done, 2: left */ __( '%1$d کار انجام شد؛ %2$d در صف است.', 'hoosh-seo' ), $done, $remaining )
				: sprintf( /* translators: %d count */ __( 'همه کارها انجام شد (%d مورد).', 'hoosh-seo' ), $done ),
		);
	}

	/**
	 * Run one job.
	 *
	 * @param int  $id   Job id.
	 * @param bool $dry  Dry run (do not write).
	 * @return array
	 */
	public static function run_job( $id, $dry = false ) {
		global $wpdb;
		$table = Database::table( 'ai_jobs' );
		$job   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $job ) {
			return array( 'ok' => false, 'message' => __( 'کار پیدا نشد.', 'hoosh-seo' ) );
		}

		$wpdb->update( // phpcs:ignore
			$table,
			array( 'status' => 'running', 'started_at' => current_time( 'mysql', true ), 'attempts' => (int) $job['attempts'] + 1, 'error' => '' ),
			array( 'id' => (int) $id )
		);

		$payload = (array) json_decode( (string) $job['payload'], true );
		$result  = Tasks::run( (string) $job['type'], (int) $job['post_id'], $payload, $dry );

		if ( ! empty( $result['ok'] ) ) {
			$wpdb->update( // phpcs:ignore
				$table,
				array(
					'status'     => 'done',
					'result'     => wp_json_encode( isset( $result['data'] ) ? $result['data'] : $result ),
					'progress'   => 100,
					'finished_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $id )
			);
		} else {
			$wpdb->update( // phpcs:ignore
				$table,
				array(
					'status'     => 'failed',
					'error'      => mb_substr( (string) ( $result['error'] ?? $result['message'] ?? '' ), 0, 500 ),
					'finished_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $id )
			);
		}

		$result['job_id'] = (int) $id;
		return $result;
	}

	/**
	 * Requeue failed jobs.
	 *
	 * @param array $args {ids, limit, older_than}.
	 * @return array
	 */
	public static function retry_failed( $args = array() ) {
		global $wpdb;
		if ( ! Database::exists( 'ai_jobs' ) ) {
			return array( 'ok' => false, 'count' => 0 );
		}
		$args = wp_parse_args(
			(array) $args,
			array(
				'ids'  => array(),
				'limit' => 200,
			)
		);
		$table = Database::table( 'ai_jobs' );

		if ( (array) $args['ids'] ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) $args['ids'] ) ) );
			$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare( 'UPDATE ' . $table . " SET status = 'queued', attempts = 0, error = '' WHERE id IN ($in)", $ids ) // phpcs:ignore
			);
			$count = count( $ids );
		} else {
			$count = (int) $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . $table . " SET status = 'queued', attempts = 0, error = '' WHERE status = 'failed' AND attempts < 3 ORDER BY id DESC LIMIT %d",
					min( 1000, (int) $args['limit'] )
				)
			); // phpcs:ignore
		}

		\HooshSEO\Cron::soft_drain();

		return array(
			'ok'      => (bool) $count,
			'count'   => $count,
			'message' => sprintf( /* translators: %d count */ __( '%d کار دوباره در صف قرار گرفت.', 'hoosh-seo' ), $count ),
		);
	}

	/**
	 * Cancel queued jobs.
	 *
	 * @param array $ids Ids.
	 * @return int
	 */
	public static function cancel( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::table( 'ai_jobs' ) . " SET status = 'canceled', finished_at = NOW() WHERE id IN ($in) AND status IN ('queued','running')", $ids ) ); // phpcs:ignore
		return count( $ids );
	}

	/**
	 * Jobs for the Studio table.
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function jobs( $args = array() ) {
		global $wpdb;
		if ( ! Database::exists( 'ai_jobs' ) ) {
			return array( 'rows' => array(), 'total' => 0, 'stats' => array() );
		}
		$args = wp_parse_args(
			(array) $args,
			array( 'status' => '', 'task' => '', 'per' => 40, 'paged' => 1, 'post_id' => 0 )
		);
		$table  = Database::table( 'ai_jobs' );
		$where  = '1=1';
		$params = array();
		if ( $args['status'] ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_key( $args['status'] );
		}
		if ( $args['task'] ) {
			$where   .= ' AND type = %s';
			$params[] = sanitize_key( $args['task'] );
		}
		if ( (int) $args['post_id'] ) {
			$where   .= ' AND post_id = %d';
			$params[] = (int) $args['post_id'];
		}
		$per   = max( 5, min( 200, (int) $args['per'] ) );
		$off   = ( max( 1, (int) $args['paged'] ) - 1 ) * $per;

		$rows_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT {$per} OFFSET {$off}";
		$rows     = (array) ( $params ? $wpdb->get_results( $wpdb->prepare( $rows_sql, $params ), ARRAY_A ) : $wpdb->get_results( $rows_sql, ARRAY_A ) ); // phpcs:ignore

		foreach ( $rows as &$row ) {
			$row['payload'] = (array) json_decode( (string) $row['payload'], true );
			$row['result']  = (array) json_decode( (string) $row['result'], true );
			$row['post_title'] = $row['post_id'] ? get_the_title( (int) $row['post_id'] ) : '';
			$row['task_label'] = Tasks::label( (string) $row['type'] );
			$row['ago']       = Helpers::time_ago_fa( strtotime( (string) $row['created_at'] . ' UTC' ) );
			unset( $row['payload']['content'] );
		}
		unset( $row );

		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ) ); // phpcs:ignore

		$stats_rows = (array) $wpdb->get_results( 'SELECT status, COUNT(*) c FROM ' . $table . ' GROUP BY status', ARRAY_A ); // phpcs:ignore
		$stats      = array( 'queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'canceled' => 0 );
		foreach ( $stats_rows as $stat ) {
			$stats[ (string) $stat['status'] ] = (int) $stat['c'];
		}

		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
			'stats' => $stats,
		);
	}

	/**
	 * Log a provider call.
	 *
	 * @param int    $job_id Job id.
	 * @param string $task   Task.
	 * @param array  $result Result.
	 * @param bool   $cached Cached.
	 * @param string $cache_key Cache key.
	 */
	protected static function log_call( $job_id, $task, $result, $cached = false, $cache_key = '' ) {
		global $wpdb;
		if ( ! Database::exists( 'ai_log' ) ) {
			return;
		}
		$config = Providers::config( (string) ( $result['driver'] ?? '' ) );
		$wpdb->insert( // phpcs:ignore
			Database::table( 'ai_log' ),
			array(
				'job_id'           => (int) $job_id,
				'provider'         => mb_substr( sanitize_text_field( (string) ( $result['driver'] ?? '' ) ), 0, 40 ),
				'model'            => substr( sanitize_text_field( (string) ( $result['model'] ?? $config['model'] ?? '' ) ), 0, 80 ),
				'task'             => sanitize_key( (string) $task ),
				'prompt_tokens'    => (int) ( $result['usage']['prompt'] ?? 0 ),
				'completion_tokens' => (int) ( $result['usage']['completion'] ?? 0 ),
				'cost_usd'         => (float) ( $result['cost'] ?? 0 ),
				'latency_ms'       => (int) ( $result['latency'] ?? $result['ms'] ?? 0 ),
				'cached'           => $cached ? 1 : 0,
				'status'           => empty( $result['ok'] ) ? 'error' : 'ok',
				'message'          => mb_substr( (string) ( $result['error'] ?? '' ), 0, 400 ),
				'created_at'       => current_time( 'mysql', true ),
			)
		);
		unset( $cache_key );

		$ttl = (int) \hoosh_seo()->settings->get( 'ai.log_retention', 60 );
		if ( $ttl > 0 && ! wp_next_scheduled( 'hoosh_seo_prune_ai_log' ) ) {
			wp_schedule_single_event( time() + 300, 'hoosh_seo_prune_ai_log' );
		}
	}

	/**
	 * Prune AI log rows.
	 *
	 * @return int
	 */
	public static function prune_log() {
		global $wpdb;
		$days = (int) \hoosh_seo()->settings->get( 'ai.log_retention', 60 );
		if ( $days < 1 || ! Database::exists( 'ai_log' ) ) {
			return 0;
		}
		$deleted = (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM ' . Database::table( 'ai_log' ) . ' WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)', $days )
		); // phpcs:ignore
		if ( $deleted ) {
			Audit::log( 'ai', 'prune', array( 'label' => sprintf( /* translators: %d rows */ __( '%d رکورد گزارش AI پاک شد', 'hoosh-seo' ), $deleted ) ) );
		}
		return $deleted;
	}

	/**
	 * Spend + usage summary.
	 *
	 * @return array
	 */
	public static function spend_summary() {
		global $wpdb;
		$out = Helpers::cache(
			'ai-spend',
			function () use ( $wpdb ) {
				$empty = array(
					'calls' => 0, 'tokens' => 0, 'cost' => 0, 'month_cost' => 0.0, 'today' => 0,
					'by_task' => array(), 'by_provider' => array(), 'series' => array(), 'errors' => 0, 'cached' => 0,
				);
				if ( ! Database::exists( 'ai_log' ) ) {
					return $empty;
				}
				$table = Database::table( 'ai_log' );
				$row   = $wpdb->get_row( // phpcs:ignore
					"SELECT COUNT(*) c, COALESCE(SUM(prompt_tokens + completion_tokens),0) t, COALESCE(SUM(cost_usd),0) cost,
						SUM(status='error') err, SUM(cached=1) cached
					 FROM {$table} WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)",
					ARRAY_A
				);
				$month = (float) $wpdb->get_var( // phpcs:ignore
					"SELECT COALESCE(SUM(cost_usd),0) FROM {$table} WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')"
				);
				$today = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . ' WHERE created_at >= CURDATE()' ); // phpcs:ignore

				$by_task = (array) $wpdb->get_results( // phpcs:ignore
					"SELECT task, COUNT(*) c, COALESCE(SUM(cost_usd),0) cost, COALESCE(SUM(prompt_tokens+completion_tokens),0) tokens
					 FROM {$table} WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) GROUP BY task ORDER BY c DESC LIMIT 20",
					ARRAY_A
				);
				foreach ( $by_task as &$task ) {
					$task['label'] = Tasks::label( (string) $task['task'] );
					$task['c']     = (int) $task['c'];
					$task['cost']  = (float) $task['cost'];
					$task['tokens'] = (int) $task['tokens'];
				}
				unset( $task );

				$by_provider = (array) $wpdb->get_results( 'SELECT provider, COUNT(*) c FROM ' . $table . ' GROUP BY provider ORDER BY c DESC', ARRAY_A ); // phpcs:ignore
				foreach ( $by_provider as &$provider ) {
					$provider['c'] = (int) $provider['c'];
				}
				unset( $provider );

				$series_rows = (array) $wpdb->get_results( // phpcs:ignore
					"SELECT DATE(created_at) d, COUNT(*) c, COALESCE(SUM(cost_usd),0) cost
					 FROM {$table} WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY d ASC",
					ARRAY_A
				);
				$series = array();
				foreach ( $series_rows as $item ) {
					$series[] = array(
						'date'  => (string) $item['d'],
						'label' => Helpers::jalali_date( 'j M', strtotime( (string) $item['d'] ) ),
						'value' => (int) $item['c'],
						'cost'  => (float) $item['cost'],
					);
				}

				return array(
					'calls'       => (int) ( $row['c'] ?? 0 ),
					'tokens'      => (int) ( $row['t'] ?? 0 ),
					'cost'        => (float) ( $row['cost'] ?? 0 ),
					'errors'      => (int) ( $row['err'] ?? 0 ),
					'cached'      => (int) ( $row['cached'] ?? 0 ),
					'month_cost'  => (float) $month,
					'today'       => (int) $today,
					'by_task'     => $by_task,
					'by_provider' => $by_provider,
					'series'      => $series,
					'budget'      => (float) \hoosh_seo()->settings->get( 'ai.budget_usd', 0 ),
				);
			},
			120
		);

		return is_array( $out ) ? $out : array(
			'calls' => 0, 'tokens' => 0, 'cost' => 0, 'month_cost' => 0.0, 'today' => 0,
			'by_task' => array(), 'by_provider' => array(), 'series' => array(), 'errors' => 0, 'cached' => 0, 'budget' => 0,
		);
	}

	/**
	 * Queue overview for widgets/Studio.
	 *
	 * @return array
	 */
	public static function overview() {
		global $wpdb;
		$out = array(
			'configured' => self::is_configured(),
			'driver'     => self::active_driver(),
			'model'      => (string) \hoosh_seo()->settings->get( 'ai.model', '' ),
			'spend'      => self::spend_summary(),
			'queued'     => 0,
			'running'    => 0,
			'failed'     => 0,
			'tasks'      => array(),
		);
		if ( Database::exists( 'ai_jobs' ) ) {
			$rows = (array) $wpdb->get_results( 'SELECT type, status, COUNT(*) c FROM ' . Database::table( 'ai_jobs' ) . ' GROUP BY type, status', ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $row ) {
				$out['tasks'][ (string) $row['type'] ][ (string) $row['status'] ] = (int) $row['c'];
				$key = (string) $row['status'];
				if ( isset( $out[ $key ] ) ) {
					$out[ $key ] += (int) $row['c'];
				}
			}
		}
		foreach ( $out['tasks'] as $type => $counts ) {
			$out['tasks'][ $type ] = array(
				'label'  => Tasks::label( (string) $type ),
				'counts' => $counts,
				'total'  => array_sum( (array) $counts ),
			);
		}
		return $out;
	}

	/**
	 * Standard error payload.
	 *
	 * @param string $message Message.
	 * @param string $code Code.
	 * @return array
	 */
	protected static function error( $message, $code = 'error' ) {
		return array(
			'ok'         => false,
			'text'       => '',
			'json'       => null,
			'error'      => $message,
			'error_type'  => $code,
			'message'    => $message,
			'usage'      => array( 'prompt' => 0, 'completion' => 0 ),
			'cost'       => 0,
			'tokens'     => 0,
			'ms'         => 0,
			'cached'     => false,
			'driver'     => '',
			'model'      => '',
			'hint'       => 'not-configured' === $code ? __( 'تنظیمات → هوش مصنوعی → سرویس و کلید.', 'hoosh-seo' ) : '',
		);
	}
}
