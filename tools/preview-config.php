<?php
/**
 * Extracts the *real* window.HOOSH config from the plugin source, so the
 * preview is driven by the same data the WordPress page would embed instead
 * of a hand-typed approximation.
 *
 * Only the pure/static catalogues are reachable without a database; anything
 * that needs $wpdb or the options table is filled in by the preview server.
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/home/user/seo/' ); // the plugin's files bail out without it
define( 'HOOSH_SEO_VERSION', '1.1.1' );
define( 'HOOSH_SEO_DIR', '/home/user/seo/' );
define( 'HOOSH_SEO_FILE', '/home/user/seo/hoosh-seo.php' );
define( 'HOOSH_SEO_BASENAME', 'hoosh-seo/hoosh-seo.php' );
define( 'HOOSH_SEO_MIN_PHP', '7.3' );
define( 'HOOSH_SEO_APP_SLUG', 'hoosh-seo-studio' );
define( 'HOOSH_SEO_URL', 'http://localhost:8080/wp-content/plugins/hoosh-seo/' );

$stubs = array(
	'__'            => function ( $t, $d = null ) { return $t; },
	'esc_html__'    => function ( $t, $d = null ) { return $t; },
	'esc_html_e'    => function ( $t, $d = null ) { echo $t; },
	'esc_attr__'    => function ( $t, $d = null ) { return $t; },
	'esc_attr_e'    => function ( $t, $d = null ) { echo $t; },
	'_x'            => function ( $t, $c, $d = null ) { return $t; },
	'esc_html'      => function ( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); },
	'esc_attr'      => function ( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); },
	'esc_url'       => function ( $t ) { return $t; },
	'esc_url_raw'   => function ( $t ) { return $t; },
	'wp_kses_post'  => function ( $t ) { return $t; },
	'sanitize_text_field' => function ( $t ) { return is_string( $t ) ? trim( $t ) : $t; },
	'get_locale'    => function () { return 'fa_IR'; },
	'wp_timezone_string' => function () { return 'Asia/Tehran'; },
	'get_bloginfo'  => function ( $k = '' ) { return $k === 'version' ? '6.8.2' : 'نمایش هوش‌سئو'; },
	'home_url'      => function ( $p = '' ) { return 'http://localhost:8080' . $p; },
	'admin_url'     => function ( $p = '' ) { return 'http://localhost:8080/wp-admin/' . $p; },
	'rest_url'      => function ( $p = '' ) { return 'http://localhost:8080/wp-json/' . $p; },
	'wp_create_nonce' => function ( $a = '' ) { return 'preview-nonce'; },
	'get_option'    => function ( $k, $d = false ) { return $d; },
	'get_transient' => function ( $k ) { return false; },
	'is_multisite'  => function () { return false; },
	'current_user_can' => function ( $c ) { return true; },
	'get_current_user_id' => function () { return 1; },
	'get_avatar_url' => function ( $i = 0, $a = array() ) { return ''; },
	'wp_get_current_user' => function () {
		$u = new stdClass();
		$u->display_name = 'مدیر سایت';
		$u->user_email   = 'admin@example.com';
		return $u;
	},
	'apply_filters' => function ( $t, $v ) { return $v; },
	'do_action'     => function () {},
	'wp_json_encode' => function ( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); },
	'wp_parse_args' => function ( $a, $d = array() ) { return array_merge( (array) $d, (array) $a ); },
	'is_plugin_active' => function ( $p ) { return false; },
	'class_exists'  => 'class_exists',
	'wp_list_pluck' => function ( $l, $f ) { return array_map( function ( $i ) use ( $f ) { return is_object( $i ) ? $i->$f : $i[ $f ]; }, (array) $l ); },
);

foreach ( $stubs as $name => $impl ) {
	if ( ! function_exists( $name ) ) {
		eval( 'function ' . $name . '() { $a = func_get_args(); $f = $GLOBALS["__stubs"]["' . $name . '"]; return $f(...$a); }' );
	}
	$GLOBALS['__stubs'][ $name ] = $impl;
}


/**
 * KeywordResearch::sources() reads custom sources off the settings object at
 * line 196. Returning the default for every key yields the built-in catalogue
 * unchanged; no database is touched.
 */
if ( ! function_exists( 'hoosh_seo' ) ) {
	function hoosh_seo() {
		static $plugin = null;
		if ( null === $plugin ) {
			$settings        = new class {
				public function get( $key, $default = null ) { return $default; }
				public function all() { return array(); }
			};
			$plugin          = new stdClass();
			$plugin->settings = $settings;
		}
		return $plugin;
	}
}

require HOOSH_SEO_DIR . 'includes/autoloader.php';
\HooshSEO\Autoloader::register(); // the main plugin file normally does this

$out   = array();
$calls = array(
	'nav'            => array( 'HooshSEO\App', 'nav' ),
	'aiProviders'    => array( 'HooshSEO\AI\Providers', 'catalog' ),
	'aiTasks'        => array( 'HooshSEO\AI\Tasks', 'catalog' ),
	'keywordSources' => array( 'HooshSEO\Modules\KeywordResearch', 'sources' ),
	'schemaTypes'    => array( 'HooshSEO\Modules\Schema', 'types' ),
	'checks'         => array( 'HooshSEO\Modules\Content', 'check_catalog' ),
);

foreach ( $calls as $key => $cb ) {
	list( $class, $method ) = $cb;
	try {
		if ( ! class_exists( $class ) ) {
			$out[ $key ] = array( '__error' => 'class missing: ' . $class );
			continue;
		}
		$ref = new ReflectionMethod( $class, $method );
		if ( $ref->isStatic() ) {
			$out[ $key ] = $class::$method();
		} else {
			$obj = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
			$out[ $key ] = $obj->$method();
		}
	} catch ( Throwable $e ) {
		$out[ $key ] = array( '__error' => get_class( $e ) . ': ' . $e->getMessage() );
	}
}

fwrite( STDERR, "extracted keys: " . implode( ', ', array_keys( $out ) ) . "\n" );
foreach ( $out as $k => $v ) {
	$n = is_array( $v ) ? count( $v ) : 0;
	$err = ( is_array( $v ) && isset( $v['__error'] ) ) ? ' ERROR ' . $v['__error'] : '';
	fwrite( STDERR, sprintf( "  %-16s %3d entries%s\n", $k, $n, $err ) );
}

echo json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
