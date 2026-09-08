<?php
/**
 * Search analytics: Google Search Console, GA4, Matomo, Plausible, PageSpeed.
 *
 * Everything is read-through with a transient cache, so the Studio never blocks
 * on a slow Google round-trip, and every metric has a CSV-import fallback for
 * sites that refuse to hand out OAuth.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Helpers;
use HooshSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Analytics
 */
final class Analytics {

	const CACHE = 43200;

	/**
	 * Singleton.
	 *
	 * @var Analytics|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Analytics
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
		add_action( 'admin_post_hoosh_gsc_connect', array( __CLASS__, 'oauth_redirect' ) );
		add_action( 'admin_post_hoosh_gsc_callback', array( __CLASS__, 'oauth_callback' ) );
		add_action( 'admin_post_hoosh_gsc_disconnect', array( __CLASS__, 'oauth_disconnect' ) );
		add_action( 'hoosh_seo_daily', array( __CLASS__, 'refresh_all' ) );
	}

	/**
	 * Which integrations are wired up.
	 *
	 * @return array
	 */
	public static function status() {
		$settings = \hoosh_seo()->settings;
		return array(
			'gsc'       => array(
				'label'    => __( 'سرچ کنسول', 'hoosh-seo' ),
				'connected' => self::connected( 'gsc' ),
				'property'  => (string) $settings->get( 'analytics.gsc.property', '' ),
				'sync'      => (string) $settings->get( 'analytics.gsc.sync', 'daily' ),
				'days'      => (int) $settings->get( 'analytics.gsc.days', 90 ),
				'auth'      => self::auth_mode( 'gsc' ),
				'cached'    => (bool) get_transient( 'hoosh_gsc_summary' ),
			),
			'ga4'       => array(
				'label'    => 'GA4',
				'connected' => self::connected( 'ga4' ),
				'property'  => (string) $settings->get( 'analytics.ga4.property', '' ),
				'sync'      => (string) $settings->get( 'analytics.ga4.sync', 'daily' ),
				'days'      => (int) $settings->get( 'analytics.ga4.days', 28 ),
				'auth'      => self::auth_mode( 'ga4' ),
			),
			'pagespeed' => array(
				'label'    => 'PageSpeed Insights',
				'connected' => (bool) $settings->get( 'analytics.pagespeed.enabled', true ),
				'key'      => $settings->get( 'analytics.pagespeed.key' ) ? 'set' : '',
				'sample'   => (int) $settings->get( 'analytics.pagespeed.sample', 20 ),
				'schedule' => (string) $settings->get( 'analytics.pagespeed.schedule', 'weekly' ),
			),
			'serper'    => array(
				'label'    => 'Serper.dev',
				'connected' => (bool) $settings->get( 'analytics.serper.enabled', false ) && (bool) $settings->get( 'analytics.serper.key' ),
			),
			'dataforseo' => array(
				'label'    => 'DataForSEO',
				'connected' => (bool) $settings->get( 'analytics.dataforseo.enabled', false ) && (bool) $settings->get( 'analytics.dataforseo.password' ),
			),
			'serpapi'   => array(
				'label'    => 'SerpAPI',
				'connected' => (bool) $settings->get( 'analytics.serpapi.enabled', false ) && (bool) $settings->get( 'analytics.serpapi.key' ),
			),
			'semrush'   => array(
				'label'    => 'Semrush',
				'connected' => (bool) $settings->get( 'analytics.semrush.enabled', false ) && (bool) $settings->get( 'analytics.semrush.key' ),
			),
			'alerts'    => (array) $settings->get( 'analytics.alerts', array() ),
			'imported'  => array(
				'gsc_rows' => count( (array) get_transient( 'hoosh_gsc_import' ) ),
			),
			'advanced'  => array(
				'bing_key' => (bool) $settings->get( 'advanced.bing_key', '' ),
			),
		);
	}

	/**
	 * Is an integration usable?
	 *
	 * @param string $which Integration key.
	 * @return bool
	 */
	public static function connected( $which ) {
		$settings = \hoosh_seo()->settings;
		switch ( $which ) {
			case 'gsc':
				return (bool) $settings->get( 'analytics.gsc.enabled', false ) && (bool) $settings->get( 'analytics.gsc.property', '' ) && ( ! ! $settings->get( 'analytics.gsc.token', '' ) || ! ! $settings->get( 'analytics.gsc.credentials', '' ) );
			case 'ga4':
				return (bool) $settings->get( 'analytics.ga4.enabled', false ) && (bool) $settings->get( 'analytics.ga4.property', '' ) && ( ! ! $settings->get( 'analytics.ga4.token', '' ) || ! ! $settings->get( 'analytics.ga4.credentials', '' ) );
		}
		return false;
	}

	/**
	 * Auth mode label.
	 *
	 * @param string $which Which.
	 * @return string
	 */
	public static function auth_mode( $which ) {
		$settings = \hoosh_seo()->settings;
		if ( $settings->get( 'analytics.' . $which . '.credentials' ) ) {
			return 'service-account';
		}
		if ( $settings->get( 'analytics.' . $which . '.token' ) ) {
			return 'oauth';
		}
		return 'none';
	}

	/**
	 * Access token, refreshed when needed.
	 *
	 * @param string $which gsc|ga4.
	 * @return string
	 */
	protected static function access_token( $which = 'gsc' ) {
		$settings = \hoosh_seo()->settings;
		$token    = (array) json_decode( (string) $settings->get( 'analytics.' . $which . '.token', '' ), true );

		if ( ! empty( $token['access_token'] ) && ( empty( $token['expires'] ) || $token['expires'] > time() + 60 ) ) {
			return (string) $token['access_token'];
		}

		// Service account: sign a JWT.
		$credentials = (string) $settings->get( 'analytics.' . $which . '.credentials', '' );
		if ( $credentials ) {
			$jwt = self::service_token( $credentials );
			if ( $jwt ) {
				self::store_token( $which, array( 'access_token' => $jwt, 'expires' => time() + 3500, 'type' => 'jwt' ) );
				return $jwt;
			}
		}

		if ( ! empty( $token['refresh_token'] ) ) {
			$raw = Helpers::http(
				'https://oauth2.googleapis.com/token',
				array(
					'method'  => 'POST',
					'timeout' => 15,
					'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
					'body'    => http_build_query(
						array(
							'client_id'     => (string) $settings->get( 'analytics.' . $which . '.client_id', $settings->get( 'google_client_id', '' ) ),
							'client_secret' => (string) $settings->get( 'analytics.' . $which . '.client_secret', $settings->get( 'google_client_secret', '' ) ),
							'refresh_token' => (string) $token['refresh_token'],
							'grant_type'    => 'refresh_token',
						)
					),
				)
			);
			$json = json_decode( (string) $raw['body'], true );
			if ( ! empty( $json['access_token'] ) ) {
				self::store_token(
					$which,
					array(
						'access_token'  => (string) $json['access_token'],
						'refresh_token' => (string) ( $json['refresh_token'] ?? $token['refresh_token'] ),
						'expires'       => time() + (int) ( $json['expires_in'] ?? 3500 ) - 60,
						'type'          => 'oauth',
					)
				);
				return (string) $json['access_token'];
			}
		}

		return '';
	}

