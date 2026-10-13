<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Token;

/**
 * Keeps the token on the instance: it lives as long as the PHP process, and is not shared.
 */
final class MemoryTokenStore implements TokenStore
{
    /**
     * @var array<string, array{token: string, expiresAt: int}>
     */
    private array $tokens = [];

    public function get(string $key): ?string
    {
        $entry = $this->tokens[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['expiresAt'] <= time()) {
            unset($this->tokens[$key]);

            return null;
        }

        return $entry['token'];
    }

    public function put(string $key, #[\SensitiveParameter] string $token, int $seconds): void
    {
        $this->tokens[$key] = ['token' => $token, 'expiresAt' => time() + $seconds];
    }

    public function forget(string $key): void
    {
        unset($this->tokens[$key]);
    }

    public function synchronized(string $key, int $seconds, callable $callback): mixed
    {
        return $callback();
    }
}
