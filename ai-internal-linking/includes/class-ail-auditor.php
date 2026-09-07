<?php
/** Internal-linking auditor. @package AIL */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Auditor {

	/** Run deterministic checks and optionally request a prioritised AI summary. */
	public function run( $with_ai = true ) {
		global $wpdb;
		AIL_Plugin::instance()->sync()->update_link_counts();

		$index = AIL_DB::table( 'index' );
		$pages = $wpdb->get_results( "SELECT post_id, post_type, title, url, word_count, inbound_count, outbound_count FROM {$index} WHERE post_status = 'publish'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$by_id = array();
		foreach ( $pages as $page ) {
			$by_id[ (int) $page['post_id'] ] = $page;
		}

		$managed       = $this->managed_link_map();
		$seen_managed  = array();
		$graph         = array();
		$anchor_targets = array();
		$issues        = array();
		$front_id      = (int) get_option( 'page_on_front' );
		$navigation    = $this->navigation_routes( $pages );
		$generic       = array( 'click here', 'learn more', 'read more', 'more', 'here', 'this page', 'find out more', 'view more', 'discover more' );

		foreach ( $pages as $page ) {
			$pid  = (int) $page['post_id'];
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$links       = AIL_Sync::extract_internal_links( $post );
			$destinations = array();
			$graph[ $pid ] = array();

			foreach ( $links as $link ) {
				$tid       = (int) $link['target_post_id'];
				$anchor    = trim( (string) $link['anchor_text'] );
				$anchor_key = $this->anchor_key( $anchor );
				$dest_key  = $tid ? 'post:' . $tid : 'url:' . AIL_Sync::destination_key( $link['target_url'] );
				$record    = $this->find_managed( $managed, $pid, $tid, $link['target_url'], $anchor_key );
				$is_managed = ! empty( $record ) || ! empty( $link['managed_markup'] );
				if ( $record ) {
					$seen_managed[ (int) $record['id'] ] = true;
				}

				if ( $tid && $tid !== $pid && isset( $by_id[ $tid ] ) ) {
					$graph[ $pid ][ $tid ] = true;
				}

				$is_cta_link = ! empty( $link['is_cta'] );
				if ( ! $is_cta_link && ! empty( $destinations[ $dest_key ] ) && ! $this->is_repeatable_utility_destination( $link, $by_id ) ) {
					$issues[] = $this->issue( $pid, 'duplicate_destination', 'warning', 'Page links to the same destination more than once', sprintf( '“%1$s” links to %2$s multiple times. Keep the most useful contextual link and remove redundant repeats.', $page['title'], $link['target_url'] ), 'remove', array( 'anchor' => $anchor, 'url' => $link['target_url'], 'managed' => $is_managed, 'link_id' => $record ? (int) $record['id'] : 0 ) );
				}
				if ( ! $is_cta_link ) {
					$destinations[ $dest_key ] = isset( $destinations[ $dest_key ] ) ? $destinations[ $dest_key ] + 1 : 1;
				}

			if ( $tid === $pid ) {
				$issues[] = $this->issue( $pid, 'self_link', 'warning', 'Page links to itself', sprintf( '“%1$s” contains a self-link using “%2$s”. Remove it unless it intentionally points to a section anchor.', $page['title'], $anchor ), 'remove', array( 'anchor' => $anchor, 'url' => $link['target_url'], 'managed' => $is_managed, 'link_id' => $record ? (int) $record['id'] : 0 ) );
			} elseif ( 0 === $tid ) {
				$issues[] = $this->issue( $pid, 'unresolved_link', 'warning', 'Internal destination could not be verified', sprintf( '“%1$s” links to %2$s, but WordPress cannot map it to published indexed content. Check for a broken URL or redirect.', $page['title'], $link['target_url'] ), 'review', array( 'anchor' => $anchor, 'url' => $link['target_url'], 'managed' => $is_managed, 'link_id' => $record ? (int) $record['id'] : 0 ) );
			} elseif ( ! isset( $by_id[ $tid ] ) ) {
				$issues[] = $this->issue( $pid, 'unpublished_target', 'critical', 'Link points to unpublished or excluded content', sprintf( '“%1$s” links to “%2$s”, which is not in the published content index.', $page['title'], $anchor ), 'replace', array( 'anchor' => $anchor, 'url' => $link['target_url'], 'managed' => $is_managed, 'link_id' => $record ? (int) $record['id'] : 0 ) );
			}

			// Relevance was approved when managed links were applied. Do not second-guess it with generic heuristics.
			if ( ! $is_managed && '' !== $anchor_key ) {
				$anchor_targets[ $anchor_key ][ $dest_key ] = true;
				if ( in_array( $anchor_key, $generic, true ) || preg_match( '#^https?://#i', $anchor ) ) {
					$issues[] = $this->issue( $pid, 'generic_anchor', 'warning', 'Anchor text is not descriptive', sprintf( 'Change “%s” to a concise phrase that describes the destination.', $anchor ), 'edit', array( 'anchor' => $anchor, 'url' => $link['target_url'] ) );
				} elseif ( str_word_count( $anchor ) > 12 ) {
					$issues[] = $this->issue( $pid, 'long_anchor', 'info', 'Anchor text is unusually long', sprintf( 'Shorten “%s” while preserving its meaning and natural context.', $anchor ), 'edit', array( 'anchor' => $anchor, 'url' => $link['target_url'] ) );
				}
			}
			if ( preg_match( '/(?:^|\s)nofollow(?:\s|$)/i', (string) ( $link['rel'] ?? '' ) ) ) {
				$issues[] = $this->issue( $pid, 'internal_nofollow', 'warning', 'Internal link is marked nofollow', sprintf( 'Remove nofollow from the internal link “%s” unless there is a specific crawl-control reason.', $anchor ), 'edit', array( 'anchor' => $anchor, 'url' => $link['target_url'], 'managed' => $is_managed ) );
			}
		}

			$effective_inbound = (int) $page['inbound_count'] + ( isset( $navigation['reachable'][ $pid ] ) ? 1 : 0 );
			if ( 0 === $effective_inbound && $pid !== $front_id ) {
				$issues[] = $this->issue( $pid, 'orphan', 'critical', 'No internal links point to this page', sprintf( '“%s” is disconnected from the site’s internal link graph. Find relevant pages that can link to it.', $page['title'] ), 'add_inbound', array( 'url' => $page['url'] ) );
			} elseif ( (int) $page['word_count'] >= 300 && 1 === $effective_inbound && $pid !== $front_id ) {
				$issues[] = $this->issue( $pid, 'weak_inbound_support', 'info', 'Page has only one inbound internal link', sprintf( '“%s” has substantial content but only one page links to it. Review additional relevant inbound opportunities.', $page['title'] ), 'add_inbound', array( 'url' => $page['url'] ) );
			}
			if ( (int) $page['word_count'] >= 250 && 0 === (int) $page['outbound_count'] ) {
				$issues[] = $this->issue( $pid, 'dead_end', 'warning', 'Content page is a dead end', sprintf( '“%1$s” has %2$d words and no outbound internal links. Add useful next-step links for readers.', $page['title'], (int) $page['word_count'] ), 'add_outbound', array( 'words' => (int) $page['word_count'] ) );
			}
			$cap = max( 15, (int) ceil( max( 1, (int) $page['word_count'] ) / 80 ) );
			if ( (int) $page['outbound_count'] > $cap ) {
				$issues[] = $this->issue( $pid, 'excessive_outbound', 'info', 'Page may contain too many internal links', sprintf( '“%1$s” has %2$d internal links across %3$d words. Review repeated or low-value links rather than removing links only to meet a fixed ratio.', $page['title'], (int) $page['outbound_count'], (int) $page['word_count'] ), 'review', array( 'outbound' => (int) $page['outbound_count'], 'words' => (int) $page['word_count'] ) );
			}
		}

		foreach ( $anchor_targets as $anchor => $targets ) {
			if ( count( $targets ) > 1 && str_word_count( $anchor ) > 1 ) {
				$issues[] = $this->issue( null, 'anchor_ambiguity', 'info', 'Manual links reuse one anchor for different destinations', sprintf( 'The manual anchor “%1$s” points to %2$d destinations. Review whether each use clearly matches its destination; plugin-approved links are excluded.', $anchor, count( $targets ) ), 'review', array( 'anchor' => $anchor, 'targets' => array_keys( $targets ) ) );
			}
		}

		foreach ( $managed as $records ) {
			foreach ( $records as $record ) {
				if ( empty( $seen_managed[ (int) $record['id'] ] ) ) {
					$issues[] = $this->issue( (int) $record['source_post_id'], 'ledger_drift', 'info', 'Applied-link record no longer matches page content', 'A link previously applied by this plugin is no longer present. Reconcile the record before relying on applied-link reporting.', 'reconcile', array( 'link_id' => (int) $record['id'], 'url' => $record['target_url'] ) );
				}
			}
		}

		$depths = $this->crawl_depths( $graph, $front_id, $navigation );
		foreach ( $depths as $pid => $depth ) {
			if ( $depth > 3 && isset( $by_id[ $pid ] ) ) {
				$issues[] = $this->issue( $pid, 'deep_page', 'info', 'Page is more than three clicks from the homepage', sprintf( '“%1$s” is approximately %2$d internal-link steps from the homepage. Consider a relevant link from a stronger hub or category page.', $by_id[ $pid ]['title'], $depth ), 'add_inbound', array( 'depth' => $depth ) );
			}
		}

		return $this->store_result( $pages, $issues, $with_ai );
	}

	private function issue( $post_id, $type, $severity, $title, $message, $action, array $data = array() ) {
		$data['action'] = $action;
		return array( 'post_id' => $post_id, 'issue_type' => $type, 'severity' => $severity, 'title' => __( $title, 'ai-internal-linking' ), 'message' => $message, 'data' => $data );
	}

	private function managed_link_map() {
		$map = array();
		foreach ( AIL_DB::get_active_links() as $row ) {
			$map[ (int) $row['source_post_id'] ][] = $row;
		}
		return $map;
	}

	private function find_managed( array $map, $source_id, $target_id, $url, $anchor ) {
		foreach ( $map[ $source_id ] ?? array() as $row ) {
			$destination_matches = ( $target_id && (int) $row['target_post_id'] === $target_id ) || AIL_Sync::destination_key( $row['target_url'] ) === AIL_Sync::destination_key( $url );
			if ( $destination_matches && $this->anchor_key( $row['anchor_text'] ) === $anchor ) {
				return $row;
			}
		}
		return null;
	}

	private function anchor_key( $anchor ) {
		return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $anchor, ENT_QUOTES, 'UTF-8' ) ) ) ), 'UTF-8' );
	}

	/** Collect active WordPress menu destinations and archive-like path routes. */
	private function navigation_routes( array $pages ) {
		$ids        = array();
		$prefixes   = array();
		$page_by_url = array();
		foreach ( $pages as $page ) {
			$page_by_url[ AIL_Sync::destination_key( $page['url'] ) ] = (int) $page['post_id'];
		}
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( function_exists( 'get_nav_menu_locations' ) && function_exists( 'wp_get_nav_menu_items' ) ) {
			foreach ( array_unique( array_values( (array) get_nav_menu_locations() ) ) as $menu_id ) {
				foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
					if ( empty( $item->url ) ) {
						continue;
					}
					$key = AIL_Sync::destination_key( $item->url );
					if ( isset( $page_by_url[ $key ] ) ) {
						$ids[ $page_by_url[ $key ] ] = true;
					}
					if ( ! empty( $item->object_id ) && 'custom' !== $item->type ) {
						$ids[ (int) $item->object_id ] = true;
					}
					$path = trim( (string) wp_parse_url( $item->url, PHP_URL_PATH ), '/' );
					$is_listing = 'post_type_archive' === $item->type || ( $posts_page && (int) $item->object_id === $posts_page ) || ( '' !== $path && ! isset( $page_by_url[ $key ] ) && empty( $item->object_id ) );
					if ( $is_listing && '' !== $key ) {
						$prefixes[] = rtrim( $key, '/' ) . '/';
					}
				}
			}
		}
		$reachable = $ids;
		foreach ( $pages as $page ) {
			$page_key = rtrim( AIL_Sync::destination_key( $page['url'] ), '/' ) . '/';
			foreach ( $prefixes as $menu_url ) {
				if ( $page_key === $menu_url || ( strlen( $page_key ) > strlen( $menu_url ) && 0 === strpos( $page_key, $menu_url ) ) ) {
					$reachable[ (int) $page['post_id'] ] = true;
					break;
				}
			}
		}
		return array( 'ids' => $ids, 'prefixes' => array_unique( $prefixes ), 'reachable' => $reachable );
	}

	/** Contact and similar conversion destinations may intentionally be linked repeatedly. */
	private function is_repeatable_utility_destination( array $link, array $pages ) {
		$title = '';
		if ( ! empty( $link['target_post_id'] ) && isset( $pages[ (int) $link['target_post_id'] ] ) ) {
			$title = mb_strtolower( (string) $pages[ (int) $link['target_post_id'] ]['title'] );
		}
		$path = mb_strtolower( (string) wp_parse_url( $link['target_url'], PHP_URL_PATH ) );
		return (bool) preg_match( '#(?:^|/)(contact|contact-us|book-a-demo|get-a-quote)/?$#', trim( $path, '/' ) ) || in_array( trim( $title ), array( 'contact', 'contact us', 'book a demo', 'get a quote' ), true );
	}

	private function crawl_depths( array $graph, $front_id, array $navigation ) {
		if ( ! $front_id || ! isset( $graph[ $front_id ] ) ) {
			return array();
		}
		$depths = array( $front_id => 0 );
		$queue  = array( $front_id );
		foreach ( array_keys( $navigation['ids'] ) as $menu_target ) {
			if ( $menu_target !== $front_id ) {
				$depths[ $menu_target ] = 1;
				$queue[] = $menu_target;
			}
		}
		foreach ( array_keys( $navigation['reachable'] ) as $listed_target ) {
			if ( ! isset( $depths[ $listed_target ] ) ) {
				$depths[ $listed_target ] = 2;
				$queue[] = $listed_target;
			}
		}
		while ( $queue ) {
			$source = array_shift( $queue );
			foreach ( array_keys( $graph[ $source ] ?? array() ) as $target ) {
				if ( ! isset( $depths[ $target ] ) ) {
					$depths[ $target ] = $depths[ $source ] + 1;
					$queue[] = $target;
				}
			}
		}
		return $depths;
	}

	private function store_result( array $pages, array $issues, $with_ai ) {
		$weights = array( 'critical' => 6, 'warning' => 3, 'info' => 1 );
		$counts  = array( 'critical' => 0, 'warning' => 0, 'info' => 0, 'total' => count( $issues ) );
		$penalty = 0;
		foreach ( $issues as $issue ) {
			$severity = $issue['severity'];
			++$counts[ $severity ];
			$penalty += $weights[ $severity ];
		}
		$score  = (int) round( max( 0, 100 - min( 100, ( $penalty / max( 1, count( $pages ) ) ) * 15 ) ) );
		$run_id = wp_generate_uuid4();
		AIL_DB::truncate( 'audit' );
		AIL_DB::insert_audit_issues( $run_id, $issues );
		$summary = array(
			'pages'           => count( $pages ),
			'score'           => $score,
			'counts'          => $counts,
			'top_issues'      => array_slice( $issues, 0, 40 ),
			'method'          => 'Deterministic graph and content checks. Plugin-approved links are excluded from subjective anchor criticism.',
			'reporting_rules' => array(
				'Summarise only supplied findings; do not invent link-quality judgements.',
				'Do not question relevance or recommend removing a link merely because an anchor repeats.',
				'Preserve each finding action: add, edit, replace, remove, review, or reconcile.',
				'Prioritise objective breakage, then crawlability and reader journeys.',
			),
		);
		$narrative = '';
		if ( $with_ai && AIL_Settings::get( 'n8n_audit_url' ) ) {
			$ai = ( new AIL_N8N() )->audit_narrative( $summary );
			if ( ! is_wp_error( $ai ) ) {
				$narrative = isset( $ai['narrative'] ) ? (string) $ai['narrative'] : ( isset( $ai['output'] ) ? (string) $ai['output'] : '' );
			}
		}
		$last = array( 'run_id' => $run_id, 'score' => $score, 'counts' => $counts, 'narrative' => $narrative, 'at' => current_time( 'mysql' ) );
		update_option( 'ail_last_audit', $last, false );
		AIL_Logger::log( sprintf( 'Audit complete: score %1$d, %2$d issues', $score, $counts['total'] ), 'audit', 'success', $counts );
		return array_merge( $last, array( 'issues' => $issues ) );
	}
}
