<?php
/**
 * Streamable HTTP and JSON-RPC protocol for the read-only MCP server.
 *
 * GET `/mcp/` returns discovery JSON. POST `/mcp/` (and POST `/mcp/http` or
 * `/mcp/http/` with a JSON-RPC body) follows the MCP Streamable HTTP path.
 * POST with a `tool` field, and `/mcp/http/<tool>`, remain a simple JSON API.
 *
 * Read-only: no session is opened, and Google Analytics is not called.
 *
 * @package Meirinho\Mcp
 */

require_once __DIR__ . '/reading.php';
require_once __DIR__ . '/rate.php';

/** MCP protocol version advertised in initialize and discovery. */
const MCP_PROTOCOL = '2025-06-18';

/**
 * JSON encoding flags used for every response body.
 *
 * @return int
 */
function mcp_json_flags(): int
{
    return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
}

/**
 * Encode a value as JSON with the server's standard flags.
 *
 * @param mixed $value Value to encode.
 * @return string JSON text, or a fallback object when encoding fails.
 */
function mcp_encode($value): string
{
    $json = json_encode($value, mcp_json_flags());
    return $json === false ? '{"found":false,"note":"JSON inválido."}' : $json;
}

/**
 * Load the published tool list from tools.json.
 *
 * @return list<array<string, mixed>>
 */
function mcp_tools(): array
{
    static $tools = null;
    if ($tools === null) {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/tools.json'), true);
        $tools = is_array($decoded) ? $decoded : [];
    }
    return $tools;
}

/**
 * Return the published tool names.
 *
 * @return list<string>
 */
function mcp_tool_names(): array
{
    $names = [];
    foreach (mcp_tools() as $tool) {
        if (!empty($tool['name'])) {
            $names[] = $tool['name'];
        }
    }
    return $names;
}

/**
 * Build CORS and cache headers for MCP responses.
 *
 * @param array<string, string> $extra Extra headers to merge.
 * @return array<string, string>
 */
function mcp_cors(array $extra = []): array
{
    return array_merge([
        'access-control-allow-origin' => '*',
        'access-control-allow-methods' => 'GET, POST, OPTIONS',
        'access-control-allow-headers' => 'content-type, accept, mcp-session-id, mcp-protocol-version',
        'access-control-expose-headers' => 'mcp-session-id, retry-after',
        'cache-control' => 'no-store',
        'x-robots-tag' => 'noindex',
    ], $extra);
}

/**
 * Absolute path of the reading index JSON file.
 *
 * @param string $mcpDir Absolute path of the `mcp/` directory.
 * @return string
 */
function mcp_index_path(string $mcpDir): string
{
    return dirname($mcpDir) . '/agent/reading-index.json';
}

/**
 * Load and decode the reading index.
 *
 * @param string $mcpDir Absolute path of the `mcp/` directory.
 * @return array<string, mixed>
 * @throws RuntimeException When the index file is missing or invalid.
 */
function mcp_load_index(string $mcpDir): array
{
    static $cached = null;
    static $mtime = null;
    $path = mcp_index_path($mcpDir);
    if (!is_file($path)) {
        throw new RuntimeException('índice de leitura ausente');
    }
    $current = filemtime($path);
    if ($cached !== null && $mtime === $current) {
        return $cached;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['items'])) {
        throw new RuntimeException('índice de leitura ausente');
    }
    $cached = $decoded;
    $mtime = $current;
    return $cached;
}

/**
 * Discovery document returned by GET `/mcp/` and GET `/mcp/http`.
 *
 * @return array<string, mixed>
 */
function mcp_discovery(): array
{
    return [
        'name' => 'meirinho.com.br, leitura para agentes',
        'transport' => 'streamable-http',
        'endpoint' => 'https://meirinho.com.br/mcp/',
        'http' => 'https://meirinho.com.br/mcp/http',
        'tools' => mcp_tool_names(),
        'limits' => [
            'perMinute' => MCP_PER_MINUTE,
            'perDay' => MCP_PER_DAY,
            'perIp' => true,
            'bodyBytes' => MCP_BODY_BYTES,
            'timeoutMs' => MCP_TIMEOUT_MS,
        ],
    ];
}

