<?php
/**
 * robots.txt (virtual or physical), crawler rules, bot access control, .htaccess helpers.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Admin;
use HooshSEO\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Class Robots
 */
final class Robots {

	/**
	 * Singleton.
	 *
	 * @var Robots|null
	 */
	private static $instance = null;

	/**
	 * AI / SEO bots the plugin can gate.
	 *
	 * @return array
	 */
	public static function bot_catalog() {
		$bots = Helpers::bot_signatures();
		$out  = array();
		foreach ( $bots as $key => $bot ) {
			if ( 'ai' !== $bot['purpose'] ) {
				continue;
			}
			$out[ $key ] = $bot['name'];
		}
		return array(
			'GPTBot'          => 'GPTBot (OpenAI)',
			'ChatGPT-User'    => 'ChatGPT-User (پاسخ زنده)',
			'OAI-SearchBot'   => 'OAI-SearchBot (جستجوی OpenAI)',
			'anthropic-ai'    => 'anthropic-ai (Claude)',
			'ClaudeBot'       => 'ClaudeBot',
			'Claude-User'     => 'Claude-User',
			'PerplexityBot'   => 'PerplexityBot',
			'Perplexity-User' => 'Perplexity-User',
			'Google-Extended' => 'Google-Extended (Gemini/Vertex)',
			'Applebot-Extended' => 'Applebot-Extended',
			'Amazonbot'       => 'Amazonbot',
			'Bytespider'      => 'Bytespider (TikTok)',
			'CCBot'           => 'CCBot (Common Crawl)',
			'FacebookBot'     => 'FacebookBot',
			'Magpie-Crawler'  => 'Magpie-Crawler',
			'DuckAssistBot'   => 'DuckAssistBot',
			'MistralAI-User'  => 'MistralAI-User',
			'cohere-ai'       => 'Cohere (training-data-crawler)',
			'YouBot'          => 'YouBot',
			'Spawn'           => 'Spawn (AI training)',
			'Qwantify'        => 'Qwantify',
			'Omgili'          => 'Omgilibot',
			'GPTCrawler'      => 'GPTCrawler',
		);
	}

