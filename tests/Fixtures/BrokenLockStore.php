<?php

namespace Darvis\Snelstart\Tests\Fixtures;

use Illuminate\Contracts\Cache\LockProvider;
use RuntimeException;

/**
 * A store that caches fine but whose locks are broken, as a Redis with a full disk can be.
 */
class BrokenLockStore extends NoLockStore implements LockProvider
{
    public function lock($name, $seconds = 0, $owner = null)
    {
        throw new RuntimeException('The lock backend is gone.');
    }

    public function restoreLock($name, $owner)
    {
        throw new RuntimeException('The lock backend is gone.');
    }
}
