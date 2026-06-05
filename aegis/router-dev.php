<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$direct = __DIR__ . $path;
if (is_file($direct) && preg_match('/\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|map)$/i', $path)) {
    return false;
}
require __DIR__ . '/index.php';
