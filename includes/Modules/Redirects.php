<?php
/**
 * Redirect manager: 301/302/307/308/410/451, regex, auto-redirects, hit counting.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Modules;

use HooshSEO\Database;
use HooshSEO\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Class Redirects
 */
final class Redirects {

	/**
	 * Singleton.
	 *
	 * @var Redirects|null
	 */
	private static $instance = null;

	/**
	 * Runtime cache of the rule table.
	 *
	 * @var array|null
	 */
	private static $rules = null;

	/**
	 * Get instance.
	 *
	 * @return Redirects
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->bootstrap();
		}
		return self::$instance;
	}

	/**
	 * Admin-side hooks.
	 */
	public function bootstrap() {
		add_action( 'post_updated', array( $this, 'on_slug_change' ), 20, 3 );
		add_action( 'wp_trash_post', array( $this, 'on_trash' ), 20 );
		add_action( 'before_delete_post', array( $this, 'on_trash' ), 20 );
		add_action( 'delete_attachment', array( $this, 'on_trash' ), 20 );
		add_action( 'hoosh_seo_prune_logs', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Front-side hooks (only on the front end).
	 */
	public function bootstrap_front() {
		if ( ! \hoosh_seo()->settings->get( 'redirects.enabled', true ) ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );
	}

	/**
	 * Load every active rule (cached per request + transient).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$rules ) {
			return self::$rules;
		}
		$ttl = (int) \hoosh_seo()->settings->get( 'redirects.cache_ttl', 300 );
		self::$rules = $ttl > 0
			? (array) Helpers::cache( 'redirect-rules', array( __CLASS__, 'load' ), $ttl )
			: self::load();
		return self::$rules;
	}

	/**
	 * Read the table.
	 *
	 * @return array
	 */
	public static function load() {
		if ( ! Database::exists( 'redirects' ) ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, source, target, type, is_regex, status, hits, group_name FROM " . Database::table( 'redirects' ) . " WHERE status = 'active' ORDER BY is_regex ASC, id ASC", ARRAY_A ); // phpcs:ignore
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Invalidate the rule cache after a write.
	 */
	public static function flush() {
		self::$rules = null;
		Helpers::cache_flush( 'redirect-rules' );
	}

	/**
	 * Match a requested path against the rules.
	 *
	 * @param string $path Path with query string.
	 * @return array|null matched rule
	 */
	public static function find( $path ) {
		$path  = (string) $path;
		$plain = wp_parse_url( $path, PHP_URL_PATH ) ? (string) wp_parse_url( $path, PHP_URL_PATH ) : $path;
		$query = (string) wp_parse_url( $path, PHP_URL_QUERY );

		$normalized = untrailingslashit( '/' . ltrim( strtolower( $plain ), '/' ) );
		$with_slash = trailingslashit( '/' . ltrim( strtolower( $plain ), '/' ) );

		$strip = (array) \hoosh_seo()->settings->get( 'redirects.strip_get', array() );

		foreach ( self::all() as $rule ) {
			$source = (string) $rule['source'];
			$hit    = false;

			if ( ! empty( $rule['is_regex'] ) ) {
				$pattern = self::regex_pattern( $source );
				if ( $pattern && preg_match( $pattern, $plain, $m ) ) {
					$hit       = true;
					$captures  = array_slice( $m );
				}
			} else {
				$source_norm = untrailingslashit( '/' . ltrim( strtolower( $source ), '/' ) );
				$hit         = in_array( $normalized, array( $source_norm, untrailingslashit( $source_norm ) ), true )
					|| untrailingslashit( $with_slash ) === $source_norm
					|| rtrim( $plain, '/' ) === rtrim( $source, '/' );
			}

			if ( ! $hit ) {
				continue;
			}

			$target = (string) $rule['target'];

			// Back-references for regex rules.
			if ( ! empty( $rule['is_regex'] ) && ! empty( $captures ) ) {
				foreach ( $captures as $i => $value ) {
					$target = str_replace( '$' . ( $i + 1 ), (string) $value, $target );
				}
			}

			if ( '' === $target ) {
				return null;
			}

			// Keep or strip the query string.
			if ( '' !== $query && false === strpos( $target, '?' ) ) {
				if ( $strip ) {
					parse_str( $query, $parts );
					foreach ( $strip as $arg ) {
						unset( $parts[ $arg ] );
					}
					$query = http_build_query( $parts );
				}
				if ( '' !== $query ) {
					$target .= '?' . $query;
				}
			}

			return array(
				'id'     => (int) $rule['id'],
				'target' => $target,
				'type'   => (string) $rule['type'],
				'rule'   => $rule,
			);
		}

		return null;
	}

	/**
	 * Build a safe PCRE from a user regex source.
	 *
	 * @param string $source Raw regex-ish source.
	 * @return string
	 */
	public static function regex_pattern( $source ) {
		$source = trim( (string) $source );
		if ( '' === $source ) {
			return '';
		}
		// Already delimited.
		if ( preg_match( '#^([#%~/]).*\\1[iimsuxADUXJ]*$#', $source ) ) {
			return $source;
		}
		$delimited = '#' . str_replace( '#', '\#', $source ) . '#i';
		// Validate: never let a broken regex fatals the site.
		$valid = @preg_match( $delimited, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $valid ? '' : $delimited;
	}

	/**
	 * Redirect the current request if a rule matches.
	 */
	public function maybe_redirect() {
		if ( is_admin() || is_robots() || defined( 'DOING_AJAX' ) ) {
			return;
		}

		$settings = \hoosh_seo()->settings;
		$path     = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $path ) {
			return;
		}

		// Never redirect our own routes.
		if ( preg_match( '#^/(sitemap|llms|index\.php|wp-json|wp-admin|wp-login|xmlrpc)#i', $path ) ) {
			return;
		}

		// Canonical host/protocol fixes first.
		$canonical = $this->canonical_target( $path );
		if ( $canonical ) {
			$this->emit( $canonical, 301, 0 );
		}

		$rule = self::find( $path );
		if ( $rule ) {
			self::hit( (int) $rule['id'] );
			$this->emit( $rule['target'], (int) $rule['type'], (int) $rule['id'] );
		}
	}

	/**
	 * Force HTTPS / www canonicalisation.
	 *
	 * @param string $path Request path.
	 * @return string|false
	 */
	protected function canonical_target( $path ) {
		$settings = \hoosh_seo()->settings;
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$target   = '';

		if ( $settings->get( 'redirects.force_https', false ) && ! is_ssl() ) {
			$target = 'https://' . $host . $path;
		}

		if ( '' === $target ) {
			$mode = (string) $settings->get( 'redirects.www_mode', 'keep' );
			$bare = preg_replace( '/^www\./', '', $host );
			if ( 'force_www' === $mode && $host && 0 !== strpos( $host, 'www.' ) ) {
				$target = ( is_ssl() ? 'https://' : 'http://' ) . 'www.' . $host . $path;
			} elseif ( 'strip_www' === $mode && 0 === strpos( $host, 'www.' ) ) {
				$target = ( is_ssl() ? 'https://' : 'http://' ) . $bare . $path;
			}
		}

		if ( '' === $target && $settings->get( 'redirects.auto_slash', true ) ) {
			$plain = (string) wp_parse_url( $path, PHP_URL_PATH );
			$has_extension = (bool) preg_match( '/\.[a-z0-9]{2,5}$/i', $plain );
			if ( ! $has_extension && '/' !== substr( $plain, -1 ) && '' === (string) wp_parse_url( $path, PHP_URL_QUERY ) ) {
				$home = untrailingslashit( home_url() );
				if ( 0 === strpos( $home, 'http' ) && false === strpos( $plain, 'index.php' ) ) {
					$target = trailingslashit( $home . $plain );
				}
			}
		}

		return $target ? esc_url_raw( $target ) : false;
	}

	/**
	 * Send the redirect (or just report it, when testing).
	 *
	 * @param string $target Target.
	 * @param int    $code   Status code.
	 * @param int    $rule_id Rule id.
	 */
	protected function emit( $target, $code, $rule_id = 0 ) {
		if ( ! headers_sent() ) {
			status_header( $code ?: 301 );
			if ( in_array( $code, array( 410, 451 ), true ) ) {
				header( 'X-Robots-Tag: noindex' );
				exit;
			}
			header( 'X-Redirect-By: HooshSEO' );
			header( 'X-Redirect-Rule: ' . (int) $rule_id );
			wp_redirect( esc_url_raw( $target ), $code ?: 301 ); // phpcs:ignore
			exit;
		}
	}

	/**
	 * Count a hit (throttled to avoid write storms).
	 *
	 * @param int $id Rule id.
	 */
	public static function hit( $id ) {
		if ( ! $id || ! \hoosh_seo()->settings->get( 'redirects.header', true ) ) {
			return;
		}
		if ( ! get_transient( 'hoosh_hit_' . $id ) ) {
			set_transient( 'hoosh_hit_' . $id, 1, 60 );
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Database::table( 'redirects' ) . ' SET hits = hits + 1 WHERE id = %d', $id ) ); // phpcs:ignore
		}
	}

	/**
	 * Create a rule.
	 *
	 * @param array $data Rule data.
	 * @return int|false ID.
	 */
	public static function create( $data ) {
		global $wpdb;
		$source = isset( $data['source'] ) ? trim( (string) $data['source'] ) : '';
		if ( '' === $source ) {
			return false;
		}

		$row = array(
			'source'     => mb_substr( $source, 0, 500 ),
			'target'     => mb_substr( Helpers::sanitize_url( isset( $data['target'] ) ? $data['target'] : '' ), 0, 500 ),
			'type'       => in_array( (string) ( $data['type'] ?? '301' ), array( '301', '302', '307', '308', '410', '451' ), true ) ? (string) $data['type'] : '301',
			'is_regex'   => ! empty( $data['is_regex'] ) ? 1 : 0,
			'status'     => in_array( (string) ( $data['status'] ?? 'active' ), array( 'active', 'inactive', 'regex' ), true ) ? (string) $data['status'] : 'active',
			'notes'      => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : '',
			'group_name' => isset( $data['group'] ) ? sanitize_text_field( (string) $data['group'] ) : 'default',
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		);

		if ( $row['is_regex'] && ! self::regex_pattern( $row['source'] ) ) {
			return new \WP_Error( 'bad_regex', __( 'عبارت منظم نامعتبر است.', 'hoosh-seo' ) );
		}

		// One source, one rule: update in place.
		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Database::table( 'redirects' ) . ' WHERE source = %s', $row['source'] ) ); // phpcs:ignore
		if ( $existing ) {
			$wpdb->update( Database::table( 'redirects' ), $row, array( 'id' => (int) $existing ) ); // phpcs:ignore
			self::flush();
			return (int) $existing;
		}

		$wpdb->insert( Database::table( 'redirects' ), $row ); // phpcs:ignore
		self::flush();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a rule.
	 *
	 * @param int   $id   ID.
	 * @param array $data Data.
	 * @return bool
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$row = array();
		$map = array(
			'source'   => 'text',
			'target'   => 'url',
			'type'     => 'type',
			'status'   => 'text',
			'notes'    => 'textarea',
			'group_name' => 'text',
			'is_regex' => 'bool',
		);
		foreach ( $map as $column => $kind ) {
			$key = 'group_name' === $column ? 'group' : $column;
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			switch ( $kind ) {
				case 'url':
					$row[ $column ] = Helpers::sanitize_url( $data[ $key ] );
					break;
				case 'bool':
					$row[ $column ] = empty( $data[ $key ] ) ? 0 : 1;
					break;
				case 'type':
					$row[ $column ] = in_array( (string) $data[ $key ], array( '301', '302', '307', '308', '410', '451' ), true ) ? (string) $data[ $key ] : '301';
					break;
				case 'textarea':
					$row[ $column ] = sanitize_textarea_field( (string) $data[ $key ] );
					break;
				default:
					$row[ $column ] = sanitize_text_field( (string) $data[ $key ] );
			}
		}
		if ( ! $row ) {
			return false;
		}
		$row['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( Database::table( 'redirects' ), $row, array( 'id' => absint( $id ) ) ); // phpcs:ignore
		self::flush();
		return true;
	}

	/**
	 * Delete rules.
	 *
	 * @param array $ids IDs.
	 * @return int
	 */
	public static function remove( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( 'redirects' ) . ' WHERE id IN (' . $in . ')', $ids ) ); // phpcs:ignore
		self::flush();
		return count( $ids );
	}

	/**
	 * Paginated list for the Studio.
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function listing( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'search' => '',
				'status' => '',
				'group'  => '',
				'orderby' => 'id',
				'order'  => 'DESC',
				'per'    => 30,
				'paged'  => 1,
			)
		);

		$where  = '1=1';
		$params = array();
		if ( '' !== $args['search'] ) {
			$where .= ' AND (source LIKE %s OR target LIKE %s OR notes LIKE %s)';
			$like  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			array_push( $params, $like, $like, $like );
		}
		if ( '' !== $args['status'] ) {
			$where .= ' AND status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['group'] ) {
			$where .= ' AND group_name = %s';
			$params[] = $args['group'];
		}

		$allowed_order = array( 'id', 'hits', 'source', 'created_at', 'updated_at' );
		$orderby       = in_array( $args['orderby'], $allowed_order, true ) ? $args['orderby'] : 'id';
		$order         = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$table  = Database::table( 'redirects' );
		$total  = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) : "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore
		$per    = max( 5, min( 200, (int) $args['per'] ) );
		$offset = ( max( 1, (int) $args['paged'] ) - 1 ) * $per;
		$sql    = $params ? $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", array_merge( $params, array( $per, $offset ) ) ) : "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT {$per} OFFSET {$offset}"; // phpcs:ignore
		$rows   = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore

		$groups = (array) $wpdb->get_col( "SELECT DISTINCT group_name FROM {$table} ORDER BY group_name" ); // phpcs:ignore

		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
			'groups' => array_values( array_filter( $groups ) ),
			'stats' => array(
				'active'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status='active'" ), // phpcs:ignore
				'hits'    => (int) $wpdb->get_var( "SELECT COALESCE(SUM(hits),0) FROM {$table}" ), // phpcs:ignore
				'regex'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_regex=1" ), // phpcs:ignore
				'top'     => (array) $wpdb->get_results( "SELECT source, hits FROM {$table} ORDER BY hits DESC LIMIT 10", ARRAY_A ), // phpcs:ignore
			),
		);
	}

	/**
	 * Import from CSV / Redirection / Yoast style files.
	 *
	 * @param string $csv    CSV content.
	 * @param array  $map    Column map.
	 * @param bool   $dry    Preview only.
	 * @return array
	 */
	public static function import_csv( $csv, $map = array(), $dry = false ) {
		$lines = preg_split( '/\r\n|\r|\n/', trim( (string) $csv ) );
		if ( ! $lines ) {
			return array( 'imported' => 0, 'skipped' => 0, 'errors' => array( __( 'فایل خالی است.', 'hoosh-seo' ) ) );
		}

		$delimiter = ',';
		if ( false !== strpos( $lines[0], ';' ) && false === strpos( $lines[0], ',' ) ) {
			$delimiter = ';';
		}

		$header = str_getcsv( array_shift( $lines ), $delimiter );
		$header = array_map(
			function ( $col ) {
				return strtolower( trim( (string) $col, " \t\n\r\0\x0B\"'" ) );
			},
			$header
		);

		$defaults = array(
			'source' => self::find_column( $header, array( 'source', 'url', 'old', 'from', 'regex', '404' ) ),
			'target' => self::find_column( $header, array( 'target', 'destination', 'to', 'new', 'redirect' ) ),
			'type'   => self::find_column( $header, array( 'code', 'type', 'status', 'statuscode' ) ),
			'regex'  => self::find_column( $header, array( 'regex', 'ispattern', 'pattern' ) ),
		);
		$map = wp_parse_args( (array) $map, $defaults );

		$imported = 0;
		$skipped  = 0;
		$errors   = array();
		$sample   = array();

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cols = str_getcsv( $line, $delimiter );
			$source = isset( $cols[ $map['source'] ] ) ? trim( (string) $cols[ $map['source'] ] ) : '';
			if ( '' === $source ) {
				$skipped++;
				continue;
			}
			$source = Helpers::rel_url( Helpers::abs_url( $source ) );
			$target = isset( $map['target'] ) && isset( $cols[ $map['target'] ] ) ? trim( (string) $cols[ $map['target'] ] ) : '';
			$type   = isset( $map['type'] ) && isset( $cols[ $map['type'] ] ) ? preg_replace( '/\D/', '', (string) $cols[ $map['type'] ] ) : '301';
			$type   = $type && in_array( $type, array( '301', '302', '307', '308', '410', '451' ), true ) ? $type : '301';
			$regex  = ! empty( $map['regex'] ) && ! empty( $cols[ $map['regex'] ] ) && in_array( strtolower( (string) $cols[ $map['regex'] ] ), array( '1', 'true', 'yes', 'on' ), true );

			if ( '' === $target && ! in_array( $type, array( '410', '451' ), true ) ) {
				$skipped++;
				continue;
			}

			$sample[] = array( 'source' => $source, 'target' => $target, 'type' => $type, 'regex' => $regex );
			$imported++;

			if ( ! $dry ) {
				$created = self::create(
					array(
						'source'   => $source,
						'target'   => $target,
						'type'     => $type,
						'is_regex' => $regex,
						'group'    => 'import',
						'notes'    => __( 'واردشده از CSV', 'hoosh-seo' ),
					)
				);
				if ( is_wp_error( $created ) ) {
					$errors[] = $source . ': ' . $created->get_error_message();
					$imported--;
				}
			}
			if ( $imported >= 20000 ) {
				break;
			}
		}

		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => array_slice( $errors, 0, 30 ),
			'header'   => $header,
			'map'      => $map,
			'sample'   => array_slice( $sample, 0, 20 ),
			'dry'      => $dry,
		);
	}

	/**
	 * Locate a column index by candidate names.
	 *
	 * @param array $header    Header labels.
	 * @param array $candidates Candidate names.
	 * @return int
	 */
	protected static function find_column( $header, $candidates ) {
		foreach ( $candidates as $candidate ) {
			foreach ( $header as $index => $label ) {
				if ( false !== strpos( (string) $label, $candidate ) ) {
					return $index;
				}
			}
		}
		return 0;
	}

	/**
	 * Auto 301 when a slug changes.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post_after New.
	 * @param \WP_Post $post_before Old.
	 */
	public function on_slug_change( $post_id, $post_after, $post_before ) {
		if ( ! \hoosh_seo()->settings->get( 'redirects.auto_post_redirect', true ) ) {
			return;
		}
		if ( ! $post_before || ! $post_after ) {
			return;
		}
		if ( $post_before->post_name === $post_after->post_name && $post_before->post_type === $post_after->post_type ) {
			return;
		}
		if ( 'publish' !== $post_after->post_status ) {
			return;
		}

		$old = get_permalink( $post_before );
		$old = Helpers::rel_url( (string) $old );
		$old = preg_replace( '#/[0-9]{4}/[0-9]{2}/#', '/', $old );
		$new = get_permalink( $post_after );

		if ( ! $old || $old === $new || '/' === $old ) {
			return;
		}

		self::create(
			array(
				'source' => $old,
				'target' => $new,
				'type'   => '301',
				'group'  => 'auto',
				'notes'  => __( 'تغییر خودکار به‌خاطر تغییر پیوند یکتا', 'hoosh-seo' ),
			)
		);
	}

	/**
	 * Auto 301/410 when a post is trashed or deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_trash( $post_id ) {
		$settings = \hoosh_seo()->settings;
		if ( ! $settings->get( 'redirects.auto_trashed', true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$url = Helpers::rel_url( (string) get_permalink( $post ) );
		if ( ! $url || '/' === $url ) {
			return;
		}

		if ( 'attachment' === $post->post_type && 'redirect' === $settings->get( 'redirects.attachments', 'redirect' ) ) {
			$parent = $post->post_parent ? get_permalink( $post->post_parent ) : home_url( '/' );
			self::create(
				array(
					'source' => $url,
					'target' => Helpers::rel_url( (string) $parent ),
					'type'   => '301',
					'group'  => 'attachment',
					'notes'  => __( 'هدایت پیوست به صفحه والد', 'hoosh-seo' ),
				)
			);
			return;
		}

		$replacement = (string) $settings->get( 'redirects.trashed_target', '' );
		if ( '' !== $replacement ) {
			self::create( array( 'source' => $url, 'target' => $replacement, 'type' => '301', 'group' => 'auto', 'notes' => __( 'حذف محتوا', 'hoosh-seo' ) ) );
			return;
		}

		// Leave a 410 so search engines drop it quickly.
		if ( ! $settings->get( 'redirects.fallback_home', false ) ) {
			return;
		}
		self::create( array( 'source' => $url, 'target' => home_url( '/' ), 'type' => '301', 'group' => 'auto', 'notes' => __( 'حذف محتوا — هدایت به خانه', 'hoosh-seo' ) ) );
	}

	/**
	 * Whether a URL is already handled by our rules (so WP canonical stays out).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function owns( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( ! $path ) {
			return false;
		}
		foreach ( self::all() as $rule ) {
			if ( ! empty( $rule['is_regex'] ) ) {
				continue;
			}
			if ( untrailingslashit( $path ) === untrailingslashit( (string) $rule['source'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Keep the table bounded.
	 */
	public static function prune() {
		global $wpdb;
		$max = (int) \hoosh_seo()->settings->get( 'redirects.log_limit', 10000 );
		if ( $max <= 0 ) {
			return 0;
		}
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Database::table( 'redirects' ) ); // phpcs:ignore
		if ( $count <= $max ) {
			return 0;
		}
		$excess = $count - $max;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Database::table( 'redirects' ) . ' WHERE hits = 0 AND group_name = %s ORDER BY id ASC LIMIT %d', 'auto', $excess ) ); // phpcs:ignore
		self::flush();
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Test a path against the rules without redirecting (Studio "آزمایش").
	 *
	 * @param string $path Path.
	 * @return array
	 */
	public static function test( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return array( 'hit' => false, 'message' => __( 'نشانی را وارد کنید.', 'hoosh-seo' ) );
		}
		if ( ! preg_match( '#^/#', $path ) ) {
			$path = Helpers::rel_url( Helpers::abs_url( $path ) );
		}
		$rule = self::find( $path );
		if ( ! $rule ) {
			return array(
				'hit' => false,
				'path' => $path,
				'message' => __( 'هیچ قاعده‌ای با این نشانی مطابقت نداشت.', 'hoosh-seo' ),
				'suggestions' => self::similar( $path ),
			);
		}
		return array(
			'hit' => true,
			'path' => $path,
			'rule' => $rule['rule'],
			'to'   => $rule['target'],
			'code' => $rule['type'],
		);
	}

	/**
	 * Near misses for the test panel.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	protected static function similar( $path ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( trim( $path, '/' ) ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source, target, type FROM ' . Database::table( 'redirects' ) . ' WHERE source LIKE %s ORDER BY hits DESC LIMIT 5', $like ) ); // phpcs:ignore
		return array_map(
			function ( $row ) {
				return (array) $row;
			},
			(array) $rows
		);
	}

	/**
	 * The redirect the metabox owns for this post (source = its own URL).
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function for_post( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( ! $post_id || ! Database::exists( 'redirects' ) ) {
			return array();
		}
		$source = Helpers::rel_url( (string) get_permalink( $post_id ) );
		$rows   = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . Database::table( 'redirects' ) . ' WHERE source = %s ORDER BY id DESC',
				$source
			),
			ARRAY_A
		); // phpcs:ignore
		foreach ( $rows as &$row ) {
			$row['id']    = (int) $row['id'];
			$row['type']   = (string) $row['type'];
			$row['hits']   = (int) $row['hits'];
			$row['source'] = (string) $row['source'];
			$row['target'] = (string) $row['target'];
		}
		unset( $row );
		return $rows;
	}

}
