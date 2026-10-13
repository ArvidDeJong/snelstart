<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Http;

/**
 * The default transport: plain PHP cURL, no dependencies.
 */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $body, float $timeout, float $connectTimeout): Response
    {
        if (! function_exists('curl_init')) {
            throw new \RuntimeException('The PHP cURL extension is not installed.');
        }

        if ($method === '' || $url === '') {
            throw new \RuntimeException('A request needs a method and a URL.');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            // Millisecond timeouts need NOSIGNAL, or a value below one second ends at once.
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeout * 1000)),
        ]);

        // A POST or PUT without a body still says so (Content-Length: 0); some servers insist.
        if ($body !== null || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body ?? '');
        }

        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        $errno = curl_errno($handle);

        if (! is_string($result)) {
            throw new \RuntimeException('cURL error '.$errno.': '.$error);
        }

        return new Response($status, $result);
    }
}
