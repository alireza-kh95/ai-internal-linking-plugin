<?php
/**
 * HTTP client for the n8n + Ollama workflow.
 *
 * The plugin owns all data. n8n is a stateless processor: it receives a
 * payload, runs Ollama, and returns JSON. Requests are HMAC-signed so the
 * workflow can verify they came from this site.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_N8N {

	/**
	 * Ask the workflow to find internal-linking opportunities for a post.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $direction 'outbound' (links FROM this page) or 'inbound' (links TO it from other pages).
	 * @return array|WP_Error { opportunities: array, raw: array }
	 */
	public function find_opportunities( $post_id, $direction = 'outbound' ) {
		$index = AIL_DB::get_index_by_post( $post_id );
		if ( ! $index ) {
			return new WP_Error( 'ail_not_indexed', __( 'This page has not been indexed yet. Run a sync first.', 'ai-internal-linking' ) );
		}

		$url = AIL_Settings::get( 'n8n_find_url' );
		if ( empty( $url ) ) {
			return new WP_Error( 'ail_no_endpoint', __( 'No n8n "Find opportunities" webhook URL is configured. Add it in Settings.', 'ai-internal-linking' ) );
		}

		$direction = 'inbound' === $direction ? 'inbound' : 'outbound';
		$post = get_post( $post_id );
		$existing_links = $post ? AIL_Sync::extract_internal_links( $post ) : array();
		$linkable_text  = $post ? AIL_Content::linkable_text( $post ) : '';

		$payload = array(
			'action'    => 'find_opportunities',
			'direction' => $direction,
			'site_url'  => home_url(),
			'model'     => AIL_Settings::get( 'ollama_model' ),
			'source'    => array(
				'post_id' => (int) $post_id,
				'title'   => $index['title'],
				'url'     => $index['url'],
				'content' => $linkable_text,
				'keywords' => json_decode( (string) $index['keywords'], true ) ?: array(),
				'existing_links' => $existing_links,
			),
			'rules'     => array(
				'max_links'        => (int) AIL_Settings::get( 'max_links' ),
				'min_score'        => (float) AIL_Settings::get( 'min_score' ),
				'existing_anchors' => AIL_DB::get_applied_anchor_map(),
				'guidelines'       => self::guidelines(),
			),
		);

		if ( 'inbound' === $direction ) {
			$candidates = array();
			foreach ( AIL_DB::get_source_candidates( $post_id ) as $c ) {
				$candidate_post = get_post( $c['post_id'] );
				$candidate_links = $candidate_post ? AIL_Sync::extract_internal_links( $candidate_post ) : array();
				if ( AIL_Sync::has_destination( $candidate_links, $post_id, $index['url'] ) ) {
					continue;
				}
				$candidates[] = array(
					'post_id' => (int) $c['post_id'],
					'title'   => $c['title'],
					'url'     => $c['url'],
					'content' => $candidate_post ? mb_substr( AIL_Content::linkable_text( $candidate_post ), 0, 2000 ) : '',
					'existing_links' => $candidate_links,
				);
			}
			$payload['candidates'] = $candidates;
		} else {
			$targets = array();
			foreach ( AIL_DB::get_target_catalog( $post_id ) as $t ) {
				if ( AIL_Sync::has_destination( $existing_links, $t['post_id'], $t['url'] ) ) {
					continue;
				}
				$targets[] = array(
					'post_id'  => (int) $t['post_id'],
					'title'    => $t['title'],
					'url'      => $t['url'],
					'keywords' => json_decode( (string) $t['keywords'], true ) ?: array(),
					'summary'  => $t['excerpt'],
				);
			}
			$payload['targets'] = $targets;
		}

		if ( empty( 'inbound' === $direction ? $payload['candidates'] : $payload['targets'] ) ) {
			return array( 'opportunities' => array(), 'raw' => array( 'opportunities' => array() ) );
		}
		$response = $this->request( $url, $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 'inbound' === $direction ) {
			$opportunities = $this->normalise_inbound( $response, $payload['candidates'], $index );
		} else {
			$opportunities = $this->normalise_opportunities( $response, $payload['targets'], $linkable_text, $existing_links );
		}
		return array(
			'opportunities' => $opportunities,
			'raw'           => $response,
		);
	}

	/**
	 * Ask the workflow for an AI narrative on a set of deterministic audit findings.
	 *
	 * @param array $summary Audit summary + issue list.
	 * @return array|WP_Error
	 */
	public function audit_narrative( $summary ) {
		$url = AIL_Settings::get( 'n8n_audit_url' );
		if ( empty( $url ) ) {
			return new WP_Error( 'ail_no_endpoint', __( 'No n8n "Audit" webhook URL is configured.', 'ai-internal-linking' ) );
		}
		$payload = array(
			'action'   => 'audit',
			'site_url' => home_url(),
			'model'    => AIL_Settings::get( 'ollama_model' ),
			'summary'  => $summary,
		);
		return $this->request( $url, $payload );
	}

	/**
	 * Send a connectivity ping to a webhook URL.
	 *
	 * @param string $url Webhook URL.
	 * @return array|WP_Error
	 */
	public function ping( $url ) {
		return $this->request( $url, array( 'action' => 'ping', 'site_url' => home_url() ), 20 );
	}

	/**
	 * Perform a signed POST and decode the JSON body.
	 *
	 * @param string $url     Endpoint.
	 * @param array  $payload Body.
	 * @param int    $timeout Optional timeout override (seconds).
	 * @return array|WP_Error
	 */
	protected function request( $url, $payload, $timeout = null ) {
		$body      = wp_json_encode( $payload );
		$secret    = (string) AIL_Settings::get( 'shared_secret' );
		$signature = $secret ? hash_hmac( 'sha256', $body, $secret ) : '';
		$timeout   = null === $timeout ? (int) AIL_Settings::get( 'request_timeout' ) : $timeout;
		$timeout   = max( 10, min( 300, $timeout ) );

		$args = array(
			'method'  => 'POST',
			'timeout' => $timeout,
			'headers' => array(
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'X-AIL-Signature' => $signature,
				'X-AIL-Site'      => home_url(),
			),
			'body'    => $body,
		);

		$res = wp_remote_post( esc_url_raw( $url ), $args );

		if ( is_wp_error( $res ) ) {
			AIL_Logger::log( 'n8n request failed: ' . $res->get_error_message(), 'n8n', 'error' );
			return $res;
		}

		$code     = wp_remote_retrieve_response_code( $res );
		$raw_body = wp_remote_retrieve_body( $res );

		if ( $code < 200 || $code >= 300 ) {
			AIL_Logger::log( sprintf( 'n8n returned HTTP %d', $code ), 'n8n', 'error', array( 'body' => mb_substr( $raw_body, 0, 500 ) ) );
			return new WP_Error( 'ail_http_error', sprintf( __( 'The workflow returned HTTP %1$d. %2$s', 'ai-internal-linking' ), $code, mb_substr( wp_strip_all_tags( $raw_body ), 0, 200 ) ) );
		}

		$data = json_decode( $raw_body, true );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'ail_bad_json', __( 'The workflow response was not valid JSON. Check the n8n "Respond to Webhook" node.', 'ai-internal-linking' ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Normalise the workflow output into validated opportunity rows.
	 *
	 * Accepts either { opportunities: [...] } or a bare array, and tolerates
	 * the model returning a JSON string. Targets are validated against the
	 * catalogue so the AI cannot invent URLs.
	 *
	 * @param mixed  $response       Workflow response.
	 * @param array  $targets        Target catalogue (post_id keyed lookups).
	 * @param string $source_content Plain text of the source page, for anchor verification.
	 * @param array  $existing_links Existing links found in the source page.
	 * @return array
	 */
	protected function normalise_opportunities( $response, array $targets, $source_content = '', array $existing_links = array() ) {
		$list = $this->extract_list( $response );
		if ( ! $list ) {
			return array();
		}

		// Build id => target and url => target lookups for validation.
		$by_id  = array();
		$by_url = array();
		foreach ( $targets as $t ) {
			$by_id[ (int) $t['post_id'] ]              = $t;
			$by_url[ untrailingslashit( $t['url'] ) ]  = $t;
		}

		$source_lower = mb_strtolower( $source_content );
		$min_score    = (float) AIL_Settings::get( 'min_score' );

		$clean = array();
		foreach ( $list as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$anchor = isset( $o['anchor_text'] ) ? trim( (string) $o['anchor_text'] ) : '';
			if ( '' === $anchor ) {
				continue;
			}
			if ( AIL_Sync::has_anchor( $existing_links, $anchor ) ) {
				continue;
			}
			// The anchor must genuinely exist in the source body, or it can never be applied.
			if ( '' !== $source_lower && false === mb_strpos( $source_lower, mb_strtolower( $anchor ) ) ) {
				continue;
			}

			$target = null;
			if ( ! empty( $o['target_post_id'] ) && isset( $by_id[ (int) $o['target_post_id'] ] ) ) {
				$target = $by_id[ (int) $o['target_post_id'] ];
			} elseif ( ! empty( $o['target_url'] ) && isset( $by_url[ untrailingslashit( $o['target_url'] ) ] ) ) {
				$target = $by_url[ untrailingslashit( $o['target_url'] ) ];
			}
			if ( ! $target ) {
				continue; // Reject hallucinated targets.
			}

			$score = isset( $o['score'] ) ? max( 0, min( 1, (float) $o['score'] ) ) : 0.5;
			if ( $score < $min_score ) {
				continue;
			}

			$clean[] = array(
				'anchor_text'      => $anchor,
				'target_post_id'   => (int) $target['post_id'],
				'target_url'       => $target['url'],
				'target_title'     => $target['title'],
				'context_sentence' => isset( $o['context_sentence'] ) ? (string) $o['context_sentence'] : '',
				'score'            => $score,
				'reason'           => isset( $o['reason'] ) ? (string) $o['reason'] : '',
			);
		}
		return $clean;
	}

	/**
	 * Normalise inbound suggestions: each item names a SOURCE page (candidate)
	 * whose body contains the anchor, pointing at the requested target page.
	 *
	 * @param mixed $response   Workflow response.
	 * @param array $candidates Candidate source pages sent to the AI.
	 * @param array $target     Index row of the page receiving the links.
	 * @return array
	 */
	protected function normalise_inbound( $response, array $candidates, array $target ) {
		$list = $this->extract_list( $response );
		if ( ! $list ) {
			return array();
		}

		$by_id = array();
		foreach ( $candidates as $c ) {
			$by_id[ (int) $c['post_id'] ] = $c;
		}
		$min_score = (float) AIL_Settings::get( 'min_score' );

		$clean = array();
		foreach ( $list as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$anchor = isset( $o['anchor_text'] ) ? trim( (string) $o['anchor_text'] ) : '';
			$sid    = isset( $o['source_post_id'] ) ? (int) $o['source_post_id'] : 0;
			if ( '' === $anchor || ! isset( $by_id[ $sid ] ) ) {
				continue; // Unknown source page = hallucination.
			}
			// The anchor must exist in that candidate's body text.
			if ( false === mb_stripos( $by_id[ $sid ]['content'], $anchor ) ) {
				continue;
			}
			$score = isset( $o['score'] ) ? max( 0, min( 1, (float) $o['score'] ) ) : 0.5;
			if ( $score < $min_score ) {
				continue;
			}

			$clean[] = array(
				'source_post_id'   => $sid,
				'anchor_text'      => $anchor,
				'target_post_id'   => (int) $target['post_id'],
				'target_url'       => $target['url'],
				'target_title'     => $target['title'],
				'context_sentence' => isset( $o['context_sentence'] ) ? (string) $o['context_sentence'] : '',
				'score'            => $score,
				'reason'           => isset( $o['reason'] ) ? (string) $o['reason'] : '',
			);
		}
		return $clean;
	}

	/**
	 * Pull the opportunities array out of whatever shape the workflow returned.
	 *
	 * @param mixed $response Workflow response.
	 * @return array
	 */
	protected function extract_list( $response ) {
		$list = array();
		if ( isset( $response['opportunities'] ) ) {
			$list = $response['opportunities'];
		} elseif ( isset( $response[0] ) ) {
			$list = $response;
		} elseif ( isset( $response['output'] ) ) {
			$list = is_string( $response['output'] ) ? json_decode( $response['output'], true ) : $response['output'];
			if ( isset( $list['opportunities'] ) ) {
				$list = $list['opportunities'];
			}
		}
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Best-practice guidelines passed to the model (and shown in the n8n prompt).
	 *
	 * @return string[]
	 */
	public static function guidelines() {
		return array(
			'Only suggest links that are genuinely contextually relevant to the surrounding sentence.',
			'Use the same anchor text for the same destination consistently; never point the same keyword/anchor to different pages.',
			'Never suggest anchors that fall inside headings, navigation, buttons, captions or existing links.',
			'Prefer descriptive, natural anchor text; avoid generic anchors like "click here" or "read more".',
			'Avoid exact-match keyword stuffing and over-optimised anchors.',
			'Do not link the same destination more than once from the same page.',
			'Respect the maximum number of links for the page length; quality over quantity.',
			'Each anchor phrase must appear verbatim in the source content.',
			'Return the single best destination per anchor, but you may return alternative destinations as separate items when more than one page is a strong, defensible match.',
		);
	}
}

