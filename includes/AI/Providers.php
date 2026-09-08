<?php
/**
 * AI providers: catalog + one normalised chat/completions client.
 *
 * @package HooshSEO
 */

namespace HooshSEO\AI;

use HooshSEO\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Class Providers
 */
final class Providers {

	/**
	 * Provider catalog.
	 *
	 * Every entry is an OpenAI-compatible or natively supported chat endpoint;
	 * request()/parse() adapt the differences.
	 *
	 * @return array
	 */
	public static function catalog() {
		return array(
			'openai'      => array(
				'label'      => 'OpenAI',
				'base'       => 'https://api.openai.com/v1',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1-nano', 'o4-mini' ),
				'json'       => true,
				'vision'     => true,
				'price_in'   => 0.15,
				'price_out'  => 0.6,
				'key_url'    => 'https://platform.openai.com/api-keys',
				'note'       => __( 'سازگار با اکثر سرویس‌های ایرانی و پروکسی هم هست.', 'hoosh-seo' ),
			),
			'anthropic'   => array(
				'label'      => 'Anthropic (Claude)',
				'base'       => 'https://api.anthropic.com/v1',
				'path'       => '/messages',
				'auth'       => 'x-api-key',
				'models'     => array( 'claude-3-5-haiku-latest', 'claude-3-5-sonnet-latest', 'claude-sonnet-4' ),
				'json'       => false,
				'vision'     => true,
				'price_in'   => 0.8,
				'price_out'  => 4,
				'key_url'    => 'https://console.anthropic.com/settings/keys',
			),
			'google'      => array(
				'label'      => 'Google Gemini',
				'base'       => 'https://generativelanguage.googleapis.com/v1beta',
				'path'       => '/models/{model}:generateContent',
				'auth'       => 'query',
				'models'     => array( 'gemini-2.0-flash', 'gemini-2.5-flash', 'gemini-1.5-flash' ),
				'json'       => true,
				'vision'     => true,
				'price_in'   => 0.1,
				'price_out'  => 0.4,
				'key_url'    => 'https://aistudio.google.com/app/apikey',
				'note'       => __( 'رایگانِ ماهانه‌اش برای سایت‌های کوچک کافیست.', 'hoosh-seo' ),
			),
			'mistral'     => array(
				'label'      => 'Mistral',
				'base'       => 'https://api.mistral.ai/v1',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array( 'mistral-small-latest', 'open-mixtral-8x22b', 'mistral-large-latest' ),
				'json'       => true,
				'vision'     => true,
				'price_in'   => 0.1,
				'price_out'  => 0.3,
				'key_url'    => 'https://console.mistral.ai/api-keys/',
			),
			'deepseek'    => array(
				'label'      => 'DeepSeek',
				'base'       => 'https://api.deepseek.com/v1',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array( 'deepseek-chat', 'deepseek-reasoner' ),
				'json'       => true,
				'vision'     => false,
				'price_in'   => 0.07,
				'price_out'  => 0.1,
				'key_url'    => 'https://platform.deepseek.com/api_keys',
				'note'       => __( 'ارزان‌ترین گزینه قابل قبول برای فارسی.', 'hoosh-seo' ),
			),
			'groq'        => array(
				'label'      => 'Groq (سریع)',
				'base'       => 'https://api.groq.com/openai/v1',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array( 'llama-3.3-70b-versatile', 'gemma2-9b-it', 'llama-3.1-8b-instant' ),
				'json'       => true,
				'vision'     => false,
				'price_in'   => 0.05,
				'price_out'  => 0.08,
				'key_url'    => 'https://console.groq.com/keys',
			),
			'together'    => array(
				'label'      => 'Together AI',
				'base'       => 'https://api.together.xyz/v1',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array( 'meta-llama/Llama-3.3-70B-Instruct-Turbo', 'Qwen/Qwen2.5-72B-Instruct-Turbo', 'deepseek-ai/DeepSeek-V3' ),
				'json'       => true,
				'vision'     => true,
				'price_in'   => 0.2,
				'price_out'  => 0.4,
				'key_url'    => 'https://api.together.xyz/settings/api-keys',
			),
			'openrouter'  => array(
				'label'      => 'OpenRouter',
				'base'       => 'https://openrouter.ai/api/v1',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array( 'openrouter/auto', 'anthropic/claude-3.5-haiku', 'deepseek/deepseek-chat', 'mistralai/mistral-nemo' ),
				'json'       => true,
				'vision'     => true,
				'price_in'   => 0.2,
				'price_out'  => 0.6,
				'key_url'    => 'https://openrouter.ai/settings/keys',
				'note'       => __( 'یک کلید، صدها مدل؛ برای تست مناسب است.', 'hoosh-seo' ),
			),
			'xai'         => array(
				'label'      => 'xAI Grok',
				'base'       => 'https://api.x.ai/v1',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array( 'grok-2-latest', 'grok-2-vision-1212' ),
				'json'       => true,
				'vision'     => true,
				'price_in'   => 0.2,
				'price_out'  => 0.5,
			),
			'cohere'      => array(
				'label'      => 'Cohere',
				'base'       => 'https://api.cohere.com/v2',
				'path'       => '/chat',
				'auth'       => 'bearer',
				'models'     => array( 'command-r-plus', 'command-r', 'north-mini' ),
				'json'       => false,
				'vision'     => false,
				'price_in'   => 0.15,
				'price_out'  => 0.6,
			),
			'smartapi'    => array(
				'label'      => __( 'اسمارت‌ای‌پی‌آی (ایران)', 'hoosh-seo' ),
				'base'       => 'https://api.smartapi.ir/v1',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array( 'gpt-4o-mini', 'gpt-4o', 'gemini-2.0-flash', 'deepseek-chat', 'llama3.1-8b-instruct-fa' ),
				'json'       => true,
				'vision'     => true,
				'price_in'   => 0,
				'price_out'  => 0,
				'key_url'    => 'https://panel.smartapi.ir/',
				'note'       => __( 'پرداخت ریالی و بدون نیاز به تحریم‌شکن.', 'hoosh-seo' ),
			),
			'sorena'      => array(
				'label'      => __( 'سورنا (ایران)', 'hoosh-seo' ),
				'base'       => 'https://token.sorena.ai/v1',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array( 'sorena-llama3.1-8b-instruct', 'sorena-mistral-nemo' ),
				'json'       => false,
				'vision'     => false,
				'price_in'   => 0,
				'price_out'  => 0,
				'key_url'    => 'https://sorena.ai/',
				'note'       => __( 'مدل فارسی؛ برای بازنویسی متن‌های کوتاه.', 'hoosh-seo' ),
			),
			'ollama'      => array(
				'label'      => __( 'Ollama (لوکال)', 'hoosh-seo' ),
				'base'       => 'http://127.0.0.1:11434',
				'path'       => '/api/chat',
				'auth'       => 'none',
				'models'     => array( 'llama3.1:8b', 'qwen2.5:7b', 'mistral:7b', 'parandrus-fa:7b' ),
				'json'       => true,
				'vision'     => false,
				'price_in'   => 0,
				'price_out'  => 0,
				'note'       => __( 'رایگان و درون‌سازمانی؛ سرور باید به Ollama دسترسی داشته باشد.', 'hoosh-seo' ),
			),
			'lmstudio'    => array(
				'label'      => __( 'LM Studio (لوکال)', 'hoosh-seo' ),
				'base'       => 'http://127.0.0.1:1234/v1',
				'path'       => '/chat/completions',
				'auth'       => 'none',
				'models'     => array( 'local-model' ),
				'json'       => true,
				'vision'     => false,
				'price_in'   => 0,
				'price_out'  => 0,
			),
			'custom'      => array(
				'label'      => __( 'سرور سازگار با OpenAI', 'hoosh-seo' ),
				'base'       => '',
				'path'       => '/chat/completions',
				'auth'       => 'bearer',
				'models'     => array(),
				'json'       => true,
				'vision'     => false,
				'price_in'   => 0,
				'price_out'   => 0,
				'note'       => __( 'هر آدرسی که /chat/completions دارد؛ مثل vLLM، LiteLLM یا پروکسی داخلی.', 'hoosh-seo' ),
			),
		);
	}

