<?php
/**
 * Plugin Name:       AI Internal Linking
 * Plugin URI:        https://rankermind.com
 * Description:       AI-powered internal linking. Indexes all page content with delta sync, finds contextual internal-linking opportunities via an n8n + Ollama workflow, applies links safely, and audits your internal link structure against best practices.
 * Version:           1.5.2
 * Update URI:        https://github.com/alireza-kh95/ai-internal-linking-plugin
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Alireza Khosravani
 * Author URI:        https://rankermind.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-internal-linking
 * Domain Path:       /languages
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/* -------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------- */
define( 'AIL_VERSION', '1.5.2' );
define( 'AIL_DB_VERSION', '1' );
define( 'AIL_PLUGIN_FILE', __FILE__ );
define( 'AIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Autoloader (maps AIL_Foo_Bar -> includes/class-ail-foo-bar.php)
 * ---------------------------------------------------------------------- */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'AIL_' ) !== 0 ) {
			return;
		}
		$slug = strtolower( str_replace( '_', '-', substr( $class, 4 ) ) );
		$file = AIL_PLUGIN_DIR . 'includes/class-ail-' . $slug . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/* -------------------------------------------------------------------------
 * Activation / Deactivation
 * ---------------------------------------------------------------------- */
register_activation_hook(
	__FILE__,
	function () {
		AIL_Install::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		AIL_Install::deactivate();
	}
);

/* -------------------------------------------------------------------------
 * Boot
 * ---------------------------------------------------------------------- */
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'ai-internal-linking', false, dirname( AIL_PLUGIN_BASENAME ) . '/languages' );
		AIL_Plugin::instance()->boot();
		AIL_Updater::boot();
	}
);

