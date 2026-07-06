# AI Internal Linking

AI-powered internal linking for WordPress. Indexes all page content with delta
sync, finds contextual internal-linking opportunities via an **n8n + Ollama**
workflow, applies links safely, and audits your internal link structure against
best practices — all from a modern dashboard.

- **Author:** Alireza Khosravani
- **Author URI:** https://rankermind.com

---

## Architecture at a glance

```
WordPress (source of truth)                         n8n (stateless processor)
┌───────────────────────────────────────┐          ┌──────────────────────────────┐
│  Content index (delta sync)            │  HTTPS   │  Webhook                     │
│  Opportunities · Links · Audit · Log   │ ───────▶ │   → route ping/find/audit    │
│  Modern admin dashboard (REST + JS)    │          │   → Ollama (JSON mode)       │
│  Safe DOM link insertion + auditor     │ ◀─────── │   → return JSON              │
└───────────────────────────────────────┘  JSON    └──────────────────────────────┘
                                                              │
                                                          Ollama (your hardware)
```

**Why WordPress owns the data:** fast dashboard queries, full in-plugin cleanup &
uninstall, and zero per-site provisioning in n8n. The same workflow JSON works on
every site you install the plugin on. n8n never stores anything.

## Project layout

```
ai-internal-linking.php          Bootstrap: constants, autoloader, activation hooks
uninstall.php                    Preserves data unless uninstall deletion is enabled
includes/
  class-ail-install.php          dbDelta schema + drop/reset
  class-ail-db.php               Data-access layer (prepared statements)
  class-ail-content.php          Extraction, normalisation, hashing, keyword n-grams
  class-ail-sync.php             Delta sync (save_post hooks, cron), link-graph counts
  class-ail-n8n.php              Signed HTTP client + response validation
  class-ail-linker.php           DOMDocument-based safe link insertion / removal
  class-ail-auditor.php          Deterministic link-graph checks + AI narrative
  class-ail-settings.php         Settings storage + sanitisation
  class-ail-rest.php             REST API (namespace ail/v1)
  class-ail-admin.php            Menu + asset enqueue + app container
  class-ail-logger.php           DB-backed activity log
  class-ail-plugin.php           Orchestrator (singleton)
assets/
  css/admin.css                  Scoped design system
  js/admin.js                    Dependency-free dashboard SPA
n8n/
  internal-linking.workflow.json Importable workflow
  README.md                      n8n + Ollama setup guide
```

## Database tables (prefix `{wp_prefix}ail_`)

| Table           | Purpose |
|-----------------|---------|
| `ail_index`         | One row per indexed page: text, content hash, word count, keywords, inbound/outbound counts, sync status |
| `ail_opportunities` | AI suggestions (anchor, target, context, score, reason, status) |
| `ail_links`         | Applied links log (for review/undo) |
| `ail_audit`         | Latest audit run findings |
| `ail_log`           | Activity feed |

## Choosing the Ollama model

Settings → **Model** has one manual field: type the exact Ollama model name that
your n8n "Ollama" credential can run. For self-hosted Ollama, use a local tag
such as `llama3.1:8b`. For Ollama Cloud, include the cloud tag yourself, such as
`deepseek-v4-pro:cloud`.

The plugin no longer calls Ollama directly or tries to list/transform model
names. It only passes the saved model string to n8n with each request.

## Download the n8n workflow

Settings → n8n workflow → **Download workflow JSON** streams the exact file at
`n8n/internal-linking.workflow.json` from the plugin (via `admin-post.php`, nonce +
`manage_options` protected) so you always get the version bundled with the plugin
you're running, even if you customised your imported copy in n8n.

## ACF support

Themes that store body copy in **Advanced Custom Fields** (common for `service`/CPT
layouts) are handled: the indexer recursively pulls prose from all ACF fields (so word
counts and AI suggestions work), the link graph includes links inside ACF **WYSIWYG**
fields, and applying/removing a link writes back into the correct WYSIWYG field via
`update_field`. Plain `text`/`textarea` ACF fields are indexed for discovery but can't
receive an HTML link, so links are only placed in `post_content` or WYSIWYG fields.

## Delta sync

- `save_post` / `transition_post_status` re-index a single page only when its
  content hash changes.
- Trash/delete removes it from the index.
- An hourly cron (and the Tools → *Reconcile* button) catches anything changed
  outside the editor and prunes rows for posts that no longer qualify.

## Find → review → apply flow

1. **Pages** tab → *Find opportunities* on a row opens a side drawer with a live
   loading bar.
2. The server builds a payload (source content + catalogue of all other pages +
   best-practice rules + your existing anchor→URL map) and POSTs it to n8n.
3. Ollama returns suggestions; the plugin **re-validates** them (rejects invented
   targets and anchors not present in the page), stores them, and returns them
   grouped by anchor.
4. You tick opportunities, pick a destination when a phrase has several good targets,
   optionally edit the linked phrase, then **Apply**.
5. Links are inserted with `DOMDocument` — only into eligible text nodes, never in
   headings, existing links, buttons, code or captions.

## Audit checks (deterministic) + AI narrative

Orphan pages · unresolved internal links · self-links · thin outbound (long page,
no links) · excessive outbound · anchor dilution (one anchor → many targets) ·
over-optimised anchors. Produces a 0–100 score and, if the audit webhook is set, an
AI-written prioritised action plan.

## Setup

1. Install & activate the plugin → first sync is scheduled automatically (or click
   **Sync now**).
2. Import the workflow and wire Ollama — see [`n8n/README.md`](n8n/README.md).
3. **AI Linking → Settings:** paste the n8n webhook URL (same URL for Find & Audit),
   set your model, Save, **Test connection**.

## REST API (all require `manage_options` + nonce)

`GET /stats` · `GET /pages` · `POST /sync` · `POST /opportunities/find` ·
`GET /opportunities` · `POST /opportunities/apply` · `POST /opportunities/status` ·
`GET /links` · `POST /links/remove` · `POST /audit/run` · `GET /audit` ·
`GET /activity` · `GET|POST /settings` · `POST /test-connection` · `POST /maintenance`

## Uninstall behavior

Deleting the plugin preserves its database tables and settings by default, which
protects data during delete/reinstall update workflows. In **Settings →
Uninstall behavior**, enable **Delete all plugin tables and settings on
uninstall** only when you intentionally want WordPress uninstall to permanently
remove the content index, opportunities, applied-link records, audit results,
activity log, and plugin settings.

## Extensibility (filters)

- `ail_indexable_post_types` — change which post types are indexed.
- `ail_should_index_post` — per-post indexing veto.
- `ail_stopwords` — tune keyword extraction.

## Requirements

WordPress 6.0+, PHP 7.4+, an n8n instance, and an Ollama server reachable from n8n.
