<?php
/**
 * Tiny fluent SELECT builder used by the REST layer and modules.
 *
 * @package HooshSEO
 */

namespace HooshSEO\DB;

use HooshSEO\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Query
 */
class Query {

	/**
	 * Fully qualified table name.
	 *
	 * @var string
	 */
	protected $table;

	/**
	 * Columns.
	 *
	 * @var string
	 */
	protected $columns = '*';

	/**
	 * Where clauses.
	 *
	 * @var array
	 */
	protected $where = array();

	/**
	 * Joins.
	 *
	 * @var array
	 */
	protected $join = array();

	/**
	 * Group by.
	 *
	 * @var string
	 */
	protected $group = '';

	/**
	 * Order by.
	 *
	 * @var string
	 */
	protected $order = '';

	/**
	 * Limit.
	 *
	 * @var int
	 */
	protected $limit = 0;

	/**
	 * Offset.
	 *
	 * @var int
	 */
	protected $offset = 0;

	/**
	 * Bindings, in placeholder order.
	 *
	 * @var array
	 */
	protected $bindings = array();

	/**
	 * Constructor.
	 *
	 * @param string $table Short table name.
	 */
	public function __construct( $table ) {
		$this->table = Database::table( $table );
	}

	/**
	 * Select columns.
	 *
	 * @param string $columns Raw column list (developer supplied, never user input).
	 * @return $this
	 */
	public function select( $columns ) {
		$this->columns = $columns;
		return $this;
	}

	/**
	 * Add a where clause.
	 *
	 * @param string $sql    Clause, e.g. "status = %s".
	 * @param mixed  ...$values Bindings.
	 * @return $this
	 */
	public function where( $sql ) {
		$values        = array_slice( func_get_args(), 1 );
		$this->where[] = $sql;
		foreach ( $values as $value ) {
			$this->bindings[] = $value;
		}
		return $this;
	}

	/**
	 * Add an OR group of clauses (already bracketed by the caller).
	 *
	 * @param string $sql Raw clause.
	 * @param array  $values Bindings.
	 * @return $this
	 */
	public function where_raw( $sql, $values = array() ) {
		$this->where[] = $sql;
		foreach ( (array) $values as $value ) {
			$this->bindings[] = $value;
		}
		return $this;
	}

	/**
	 * Join clause.
	 *
	 * @param string $sql Raw join SQL.
	 * @return $this
	 */
	public function join( $sql ) {
		$this->join[] = $sql;
		return $this;
	}

	/**
	 * Group by.
	 *
	 * @param string $sql Raw group SQL.
	 * @return $this
	 */
	public function group_by( $sql ) {
		$this->group = $sql;
		return $this;
	}

	/**
	 * Order by.
	 *
	 * @param string $sql Raw order SQL.
	 * @return $this
	 */
	public function order_by( $sql ) {
		$this->order = $sql;
		return $this;
	}

	/**
	 * Limit and offset.
	 *
	 * @param int $limit  Limit.
	 * @param int $offset Offset.
	 * @return $this
	 */
	public function limit( $limit, $offset = 0 ) {
		$this->limit  = (int) $limit;
		$this->offset = (int) $offset;
		return $this;
	}

	/**
	 * Assemble SQL.
	 *
	 * @param string $mode rows|count.
	 * @return string
	 */
	protected function build( $mode = 'rows' ) {
		global $wpdb;

		$columns = 'count' === $mode ? 'COUNT(*)' : $this->columns;
		$sql     = 'SELECT ' . $columns . ' FROM ' . $this->table;

		if ( $this->join ) {
			$sql .= ' ' . implode( ' ', $this->join );
		}
		if ( $this->where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $this->where );
		}
		if ( 'count' !== $mode ) {
			if ( $this->group ) {
				$sql .= ' GROUP BY ' . $this->group;
			}
			if ( $this->order ) {
				$sql .= ' ORDER BY ' . $this->order;
			}
			if ( $this->limit > 0 ) {
				$sql .= ' LIMIT ' . absint( $this->limit ) . ' OFFSET ' . absint( $this->offset );
			}
		}

		if ( $this->bindings ) {
			$sql = $wpdb->prepare( $sql, $this->bindings );
		}

		return $sql;
	}

	/**
	 * Rows as arrays.
	 *
	 * @return array
	 */
	public function get() {
		global $wpdb;
		$rows = $wpdb->get_results( $this->build(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * First row.
	 *
	 * @return array|null
	 */
	public function first() {
		$this->limit( 1 );
		$rows = $this->get();
		return isset( $rows[0] ) ? $rows[0] : null;
	}

	/**
	 * First value of the first row.
	 *
	 * @return mixed
	 */
	public function value() {
		global $wpdb;
		$this->limit( 1 );
		return $wpdb->get_var( $this->build() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count matching rows.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		return (int) $wpdb->get_var( $this->build( 'count' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
