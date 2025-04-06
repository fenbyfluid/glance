<?php

namespace App\Console\Commands;

use App\Media\TranscodeManager;
use Illuminate\Console\Command;

class TranscodeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:transcode {sessionId} {filesystemPath} {startupLockOwner} {runningLockOwner}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start and monitor a transcode session';

    public function handle(): void
    {
        $sessionId = $this->argument('sessionId');
        $filesystemPath = $this->argument('filesystemPath');
        $transcodeManager = new TranscodeManager($sessionId, $filesystemPath);

        $startupLockOwner = $this->argument('startupLockOwner');
        $runningLockOwner = $this->argument('runningLockOwner');
        $transcodeManager->monitorTranscode($startupLockOwner, $runningLockOwner);
    }
}
