<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Token;

/**
 * Where the client keeps its access token between calls. The key holds a hash, never the client
 * key itself. A store never throws: when it cannot keep a token the client simply fetches one more.
 */
interface TokenStore
{
    /**
     * The token for this key, or null when there is none or it has expired.
     */
    public function get(string $key): ?string;

    /**
     * Keep the token for this many seconds.
     */
    public function put(string $key, #[\SensitiveParameter] string $token, int $seconds): void;

    public function forget(string $key): void;

    /**
     * Run the callback while no other process runs it for the same key, when the store can
     * arrange that, and without a lock when it cannot. The lock lasts at most $seconds.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function synchronized(string $key, int $seconds, callable $callback): mixed;
}
