<?php
/**
 * REST API controller — powers the admin dashboard.
 *
 * Namespace: ail/v1. Every route requires manage_options and a valid
 * X-WP-Nonce (handled by WordPress cookie auth).
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_REST {

	const NS = 'ail/v1';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Permission check shared by all routes.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Register every route.
	 */
	public function register_routes() {
		$perm = array( $this, 'can_manage' );

		register_rest_route( self::NS, '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_stats' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/pages', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_pages' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/sync', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_sync' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/opportunities/find', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_find' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/opportunities', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_opportunities' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/opportunities/apply', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_apply' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/opportunities/status', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_opportunity_status' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/links', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_links' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/links/remove', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_remove_link' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/audit/run', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_audit_run' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/audit', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_audit' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/activity', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_activity' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_settings' ),
				'permission_callback' => $perm,
			),
		) );

		register_rest_route( self::NS, '/test-connection', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_test_connection' ),
			'permission_callback' => $perm,
		) );

		register_rest_route( self::NS, '/maintenance', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'post_maintenance' ),
			'permission_callback' => $perm,
		) );
	}

	/* ----------------------------------------------------------------- *
	 *  Stats / dashboard
	 * ----------------------------------------------------------------- */

	/**
	 * Dashboard statistics.
	 *
	 * @return WP_REST_Response
	 */
	public function get_stats() {
		global $wpdb;
		$index = AIL_DB::table( 'index' );

		$last_audit = get_option( 'ail_last_audit', array() );

		$data = array(
			'indexed_pages'   => AIL_DB::count( 'index' ),
			'opportunities'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AIL_DB::table( 'opportunities' ) . " WHERE status = 'suggested'" ), // phpcs:ignore WordPress.DB
			'links_applied'   => AIL_DB::count( 'links', 'status', 'active' ),
			'orphans'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} WHERE inbound_count = 0 AND post_status = 'publish'" ), // phpcs:ignore WordPress.DB
			'total_words'     => (int) $wpdb->get_var( "SELECT SUM(word_count) FROM {$index}" ), // phpcs:ignore WordPress.DB
			'avg_outbound'    => round( (float) $wpdb->get_var( "SELECT AVG(outbound_count) FROM {$index}" ), 1 ), // phpcs:ignore WordPress.DB
			'last_full_sync'  => get_option( 'ail_last_full_sync', '' ),
			'audit_score'     => isset( $last_audit['score'] ) ? (int) $last_audit['score'] : null,
			'audit_at'        => isset( $last_audit['at'] ) ? $last_audit['at'] : '',
			'configured'      => (bool) AIL_Settings::get( 'n8n_find_url' ),
			'post_types'      => $this->post_type_options(),
			'activity'        => array_map( array( $this, 'shape_log' ), AIL_Logger::recent( 8 ) ),
		);
		return rest_ensure_response( $data );
	}

	/**
	 * Paginated indexed pages.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function get_pages( $req ) {
		$result = AIL_DB::query_index( array(
			'search'   => sanitize_text_field( (string) $req->get_param( 'search' ) ),
			'status'   => sanitize_text_field( (string) $req->get_param( 'status' ) ),
			'type'     => sanitize_text_field( (string) $req->get_param( 'type' ) ),
			'orderby'  => sanitize_text_field( (string) $req->get_param( 'orderby' ) ?: 'updated_at' ),
			'order'    => sanitize_text_field( (string) $req->get_param( 'order' ) ?: 'DESC' ),
			'per_page' => (int) ( $req->get_param( 'per_page' ) ?: 20 ),
			'page'     => (int) ( $req->get_param( 'page' ) ?: 1 ),
		) );

		// Attach suggested-opportunity counts for badges.
		global $wpdb;
		foreach ( $result['rows'] as &$row ) {
			$row['post_id']         = (int) $row['post_id'];
			$row['edit_link']       = get_edit_post_link( $row['post_id'], 'raw' );
			$row['suggested_count'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . AIL_DB::table( 'opportunities' ) . " WHERE source_post_id = %d AND status = 'suggested'", $row['post_id'] ) ); // phpcs:ignore WordPress.DB
			$row['inbound_suggested_count'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . AIL_DB::table( 'opportunities' ) . " WHERE target_post_id = %d AND source_post_id <> %d AND status = 'suggested'", $row['post_id'], $row['post_id'] ) ); // phpcs:ignore WordPress.DB
		}
		unset( $row );

		return rest_ensure_response( $result );
	}

	/* ----------------------------------------------------------------- *
	 *  Sync
	 * ----------------------------------------------------------------- */

	/**
	 * Run a sync (single post or full).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function post_sync( $req ) {
		$post_id = (int) $req->get_param( 'post_id' );
		$force   = (bool) $req->get_param( 'force' );
		$sync    = AIL_Plugin::instance()->sync();

		if ( $post_id ) {
			$result = $sync->index_post( $post_id, true );
			$sync->update_link_counts();
			return rest_ensure_response( array(
				'ok'     => true,
				'result' => $result,
				'page'   => AIL_DB::get_index_by_post( $post_id ),
			) );
		}

		$stats = $sync->full_sync( $force );
		return rest_ensure_response( array(
			'ok'    => true,
			'stats' => $stats,
		) );
	}

	/* ----------------------------------------------------------------- *
	 *  Opportunities
	 * ----------------------------------------------------------------- */

	/**
	 * Find opportunities for a post (calls n8n) and store the result.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_find( $req ) {
		$post_id   = (int) $req->get_param( 'post_id' );
		$direction = 'inbound' === $req->get_param( 'direction' ) ? 'inbound' : 'outbound';
		if ( ! $post_id ) {
			return new WP_Error( 'ail_bad_request', __( 'A post id is required.', 'ai-internal-linking' ), array( 'status' => 400 ) );
		}

		$result = ( new AIL_N8N() )->find_opportunities( $post_id, $direction );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 502 ) );
			return $result;
		}

		$batch = wp_generate_uuid4();
		if ( 'inbound' === $direction ) {
			$inserted = AIL_DB::replace_inbound_opportunities( $post_id, $result['opportunities'], $batch );
		} else {
			$inserted = AIL_DB::replace_opportunities( $post_id, $result['opportunities'], $batch );
		}
		if ( $inserted !== count( $result['opportunities'] ) ) {
			return new WP_Error( 'ail_store_failed', __( 'The workflow found opportunities, but WordPress could not save all of them. Check the database error log.', 'ai-internal-linking' ), array( 'status' => 500 ) );
		}

		global $wpdb;
		$wpdb->update( AIL_DB::table( 'index' ), array( 'last_opportunity_at' => current_time( 'mysql' ) ), array( 'post_id' => $post_id ) );

		AIL_Logger::log(
			sprintf( 'Found %1$d %2$s opportunit%3$s for "%4$s"', count( $result['opportunities'] ), $direction, count( $result['opportunities'] ) === 1 ? 'y' : 'ies', get_the_title( $post_id ) ),
			'n8n',
			'success',
			array( 'post_id' => $post_id )
		);

		return rest_ensure_response( $this->grouped_opportunities( $post_id, $direction ) );
	}

	/**
	 * Get stored opportunities for a post, grouped by anchor.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function get_opportunities( $req ) {
		$post_id   = (int) $req->get_param( 'post_id' );
		$direction = 'inbound' === $req->get_param( 'direction' ) ? 'inbound' : 'outbound';
		return rest_ensure_response( $this->grouped_opportunities( $post_id, $direction ) );
	}

	/**
	 * Group opportunity rows by anchor text so the UI can offer a choice when
	 * one phrase has several candidate destinations. Inbound rows additionally
	 * group per source page (the same anchor may exist on several pages).
	 *
	 * @param int    $post_id   Post id the drawer was opened for.
	 * @param string $direction outbound|inbound.
	 * @return array
	 */
	private function grouped_opportunities( $post_id, $direction = 'outbound' ) {
		$inbound = ( 'inbound' === $direction );
		$rows    = $inbound
			? AIL_DB::get_inbound_opportunities( $post_id, 'suggested' )
			: AIL_DB::get_opportunities( $post_id, 'suggested' );

		$groups = array();
		$existing_by_source = array();
		foreach ( $rows as $r ) {
			$sid = (int) $r['source_post_id'];
			if ( ! isset( $existing_by_source[ $sid ] ) ) {
				$source = get_post( $sid );
				$existing_by_source[ $sid ] = $source ? AIL_Sync::extract_internal_links( $source ) : array();
			}
			if ( AIL_Sync::has_destination( $existing_by_source[ $sid ], (int) $r['target_post_id'], $r['target_url'] ) || AIL_Sync::has_anchor( $existing_by_source[ $sid ], $r['anchor_text'] ) ) {
				AIL_DB::set_opportunity_status( (int) $r['id'], 'ignored' );
				continue;
			}
			$key = $inbound
				? $r['source_post_id'] . '|' . mb_strtolower( $r['anchor_text'] )
				: mb_strtolower( $r['anchor_text'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'anchor_text'  => $r['anchor_text'],
					'source_post_id' => (int) $r['source_post_id'],
					'source_title' => $inbound ? get_the_title( (int) $r['source_post_id'] ) : '',
					'candidates'   => array(),
				);
			}
			$groups[ $key ]['candidates'][] = array(
				'id'               => (int) $r['id'],
				'source_post_id'   => (int) $r['source_post_id'],
				'target_post_id'   => (int) $r['target_post_id'],
				'target_url'       => $r['target_url'],
				'target_title'     => $r['target_title'],
				'context_sentence' => $r['context_sentence'],
				'score'            => round( (float) $r['score'], 2 ),
				'reason'           => $r['reason'],
			);
		}
		// Sort candidates within each group by score desc.
		foreach ( $groups as &$g ) {
			usort( $g['candidates'], function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			} );
		}
		unset( $g );

		return array(
			'post_id'   => $post_id,
			'direction' => $direction,
			'post'      => AIL_DB::get_index_by_post( $post_id ),
			'groups'    => array_values( $groups ),
			'count'     => array_sum( array_map( function ( $group ) { return count( $group['candidates'] ); }, $groups ) ),
		);
	}

	/**
	 * Apply selected links to a post.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_apply( $req ) {
		$post_id   = (int) $req->get_param( 'post_id' );
		$direction = 'inbound' === $req->get_param( 'direction' ) ? 'inbound' : 'outbound';
		$items     = $req->get_param( 'items' );
		if ( ! $post_id || ! is_array( $items ) || empty( $items ) ) {
			return new WP_Error( 'ail_bad_request', __( 'Provide a post id and at least one link to apply.', 'ai-internal-linking' ), array( 'status' => 400 ) );
		}

		// Group items by the page whose content actually gets edited: the drawer's
		// page for outbound links, each suggestion's own source page for inbound.
		$by_source = array();
		foreach ( $items as $i ) {
			$source = ! empty( $i['source_post_id'] ) ? (int) $i['source_post_id'] : $post_id;
			$by_source[ $source ][] = array(
				'opportunity_id'  => isset( $i['opportunity_id'] ) ? (int) $i['opportunity_id'] : 0,
				'anchor_text'     => isset( $i['anchor_text'] ) ? sanitize_text_field( $i['anchor_text'] ) : '',
				'original_anchor' => isset( $i['original_anchor'] ) ? sanitize_text_field( $i['original_anchor'] ) : '',
				'target_url'      => isset( $i['target_url'] ) ? esc_url_raw( $i['target_url'] ) : '',
				'target_post_id'  => isset( $i['target_post_id'] ) ? (int) $i['target_post_id'] : 0,
			);
		}

		$linker  = new AIL_Linker();
		$applied = 0;
		$failed  = array();
		foreach ( $by_source as $source_id => $source_items ) {
			$result   = $linker->apply( $source_id, $source_items );
			$applied += $result['applied'];
			$failed   = array_merge( $failed, $result['failed'] );
		}

		return rest_ensure_response( array(
			'ok'        => true,
			'applied'   => $applied,
			'failed'    => $failed,
			'post_id'   => $post_id,
			'remaining' => $this->grouped_opportunities( $post_id, $direction ),
		) );
	}

	/**
	 * Update a single opportunity's status (reject / ignore).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function post_opportunity_status( $req ) {
		$id     = (int) $req->get_param( 'id' );
		$status = sanitize_text_field( (string) $req->get_param( 'status' ) );
		$allowed = array( 'suggested', 'accepted', 'rejected', 'ignored' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'ail_bad_request', __( 'Invalid status.', 'ai-internal-linking' ), array( 'status' => 400 ) );
		}
		AIL_DB::set_opportunity_status( $id, $status );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ----------------------------------------------------------------- *
	 *  Links
	 * ----------------------------------------------------------------- */

	/**
	 * List applied links.
	 *
	 * @return WP_REST_Response
	 */
	public function get_links() {
		$rows = AIL_DB::get_links( 200 );
		foreach ( $rows as &$r ) {
			$r['source_title'] = get_the_title( $r['source_post_id'] );
			$r['source_edit']  = get_edit_post_link( $r['source_post_id'], 'raw' );
		}
		unset( $r );
		return rest_ensure_response( array( 'rows' => $rows ) );
	}

	/**
	 * Remove an applied link.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function post_remove_link( $req ) {
		$id = (int) $req->get_param( 'id' );
		$ok = ( new AIL_Linker() )->remove( $id );
		return rest_ensure_response( array( 'ok' => $ok ) );
	}

	/* ----------------------------------------------------------------- *
	 *  Audit
	 * ----------------------------------------------------------------- */

	/**
	 * Run an audit.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function post_audit_run( $req ) {
		$with_ai = null === $req->get_param( 'with_ai' ) ? true : (bool) $req->get_param( 'with_ai' );
		$result  = ( new AIL_Auditor() )->run( $with_ai );
		return rest_ensure_response( $result );
	}

	/**
	 * Get the latest audit run.
	 *
	 * @return WP_REST_Response
	 */
	public function get_audit() {
		$run_id = AIL_DB::latest_audit_run();
		$last   = get_option( 'ail_last_audit', array() );
		$issues = $run_id ? AIL_DB::get_audit_issues( $run_id ) : array();
		foreach ( $issues as &$i ) {
			$i['data'] = $i['data'] ? json_decode( $i['data'], true ) : null;
			if ( $i['post_id'] ) {
				$i['edit_link'] = get_edit_post_link( (int) $i['post_id'], 'raw' );
			}
		}
		unset( $i );
		return rest_ensure_response( array(
			'run_id'    => $run_id,
			'score'     => isset( $last['score'] ) ? (int) $last['score'] : null,
			'counts'    => isset( $last['counts'] ) ? $last['counts'] : null,
			'narrative' => isset( $last['narrative'] ) ? $last['narrative'] : '',
			'at'        => isset( $last['at'] ) ? $last['at'] : '',
			'issues'    => $issues,
		) );
	}

	/* ----------------------------------------------------------------- *
	 *  Activity / settings / maintenance
	 * ----------------------------------------------------------------- */

	/**
	 * Activity log.
	 *
	 * @return WP_REST_Response
	 */
	public function get_activity() {
		return rest_ensure_response( array( 'rows' => array_map( array( $this, 'shape_log' ), AIL_Logger::recent( 60 ) ) ) );
	}

	/**
	 * Get settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( array(
			'settings'   => AIL_Settings::for_app(),
			'post_types' => $this->post_type_options(),
		) );
	}

	/**
	 * Save settings.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function post_settings( $req ) {
		$input = $req->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = $req->get_params();
		}
		// Don't overwrite secret-like fields with their masked placeholder.
		foreach ( array( 'shared_secret' ) as $secret_field ) {
			if ( isset( $input[ $secret_field ] ) && false !== strpos( $input[ $secret_field ], '•' ) ) {
				unset( $input[ $secret_field ] );
			}
		}
		AIL_Settings::update( $input );
		return rest_ensure_response( array(
			'ok'       => true,
			'settings' => AIL_Settings::for_app(),
		) );
	}

	/**
	 * Test a webhook URL.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function post_test_connection( $req ) {
		$url = esc_url_raw( (string) $req->get_param( 'url' ) );
		if ( ! $url ) {
			$url = (string) AIL_Settings::get( 'n8n_find_url' );
		}
		if ( ! $url ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'No URL provided.', 'ai-internal-linking' ) ) );
		}
		$res = ( new AIL_N8N() )->ping( $url );
		if ( is_wp_error( $res ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => $res->get_error_message() ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Connection successful.', 'ai-internal-linking' ), 'response' => $res ) );
	}

	/**
	 * Maintenance / cleanup actions.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_maintenance( $req ) {
		$action = sanitize_key( (string) $req->get_param( 'action' ) );
		$sync   = AIL_Plugin::instance()->sync();

		switch ( $action ) {
			case 'clear_opportunities':
				AIL_DB::truncate( 'opportunities' );
				$msg = __( 'All stored opportunities were cleared.', 'ai-internal-linking' );
				break;
			case 'clear_audit':
				AIL_DB::truncate( 'audit' );
				delete_option( 'ail_last_audit' );
				$msg = __( 'Audit results were cleared.', 'ai-internal-linking' );
				break;
			case 'clear_log':
				AIL_DB::truncate( 'log' );
				$msg = __( 'Activity log was cleared.', 'ai-internal-linking' );
				break;
			case 'clear_index':
				AIL_DB::truncate( 'index' );
				$msg = __( 'Content index was cleared. Run a full sync to rebuild it.', 'ai-internal-linking' );
				break;
			case 'prune_orphans':
				$removed = $sync->full_sync( false );
				$msg     = sprintf( __( 'Reconciled index. %d stale row(s) removed.', 'ai-internal-linking' ), (int) $removed['removed'] );
				break;
			case 'reset_all':
				AIL_Install::drop_tables();
				AIL_Install::create_tables();
				delete_option( 'ail_last_audit' );
				delete_option( 'ail_last_full_sync' );
				$msg = __( 'All plugin data was reset. Run a full sync to start fresh.', 'ai-internal-linking' );
				break;
			default:
				return new WP_Error( 'ail_bad_request', __( 'Unknown maintenance action.', 'ai-internal-linking' ), array( 'status' => 400 ) );
		}

		AIL_Logger::log( 'Maintenance: ' . $action, 'general', 'warning' );
		return rest_ensure_response( array( 'ok' => true, 'message' => $msg ) );
	}

	/* ----------------------------------------------------------------- *
	 *  Helpers
	 * ----------------------------------------------------------------- */

	/**
	 * Shape a log row for the client.
	 *
	 * @param array $row Log row.
	 * @return array
	 */
	private function shape_log( $row ) {
		return array(
			'level'   => $row['level'],
			'context' => $row['context'],
			'message' => $row['message'],
			'time'    => $row['created_at'],
			'ago'     => human_time_diff( strtotime( $row['created_at'] ), current_time( 'timestamp' ) ),
		);
	}

	/**
	 * Public post-type options for settings + filters.
	 *
	 * @return array
	 */
	private function post_type_options() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( in_array( $pt->name, array( 'attachment' ), true ) ) {
				continue;
			}
			$out[] = array(
				'name'  => $pt->name,
				'label' => $pt->labels->name,
			);
		}
		return $out;
	}
}

