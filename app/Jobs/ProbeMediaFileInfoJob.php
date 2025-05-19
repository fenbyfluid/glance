<?php

namespace App\Jobs;

use App\Media\MediaInfo;
use App\Models\IndexedFile;
use App\Utilities\Deferred;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;

class ProbeMediaFileInfoJob implements ShouldQueue
{
    use Batchable, Queueable;

    // TODO: We could require a passed in IndexedFile to always have its directory relationship populated, and thus get rid of the path argument.
    public function __construct(
        #[WithoutRelations]
        public Deferred|IndexedFile $outputObject,
        public string $path,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $path = $this->path;
        if (!str_starts_with($path, '/')) {
            $path = config('media.path').'/'.$path;
        }

        $mediaInfo = MediaInfo::probeFile($path);

        if ($this->outputObject instanceof Deferred) {
            $this->outputObject->set($mediaInfo);
        } else {
            $this->outputObject->update([
                'media_info' => $mediaInfo,
            ]);
        }
    }
}
