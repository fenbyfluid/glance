<?php

namespace App\Media;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

readonly class TranscodeManager
{
    private const int HLS_SEGMENT_LENGTH = 2;

    private const int CACHE_TTL = 60 * 60 * 24;

    private string $sessionDir;

    public function __construct(
        private string $sessionId,
        private string $filesystemPath,
    ) {
        $this->sessionDir = storage_path('transcode').'/'.$this->sessionId;
    }

    public function getPlaylistResponse(string $baseUrl): Response
    {
        $mediaInfo = new MediaFile($this->filesystemPath)->probe();

        $playlist = implode(PHP_EOL, [
            '#EXTM3U',
            '#EXT-X-VERSION:3',
            '#EXT-X-MEDIA-SEQUENCE:0',
            '#EXT-X-TARGETDURATION:'.self::HLS_SEGMENT_LENGTH,
            '#EXT-X-PLAYLIST-TYPE:VOD',
        ]);

        $remainingDuration = $mediaInfo->duration;
        for ($i = 0; $remainingDuration > 0; $i++) {
            $playlist .= sprintf(PHP_EOL.'#EXTINF:%.7f,'.PHP_EOL.'%s/%d.ts',
                min(self::HLS_SEGMENT_LENGTH, $remainingDuration),
                $baseUrl,
                $i);

            $remainingDuration -= self::HLS_SEGMENT_LENGTH;
        }

        $playlist .= PHP_EOL.'#EXT-X-ENDLIST'.PHP_EOL;

        Cache::set($this->getCacheKey('segment-count'), $i, self::CACHE_TTL);

        return response($playlist)
            ->header('Content-Type', 'application/vnd.apple.mpegurl');
    }

    /**
     * Return a HLS segment in response to a web request.
     */
    public function getSegmentResponse(int $segment): BinaryFileResponse
    {
        $segmentCount = Cache::get($this->getCacheKey('segment-count'));
        if ($segmentCount === null) {
            Log::debug(sprintf('Segment count missing for request to segment %d of session %s', $segment, $this->sessionId));

            // Re-generate the playlist to get the side effect of setting the expected segment count.
            if (!$this->getPlaylistResponse('')->isSuccessful()) {
                abort(500);
            }

            $segmentCount = Cache::get($this->getCacheKey('segment-count'));
            if ($segmentCount === null) {
                abort(500);
            }
        }

        if ($segment < 0 || $segment >= $segmentCount) {
            abort(404);
        }

        Cache::set($this->getCacheKey('last-segment'), [$segment, now()->getTimestamp()], self::CACHE_TTL);

        if (!$this->ensureTranscodeMonitorRunning()) {
            abort(500);
        }

        $segmentPath = $this->sessionDir.'/'.$segment.'.ts';

        $waitTime = 0;
        $segmentExists = false;
        while ($waitTime < 30) {
            clearstatcache(false, $segmentPath);
            $segmentExists = file_exists($segmentPath);
            if ($segmentExists) {
                break;
            }

            sleep(1);
            $waitTime += 1;
        }

        if (!$segmentExists) {
            abort(503);
        }

        return response()
            ->file($segmentPath, ['Content-Type' => 'video/MP2T'])
            ->setPrivate();
    }

    public function monitorTranscode(string $startupLockOwner, string $runningLockOwner): void
    {
        $startupLock = Cache::restoreLock($this->getCacheKey('monitor-startup-lock'), $startupLockOwner);
        $runningLock = Cache::restoreLock($this->getCacheKey('monitor-lock'), $runningLockOwner);

        try {
            File::makeDirectory($this->sessionDir, 0755, true);

            $activeTranscode = null;

            while ($this->monitorTranscodeLoop($activeTranscode)) {
                // Release the startup lock after the first successful invocation of the transcoder.
                if ($startupLock) {
                    $startupLock->release();
                    $startupLock = null;
                }

                sleep(1);
            }

            if ($activeTranscode !== null) {
                $activeTranscode->stop();
            }
        } finally {
            File::deleteDirectory($this->sessionDir);

            $runningLock->release();
        }
    }

    private function getCacheKey(string $kind): string
    {
        return __CLASS__.':'.$this->sessionId.':'.$kind;
    }

    private function ensureTranscodeMonitorRunning(): bool
    {
        $runningLockName = $this->getCacheKey('monitor-lock');
        $runningLock = Cache::lock($runningLockName);
        if (!$runningLock->get()) {
            return true;
        }

        $startupLockName = $this->getCacheKey('monitor-startup-lock');
        $preStartupLock = Cache::lock($startupLockName);
        if (!$preStartupLock->get()) {
            throw new \LogicException('Failed to acquire startup lock');
        }

        $runningLockOwner = $runningLock->owner();
        $preStartupLockOwner = $preStartupLock->owner();
        $transcodeSessionId = $this->sessionId;
        $filesystemPath = $this->filesystemPath;
        dispatch(static function () use ($runningLockOwner, $preStartupLockOwner, $transcodeSessionId, $filesystemPath) {
            $process = Process::path(base_path())->start([
                'nohup',
                'php',
                'artisan',
                'app:transcode',
                '--',
                $transcodeSessionId,
                $filesystemPath,
                $preStartupLockOwner,
                $runningLockOwner,
            ]);

            // This one has a very short timeout as we're just trying to catch very fatal process start issues.
            // Uncomment this block to diagnose early startup failures, disabled by default to avoid blocking the queue.
            // try {
            //     $midStartupLock = Cache::lock($startupLockName);
            //     $midStartupLock->block(2);
            //     $midStartupLock->release();
            // } catch (LockTimeoutException) {
            //     // Kill the process in case it is stalled for some reason.
            //     $exitCode = $process->stop();
            //
            //     Log::warning(sprintf('Failed to start transcode monitor with code %d: %s', $exitCode, $process->errorOutput()));
            // }
        });

        // If this times out then startup failed - let the exception bubble up to the caller and force release the lock for the next attempt.
        // TODO: We might not actually want to the release the lock to avoid a DoS, instead specify a max lock time during initial creation.
        $postStartupLock = Cache::lock($startupLockName);
        try {
            $postStartupLock->block(30);
        } catch (LockTimeoutException) {
            $runningLock->release();

            Log::warning('Transcode monitor startup timed out');

            return false;
        } finally {
            $postStartupLock->forceRelease();
        }

        return true;
    }

    private function monitorTranscodeLoop(?MediaTranscode &$activeTranscode): bool
    {
        [$requestedSegment, $requestTime] = Cache::get($this->getCacheKey('last-segment'), [0, null]);

        Log::debug(sprintf('%s: %d, %s', __METHOD__, $requestedSegment, $requestTime));

        if ($activeTranscode !== null) {
            try {
                $activeTranscode->check();
            } catch (\RuntimeException $e) {
                Log::warning($e->getMessage());

                $activeTranscode = null;
            }
        }

        if ($activeTranscode !== null) {
            [$oldestSegment, $latestSegment] = $activeTranscode->getSegmentRange();

            $seekBackwards = $requestedSegment < $oldestSegment;
            $seekForwards = ($requestedSegment - $latestSegment) > 3;
            if ($seekBackwards || $seekForwards) {
                Log::debug('Ending transcode session due to seek');

                // TODO: Delete these segments after some time, somehow.
                //       Otherwise we leak 5-10 segments per seek (~10MB)
                //       Critically, we need to make sure we don't delete segments from a newer transcode.
                $activeTranscode->stop();
                $activeTranscode = null;
            } else {
                $activeTranscode->removeSegmentsBefore($requestedSegment - 6);
            }
        }

        if ($activeTranscode === null) {
            Log::debug('Starting a new transcoder from segment '.$requestedSegment);

            $activeTranscode = new MediaTranscode($this->sessionDir, $this->filesystemPath, self::HLS_SEGMENT_LENGTH, $requestedSegment);
        }

        if ($requestTime !== null) {
            $requestAge = now()->getTimestamp() - $requestTime;

            if ($requestAge > (self::HLS_SEGMENT_LENGTH * 6)) {
                Log::debug('Transcode session aged out');

                return false;
            }
        }

        return true;
    }
}
