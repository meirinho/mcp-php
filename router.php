<?php
/**
 * Router for PHP's built-in server only.
 *
 * When you run `php -S 127.0.0.1:8080 router.php` from the repository root,
 * this script sends `/mcp/` and `/mcp/http` to `mcp/index.php` and blocks
 * HTTP access to `mcp/lib` and the rate-limit counter directory.
 *
 * It is not used in production on meirinho.com.br. There, Apache uses
 * `mcp/.htaccess` instead.
 *
 * @package Meirinho\Mcp
 */
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);

if (preg_match('#^/mcp/(?:lib|\.rate)(?:/|$)#', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    return true;
}

if (preg_match('#^/mcp(?:/http)?(?:/.*)?$#', $uri)) {
    require __DIR__ . '/mcp/index.php';
    return true;
}

if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not Found\n";
return true;
