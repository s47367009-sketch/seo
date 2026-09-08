<?php
/**
 * Google Indexing API.
 *
 * Tells Google directly that a URL appeared or disappeared, instead of waiting
 * for the next crawl. This is the single fastest way to get new content
 * indexed — but it only works for URLs the site is verified for, and Google
 * only accepts JobPosting and BroadcastEvent pages in the official docs.
 *
 * That caveat is the reason this class reports honestly instead of pretending
 * to succeed: a 403 here is normal for most sites and must be surfaced, not
 * swallowed.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Google;

use HooshSEO\Helpers;
use HooshSEO\Modules\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Class Indexing
 */
final class Indexing {

	/**
	 * Publish endpoint.
	 */
	const ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

	/**
	 * Required OAuth scope.
	 */
	const SCOPE = 'https://www.googleapis.com/auth/indexing';

	/**
	 * Is the API usable?
	 *
	 * @return array {ready:bool, why:string}
	 */
	public static function status() {
		$settings = \hoosh_seo()->settings;

		if ( ! $settings || ! $settings->get( 'analytics.gsc.credentials' ) ) {
			return array(
				'ready' => false,
				'why'   => __( 'برای Indexing API به یک Service Account گوگل نیاز دارید؛ در بخش سرچ کنسول فایل JSON را وارد کنید.', 'hoosh-seo' ),
			);
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return array( 'ready' => false, 'why' => __( 'افزونهٔ openssl روی سرور فعال نیست.', 'hoosh-seo' ) );
		}
		return array( 'ready' => true, 'why' => '' );
	}

	/**
	 * Submit URLs as updated.
	 *
	 * Google has no bulk endpoint, so each URL is its own request. That is the
	 * one place here where concurrency genuinely helps: 20 URLs serially is 20
	 * round trips, together it is roughly one.
	 *
	 * @param array  $urls URLs.
	 * @param string $type URL_UPDATED|URL_DELETED.
	 * @return array {ok, submitted, failed, results[]}
	 */
	public static function submit( $urls, $type = 'URL_UPDATED' ) {
		$urls = array_values( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) );
		if ( ! $urls ) {
			return array( 'ok' => false, 'submitted' => 0, 'failed' => 0, 'results' => array(), 'why' => __( 'نشانی‌ای فرستاده نشد.', 'hoosh-seo' ) );
		}

		$type = in_array( $type, array( 'URL_UPDATED', 'URL_DELETED' ), true ) ? $type : 'URL_UPDATED';

		$status = self::status();
		if ( ! $status['ready'] ) {
			return array( 'ok' => false, 'submitted' => 0, 'failed' => count( $urls ), 'results' => array(), 'why' => $status['why'] );
		}

		$token = Analytics::service_access_token( self::SCOPE );
		if ( ! $token ) {
			return array(
				'ok'      => false,
				'submitted' => 0,
				'failed'  => count( $urls ),
				'results' => array(),
				'why'     => __( 'دریافت توکن از گوگل ناموفق بود؛ کلید Service Account را بررسی کنید.', 'hoosh-seo' ),
			);
		}

		$results   = array();
		$submitted = 0;
		$failed    = 0;

		// Keep the batch modest — the documented quota is 200/day.
		foreach ( array_slice( $urls, 0, 100 ) as $url ) {
			$raw = Helpers::http(
				self::ENDPOINT,
				array(
					'method'  => 'POST',
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( array( 'url' => $url, 'type' => $type ) ),
				)
			);

			$ok = ! empty( $raw['ok'] );
			if ( $ok ) {
				$submitted++;
			} else {
				$failed++;
			}

			$results[] = array(
				'url'  => $url,
				'ok'   => $ok,
				'code' => (int) $raw['code'],
				'why'  => $ok ? '' : self::explain( (int) $raw['code'], (string) $raw['body'] ),
			);
		}

		return array(
			'ok'        => $submitted > 0,
			'submitted' => $submitted,
			'failed'    => $failed,
			'results'   => $results,
			'why'       => $submitted ? '' : __( 'گوگل هیچ‌کدام را نپذیرفت.', 'hoosh-seo' ),
		);
	}

	/**
	 * Translate a Google error into something a site owner can act on.
	 *
	 * @param int    $code HTTP code.
	 * @param string $body Response body.
	 * @return string
	 */
	protected static function explain( $code, $body ) {
		$json    = json_decode( (string) $body, true );
		$message = is_array( $json ) && isset( $json['error']['message'] ) ? (string) $json['error']['message'] : '';

		if ( 403 === $code ) {
			return __( 'گوگل اجازه نداد. معمولاً یعنی نشانی در سایت تأییدشده نیست یا این API برای نوع محتوای شما فعال نیست.', 'hoosh-seo' ) . ( $message ? ' — ' . mb_substr( $message, 0, 160 ) : '' );
		}
		if ( 400 === $code ) {
			return __( 'درخواست نامعتبر بود.' ) . ( $message ? ' — ' . mb_substr( $message, 0, 160 ) : '' );
		}
		if ( 429 === $code ) {
			return __( 'سهمیهٔ روزانهٔ Indexing API پر شده است (۲۰۰ نشانی در روز).', 'hoosh-seo' );
		}
		return $message ? mb_substr( $message, 0, 200 ) : 'HTTP ' . (int) $code;
	}
}
