<?php
/**
 * Admin menu, asset loading and the app container.
 *
 * @package AIL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Admin {

	const MENU_SLUG = 'ai-internal-linking';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . AIL_PLUGIN_BASENAME, array( $this, 'action_links' ) );
		add_action( 'admin_post_ail_download_workflow', array( $this, 'download_workflow' ) );
	}

	/**
	 * Stream the bundled n8n workflow JSON as a file download.
	 */
	public function download_workflow() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-internal-linking' ), 403 );
		}
		check_admin_referer( 'ail_download_workflow' );

		$file = AIL_PLUGIN_DIR . 'n8n/internal-linking.workflow.json';
		if ( ! is_readable( $file ) ) {
			wp_die( esc_html__( 'The workflow file could not be found.', 'ai-internal-linking' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="internal-linking.workflow.json"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}

	/**
	 * Register the top-level admin menu.
	 */
	public function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="black"><path d="M5.172 6.586a1 1 0 0 1 0 1.414L3.757 9.414a2 2 0 1 0 2.829 2.829L8 10.828a1 1 0 0 1 1.414 1.415L8 13.657A4 4 0 1 1 2.343 8l1.414-1.414a1 1 0 0 1 1.415 0m5.364-1.122a1 1 0 0 1 0 1.415l-3.657 3.657A1 1 0 0 1 5.464 9.12l3.657-3.657a1 1 0 0 1 1.415 0m3.12-3.12a4 4 0 0 1 0 5.656l-1.413 1.414A1 1 0 1 1 10.828 8l1.415-1.414a2 2 0 0 0-2.829-2.829L8 5.172a1 1 0 0 1-1.414-1.415L8 2.343a4 4 0 0 1 5.657 0"/></svg>' );

		add_menu_page(
			__( 'AI Internal Linking', 'ai-internal-linking' ),
			__( 'AI Linking', 'ai-internal-linking' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			$icon,
			58
		);
	}

	/**
	 * Add a Settings shortcut on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url      = admin_url( 'admin.php?page=' . self::MENU_SLUG . '#/settings' );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Dashboard', 'ai-internal-linking' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Enqueue assets only on the plugin screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'ail-admin',
			AIL_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AIL_VERSION
		);

		wp_enqueue_script(
			'ail-admin',
			AIL_PLUGIN_URL . 'assets/js/admin.js',
			array(), // dependency-free vanilla JS app
			AIL_VERSION,
			true
		);

		wp_localize_script(
			'ail-admin',
			'AIL',
			array(
				'root'               => esc_url_raw( rest_url( AIL_REST::NS ) ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'adminUrl'           => admin_url(),
				'version'            => AIL_VERSION,
				'docsUrl'            => 'https://rankermind.com',
				'downloadWorkflowUrl'  => admin_url( 'admin-post.php?action=ail_download_workflow' ),
				'downloadWorkflowNonce' => wp_create_nonce( 'ail_download_workflow' ),
			)
		);
	}

	/**
	 * Render the SPA container. All UI is rendered client-side by admin.js.
	 */
	public function render() {
		echo '<div id="ail-app" class="ail-app"><div class="ail-boot"><span class="ail-spinner"></span> ' . esc_html__( 'Loading AI Internal Linking…', 'ai-internal-linking' ) . '</div></div>';
	}
}
