<?php

namespace App\Jobs;

use App\Enums\ExtractedTextStatus;
use App\Media\MediaContentKind;
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

        // What we know from the contents alone belongs to the fingerprint, so is already there for contents we've
        // seen before (a file that was moved, copied, or deleted and recreated by the index). The searchable text
        // for its scene belongs to the file though, so needs writing again.
        $this->fileEntry->load('fingerprint.stashScene');
        $fingerprint = $this->fileEntry->fingerprint;

        if ($fingerprint->stashScene !== null && $this->fileEntry->kind === MediaContentKind::Video && $this->fileEntry->extractedText()->doesntExist()) {
            $this->fileEntry->writeSceneText($fingerprint->stashScene);
        }

        // TODO: Consider moving this into IndexedFile so it can be close to the requiresGeneration() logic.
        // TODO: See event note in \App\Models\IndexedFile::updateWithInfo

        $generationJobs = [];

        if ($this->fileEntry->kind->canProbeMediaInfo() && $this->fileEntry->media_info === null) {
            $generationJobs[] = (new ProbeMediaFileInfoJob($this->fileEntry, $this->path))->onQueue('ffprobe');
        }

        if ($this->fileEntry->kind->canThumbnail() && $this->fileEntry->thumbnail === null) {
            $generationJobs[] = (new GenerateMediaFileThumbnailJob($this->fileEntry, $this->path, $this->parentDirectory->video_thumbnail_time ?? 15_000))->onQueue('index');
        }

        if ($this->fileEntry->kind === MediaContentKind::Video && $this->fileEntry->filmstrip === null) {
            $generationJobs[] = (new GenerateMediaFileFilmstripJob($this->fileEntry, $this->path, $this->parentDirectory->video_thumbnail_time ?? 15_000))->onQueue('index');
        }

        if ($this->fileEntry->kind->canPerceptualHash() && $fingerprint->phash === null) {
            $generationJobs[] = (new GenerateMediaFilePerceptualHashJob($this->fileEntry, $this->path))->onQueue('index');
        }

        if (ExtractTextJob::canExtractText($this->fileEntry->kind) && $this->fileEntry->extracted_text_status === ExtractedTextStatus::Pending) {
            $generationJobs[] = (new ExtractTextJob($this->fileEntry, $this->path))->onQueue('ffprobe');
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
