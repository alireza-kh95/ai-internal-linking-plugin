=== AI Internal Linking ===
Contributors: alirezakhosravani
Author URI: https://rankermind.com
Tags: internal linking, seo, ai, ollama, n8n
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered internal linking: index your content, find contextual link opportunities with Ollama via n8n, apply them safely, and audit your link structure.

== Description ==

AI Internal Linking gives you a modern dashboard to manage internal links across your whole site.

* **Content index with delta sync** — every published page is indexed into the WordPress database. When a page changes, only that page is re-synced (change detection via a content hash). Your site owns all the data.
* **Find opportunities (AI)** — one click per page sends its content plus a catalogue of your other pages to an n8n workflow running Ollama. The model proposes contextual internal links following best practices.
* **Review & apply** — opportunities load in a side panel grouped by anchor. Tick the ones you want, pick a destination when several pages fit the same phrase, optionally reword the linked phrase, then apply. Links are inserted safely (never inside headings, existing links, buttons or code).
* **Site-wide opportunity scan** — scan every indexed page in a controlled queue, watch progress, then select and apply links across the whole site from one review screen.
* **Internal linking audit (AI)** — scans your link graph for orphan pages, anchor dilution, over-optimised anchors, thin/excessive outbound linking, self-links and unresolved links, scores your site, and (optionally) returns an AI-written, prioritised action plan.
* **Clean, no-mess data** — a Tools page lets you reconcile the index, clear opportunities/audit/logs, or reset everything. Uninstalling removes every table and option.

Best-practice rules enforced for suggestions:

* Same anchor text always points to the same destination (no anchor dilution).
* No links in headings, navigation, buttons or already-linked text.
* Descriptive, natural anchors only — no "click here", no exact-match stuffing.
* One link per destination per page, capped by your max-links setting.
* Every anchor must really exist in the page; the plugin re-validates the AI's output.

== External services ==

This plugin sends the content of the page you choose, plus titles/URLs/keywords of
your other pages, to an **n8n workflow that you control** (your own server), which in
turn calls your own **Ollama** instance. No data is sent to any third-party SaaS by
the plugin itself. You configure the n8n webhook URL in the plugin settings.

== Installation ==

1. Upload the `ai-internal-linking` folder to `/wp-content/plugins/` (or install the zip).
2. Activate the plugin. A first full sync is scheduled automatically.
3. Import `n8n/internal-linking.workflow.json` into your n8n and set up an Ollama credential (see `n8n/README.md`).
4. In **AI Linking → Settings**, paste your n8n webhook URL and Save, then click **Test connection**.
5. Go to **AI Linking → Pages** and click **Find opportunities** on any page.

== Frequently Asked Questions ==

= Where is the data stored? =
In your WordPress database, in five tidy custom tables (index, opportunities, links, audit, log). n8n stays stateless. This keeps every site self-contained and portable.

= Does it change my content automatically? =
No. Links are only inserted when you tick opportunities and click Apply. You can remove any applied link later from the Applied Links tab.

= Which AI model does it use? =
Whatever Ollama model you select in Settings. It can use self-hosted Ollama models or Ollama Cloud model tags via n8n.

= Is data deleted when I uninstall? =
No, not by default. Settings includes an explicit "Delete all plugin tables and settings on uninstall" checkbox for intentionally destructive uninstall cleanup.

== Changelog ==

= 1.5.0 =
* Expanded auditing across crawl depth, dead ends, weak inbound support, duplicate destinations, target validity, anchor quality, internal nofollow and applied-link record drift.
* Added contextual audit actions for finding links, editing pages and safely removing plugin-applied links.
* Protected plugin-approved links from subjective audit criticism while retaining objective integrity checks.

= 1.4.0 =
* Added a site-wide opportunity scan with live progress, stop control, failure tracking and a combined bulk review/apply queue.

= 1.2.3 =
* Added a Settings checkbox to opt into deleting all plugin tables/settings on uninstall. Data is preserved by default.

= 1.2.0 =
* Inbound opportunities: the AI can now scan other pages for phrases that should link TO a page, alongside the existing outbound mode (drawer tabs).
* Saved suggestions are viewable any time via the "View N" button — no AI re-run needed.
* Much stricter topical-relevance prompting; anchors are also verified to exist in the page before being stored.
* Manual Ollama model field, workflow JSON download button, Outfit font, ACF support.

= 1.0.0 =
* Initial release: content index + delta sync, AI find-opportunities, safe link insertion, AI audit, modern dashboard, maintenance tools.

