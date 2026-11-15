<?php
/**
 * Per-IP rate limits for `/mcp/` and `/mcp/http`.
 *
 * Defaults: 60 requests per minute and 1,000 per day, 64 KiB max body,
 * 10 second timeout. The counter directory may be set with MCP_RATE_DIR.
 * Otherwise the code tries a writable path outside the public folder, then
 * a `.rate` subdirectory, then the system temporary directory.
 *
 * @package Meirinho\Mcp
 */

/** Maximum accepted requests per IP per rolling minute. */
const MCP_PER_MINUTE = 60;
/** Maximum accepted requests per IP per rolling day. */
const MCP_PER_DAY = 1000;
/** Maximum request body size, in bytes (64 KiB). */
const MCP_BODY_BYTES = 64 * 1024;
/** Request time limit advertised by the server, in milliseconds. */
const MCP_TIMEOUT_MS = 10000;
/** Length of the per-minute window, in milliseconds. */
const MCP_MINUTE_MS = 60000;
/** Length of the per-day window, in milliseconds. */
const MCP_DAY_MS = 86400000;

/**
 * Return an empty per-IP counter state.
 *
 * @return array{minuteKey: null, minuteCount: int, dayKey: null, dayCount: int}
 */
function mcp_empty_gate(): array
{
    return ['minuteKey' => null, 'minuteCount' => 0, 'dayKey' => null, 'dayCount' => 0];
}

/**
 * Resolve the client IP from proxy headers, then REMOTE_ADDR.
 *
 * @param array<string, string> $headers Request headers, any capitalization.
 * @param string|null $remoteAddr Value of $_SERVER["REMOTE_ADDR"], if known.
 * @return string The client IP, or "unknown".
 */
function mcp_client_ip(array $headers, ?string $remoteAddr = null): string
{
    $lower = [];
    foreach ($headers as $key => $value) {
        $lower[strtolower((string) $key)] = trim((string) $value);
    }
    if (($lower['cf-connecting-ip'] ?? '') !== '') {
        return $lower['cf-connecting-ip'];
    }
    if (($lower['x-forwarded-for'] ?? '') !== '') {
        $first = trim(explode(',', $lower['x-forwarded-for'])[0]);
        if ($first !== '') {
            return $first;
        }
    }
    if (($lower['x-real-ip'] ?? '') !== '') {
        return $lower['x-real-ip'];
    }
    if ($remoteAddr !== null && $remoteAddr !== '') {
        return $remoteAddr;
    }
    return 'unknown';
}

/**
 * Consume one request from a counter state, rolling windows when needed.
 *
 * @param array<string, mixed> $state Counter state, updated in place.
 * @param int $now Unix time in milliseconds.
 * @return array{ok: bool, scope?: string, retryAfter?: int, minute?: int, day?: int}
 */
function mcp_take(array &$state, int $now): array
{
    $minuteKey = intdiv($now, MCP_MINUTE_MS);
    $dayKey = intdiv($now, MCP_DAY_MS);
    if ($state['minuteKey'] !== $minuteKey) {
        $state['minuteKey'] = $minuteKey;
        $state['minuteCount'] = 0;
    }
    if ($state['dayKey'] !== $dayKey) {
        $state['dayKey'] = $dayKey;
        $state['dayCount'] = 0;
    }
    if ($state['minuteCount'] >= MCP_PER_MINUTE) {
        return [
            'ok' => false,
            'scope' => 'minute',
            'retryAfter' => max(1, (int) ceil((($minuteKey + 1) * MCP_MINUTE_MS - $now) / 1000)),
        ];
    }
    if ($state['dayCount'] >= MCP_PER_DAY) {
        return [
            'ok' => false,
            'scope' => 'day',
            'retryAfter' => max(1, (int) ceil((($dayKey + 1) * MCP_DAY_MS - $now) / 1000)),
        ];
    }
    $state['minuteCount']++;
    $state['dayCount']++;
    return ['ok' => true, 'minute' => $state['minuteCount'], 'day' => $state['dayCount']];
}

/**
 * Build the JSON body returned when a client exceeds the rate limit.
 *
 * @param array{scope: string, retryAfter: int} $decision Negative result from mcp_take().
 * @return array<string, mixed>
 */
function mcp_rate_limit_body(array $decision): array
{
    return [
        'error' => 'rate_limit',
        'found' => false,
        'message' => 'Limite de leitura deste IP: 60 pedidos por minuto e 1.000 por dia.',
        'scope' => $decision['scope'],
        'retryAfter' => $decision['retryAfter'],
        'perMinute' => MCP_PER_MINUTE,
        'perDay' => MCP_PER_DAY,
    ];
}

/**
 * List candidate directories for rate-limit counter files.
 *
 * @param string $mcpDir Absolute path of the `mcp/` directory.
 * @return list<string>
 */
function mcp_rate_candidates(string $mcpDir): array
{
    $webRoot = dirname($mcpDir);
    return [
        dirname($webRoot) . DIRECTORY_SEPARATOR . '.meirinho-mcp-rate',
        $webRoot . DIRECTORY_SEPARATOR . 'mcp' . DIRECTORY_SEPARATOR . '.rate',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'meirinho-mcp-rate',
    ];
}

/**
 * Create a directory if needed and test that it is writable.
 *
 * @param string $dir Directory to prepare.
 * @return bool True when a probe file can be written.
 */
function mcp_rate_prepare(string $dir): bool
{
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    if (!is_writable($dir)) {
        return false;
    }
    $probe = $dir . DIRECTORY_SEPARATOR . '.write-probe';
    $ok = @file_put_contents($probe, '1') !== false;
    if ($ok) {
        @unlink($probe);
    }
    return $ok;
}

/**
 * Choose the directory used to store per-IP counter files.
 *
 * @param string $mcpDir Absolute path of the `mcp/` directory.
 * @return string Absolute path of the selected directory.
 */
function mcp_rate_directory(string $mcpDir): string
{
    $override = getenv('MCP_RATE_DIR');
    if (is_string($override) && $override !== '') {
        mcp_rate_prepare($override);
        return $override;
    }
    foreach (mcp_rate_candidates($mcpDir) as $dir) {
        if (mcp_rate_prepare($dir)) {
            return $dir;
        }
    }
    return mcp_rate_candidates($mcpDir)[2];
}

/**
 * Atomically take one slot for an IP from its counter file.
 *
 * @param string $dir Rate-limit directory.
 * @param string $ip Client IP (hashed before it is used as a file name).
 * @param int $now Unix time in milliseconds.
 * @return array{ok: bool, scope?: string, retryAfter?: int, minute?: int, day?: int, stored?: bool}
 */
function mcp_consume_file(string $dir, string $ip, int $now): array
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json';
    $handle = @fopen($file, 'c+');
    if (!$handle) {
        return ['ok' => true, 'minute' => 0, 'day' => 0, 'stored' => false];
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            return ['ok' => true, 'minute' => 0, 'day' => 0, 'stored' => false];
        }
        $raw = stream_get_contents($handle);
        $state = json_decode($raw ?: '', true);
        if (!is_array($state) || !array_key_exists('minuteCount', $state)) {
            $state = mcp_empty_gate();
        }
        $decision = mcp_take($state, $now);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state));
        fflush($handle);
        flock($handle, LOCK_UN);
        return $decision;
    } finally {
        fclose($handle);
    }
}
