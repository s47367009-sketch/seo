<?php
/**
 * Database schema, install/uninstall and query helper factory.
 *
 * @package HooshSEO
 */

namespace HooshSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Class Database
 */
class Database {

	/**
	 * Table prefix for this plugin.
	 *
	 * @return string
	 */
	public static function prefix() {
		global $wpdb;
		return $wpdb->prefix . 'hoosh_';
	}

	/**
	 * Full table name for a short name.
	 *
	 * @param string $name Short table name (whitelisted).
	 * @return string
	 */
	public static function table( $name ) {
		$allowed = self::tables();
		if ( ! in_array( $name, $allowed, true ) ) {
			$name = $allowed[0];
		}
		return self::prefix() . $name;
	}

	/**
	 * All short table names.
	 *
	 * @return array
	 */
	public static function tables() {
		return array(
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
	}

	/**
	 * Create / upgrade all tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = self::prefix();

		$sql = array();

		$sql[] = "CREATE TABLE {$p}redirects (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(500) NOT NULL DEFAULT '',
			target VARCHAR(500) NOT NULL DEFAULT '',
			type VARCHAR(10) NOT NULL DEFAULT '301',
			is_regex TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			notes TEXT NULL,
			group_name VARCHAR(100) NOT NULL DEFAULT 'default',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY source (source(190)),
			KEY status (status)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}notfound (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(500) NOT NULL DEFAULT '',
			hash CHAR(32) NOT NULL DEFAULT '',
			status_code INT(11) NOT NULL DEFAULT 404,
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			referer VARCHAR(500) NOT NULL DEFAULT '',
			ip VARCHAR(45) NOT NULL DEFAULT '',
			hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			state VARCHAR(20) NOT NULL DEFAULT 'new',
			redirect_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			first_seen DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_seen DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY hash (hash),
			KEY state (state),
			KEY last_seen (last_seen)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}keywords (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			phrase VARCHAR(255) NOT NULL DEFAULT '',
			norm VARCHAR(255) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			url VARCHAR(500) NOT NULL DEFAULT '',
			volume INT(11) NOT NULL DEFAULT 0,
			difficulty INT(11) NOT NULL DEFAULT 0,
			cpc DECIMAL(10,2) NOT NULL DEFAULT 0,
			intent VARCHAR(20) NOT NULL DEFAULT '',
			position DECIMAL(6,2) NOT NULL DEFAULT 0,
			prev_position DECIMAL(6,2) NOT NULL DEFAULT 0,
			clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			impressions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			device VARCHAR(10) NOT NULL DEFAULT 'all',
			lang VARCHAR(5) NOT NULL DEFAULT 'fa',
			source VARCHAR(40) NOT NULL DEFAULT 'manual',
			kind VARCHAR(20) NOT NULL DEFAULT 'focus',
			state VARCHAR(20) NOT NULL DEFAULT 'saved',
			competition VARCHAR(12) NOT NULL DEFAULT '',
			tags LONGTEXT NULL,
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY norm (norm),
			KEY post_id (post_id),
			KEY position (position),
			KEY state (state, kind)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}positions (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			recorded_on DATE NOT NULL DEFAULT '1970-01-01',
			position DECIMAL(6,2) NOT NULL DEFAULT 0,
			prev_position DECIMAL(6,2) NOT NULL DEFAULT 0,
			change DECIMAL(6,2) NOT NULL DEFAULT 0,
			url VARCHAR(500) NOT NULL DEFAULT '',
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			impressions BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			device VARCHAR(10) NOT NULL DEFAULT 'all',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY keyword_id (keyword_id, recorded_on),
			KEY recorded_on (recorded_on)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}pages (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			url VARCHAR(500) NOT NULL DEFAULT '',
			hash CHAR(32) NOT NULL DEFAULT '',
			type VARCHAR(40) NOT NULL DEFAULT 'post',
			title VARCHAR(255) NOT NULL DEFAULT '',
			description LONGTEXT NULL,
			keywords LONGTEXT NULL,
			word_count INT(11) NOT NULL DEFAULT 0,
			links_out INT(11) NOT NULL DEFAULT 0,
			links_in INT(11) NOT NULL DEFAULT 0,
			score DECIMAL(5,2) NOT NULL DEFAULT 0,
			seo_score DECIMAL(5,2) NOT NULL DEFAULT 0,
			readability DECIMAL(5,2) NOT NULL DEFAULT 0,
			content_hash CHAR(32) NOT NULL DEFAULT '',
			issues LONGTEXT NULL,
			suggestions LONGTEXT NULL,
			ai_summary LONGTEXT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'pending',
			scanned_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY hash (hash),
			KEY score (score),
			KEY state (state),
			KEY type (type)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}links (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			keyword VARCHAR(255) NOT NULL DEFAULT '',
			norm VARCHAR(255) NOT NULL DEFAULT '',
			url VARCHAR(500) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			target_title VARCHAR(255) NOT NULL DEFAULT '',
			max_links INT(11) NOT NULL DEFAULT 1,
			link_nth INT(11) NOT NULL DEFAULT 1,
			case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
			skip_own TINYINT(1) NOT NULL DEFAULT 1,
			new_tab TINYINT(1) NOT NULL DEFAULT 0,
			direct TINYINT(1) NOT NULL DEFAULT 1,
			nofollow TINYINT(1) NOT NULL DEFAULT 0,
			bold TINYINT(1) NOT NULL DEFAULT 0,
			include_types LONGTEXT NULL,
			include_terms LONGTEXT NULL,
			exclude_terms LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			clicks BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			click_threshold INT(11) NOT NULL DEFAULT 0,
			notified TINYINT(1) NOT NULL DEFAULT 0,
			notify_email VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY norm (norm),
			KEY status (status),
			KEY post_id (post_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}link_log (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			referer VARCHAR(500) NOT NULL DEFAULT '',
			valid TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY link_id (link_id, created_at),
			KEY created_at (created_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}ai_jobs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(40) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			payload LONGTEXT NULL,
			result LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			progress INT(11) NOT NULL DEFAULT 0,
			total INT(11) NOT NULL DEFAULT 0,
			error TEXT NULL,
			attempts INT(11) NOT NULL DEFAULT 0,
			batch_id CHAR(32) NOT NULL DEFAULT '',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			applied TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY type (type),
			KEY batch_id (batch_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}ai_log (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			provider VARCHAR(40) NOT NULL DEFAULT '',
			model VARCHAR(80) NOT NULL DEFAULT '',
			task VARCHAR(40) NOT NULL DEFAULT '',
			prompt_tokens INT(11) NOT NULL DEFAULT 0,
			completion_tokens INT(11) NOT NULL DEFAULT 0,
			cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			latency_ms INT(11) NOT NULL DEFAULT 0,
			cached TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'ok',
			message TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY provider (provider)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}kw_research (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			seed VARCHAR(255) NOT NULL DEFAULT '',
			lang VARCHAR(5) NOT NULL DEFAULT 'fa',
			sources LONGTEXT NULL,
			depth INT(11) NOT NULL DEFAULT 1,
			prefixes LONGTEXT NULL,
			suffixes LONGTEXT NULL,
			items LONGTEXT NULL,
			stats LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY seed (seed),
			KEY created_at (created_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}schema (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			schema_type VARCHAR(60) NOT NULL DEFAULT '',
			data LONGTEXT NULL,
			conditions LONGTEXT NULL,
			location VARCHAR(20) NOT NULL DEFAULT 'head',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			print_count INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status),
			KEY schema_type (schema_type)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}changelog (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			scope VARCHAR(40) NOT NULL DEFAULT '',
			action VARCHAR(40) NOT NULL DEFAULT '',
			post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			url VARCHAR(500) NOT NULL DEFAULT '',
			before_data LONGTEXT NULL,
			after_data LONGTEXT NULL,
			applied TINYINT(1) NOT NULL DEFAULT 1,
			reverted TINYINT(1) NOT NULL DEFAULT 0,
			batch_id CHAR(32) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			note TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY scope (scope),
			KEY post_id (post_id),
			KEY batch_id (batch_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}crawl_log (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			bot_key VARCHAR(40) NOT NULL DEFAULT '',
			bot_name VARCHAR(80) NOT NULL DEFAULT '',
			bot_ip VARCHAR(45) NOT NULL DEFAULT '',
			path VARCHAR(500) NOT NULL DEFAULT '',
			purpose VARCHAR(20) NOT NULL DEFAULT 'visit',
			ua VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY bot_key (bot_key, created_at),
			KEY created_at (created_at)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}notices (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			key_name VARCHAR(64) NOT NULL DEFAULT '',
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			title VARCHAR(255) NOT NULL DEFAULT '',
			message TEXT NULL,
			action_label VARCHAR(120) NOT NULL DEFAULT '',
			action_url VARCHAR(400) NOT NULL DEFAULT '',
			dismissed TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY key_name (key_name)
		) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		update_option( 'hoosh_seo_db_version', HOOSH_SEO_VERSION, false );
	}

	/**
	 * Drop every table (uninstall only).
	 */
	public static function uninstall_all() {
		global $wpdb;
		$p = self::prefix();
		foreach ( self::tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $p . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Start a small fluent query.
	 *
	 * @param string $table Short table name.
	 * @return DB\Query
	 */
	public static function query( $table ) {
		return new DB\Query( $table );
	}

	/**
	 * Delete every row of a plugin table.
	 *
	 * @param string $table Short table name.
	 */
	public static function truncate( $table ) {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Does a plugin table exist?
	 *
	 * @param string $table Short table name.
	 * @return bool
	 */
	public static function exists( $table ) {
		global $wpdb;
		$name = self::table( $table );
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
	}

	/**
	 * Row counts per table, for the tools screen.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$out = array();
		foreach ( self::tables() as $table ) {
			$out[ $table ] = 0;
			if ( self::exists( $table ) ) {
				$out[ $table ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		return $out;
	}
}
