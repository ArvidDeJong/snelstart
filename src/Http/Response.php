<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Http;

/**
 * What a transport hands back: the status and the raw body, nothing interpreted.
 */
final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The decoded JSON body, null for an empty body, and the raw body when it is not JSON.
     */
    public function decoded(): mixed
    {
        if (trim($this->body) === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $this->body;
    }
}