/**
 * Optional debug log of a tool call; no-op unless MCP_LOG is set.
 *
 * @param string $tool Tool name.
 * @param array<string, mixed> $result Tool result.
 * @param string|null $lang Requested language, if any.
 * @return void
 */
function mcp_log_call(string $tool, array $result, ?string $lang): void
{
    error_log(mcp_encode([
        'tool' => $tool,
        'found' => !empty($result['found']) ? 'yes' : 'no',
        'lang' => $result['lang'] ?? ($lang ?: 'pt'),
    ]));
}

/**
 * Wrap a tool result as an MCP CallTool result.
 *
 * @param array<string, mixed> $result Tool payload.
 * @return array<string, mixed>
 */
function mcp_tool_result(array $result): array
{
    return [
        'content' => [[
            'type' => 'text',
            'text' => mcp_encode($result),
        ]],
        'isError' => ($result['found'] ?? null) === false,
    ];
}

/**
 * Build a JSON-RPC success envelope.
 *
 * @param mixed $id Request id.
 * @param mixed $result Result value.
 * @return array<string, mixed>
 */
function mcp_rpc_result($id, $result): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/**
 * Build a JSON-RPC error envelope.
 *
 * @param mixed $id Request id.
 * @param int $code JSON-RPC error code.
 * @param string $message Error message.
 * @param mixed $data Optional error data.
 * @return array<string, mixed>
 */
function mcp_rpc_error($id, int $code, string $message, $data = null): array
{
    $error = ['code' => $code, 'message' => $message];
    if ($data !== null) {
        $error['data'] = $data;
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
}

/**
 * Handle a single JSON-RPC message (initialize, tools/list, tools/call, notifications).
 *
 * @param mixed $message Decoded JSON-RPC object.
 * @param array<string, mixed> $index Reading index.
 * @return array<string, mixed>|null Null for notifications that have no response.
 */
function mcp_handle_rpc($message, array $index): ?array
{
    if (!is_array($message)) {
        return mcp_rpc_error(null, -32600, 'Invalid Request');
    }
    $id = array_key_exists('id', $message) ? $message['id'] : null;
    $hasId = array_key_exists('id', $message);
    $method = $message['method'] ?? null;
    $params = is_array($message['params'] ?? null) ? $message['params'] : [];
    if (!$method) {
        return mcp_rpc_error($hasId ? $id : null, -32600, 'Invalid Request');
    }
    if ($method === 'initialize') {
        return mcp_rpc_result($hasId ? $id : null, [
            'protocolVersion' => MCP_PROTOCOL,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'meirinho.com.br',
                'title' => 'meirinho.com.br, leitura para agentes',
                'version' => '0.1.0',
            ],
            'instructions' => 'Só de leitura. Consulta o que já está publicado. Não escreve, não comenta e não publica.',
        ]);
    }
    if ($method === 'notifications/initialized' || $method === 'initialized') {
        return null;
    }
    if ($method === 'ping') {
        return mcp_rpc_result($hasId ? $id : null, new stdClass());
    }
    if ($method === 'tools/list') {
        return mcp_rpc_result($hasId ? $id : null, ['tools' => mcp_tools()]);
    }
    if ($method === 'tools/call') {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (!in_array($name, mcp_tool_names(), true)) {
            $result = [
                'found' => false,
                'lang' => $arguments['lang'] ?? ($arguments['language'] ?? 'pt'),
                'tool' => $name,
                'note' => 'Não está publicado.',
            ];
            mcp_log_call($name ?: 'unknown', $result, $result['lang']);
            return mcp_rpc_result($hasId ? $id : null, mcp_tool_result($result));
        }
        $pageLang = $arguments['lang'] ?? ($arguments['language'] ?? 'pt');
        $result = mcp_run_tool($index, $name, $arguments, ['lang' => $pageLang]);
        mcp_log_call($name, $result, $arguments['lang'] ?? ($arguments['language'] ?? null));
        return mcp_rpc_result($hasId ? $id : null, mcp_tool_result($result));
    }
    if (!$hasId) {
        return null;
    }
    return mcp_rpc_error($id, -32601, 'Method not found: ' . $method);
}

