<?php

namespace Tests\Support;

use Illuminate\Contracts\Cache\Store;

/** A cache store that behaves like an unreachable Redis. */
final class FailingCacheStore implements Store
{
    public function get($key)
    {
        $this->fail();
    }

    public function many(array $keys)
    {
        $this->fail();
    }

    public function put($key, $value, $seconds)
    {
        $this->fail();
    }

    public function putMany(array $values, $seconds)
    {
        $this->fail();
    }

    public function increment($key, $value = 1)
    {
        $this->fail();
    }

    public function decrement($key, $value = 1)
    {
        $this->fail();
    }

    public function forever($key, $value)
    {
        $this->fail();
    }

    public function forget($key)
    {
        $this->fail();
    }

    public function flush()
    {
        $this->fail();
    }

    public function touch($key, $seconds)
    {
        $this->fail();
    }

    public function getPrefix()
    {
        return '';
    }

    private function fail(): never
    {
        throw new \RuntimeException('cache store unavailable');
    }
}
