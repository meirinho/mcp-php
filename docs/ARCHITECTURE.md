# Architecture

This document describes how the PHP server handles a request, where state lives, and how the files in this repository fit together. Behavior described here matches the public endpoint at `https://meirinho.com.br/mcp/`.

## Request flow

1. **Entry**
   - Production (Apache): `mcp/.htaccess` sends `/mcp/http` and `/mcp/http/*` to `mcp/index.php`. `/mcp/` is served by the same front controller as the directory index. Paths under `mcp/lib` or `mcp/.rate` are forbidden.
   - Local built-in server: `router.php` applies the same routing and the same 403 rules, then requires `mcp/index.php`.
2. **Front controller** (`mcp/index.php`)
   - In PHP-FPM or Apache, it calls `mcp_serve()`.
   - In CLI, if `MCP_BATCH=1`, it reads JSON from STDIN and writes a batch result to STDOUT.
3. **Dispatch** (`mcp_dispatch()` in `protocol.php`)
   - Reads method, path, query, headers, and body (capped at 64 KiB).
   - Resolves the client IP (`cf-connecting-ip`, then `x-forwarded-for`, then `x-real-ip`, then `REMOTE_ADDR`).
   - Consumes one slot from the per-IP rate-limit file. If the limit is exceeded, it returns the rate-limit JSON body and a `Retry-After` header.
   - Loads `agent/reading-index.json`.
   - Routes the request with `mcp_answer()`.
4. **Routing** (`mcp_answer()`)
   - `POST` to `/mcp/`, or `POST` to `/mcp/http` or `/mcp/http/` with a JSON-RPC body, is handled as MCP JSON-RPC (`initialize`, `tools/list`, `tools/call`, and notifications).
   - `GET` to `/mcp/` or `/mcp/http` returns the discovery document.
   - Other `/mcp/http` requests use the simple JSON HTTP API (a `tool` field in the body, or `/mcp/http/<tool>`).
5. **Tools** (`mcp_run_tool()` in `reading.php`)
   - `search`, `list`, `get`, and `get_comparison_row` only read the index. They do not invent figures or unpublished text.

If the client sends `Accept: text/event-stream`, JSON-RPC responses may be written as a Streamable HTTP event stream. Clients that accept JSON still receive a normal JSON body.

## Files

| File | Role |
| --- | --- |
| `router.php` | Local router for `php -S`. Not used on the public Apache host. |
| `mcp/index.php` | Front controller. Thin on purpose: it loads `protocol.php` and either serves HTTP or runs a CLI batch. |
| `mcp/.htaccess` | Production rewrite and `noindex` / `no-store` headers. |
| `mcp/lib/protocol.php` | CORS, discovery, JSON-RPC, HTTP API, request dispatch. |
| `mcp/lib/rate.php` | Constants and per-IP counters. |
| `mcp/lib/reading.php` | Index queries, language fallback, comparison rows. |
| `mcp/lib/tools.json` | Tool names, titles, descriptions, and JSON Schemas advertised to clients. |
| `agent/reading-index.json` | The catalog the server reads. In this repository it is a fictional sample. |

PHP sources use file-level docblocks, constant docblocks, and function PHPDoc (`@param`, `@return`, `@throws` where it applies). Executable statements were not changed when those comments were added.

## Rate limits

Defaults, also advertised in discovery:

- `MCP_PER_MINUTE = 60`
- `MCP_PER_DAY = 1000`
- `MCP_BODY_BYTES = 65536`
- `MCP_TIMEOUT_MS = 10000`

Counters are stored as one JSON file per IP, named with the SHA-256 hash of the IP, under a directory chosen in this order:

1. The `MCP_RATE_DIR` environment variable, if it is set
2. A writable directory outside the public web root (`.meirinho-mcp-rate` next to the site root)
3. `mcp/.rate`
4. The system temporary directory (`/tmp/meirinho-mcp-rate` or the equivalent)

`mcp_consume_file()` opens the file with an exclusive lock, updates the rolling minute and day windows, and writes the new state back. If the file cannot be opened or locked, the request is allowed and marked as `stored: false`, so a full disk does not take the server offline. That is a deliberate fail-open for availability; the public host is expected to have a writable counter directory.

## Reading index

The index is a JSON object with an `items` array. Each item is a published page or fragment: collection, language, slug, title, body, sources, and search text. `reading.php` looks up items by slug, path, URL, or collection, and applies language fallback (requested language, otherwise Portuguese, with a notice).

This repository does not ship the live catalog. Replace or extend `agent/reading-index.json` locally if you want more fixtures. Do not commit credentials or a copy of the production index.

## PHP conventions

- PHP 8.2, procedural style, `mcp_` function prefix
- Explicit parameter and return types on every function
- No `declare(strict_types=1)` in this recorte (production does not use it)
- No Composer autoload; `index.php` requires `protocol.php`, which requires `reading.php` and `rate.php`
- Comments explain intent, limits, and fallbacks; they do not restate the obvious next line

## What the server will not do

It will not write to the site, post comments, send messages, or accept a client API key. It will not call Google Analytics. It will not build a new model ranking: `get_comparison_row` only returns rows or cards that are already in the index.