/**
 * Normalize a list-like parameter to a list of strings, if possible.
 *
 * @param mixed $value Raw parameter.
 * @return list<string>|null
 */
function mcp_list_param($value): ?array
{
    if (is_array($value)) {
        $items = [];
        foreach ($value as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $items[] = $part;
            }
        }
        return $items ?: null;
    }
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $items = [];
    foreach (explode(',', $value) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $items[] = $part;
        }
    }
    return $items ?: null;
}

/**
 * Turn a URL query string into a tool-arguments array.
 *
 * @param string $query Raw query string, without a leading "?".
 * @return array<string, mixed>
 */
function mcp_args_from_query(string $query): array
{
    $params = [];
    parse_str($query, $params);
    $get = static function (string $key) use ($params) {
        if (!isset($params[$key]) || is_array($params[$key])) {
            return '';
        }
        return trim((string) $params[$key]);
    };
    return [
        'tool' => $get('tool'),
        'query' => $get('q') !== '' ? $get('q') : $get('query'),
        'lang' => $get('lang') !== '' ? $get('lang') : $get('language'),
        'collection' => $get('collection') ?: null,
        'url' => $get('url') !== '' ? $get('url') : ($get('path') !== '' ? $get('path') : null),
        'slug' => $get('slug') ?: null,
        'model' => $get('model') ?: null,
        'versus' => $get('versus') ?: null,
        'use' => $get('use') !== '' ? $get('use') : ($get('uso') !== '' ? $get('uso') : null),
        'slugs' => mcp_list_param($params['slugs'] ?? null),
        'fields' => mcp_list_param($params['fields'] ?? null),
        'limit' => $get('limit') !== '' ? (int) $get('limit') : null,
    ];
}

/**
 * Extract a tool name from an `/mcp/http/<tool>` path.
 *
 * @param string $path Request path.
 * @return string Tool name, or an empty string.
 */
function mcp_tool_from_path(string $path): string
{
    $path = rtrim($path, '/') ?: '/';
    $marker = '/http/';
    $at = strpos($path, $marker);
    if ($at === false) {
        return '';
    }
    $rest = substr($path, $at + strlen($marker));
    $name = explode('/', $rest)[0] ?? '';
    return $name;
}

/**
 * Normalize arguments for the simple JSON HTTP API.
 *
 * @param array<string, mixed> $args Raw arguments.
 * @return array<string, mixed>
 */
function mcp_normalize_http_args(array $args): array
{
    $args['lang'] = $args['lang'] ?? '';
    if ($args['lang'] === '' && !empty($args['language'])) {
        $args['lang'] = $args['language'];
    }
    if ($args['lang'] === '') {
        $args['lang'] = 'pt';
    }
    $args['query'] = $args['query'] ?? ($args['q'] ?? '');
    if (empty($args['url']) && !empty($args['path'])) {
        $args['url'] = $args['path'];
    }
    if (empty($args['use']) && !empty($args['uso'])) {
        $args['use'] = $args['uso'];
    }
    if (empty($args['slugs']) && !empty($args['models']) && is_array($args['models'])) {
        $args['slugs'] = $args['models'];
    }
    return $args;
}

/**
 * Handle the simple JSON HTTP API (`/mcp/http` and `/mcp/http/<tool>`).
 *
 * @param string $method HTTP method.
 * @param string $path Request path.
 * @param string $query Raw query string.
 * @param array<string, mixed> $index Reading index.
 * @param string $rawBody Raw request body.
 * @return array{status: int, json: array<string, mixed>}
 */