	/**
	 * Merged configuration for a driver.
	 *
	 * @param string $driver Driver key.
	 * @return array
	 */
	public static function config( $driver = '' ) {
		$settings  = \hoosh_seo()->settings;
		$catalog   = self::catalog();
		$driver    = $driver ? sanitize_key( $driver ) : (string) $settings->get( 'ai.driver', 'openai' );
		$defaults  = isset( $catalog[ $driver ] ) ? $catalog[ $driver ] : $catalog['openai'];
		$overrides = (array) $settings->get( 'ai.providers', array() );
		$mine      = isset( $overrides[ $driver ] ) ? (array) $overrides[ $driver ] : array();

		$config = array_merge(
			array(
				'driver'  => $driver,
				'base'    => $defaults['base'],
				'model'   => (string) $settings->get( 'ai.model', $catalog[ $driver ]['models'][0] ?? '' ),
				'key'     => self::key( $driver ),
				'timeout' => (int) $settings->get( 'ai.timeout', 45 ),
				'retries' => (int) $settings->get( 'ai.retries', 2 ),
				'json'    => (bool) $settings->get( 'ai.json_mode', true ),
			),
			$defaults,
			$mine
		);

		if ( 'custom' === $driver && ! $config['base'] ) {
			$config['base'] = (string) $settings->get( 'ai.custom_base', '' );
		}
		if ( 'custom' === $driver && ! $config['model'] ) {
			$config['model'] = (string) $settings->get( 'ai.custom_model', '' );
		}
		if ( 'ollama' === $driver && $settings->get( 'ai.self_host.ollama_url' ) ) {
			$config['base'] = rtrim( (string) $settings->get( 'ai.self_host.ollama_url' ), '/' );
		}
		if ( 'lmstudio' === $driver && $settings->get( 'ai.self_host.lmstudio_url' ) ) {
			$config['base'] = rtrim( (string) $settings->get( 'ai.self_host.lmstudio_url' ), '/' );
		}

		return $config;
	}

