<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Laravel;

use Darvis\Snelstart\Token\MemoryTokenStore;
use Darvis\Snelstart\Token\TokenStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the token in Laravel's cache, encrypted, so every web request and worker shares one, and
 * in memory, so one process does not decrypt it for every call.
 *
 * The cache only saves token requests. Whatever goes wrong with it (the store is down, it has no
 * locks, the application key changed) is one warning in the log and a client that works from
 * memory; it never fails a call to SnelStart.
 */
final class CacheTokenStore implements TokenStore
{
    /**
     * Seconds a process waits for another process that is fetching a token.
     */
    private const LOCK_WAIT_SECONDS = 5;

    private readonly MemoryTokenStore $memory;

    private bool $warned = false;

    /**
     * @param  string|null  $store  a store from config/cache.php, null for the default store
     */
    public function __construct(private readonly ?string $store = null)
    {
        $this->memory = new MemoryTokenStore;
    }

    public function get(string $key): ?string
    {
        $token = $this->memory->get($key);

        if ($token !== null) {
            return $token;
        }

        $cached = $this->cache(static fn (Repository $cache): mixed => $cache->get($key));

        if ($cached === null) {
            return null;
        }

        try {
            $data = is_string($cached) ? json_decode(Crypt::decryptString($cached), true) : null;
        } catch (\Throwable) {
            // Encrypted with another application key, or not encrypted at all: that is no token.
            $data = null;
        }

        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        $seconds = is_array($data) && is_int($data['expires_at'] ?? null) ? $data['expires_at'] - time() : 0;

        if (! is_string($token) || $token === '' || $seconds < 1) {
            $this->cache(static fn (Repository $cache): mixed => $cache->forget($key));

            return null;
        }

        $this->memory->put($key, $token, $seconds);

        return $token;
    }

    public function put(string $key, #[\SensitiveParameter] string $token, int $seconds): void
    {
        $this->memory->put($key, $token, $seconds);

        $this->cache(static fn (Repository $cache): mixed => $cache->put(
            $key,
            Crypt::encryptString((string) json_encode(['access_token' => $token, 'expires_at' => time() + $seconds])),
            $seconds,
        ));
    }

    public function forget(string $key): void
    {
        $this->memory->forget($key);

        $this->cache(static fn (Repository $cache): mixed => $cache->forget($key));
    }

    public function synchronized(string $key, int $seconds, callable $callback): mixed
    {
        $lock = $this->lock($key.'.lock', $seconds);

        try {
            return $callback();
        } finally {
            try {
                $lock?->release();
            } catch (\Throwable $e) {
                $this->warn($e);
            }
        }
    }

    /**
     * The lock, or null to go on without one: the store has no locks, the lock did not come free
     * in time, or the lock failed.
     */
    private function lock(string $name, int $seconds): ?Lock
    {
        try {
            $store = Cache::store($this->store)->getStore();

            if (! $store instanceof LockProvider) {
                return null;
            }

            $lock = $store->lock($name, max(1, min($seconds, 120)));
            $lock->block(self::LOCK_WAIT_SECONDS);

            return $lock;
        } catch (LockTimeoutException) {
            return null;
        } catch (\Throwable $e) {
            $this->warn($e);

            return null;
        }
    }

    /**
     * @param  callable(Repository): mixed  $callback
     */
    private function cache(callable $callback): mixed
    {
        try {
            return $callback(Cache::store($this->store));
        } catch (\Throwable $e) {
            $this->warn($e);

            return null;
        }
    }

    /**
     * One warning per instance, however often the cache or its lock fails.
     */
    private function warn(\Throwable $e): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;

        try {
            Log::warning('Snelstart token cache is not available, the token is kept in memory only: '.$e->getMessage());
        } catch (\Throwable) {
            // A log that cannot be written must not fail a call either.
        }
    }
}
