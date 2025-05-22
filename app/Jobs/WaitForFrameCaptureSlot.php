<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatched on a low-priority queue as part of a chain, this efficiently controls parallelization within a batch.
 */
class WaitForFrameCaptureSlot implements ShouldQueue
{
    use Queueable;

    // Releasing the job increments the attempts, so we need to allow unlimited attempts.
    public int $tries = 0;

    // But still fail immediately if an error occurs.
    public int $maxExceptions = 1;

    public function __construct() {}

    public function handle(): void
    {
        // Use a lock to rate limit ourselves to run once per second.
        // The work later in the queue takes much longer than a second to complete,
        // so this only happens when multiple workers try and pick up one of these
        // wait jobs at the same time, or the issue happens where one tries to drain
        // a large chunk of the wait queue in one pass.
        if (!Cache::lock(__CLASS__, 1)->acquire()) {
            $this->release();
        }

        // Do nothing, the job completing is the only action required.
    }
}