	/**
	 * Drivers with a usable key (or local endpoint).
	 *
	 * @return array
	 */
	public static function ready() {
		$out = array();
		foreach ( self::catalog() as $driver => $meta ) {
			$key   = self::key( $driver );
			$ready = in_array( $driver, array( 'ollama', 'lmstudio' ), true ) || '' !== $key;
			$out[ $driver ] = array(
				'label'  => $meta['label'],
				'ready'  => $ready,
				'masked' => $key ? Helpers::mask_key( $key ) : '',
				'models' => $meta['models'],
			);
		}
		return $out;
	}

	/**
	 * API keys live outside the settings option so exports never carry secrets.
	 *
	 * @param string $driver Driver.
	 * @return string
	 */
	public static function key( $driver ) {
		$driver = sanitize_key( (string) $driver );
		$keys   = (array) get_option( 'hoosh_ai_keys', array() );
		if ( isset( $keys[ $driver ] ) && '' !== (string) $keys[ $driver ] ) {
			return (string) $keys[ $driver ];
		}
		// Legacy/fallback: a per-provider entry inside the settings tree.
		$overrides = (array) \hoosh_seo()->settings->get( 'ai.providers', array() );
		if ( isset( $overrides[ $driver ]['api_key'] ) ) {
			return (string) $overrides[ $driver ]['api_key'];
		}
		return '';
	}

	/**
	 * Store one or more keys.
	 *
	 * @param array $pairs driver => key (empty string deletes).
	 * @return array
	 */
	public static function set_keys( $pairs ) {
		$keys = (array) get_option( 'hoosh_ai_keys', array() );
		foreach ( (array) $pairs as $driver => $value ) {
			$driver = sanitize_key( $driver );
			$value  = trim( (string) $value );
			if ( '' === $value ) {
				unset( $keys[ $driver ] );
				continue;
			}
			$keys[ $driver ] = preg_replace( '/\s+/', '', $value );
		}
		update_option( 'hoosh_ai_keys', $keys, false );
		$out = array();
		foreach ( $keys as $driver => $value ) {
			$out[ $driver ] = Helpers::mask_key( (string) $value );
		}
		return $out;
	}

	/**
	 * Masked key map for the Studio.
	 *
	 * @return array
	 */
	public static function keys_masked() {
		$out = array();
		foreach ( (array) get_option( 'hoosh_ai_keys', array() ) as $driver => $value ) {
			$out[ (string) $driver ] = Helpers::mask_key( (string) $value );
		}
		return $out;
	}

