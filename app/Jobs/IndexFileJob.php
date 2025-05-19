<?php

namespace App\Jobs;

use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Bus;

class IndexFileJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public string $path,
        #[WithoutRelations]
        public IndexedDirectory $parentDirectory,
        public bool $forceUpdate,
        #[WithoutRelations]
        public ?IndexedFile $fileEntry = null,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $filesystemPath = config('media.path').'/'.$this->path;

        $fileInfo = new \SplFileInfo($filesystemPath);
        if (!$fileInfo->isFile() || !$fileInfo->isReadable()) {
            throw new \RuntimeException($filesystemPath.' is not a readable file');
        }

        $this->fileEntry ??= IndexedFile::createWithInfo([
            'directory_id' => $this->parentDirectory->getKey(),
        ], $fileInfo);

        if (!$this->fileEntry->wasRecentlyCreated && ($this->forceUpdate || $this->fileEntry->isIndexOutdated($fileInfo))) {
            $this->fileEntry->updateWithInfo($fileInfo);
        }

        // TODO: Consider moving this into IndexedFile so it can be close to the requiresGeneration() logic.
        // TODO: See event note in \App\Models\IndexedFile::updateWithInfo

        $generationJobs = [];

        if ($this->fileEntry->kind->canProbeMediaInfo() && $this->fileEntry->media_info === null) {
            $generationJobs[] = (new ProbeMediaFileInfoJob($this->fileEntry, $this->path))->onQueue('ffprobe');
        }

        if ($this->fileEntry->kind->canPerceptualHash() && $this->fileEntry->phash === null) {
            $generationJobs[] = (new GenerateMediaFilePerceptualHashJob($this->fileEntry, $this->path))->onQueue('index');
        }

        if (empty($generationJobs)) {
            return;
        }

        // If we're running a sync batch (for the CLI command), do the generation inline too.
        $currentBatch = $this->batch();
        if (($currentBatch?->options['connection'] ?? null) === 'sync') {
            $currentBatch->add([$generationJobs]);

            return;
        }

        Bus::chain($generationJobs)->dispatch();
    }
}
