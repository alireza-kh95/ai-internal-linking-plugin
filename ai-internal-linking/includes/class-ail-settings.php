<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * Stored as a single option array (ail_settings) to keep the options table tidy.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Settings {

	const OPTION = 'ail_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'n8n_find_url'    => '',
			'n8n_audit_url'   => '',
			'shared_secret'   => '',
			'ollama_model'    => '',
			'post_types'      => array( 'post', 'page' ),
			'max_links'       => 5,
			'min_score'       => 0.6,
			'request_timeout' => 120,
			'link_target'     => '',     // '' or '_blank'
			'link_rel'        => '',     // e.g. 'nofollow'
			'delete_on_uninstall' => 0,
		);
	}

	/**
	 * Set defaults on activation if no settings exist, and generate a secret.
	 */
	public static function maybe_set_defaults() {
		$current = get_option( self::OPTION, null );
		if ( null === $current ) {
			$defaults                  = self::defaults();
			$defaults['shared_secret'] = wp_generate_password( 40, false, false );
			add_option( self::OPTION, $defaults );
		}
	}

	/**
	 * Get all settings (merged with defaults).
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Persist a settings array (sanitised, merged over existing).
	 *
	 * @param array $input Raw input.
	 * @return array The saved settings.
	 */
	public static function update( array $input ) {
		$current = self::all();
		$clean   = $current;

		if ( isset( $input['n8n_find_url'] ) ) {
			$clean['n8n_find_url'] = esc_url_raw( trim( $input['n8n_find_url'] ) );
		}
		if ( isset( $input['n8n_audit_url'] ) ) {
			$clean['n8n_audit_url'] = esc_url_raw( trim( $input['n8n_audit_url'] ) );
		}
		if ( isset( $input['shared_secret'] ) ) {
			$clean['shared_secret'] = sanitize_text_field( $input['shared_secret'] );
		}
		if ( isset( $input['ollama_model'] ) ) {
			$clean['ollama_model'] = sanitize_text_field( $input['ollama_model'] );
		}
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$valid                = get_post_types( array( 'public' => true ) );
			$clean['post_types']  = array_values( array_intersect( array_map( 'sanitize_key', $input['post_types'] ), array_keys( $valid ) ) );
			if ( empty( $clean['post_types'] ) ) {
				$clean['post_types'] = array( 'post', 'page' );
			}
		}
		if ( isset( $input['max_links'] ) ) {
			$clean['max_links'] = max( 1, min( 50, (int) $input['max_links'] ) );
		}
		if ( isset( $input['min_score'] ) ) {
			$clean['min_score'] = max( 0, min( 1, (float) $input['min_score'] ) );
		}
		if ( isset( $input['request_timeout'] ) ) {
			$clean['request_timeout'] = max( 10, min( 300, (int) $input['request_timeout'] ) );
		}
		if ( isset( $input['link_target'] ) ) {
			$clean['link_target'] = '_blank' === $input['link_target'] ? '_blank' : '';
		}
		if ( isset( $input['link_rel'] ) ) {
			$clean['link_rel'] = sanitize_text_field( $input['link_rel'] );
		}
		if ( isset( $input['delete_on_uninstall'] ) ) {
			$clean['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] ) ? 1 : 0;
		}

		update_option( self::OPTION, $clean );
		return $clean;
	}

	/**
	 * Settings safe to expose to the admin app (secret is masked).
	 *
	 * @return array
	 */
	public static function for_app() {
		$all                    = self::all();
		$all['has_secret']      = ! empty( $all['shared_secret'] );
		$all['shared_secret']   = $all['shared_secret'] ? str_repeat( '•', 8 ) . substr( $all['shared_secret'], -4 ) : '';
		return $all;
	}
}
