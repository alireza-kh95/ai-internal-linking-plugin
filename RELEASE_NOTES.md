Existing linked anchor phrases are now removed from saved suggestions, even when a stale suggestion points to a different destination.

The check reads current post and nested ACF links whenever the opportunity drawer opens. Matching ignores case and whitespace. Applying a link also checks again to prevent duplicate anchors during concurrent edits.

Includes the nested ACF rich-text fix and prompt-side suppression of already-linked anchors. Opening Plugins checks for this release at most once per minute.

