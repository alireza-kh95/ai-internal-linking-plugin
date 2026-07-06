<?php
/**
 * Content extraction, normalisation, hashing and keyword extraction.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Content {

	/**
	 * Render a post's content to display HTML (runs blocks + shortcodes).
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function rendered_html( $post ) {
		$content = $post->post_content;
		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
			$content = do_blocks( $content );
		}
		$content = wpautop( $content );
		$content = do_shortcode( $content );
		return $content;
	}

	/**
	 * Plain-text version of a post's content (for the AI corpus / keyword work).
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function plain_text( $post ) {
		$html = self::rendered_html( $post );
		// Drop script/style blocks entirely.
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
		$text = wp_strip_all_tags( $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		// Many themes store the real body in ACF fields, not post_content.
		$acf = self::acf_plain_text( $post );
		if ( '' !== $acf ) {
			$text = trim( $text . ' ' . $acf );
		}
		return $text;
	}

	/**
	 * Concatenated plain text from all of a post's ACF fields (recursive).
	 * No-op when ACF is not active.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function acf_plain_text( $post ) {
		if ( ! function_exists( 'get_fields' ) ) {
			return '';
		}
		$fields = get_fields( $post->ID );
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return '';
		}
		$parts = array();
		self::collect_strings( $fields, $parts );
		if ( ! $parts ) {
			return '';
		}
		$text = implode( ' ', $parts );
		$text = wp_strip_all_tags( $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Recursively collect prose-like strings from an ACF value tree.
	 *
	 * @param mixed $value Field value.
	 * @param array $out   Accumulator (by reference).
	 * @param int   $depth Recursion guard.
	 */
	private static function collect_strings( $value, array &$out, $depth = 0 ) {
		if ( $depth > 12 ) {
			return;
		}
		if ( is_string( $value ) ) {
			$v = trim( $value );
			if ( '' === $v || is_numeric( $v ) || mb_strlen( $v ) < 3 ) {
				return;
			}
			// Skip bare URLs / emails.
			if ( preg_match( '#^(https?://|mailto:|tel:)#i', $v ) || is_email( $v ) ) {
				return;
			}
			$out[] = $v;
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $sub ) {
				// Skip scalar values under obviously non-text keys (media, ids, colours…).
				if ( ! is_array( $sub ) && is_string( $key ) && preg_match( '/(^id$|_id$|image|thumbnail|gallery|file|icon|colou?r|^date|^url$|^link$|^email$|phone|order|position|width|height)/i', $key ) ) {
					continue;
				}
				self::collect_strings( $sub, $out, $depth + 1 );
			}
		}
	}

	/**
	 * Top-level ACF WYSIWYG fields that can safely hold an HTML link.
	 * Returns a map of field_key => [ name, html (raw stored value) ].
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function acf_wysiwyg_fields( $post_id ) {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return array();
		}
		// format_value=false → raw stored HTML we can edit and write back.
		$objects = get_field_objects( $post_id, false );
		if ( empty( $objects ) || ! is_array( $objects ) ) {
			return array();
		}
		$out = array();
		foreach ( $objects as $name => $fo ) {
			if ( isset( $fo['type'], $fo['value'], $fo['key'] ) && 'wysiwyg' === $fo['type'] && is_string( $fo['value'] ) && '' !== trim( $fo['value'] ) ) {
				$out[ $fo['key'] ] = array(
					'name' => $name,
					'html' => $fo['value'],
				);
			}
		}
		return $out;
	}

	/**
	 * Word count of a plain-text string (multibyte aware).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function word_count( $text ) {
		if ( '' === $text ) {
			return 0;
		}
		return count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
	}

	/**
	 * Stable content hash used for delta sync.
	 *
	 * @param string $title Post title.
	 * @param string $text  Plain text body.
	 * @return string
	 */
	public static function hash( $title, $text ) {
		return sha1( $title . '|' . $text );
	}

	/**
	 * Extract a ranked list of candidate key phrases (1-3 word n-grams).
	 *
	 * Deliberately lightweight and dependency-free: it surfaces the topical
	 * phrases that help the AI (and the auditor) reason about a page.
	 *
	 * @param string $text  Plain text.
	 * @param string $title Post title (weighted higher).
	 * @param int    $limit Max phrases.
	 * @return array
	 */
	public static function extract_keywords( $text, $title = '', $limit = 15 ) {
		$stop  = self::stopwords();
		$lower = mb_strtolower( $title . ' . ' . $text, 'UTF-8' );

		// Tokenise into word sequences, breaking on punctuation so n-grams stay within a clause.
		$chunks = preg_split( '/[^\p{L}\p{N}\'-]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );

		$scores = array();
		$titleterms = array_flip( preg_split( '/[^\p{L}\p{N}\'-]+/u', mb_strtolower( $title, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );

		$n      = count( $chunks );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $size = 1; $size <= 3; $size++ ) {
				if ( $i + $size > $n ) {
					break;
				}
				$gram_words = array_slice( $chunks, $i, $size );

				// Reject grams whose first/last token is a stopword or too short.
				$first = $gram_words[0];
				$last  = $gram_words[ $size - 1 ];
				if ( isset( $stop[ $first ] ) || isset( $stop[ $last ] ) ) {
					continue;
				}
				if ( $size === 1 && ( mb_strlen( $first ) < 4 || is_numeric( $first ) ) ) {
					continue;
				}

				$phrase = implode( ' ', $gram_words );
				$weight = $size; // Favour longer phrases.
				// Boost phrases that appear in the title.
				foreach ( $gram_words as $w ) {
					if ( isset( $titleterms[ $w ] ) ) {
						$weight += 2;
						break;
					}
				}
				if ( ! isset( $scores[ $phrase ] ) ) {
					$scores[ $phrase ] = 0;
				}
				$scores[ $phrase ] += $weight;
			}
		}

		arsort( $scores );

		// Drop phrases that are substrings of a higher-ranked phrase to reduce noise.
		$result = array();
		foreach ( array_keys( $scores ) as $phrase ) {
			$contained = false;
			foreach ( $result as $kept ) {
				if ( false !== mb_strpos( $kept, $phrase ) ) {
					$contained = true;
					break;
				}
			}
			if ( ! $contained ) {
				$result[] = $phrase;
			}
			if ( count( $result ) >= $limit ) {
				break;
			}
		}
		return $result;
	}

	/**
	 * Build the full index payload for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public static function build_index_row( $post ) {
		$text     = self::plain_text( $post );
		$title    = get_the_title( $post );
		$keywords = self::extract_keywords( $text, $title );

		return array(
			'post_id'      => (int) $post->ID,
			'post_type'    => $post->post_type,
			'post_status'  => $post->post_status,
			'title'        => $title,
			'url'          => get_permalink( $post ),
			'content_text' => $text,
			'content_hash' => self::hash( $title, $text ),
			'word_count'   => self::word_count( $text ),
			'keywords'     => wp_json_encode( $keywords ),
			'excerpt'      => wp_trim_words( $text, 60, '…' ),
			'sync_status'  => 'synced',
			'last_synced'  => current_time( 'mysql' ),
		);
	}

	/**
	 * English stopword set used by keyword extraction.
	 *
	 * @return array<string,bool>
	 */
	private static function stopwords() {
		static $set = null;
		if ( null !== $set ) {
			return $set;
		}
		$words = array( 'the', 'and', 'for', 'are', 'but', 'not', 'you', 'all', 'any', 'can', 'her', 'was', 'one', 'our', 'out', 'his', 'has', 'had', 'how', 'who', 'its', 'they', 'them', 'this', 'that', 'with', 'have', 'from', 'your', 'will', 'would', 'there', 'their', 'what', 'about', 'which', 'when', 'were', 'been', 'into', 'than', 'then', 'some', 'more', 'most', 'such', 'only', 'over', 'also', 'just', 'like', 'these', 'those', 'where', 'while', 'should', 'could', 'because', 'between', 'after', 'before', 'here', 'very', 'each', 'both', 'being', 'does', 'doing', 'down', 'under', 'again', 'once', 'other', 'own', 'same', 'too', 'use', 'using', 'used', 'get', 'got', 'via', 'per', 'etc', 'may', 'might', 'must', 'shall', 'upon' );
		$set   = array_fill_keys( $words, true );
		/**
		 * Filter the stopword list used for keyword extraction.
		 *
		 * @param array $set Map of stopword => true.
		 */
		$set = apply_filters( 'ail_stopwords', $set );
		return $set;
	}
}
