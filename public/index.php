<?php
declare(strict_types=1);

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($path === '/' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/viewer.html');
    return;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($method !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error'=>'Only GET is supported']);
    return;
}
try {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $viewer = new App\Viewer\WorldViewer(App\Infrastructure\Database::connect());
    [$status,$data] = $viewer->respond($method, is_string($path) ? $path : '', $_GET);
} catch (Throwable $error) {
    error_log('World viewer connection failed: ' . get_class($error));
    $status = 503;
    $data = ['error'=>'World data is temporarily unavailable'];
}
http_response_code($status);
echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
