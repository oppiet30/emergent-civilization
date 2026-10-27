<?php
declare(strict_types=1);
// Never serve arbitrary paths from the project or expose configuration files.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($path,['/assets/app.css','/assets/app.js'],true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('X-Content-Type-Options: nosniff');
    return false;
}
require __DIR__ . '/index.php';
