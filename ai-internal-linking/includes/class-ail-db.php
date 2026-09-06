<?php
/**
 * Thin data-access layer over the plugin tables.
 *
 * All table names are derived from the WordPress prefix; values are always
 * passed through $wpdb->prepare().
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_DB {

	/**
	 * Common table-name prefix, e.g. wp_ail_.
	 *
	 * @return string
	 */
	public static function table_prefix() {
		global $wpdb;
		return $wpdb->prefix . 'ail_';
	}

	/**
	 * Resolve a logical table name to its full DB name.
	 *
	 * @param string $name index|opportunities|links|audit|log
	 * @return string
	 */
	public static function table( $name ) {
		return self::table_prefix() . $name;
	}

	/* ----------------------------------------------------------------- *
	 *  Index
	 * ----------------------------------------------------------------- */

	/**
	 * Insert or update a row in the content index.
	 *
	 * @param array $data Column => value.
	 * @return int Row id.
	 */
	public static function upsert_index( array $data ) {
		global $wpdb;
		$table = self::table( 'index' );
		$now   = current_time( 'mysql' );

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $data['post_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$data['updated_at'] = $now;
		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing ) );
			return (int) $existing;
		}
		$data['created_at'] = $now;
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a single index row by post id.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function get_index_by_post( $post_id ) {
		global $wpdb;
		$table = self::table( 'index' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $row ?: null;
	}

	/**
	 * Delete an index row by post id.
	 *
	 * @param int $post_id Post id.
	 */
	public static function delete_index_by_post( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'index' ), array( 'post_id' => $post_id ) );
	}

	/**
	 * Query indexed pages with search / filter / pagination.
	 *
	 * @param array $args Query args.
	 * @return array{rows: array, total: int}
	 */
	public static function query_index( array $args = array() ) {
		global $wpdb;
		$table = self::table( 'index' );

		$defaults = array(
			'search'   => '',
			'status'   => '',
			'type'     => '',
			'orderby'  => 'updated_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'page'     => 1,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = 'WHERE 1=1';
		$params = array();

		if ( $args['search'] !== '' ) {
			$where   .= ' AND title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}
		if ( $args['status'] !== '' ) {
			$where   .= ' AND sync_status = %s';
			$params[] = $args['status'];
		}
		if ( $args['type'] !== '' ) {
			$where   .= ' AND post_type = %s';
			$params[] = $args['type'];
		}

		$allowed_orderby = array( 'updated_at', 'title', 'word_count', 'inbound_count', 'outbound_count', 'last_synced', 'last_opportunity_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'updated_at';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB

		$sql         = "SELECT id, post_id, post_type, post_status, title, url, word_count, inbound_count, outbound_count, sync_status, last_opportunity_at, last_synced, updated_at FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( $per_page, $offset ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $query_params ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Lightweight catalogue of link targets for the AI (everything except $exclude_id).
	 *
	 * @param int $exclude_id Source post to exclude.
	 * @param int $limit      Max targets.
	 * @return array
	 */
	public static function get_target_catalog( $exclude_id, $limit = 300 ) {
		global $wpdb;
		$table = self::table( 'index' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, title, url, keywords, excerpt FROM {$table} WHERE post_id <> %d AND post_status = 'publish' ORDER BY word_count DESC LIMIT %d", // phpcs:ignore WordPress.DB
				$exclude_id,
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/**
	 * Candidate SOURCE pages for inbound linking: other pages whose body text the
	 * AI scans for phrases that could link TO the given page. Includes a content
	 * excerpt because the anchor must exist verbatim in the source body.
	 *
	 * @param int $target_id Page that should receive the links.
	 * @param int $limit     Max candidates.
	 * @return array
	 */
	public static function get_source_candidates( $target_id, $limit = 40 ) {
		global $wpdb;
		$table = self::table( 'index' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, title, url, LEFT(content_text, 2000) AS content FROM {$table} WHERE post_id <> %d AND post_status = 'publish' ORDER BY word_count DESC LIMIT %d", // phpcs:ignore WordPress.DB
				$target_id,
				$limit
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	/* ----------------------------------------------------------------- *
	 *  Generic helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Count rows of a table, optionally filtered by a single equality clause.
	 *
	 * @param string $name   Logical table name.
	 * @param string $column Optional column.
	 * @param mixed  $value  Optional value.
	 * @return int
	 */
	public static function count( $name, $column = '', $value = null ) {
		global $wpdb;
		$table = self::table( $name );
		if ( $column ) {
			$column = preg_replace( '/[^a-z0-9_]/', '', $column );
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$column} = %s", $value ) ); // phpcs:ignore WordPress.DB
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Truncate a logical table.
	 *
	 * @param string $name Logical table name.
	 */
	public static function truncate( $name ) {
		global $wpdb;
		$table = self::table( $name );
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB
	}

	/* ----------------------------------------------------------------- *
	 *  Opportunities
	 * ----------------------------------------------------------------- */

	/**
	 * Replace stored opportunities for a source post with a new batch.
	 *
	 * @param int    $source_post_id Source post.
	 * @param array  $opportunities  List of opportunity rows.
	 * @param string $batch_id       Batch identifier.
	 * @return int Number inserted.
	 */
	public static function replace_opportunities( $source_post_id, array $opportunities, $batch_id ) {
		global $wpdb;
		$table = self::table( 'opportunities' );
		$now   = current_time( 'mysql' );

		// Remove any previous *unapplied* suggestions for this source; keep applied history.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source_post_id = %d AND status IN ('suggested','accepted','rejected','ignored')", $source_post_id ) ); // phpcs:ignore WordPress.DB

		$inserted = 0;
		foreach ( $opportunities as $o ) {
			$saved = $wpdb->insert(
				$table,
				array(
					'batch_id'         => $batch_id,
					'source_post_id'   => $source_post_id,
					'target_post_id'   => isset( $o['target_post_id'] ) ? (int) $o['target_post_id'] : 0,
					'anchor_text'      => isset( $o['anchor_text'] ) ? mb_substr( (string) $o['anchor_text'], 0, 255 ) : '',
					'context_sentence' => isset( $o['context_sentence'] ) ? (string) $o['context_sentence'] : '',
					'target_url'       => isset( $o['target_url'] ) ? esc_url_raw( $o['target_url'] ) : '',
					'target_title'     => isset( $o['target_title'] ) ? (string) $o['target_title'] : '',
					'score'            => isset( $o['score'] ) ? (float) $o['score'] : 0,
					'reason'           => isset( $o['reason'] ) ? (string) $o['reason'] : '',
					'status'           => 'suggested',
					'created_at'       => $now,
				)
			);
			if ( false !== $saved ) {
				++$inserted;
			}
		}
		return $inserted;
	}

	/**
	 * Replace stored INBOUND opportunities for a target post (suggestions that
	 * live on other pages but point here). Keeps applied history, and does not
	 * touch the target page's own outbound suggestions.
	 *
	 * @param int    $target_post_id Target post.
	 * @param array  $opportunities  Opportunity rows (must carry source_post_id).
	 * @param string $batch_id       Batch identifier.
	 * @return int Number inserted.
	 */
	public static function replace_inbound_opportunities( $target_post_id, array $opportunities, $batch_id ) {
		global $wpdb;
		$table = self::table( 'opportunities' );
		$now   = current_time( 'mysql' );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE target_post_id = %d AND source_post_id <> %d AND status IN ('suggested','accepted','rejected','ignored')", $target_post_id, $target_post_id ) ); // phpcs:ignore WordPress.DB

		$inserted = 0;
		foreach ( $opportunities as $o ) {
			if ( empty( $o['source_post_id'] ) ) {
				continue;
			}
			$saved = $wpdb->insert(
				$table,
				array(
					'batch_id'         => $batch_id,
					'source_post_id'   => (int) $o['source_post_id'],
					'target_post_id'   => $target_post_id,
					'anchor_text'      => isset( $o['anchor_text'] ) ? mb_substr( (string) $o['anchor_text'], 0, 255 ) : '',
					'context_sentence' => isset( $o['context_sentence'] ) ? (string) $o['context_sentence'] : '',
					'target_url'       => isset( $o['target_url'] ) ? esc_url_raw( $o['target_url'] ) : '',
					'target_title'     => isset( $o['target_title'] ) ? (string) $o['target_title'] : '',
					'score'            => isset( $o['score'] ) ? (float) $o['score'] : 0,
					'reason'           => isset( $o['reason'] ) ? (string) $o['reason'] : '',
					'status'           => 'suggested',
					'created_at'       => $now,
				)
			);
			if ( false !== $saved ) {
				++$inserted;
			}
		}
		return $inserted;
	}

	/**
	 * Get inbound opportunities: suggestions on OTHER pages pointing to this one.
	 *
	 * @param int    $target_post_id Target post.
	 * @param string $status         Optional status filter.
	 * @return array
	 */
	public static function get_inbound_opportunities( $target_post_id, $status = '' ) {
		global $wpdb;
		$table = self::table( 'opportunities' );
		if ( $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE target_post_id = %d AND source_post_id <> %d AND status = %s ORDER BY source_post_id ASC, score DESC", $target_post_id, $target_post_id, $status ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE target_post_id = %d AND source_post_id <> %d ORDER BY source_post_id ASC, score DESC", $target_post_id, $target_post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return $rows ?: array();
	}

	/**
	 * Get opportunities for a source post.
	 *
	 * @param int    $source_post_id Source post.
	 * @param string $status         Optional status filter.
	 * @return array
	 */
	public static function get_opportunities( $source_post_id, $status = '' ) {
		global $wpdb;
		$table = self::table( 'opportunities' );
		if ( $status ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_post_id = %d AND status = %s ORDER BY anchor_text ASC, score DESC", $source_post_id, $status ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_post_id = %d ORDER BY anchor_text ASC, score DESC", $source_post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return $rows ?: array();
	}

	/**
	 * Get a single opportunity by id.
	 *
	 * @param int $id Opportunity id.
	 * @return array|null
	 */
	public static function get_opportunity( $id ) {
		global $wpdb;
		$table = self::table( 'opportunities' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/**
	 * Update an opportunity status (and applied timestamp when applied).
	 *
	 * @param int    $id     Opportunity id.
	 * @param string $status New status.
	 */
	public static function set_opportunity_status( $id, $status ) {
		global $wpdb;
		$data = array( 'status' => $status );
		if ( 'applied' === $status ) {
			$data['applied_at'] = current_time( 'mysql' );
		}
		$wpdb->update( self::table( 'opportunities' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Map of anchor text => target_url for every applied link site-wide.
	 * Used to enforce consistent anchor->target and avoid duplicate anchors.
	 *
	 * @return array
	 */
	public static function get_applied_anchor_map() {
		global $wpdb;
		$table = self::table( 'links' );
		$rows  = $wpdb->get_results( "SELECT anchor_text, target_url FROM {$table} WHERE status = 'active'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$map   = array();
		foreach ( (array) $rows as $r ) {
			$map[ mb_strtolower( $r['anchor_text'] ) ] = $r['target_url'];
		}
		return $map;
	}

	/* ----------------------------------------------------------------- *
	 *  Links
	 * ----------------------------------------------------------------- */

	/**
	 * Record an applied link.
	 *
	 * @param array $data Column => value.
	 * @return int
	 */
	public static function insert_link( array $data ) {
		global $wpdb;
		$data['applied_at'] = current_time( 'mysql' );
		$data['status']     = 'active';
		$wpdb->insert( self::table( 'links' ), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get applied links, newest first.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_links( $limit = 200 ) {
		global $wpdb;
		$table = self::table( 'links' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY applied_at DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ?: array();
	}

	/**
	 * Mark a link as removed.
	 *
	 * @param int $id Link id.
	 */
	public static function remove_link( $id ) {
		global $wpdb;
		$wpdb->update(
			self::table( 'links' ),
			array(
				'status'     => 'removed',
				'removed_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Audit
	 * ----------------------------------------------------------------- */

	/**
	 * Store a batch of audit issues under a run id.
	 *
	 * @param string $run_id Run identifier.
	 * @param array  $issues Issues.
	 */
	public static function insert_audit_issues( $run_id, array $issues ) {
		global $wpdb;
		$table = self::table( 'audit' );
		$now   = current_time( 'mysql' );
		foreach ( $issues as $i ) {
			$wpdb->insert(
				$table,
				array(
					'run_id'     => $run_id,
					'post_id'    => isset( $i['post_id'] ) ? (int) $i['post_id'] : null,
					'issue_type' => isset( $i['issue_type'] ) ? $i['issue_type'] : 'general',
					'severity'   => isset( $i['severity'] ) ? $i['severity'] : 'info',
					'title'      => isset( $i['title'] ) ? mb_substr( (string) $i['title'], 0, 255 ) : '',
					'message'    => isset( $i['message'] ) ? (string) $i['message'] : '',
					'data'       => isset( $i['data'] ) ? wp_json_encode( $i['data'] ) : null,
					'created_at' => $now,
				)
			);
		}
	}

	/**
	 * Get the most recent audit run id.
	 *
	 * @return string
	 */
	public static function latest_audit_run() {
		global $wpdb;
		$table = self::table( 'audit' );
		return (string) $wpdb->get_var( "SELECT run_id FROM {$table} ORDER BY created_at DESC LIMIT 1" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Get audit issues for a run.
	 *
	 * @param string $run_id Run identifier.
	 * @return array
	 */
	public static function get_audit_issues( $run_id ) {
		global $wpdb;
		$table = self::table( 'audit' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s ORDER BY FIELD(severity,'critical','warning','info'), issue_type", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ?: array();
	}
}
