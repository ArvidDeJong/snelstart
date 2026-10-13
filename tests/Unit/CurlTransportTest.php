<?php

declare(strict_types=1);

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;
use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Http\CurlTransport;

/**
 * The cURL transport cannot be faked, so it talks to tests/Fixtures/server.php on 127.0.0.1.
 * PHP_SERVER_BINARY names the php that serves it, for a test runner whose own binary cannot.
 */
function testServer(): string
{
    static $server = null;

    if ($server !== null) {
        return $server['url'];
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($socket, false), strlen('127.0.0.1:'));
    fclose($socket);

    $php = getenv('PHP_SERVER_BINARY') ?: PHP_BINARY;
    $process = proc_open(
        [$php, '-S', '127.0.0.1:'.$port, dirname(__DIR__).'/Fixtures/server.php'],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );

    $url = 'http://127.0.0.1:'.$port;

    for ($attempt = 0; $attempt < 100; $attempt++) {
        // cURL, because a refused fsockopen() is a warning, and a warning fails the test.
        $probe = curl_init($url);
        curl_setopt_array($probe, [CURLOPT_CONNECT_ONLY => true, CURLOPT_RETURNTRANSFER => true]);

        if (curl_exec($probe) !== false) {
            $server = ['url' => $url, 'process' => $process];

            register_shutdown_function(static function () use ($process): void {
                proc_terminate($process);
            });

            return $url;
        }

        usleep(50000);
    }

    throw new RuntimeException('The test server did not start.');
}

test('it sends the method, the headers and the body, and returns the status and the raw body', function () {
    $response = (new CurlTransport)->send('PUT', testServer().'/v2/relaties/1?%24top=1', [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Ocp-Apim-Subscription-Key' => 'subscription-key',
    ], '{"naam":"Jansen"}', 5, 5);

    $seen = json_decode($response->body, true);

    expect($response->status)->toBe(200)
        ->and($seen['method'])->toBe('PUT')
        ->and($seen['uri'])->toBe('/v2/relaties/1?%24top=1')
        ->and($seen['body'])->toBe('{"naam":"Jansen"}')
        ->and($seen['headers']['content-type'])->toBe('application/json')
        ->and($seen['headers']['ocp-apim-subscription-key'])->toBe('subscription-key');
});

test('a request without a body sends none, and a POST or PUT says its length is zero', function (string $method, bool $length) {
    $seen = json_decode((new CurlTransport)->send($method, testServer().'/v2/relaties/1', [], null, 5, 5)->body, true);

    expect($seen['method'])->toBe($method)
        ->and($seen['body'])->toBe('')
        ->and(($seen['headers']['content-length'] ?? null) === '0')->toBe($length);
})->with([['GET', false], ['DELETE', false], ['POST', true], ['PUT', true]]);

test('a 4xx or 5xx is a response, not an exception, and a redirect is not followed', function () {
    $transport = new CurlTransport;

    expect($transport->send('GET', testServer().'/status/404', [], null, 5, 5)->status)->toBe(404)
        ->and($transport->send('GET', testServer().'/status/503', [], null, 5, 5)->status)->toBe(503)
        ->and($transport->send('GET', testServer().'/redirect', [], null, 5, 5)->status)->toBe(302);
});

test('a server that cannot be reached throws', function () {
    expect(fn () => (new CurlTransport)->send('GET', 'http://127.0.0.1:1/', [], null, 2, 2))
        ->toThrow(RuntimeException::class, 'cURL error');
});

// The server handles one request at a time: the tests that make it sleep come last.
test('the client works end to end on cURL, and its timeout is a SnelstartException with status 0', function () {
    $config = new Config('client-key', 'subscription-key', testServer().'/v2', testServer().'/echo', testServer().'/token', 0.3, 0.3);
    $client = new Client($config);

    // The fixture has no token to give: the token request is answered, but without access_token.
    expect(fn () => $client->get('relaties'))->toThrow(SnelstartException::class, 'does not contain access_token');

    // The Echo API needs no token.
    expect($client->echo()['uri'])->toBe('/echo/resource?param1=sample');

    usleep(100000);

    $slow = new Client(new Config('client-key', 'subscription-key', echoUrl: testServer().'/slow', timeout: 0.3));

    expect(fn () => $slow->echo())->toThrow(function (SnelstartException $e) {
        expect($e->status())->toBe(0)->and($e->getMessage())->toStartWith('Snelstart API call failed. No response: cURL error 28');
    });
});

test('it gives up after the timeout, also one below a second', function () {
    $start = microtime(true);

    expect(fn () => (new CurlTransport)->send('GET', testServer().'/slow', [], null, 0.3, 0.3))
        ->toThrow(RuntimeException::class, 'cURL error 28')
        ->and(microtime(true) - $start)->toBeLessThan(0.7);
});