	/**
	 * Get instance.
	 *
	 * @return Robots
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
		add_filter( 'robots_txt', array( $this, 'filter_robots' ), 99, 2 );
		add_action( 'do_robots', array( $this, 'log_search_bot' ), 1 );
		add_action( 'admin_post_hoosh_seo_write_robots', array( $this, 'write_physical' ) );
	}

	/**
	 * Full robots.txt body.
	 *
	 * @param string $public Whether the site is public.
	 * @return string
	 */
	public static function content( $public = true ) {
		$settings = hoosh_seo()->settings;
		$lines    = array();

		if ( ! $public || ! get_option( 'blog_public' ) ) {
			return "User-agent: *\nDisallow: /\n";
		}

		$masterbots = (array) $settings->get( 'robots.masterbots', array() );
		foreach ( $masterbots as $bot => $mode ) {
			if ( 'allow' === $mode ) {
				continue;
			}
			$lines[] = 'User-agent: ' . $bot;
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}

		$lines[] = 'User-agent: *';
		foreach ( (array) $settings->get( 'robots.block_dirs', array() ) as $dir ) {
			if ( is_string( $dir ) && '' !== trim( $dir ) ) {
				$lines[] = 'Disallow: ' . trim( $dir );
			}
		}
		foreach ( self::sensitive_paths() as $path ) {
			$lines[] = 'Disallow: ' . $path;
		}
		if ( ! $settings->get( 'index.index_attachments', false ) ) {
			$lines[] = 'Disallow: /?attachment_id=';
		}
		$lines[] = 'Allow: /wp-admin/admin-ajax.php?action=hoosh';

		// AI bots.
		$ai = (array) $settings->get( 'robots.ai_bots', array() );
		foreach ( $ai as $bot => $allow ) {
			if ( (bool) $allow ) {
				continue;
			}
			$lines[] = '';
			$lines[] = 'User-agent: ' . $bot;
			$lines[] = 'Disallow: /';
		}

		$custom = trim( (string) $settings->get( 'robots.custom', '' ) );
		if ( '' !== $custom ) {
			$lines[] = '';
			$lines[] = $custom;
		}

		if ( $settings->get( 'robots.sitemap_line', true ) && $settings->get( 'sitemap.enabled', true ) ) {
			$lines[] = '';
			$lines[] = 'Sitemap: ' . home_url( '/sitemap_index.xml' );
		}

		$lines[] = '';
		$lines[] = '# HooshSEO ' . HOOSH_SEO_VERSION . ' · تولید خودکار — قابل ویرایش از استودیو';
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * Paths worth hiding from crawlers.
	 *
	 * @return array
	 */
	public static function sensitive_paths() {
		$paths = array(
			'/wp-login.php',
			'/wp-register.php',
			'/wp-signup.php',
			'/wp-json/',
			'/*/feed/$',
			'/xmlrpc.php',
			'/readme.html',
			'/license.txt',
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$paths[] = '/cart/';
			$paths[] = '/checkout/';
			$paths[] = '/my-account/';
			$paths[] = '/*/add-to-cart/';
		}
		if ( function_exists( 'edd_get_checkout_uri' ) ) {
			$paths[] = '/checkout/';
		}
		return apply_filters( 'hoosh_seo_robots_sensitive', $paths );
	}

	/**
	 * WP core generates robots.txt; we take over the body.
	 *
	 * @param string $output Existing output.
	 * @param bool   $public Public.
	 * @return string
	 */
	public function filter_robots( $output, $public = true ) {
		unset( $output );
		if ( ! hoosh_seo()->settings->get( 'robots.virtual', true ) ) {
			return '';
		}
		return "\n" . self::content( (bool) $public );
	}

	/**
	 * Serve /robots.txt directly when there is no physical file.
	 */
	public static function serve() {
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo self::content( (bool) get_option( 'blog_public' ) ); // phpcs:ignore
		exit;
	}

	/**
	 * Write a physical robots.txt (some hosts only serve real files).
	 */
	public function write_physical() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'hoosh_seo_robots' );
		self::write_file();
		$back = wp_get_referer() ? wp_get_referer() : Admin::settings_url();
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Write a physical robots.txt and report what happened.
	 *
	 * Some hosts ignore the virtual output and only serve a real file, so the
	 * Studio offers this as an opt-in ("override_file"). Nothing is ever
	 * deleted: turning the option off only stops the plugin from writing.
	 *
	 * @return array {ok, file, bytes, error}
	 */
	public static function write_file() {
		$root = untrailingslashit( ABSPATH );
		$file = $root . '/robots.txt';
		$out  = array(
			'ok'    => false,
			'file'  => $file,
			'bytes' => 0,
			'error' => '',
		);
		if ( ! is_dir( $root ) || ! is_writable( $root ) ) {
			$out['error'] = __( 'پوشهٔ اصلی وردپرس نوشتنی نیست.', 'hoosh-seo' );
			return $out;
		}
		$written = @file_put_contents( $file, self::content( (bool) get_option( 'blog_public' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions, no special chars
		if ( false === $written ) {
			$out['error'] = __( 'نوشتن فایل robots.txt ممکن نشد.', 'hoosh-seo' );
			return $out;
		}
		$out['ok']    = true;
		$out['bytes'] = (int) $written;
		Helpers::cache_flush( 'robots' );
		/**
		 * Fires after the physical robots.txt has been written.
		 */
		do_action( 'hoosh_seo_robots_written', $file, (int) $written );
		return $out;
	}

	/**
	 * Log search/AI bot hits on robots.txt requests.
	 */
	public function log_search_bot() {
		if ( ! hoosh_seo()->settings->get( 'robots.log_bots', true ) ) {
			return;
		}
		$bot = Helpers::detect_bot();
		if ( ! $bot ) {
			return;
		}
		Geo::log_crawl( $bot[0], $bot[1], '/robots.txt', 'robots' );
	}

	/**
	 * Apache block for a directory, offered in the Tools screen.
	 *
	 * @param string $dir Directory path (relative).
	 * @return string
	 */
	public static function htaccess_snippet( $dir ) {
		$dir = trim( (string) $dir, '/' );
		return "# HooshSEO — دسترسی به /{$dir} برای عموم مسدود است\n<IfModule mod_authz_core.c>\n  <LocationMatch \"^/{$dir}/\">\n    Require all denied\n  </LocationMatch>\n</IfModule>\n<IfModule !mod_authz_core.c>\n  <FilesMatch \"^{$dir}\">\n    Order allow,deny\n    Deny from all\n  </FilesMatch>\n</IfModule>\n";
	}

	/**
	 * Nginx equivalent (users on nginx paste it into their server block).
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	public static function nginx_snippet( $dir ) {
		$dir = trim( (string) $dir, '/' );
		return "location ^~ /{$dir}/ {\n    deny all;\n    return 404;\n}\n";
	}

	/**
	 * Whether the virtual robots.txt can be used (no physical file blocking).
	 *
	 * @return array
	 */
	public static function status() {
		$file    = untrailingslashit( ABSPATH ) . '/robots.txt';
		$exists  = file_exists( $file );
		$writable = $exists ? is_writable( $file ) : is_writable( untrailingslashit( ABSPATH ) );
		return array(
			'exists'    => $exists,
			'writable'  => $writable,
			'virtual'   => (bool) hoosh_seo()->settings->get( 'robots.virtual', true ),
			'preview'   => self::content( true ),
			'suggestion' => $exists ? __( 'فایل واقعی robots.txt روی سرور وجود دارد؛ اگر آن را نگه دارید، همین فایل به ربات‌ها نشان داده می‌شود. می‌توانید محتوای بالا را در آن کپی کنید یا فایل را حذف کنید تا نسخه مجازی فعال شود.', 'hoosh-seo' ) : __( 'فایل فیزیکی وجود ندارد؛ نسخه مجازی هوش‌سئو فعال است و نیازی به نوشتن فایل نیست.', 'hoosh-seo' ),
		);
	}

	/**
	 * Simulate robots.txt for a bot + path (Studio tester).
	 *
	 * @param string $bot  Bot name or key.
	 * @param string $path Path to test.
	 * @return array
	 */
	public static function test( $bot, $path ) {
		$bot  = sanitize_text_field( (string) $bot );
		$path = '/' . ltrim( (string) parse_url( (string) $path, PHP_URL_PATH ) ?: '/', '/' );

		$rules = self::parse( (string) self::content() );
		$agent = strtolower( $bot );
		$matched = array();
		$allow   = true;
		$why     = __( 'هیچ قاعده‌ای نگفت؛ پیش‌فرض: مجاز.', 'hoosh-seo' );
		$hit     = '';

		foreach ( $rules as $group ) {
			$agents = (array) ( $group['user-agent'] ?? array() );
			$applicable = false;
			foreach ( $agents as $ua ) {
				$ua = strtolower( trim( (string) $ua ) );
				if ( '*' === $ua || ( $agent && ( false !== strpos( $agent, $ua ) || false !== strpos( $ua, $agent ) ) ) ) {
					$applicable = true;
					$hit        = $ua;
					break;
				}
			}
			if ( ! $applicable ) {
				continue;
			}
			foreach ( (array) ( $group['disallow'] ?? array() ) as $rule ) {
				$rule = trim( (string) $rule );
				if ( '' === $rule ) {
					continue;
				}
				if ( self::path_matches( $path, $rule ) ) {
					$allow = false;
					$why   = sprintf( /* translators: %s rule */ __( 'باDisallow: %s مسدود شد.', 'hoosh-seo' ), $rule );
					$matched[] = array( 'type' => 'disallow', 'rule' => $rule );
				}
			}
			foreach ( (array) ( $group['allow'] ?? array() ) as $rule ) {
				$rule = trim( (string) $rule );
				if ( '' === $rule ) {
					continue;
				}
				if ( self::path_matches( $path, $rule ) ) {
					$allow  = true;
					$why    = sprintf( /* translators: %s rule */ __( 'با Allow: %s آزاد شد.', 'hoosh-seo' ), $rule );
					$matched[] = array( 'type' => 'allow', 'rule' => $rule );
				}
			}
		}

		return array(
			'ok'      => true,
			'bot'     => $bot,
			'agent'   => $hit,
			'path'    => $path,
			'allowed' => $allow,
			'reason'  => $why,
			'rules'   => $matched,
			'state'   => $allow ? 'allowed' : 'blocked',
			'severity' => $allow ? 'ok' : 'warning',
		);
	}

	/**
	 * Tiny glob-ish matcher used by test().
	 *
	 * @param string $path Path.
	 * @param string $rule Rule.
	 * @return bool
	 */
	protected static function path_matches( $path, $rule ) {
		$path = (string) $path;
		$rule = (string) $rule;

		if ( '' === $rule ) {
			return false;
		}
		$ends_dollar = '$' === substr( $rule, -1 );
		$rule        = rtrim( $rule, '$' );

		if ( false !== strpos( $rule, '*' ) ) {
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . ( $ends_dollar ? '$' : '' ) . '#i';
			return (bool) preg_match( $regex, $path );
		}
		if ( $ends_dollar ) {
			return $path === $rule;
		}
		return 0 === strpos( $path, $rule );
	}


	/**
	 * Parse robots.txt text into rule groups.
	 *
	 * @param string $content Robots text.
	 * @return array
	 */
	public static function parse( $content ) {
		$groups = array();
		$index  = -1;

		foreach ( preg_split( '/\r?\n/', (string) $content ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field = strtolower( $field );
			$value = trim( (string) preg_replace( '/\s*#.*$/', '', $value ) );

			if ( 'sitemap' === $field ) {
				$groups[] = array( 'sitemap' => $value );
				$index    = -1;
				continue;
			}

			$open_group = true;
			if ( 'user-agent' !== $field && $index >= 0 ) {
				$open_group = false;
			}
			if ( $open_group ) {
				$groups[] = array(
					'user-agent'   => array(),
					'allow'        => array(),
					'disallow'     => array(),
					'crawl-delay'  => null,
				);
				$index = count( $groups ) - 1;
			}

			if ( 'user-agent' === $field ) {
				$groups[ $index ]['user-agent'][] = $value;
			} elseif ( 'allow' === $field ) {
				$groups[ $index ]['allow'][] = $value;
			} elseif ( 'disallow' === $field ) {
				$groups[ $index ]['disallow'][] = $value;
			} elseif ( 'crawl-delay' === $field ) {
				$groups[ $index ]['crawl-delay'] = (float) $value;
			}
		}

		$groups = array_map(
			function ( $group ) {
				if ( isset( $group['user-agent'] ) && ! $group['user-agent'] && ! $group['allow'] && ! $group['disallow'] ) {
					return array();
				}
				return $group;
			},
			$groups
		);
		unset( $current );
		return array_values( array_filter( $groups ) );
	}

}
