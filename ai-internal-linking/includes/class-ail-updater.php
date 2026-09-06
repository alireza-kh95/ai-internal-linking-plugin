<?php
/** GitHub release updates through WordPress's native plugin updater. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIL_Updater {
	const REPOSITORY = 'alireza-kh95/ai-internal-linking-plugin';
	const SLUG = 'ai-internal-linking';
	const CACHE = 'ail_github_release';

	public static function boot() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'information' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
		add_action( 'load-plugins.php', array( __CLASS__, 'refresh_admin' ) );
		add_action( 'load-update-core.php', array( __CLASS__, 'refresh_admin' ) );
	}

	/** Refresh only this plugin's update entry, without rechecking other plugins. */
	public static function refresh_admin() {
		if ( ! current_user_can( 'update_plugins' ) || get_site_transient( 'ail_update_page_checked' ) ) {
			return;
		}
		set_site_transient( 'ail_update_page_checked', 1, MINUTE_IN_SECONDS );
		delete_site_transient( self::CACHE );
		$update = self::check( false, array(), AIL_PLUGIN_BASENAME, array() );
		if ( ! $update ) { return; }
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) ) { $updates = new stdClass(); }
		$updates->response = isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
		$updates->no_update = isset( $updates->no_update ) && is_array( $updates->no_update ) ? $updates->no_update : array();
		$update['plugin'] = AIL_PLUGIN_BASENAME;
		$update['new_version'] = $update['version'];
		unset( $updates->response[ AIL_PLUGIN_BASENAME ], $updates->no_update[ AIL_PLUGIN_BASENAME ] );
		if ( version_compare( $update['version'], AIL_VERSION, '>' ) ) {
			$updates->response[ AIL_PLUGIN_BASENAME ] = (object) $update;
		} else {
			$updates->no_update[ AIL_PLUGIN_BASENAME ] = (object) $update;
		}
		set_site_transient( 'update_plugins', $updates );
	}

	public static function release() {
		$cached = get_site_transient( self::CACHE );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}
		$response = wp_remote_get( 'https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'AI-Internal-Linking/' . AIL_VERSION ),
		) );
		$release = array();
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$release = self::parse_release( json_decode( wp_remote_retrieve_body( $response ), true ) );
		}
		// Brief negative caching prevents repeated requests during GitHub outages.
		set_site_transient( self::CACHE, $release, $release ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $release;
	}

	public static function parse_release( $data ) {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return array();
		}
		$tag = $data['tag_name'] ?? '';
		if ( ! is_string( $tag ) || ! preg_match( '/^v?(\d+\.\d+\.\d+)$/D', $tag, $match ) ) {
			return array();
		}
		$expected = 'https://github.com/' . self::REPOSITORY . '/releases/download/' . $tag . '/' . self::SLUG . '.zip';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( self::SLUG . '.zip' === ( $asset['name'] ?? '' ) && $expected === ( $asset['browser_download_url'] ?? '' ) && 'uploaded' === ( $asset['state'] ?? '' ) ) {
				return array(
					'version' => $match[1],
					'package' => $expected,
					'notes' => is_string( $data['body'] ?? null ) ? $data['body'] : '',
				);
			}
		}
		return array();
	}

	public static function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( AIL_PLUGIN_BASENAME !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id' => 'https://github.com/' . self::REPOSITORY,
			'slug' => self::SLUG,
			'version' => $release['version'],
			'url' => 'https://github.com/' . self::REPOSITORY . '/releases',
			'package' => $release['package'],
		);
	}

	public static function information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name' => 'AI Internal Linking',
			'slug' => self::SLUG,
			'version' => $release['version'],
			'author' => '<a href="https://rankermind.com">Alireza Khosravani</a>',
			'homepage' => 'https://github.com/' . self::REPOSITORY,
			'download_link' => $release['package'],
			'sections' => array(
				'description' => 'Contextual internal linking with WordPress, ACF, n8n and Ollama.',
				'changelog' => nl2br( esc_html( $release['notes'] ) ),
			),
		);
	}

	public static function clear_cache( $upgrader, $options ) {
		if ( 'plugin' === ( $options['type'] ?? '' ) && 'update' === ( $options['action'] ?? '' ) ) {
			$plugins = $options['plugins'] ?? array( $options['plugin'] ?? '' );
			if ( in_array( AIL_PLUGIN_BASENAME, $plugins, true ) ) {
				delete_site_transient( self::CACHE );
			}
		}
	}
}

