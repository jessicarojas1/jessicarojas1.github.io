<?php
/**
 * Dev router for the PHP built-in server:
 *   php -S localhost:8080 router-dev.php
 * Serves existing static files directly; everything else goes through index.php.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // let the built-in server serve the static asset
}
require __DIR__ . '/index.php';
