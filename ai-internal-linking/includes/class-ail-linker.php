<?php
/**
 * Safe link insertion into post content.
 *
 * Uses DOMDocument so links are only ever inserted into eligible text nodes:
 * never inside headings, existing links, buttons, code, captions or markup.
 * Supports re-wording: if the user edits the anchor phrase, the original
 * phrase is located and replaced with the new linked phrase.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Linker {

	/**
	 * Tags whose text must never be linked.
	 *
	 * @var string[]
	 */
	private static $skip_tags = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'button', 'code', 'pre', 'script', 'style', 'figcaption', 'label', 'textarea', 'cite' );

	/**
	 * Apply a set of links to a post.
	 *
	 * Each item: array{
	 *   opportunity_id?: int,
	 *   anchor_text: string,         // phrase to link (may be edited by the user)
	 *   original_anchor?: string,    // original suggested phrase, used for re-wording
	 *   target_url: string,
	 *   target_post_id?: int
	 * }
	 *
	 * @param int   $post_id Source post id.
	 * @param array $items   Links to apply.
	 * @return array{applied:int,failed:array,post_id:int}
	 */
	public function apply( $post_id, array $items ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'applied' => 0,
				'failed'  => array( __( 'Post not found.', 'ai-internal-linking' ) ),
				'post_id' => $post_id,
			);
		}

		$content     = $post->post_content;
		$acf         = AIL_Content::acf_wysiwyg_fields( $post_id ); // key => [ name, html ]
		$applied     = 0;
		$failed      = array();
		$done        = array();
		$existing_links = AIL_Sync::extract_internal_links( $post );

		foreach ( $items as $item ) {
			$anchor = isset( $item['anchor_text'] ) ? trim( (string) $item['anchor_text'] ) : '';
			$url    = isset( $item['target_url'] ) ? esc_url_raw( $item['target_url'] ) : '';
			$orig   = isset( $item['original_anchor'] ) ? trim( (string) $item['original_anchor'] ) : $anchor;
			if ( '' === $anchor || '' === $url ) {
				$failed[] = sprintf( __( 'Skipped an item with a missing anchor or URL.', 'ai-internal-linking' ) );
				continue;
			}

			if ( AIL_Sync::has_destination( $existing_links, url_to_postid( $url ), $url ) ) {
				$failed[] = __( 'This page already links to that destination, including its ACF blocks.', 'ai-internal-linking' );
				continue;
			}

			// Try post_content first (as-is, then re-worded from the original phrase).
			$result = $this->insert_into_content( $content, $anchor, $anchor, $url );
			if ( ! $result['changed'] && $orig !== $anchor ) {
				$result = $this->insert_into_content( $content, $orig, $anchor, $url );
			}
			if ( $result['changed'] ) {
				$content = $result['content'];
				++$applied;
				$done[] = $item;
				$existing_links[] = array( 'target_post_id' => url_to_postid( $url ), 'target_url' => $url );
				continue;
			}

			// Then try each ACF WYSIWYG field.
			$placed = false;
			foreach ( $acf as $key => $field ) {
				$r2 = $this->insert_into_content( $field['html'], $anchor, $anchor, $url );
				if ( ! $r2['changed'] && $orig !== $anchor ) {
					$r2 = $this->insert_into_content( $field['html'], $orig, $anchor, $url );
				}
				if ( $r2['changed'] ) {
					if ( ! AIL_Content::save_acf_editor( $post_id, $field, $r2['content'] ) ) {
						$failed[] = __( 'The ACF field changed or could not be saved. Refresh the page and try again.', 'ai-internal-linking' );
						$placed = true;
						break;
					}
					$acf[ $key ]['html']  = $r2['content'];
					$placed               = true;
					++$applied;
					$done[] = $item;
					$existing_links[] = array( 'target_post_id' => url_to_postid( $url ), 'target_url' => $url );
					break;
				}
			}

			if ( ! $placed ) {
				$failed[] = sprintf(
					/* translators: %s: anchor phrase */
					__( 'Could not place a link for "%s" — the phrase was not found in linkable body text (it may sit in a heading, a plain-text field, or already be linked).', 'ai-internal-linking' ),
					$anchor
				);
			}
		}

		if ( $applied > 0 ) {
			// Persist post_content (if it changed) without firing a re-index storm.
			if ( $content !== $post->post_content ) {
				remove_action( 'save_post', array( AIL_Plugin::instance()->sync(), 'on_save_post' ), 20 );
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				);
				add_action( 'save_post', array( AIL_Plugin::instance()->sync(), 'on_save_post' ), 20, 3 );
			}

			// Record links, mark opportunities applied, refresh the index + counts.
			foreach ( $done as $item ) {
				AIL_DB::insert_link(
					array(
						'opportunity_id' => isset( $item['opportunity_id'] ) ? (int) $item['opportunity_id'] : null,
						'source_post_id' => $post_id,
						'target_post_id' => isset( $item['target_post_id'] ) ? (int) $item['target_post_id'] : 0,
						'anchor_text'    => $item['anchor_text'],
						'target_url'     => esc_url_raw( $item['target_url'] ),
					)
				);
				if ( ! empty( $item['opportunity_id'] ) ) {
					AIL_DB::set_opportunity_status( (int) $item['opportunity_id'], 'applied' );
				}
			}

			AIL_Plugin::instance()->sync()->index_post( $post_id, true );
			AIL_Plugin::instance()->sync()->update_link_counts();

			AIL_Logger::log(
				sprintf( 'Applied %d internal link(s) to "%s"', $applied, get_the_title( $post_id ) ),
				'link',
				'success',
				array( 'post_id' => $post_id )
			);
		}

		return array(
			'applied' => $applied,
			'failed'  => $failed,
			'post_id' => $post_id,
		);
	}

	/**
	 * Insert a single link into a content string.
	 *
	 * @param string $content   Raw post content.
	 * @param string $find_text Phrase to locate.
	 * @param string $link_text Phrase to display inside the link (may differ).
	 * @param string $url       Destination URL.
	 * @return array{changed:bool,content:string}
	 */
	public function insert_into_content( $content, $find_text, $link_text, $url ) {
		if ( '' === trim( $content ) ) {
			return array(
				'changed' => false,
				'content' => $content,
			);
		}

		$dom = new DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML(
			'<?xml encoding="utf-8" ?><div data-ail-root="1">' . $content . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $loaded ) {
			return array(
				'changed' => false,
				'content' => $content,
			);
		}

		$xpath   = new DOMXPath( $dom );
		$wrapper = $xpath->query( '//*[@data-ail-root]' )->item( 0 );
		if ( ! $wrapper ) {
			return array(
				'changed' => false,
				'content' => $content,
			);
		}

		$changed = false;
		$nodes   = $xpath->query( './/text()', $wrapper );

		foreach ( $nodes as $node ) {
			if ( $this->in_skipped_context( $node, $wrapper ) ) {
				continue;
			}
			$match = $this->locate( $node->nodeValue, $find_text );
			if ( null === $match ) {
				continue;
			}

			$before = substr( $node->nodeValue, 0, $match['offset'] );
			$after  = substr( $node->nodeValue, $match['offset'] + $match['length'] );

			$anchor = $dom->createElement( 'a' );
			$anchor->setAttribute( 'href', $url );
			$rel = (string) AIL_Settings::get( 'link_rel' );
			if ( $rel ) {
				$anchor->setAttribute( 'rel', $rel );
			}
			if ( '_blank' === AIL_Settings::get( 'link_target' ) ) {
				$anchor->setAttribute( 'target', '_blank' );
			}
			$anchor->setAttribute( 'data-ail', '1' );
			$anchor->appendChild( $dom->createTextNode( $link_text ) );

			$parent = $node->parentNode;
			if ( '' !== $after ) {
				$parent->insertBefore( $dom->createTextNode( $after ), $node->nextSibling );
			}
			$parent->insertBefore( $anchor, $node );
			if ( '' !== $before ) {
				$parent->insertBefore( $dom->createTextNode( $before ), $anchor );
			}
			$parent->removeChild( $node );

			$changed = true;
			break; // One link per call.
		}

		if ( ! $changed ) {
			return array(
				'changed' => false,
				'content' => $content,
			);
		}

		$html = '';
		foreach ( $wrapper->childNodes as $child ) {
			$html .= $dom->saveHTML( $child );
		}

		return array(
			'changed' => true,
			'content' => $html,
		);
	}

	/**
	 * Is this text node inside a context where links must not be placed?
	 *
	 * @param DOMNode $node    Text node.
	 * @param DOMNode $wrapper Root wrapper to stop at.
	 * @return bool
	 */
	private function in_skipped_context( $node, $wrapper ) {
		$parent = $node->parentNode;
		while ( $parent && $parent !== $wrapper ) {
			if ( XML_ELEMENT_NODE === $parent->nodeType && in_array( strtolower( $parent->nodeName ), self::$skip_tags, true ) ) {
				return true;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}

	/**
	 * Locate the first whole-phrase, case-insensitive occurrence of $needle in $haystack.
	 *
	 * @param string $haystack Text.
	 * @param string $needle   Phrase.
	 * @return array{offset:int,length:int}|null Byte offset + byte length, or null.
	 */
	private function locate( $haystack, $needle ) {
		if ( '' === trim( $needle ) ) {
			return null;
		}
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/iu';
		if ( preg_match( $pattern, $haystack, $m, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'offset' => (int) $m[0][1],
				'length' => strlen( $m[0][0] ),
			);
		}
		return null;
	}

	/**
	 * Remove a previously applied link from a post (unwraps the anchor).
	 *
	 * @param int $link_id Row id in the links table.
	 * @return bool
	 */
	public function remove( $link_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . AIL_DB::table( 'links' ) . ' WHERE id = %d', $link_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return false;
		}
		$post = get_post( $row['source_post_id'] );
		if ( ! $post ) {
			AIL_DB::remove_link( $link_id );
			return true;
		}

		$url     = preg_quote( $row['target_url'], '#' );
		$url_alt = preg_quote( untrailingslashit( $row['target_url'] ), '#' );
		// Unwrap the first <a> to this URL, keeping its inner text.
		$pattern = '#<a\b[^>]*href=("|\')(?:' . $url . '|' . $url_alt . '/?)\1[^>]*>(.*?)</a>#is';

		$new_content = preg_replace( $pattern, '$2', $post->post_content, 1, $count );
		if ( $count > 0 && null !== $new_content ) {
			remove_action( 'save_post', array( AIL_Plugin::instance()->sync(), 'on_save_post' ), 20 );
			wp_update_post(
				array(
					'ID'           => $row['source_post_id'],
					'post_content' => $new_content,
				)
			);
			add_action( 'save_post', array( AIL_Plugin::instance()->sync(), 'on_save_post' ), 20, 3 );
		} else {
			// Not in post_content — look inside ACF WYSIWYG fields.
			foreach ( AIL_Content::acf_wysiwyg_fields( $row['source_post_id'] ) as $key => $field ) {
				$fixed = preg_replace( $pattern, '$2', $field['html'], 1, $c2 );
				if ( $c2 > 0 && null !== $fixed && function_exists( 'update_field' ) ) {
					if ( ! AIL_Content::save_acf_editor( $row['source_post_id'], $field, $fixed ) ) {
						return new WP_Error( 'ail_acf_save_failed', __( 'The ACF field changed or could not be saved. Refresh and try again.', 'ai-internal-linking' ) );
					}
					break;
				}
			}
		}
		AIL_Plugin::instance()->sync()->index_post( $row['source_post_id'], true );

		AIL_DB::remove_link( $link_id );
		AIL_Plugin::instance()->sync()->update_link_counts();
		AIL_Logger::log( sprintf( 'Removed internal link "%s"', $row['anchor_text'] ), 'link', 'warning' );
		return true;
	}
}

