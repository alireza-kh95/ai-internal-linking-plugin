# n8n workflow — AI Internal Linking

This folder contains the n8n workflow that the plugin talks to. It is a **stateless
processor**: it receives a page's content plus a catalogue of your other pages,
runs them through **Ollama**, and returns JSON. All data lives in your WordPress
database — nothing is stored in n8n.

A live copy has already been deployed to the connected n8n instance:

- **Workflow:** AI Internal Linking — RankerMind
- **URL:** https://automation.techvertu.co.uk/workflow/flA8ynXen2BDhcbQ
- **Production webhook:** `https://automation.techvertu.co.uk/webhook/ai-internal-linking`

`internal-linking.workflow.json` is a portable copy you can import into **any** n8n
instance (each website you install the plugin on can use its own n8n + Ollama).

## Importing into another n8n

1. n8n → **Workflows → Import from File** → choose `internal-linking.workflow.json`.
2. Open **Ollama Generate** and select (or create) an **Ollama** credential whose
   Base URL points at your Ollama server, e.g. `http://host.docker.internal:11434`
   (use `http://localhost:11434` if n8n runs on the same host as Ollama).
3. Make sure the model you set in the plugin (default `llama3.1:8b`) is pulled:
   `ollama pull llama3.1:8b`.
4. **Activate** the workflow (top-right toggle).
5. Open the **AIL Webhook** node and copy the **Production URL**.
6. In WordPress: **AI Linking → Settings**, paste that URL into both
   *Find opportunities webhook URL* and *Audit webhook URL* (one URL serves both —
   the workflow routes by the `action` field), then **Save**.
7. Click **Test connection** in Settings — you should get *Connection successful*.
8. In the same Settings page, under **Model**, type the exact Ollama model name
   your n8n credential can run. For self-hosted Ollama, use a local tag such as
   `llama3.1:8b`. For Ollama Cloud, include the cloud tag yourself, such as
   `deepseek-v4-pro:cloud`.

## How it works

```
Webhook (POST)
  └─ Is Ping?  ── true ─→ Respond Pong            ({ ok: true })
                └ false ─→ Build Prompt            (builds system+user prompt by action)
                            └─→ Ollama Generate    (JSON mode, model from payload)
                                 └─→ Shape Response (parses/validates model output)
                                      └─→ Respond Result
```

### Request shapes the plugin sends

`find_opportunities`:

```json
{
  "action": "find_opportunities",
  "model": "llama3.1:8b",
  "source": { "post_id": 12, "title": "...", "url": "...", "content": "plain text" },
  "targets": [ { "post_id": 45, "title": "...", "url": "...", "keywords": [], "summary": "..." } ],
  "rules": { "max_links": 5, "min_score": 0.6, "existing_anchors": {}, "guidelines": [] }
}
```

Response: `{ "opportunities": [ { "anchor_text", "target_post_id", "target_url", "context_sentence", "score", "reason" } ] }`

`audit`: `{ "action": "audit", "summary": { ... } }` → `{ "narrative": "..." }`

`ping`: `{ "action": "ping" }` → `{ "ok": true, "message": "pong" }`

> The plugin **re-validates** every returned opportunity against its own catalogue
> (rejecting any invented URLs/IDs and anything whose anchor isn't really in the
> page), so a small local model can't damage your content.

## Security (optional but recommended)

Each request is signed: the plugin sends `X-AIL-Signature` = HMAC-SHA256 of the raw
body using the **Shared secret** from Settings, plus `X-AIL-Site`. If you want the
workflow to reject forged calls, add a Code node after the webhook that recomputes
the HMAC with the same secret and compares it to the header. (Left out by default so
the workflow imports and runs with zero configuration.)

## Tuning

- **Model:** change it in the plugin Settings; it's passed per-request, so you don't
  edit the workflow.
- **Speed/quality:** in *Ollama Generate → Options* adjust `temperature`, `num_ctx`
  (context window) and `num_predict`. Larger `num_ctx` lets the model see more target
  pages but uses more memory.
- **Catalogue size:** *Build Prompt* caps the catalogue at 120 pages and trims each
  summary; raise/lower these if your model and hardware allow.
