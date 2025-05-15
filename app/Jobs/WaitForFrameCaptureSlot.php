<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatched on a low-priority queue as part of a chain, this efficiently controls parallelization within a batch.
 */
class WaitForFrameCaptureSlot implements ShouldQueue
{
    use Queueable;

    public function __construct() {}

    public function handle(): void
    {
        // Do nothing, the job completing is the only action required.
    }
}
