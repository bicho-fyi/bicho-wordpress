<?php
declare(strict_types=1);

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ['http://localhost:8896', 'http://localhost:8897', 'http://localhost:8898'], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Content-Type, X-Bicho-Key');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    return;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/v1/logs') {
    http_response_code(404);
    echo '{}';
    return;
}
$key = $_SERVER['HTTP_X_BICHO_KEY'] ?? '';
$secret = $_SERVER['HTTP_X_BICHO_SECRET'] ?? '';
if (!in_array($key, ['wordpress-php-fixture', 'wordpress-js-fixture'], true) || ($key === 'wordpress-php-fixture' && $secret !== 'wordpress-secret-fixture')) {
    http_response_code(403);
    echo '{}';
    return;
}
$body = file_get_contents('php://input');
$payload = json_decode($body, true);
if (!is_array($payload) || !isset($payload['resourceLogs'])) {
    http_response_code(400);
    echo '{}';
    return;
}
file_put_contents('/var/bicho-output/events.jsonl', json_encode(['key' => $key, 'secret' => $secret, 'payload' => $payload], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
echo '{}';
