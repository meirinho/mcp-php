# Tools

The server publishes four read-only tools. The canonical schemas live in [`mcp/lib/tools.json`](../mcp/lib/tools.json). Titles on the live server are in Portuguese; each `description` already includes PT, EN, and ES. This page describes the tools in English.

All tools accept `language` or `lang` as `pt`, `en`, or `es`. If that language is not in the index, the answer points at the Portuguese text. Additional properties are rejected (`additionalProperties: false`).

Annotations on every tool: `readOnlyHint: true`, `destructiveHint: false`, `idempotentHint: true`, `openWorldHint: false`.

## `search`

Search what is already published.

| Argument | Type | Required | Notes |
| --- | --- | --- | --- |
| `query` | string | yes | Free-text query |
| `collection` | string | no | `mercado`, `artigos`, or `modelos` |
| `language` or `lang` | string | no | `pt`, `en`, or `es` |

On the live catalog this searches Mercado notes, articles, or the model comparison at `/ia/modelos/`. Locally it searches the sample index.

## `list`

List published items in one collection.

| Argument | Type | Required | Notes |
| --- | --- | --- | --- |
| `collection` | string | yes | `mercado`, `artigos`, or `modelos` |
| `limit` | integer | no | Between 1 and 50 |
| `use` | string | no | Published "use" filter (models) |
| `language` or `lang` | string | no | `pt`, `en`, or `es` |

## `get`

Open one published item and return the live body, plus sources when they exist on the page.

| Argument | Type | Required | Notes |
| --- | --- | --- | --- |
| `url` | string | no | Full URL of the published page |
| `slug` | string | no | Slug without a leading `/en/` or `/es/` prefix |
| `collection` | string | no | `mercado`, `artigos`, or `modelos` |
| `language` or `lang` | string | no | `pt`, `en`, or `es` |

At least one locator (`url`, `slug`, or a path the HTTP API accepts) should be provided. A slug that starts with `/en/` or `/es/` may return `found: false` on the live server. Prefer the unprefixed slug plus `language`, or pass `path`.

## `get_comparison_row`

Return a published row from the model comparison, or a published model card. It does not build a new ranking.

| Argument | Type | Required | Notes |
| --- | --- | --- | --- |
| `model` | string | no | Model id or slug |
| `versus` | string | no | Second model, for a published pair |
| `use` | string | no | Published "use" filter |
| `slugs` | string[] | no | Model slugs |
| `query` | string | no | Free-text comparison query |
| `fields` | string[] | no | Published field names to include |
| `language` or `lang` | string | no | `pt`, `en`, or `es` |

## JSON-RPC shape

`tools/call` follows MCP: `params.name` is the tool name, `params.arguments` is the object above. A successful result is an MCP tool result whose text is JSON for the tool payload (`found`, `items`, body, sources, or a notice when a translation is missing).
