<?php
/**
 * Agent memory: the run ledger plus what the agent has learned.
 *
 * Two different things live here on purpose:
 *
 *  1. The ledger — immutable record of every step, so any change can be
 *     inspected and undone. This is an audit trail, not a cache.
 *  2. Episodic memory — small weighted facts ("this site ranks for X",
 *     "meta rewrites on /blog raised CTR") that the planner reads so the
 *     agent gets better at this particular site instead of starting from
 *     zero every night.
 *
 * @package HooshSEO
 */

namespace HooshSEO\Agent;

use HooshSEO\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Class Memory
 */
final class Memory {

	/**
	 * Open a run row.
	 *
	 * @param array $args {goal, mode, triggered_by}.
	 * @return int Run id.
	 */
	public static function start_run( $args = array() ) {
		global $wpdb;
		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			Database::table( 'agent_runs' ),
			array(
				'goal'         => isset( $args['goal'] ) ? mb_substr( (string) $args['goal'], 0, 250 ) : '',
				'mode'         => isset( $args['mode'] ) ? sanitize_key( $args['mode'] ) : 'auto',
				'triggered_by' => isset( $args['triggered_by'] ) ? sanitize_key( $args['triggered_by'] ) : 'cron',
				'status'       => 'running',
				'phase'        => 'observe',
				'created_by'   => get_current_user_id(),
				'started_at'   => $now,
				'created_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a run row.
	 *
	 * @param int   $run_id Run.
	 * @param array $data   Columns.
	 */
	public static function update_run( $run_id, $data ) {
		global $wpdb;
		$wpdb->update( Database::table( 'agent_runs' ), $data, array( 'id' => (int) $run_id ) );
	}

	/**
	 * Close a run.
	 *
	 * @param int    $run_id  Run.
	 * @param string $status  done|failed|halted.
	 * @param string $summary Human summary.
	 */
	public static function finish_run( $run_id, $status = 'done', $summary = '' ) {
		self::update_run(
			$run_id,
			array(
				'status'      => sanitize_key( $status ),
				'summary'     => mb_substr( (string) $summary, 0, 2000 ),
				'finished_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Record one step.
	 *
	 * @param array $step {run_id, seq, skill, title, reason, payload, result, status, needs_review, post_id, cost_usd, ms, error}.
	 * @return int Step id.
	 */
	public static function log_step( $step ) {
		global $wpdb;
		$ok = $wpdb->insert(
			Database::table( 'agent_steps' ),
			array(
				'run_id'       => (int) ( isset( $step['run_id'] ) ? $step['run_id'] : 0 ),
				'seq'          => (int) ( isset( $step['seq'] ) ? $step['seq'] : 0 ),
				'skill'        => sanitize_key( isset( $step['skill'] ) ? $step['skill'] : '' ),
				'title'        => mb_substr( (string) ( isset( $step['title'] ) ? $step['title'] : '' ), 0, 250 ),
				'reason'       => isset( $step['reason'] ) ? wp_json_encode( $step['reason'], JSON_UNESCAPED_UNICODE ) : '',
				'payload'      => isset( $step['payload'] ) ? wp_json_encode( $step['payload'], JSON_UNESCAPED_UNICODE ) : '',
				'result'       => isset( $step['result'] ) ? wp_json_encode( $step['result'], JSON_UNESCAPED_UNICODE ) : '',
				'status'       => sanitize_key( isset( $step['status'] ) ? $step['status'] : 'done' ),
				'needs_review' => empty( $step['needs_review'] ) ? 0 : 1,
				'post_id'      => (int) ( isset( $step['post_id'] ) ? $step['post_id'] : 0 ),
				'cost_usd'     => (float) ( isset( $step['cost_usd'] ) ? $step['cost_usd'] : 0 ),
				'ms'           => (int) ( isset( $step['ms'] ) ? $step['ms'] : 0 ),
				'error'        => isset( $step['error'] ) ? mb_substr( (string) $step['error'], 0, 1000 ) : '',
				'created_at'   => current_time( 'mysql' ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Steps for a run.
	 *
	 * @param int $run_id Run.
	 * @param int $limit  Max rows.
	 * @return array
	 */
	public static function steps( $run_id, $limit = 200 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Database::table( 'agent_steps' ) . ' WHERE run_id = %d ORDER BY seq ASC LIMIT %d', (int) $run_id, (int) $limit ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$row['reason']  = self::json( $row['reason'] );
			$row['payload'] = self::json( $row['payload'] );
			$row['result']  = self::json( $row['result'] );
			$out[]          = $row;
		}
		return $out;
	}

	/**
	 * Recent runs, newest first.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function runs( $limit = 20 ) {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Database::table( 'agent_runs' ) . ' ORDER BY id DESC LIMIT %d', (int) $limit ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	/**
	 * One run.
	 *
	 * @param int $run_id Run.
	 * @return array|null
	 */
	public static function run( $run_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Database::table( 'agent_runs' ) . ' WHERE id = %d', (int) $run_id ), // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['plan'] = self::json( $row['plan'] );
		return $row;
	}

	/**
	 * Remember a fact. Re-remembering raises its weight.
	 *
	 * @param string $scope site|post|keyword.
	 * @param string $slug  Identifier inside the scope.
	 * @param string $kind  fact kind.
	 * @param mixed  $value Value.
	 */
	public static function remember( $scope, $slug, $kind, $value ) {
		global $wpdb;
		if ( ! \hoosh_seo()->settings->on( 'agent.memory' ) ) {
			return;
		}
		$now  = current_time( 'mysql' );
		$found = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, weight, hits FROM ' . Database::table( 'agent_memory' ) . ' WHERE scope = %s AND slug = %s AND kind = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL
				sanitize_key( $scope ),
				mb_substr( (string) $slug, 0, 120 ),
				sanitize_key( $kind )
			),
			ARRAY_A
		);
		if ( $found ) {
			$wpdb->update(
				Database::table( 'agent_memory' ),
				array(
					'value'      => wp_json_encode( $value, JSON_UNESCAPED_UNICODE ),
					'weight'     => min( 10, (float) $found['weight'] + 0.5 ),
					'hits'       => (int) $found['hits'] + 1,
					'updated_at' => $now,
				),
				array( 'id' => (int) $found['id'] )
			);
			return;
		}
		$wpdb->insert(
			Database::table( 'agent_memory' ),
			array(
				'scope'      => sanitize_key( $scope ),
				'slug'       => mb_substr( (string) $slug, 0, 120 ),
				'kind'       => sanitize_key( $kind ),
				'value'      => wp_json_encode( $value, JSON_UNESCAPED_UNICODE ),
				'weight'     => 1,
				'hits'       => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
	}

	/**
	 * Recall facts, strongest first.
	 *
	 * @param string $scope Scope.
	 * @param string $slug  Optional slug filter.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function recall( $scope = 'site', $slug = '', $limit = 25 ) {
		global $wpdb;
		$sql  = 'SELECT slug, kind, value, weight, hits FROM ' . Database::table( 'agent_memory' ) . ' WHERE scope = %s';
		$args = array( sanitize_key( $scope ) );
		if ( '' !== $slug ) {
			$sql   .= ' AND slug = %s';
			$args[] = mb_substr( (string) $slug, 0, 120 );
		}
		$sql   .= ' ORDER BY weight DESC, updated_at DESC LIMIT %d';
		$args[] = (int) $limit;

		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $row ) {
			$row['value'] = self::json( $row['value'] );
			$out[]        = $row;
		}
		return $out;
	}

	/**
	 * Everything the planner needs to know about this site, as prose.
	 *
	 * Kept short and factual — it is prepended to the strategist prompt, so
	 * every token here is paid for on every run.
	 *
	 * @param int $limit Max facts.
	 * @return string
	 */
	public static function briefing( $limit = 15 ) {
		$facts = self::recall( 'site', '', $limit );
		if ( ! $facts ) {
			return '';
		}
		$lines = array();
		foreach ( $facts as $f ) {
			$value = is_scalar( $f['value'] ) ? (string) $f['value'] : wp_json_encode( $f['value'], JSON_UNESCAPED_UNICODE );
			$lines[] = '- ' . $f['kind'] . ': ' . mb_substr( $value, 0, 160 ) . ' (وزن ' . (float) $f['weight'] . ')';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Lifetime counters for the mission-control header.
	 *
	 * @return array
	 */
	public static function totals() {
		global $wpdb;
		$runs = Database::table( 'agent_runs' );
		$step = Database::table( 'agent_steps' );
		return array(
			'runs'        => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $runs ), // phpcs:ignore WordPress.DB.PreparedSQL
			'wins'        => (int) $wpdb->get_var( 'SELECT COALESCE(SUM(wins),0) FROM ' . $runs ), // phpcs:ignore WordPress.DB.PreparedSQL
			'steps'       => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $step ), // phpcs:ignore WordPress.DB.PreparedSQL
			'review'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $step WHERE needs_review = 1 AND status = 'review'" ), // phpcs:ignore WordPress.DB.PreparedSQL
			'cost_usd'    => (float) $wpdb->get_var( 'SELECT COALESCE(SUM(cost_usd),0) FROM ' . $runs ), // phpcs:ignore WordPress.DB.PreparedSQL
			'last_run_at' => (string) $wpdb->get_var( 'SELECT MAX(created_at) FROM ' . $runs ), // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Prune old runs so the tables do not grow forever.
	 */
	public static function prune() {
		global $wpdb;
		$keep = max( 5, (int) \hoosh_seo()->settings->get( 'agent.keep_runs', 60 ) );
		$wpdb->query( 'DELETE FROM ' . Database::table( 'agent_runs' ) . ' WHERE id NOT IN (SELECT id FROM (SELECT id FROM ' . Database::table( 'agent_runs' ) . ' ORDER BY id DESC LIMIT ' . $keep . ') AS keep_ids)' ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( 'DELETE FROM ' . Database::table( 'agent_steps' ) . " WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Decode a stored JSON column without exploding on legacy rows.
	 *
	 * @param mixed $raw Raw column.
	 * @return mixed
	 */
	protected static function json( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : $raw;
	}
}