function mcp_http_api(string $method, string $path, string $query, array $index, string $rawBody): array
{
    $args = mcp_args_from_query($query);
    $fromPath = mcp_tool_from_path($path);
    if ($fromPath !== '') {
        $args['tool'] = $args['tool'] !== '' ? $args['tool'] : $fromPath;
    }
    if ($method === 'POST') {
        $body = null;
        if ($rawBody !== '') {
            $body = json_decode($rawBody, true);
        }
        if (!is_array($body) || array_is_list($body)) {
            return ['status' => 400, 'json' => ['found' => false, 'note' => 'JSON inválido.']];
        }
        $args = array_merge($args, $body);
        $args['tool'] = $body['tool'] ?? ($fromPath !== '' ? $fromPath : ($args['tool'] ?? ''));
        $args['query'] = $body['query'] ?? ($body['q'] ?? ($args['query'] ?? ''));
    }
    $args = mcp_normalize_http_args($args);
    if (!in_array($args['tool'], mcp_tool_names(), true)) {
        return ['status' => 200, 'json' => array_merge(mcp_discovery(), ['found' => false])];
    }
    $result = mcp_run_tool($index, $args['tool'], $args, ['lang' => $args['lang']]);
    mcp_log_call($args['tool'], $result, $args['lang']);
    return ['status' => 200, 'json' => array_merge(['tool' => $args['tool']], $result)];
}

/**
 * Whether a path is the `/mcp/http` or `/mcp/http/` root.
 *
 * @param string $path Request path.
 * @return bool
 */
function mcp_is_http_root(string $path): bool
{
    $bare = rtrim($path, '/') ?: '/';
    return $bare === '/mcp/http' || $bare === '/http';
}

/**
 * Whether a body looks like a JSON-RPC object or batch.
 *
 * @param string $rawBody Raw request body.
 * @return bool
 */
function mcp_body_is_jsonrpc(string $rawBody): bool
{
    if ($rawBody === '') {
        return false;
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        return false;
    }
    $messages = array_is_list($payload) ? $payload : [$payload];
    if (!$messages) {
        return false;
    }
    foreach ($messages as $message) {
        if (!is_array($message) || array_is_list($message) || !array_key_exists('jsonrpc', $message)) {
            return false;
        }
    }
    return true;
}

/**
 * Whether this POST should be handled as MCP JSON-RPC rather than the HTTP API.
 *
 * @param string $method HTTP method.
 * @param string $path Request path.
 * @param string $rawBody Raw request body.
 * @return bool
 */
function mcp_post_is_rpc(string $method, string $path, string $rawBody): bool
{
    if (strtoupper($method) !== 'POST') {
        return false;
    }
    if (!mcp_is_http_path($path)) {
        return true;
    }
    return mcp_is_http_root($path) && mcp_body_is_jsonrpc($rawBody);
}

/**
 * Route one HTTP request to JSON-RPC, discovery, or the HTTP API.
 *
 * @param string $method HTTP method.
 * @param string $path Request path.
 * @param string $query Raw query string.
 * @param array<string, mixed> $index Reading index.
 * @param string $rawBody Raw request body.
 * @return array{status: int, json: mixed}
 */
function mcp_answer(string $method, string $path, string $query, array $index, string $rawBody): array
{
    $bare = rtrim($path, '/') ?: '/';
    if ((str_ends_with($bare, '/http') || str_contains($bare, '/http/')) && !mcp_post_is_rpc($method, $bare, $rawBody)) {
        return mcp_http_api($method, $bare, $query, $index, $rawBody);
    }
    if ($method === 'GET' || $method === 'HEAD') {
        return ['status' => 200, 'json' => mcp_discovery(), 'emptyBody' => $method === 'HEAD'];
    }
    if ($method !== 'POST') {
        return ['status' => 405, 'json' => ['found' => false, 'note' => 'Use POST.']];
    }
    $payload = $rawBody === '' ? null : json_decode($rawBody, true);
    if (!is_array($payload)) {
        return ['status' => 400, 'json' => mcp_rpc_error(null, -32700, 'Parse error')];
    }
    $messages = array_is_list($payload) ? $payload : [$payload];
    $replies = [];
    foreach ($messages as $message) {
        $reply = mcp_handle_rpc($message, $index);
        if ($reply !== null) {
            $replies[] = $reply;
        }
    }
    if (!$replies) {
        return ['status' => 202, 'json' => null];
    }
    return ['status' => 200, 'json' => array_is_list($payload) ? $replies : $replies[0]];
}

