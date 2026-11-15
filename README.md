# Reading MCP for meirinho.com.br

A read-only [Model Context Protocol](https://modelcontextprotocol.io/) server, written in PHP 8.2, with the same code that runs at [https://meirinho.com.br/mcp/](https://meirinho.com.br/mcp/).

This repository is here so you can read the implementation, run the same slice on your computer, and take inspiration from it. It ships a fictional sample index for local tests. That sample is not the catalog of meirinho.com.br.

The server never writes, comments, or publishes. It does not ask for an API key or a token. The transport is [Streamable HTTP](https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#streamable-http).

Documentation is in English. Comments and PHPDoc in the PHP sources are also in English. Some strings the live server returns to clients stay in Portuguese, English, or Spanish, because that is what the public endpoint already sends. Those runtime strings were not rewritten for this repository, so the code stays aligned with production.

Further detail:

- [Architecture](docs/ARCHITECTURE.md) — request flow, files, rate limits, and the reading index
- [Tools](docs/TOOLS.md) — `search`, `list`, `get`, and `get_comparison_row`

## Public endpoint

Paste this URL into an MCP client:

```
https://meirinho.com.br/mcp/
```

| Method | Path | What you get |
| --- | --- | --- |
| `GET` | `/mcp/` or `/mcp/http` | Discovery JSON |
| `POST` | `/mcp/` | JSON-RPC (Streamable HTTP) |
| `POST` | `/mcp/http` or `/mcp/http/` | JSON-RPC when the body is JSON-RPC; otherwise the simple JSON HTTP API |
| `POST` | `/mcp/http/<tool>` | Simple JSON HTTP API for one tool |

`POST /mcp` without the trailing slash is redirected to `/mcp/` on the public site (HTTP 301). Prefer the trailing slash.

The server advertises protocol version `2025-06-18`. `serverInfo.name` is `meirinho.com.br`. The four tools are annotated as read-only, non-destructive, and idempotent.

## Limits (public endpoint)

These are per client IP:

- 60 requests per minute
- 1,000 requests per day
- Maximum request body: 64 KiB
- Time limit: 10 seconds

When a client is over the limit, the response includes `error: "rate_limit"`, `retryAfter`, `perMinute`, and `perDay`.

## Requirements

- PHP 8.2 or later
- Extensions: `json`, `mbstring` (the usual ones on a stock PHP 8.2 build)
- No Composer packages and no database

## Repository layout

```
.
├── README.md                 This file
├── LICENSE                   MIT, Copyright (c) 2026 Adriano Meirinho
├── router.php                Router for PHP's built-in server only
├── agent/
│   └── reading-index.json    Sample index for local tests (fictional)
├── docs/
│   ├── ARCHITECTURE.md
│   └── TOOLS.md
└── mcp/
    ├── index.php             Front controller
    ├── .htaccess             Apache rewrite rules used in production
    └── lib/
        ├── protocol.php      Discovery, JSON-RPC, HTTP routing
        ├── rate.php          Per-IP rate limits
        ├── reading.php       Read-only queries against the index
        └── tools.json        Published tool schemas
```

## Run it locally

From the repository root:

```bash
php -S 127.0.0.1:8080 router.php
```

`router.php` exists only for PHP's built-in server. It routes `/mcp/` and `/mcp/http` to `mcp/index.php`, and it blocks HTTP access to `mcp/lib` and to the rate-limit counters. On the public site, Apache uses `mcp/.htaccess` for that job.

The reading index must be at `agent/reading-index.json`, which is the path the PHP already uses. This repository ships a fictional sample item so `search`, `list`, and `get` can be exercised without the live catalog.

To set the directory for per-IP counters:

```bash
MCP_RATE_DIR=/tmp/meirinho-mcp-rate php -S 127.0.0.1:8080 router.php
```

In another terminal, an `initialize` call:

```bash
curl -sS http://127.0.0.1:8080/mcp/ \
  -H 'content-type: application/json' \
  -H 'accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"local","version":"0"}}}'
```

Expected `result.serverInfo` includes `"name": "meirinho.com.br"` and `"version": "0.1.0"`. Then call `notifications/initialized` (no response), `tools/list`, and `tools/call`.

### Example: list tools

```bash
curl -sS http://127.0.0.1:8080/mcp/ \
  -H 'content-type: application/json' \
  -H 'accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
```

### Example: call `get`

```bash
curl -sS http://127.0.0.1:8080/mcp/ \
  -H 'content-type: application/json' \
  -H 'accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get","arguments":{"slug":"local-example","language":"en"}}}'
```

On the live site, use a published slug (for example a blog path without a leading language prefix) plus `language`, or pass `path`. A `slug` that starts with `/en/` or `/es/` may return `found: false`; prefer the unprefixed slug and a `language` argument.

## Add it to a client

In any MCP client that supports a remote Streamable HTTP server, use:

- URL: `https://meirinho.com.br/mcp/`
- Transport: Streamable HTTP
- Authentication: none

For local work, point the same client at `http://127.0.0.1:8080/mcp/` while the built-in PHP server is running.

## Languages

Queries may ask for Portuguese (`pt`), English (`en`), or Spanish (`es`). If the requested translation is not in the index, the server returns the Portuguese text and says so. The sample index in this repository is English-only, so a `language: "pt"` call against the sample will follow that same fallback rule once you add a Portuguese item, and will otherwise tell you the item is missing.

## What this repository does not include

- The static site and the publishing process
- Credentials or hosting configuration
- The full reading index of meirinho.com.br
- Tools that are not live yet (resources, prompts, and similar)

`/mcp/` and `/mcp/http` are also kept out of the public sitemap.

## License

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Adriano Meirinho.
