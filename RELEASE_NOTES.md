Fix link insertion and removal in ACF rich-text editors nested inside groups, repeaters and flexible-content rows.

The plugin now locates the exact raw field path, preserves sibling fields and rows, and refuses stale edits. Plain-text fields remain excluded because themes may escape their HTML.

Update from version 1.3.0 through the WordPress Plugins screen. Earlier versions need a manual ZIP replacement once. No n8n workflow change is required.