/**
 * Whether the client asked for text/event-stream (Streamable HTTP).
 *
 * @param array<string, string> $headers Request headers.
 * @return bool
 */
function mcp_wants_sse(array $headers): bool
{
    $lower = [];
    foreach ($headers as $key => $value) {
        $lower[strtolower((string) $key)] = (string) $value;
    }
    $accept = strtolower($lower['accept'] ?? '');
    if (!str_contains($accept, 'text/event-stream')) {
        return false;
    }
    $first = strtolower(trim(explode(',', $accept)[0]));
    return !str_contains($first, 'application/json');
}

/**
 * Whether a path is under `/mcp/http`.
 *
 * @param string $path Request path.
 * @return bool
 */
function mcp_is_http_path(string $path): bool
{
    $bare = rtrim($path, '/') ?: '/';
    return str_ends_with($bare, '/http') || str_contains($bare, '/http/');
}

/**
 * Dispatch a normalized request array and apply the rate limit.
 *
 * @param array<string, mixed> $request Method, path, query, headers, body, and optional remoteAddr.
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function mcp_dispatch(array $request): array
{
    ini_set('display_errors', '0');
    ini_set('max_execution_time', (string) (int) (MCP_TIMEOUT_MS / 1000));
    set_time_limit((int) (MCP_TIMEOUT_MS / 1000));

    $method = strtoupper($request['method'] ?? 'GET');
    $uri = $request['uri'] ?? '/mcp/';
    $parts = parse_url($uri);
    $path = $parts['path'] ?? '/mcp/';
    $query = $parts['query'] ?? '';
    $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
    $rawBody = (string) ($request['body'] ?? '');
    $now = (int) ($request['now'] ?? (int) round(microtime(true) * 1000));
    $mcpDir = $request['mcpDir'] ?? dirname(__DIR__);
    $rateDir = $request['rateDir'] ?? mcp_rate_directory($mcpDir);
    $remote = $request['remoteAddr'] ?? null;
    $sse = mcp_wants_sse($headers);

    if ($method === 'OPTIONS') {
        return ['status' => 204, 'headers' => mcp_cors(), 'body' => ''];
    }

    $declared = null;
    foreach ($headers as $key => $value) {
        if (strtolower((string) $key) === 'content-length') {
            $declared = (int) $value;
        }
    }
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        if ($declared !== null && $declared > MCP_BODY_BYTES) {
            return mcp_json_response(413, [
                'error' => 'payload_too_large',
                'found' => false,
                'message' => 'O corpo passa de 64 KiB.',
            ]);
        }
        if (strlen($rawBody) > MCP_BODY_BYTES) {
            return mcp_json_response(413, [
                'error' => 'payload_too_large',
                'found' => false,
                'message' => 'O corpo passa de 64 KiB.',
            ]);
        }
    }

    $decision = mcp_consume_file($rateDir, mcp_client_ip($headers, $remote), $now);
    if (empty($decision['ok'])) {
        $payload = mcp_rate_limit_body($decision);
        $rpc = mcp_post_is_rpc($method, $path, $rawBody);
        $body = $rpc
            ? mcp_rpc_error(null, -32029, $payload['message'], $payload)
            : $payload;
        $response = mcp_json_response(429, $body);
        $response['headers']['retry-after'] = (string) $decision['retryAfter'];
        return $response;
    }

    try {
        $index = mcp_load_index($mcpDir);
    } catch (RuntimeException $error) {
        return mcp_json_response(500, ['found' => false, 'note' => $error->getMessage()]);
    }

    $answered = mcp_answer($method, $path, $query, $index, $rawBody);
    if (!empty($answered['emptyBody'])) {
        $headersOut = mcp_cors(['content-type' => 'application/json; charset=utf-8']);
        return ['status' => $answered['status'], 'headers' => $headersOut, 'body' => ''];
    }
    if (($answered['json'] ?? null) === null && $answered['status'] === 202) {
        return ['status' => 202, 'headers' => mcp_cors(), 'body' => ''];
    }
    $response = mcp_json_response($answered['status'], $answered['json']);
    if ($sse && $answered['status'] === 200 && mcp_post_is_rpc($method, $path, $rawBody)) {
        $response['headers']['content-type'] = 'text/event-stream; charset=utf-8';
        $response['body'] = "event: message\ndata: " . $response['body'] . "\n\n";
    }
    return $response;
}

/**
 * Build an HTTP JSON response tuple.
 *
 * @param int $status HTTP status.
 * @param mixed $payload JSON-serializable body.
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function mcp_json_response(int $status, $payload): array
{
    return [
        'status' => $status,
        'headers' => mcp_cors(['content-type' => 'application/json; charset=utf-8']),
        'body' => mcp_encode($payload),
    ];
}

/**
 * Collect request headers from the current PHP SAPI.
 *
 * @return array<string, string>
 */
