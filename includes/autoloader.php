<?php
/**
 * PSR-4 style autoloader for the HooshSEO namespace (no Composer required).
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Maps HooshSEO\Sub\Class_Name to includes/Sub/Class_Name.php
 */
class Autoloader {

	/**
	 * Registration flag.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the autoloader.
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolve a class name to a file and require it.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function autoload( $class ) {
		if ( 0 !== strpos( $class, 'HooshSEO\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( 'HooshSEO\\' ) );
		$parts    = explode( '\\', $relative );
		$file     = array_pop( $parts );
		$path     = HOOSH_SEO_DIR . 'includes/' . implode( '/', array_map( 'ucfirst', $parts ) ) . '/' . self::filename( $file ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Convert a class name to its file name.
	 *
	 * @param string $file Class short name.
	 * @return string
	 */
	private static function filename( $file ) {
		return str_replace( '_', '', $file );
	}
}

/**
 * Namespaced accessor used across the plugin.
 *
 * A global `hoosh_seo()` also exists in the main file for third-party code.
 *
 * @return Plugin
 */
function hoosh_seo() {
	return Plugin::instance();
}
