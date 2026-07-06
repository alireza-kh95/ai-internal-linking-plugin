<?php
/**
 * Installation, schema creation and upgrades.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Install {

	/**
	 * Run on activation.
	 */
	public static function activate() {
		self::create_tables();
		AIL_Settings::maybe_set_defaults();
		update_option( 'ail_db_version', AIL_DB_VERSION );

		if ( ! wp_next_scheduled( 'ail_cron_reconcile' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'ail_cron_reconcile' );
		}
		// Mark that a first full sync should run.
		if ( false === get_option( 'ail_first_sync_done', false ) ) {
			update_option( 'ail_needs_full_sync', 1 );
		}
	}

	/**
	 * Run on deactivation.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'ail_cron_reconcile' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'ail_cron_reconcile' );
		}
	}

	/**
	 * Create or upgrade DB schema. Safe to call repeatedly (dbDelta).
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = AIL_DB::table_prefix();

		$index = "CREATE TABLE {$p}index (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			post_type VARCHAR(32) NOT NULL DEFAULT 'post',
			post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
			title TEXT NOT NULL,
			url TEXT NOT NULL,
			content_text LONGTEXT NOT NULL,
			content_hash CHAR(40) NOT NULL DEFAULT '',
			word_count INT UNSIGNED NOT NULL DEFAULT 0,
			keywords LONGTEXT NULL,
			excerpt TEXT NULL,
			inbound_count INT UNSIGNED NOT NULL DEFAULT 0,
			outbound_count INT UNSIGNED NOT NULL DEFAULT 0,
			sync_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			last_opportunity_at DATETIME NULL,
			last_synced DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id),
			KEY sync_status (sync_status),
			KEY post_type (post_type)
		) {$charset_collate};";

		$opportunities = "CREATE TABLE {$p}opportunities (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(36) NOT NULL DEFAULT '',
			source_post_id BIGINT UNSIGNED NOT NULL,
			target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			anchor_text VARCHAR(255) NOT NULL,
			context_sentence TEXT NULL,
			target_url TEXT NOT NULL,
			target_title TEXT NULL,
			score FLOAT NOT NULL DEFAULT 0,
			reason TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'suggested',
			applied_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY source_post_id (source_post_id),
			KEY target_post_id (target_post_id),
			KEY status (status),
			KEY batch_id (batch_id)
		) {$charset_collate};";

		$links = "CREATE TABLE {$p}links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			opportunity_id BIGINT UNSIGNED NULL,
			source_post_id BIGINT UNSIGNED NOT NULL,
			target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			anchor_text VARCHAR(255) NOT NULL,
			target_url TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			applied_at DATETIME NOT NULL,
			removed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY source_post_id (source_post_id),
			KEY target_post_id (target_post_id),
			KEY status (status)
		) {$charset_collate};";

		$audit = "CREATE TABLE {$p}audit (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id VARCHAR(36) NOT NULL,
			post_id BIGINT UNSIGNED NULL,
			issue_type VARCHAR(50) NOT NULL,
			severity VARCHAR(10) NOT NULL DEFAULT 'info',
			title VARCHAR(255) NOT NULL,
			message TEXT NULL,
			data LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY post_id (post_id),
			KEY issue_type (issue_type)
		) {$charset_collate};";

		$log = "CREATE TABLE {$p}log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(10) NOT NULL DEFAULT 'info',
			context VARCHAR(50) NOT NULL DEFAULT 'general',
			message TEXT NOT NULL,
			data LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY context (context),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $index );
		dbDelta( $opportunities );
		dbDelta( $links );
		dbDelta( $audit );
		dbDelta( $log );
	}

	/**
	 * Drop every plugin table. Used by the "reset all" tool and uninstall.
	 */
	public static function drop_tables() {
		global $wpdb;
		$p      = AIL_DB::table_prefix();
		$tables = array( 'index', 'opportunities', 'links', 'audit', 'log' );
		foreach ( $tables as $t ) {
			// Table names cannot be parameterised; they are built from a trusted prefix.
			$wpdb->query( "DROP TABLE IF EXISTS {$p}{$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}
}
