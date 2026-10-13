<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Laravel;

use Darvis\Snelstart\Http\Response;
use Darvis\Snelstart\Http\Transport;
use Illuminate\Support\Facades\Http;

/**
 * Sends the requests with Laravel's HTTP client, so Http::fake() and Http::preventStrayRequests()
 * work in the tests of a host app.
 */
final class HttpTransport implements Transport
{
    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $body, float $timeout, float $connectTimeout): Response
    {
        $contentType = $headers['Content-Type'] ?? 'application/json';
        unset($headers['Content-Type']);

        $request = Http::withHeaders($headers)->withOptions([
            // A redirect would carry the keys and the body to another host.
            'allow_redirects' => false,
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
        ]);

        if ($body !== null) {
            $request = $request->withBody($body, $contentType);
        }

        $response = $request->send($method, $url);

        return new Response($response->status(), $response->body());
    }
}
