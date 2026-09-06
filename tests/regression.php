<?php
// Standalone contract tests; WordPress integration is checked separately.
define( 'ABSPATH', __DIR__ );
define( 'AIL_VERSION', '1.3.0' );
define( 'AIL_PLUGIN_BASENAME', 'ai-internal-linking/ai-internal-linking.php' );
class WP_Post { public $ID = 1; public $post_type = 'page'; public $post_content = ''; }
class WP_Http {
	public static function make_absolute_url( $url, $base ) {
		if ( 0 === strpos( $url, '//' ) ) { return 'https:' . $url; }
		if ( 0 === strpos( $url, '/' ) ) { return 'https://example.com' . $url; }
		return $url;
	}
}
function get_fields( $id ) { return $GLOBALS['acf_fixture']; }
function esc_url( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function get_permalink( $post ) { return 'https://example.com/source/'; }
function get_the_title( $post ) { return 'Source'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function url_to_postid( $url ) { return strpos( $url, '/service' ) !== false ? 2 : 0; }
function wpautop( $html ) { return $html; }
function do_shortcode( $html ) { return $html; }
function verify( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
require __DIR__ . '/../ai-internal-linking/includes/class-ail-content.php';
require __DIR__ . '/../ai-internal-linking/includes/class-ail-sync.php';
require __DIR__ . '/../ai-internal-linking/includes/class-ail-updater.php';

$GLOBALS['acf_fixture'] = array( 'blocks' => array( array(
	'acf_fc_layout' => 'read_more_cards',
	'card_section_wrapper' => array( 'section_cards' => array(
		array( 'card_link_url' => '/service/', 'card_icon' => array( 'url' => '/image.png', 'mime_type' => 'image/png' ) ),
		array( 'button' => array( 'url' => 'https://example.com/other', 'title' => 'Read more', 'target' => '' ) ),
		array( 'body' => '<p><a href="https://example.com/nested">Nested</a></p>' ),
	) ),
) ) );
$post = new WP_Post();
$post->post_content = '<p><a href = "https://example.com/body">Body</a><a href="//elsewhere.com/no">External</a></p>';
$links = AIL_Sync::extract_internal_links( $post );
verify( count( $links ) === 4, 'Body and all nested ACF links must be included, excluding images and external URLs.' );
verify( AIL_Sync::has_destination( $links, 2, 'https://example.com/alias' ), 'Post ID must match aliases.' );
verify( AIL_Sync::has_destination( $links, 0, 'https://example.com/other/#section' ), 'Fragments and trailing slashes must not bypass duplicate detection.' );
verify( ! AIL_Sync::has_destination( $links, 0, 'https://example.com/new' ), 'New destinations must remain eligible.' );
verify( ! AIL_Sync::has_destination( $links, 0, 'https://elsewhere.com/other' ), 'External host must not match.' );
verify( ! AIL_Sync::has_destination( $links, 0, 'https://example.com/other?variant=1' ), 'Meaningful query strings must be preserved.' );

$release = array( 'tag_name' => 'v1.3.1', 'draft' => false, 'prerelease' => false, 'body' => 'Changes', 'assets' => array( array(
	'name' => 'ai-internal-linking.zip', 'state' => 'uploaded',
	'browser_download_url' => 'https://github.com/alireza-kh95/ai-internal-linking-plugin/releases/download/v1.3.1/ai-internal-linking.zip',
) ) );
verify( AIL_Updater::parse_release( $release )['version'] === '1.3.1', 'Stable versioned asset must be accepted.' );
$release['prerelease'] = true;
verify( ! AIL_Updater::parse_release( $release ), 'Prereleases must not update production sites.' );
$release['prerelease'] = false;
$release['assets'][0]['browser_download_url'] = 'https://elsewhere.com/plugin.zip';
verify( ! AIL_Updater::parse_release( $release ), 'Unexpected package URL must be rejected.' );
$release['assets'] = array();
verify( ! AIL_Updater::parse_release( $release ), 'Release without installable archive must not be offered.' );
echo "Passed 11 regression checks.\n";

function get_field_objects( $id, $formatted ) {
	return array( array( 'key' => 'field_blocks', 'name' => 'blocks', 'type' => 'flexible_content',
		'value' => $GLOBALS['editor_value'],
		'layouts' => array( array( 'name' => 'hero', 'sub_fields' => array(
			array( 'key' => 'field_group', 'name' => 'group', 'type' => 'group', 'sub_fields' => array(
				array( 'key' => 'field_body', 'name' => 'body', 'type' => 'wysiwyg' ),
				array( 'key' => 'field_plain', 'name' => 'plain', 'type' => 'text' ),
			) ),
		) ) ),
	) );
}
function get_field( $key, $id, $formatted ) { return $GLOBALS['editor_value']; }
function update_field( $key, $value, $id ) { $GLOBALS['editor_value'] = $value; return true; }
class AIL_Settings { public static function get( $key ) { return ''; } }
require __DIR__ . '/../ai-internal-linking/includes/class-ail-linker.php';
$phrase = 'route every call to the team you already have';
$html = '<p>Give callers one free-to-call 0800 number and ' . $phrase . '.</p>';
$GLOBALS['editor_value'] = array(
	array( 'acf_fc_layout' => 'hero', 'field_group' => array( 'field_body' => $html, 'field_plain' => 'Preserve this' ) ),
	array( 'acf_fc_layout' => 'hero', 'group' => array( 'body' => '<p>Second row</p>', 'plain' => 'Also preserve' ) ),
);
$editors = array_values( AIL_Content::acf_wysiwyg_fields( 1 ) );
verify( count( $editors ) === 2, 'Nested rich-text fields must be found; plain-text fields must not be edited.' );
verify( $editors[0]['path'] === array( 0, 'field_group', 'field_body' ), 'Key-based values must retain exact path.' );
verify( $editors[1]['path'] === array( 1, 'group', 'body' ), 'Name-based values must retain exact path.' );
$insert = new ReflectionMethod( 'AIL_Linker', 'insert_into_content' );
$insert->setAccessible( true );
$result = $insert->invoke( new AIL_Linker(), $editors[0]['html'], $phrase, $phrase, 'https://example.com/routing' );
verify( $result['changed'], 'Screenshot phrase must be linkable inside rich text.' );
verify( AIL_Content::save_acf_editor( 1, $editors[0], $result['content'] ), 'Nested editor must save.' );
verify( $GLOBALS['editor_value'][0]['field_group']['field_plain'] === 'Preserve this', 'Sibling field must be retained.' );
verify( $GLOBALS['editor_value'][1]['group']['body'] === '<p>Second row</p>', 'Neighbouring row must be retained.' );
verify( ! AIL_Content::save_acf_editor( 1, $editors[0], '<p>Stale write</p>' ), 'Stale editor must not overwrite a newer value.' );
$updated = array_values( AIL_Content::acf_wysiwyg_fields( 1 ) );
verify( AIL_Content::save_acf_editor( 1, $updated[0], $html ), 'Undo must restore the exact nested field.' );
echo "Passed 9 nested ACF editor checks.\n";

