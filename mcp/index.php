<?php
/**
 * Front controller for the read-only MCP server.
 *
 * `/mcp/` is the Streamable HTTP JSON-RPC endpoint.
 * `/mcp/http` also serves discovery on GET, and the same JSON-RPC path on POST.
 *
 * A CLI batch mode is available when `MCP_BATCH=1`: JSON is read from STDIN
 * and a JSON-RPC batch (or list of HTTP-shaped requests) is written to STDOUT.
 *
 * @package Meirinho\Mcp
 */
require_once __DIR__ . '/lib/protocol.php';

if (PHP_SAPI === 'cli' && getenv('MCP_BATCH') === '1') {
    $raw = stream_get_contents(STDIN);
    $batch = json_decode($raw ?: '', true);
    if (!is_array($batch)) {
        fwrite(STDERR, "lote inválido\n");
        exit(1);
    }
    echo mcp_encode(mcp_batch($batch));
    exit(0);
}

if (PHP_SAPI !== 'cli') {
    mcp_serve();
}
