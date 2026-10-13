<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Http;

/**
 * Sends one HTTP request. The client builds the URL, the headers and the body; a transport only
 * carries them, so every transport behaves the same.
 *
 * An implementation returns a Response for every answer of the server, a 4xx or 5xx included, and
 * throws (any exception) when there was no answer. It never retries and never follows a redirect:
 * the headers hold the keys. For the same reason it never puts a header or the body in the message
 * of what it throws, and marks both parameters as sensitive, so they stay out of stack traces.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @param  float  $timeout  seconds for the whole request
     * @param  float  $connectTimeout  seconds to wait for the connection
     */
    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $body, float $timeout, float $connectTimeout): Response;
}