	/**
	 * Persist a token pair.
	 *
	 * @param string $which Which.
	 * @param array  $token Token.
	 */
	protected static function store_token( $which, $token ) {
		Settings::instance()->set(
			array( 'analytics' => array( $which => array( 'token' => wp_json_encode( $token ), 'connected' => true, 'refresh' => time() ) ) ),
			true
		);
	}

	/**
	 * OAuth: send the admin to Google.
	 */
	public static function oauth_redirect() {
		check_admin_referer( 'hoosh_seo_tools' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازه ندارید.', 'hoosh-seo' ) );
		}
		$settings = \hoosh_seo()->settings;
		$client   = (string) $settings->get( 'analytics.gsc.client_id', $settings->get( 'google_client_id', '' ) );
		if ( ! $client ) {
			wp_safe_redirect( add_query_arg( 'hs_err', 'no-client', \HooshSEO\App::studio_url( 'search-console' ) ) );
			exit;
		}
		$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(
			array(
				'client_id'    => $client,
				'redirect_uri' => admin_url( 'admin-post.php?action=hoosh_gsc_callback' ),
				'response_type' => 'code',
				'scope'        => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
				'access_type'  => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'       => 'consent',
				'state'        => wp_create_nonce( 'hoosh_gsc_state' ),
			)
		);
		wp_redirect( $url ); // phpcs:ignore
		exit;
	}

