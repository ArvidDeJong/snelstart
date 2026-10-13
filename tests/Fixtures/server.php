<?php

/**
 * A stand-in for SnelStart, for the test of the cURL transport: `php -S 127.0.0.1:<port> server.php`.
 * It answers with what it received, so the test can see what cURL really sent.
 */
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/slow')) {
    usleep(800000);
}

if (preg_match('#^/status/(\d{3})$#', $path, $match)) {
    http_response_code((int) $match[1]);
}

if ($path === '/redirect') {
    header('Location: /elsewhere', true, 302);
}

header('Content-Type: application/json');

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => file_get_contents('php://input'),
]);
