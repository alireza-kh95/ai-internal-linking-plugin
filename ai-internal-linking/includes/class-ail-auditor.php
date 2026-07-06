<?php
/**
 * Internal-linking auditor.
 *
 * Runs deterministic, network-free checks over the indexed link graph and
 * (optionally) asks the AI for a prioritised narrative on the findings.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Auditor {

	/**
	 * Run a full audit. Stores issues under a new run id.
	 *
	 * @param bool $with_ai Whether to request an AI narrative.
	 * @return array{run_id:string,score:int,counts:array,issues:array,narrative:string}
	 */
	public function run( $with_ai = true ) {
		global $wpdb;
		$index_table = AIL_DB::table( 'index' );
		$pages       = $wpdb->get_results( "SELECT post_id, title, url, word_count, inbound_count, outbound_count FROM {$index_table} WHERE post_status = 'publish'", ARRAY_A ); // phpcs:ignore WordPress.DB

		$issues = array();

		// Build the site-wide link graph + anchor maps once.
		$anchor_targets = array(); // anchor(lower) => [ normalised target => true ]
		$anchor_repeat  = array(); // "anchor|target" => count
		$front_id       = (int) get_option( 'page_on_front' );

		foreach ( $pages as $p ) {
			$pid   = (int) $p['post_id'];
			$post  = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$links = AIL_Sync::extract_internal_links( $post );

			foreach ( $links as $link ) {
				$anchor = mb_strtolower( trim( $link['anchor_text'] ) );
				if ( '' === $anchor ) {
					continue;
				}
				$target = $link['target_post_id'] ? 'post:' . $link['target_post_id'] : 'url:' . untrailingslashit( $link['target_url'] );

				$anchor_targets[ $anchor ][ $target ]      = true;
				$key                                       = $anchor . '|' . $target;
				$anchor_repeat[ $key ]                     = isset( $anchor_repeat[ $key ] ) ? $anchor_repeat[ $key ] + 1 : 1;

				// Self link.
				if ( (int) $link['target_post_id'] === $pid ) {
					$issues[] = array(
						'post_id'    => $pid,
						'issue_type' => 'self_link',
						'severity'   => 'warning',
						'title'      => __( 'Page links to itself', 'ai-internal-linking' ),
						'message'    => sprintf( __( '"%1$s" contains a self-referencing link with anchor "%2$s".', 'ai-internal-linking' ), $p['title'], $link['anchor_text'] ),
						'data'       => array( 'anchor' => $link['anchor_text'] ),
					);
				}

				// Unresolved / possibly broken internal link.
				if ( 0 === (int) $link['target_post_id'] ) {
					$issues[] = array(
						'post_id'    => $pid,
						'issue_type' => 'unresolved_link',
						'severity'   => 'warning',
						'title'      => __( 'Internal link does not resolve to a known page', 'ai-internal-linking' ),
						'message'    => sprintf( __( '"%1$s" links to %2$s, which does not map to a published page. Verify it is not broken or redirected.', 'ai-internal-linking' ), $p['title'], $link['target_url'] ),
						'data'       => array(
							'url'    => $link['target_url'],
							'anchor' => $link['anchor_text'],
						),
					);
				}
			}

			// Orphan page (no inbound internal links), excluding the front page.
			if ( 0 === (int) $p['inbound_count'] && $pid !== $front_id ) {
				$issues[] = array(
					'post_id'    => $pid,
					'issue_type' => 'orphan',
					'severity'   => 'critical',
					'title'      => __( 'Orphan page (no internal links point here)', 'ai-internal-linking' ),
					'message'    => sprintf( __( '"%s" has no inbound internal links. Add contextual links to it from related pages.', 'ai-internal-linking' ), $p['title'] ),
					'data'       => array( 'url' => $p['url'] ),
				);
			}

			// Thin outbound linking on substantial content.
			if ( (int) $p['word_count'] >= 600 && (int) $p['outbound_count'] === 0 ) {
				$issues[] = array(
					'post_id'    => $pid,
					'issue_type' => 'thin_outbound',
					'severity'   => 'warning',
					'title'      => __( 'Long page with no outbound internal links', 'ai-internal-linking' ),
					'message'    => sprintf( __( '"%1$s" has %2$d words but links out to no other pages. Run "Find opportunities" on it.', 'ai-internal-linking' ), $p['title'], (int) $p['word_count'] ),
					'data'       => array( 'words' => (int) $p['word_count'] ),
				);
			}

			// Excessive outbound linking relative to length.
			$cap = max( 25, (int) round( $p['word_count'] / 75 ) );
			if ( (int) $p['outbound_count'] > $cap ) {
				$issues[] = array(
					'post_id'    => $pid,
					'issue_type' => 'excessive_outbound',
					'severity'   => 'info',
					'title'      => __( 'Possibly excessive outbound internal links', 'ai-internal-linking' ),
					'message'    => sprintf( __( '"%1$s" has %2$d internal links for %3$d words. Consider trimming to the most relevant.', 'ai-internal-linking' ), $p['title'], (int) $p['outbound_count'], (int) $p['word_count'] ),
					'data'       => array(
						'outbound' => (int) $p['outbound_count'],
						'words'    => (int) $p['word_count'],
					),
				);
			}
		}

		// Anchor dilution: one anchor pointing to several different destinations.
		foreach ( $anchor_targets as $anchor => $targets ) {
			if ( count( $targets ) > 1 ) {
				$issues[] = array(
					'post_id'    => null,
					'issue_type' => 'anchor_dilution',
					'severity'   => 'warning',
					'title'      => __( 'Same anchor text points to multiple pages', 'ai-internal-linking' ),
					'message'    => sprintf( __( 'The anchor "%1$s" links to %2$d different destinations across the site. Standardise it to one destination.', 'ai-internal-linking' ), $anchor, count( $targets ) ),
					'data'       => array(
						'anchor'  => $anchor,
						'targets' => array_keys( $targets ),
					),
				);
			}
		}

		// Over-optimised anchors: same anchor -> same target used too often.
		foreach ( $anchor_repeat as $key => $count ) {
			if ( $count >= 5 ) {
				list( $anchor ) = explode( '|', $key, 2 );
				$issues[]       = array(
					'post_id'    => null,
					'issue_type' => 'over_optimized_anchor',
					'severity'   => 'info',
					'title'      => __( 'Over-optimised anchor text', 'ai-internal-linking' ),
					'message'    => sprintf( __( 'The exact anchor "%1$s" is used %2$d times to the same destination. Vary anchor text to read naturally.', 'ai-internal-linking' ), $anchor, $count ),
					'data'       => array(
						'anchor' => $anchor,
						'count'  => $count,
					),
				);
			}
		}

		// Score: start at 100, subtract weighted penalties (floored at 0).
		$weights = array(
			'critical' => 6,
			'warning'  => 3,
			'info'     => 1,
		);
		$penalty = 0;
		$counts  = array(
			'critical' => 0,
			'warning'  => 0,
			'info'     => 0,
			'total'    => count( $issues ),
		);
		foreach ( $issues as $i ) {
			$sev = isset( $i['severity'] ) ? $i['severity'] : 'info';
			$counts[ $sev ] = isset( $counts[ $sev ] ) ? $counts[ $sev ] + 1 : 1;
			$penalty       += isset( $weights[ $sev ] ) ? $weights[ $sev ] : 1;
		}
		$page_count = max( 1, count( $pages ) );
		// Normalise penalty against site size so big sites aren't unfairly punished.
		$score = (int) round( max( 0, 100 - min( 100, ( $penalty / $page_count ) * 20 ) ) );

		$run_id = wp_generate_uuid4();
		// Keep only the latest run to stay tidy.
		AIL_DB::truncate( 'audit' );
		AIL_DB::insert_audit_issues( $run_id, $issues );

		$summary = array(
			'pages'       => count( $pages ),
			'score'       => $score,
			'counts'      => $counts,
			'top_issues'  => array_slice( $issues, 0, 30 ),
			'orphans'     => $counts['critical'],
		);

		$narrative = '';
		if ( $with_ai && AIL_Settings::get( 'n8n_audit_url' ) ) {
			$ai = ( new AIL_N8N() )->audit_narrative( $summary );
			if ( ! is_wp_error( $ai ) ) {
				$narrative = isset( $ai['narrative'] ) ? (string) $ai['narrative'] : ( isset( $ai['output'] ) ? (string) $ai['output'] : '' );
			}
		}

		update_option(
			'ail_last_audit',
			array(
				'run_id'    => $run_id,
				'score'     => $score,
				'counts'    => $counts,
				'narrative' => $narrative,
				'at'        => current_time( 'mysql' ),
			),
			false
		);

		AIL_Logger::log( sprintf( 'Audit complete: score %1$d, %2$d issues', $score, $counts['total'] ), 'audit', 'success', $counts );

		return array(
			'run_id'    => $run_id,
			'score'     => $score,
			'counts'    => $counts,
			'issues'    => $issues,
			'narrative' => $narrative,
		);
	}
}
