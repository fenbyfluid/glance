<?php

namespace App\Utilities;

use Illuminate\Support\Facades\Cache;

/**
 * A small serializable class to handle passing binary data between asynchronous jobs.
 */
readonly class Deferred
{
    // Properties are public to reduce serialization overhead.
    public string $key;

    /**
     * @param  int  $ttl  How long the data will be stored if block isn't called for some reason.
     */
    public function __construct(
        public int $ttl = 300,
    ) {
        $this->key = bin2hex(random_bytes(16));
    }

    // TODO: A better design would split this out again so that only the code constructing the Deferred
    //       can set the result, we only want the ability to block() to be actually public.
    //       Because both halves would need to be serializable, they would need to be separate classes.
    public function set(mixed $data): void
    {
        if ($data === null) {
            throw new \LogicException('Cannot set deferred data to null');
        }

        // Log::debug('deferred '.$this->cacheKey().' set');

        Cache::put($this->cacheKey(), $data, $this->ttl);
    }

    /**
     * @param  int  $timeout  Maximum time to wait, 0 to fail immediately if not ready, <0 to wait forever
     */
    public function block(int $timeout = 0): mixed
    {
        $cacheKey = $this->cacheKey();
        // Log::debug('deferred '.$this->cacheKey().' block');

        $data = Cache::get($cacheKey);

        if ($data === null && $timeout !== 0) {
            $startTime = microtime(true);

            do {
                usleep(250_000);

                $data = Cache::get($cacheKey);
            } while ($data === null && ($timeout < 0 || (microtime(true) - $startTime) < $timeout));
        }

        if ($data === null) {
            throw new \RuntimeException('Timed out waiting for deferred');
        }

        if ($data instanceof \Throwable) {
            throw $data;
        }

        // Replace the (potentially large) data with a small error.
        // The pull method just does a get+forget so this is no worse, and avoids a 2nd read attempt timing out.
        $this->set(new \LogicException('Deferred data already read'));

        return $data;
    }

    private function cacheKey(): string
    {
        return __CLASS__.':'.$this->key;
    }
}
