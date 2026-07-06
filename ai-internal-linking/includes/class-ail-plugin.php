<?php
/**
 * Plugin orchestrator. Wires the moving parts together.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var AIL_Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var AIL_Sync
	 */
	private $sync;

	/**
	 * @var AIL_REST
	 */
	private $rest;

	/**
	 * @var AIL_Admin
	 */
	private $admin;

	/**
	 * Get the singleton.
	 *
	 * @return AIL_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin (called on plugins_loaded).
	 */
	public function boot() {
		$this->maybe_upgrade();

		$this->sync  = new AIL_Sync();
		$this->rest  = new AIL_REST();
		$this->admin = new AIL_Admin();

		$this->sync->hooks();
		$this->rest->hooks();

		if ( is_admin() ) {
			$this->admin->hooks();
		}
	}

	/**
	 * Run schema upgrades when the stored DB version is behind.
	 */
	private function maybe_upgrade() {
		if ( get_option( 'ail_db_version' ) !== AIL_DB_VERSION ) {
			AIL_Install::create_tables();
			AIL_Settings::maybe_set_defaults();
			update_option( 'ail_db_version', AIL_DB_VERSION );
		}
	}

	/**
	 * Sync engine accessor.
	 *
	 * @return AIL_Sync
	 */
	public function sync() {
		if ( ! $this->sync ) {
			$this->sync = new AIL_Sync();
		}
		return $this->sync;
	}
}