	/**
	 * One chat call. Returns a normalised payload.
	 *
	 * @param array  $messages [{role, content}].
	 * @param array  $opts     {driver, model, temperature, max_tokens, json, image, user}.
	 * @return array
	 */
	public static function request( $messages, $opts = array() ) {
		$settings = \hoosh_seo()->settings;
		$opts = wp_parse_args(
			(array) $opts,
			array(
				'driver'      => '',
				'model'       => '',
				'temperature' => (float) $settings->get( 'ai.temperature', 0.4 ),
				'max_tokens'  => (int) $settings->get( 'ai.max_tokens', 1200 ),
				'top_p'       => (float) $settings->get( 'ai.top_p', 1 ),
				'json'        => null,
				'image'       => '',
				'task'        => 'chat',
				'job_id'      => 0,
			)
		);

		$config = self::config( (string) $opts['driver'] );
		$model  = $opts['model'] ? (string) $opts['model'] : (string) $config['model'];
		if ( ! $model ) {
			return self::failure( 'model', __( 'مدل انتخاب نشده است.', 'hoosh-seo' ), $config );
		}
		if ( 'none' !== $config['auth'] && '' === (string) $config['key'] ) {
			return self::failure( 'key', __( 'کلید API این سرویس خالی است.', 'hoosh-seo' ), $config );
		}

		$body    = self::body( $config, $model, $messages, $opts );
		$headers = self::headers( $config );
		$url     = self::endpoint( $config, $model );

		if ( is_wp_error( $url ) ) {
			return self::failure( 'url', $url->get_error_message(), $config );
		}

		$attempts = 0;
		$result   = null;
		do {
			$attempts++;
			$started = microtime( true );
			$raw     = Helpers::http(
				$url,
				array(
					'method'  => 'POST',
					'headers' => $headers,
					'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'timeout' => (int) $config['timeout'],
				)
			);
			$latency = (int) round( ( microtime( true ) - $started ) * 1000 );
			$result  = self::normalize( $config, $raw, $model, (string) $opts['task'] );
			$result['latency'] = $latency;

			if ( $result['ok'] || $attempts > (int) $config['retries'] || ! in_array( (int) $raw['code'], array( 429, 500, 502, 503, 504, 0 ), true ) ) {
				break;
			}
			usleep( min( 8000000, 600000 * $attempts * $attempts ) );
		} while ( $attempts <= (int) $config['retries'] );

		$result['attempts'] = $attempts;
		$result['driver']   = $config['driver'];
		$result['model']    = $model;
		$result['job_id']   = (int) $opts['job_id'];
		$result['task']     = (string) $opts['task'];
		unset( $settings );

		return $result;
	}

	/**
	 * Endpoint URL for a driver.
	 *
	 * @param array  $config Config.
	 * @param string $model  Model.
	 * @return string|\WP_Error
	 */
	protected static function endpoint( $config, $model ) {
		$base = rtrim( (string) $config['base'], '/' );
		if ( ! $base ) {
			return new \WP_Error( 'hs-no-base', __( 'آدرس پایه سرویس تنظیم نشده است.', 'hoosh-seo' ) );
		}
		if ( 'custom' === $config['driver'] && false === strpos( $base, '/v1' ) && false === strpos( $base, 'chat/completions' ) ) {
			$base .= '/v1';
		}

		$path = (string) $config['path'];
		if ( false !== strpos( $path, '{model}' ) ) {
			$path = str_replace( '{model}', rawurlencode( $model ), $path );
		}
		$url = $base . $path;

		if ( 'query' === $config['auth'] ) {
			$url = add_query_arg( 'key', rawurlencode( (string) $config['key'] ), $url );
		}
		return $url;
	}

