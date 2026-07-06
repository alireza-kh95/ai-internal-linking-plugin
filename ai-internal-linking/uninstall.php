<?php
/**
 * Uninstall handler — removes every trace of the plugin.
 *
 * @package AIL
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

wp_clear_scheduled_hook( 'ail_cron_reconcile' );

/*
 * Preserve data by default.
 *
 * WordPress runs uninstall.php when a user deletes the plugin. Many site owners
 * delete/reinstall a plugin while trying to update or test it, and dropping the
 * content index/settings in that path is too destructive for this plugin.
 *
 * The Settings screen has an explicit "delete data on uninstall" checkbox.
 * A constant is also supported for locked-down environments.
 */
$settings            = get_option( 'ail_settings', array() );
$delete_on_uninstall = is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] );
$delete_by_constant  = defined( 'AIL_DELETE_DATA_ON_UNINSTALL' ) && true === AIL_DELETE_DATA_ON_UNINSTALL;

if ( ! $delete_on_uninstall && ! $delete_by_constant ) {
	return;
}

// Drop all plugin tables only when explicitly enabled.
$prefix = $wpdb->prefix . 'ail_';
foreach ( array( 'index', 'opportunities', 'links', 'audit', 'log' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$prefix}{$t}" ); // phpcs:ignore WordPress.DB
}

// Remove options only when explicitly enabled.
$options = array(
	'ail_settings',
	'ail_db_version',
	'ail_first_sync_done',
	'ail_needs_full_sync',
	'ail_last_full_sync',
	'ail_last_audit',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}
