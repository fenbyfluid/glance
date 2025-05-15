<?php

namespace App\Jobs;

use App\Media\MediaContentKind;
use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;

class IndexFileJob implements ShouldBeUnique, ShouldQueue
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

    public function uniqueId(): string
    {
        return hash('xxh128', $this->path);
    }

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
            'name' => basename($this->path),
        ], $fileInfo);

        // TODO: See event note in \App\Models\IndexedFile::updateWithInfo
        $requiresGeneration = $this->fileEntry->wasRecentlyCreated;
        if (!$requiresGeneration) {
            if ($this->forceUpdate || $this->fileEntry->isIndexOutdated($fileInfo)) {
                $this->fileEntry->updateWithInfo($fileInfo);
            }

            $requiresGeneration = $this->fileEntry->requiresGeneration();
        }

        if (!$requiresGeneration) {
            return;
        }

        // TODO: It probably makes sense to store this in the DB for later filtering.
        $mediaContentKind = MediaContentKind::guessForFile($this->fileEntry->name, $this->fileEntry->mime_type);

        // TODO: Consider moving this into IndexedFile so it can be close to the requiresGeneration() logic.
        match ($mediaContentKind) {
            MediaContentKind::Image => GenerateFilePerceptualHashJob::jobChainForImageFile($this->fileEntry, $this->path)->dispatch(),
            MediaContentKind::Video => GenerateFilePerceptualHashJob::jobChainForVideoFile($this->fileEntry, $this->path)->dispatch(),
            default => null,
        };
    }
}
