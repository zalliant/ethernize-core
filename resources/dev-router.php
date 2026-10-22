<?php

declare(strict_types=1);

/**
 * Router script of the development server (`php ethernize run`). PHP's built-in server calls it for
 * every request, with the application's public folder as the document root.
 *
 *  - A real file inside the public folder (CSS, JavaScript, images, fonts) is served as it is.
 *  - Everything else goes to public/index.php, the front controller, as with Apache's rewrite rules.
 *
 * What is never served as a file: PHP and template sources, anything with a hidden (dot) name in its
 * path, and anything that does not resolve to a file below the public folder (".." segments, symbolic
 * links that leave it). Those requests reach the application, which answers as it would in production.
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);

    return true;
}

$root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

if ($root === false) {
    http_response_code(500);

    return true;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

$servable = static function () use ($root, $path): bool {
    // ":" also closes a door on Windows: "file.css::$DATA" names an alternate data stream of the file.
    if ($path === '/' || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':')) {
        return false;
    }

    foreach (explode('/', $path) as $segment) {
        if ($segment === '..' || str_starts_with($segment, '.') && $segment !== '') {
            return false;
        }
    }

    $file = realpath($root . $path);

    if ($file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
        return false;
    }

    $relative = substr($file, strlen($root) + 1);

    foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
        if (str_starts_with($segment, '.')) {
            return false;
        }
    }

    return preg_match('/\.(php\d?|phtml|phar|inc|weave|env|ini|log|sql|sqlite3?|db)$/i', $file) !== 1;
};

if ($servable()) {
    return false;
}

$front = $root . DIRECTORY_SEPARATOR . 'index.php';

if (!is_file($front)) {
    http_response_code(404);

    return true;
}

$_SERVER['SCRIPT_FILENAME'] = $front;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require $front;

return true;