	/**
	 * Auth headers.
	 *
	 * @param array $config Config.
	 * @return array
	 */
	protected static function headers( $config ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
			'User-Agent'   => 'HooshSEO/' . HOOSH_SEO_VERSION . ' (+https://hooshseo.com)',
		);
		switch ( $config['auth'] ) {
			case 'bearer':
				$headers['Authorization'] = 'Bearer ' . $config['key'];
				break;
			case 'x-api-key':
				$headers['x-api-key']        = (string) $config['key'];
				$headers['anthropic-version'] = '2023-06-01';
				break;
			case 'query':
				break;
		}
		if ( 'openrouter' === $config['driver'] ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = get_bloginfo( 'name' );
		}
		return $headers;
	}

	/**
	 * Build the driver-specific request body.
	 *
	 * @param array  $config   Config.
	 * @param string $model    Model.
	 * @param array  $messages Messages.
	 * @param array  $opts     Options.
	 * @return array
	 */
	protected static function body( $config, $model, $messages, $opts ) {
		$temperature = max( 0, min( 2, (float) $opts['temperature'] ) );
		$max_tokens  = max( 64, (int) $opts['max_tokens'] );
		$json        = null === $opts['json'] ? (bool) $config['json'] : (bool) $opts['json'];

		switch ( $config['driver'] ) {
			case 'anthropic':
				$system = '';
				$chat   = array();
				foreach ( (array) $messages as $message ) {
					if ( 'system' === ( $message['role'] ?? '' ) ) {
						$system .= (string) $message['content'] . "\n";
						continue;
					}
					$chat[] = array(
						'role'    => 'assistant' === ( $message['role'] ?? '' ) ? 'assistant' : 'user',
						'content' => (string) ( $message['content'] ?? '' ),
					);
				}
				$body = array(
					'model'       => $model,
					'messages'    => $chat ?: $messages,
					'max_tokens'  => $max_tokens,
					'temperature' => $temperature,
				);
				if ( $system ) {
					$body['system'] = trim( $system );
				}
				return $body;

			case 'google':
				$contents = array();
				$system   = '';
				foreach ( (array) $messages as $message ) {
					if ( 'system' === ( $message['role'] ?? '' ) ) {
						$system .= (string) $message['content'] . "\n";
						continue;
					}
					$parts = array( array( 'text' => (string) ( $message['content'] ?? '' ) ) );
					if ( ! empty( $opts['image'] ) && 'user' === ( $message['role'] ?? '' ) ) {
						$parts[] = array( 'inline_data' => array( 'mime_type' => 'image/jpeg', 'data' => $opts['image'] ) );
						$opts['image'] = '';
					}
					$contents[] = array(
						'role'  => 'assistant' === ( $message['role'] ?? '' ) ? 'model' : 'user',
						'parts' => $parts,
					);
				}
				$body = array(
					'contents'          => $contents,
					'generationConfig'  => array(
						'temperature'     => $temperature,
						'maxOutputTokens' => $max_tokens,
						'topP'            => (float) $opts['top_p'],
					),
				);
				if ( $system ) {
					$body['systemInstruction'] = array( 'parts' => array( array( 'text' => trim( $system ) ) ) );
				}
				if ( $json ) {
					$body['generationConfig']['responseMimeType'] = 'application/json';
				}
				return $body;

			case 'cohere':
				$body = array(
					'model'       => $model,
					'message'     => '',
					'chat_history' => array(),
					'temperature' => $temperature,
					'max_tokens'  => $max_tokens,
					'preamble'    => '',
				);
				foreach ( (array) $messages as $message ) {
					if ( 'system' === ( $message['role'] ?? '' ) ) {
						$body['preamble'] .= (string) $message['content'] . "\n";
					} elseif ( 'assistant' === ( $message['role'] ?? '' ) ) {
						$body['chat_history'][] = array( 'role' => 'CHATBOT', 'message' => (string) $message['content'] );
					} else {
						$body['message'] = (string) $message['content'];
					}
				}
				$body['preamble'] = trim( $body['preamble'] );
				if ( ! $body['preamble'] ) {
					unset( $body['preamble'] );
				}
				if ( ! $body['chat_history'] ) {
					unset( $body['chat_history'] );
				}
				return $body;

			case 'ollama':
				$body = array(
					'model'    => $model,
					'messages' => array_values( (array) $messages ),
					'stream'   => false,
					'options'  => array(
						'temperature' => $temperature,
						'num_predict' => $max_tokens,
						'top_p'       => (float) $opts['top_p'],
					),
				);
				if ( $json ) {
					$body['format'] = 'json';
				}
				if ( ! empty( $opts['image'] ) ) {
					$body['images'] = array( (string) $opts['image'] );
				}
				return $body;

			default: // OpenAI-compatible.
				$body = array(
					'model'       => $model,
					'messages'    => self::openai_messages( $messages, $opts ),
					'temperature' => $temperature,
					'top_p'       => (float) $opts['top_p'],
					'stream'      => false,
				);
				if ( false !== strpos( $model, 'o1' ) || false !== strpos( $model, 'o3' ) || false !== strpos( $model, 'o4' ) ) {
					unset( $body['temperature'], $body['top_p'] );
				}
				$body[ ( 0 === strpos( $model, 'o' ) ? 'max_completion_tokens' : 'max_tokens' ) ] = $max_tokens;
				if ( $json ) {
					$body['response_format'] = array( 'type' => 'json_object' );
				}
				$body = apply_filters( 'hoosh_seo_ai_request_body', $body, $config, $opts );
				return $body;
		}
	}

	/**
	 * OpenAI-style message list, with optional vision part.
	 *
	 * @param array $messages Messages.
	 * @param array $opts     Opts.
	 * @return array
	 */
	protected static function openai_messages( $messages, $opts ) {
		$out = array();
		foreach ( (array) $messages as $message ) {
			$role = (string) ( $message['role'] ?? 'user' );
			$text = (string) ( $message['content'] ?? '' );
			if ( 'user' === $role && ! empty( $opts['image'] ) ) {
				$out[] = array(
					'role'    => 'user',
					'content' => array(
						array( 'type' => 'text', 'text' => $text ),
						array( 'type' => 'image_url', 'image_url' => array( 'url' => (string) $opts['image'] ) ),
					),
				);
				$opts['image'] = '';
				continue;
			}
			$out[] = array( 'role' => $role, 'content' => $text );
		}
		return $out;
	}

	/**
	 * Turn any provider response into {ok, text, usage, error, code}.
	 *
	 * @param array  $config Config.
	 * @param array  $raw    HTTP result from Helpers::http.
	 * @param string $model  Model.
	 * @param string $task   Task.
	 * @return array
	 */
	protected static function normalize( $config, $raw, $model, $task ) {
		unset( $model, $task );
		$out = array(
			'ok'      => false,
			'text'    => '',
			'usage'   => array( 'prompt' => 0, 'completion' => 0 ),
			'code'    => (int) ( $raw['code'] ?? 0 ),
			'error'   => '',
			'error_type' => '',
			'raw'     => array(),
			'json'    => null,
		);

		if ( ! empty( $raw['error'] ) ) {
			$out['error']      = (string) $raw['error'];
			$out['error_type'] = 'transport';
			return $out;
		}

		$data = json_decode( (string) $raw['body'], true );
		if ( ! is_array( $data ) ) {
			$out['error']      = __( 'پاسخ سرویس JSON نبود.', 'hoosh-seo' ) . ' ' . mb_substr( wp_strip_all_tags( (string) $raw['body'] ), 0, 160 );
			$out['error_type'] = ( $out['code'] >= 400 ) ? 'http' : 'parse';
			return $out;
		}

		$out['raw'] = array_intersect_key( $data, array_flip( array( 'id', 'model', 'created', 'done', 'finish_reason' ) ) );

		if ( ! empty( $data['error'] ) ) {
			$out['error'] = is_array( $data['error'] )
				? (string) ( $data['error']['message'] ?? wp_json_encode( $data['error'] ) )
				: (string) $data['error'];
			$out['error_type'] = 'provider';
			return $out;
		}
		if ( isset( $data['message'] ) && ! isset( $data['choices'] ) && ! isset( $data['content'] ) ) {
			$out['error']      = (string) $data['message'];
			$out['error_type'] = 'provider';
			return $out;
		}

		$text = '';
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			$text = (string) $data['choices'][0]['message']['content'];
		} elseif ( isset( $data['choices'][0]['text'] ) ) {
			$text = (string) $data['choices'][0]['text'];
		} elseif ( isset( $data['message']['content'] ) ) { // ollama
			$text = (string) $data['message']['content'];
		} elseif ( isset( $data['content'][0]['text'] ) ) { // anthropic
			$parts = array();
			foreach ( (array) $data['content'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$parts[] = (string) $part['text'];
				}
			}
			$text = implode( "\n", $parts );
		} elseif ( isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) { // gemini
			$text = (string) $data['candidates'][0]['content']['parts'][0]['text'];
		} elseif ( isset( $data['text'] ) ) { // cohere v1
			$text = (string) $data['text'];
		} elseif ( isset( $data['generations'][0]['text'] ) ) { // cohere v2
			$text = (string) $data['generations'][0]['text'];
		} elseif ( isset( $data['response'] ) && is_string( $data['response'] ) ) { // cohere v2 chat
			$text = (string) $data['response'];
		}

		$usage = array( 'prompt' => 0, 'completion' => 0 );
		if ( isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
			$usage['prompt']     = (int) ( $data['usage']['prompt_tokens'] ?? $data['usage']['input_tokens'] ?? 0 );
			$usage['completion'] = (int) ( $data['usage']['completion_tokens'] ?? $data['usage']['output_tokens'] ?? 0 );
			if ( ! $usage['prompt'] && ! $usage['completion'] && isset( $data['usage']['total_tokens'] ) ) {
				$usage['prompt'] = (int) $data['usage']['total_tokens'];
			}
		} elseif ( isset( $data['usage_metadata'] ) ) {
			$usage['prompt']     = (int) ( $data['usage_metadata']['prompt_tokens'] ?? 0 );
			$usage['completion'] = (int) ( $data['usage_metadata']['total_tokens'] ?? 0 );
		} elseif ( isset( $data['eval_count'] ) ) { // gemini
			$usage['completion'] = (int) $data['eval_count'];
			$usage['prompt']     = (int) ( $data['prompt_token_count'] ?? ( $data['usageMetadata']['promptTokenCount'] ?? 0 ) );
		}
		if ( ! $usage['prompt'] && isset( $data['usageMetadata']['totalTokenCount'] ) ) {
			$usage['prompt'] = (int) $data['usageMetadata']['promptTokenCount'];
		}

		if ( '' === trim( $text ) ) {
			$out['error']      = __( 'پاسخ خالی بود (احتمال فیلتر محتوایی).', 'hoosh-seo' );
			$out['error_type'] = 'empty';
			return $out;
		}

		$out['ok']    = true;
		$out['text']  = trim( $text );
		$out['usage'] = $usage;
		if ( isset( $data['error_code'] ) ) {
			$out['error'] = (string) $data['error_code'];
		}
		unset( $config );

		return $out;
	}

	/**
	 * Failure payload.
	 *
	 * @param string $type Type.
	 * @param string $message Message.
	 * @param array  $config Config.
	 * @return array
	 */
	protected static function failure( $type, $message, $config = array() ) {
		return array(
			'ok'        => false,
			'text'      => '',
			'usage'     => array( 'prompt' => 0, 'completion' => 0 ),
			'code'      => 0,
			'error'     => $message,
			'error_type' => $type,
			'raw'       => array(),
			'json'      => null,
			'driver'    => isset( $config['driver'] ) ? $config['driver'] : '',
			'latency'   => 0,
			'attempts'  => 0,
		);
	}

	/**
	 * Extract JSON out of a model reply (fenced, prefixed, or clean).
	 *
	 * @param string $text Text.
	 * @return array|null
	 */
	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		if ( preg_match( '/```(?:json)?\s*(.*?)```/is', $text, $m ) ) {
			$text = trim( $m[1] );
		}
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		foreach ( array( '{', '[' ) as $open ) {
			$close = '{' === $open ? '}' : ']';
			$start = strpos( $text, $open );
			if ( false === $start ) {
				continue;
			}
			$end = strrpos( $text, $close );
			if ( false === $end || $end < $start ) {
				continue;
			}
			$candidate = substr( $text, $start, $end - $start + 1 );
			$decoded   = json_decode( $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
			// Repair common model slips: trailing commas, smart quotes.
			$fixed = preg_replace( '/,\s*([\]}])/u', '$1', $candidate );
			$fixed = str_replace( array( '“', '”', '«', '»' ), array( '"', '"', '"', '"' ), (string) $fixed );
			$decoded = json_decode( (string) $fixed, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * Token estimate without a tokenizer: ~3.6 chars/token for Latin, ~2 for Persian.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function estimate_tokens( $text ) {
		$text = (string) $text;
		$persian = preg_match_all( '/[\x{0600}-\x{06FF}]/u', $text );
		$other   = max( 0, mb_strlen( $text ) - (int) $persian );
		return (int) ceil( ( (int) $persian * 2 ) + ( $other / 3.6 ) ) ?: 1;
	}

	/**
	 * Cost estimate for a token pair.
	 *
	 * @param array $config Config.
	 * @param int   $prompt Prompt tokens.
	 * @param int   $completion Completion tokens.
	 * @return float
	 */
	public static function cost( $config, $prompt, $completion ) {
		$in  = isset( $config['price_in'] ) ? (float) $config['price_in'] : 0;
		$out = isset( $config['price_out'] ) ? (float) $config['price_out'] : 0;
		return round( ( ( $prompt / 1000 ) * $in ) + ( ( $completion / 1000 ) * $out ), 6 );
	}

	/**
	 * Connection test used by the Studio.
	 *
	 * @param string $driver Driver.
	 * @return array
	 */
	public static function test( $driver = '' ) {
		$config = self::config( $driver );
		$start  = microtime( true );
		$result = self::request(
			array(
				array( 'role' => 'system', 'content' => 'You answer with exactly one word.' ),
				array( 'role' => 'user', 'content' => 'Say: hoosh' ),
			),
			array(
				'driver'      => $config['driver'],
				'max_tokens'  => 16,
				'json'        => false,
				'temperature' => 0,
				'task'        => 'test',
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( ! $result['ok'] ) {
			return array(
				'ok'      => false,
				'message' => $result['error'],
				'driver'  => $config['driver'],
				'model'   => $config['model'],
				'ms'      => $ms,
				'hint'    => self::hint( $result ),
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: driver, 2: ms */
				__( 'اتصال %1$s سالم است (پاسخ در %2$d میلی‌ثانیه).', 'hoosh-seo' ),
				$config['label'],
				$ms
			),
			'driver'  => $config['driver'],
			'model'   => $config['model'],
			'answer'  => mb_substr( (string) $result['text'], 0, 40 ),
			'ms'      => $ms,
		);
	}

	/**
	 * Human hint for the most common failures.
	 *
	 * @param array $result Result.
	 * @return string
	 */
	public static function hint( $result ) {
		$error = strtolower( (string) ( $result['error'] ?? '' ) );
		$type  = (string) ( $result['error_type'] ?? '' );

		if ( 'key' === $type ) {
			return __( 'در تنظیمات، بخش هوش مصنوعی، کلید این سرویس را ذخیره کنید.', 'hoosh-seo' );
		}
		if ( false !== strpos( $error, '401' ) || false !== strpos( $error, 'unauthorized' ) || false !== strpos( $error, 'invalid_api_key' ) ) {
			return __( 'کلید نامعتبر یا منقضی است.', 'hoosh-seo' );
		}
		if ( false !== strpos( $error, '429' ) || false !== strpos( $error, 'rate' ) ) {
			return __( 'سقف درخواست زوده؛ تعداد درخواست دسته‌ای را کم کنید.', 'hoosh-seo' );
		}
		if ( false !== strpos( $error, 'insufficient' ) || false !== strpos( $error, 'billing' ) || false !== strpos( $error, 'credit' ) ) {
			return __( 'موجودی حساب تمام شده است.', 'hoosh-seo' );
		}
		if ( 'transport' === $type || false !== strpos( $error, 'timed out' ) || false !== strpos( $error, 'cURL' ) ) {
			return __( 'سرور به این آدرس دسترسی ندارد؛ فایروال میزبان یا پروکسی را بررسی کنید.', 'hoosh-seo' );
		}
		if ( false !== strpos( $error, 'model' ) ) {
			return __( 'نام مدل را دقیق از فهرست سرویس کپی کنید.', 'hoosh-seo' );
		}
		return __( 'پاسخ کامل سرویس را در «گزارش درخواست‌ها» ببینید.', 'hoosh-seo' );
	}
}
