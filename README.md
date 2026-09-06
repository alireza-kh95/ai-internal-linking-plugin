# AI Internal Linking

WordPress plugin by Alireza Khosravani. Uses n8n and Ollama to suggest contextual internal links with editorial approval.

## Installation and updates

Download `ai-internal-linking.zip` from the latest GitHub release. Upload it through WordPress **Plugins > Add New Plugin > Upload Plugin**. For an existing installation, choose **Replace current with uploaded**; do not delete the plugin first.

Version 1.3.0 introduces the updater. Install it manually once. Later published releases appear on the standard Plugins and Dashboard Updates screens. WordPress controls check timing, and the GitHub response is cached for up to one hour. Routine commits do not trigger production updates until the plugin version is increased and a release is published.

## Publishing

1. Update the version in both the plugin header and `AIL_VERSION`, plus `readme.txt`.
2. Update `RELEASE_NOTES.md` and commit the changes to `main`.
3. The GitHub Actions workflow validates PHP/JavaScript and regression tests, builds the ZIP with the `ai-internal-linking/` folder, and publishes a new versioned release.

Existing releases are never overwritten. The package includes only `ai-internal-linking/`, excluding development and server configuration files. The old root ZIP is a legacy snapshot; use GitHub Releases for current downloads.

## Existing links

The plugin scans rendered post content and nested ACF link values, including flexible content, card URL fields and WYSIWYG HTML. Existing destinations are excluded before AI analysis and checked again when saved suggestions are displayed or applied. ACF data is read fresh for these checks. Theme-generated links that are not represented in post content or ACF fields may require a theme integration.

Run a sync to refresh dashboard link counts after upgrading. Existing tables and settings are retained during updates.

