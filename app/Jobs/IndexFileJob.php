<?php

namespace App\Jobs;

use App\Media\MediaContentKind;
use App\Media\VideoPerceptualHasher;
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
        $needsGeneration = $this->fileEntry->wasRecentlyCreated;
        if (!$needsGeneration) {
            $this->fileEntry->updateWithInfo($fileInfo);
            $needsGeneration = $this->fileEntry->wasChanged('phash');
        }

        if (!$needsGeneration) {
            return;
        }

        // TODO: Dispatch PHash generation for videos.
        dump($this->path.' needs generation');
    }
}
