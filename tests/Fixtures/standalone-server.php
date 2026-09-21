<?php

declare(strict_types=1);

/**
 * A tiny HTTP server on 127.0.0.1 for the tests of the standalone client, which uses cURL directly
 * and cannot be faked with Http::fake(). The path of a request picks the answer, so the server has
 * no state. Every request is appended to the log file as one line of JSON.
 *
 * Usage: php standalone-server.php <port file> <log file>
 */
[$portFile, $logFile] = [$argv[1], $argv[2]];

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

if ($server === false) {
    fwrite(STDERR, $errorMessage);
    exit(1);
}

file_put_contents($portFile, (string) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT));

/**
 * @param  array<string, string>  $headers
 * @return array{0: int, 1: string}
 */
function answer(string $path, array $headers, string $body): array
{
    $token = fn (array $extra = []): string => (string) json_encode(['access_token' => 'standalone-access-token', 'token_type' => 'bearer'] + $extra);

    return match (true) {
        $path === '/token/ok' => [200, $token(['expires_in' => 3600])],
        $path === '/token/short' => [200, $token(['expires_in' => 30])],
        $path === '/token/no-expiry' => [200, $token()],
        $path === '/token/401' => [401, '{"error":"invalid_grant"}'],
        $path === '/token/echo' => [400, 'Bad request: '.$body],
        $path === '/token/malformed' => [200, '{"access_token": '],
        $path === '/token/empty' => [200, '{"token_type":"bearer"}'],
        str_starts_with($path, '/status/') => [(int) explode('/', $path)[2], '{"message":"Something went wrong"}'],
        str_starts_with($path, '/echo-headers/') => [500, (string) json_encode([
            'authorization' => $headers['authorization'] ?? null,
            'subscription' => $headers['ocp-apim-subscription-key'] ?? null,
        ])],
        str_starts_with($path, '/no-content/') => [204, ''],
        str_starts_with($path, '/scalar/') => [200, '"ok"'],
        str_starts_with($path, '/malformed/') => [200, '{"naam": '],
        str_starts_with($path, '/plain/') => [502, 'Bad gateway'],
        default => [200, '{"id":"abc"}'],
    };
}

// An orphaned server stops by itself after two idle minutes.
while ($client = @stream_socket_accept($server, 120)) {
    $requestLine = (string) fgets($client);
    $headers = [];

    while (($line = fgets($client)) !== false && rtrim($line) !== '') {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';

    while (strlen($body) < $length && ($chunk = fread($client, $length - strlen($body))) !== false && $chunk !== '') {
        $body .= $chunk;
    }

    [$method, $target] = explode(' ', trim($requestLine)) + ['', ''];

    file_put_contents($logFile, json_encode([
        'method' => $method,
        'target' => $target,
        'headers' => $headers,
        'body' => $body,
    ])."\n", FILE_APPEND);

    [$status, $payload] = answer((string) parse_url($target, PHP_URL_PATH), $headers, $body);

    if ($method === 'HEAD') {
        // What a real server behind a gateway does: announce the length of the body a GET would
        // get, send no body, and keep the connection open. A client that waits for the body hangs.
        fwrite($client, "HTTP/1.1 {$status} Status\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\nConnection: keep-alive\r\n\r\n");
        stream_set_timeout($client, 3);
        fread($client, 1);
    } else {
        fwrite($client, "HTTP/1.1 {$status} Status\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\nConnection: close\r\n\r\n".$payload);
    }

    fclose($client);
}
