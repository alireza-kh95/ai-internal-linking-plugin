<?php
/**
 * Minimal database-backed logger feeding the Activity panel.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Logger {

	/**
	 * Write a log line.
	 *
	 * @param string $message Human readable message.
	 * @param string $context sync|n8n|link|audit|general
	 * @param string $level   info|success|warning|error
	 * @param array  $data    Optional structured data.
	 */
	public static function log( $message, $context = 'general', $level = 'info', $data = array() ) {
		global $wpdb;
		$wpdb->insert(
			AIL_DB::table( 'log' ),
			array(
				'level'      => $level,
				'context'    => $context,
				'message'    => $message,
				'data'       => $data ? wp_json_encode( $data ) : null,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Recent log entries.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function recent( $limit = 30 ) {
		global $wpdb;
		$table = AIL_DB::table( 'log' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows ?: array();
	}
}
