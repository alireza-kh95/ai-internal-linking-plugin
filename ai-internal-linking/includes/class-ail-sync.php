<?php
/**
 * Delta sync engine: keeps the content index in step with WordPress.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Sync {

	/**
	 * Register WordPress hooks.
	 */
	public function hooks() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
		add_action( 'wp_trash_post', array( $this, 'on_delete' ) );
		add_action( 'ail_cron_reconcile', array( $this, 'reconcile' ) );
		// Run the deferred first full sync once, in the background.
		add_action( 'admin_init', array( $this, 'maybe_first_sync' ) );
	}

	/**
	 * Post types that should be indexed.
	 *
	 * @return string[]
	 */
	public static function indexable_post_types() {
		$types = AIL_Settings::get( 'post_types' );
		if ( empty( $types ) || ! is_array( $types ) ) {
			$types = array( 'post', 'page' );
		}
		/**
		 * Filter the list of indexable post types.
		 *
		 * @param string[] $types Post types.
		 */
		return apply_filters( 'ail_indexable_post_types', $types );
	}

	/**
	 * Whether a post is eligible for indexing.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function should_index( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( ! in_array( $post->post_type, self::indexable_post_types(), true ) ) {
			return false;
		}
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}
		return (bool) apply_filters( 'ail_should_index_post', true, $post );
	}

	/**
	 * Index a single post (delta-aware). Returns 'indexed', 'unchanged', or 'skipped'.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $force   Force re-index even when the hash matches.
	 * @return string
	 */
	public function index_post( $post_id, $force = false ) {
		$post = get_post( $post_id );
		if ( ! self::should_index( $post ) ) {
			// If it was indexed before but is no longer eligible, drop it.
			if ( AIL_DB::get_index_by_post( $post_id ) ) {
				AIL_DB::delete_index_by_post( $post_id );
			}
			return 'skipped';
		}

		$existing = AIL_DB::get_index_by_post( $post_id );
		$row      = AIL_Content::build_index_row( $post );

		if ( ! $force && $existing && $existing['content_hash'] === $row['content_hash'] ) {
			return 'unchanged';
		}

		AIL_DB::upsert_index( $row );
		return 'indexed';
	}

	/**
	 * save_post handler.
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this is an update.
	 */
	public function on_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$result = $this->index_post( $post_id );
		if ( 'indexed' === $result ) {
			AIL_Logger::log( sprintf( 'Indexed "%s" on save', get_the_title( $post_id ) ), 'sync', 'success', array( 'post_id' => $post_id ) );
		}
	}

	/**
	 * Handle status transitions (e.g. publish -> draft removes it from the index).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			$this->index_post( $post->ID );
		} elseif ( 'publish' === $old_status ) {
			AIL_DB::delete_index_by_post( $post->ID );
		}
	}

	/**
	 * Remove a post from the index when trashed or deleted.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_delete( $post_id ) {
		AIL_DB::delete_index_by_post( $post_id );
	}

	/**
	 * Full sync of every indexable post. Returns counts.
	 *
	 * @param bool $force Force re-index.
	 * @return array{indexed:int,unchanged:int,removed:int,total:int}
	 */
	public function full_sync( $force = false ) {
		$types = self::indexable_post_types();
		$paged = 1;
		$stats = array(
			'indexed'   => 0,
			'unchanged' => 0,
			'removed'   => 0,
			'total'     => 0,
		);
		$seen = array();

		do {
			$q = new WP_Query(
				array(
					'post_type'              => $types,
					'post_status'            => 'publish',
					'posts_per_page'         => 100,
					'paged'                  => $paged,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( empty( $q->posts ) ) {
				break;
			}
			foreach ( $q->posts as $pid ) {
				$seen[]  = (int) $pid;
				$result  = $this->index_post( $pid, $force );
				$stats[ 'indexed' === $result ? 'indexed' : 'unchanged' ]++;
				$stats['total']++;
			}
			++$paged;
		} while ( true );

		// Remove index rows whose posts no longer qualify.
		$stats['removed'] = $this->prune_orphans( $seen );

		$this->update_link_counts();

		update_option( 'ail_first_sync_done', 1 );
		delete_option( 'ail_needs_full_sync' );
		update_option( 'ail_last_full_sync', current_time( 'mysql' ) );

		AIL_Logger::log(
			sprintf( 'Full sync complete: %d indexed, %d unchanged, %d removed', $stats['indexed'], $stats['unchanged'], $stats['removed'] ),
			'sync',
			'success',
			$stats
		);
		return $stats;
	}

	/**
	 * Remove index rows whose post id is not in the supplied "seen" list.
	 *
	 * @param int[] $seen Post ids that still qualify.
	 * @return int Number removed.
	 */
	public function prune_orphans( array $seen ) {
		global $wpdb;
		$table = AIL_DB::table( 'index' );
		$ids   = $wpdb->get_col( "SELECT post_id FROM {$table}" ); // phpcs:ignore WordPress.DB
		$seen  = array_flip( array_map( 'intval', $seen ) );
		$removed = 0;
		foreach ( (array) $ids as $pid ) {
			if ( ! isset( $seen[ (int) $pid ] ) ) {
				AIL_DB::delete_index_by_post( $pid );
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Hourly reconcile: catch posts changed outside of save_post and clean orphans.
	 */
	public function reconcile() {
		$this->full_sync( false );
	}

	/**
	 * Kick off the first full sync after activation (deferred to avoid slowing activation).
	 */
	public function maybe_first_sync() {
		if ( get_option( 'ail_needs_full_sync' ) && current_user_can( 'manage_options' ) ) {
			delete_option( 'ail_needs_full_sync' );
			// Schedule for the very next request so it doesn't block the page.
			wp_schedule_single_event( time() + 5, 'ail_cron_reconcile' );
		}
	}

	/* ----------------------------------------------------------------- *
	 *  Internal link graph
	 * ----------------------------------------------------------------- */

	/**
	 * Recompute inbound / outbound internal link counts for every indexed page.
	 *
	 * @return array Link graph: source_post_id => array of [target_post_id, anchor, url].
	 */
	public function update_link_counts() {
		global $wpdb;
		$table = AIL_DB::table( 'index' );
		$ids   = $wpdb->get_col( "SELECT post_id FROM {$table}" ); // phpcs:ignore WordPress.DB

		$outbound = array();
		$inbound  = array();
		$graph    = array();

		foreach ( (array) $ids as $pid ) {
			$pid   = (int) $pid;
			$post  = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$links = self::extract_internal_links( $post );
			$graph[ $pid ]    = $links;
			$outbound[ $pid ] = count( $links );
			foreach ( $links as $link ) {
				$tid = (int) $link['target_post_id'];
				if ( $tid && $tid !== $pid ) {
					$inbound[ $tid ] = isset( $inbound[ $tid ] ) ? $inbound[ $tid ] + 1 : 1;
				}
			}
		}

		foreach ( (array) $ids as $pid ) {
			$pid = (int) $pid;
			$wpdb->update(
				$table,
				array(
					'outbound_count' => isset( $outbound[ $pid ] ) ? $outbound[ $pid ] : 0,
					'inbound_count'  => isset( $inbound[ $pid ] ) ? $inbound[ $pid ] : 0,
				),
				array( 'post_id' => $pid )
			);
		}

		return $graph;
	}

	/**
	 * Extract internal links from a post's rendered content.
	 *
	 * @param WP_Post $post Post.
	 * @return array List of [target_post_id, target_url, anchor_text, raw_href].
	 */
	public static function extract_internal_links( $post ) {
		$html = AIL_Content::rendered_html( $post );
		$html .= "\n" . AIL_Content::acf_link_html( $post->ID );
		if ( '' === trim( $html ) ) {
			return array();
		}
		$home   = home_url();
		$host   = wp_parse_url( $home, PHP_URL_HOST );
		$result = array();

		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		foreach ( $dom->getElementsByTagName( 'a' ) as $element ) {
			$href   = trim( $element->getAttribute( 'href' ) );
			$anchor = trim( $element->textContent );
			if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) ) {
				continue;
			}
			// Normalise relative URLs.
			$href = WP_Http::make_absolute_url( $href, get_permalink( $post ) );
			if ( ! in_array( strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) ) {
				continue;
			}
			// Same-page fragments are section navigation, not internal links between pages.
			if ( wp_parse_url( $href, PHP_URL_FRAGMENT ) && self::destination_key( $href ) === self::destination_key( get_permalink( $post ) ) ) {
				continue;
			}
			$link_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( $link_host && $host && strtolower( $link_host ) !== strtolower( $host ) ) {
				continue; // External link.
			}
			$target_id = url_to_postid( $href );
			$result[]  = array(
				'target_post_id' => (int) $target_id,
				'target_url'     => $href,
				'anchor_text'    => $anchor,
				'rel'            => trim( $element->getAttribute( 'rel' ) ),
				'target'         => trim( $element->getAttribute( 'target' ) ),
				'managed_markup' => '1' === $element->getAttribute( 'data-ail' ),
			);
		}
		return $result;
	}

	/** Compare destinations independently of anchor text, fragments and trailing slashes. */
	public static function destination_key( $url ) {
		$url = WP_Http::make_absolute_url( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ), home_url( '/' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		return strtolower( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . rtrim( $parts['path'] ?? '/', '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	public static function has_destination( array $links, $post_id, $url ) {
		$key = self::destination_key( $url );
		foreach ( $links as $link ) {
			if ( ( $post_id && (int) $link['target_post_id'] === (int) $post_id ) || ( '' !== $key && self::destination_key( $link['target_url'] ) === $key ) ) {
				return true;
			}
		}
		return false;
	}

	/** Check whether an anchor phrase is already linked on a source page. */
	public static function has_anchor( array $links, $anchor ) {
		$anchor = self::anchor_key( $anchor );
		if ( '' === $anchor ) {
			return false;
		}
		foreach ( $links as $link ) {
			if ( self::anchor_key( $link['anchor_text'] ?? '' ) === $anchor ) {
				return true;
			}
		}
		return false;
	}

	private static function anchor_key( $anchor ) {
		$anchor = html_entity_decode( wp_strip_all_tags( (string) $anchor ), ENT_QUOTES, 'UTF-8' );
		return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $anchor ) ), 'UTF-8' );
	}
}

