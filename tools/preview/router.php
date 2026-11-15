<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server used by the cloud preview.
 *
 * Multisite makes subsite admin requests look like
 * /ladies-corner/wp-admin/admin.php — there is no file at that path, so the
 * router maps the path minus its first segment (the subsite slug) onto the
 * WordPress root and executes the real script. Everything else that is not a
 * real file goes to WordPress' index.php.
 */

$root = __DIR__ . '/wordpress';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$resolve = static function (string $candidate): ?string {
    if ($candidate === '/' || !file_exists($candidate) || is_dir($candidate)) {
        return null;
    }

    return $candidate;
};

$target = $resolve($root . $path);

// Subsite paths: strip the leading /<subsite> segment and try again.
if ($target === null && preg_match('#^/([^/]+)/(.+)$#', $path, $matches) === 1) {
    $candidate = $resolve($root . '/' . $matches[2]);

    if ($candidate !== null) {
        $target = $candidate;
        $scriptName = '/' . $matches[2];
    }
}

if ($target !== null) {
    if (str_ends_with($target, '.php')) {
        $_SERVER['SCRIPT_FILENAME'] = $target;
        $_SERVER['SCRIPT_NAME'] = $scriptName ?? $path;
        $_SERVER['PHP_SELF'] = $scriptName ?? $path;
        chdir(dirname($target));
        require $target;

        return true;
    }

    // A subsite path resolved to a real asset: the built-in server would look
    // up the original (nonexistent) path, so stream the file here instead.
    if (isset($scriptName)) {
        $types = [
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'map' => 'application/json',
        ];

        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) filesize($target));
        readfile($target);

        return true;
    }

    return false;
}

require $root . '/index.php';