function mcp_request_headers(): array
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $key => $value) {
                $headers[strtolower((string) $key)] = (string) $value;
            }
        }
    }
    foreach ($_SERVER as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
            continue;
        }
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        if (!isset($headers[$name])) {
            $headers[$name] = (string) $value;
        }
    }
    if (!isset($headers['content-type']) && isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
    }
    if (!isset($headers['content-length']) && isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
    }
    return $headers;
}

/**
 * Serve one HTTP request and send the response.
 *
 * @return void
 */
function mcp_serve(): void
{
    $response = mcp_dispatch([
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'uri' => $_SERVER['REQUEST_URI'] ?? '/mcp/',
        'headers' => mcp_request_headers(),
        'body' => (string) file_get_contents('php://input'),
        'remoteAddr' => $_SERVER['REMOTE_ADDR'] ?? null,
        'mcpDir' => dirname(__DIR__),
    ]);
    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $response['body'];
}

/**
 * Run a CLI batch of already-normalized requests or JSON-RPC messages.
 *
 * @param array<int, mixed> $batch List of requests.
 * @return array<int, mixed>
 */
function mcp_batch(array $batch): array
{
    $mcpDir = $batch['mcpDir'] ?? dirname(__DIR__);
    $rateDir = $batch['rateDir'] ?? mcp_rate_directory($mcpDir);
    $results = [];
    foreach ($batch['steps'] ?? [] as $step) {
        $op = $step['op'] ?? 'http';
        if ($op === 'tool') {
            $index = mcp_load_index($mcpDir);
            $args = is_array($step['args'] ?? null) ? $step['args'] : [];
            $results[] = mcp_run_tool($index, (string) ($step['name'] ?? ''), $args, [
                'lang' => $args['lang'] ?? ($args['language'] ?? 'pt'),
            ]);
            continue;
        }
        if ($op === 'candidates') {
            $results[] = mcp_rate_candidates($mcpDir);
            continue;
        }
        $response = mcp_dispatch([
            'method' => $step['method'] ?? 'GET',
            'uri' => $step['uri'] ?? '/mcp/',
            'headers' => is_array($step['headers'] ?? null) ? $step['headers'] : [],
            'body' => (string) ($step['body'] ?? ''),
            'now' => $step['now'] ?? ($batch['now'] ?? null),
            'rateDir' => $rateDir,
            'mcpDir' => $mcpDir,
            'remoteAddr' => $step['remoteAddr'] ?? null,
        ]);
        $decoded = json_decode($response['body'], true);
        $results[] = [
            'status' => $response['status'],
            'headers' => $response['headers'],
            'json' => $decoded,
            'body' => $response['body'],
        ];
    }
    return ['results' => $results];
}