	/**
	 * OAuth: exchange the code.
	 */
	public static function oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'اجازه ندارید.', 'hoosh-seo' ) );
		}
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore
		if ( ! $code || ! wp_verify_nonce( $state, 'hoosh_gsc_state' ) ) {
			wp_safe_redirect( add_query_arg( 'hs_err', 'bad-callback', \HooshSEO\App::studio_url( 'search-console' ) ) );
			exit;
		}
		$settings = \hoosh_seo()->settings;
		$raw      = Helpers::http(
			'https://oauth2.googleapis.com/token',
			array(
				'method'  => 'POST',
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query(
					array(
						'code'          => $code,
						'client_id'     => (string) $settings->get( 'analytics.gsc.client_id', $settings->get( 'google_client_id', '' ) ),
						'client_secret' => (string) $settings->get( 'analytics.gsc.client_secret', $settings->get( 'google_client_secret', '' ) ),
						'redirect_uri'  => admin_url( 'admin-post.php?action=hoosh_gsc_callback' ),
						'grant_type'    => 'authorization_code',
					)
				),
			)
		);
		$json = json_decode( (string) $raw['body'], true );
		if ( empty( $json['access_token'] ) ) {
			wp_safe_redirect( add_query_arg( 'hs_err', 'token-failed', \HooshSEO\App::studio_url( 'search-console' ) ) );
			exit;
		}
		self::store_token( 'gsc', array( 'access_token' => (string) $json['access_token'], 'refresh_token' => (string) ( $json['refresh_token'] ?? '' ), 'expires' => time() + (int) ( $json['expires_in'] ?? 3500 ) - 60, 'type' => 'oauth' ) );
		self::store_token( 'ga4', array( 'access_token' => (string) $json['access_token'], 'refresh_token' => (string) ( $json['refresh_token'] ?? '' ), 'expires' => time() + (int) ( $json['expires_in'] ?? 3500 ) - 60, 'type' => 'oauth' ) );

		$sites = self::list_sites();
		if ( $sites && ! $settings->get( 'analytics.gsc.property' ) ) {
			Settings::instance()->set( array( 'analytics' => array( 'gsc' => array( 'property' => (string) $sites[0], 'enabled' => true ) ) ), true );
		}

		delete_transient( 'hoosh_gsc_summary' );
		Audit::log( 'reports', 'connect', array( 'label' => __( 'اتصال گوگل برقرار شد', 'hoosh-seo' ) ) );
		wp_safe_redirect( add_query_arg( 'hs_msg', 'connected', \HooshSEO\App::studio_url( 'search-console' ) ) );
		exit;
	}

	/**
	 * Drop the token.
	 */
	public static function oauth_disconnect() {
		check_admin_referer( 'hoosh_seo_tools' );
		Settings::instance()->set(
			array(
				'analytics' => array(
					'gsc' => array( 'token' => '', 'connected' => false ),
					'ga4' => array( 'token' => '', 'connected' => false ),
				),
			),
			true
		);
		delete_transient( 'hoosh_gsc_summary' );
		wp_safe_redirect( add_query_arg( 'hs_msg', 'disconnected', \HooshSEO\App::studio_url( 'search-console' ) ) );
		exit;
	}

	/**
	 * Verified sites.
	 *
	 * @return array
	 */
	public static function list_sites() {
		$token = self::access_token( 'gsc' );
		if ( ! $token ) {
			return array();
		}
		$cache = get_transient( 'hoosh_gsc_sites' );
		if ( is_array( $cache ) ) {
			return $cache;
		}
		$raw  = Helpers::http( 'https://www.googleapis.com/webmasters/v3/sites', array( 'timeout' => 15, 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
		$json = json_decode( (string) $raw['body'], true );
		$out  = array();
		foreach ( (array) ( $json['siteEntry'] ?? array() ) as $row ) {
			if ( isset( $row['siteUrl'] ) ) {
				$out[] = (string) $row['siteUrl'];
			}
		}
		if ( $out ) {
			set_transient( 'hoosh_gsc_sites', $out, self::CACHE );
		}
		return $out;
	}

	/**
	 * Sign a service-account JWT and swap it for an access token.
	 *
	 * @param string $credentials JSON blob.
	 * @return string
	 */
	protected static function service_token( $credentials ) {
		$json = json_decode( (string) $credentials, true );
		if ( ! is_array( $json ) || empty( $json['private_key'] ) || empty( $json['client_email'] ) || empty( $json['token_uri'] ) ) {
			return '';
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return '';
		}
		$now   = time();
		$header = self::b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claim  = self::b64(
			wp_json_encode(
				array(
					'iss'   => (string) $json['client_email'],
					'scope' => 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly',
					'aud'   => (string) $json['token_uri'],
					'exp'   => $now + 3500,
					'iat'   => $now,
				)
			)
		);
		$signature = '';
		$key       = openssl_pkey_get_private( (string) $json['private_key'] );
		if ( ! $key || ! openssl_sign( $header . '.' . $claim, $signature, $key, 'sha256WithRSAEncryption' ) ) {
			return '';
		}
		$jwt = $header . '.' . $claim . '.' . self::b64( $signature );

		$raw  = Helpers::http(
			(string) $json['token_uri'],
			array(
				'method'  => 'POST',
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query(
					array(
						'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
						'assertion'  => $jwt,
					)
				),
			)
		);
		$json = json_decode( (string) $raw['body'], true );
		return isset( $json['access_token'] ) ? (string) $json['access_token'] : '';
	}

	/**
	 * URL-safe base64.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	protected static function b64( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' ); // phpcs:ignore
	}

	/**
	 * One API call, cached.
	 *
	 * @param string $endpoint Endpoint key (searchAnalytics, ga4report, …).
	 * @param array  $args     Body.
	 * @return array|null
	 */
	public static function query( $endpoint, $args = array() ) {
		$which = false !== strpos( (string) $endpoint, 'ga4' ) ? 'ga4' : 'gsc';
		if ( ! self::connected( $which ) ) {
			// Imported CSV still answers searchAnalytics.
			if ( 'searchAnalytics' === $endpoint ) {
				return self::from_import( $args );
			}
			return null;
		}
		$token = self::access_token( $which );
		if ( ! $token ) {
			return null;
		}

		$key   = 'hoosh_api_' . md5( $endpoint . wp_json_encode( $args ) );
		$cache = get_transient( $key );
		if ( is_array( $cache ) ) {
			return $cache;
		}

		if ( false !== strpos( (string) $endpoint, 'ga4' ) ) {
			$property = (string) \hoosh_seo()->settings->get( 'analytics.ga4.property', '' );
			$url      = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property ) . ':runReport';
		} else {
			$site     = (string) \hoosh_seo()->settings->get( 'analytics.gsc.property', '' );
			$url      = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site ) . '/searchAnalytics/query';
		}

		$raw = Helpers::http(
			$url,
			array(
				'method'  => 'POST',
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $args ),
			)
		);
		if ( empty( $raw['ok'] ) ) {
			return null;
		}
		$json = json_decode( (string) $raw['body'], true );
		if ( ! is_array( $json ) ) {
			return null;
		}
		if ( isset( $json['error'] ) ) {
			return null;
		}

		$rows = (array) ( $json['rows'] ?? array() );

		set_transient( $key, $rows, self::CACHE );
		return $rows;
	}

	/**
	 * Normalise a GSC row set into flat rows.
	 *
	 * @param array $rows   API rows.
	 * @param array $dims   Dimensions order.
	 * @return array
	 */
	public static function flatten_rows( $rows, $dims = array( 'date' ) ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$keys = (array) ( $row['keys'] ?? array() );
			$item = array(
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
				'ctr'         => (float) ( $row['ctr'] ?? 0 ),
			);
			foreach ( array_values( (array) $dims ) as $index => $dim ) {
				$item[ $dim ] = isset( $keys[ $index ] ) ? (string) $keys[ $index ] : '';
			}
			if ( empty( $item['ctr'] ) && $item['impressions'] ) {
				$item['ctr'] = round( $item['clicks'] / $item['impressions'], 4 );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Cached summary used by the dashboard + reports.
	 *
	 * @param int $days Window.
	 * @return array
	 */
	public static function summary( $days = 28 ) {
		$days = max( 7, min( 365, (int) $days ) );
		$key  = 'hoosh_gsc_summary_' . $days;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$empty = array(
			'available'  => false,
			'source'     => 'none',
			'clicks'     => 0,
			'impressions' => 0,
			'ctr'        => 0,
			'position'   => 0,
			'series'     => array(),
			'labels'     => array(),
			'prev'       => array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 ),
			'delta'      => array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 ),
			'pages'      => array(),
			'queries'    => array(),
			'devices'    => array(),
			'countries'  => array(),
			'imported'   => false,
		);

		$start = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) );
		$end   = gmdate( 'Y-m-d' );

		$rows = self::query(
			'searchAnalytics',
			array(
				'startDate'  => $start,
				'endDate'    => $end,
				'dimensions' => array( 'date' ),
				'rowLimit'   => (int) \hoosh_seo()->settings->get( 'analytics.gsc.row_limit', 25000 ) > 1000 ? 1000 : (int) \hoosh_seo()->settings->get( 'analytics.gsc.row_limit', 25000 ),
			)
		);

		if ( ! is_array( $rows ) ) {
			$import = get_transient( 'hoosh_gsc_import' );
			if ( is_array( $import ) && $import ) {
				$out               = $empty;
				$out['available']  = true;
				$out['source']     = 'import';
				$out['imported']   = true;
				$out['series']     = array_slice( (array) $import, -90 );
				$out['clicks']     = (int) array_sum( array_column( $out['series'], 'clicks' ) );
				$out['impressions'] = (int) array_sum( array_column( $out['series'], 'impressions' ) );
				$out['ctr']        = $out['impressions'] ? round( $out['clicks'] / $out['impressions'] * 100, 2 ) : 0;
				$out['labels']     = array_map(
					static function ( $row ) {
						return isset( $row['date'] ) ? Helpers::jalali_date( strtotime( (string) $row['date'] ) ) : '';
					},
					$out['series']
				);
				set_transient( $key, $out, 3600 );
				return $out;
			}
			return $empty;
		}

		$daily = self::flatten_rows( $rows, array( 'date' ) );
		usort_by_date( $daily );

		$series  = array();
		$labels  = array();
		$clicks  = 0;
		$impr    = 0;
		$pos_sum = 0;
		foreach ( $daily as $row ) {
			$clicks += $row['clicks'];
			$impr   += $row['impressions'];
			$pos_sum += $row['position'] * max( 1, $row['impressions'] );
			$series[] = array(
				'date'        => (string) $row['date'],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'ctr'         => round( (float) $row['ctr'] * 100, 2 ),
				'position'    => (float) $row['position'],
			);
			$labels[] = Helpers::jalali_date( strtotime( (string) $row['date'] ), 'j n' );
		}

		$previous = self::query(
			'searchAnalytics',
			array(
				'startDate'  => gmdate( 'Y-m-d', strtotime( $start . ' -' . $days . ' days' ) ),
				'endDate'    => gmdate( 'Y-m-d', strtotime( $start . ' -1 day' ) ),
				'dimensions' => array( 'date' ),
				'rowLimit'   => 1000,
			)
		);
		$prev     = array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0 );
		foreach ( self::flatten_rows( (array) $previous, array( 'date' ) ) as $row ) {
			$prev['clicks']     += (int) $row['clicks'];
			$prev['impressions'] += (int) $row['impressions'];
			$prev['position']   += (float) $row['position'];
		}
		$prev_days = max( 1, count( (array) $previous ) );
		$prev['position'] = round( $prev['position'] / $prev_days, 1 );
		$prev['ctr']      = $prev['impressions'] ? round( $prev['clicks'] / $prev['impressions'] * 100, 2 ) : 0;

		$ctr = $impr ? round( $clicks / $impr * 100, 2 ) : 0;

		return array(
			'available'   => true,
			'source'      => self::connected( 'gsc' ) ? 'gsc' : 'import',
			'clicks'      => $clicks,
			'impressions' => $impr,
			'ctr'         => $ctr,
			'position'    => $daily ? round( $pos_sum / max( 1, $impr ) * ( $impr ? 1 : 0 ), 1 ) : 0,
			'position_avg' => $daily ? round( array_sum( array_column( $daily, 'position' ) ) / count( $daily ), 1 ) : 0,
			'series'      => $series,
			'labels'      => array_values( array_reverse( $labels ) ),
			'prev'        => $prev,
			'delta'       => array(
				'clicks'      => $prev['clicks'] ? round( ( $clicks - $prev['clicks'] ) / $prev['clicks'] * 100, 1 ) : 0,
				'impressions' => $prev['impressions'] ? round( ( $impr - $prev['impressions'] ) / $prev['impressions'] * 100, 1 ) : 0,
				'ctr'         => $prev['ctr'] ? round( ( $ctr - $prev['ctr'] ) / $prev['ctr'] * 100, 1 ) : 0,
				'position'    => $prev['position'] ? round( $prev['position'] - ( $daily ? round( array_sum( array_column( $daily, 'position' ) ) / count( $daily ), 1 ) : 0 ), 1 ) : 0,
			),
			'pages'       => self::top( 'page', $days, 15 ),
			'queries'     => self::top( 'query', $days, 15 ),
			'devices'     => self::dimension( 'device' ),
			'countries'   => self::dimension( 'country' ),
			'imported'    => false,
		);
	}

	/**
	 * Top rows for a dimension.
	 *
	 * @param string $dimension query|page.
	 * @param int    $days      Window.
	 * @param int    $limit     Rows.
	 * @return array
	 */
	public static function top( $dimension, $days = 28, $limit = 25 ) {
		$dimension = in_array( $dimension, array( 'query', 'page' ), true ) ? $dimension : 'query';
		$rows      = self::query(
			'searchAnalytics',
			array(
				'startDate'  => gmdate( 'Y-m-d', strtotime( '-' . max( 1, (int) $days ) . ' days' ) ),
				'endDate'    => gmdate( 'Y-m-d' ),
				'dimensions' => array( $dimension ),
				'rowLimit'   => max( 5, min( 1000, (int) $limit ) ),
			)
		);
		$out = array();
		foreach ( self::flatten_rows( (array) $rows, array( $dimension ) ) as $index => $row ) {
			$out[] = array(
				'label'       => (string) $row[ $dimension ],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'ctr'         => round( (float) $row['ctr'] * 100, 2 ),
				'position'    => (float) $row['position'],
				'rank'        => $index + 1,
				'url'         => 'page' === $dimension ? (string) $row[ $dimension ] : '',
			);
		}
		return $out;
	}

	/**
	 * One-dimension breakdown (device / country / query).
	 *
	 * @param string $dimension Dimension.
	 * @return array
	 */
	public static function dimension( $dimension ) {
		$rows = self::query(
			'searchAnalytics',
			array(
				'startDate'  => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
				'endDate'    => gmdate( 'Y-m-d' ),
				'dimensions' => array( sanitize_key( (string) $dimension ) ),
				'rowLimit'   => 15,
			)
		);
		$out = array();
		foreach ( self::flatten_rows( (array) $rows, array( $dimension ) ) as $row ) {
			$out[] = array(
				'label'       => (string) $row[ $dimension ],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'ctr'         => round( (float) $row['ctr'] * 100, 2 ),
				'position'    => (float) $row['position'],
			);
		}
		return $out;
	}

	/**
	 * Per-page rows for a single URL (metabox + snippet view).
	 *
	 * @param string $url  URL.
	 * @param int    $days Window.
	 * @return array
	 */
	public static function page_report( $url, $days = 28 ) {
		$rows = self::query(
			'searchAnalytics',
			array(
				'startDate'   => gmdate( 'Y-m-d', strtotime( '-' . max( 1, (int) $days ) . ' days' ) ),
				'endDate'     => gmdate( 'Y-m-d' ),
				'dimensions'  => array( 'query' ),
				'dimensionFilterGroups' => array(
					array( 'filters' => array( array( 'dimension' => 'page', 'operator' => 'equals', 'expression' => (string) $url ) ) ),
				),
				'rowLimit'    => 50,
			)
		);
		$out  = self::top( 'page', $days, 500 );
		$mine = array();
		foreach ( $out as $row ) {
			if ( untrailingslashit( $row['label'] ) === untrailingslashit( (string) $url ) ) {
				$mine = $row;
				break;
			}
		}

		return array(
			'ok'      => true,
			'url'     => (string) $url,
			'queries' => self::flatten_rows( (array) $rows, array( 'query' ) ),
			'page'    => $mine,
		);
	}

	/**
	 * GA4 overview (sessions, users, engaged, top pages).
	 *
	 * @param int $days Window.
	 * @return array
	 */
	public static function ga4( $days = 28 ) {
		$rows = self::query(
			'ga4report',
			array(
				'dateRanges'  => array( array( 'startDate' => gmdate( 'Y-m-d', strtotime( '-' . max( 1, (int) $days ) . ' days' ) ), 'endDate' => gmdate( 'Y-m-d' ) ) ),
				'metrics'     => array(
					array( 'name' => 'sessions' ),
					array( 'name' => 'activeUsers' ),
					array( 'name' => 'engagedSessions' ),
					array( 'name' => 'averageSessionDuration' ),
				),
				'dimensions'  => array( array( 'name' => 'date' ) ),
				'limit'       => 400,
			)
		);
		if ( ! is_array( $rows ) || ! $rows ) {
			return array( 'available' => false, 'sessions' => 0, 'users' => 0, 'series' => array() );
		}
		$sessions = 0;
		$users    = 0;
		$series   = array();
		foreach ( $rows as $row ) {
			$dims   = (array) ( $row['dimensionValues'] ?? array() );
			$metric = (array) ( $row['metricValues'] ?? array() );
			$date   = isset( $dims[0]['value'] ) ? (string) $dims[0]['value'] : '';
			$s      = isset( $metric[0]['value'] ) ? (int) $metric[0]['value'] : 0;
			$u      = isset( $metric[1]['value'] ) ? (int) $metric[1]['value'] : 0;
			$sessions += $s;
			$users     = max( $users, $u );
			$series[]   = array(
				'date'     => $date,
				'sessions' => $s,
				'users'    => $u,
				'label'    => $date ? Helpers::jalali_date( strtotime( $date ), 'j n' ) : '',
			);
		}
		return array(
			'available' => true,
			'sessions'   => $sessions,
			'users'      => $users,
			'series'     => array_reverse( $series ),
		);
	}

	/**
	 * Import a GSC/GA CSV export.
	 *
	 * @param array $args {file, content, kind}.
	 * @return array
	 */
	public static function import( $args = array() ) {
		$args = wp_parse_args( (array) $args, array( 'content' => '', 'kind' => 'search-analytics', 'file' => '' ) );

		$content = (string) $args['content'];
		if ( '' === $content && ! empty( $args['file'] ) && file_exists( (string) $args['file'] ) ) {
			$content = (string) file_get_contents( (string) $args['file'] ); // phpcs:ignore
		}
		if ( '' === $content ) {
			return array( 'ok' => false, 'message' => __( 'فایل خالی بود.', 'hoosh-seo' ) );
		}
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );

		$lines = array_values( array_filter( preg_split( '/\r\n|\n|\r/', trim( $content ) ), 'strlen' ) );
		$header = array_map( 'strtolower', array_map( 'trim', str_getcsv( (string) array_shift( $lines ) ) ) );

		$index = array(
			'date'        => array_search( 'date', $header, true ),
			'queries'     => array_search( 'query', $header, true ),
			'pages'       => array_search( 'page', $header, true ),
			'clicks'      => array_search( 'clicks', $header, true ),
			'impressions' => array_search( 'impressions', $header, true ),
			'ctr'         => array_search( 'ctr', $header, true ),
			'position'    => array_search( 'position', $header, true ),
			'device'      => array_search( 'device', $header, true ),
			'country'     => array_search( 'country', $header, true ),
		);
		if ( false === $index['clicks'] || false === $index['impressions'] ) {
			return array( 'ok' => false, 'message' => __( 'ستون clicks/impressions در فایل پیدا نشد؛ خروجی استاندارد سرچ کنسول را بارگذاری کنید.', 'hoosh-seo' ) );
		}

		$daily   = array();
		$queries = array();
		$pages   = array();
		$rows    = 0;
		foreach ( $lines as $line ) {
			$cells = array_map( 'trim', str_getcsv( $line ) );
			$get   = function ( $key ) use ( $cells, $index ) {
				return false !== $index[ $key ] && isset( $cells[ $index[ $key ] ] ) ? $cells[ $index[ $key ] ] : '';
			};
			$date  = (string) $get( 'date' );
			$clicks = (int) round( (float) str_replace( ',', '', (string) $get( 'clicks' ) ) );
			$impr   = (int) round( (float) str_replace( ',', '', (string) $get( 'impressions' ) ) );
			$pos    = (float) str_replace( ',', '.', (string) $get( 'position' ) );
			$rows++;

			if ( $date ) {
				if ( ! isset( $daily[ $date ] ) ) {
					$daily[ $date ] = array( 'date' => $date, 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'n' => 0 );
				}
				$daily[ $date ]['clicks']      += $clicks;
				$daily[ $date ]['impressions'] += $impr;
				$daily[ $date ]['position']    += $pos;
				$daily[ $date ]['n']++;
			}
			$label = (string) $get( 'queries' );
			if ( $label ) {
				$queries[ $label ] = isset( $queries[ $label ] ) ? $queries[ $label ] : array( 'label' => $label, 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'n' => 0 );
				$queries[ $label ]['clicks'] += $clicks;
				$queries[ $label ]['impressions'] += $impr;
				$queries[ $label ]['position'] += $pos;
				$queries[ $label ]['n']++;
			}
			$label = (string) $get( 'pages' );
			if ( $label ) {
				$pages[ $label ] = isset( $pages[ $label ] ) ? $pages[ $label ] : array( 'label' => Helpers::rel_url( $label ), 'url' => Helpers::abs_url( $label ), 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'n' => 0 );
				$pages[ $label ]['clicks'] += $clicks;
				$pages[ $label ]['impressions'] += $impr;
				$pages[ $label ]['position'] += $pos;
				$pages[ $label ]['n']++;
			}
		}

		$finish = function ( &$set ) {
			foreach ( $set as &$row ) {
				$row['position'] = $row['n'] ? round( $row['position'] / $row['n'], 1 ) : 0;
				$row['ctr']      = $row['impressions'] ? round( $row['clicks'] / $row['impressions'] * 100, 2 ) : 0;
				unset( $row['n'] );
			}
			unset( $row );
			$set = array_values( $set );
			usort_by_clicks( $set );
			$set = array_slice( $set, 0, 500 );
		};
		$finish( $queries );
		$finish( $pages );

		ksort( $daily );
		$daily_rows = array_values( $daily );
		foreach ( $daily_rows as &$d ) {
			$d['position'] = $d['n'] ? round( $d['position'] / $d['n'], 1 ) : 0;
			$d['ctr']      = $d['impressions'] ? round( $d['clicks'] / $d['impressions'] * 100, 2 ) : 0;
			unset( $d['n'] );
		}
		unset( $d );

		set_transient( 'hoosh_gsc_import', $daily_rows, 30 * DAY_IN_SECONDS );
		set_transient( 'hoosh_import_queries', $queries, 30 * DAY_IN_SECONDS );
		set_transient( 'hoosh_import_pages', $pages, 30 * DAY_IN_SECONDS );
		foreach ( array( 'hoosh_gsc_summary', 'hoosh_gsc_summary_28', 'hoosh_gsc_summary_90' ) as $key ) {
			delete_transient( $key );
		}
		delete_transient( 'hoosh_gsc_summary_' . gmdate( 'n' ) );

		Audit::log( 'reports', 'import', array( 'label' => sprintf( /* translators: %d rows */ __( 'واردکردن CSV سرچ کنسول: %d سطر', 'hoosh-seo' ), $rows ) ) );

		return array(
			'ok'      => true,
			'rows'    => $rows,
			'days'    => count( $daily_rows ),
			'queries' => count( $queries ),
			'pages'   => count( $pages ),
			'message' => sprintf( /* translators: 1: rows, 2: days */ __( '%1$s سطر از %2$s روز وارد شد.', 'hoosh-seo' ), $rows, count( $daily_rows ) ),
		);
	}

	/**
	 * Read rows back from an import when no API exists.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	protected static function from_import( $args ) {
		$daily = (array) get_transient( 'hoosh_gsc_import' );
		if ( ! $daily ) {
			return array();
		}
		$dims = (array) ( $args['dimensions'] ?? array( 'date' ) );
		if ( array( 'date' ) !== $dims ) {
			$which = in_array( 'query', $dims, true ) ? 'hoosh_import_queries' : 'hoosh_import_pages';
			$rows  = (array) get_transient( $which );
			$limit = (int) ( $args['rowLimit'] ?? 25 );
			return array_map(
				static function ( $row ) {
					return array(
						'keys'        => array( $row['label'] ),
						'clicks'      => (int) $row['clicks'],
						'impressions' => (int) $row['impressions'],
						'position'    => (float) $row['position'],
						'ctr'         => (float) $row['ctr'] / 100,
					);
				},
				array_slice( $rows, 0, $limit )
			);
		}
		$out = array();
		foreach ( $daily as $row ) {
			$out[] = array(
				'keys'        => array( $row['date'] ),
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'position'    => (float) $row['position'],
				'ctr'         => (float) $row['ctr'] / 100,
			);
		}
		return $out;
	}

	/**
	 * Top queries (Studio).
	 *
	 * @param array $args {days, limit, search}.
	 * @return array
	 */
	public static function top_queries( $args = array() ) {
		$args = wp_parse_args( (array) $args, array( 'days' => 28, 'limit' => 100, 'search' => '' ) );
		$rows = self::top( 'query', (int) $args['days'], (int) $args['limit'] );
		if ( '' !== $args['search'] ) {
			$needle = Helpers::normalize_fa( (string) $args['search'] );
			$rows   = array_values( array_filter( $rows, static function ( $row ) use ( $needle ) {
				return false !== mb_strpos( Helpers::normalize_fa( (string) $row['label'] ), $needle );
			} ) );
		}
		foreach ( $rows as &$row ) {
			$row['tracking'] = self::is_tracked( (string) $row['label'] );
			$row['edit']     = admin_url( 'post-new.php?post_title=' . rawurlencode( (string) $row['label'] ) );
		}
		unset( $row );
		return array( 'rows' => $rows, 'total' => count( $rows ) );
	}

	/**
	 * Top pages (Studio).
	 *
	 * @param array $args {days, limit, sort}.
	 * @return array
	 */
	public static function top_pages( $args = array() ) {
		$args = wp_parse_args( (array) $args, array( 'days' => 28, 'limit' => 100, 'sort' => 'clicks' ) );
		$rows = self::top( 'page', (int) $args['days'], (int) $args['limit'] );
		foreach ( $rows as &$row ) {
			$url       = Helpers::rel_url( (string) $row['label'] );
			$id        = Helpers::url_to_post( $url );
			$row['url'] = Helpers::abs_url( $url );
			$row['post_id'] = $id;
			$row['title'] = $id ? (string) get_the_title( $id ) : '';
			$row['edit']  = $id ? (string) get_edit_post_link( $id, 'raw' ) : '';
			$row['optimize'] = $row['clicks'] > 20 && $row['position'] > 8;
		}
		unset( $row );

		if ( 'position' === $args['sort'] ) {
			usort_by_position( $rows );
		}
		return array( 'rows' => $rows, 'total' => count( $rows ) );
	}

	/**
	 * Is the phrase already in the vault (tracking)?
	 *
	 * @param string $phrase Phrase.
	 * @return bool
	 */
	protected static function is_tracked( $phrase ) {
		$exists = get_transient( 'hoosh_track_' . md5( Helpers::normalize_fa( (string) $phrase ) ) );
		if ( false !== $exists ) {
			return (bool) $exists;
		}
		global $wpdb;
		if ( ! \HooshSEO\Database::exists( 'keywords' ) ) {
			return false;
		}
		$found = (bool) $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare(
				'SELECT id FROM ' . \HooshSEO\Database::table( 'keywords' ) . ' WHERE norm = %s LIMIT 1',
				Helpers::normalize_fa( (string) $phrase )
			)
		);
		set_transient( 'hoosh_track_' . md5( Helpers::normalize_fa( (string) $phrase ) ), $found ? 1 : 0, HOUR_IN_SECONDS );
		return $found;
	}

	/**
	 * Alerts: big drops + CTR anomalies worth an email.
	 *
	 * @return array
	 */
	public static function alerts() {
		$settings = \hoosh_seo()->settings;
		$out      = array( 'rows' => array(), 'sent' => false );
		if ( ! $settings->on( 'analytics.alerts.email' ) ) {
			return $out;
		}
		$drop_position = (int) $settings->get( 'analytics.alerts.drop_position', 5 );
		$drop_percent  = (int) $settings->get( 'analytics.alerts.drop_percent', 30 );

		global $wpdb;
		if ( \HooshSEO\Database::exists( 'keywords' ) ) {
			$rows = (array) $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare(
					'SELECT id, phrase, url, position, prev_position FROM ' . \HooshSEO\Database::table( 'keywords' ) . '
					 WHERE state = \'tracking\' AND position > 0 AND prev_position > 0 AND ( position - prev_position ) >= %d
					 ORDER BY ( position - prev_position ) DESC LIMIT 15',
					$drop_position
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$out['rows'][] = array(
					'type'     => 'rank',
					'keyword'  => (string) $row['phrase'],
					'url'      => (string) $row['url'],
					'from'     => (float) $row['prev_position'],
					'to'       => (float) $row['position'],
					'delta'    => (float) $row['position'] - (float) $row['prev_position'],
					'percent'  => $row['prev_position'] ? round( ( ( $row['position'] - $row['prev_position'] ) / $row['prev_position'] ) * 100 ) : 0,
					'post_id'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM ' . \HooshSEO\Database::table( 'keywords' ) . ' WHERE id = %d', (int) $row['id'] ) ), // phpcs:ignore
				);
			}
		}

		$summary = self::summary( 28 );
		if ( ! empty( $summary['available'] ) && $summary['prev']['clicks'] ) {
			$change = ( $summary['clicks'] - $summary['prev']['clicks'] ) / max( 1, $summary['prev']['clicks'] ) * 100;
			if ( $change <= -1 * $drop_percent ) {
				$out['rows'][] = array(
					'type'    => 'traffic',
					'keyword' => __( 'کل ترافیک ارگانیک', 'hoosh-seo' ),
					'percent' => round( $change ),
					'from'    => (int) $summary['prev']['clicks'],
					'to'      => (int) $summary['clicks'],
					'url'     => '',
					'delta'   => (int) ( $summary['clicks'] - $summary['prev']['clicks'] ),
				);
			}
		}

		return $out;
	}

	/**
	 * Refresh every cache.
	 */
	public static function refresh_all() {
		self::refresh( array( 'force' => true ) );
	}

	/**
	 * Refresh the caches (and PSI if scheduled).
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function refresh( $args = array() ) {
		$args  = wp_parse_args( (array) $args, array( 'days' => 28, 'force' => false ) );
		$force = ! empty( $args['force'] );

		foreach ( array( 'hoosh_gsc_summary', 'hoosh_gsc_sites' ) as $key ) {
			delete_transient( $key );
		}
		foreach ( array( 28, 90 ) as $days ) {
			delete_transient( 'hoosh_gsc_summary_' . $days );
		}
		$summary = self::summary( (int) $args['days'] );

		$out = array(
			'ok'       => true,
			'available' => ! empty( $summary['available'] ),
			'source'   => (string) ( $summary['source'] ?? 'none' ),
			'force'    => $force,
			'rows'     => count( (array) ( $summary['series'] ?? array() ) ),
			'message'  => ! empty( $summary['available'] )
				? sprintf( /* translators: %d days */ __( 'داده‌ها برای %d روز تازه شد.', 'hoosh-seo' ), (int) $args['days'] )
				: __( 'اتصال گوگل برقرار نیست؛ فایل CSV را وارد کنید.', 'hoosh-seo' ),
		);
		if ( ! $force ) {
			set_transient( 'hoosh_analytics_refresh', time(), DAY_IN_SECONDS );
		}
		return $out;
	}

	/**
	 * PageSpeed run for a URL.
	 *
	 * @param string $url URL.
	 * @param string $strategy mobile|desktop.
	 * @return array
	 */
	public static function psi( $url, $strategy = 'mobile' ) {
		$settings = \hoosh_seo()->settings;
		$query    = array(
			'url'               => (string) $url,
			'returnRunData'     => 'false',
			'strategy'          => 'desktop' === $strategy ? 'desktop' : 'mobile',
			'category'          => 'PERFORMANCE,ACCESSIBILITY,BEST_PRACTICES,SEO',
		);
		if ( $settings->get( 'analytics.pagespeed.key' ) ) {
			$query['key'] = (string) $settings->get( 'analytics.pagespeed.key' );
		}
		$cache_key = 'hoosh_psi_' . md5( $url . '|' . $strategy );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$raw = Helpers::http( 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query( $query ), array( 'timeout' => 60 ) );
		$json = json_decode( (string) $raw['body'], true );
		if ( empty( $json['lighthouseResult'] ) ) {
			return array( 'ok' => false, 'error' => 'psi', 'score' => 0, 'url' => (string) $url );
		}
		$cats  = (array) ( $json['lighthouseResult']['categories'] ?? array() );
		$out   = array(
			'ok'       => true,
			'url'      => (string) $url,
			'strategy' => $strategy,
			'perf'     => (int) round( (float) ( $cats['performance']['score'] ?? 0 ) * 100 ),
			'a11y'     => (int) round( (float) ( $cats['accessibility']['score'] ?? 0 ) * 100 ),
			'bp'       => (int) round( (float) ( $cats['best-practices']['score'] ?? 0 ) * 100 ),
			'seo'      => (int) round( (float) ( $cats['seo']['score'] ?? 0 ) * 100 ),
			'fields'   => array(
				'cls'  => (float) ( $json['loadingExperience']['metrics']['CLS']['percentile'] ?? 0 ) / 100,
				'inp'  => (float) ( $json['loadingExperience']['metrics']['INP']['percentile'] ?? 0 ),
				'lcp'  => (float) ( $json['loadingExperience']['metrics']['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ?? 0 ),
				'ttfb' => (float) ( $json['loadingExperience']['metrics']['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ?? 0 ),
			),
			'runs'     => (int) ( $json['lighthouseResult']['runWarnings'] ? 1 : 1 ),
			'fcp'      => (string) ( $json['lighthouseResult']['audits']['first-contentful-paint']['displayValue'] ?? '' ),
			'lcp'      => (string) ( $json['lighthouseResult']['audits']['largest-contentful-paint']['displayValue'] ?? '' ),
			'tbt'      => (string) ( $json['lighthouseResult']['audits']['total-blocking-time']['displayValue'] ?? '' ),
			'size'     => (string) ( $json['lighthouseResult']['audits']['total-byte-weight']['displayValue'] ?? '' ),
			'render'   => (string) ( $json['lighthouseResult']['audits']['render-blocking-resources'] ? 'blocking' : 'clean' ),
			'checked'  => time(),
		);
		$ttl = self::connected( 'gsc' ) ? 6 * self::CACHE : 2 * self::CACHE;
		set_transient( $cache_key, $out, min( DAY_IN_SECONDS, $ttl ) );
		return $out;
	}

	/**
	 * PageSpeed across a sample of the busiest pages.
	 *
	 * @param array $args {limit, strategy}.
	 * @return array
	 */
	public static function psi_sample( $args = array() ) {
		$args     = wp_parse_args( (array) $args, array( 'limit' => 20, 'strategy' => 'mobile' ) );
		$limit    = max( 1, min( 50, (int) $args['limit'] ) );
		$urls     = self::psi_urls( $limit );
		$rows     = array();
		$sum      = array( 'perf' => 0, 'a11y' => 0, 'bp' => 0, 'seo' => 0 );
		foreach ( $urls as $url ) {
			$result = self::psi( $url, (string) $args['strategy'] );
			if ( empty( $result['ok'] ) ) {
				$rows[] = array( 'url' => $url, 'ok' => false, 'perf' => 0, 'a11y' => 0, 'bp' => 0, 'seo' => 0 );
				continue;
			}
			foreach ( $sum as $key => $value ) {
				$sum[ $key ] += (int) $result[ $key ];
			}
			$rows[] = array(
				'url'    => $url,
				'ok'     => true,
				'perf'   => (int) $result['perf'],
				'a11y'   => (int) $result['a11y'],
				'bp'     => (int) $result['bp'],
				'seo'    => (int) $result['seo'],
				'size'   => (string) $result['size'],
				'lcp'    => (string) $result['lcp'],
				'fcp'    => (string) $result['fcp'],
				'tbt'    => (string) $result['tbt'],
			);
			usleep( 300000 );
		}
		$n = max( 1, count( array_filter( $rows, static function ( $row ) { return ! empty( $row['ok'] ); } ) ) );
		foreach ( $sum as $key => $value ) {
			$sum[ $key ] = (int) round( $value / $n );
		}

		return array(
			'rows'   => $rows,
			'avg'    => $sum,
			'count'  => count( $rows ),
			'strategy' => (string) $args['strategy'],
			'score'  => $sum['perf'],
		);
	}

	/**
	 * URLs worth testing: homepage + top pages + newest.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	protected static function psi_urls( $limit = 20 ) {
		$urls = array( home_url( '/' ) );
		$top  = self::top( 'page', 90, $limit );
		foreach ( $top as $row ) {
			$urls[] = Helpers::abs_url( (string) $row['label'] );
		}
		$posts = get_posts(
			array(
				'post_type'      => Helpers::managed_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( (array) $posts as $post ) {
			$urls[] = (string) get_permalink( $post );
		}
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		return array_slice( $urls, 0, max( 1, (int) $limit ) );
	}

	/**
	 * Bing Webmaster + IndexNow crawl stats (best effort, key gated).
	 *
	 * @return array
	 */
	public static function webmasters() {
		$out = array(
			'google' => array(
				'label'   => __( 'گوگل', 'hoosh-seo' ),
				'connected' => self::connected( 'gsc' ),
				'sitemap' => self::connected( 'gsc' ) ? self::sitemap_counts() : array(),
			),
			'bing'   => array(
				'label'   => 'Bing',
				'connected' => false,
				'note'    => __( 'برای آمار Bing، کلید Bing Webmaster را در تنظیمات پیشرفته وارد کنید.', 'hoosh-seo' ),
			),
		);
		$bing = \hoosh_seo()->settings->get( 'advanced.bing_key', '' );
		if ( $bing ) {
			$raw  = Helpers::http(
				'https://ssl.bing.com/webmaster/api.svc/json/GetAppSummary?siteUrl=' . rawurlencode( home_url( '/' ) ) . '&apikey=' . rawurlencode( (string) $bing ),
				array( 'timeout' => 12 )
			);
			$json = json_decode( (string) $raw['body'], true );
			if ( isset( $json['d'] ) ) {
				$out['bing']['connected'] = true;
				$out['bing']['stats']     = (array) $json['d'];
			}
		}
		return $out;
	}

	/**
	 * Sitemap index stats from GSC.
	 *
	 * @return array
	 */
	protected static function sitemap_counts() {
		$token = self::access_token( 'gsc' );
		if ( ! $token ) {
			return array();
		}
		$cache = get_transient( 'hoosh_gsc_sitemaps' );
		if ( is_array( $cache ) ) {
			return $cache;
		}
		$raw  = Helpers::http( 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( (string) \hoosh_seo()->settings->get( 'analytics.gsc.property' ) ) . '/sitemaps', array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ), 'timeout' => 15 ) );
		$json = json_decode( (string) $raw['body'], true );
		$out  = array();
		foreach ( (array) ( $json['sitemap'] ?? array() ) as $row ) {
			$out[] = array(
				'feed'      => (string) ( $row['path'] ?? '' ),
				'submitted' => (int) ( $row['contents'][0]['submitted'] ?? 0 ),
				'indexed'   => (int) ( $row['contents'][0]['indexed'] ?? 0 ),
				'status'    => (string) ( $row['lastDownload'] ?? '' ),
			);
		}
		if ( $out ) {
			set_transient( 'hoosh_gsc_sitemaps', $out, self::CACHE );
		}
		return $out;
	}
}

/*
 * Tiny sort helpers kept global so the closures above stay readable.
 */
if ( ! function_exists( __NAMESPACE__ . '\\usort_by_date' ) ) {
	/**
	 * Sort rows by date ascending.
	 *
	 * @param array $rows Rows (by reference).
	 */
	function usort_by_date( &$rows ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( (string) ( $a['date'] ?? '' ), (string) ( $b['date'] ?? '' ) );
			}
		);
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\usort_by_clicks' ) ) {
	/**
	 * Sort rows by clicks descending.
	 *
	 * @param array $rows Rows (by reference).
	 */
	function usort_by_clicks( &$rows ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) ( $b['clicks'] ?? 0 ) <=> (int) ( $a['clicks'] ?? 0 );
			}
		);
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\usort_by_position' ) ) {
	/**
	 * Sort rows by position descending (worst first).
	 *
	 * @param array $rows Rows (by reference).
	 */
	function usort_by_position( &$rows ) {
		usort(
			$rows,
			static function ( $a, $b ) {
				return (float) ( $b['position'] ?? 0 ) <=> (float) ( $a['position'] ?? 0 );
			}
		);
	}
}
