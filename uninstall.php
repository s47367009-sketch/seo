<?php
/**
 * HooshSEO — uninstall routine.
 *
 * Runs only when the plugin is deleted from wp-admin/plugins.php (never on
 * deactivate). Data is removed only if the user asked for it in
 * Settings → General → «حذف داده‌ها هنگام حذف افزونه»; otherwise everything is
 * kept so a reinstall picks up where you left off.
 *
 * @package HooshSEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Capability gate: only a network admin (or a site admin) may wipe data.
 */
if ( ! current_user_can( 'delete_plugins' ) ) {
	return;
}

$hoosh_settings = get_option( 'hoosh_seo_settings', array() );
$hoosh_settings = is_array( $hoosh_settings ) ? $hoosh_settings : array();

$remove = false;
if ( isset( $hoosh_settings['general']['remove_data_on_uninstall'] ) ) {
	$remove = (bool) $hoosh_settings['general']['remove_data_on_uninstall'];
}
/* A single-file escape hatch for hosts that cannot open the Studio:
 * define( 'HOOSH_SEO_REMOVE_ON_UNINSTALL', true ); in wp-config.php. */
if ( defined( 'HOOSH_SEO_REMOVE_ON_UNINSTALL' ) ) {
	$remove = (bool) HOOSH_SEO_REMOVE_ON_UNINSTALL;
}

/* Scheduled events and in-flight locks always go away: nothing should keep
 * running for a plugin that is no longer installed. This file cannot use any
 * plugin class (the plugin is not loaded during uninstall). */
foreach ( array( 'hoosh_seo_daily', 'hoosh_seo_hourly', 'hoosh_seo_weekly', 'hoosh_seo_batch' ) as $hoosh_hook ) {
	wp_unschedule_hook( $hoosh_hook );
}
delete_option( 'hoosh_running_job' );
delete_transient( 'hoosh_automation_lock' );

if ( ! $remove ) {
	return;
}

global $wpdb;

/**
 * Clean one site (used per-blog on multisite).
 */
$hoosh_clean_site = function () use ( $wpdb ) {

	/* 1. Custom tables. */
	$tables = array(
		'redirects',
		'notfound',
		'keywords',
		'positions',
		'pages',
		'links',
		'link_log',
		'ai_jobs',
		'ai_log',
		'kw_research',
		'schema',
		'changelog',
		'crawl_log',
		'notices',
	);
	$known  = array();
	foreach ( $tables as $short ) {
		$known[] = $wpdb->prefix . 'hoosh_' . $short;
	}
	/* Anything that starts with our prefix, in case a future version added tables. */
	$like   = $wpdb->esc_like( $wpdb->prefix . 'hoosh_' ) . '%';
	$found  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	foreach ( (array) $found as $table ) {
		$known[] = $table;
	}
	$known = array_unique( array_filter( $known ) );
	foreach ( $known as $table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/* 2. Options (settings, keys, state, history). */
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'hoosh\\_%' OR option_name LIKE '\\_transient\\_hoosh\\_%' OR option_name LIKE '\\_transient\\_timeout\\_hoosh\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'hoosh_seo_db_version' OR option_name = 'hs_settings'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	wp_cache_flush();

	/* 3. Post meta. */
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_hs\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	/* 4. Term + user meta written by the plugin. */
	$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE 'hs\\_term\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'hoosh\\_notice\\_%' OR meta_key LIKE 'hoosh\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	/* 5. Scheduled events. */
	foreach ( array( 'hoosh_seo_daily', 'hoosh_seo_hourly', 'hoosh_seo_weekly', 'hoosh_seo_batch' ) as $hook ) {
		wp_unschedule_hook( $hook );
	}

	/* 6. Roles: drop our custom capability. */
	foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role && $role->has_cap( 'hoosh_seo_manage' ) ) {
			$role->remove_cap( 'hoosh_seo_manage' );
		}
	}

	/* 7. Files we own. */
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'hoosh-seo';
	hoosh_uninstall_rrmdir( $dir );
	foreach ( (array) glob( trailingslashit( $uploads['basedir'] ) . 'hoosh-report-*.html' ) as $report ) {
		@unlink( $report ); // phpcs:ignore
	}

	/* 8. robots.txt we physically wrote (never touch a file we did not create). */
	$robots = untrailingslashit( ABSPATH ) . '/robots.txt';
	if ( is_readable( $robots ) ) {
		$body = (string) file_get_contents( $robots ); // phpcs:ignore
		if ( false !== strpos( $body, 'admin-ajax.php?action=hoosh' ) ) {
			@unlink( $robots ); // phpcs:ignore
		}
	}

	/* 9. IndexNow key file. */
	$key = (string) get_option( 'hoosh_seo_indexnow_key', '' );
	if ( $key && preg_match( '/^[a-f0-9]{16,64}$/i', $key ) ) {
		$file = untrailingslashit( ABSPATH ) . '/' . $key . '.txt';
		if ( is_readable( $file ) ) {
			@unlink( $file ); // phpcs:ignore
		}
	}

	/* 10. Permalink rules owned by the Studio. */
	delete_option( 'hoosh_seo_rewrite_flushed' );
	flush_rewrite_rules();
};

/**
 * Recursive delete for folders we created ourselves.
 *
 * @param string $dir Absolute path.
 */
function hoosh_uninstall_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = (array) scandir( $dir );
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = trailingslashit( $dir ) . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			hoosh_uninstall_rrmdir( $path );
		} else {
			@unlink( $path ); // phpcs:ignore
		}
	}
	@rmdir( $dir ); // phpcs:ignore
};

if ( is_multisite() ) {
	$hoosh_blogs = get_sites( array( 'fields' => 'ids', 'number' => 1000 ) );
	foreach ( (array) $hoosh_blogs as $hoosh_blog_id ) {
		switch_to_blog( (int) $hoosh_blog_id );
		$hoosh_clean_site();
		restore_current_blog();
	}
} else {
	$hoosh_clean_site();
}

/**
 * Fires after HooshSEO removed all of its data.
 */
do_action( 'hoosh_seo_uninstalled' );
