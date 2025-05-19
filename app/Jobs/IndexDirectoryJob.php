<?php

namespace App\Jobs;

use App\Models\IndexedDirectory;
use App\Models\IndexedFile;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;

class IndexDirectoryJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public string $path,
        public int $maxDepth,
        public bool $forceDirectoryTraversal,
        public bool $forceFileUpdate,
        public bool $generateMissing,
        #[WithoutRelations]
        public ?IndexedDirectory $rootDirectory = null,
    ) {}

    public function handle(): void
    {
        $jobBatch = $this->batch();
        if ($jobBatch?->cancelled()) {
            return;
        }

        // TODO: Decide if we need this, get it from config if we do, for now just using it to ignore some legacy stuff.
        $ignoreRegex = '#^\.|(?:^|/)preview_#';

        if (preg_match($ignoreRegex, $this->path) === 1) {
            return;
        }

        $rootFilesystemPath = config('media.path').'/'.$this->path;

        $rootInfo = new \SplFileInfo($rootFilesystemPath);
        if (!$rootInfo->isDir()) {
            throw new \RuntimeException($rootFilesystemPath.' is not a directory');
        }

        $this->rootDirectory ??= $this->findOrCreateIndexedDirectory($this->path, $rootInfo);

        if (!$this->rootDirectory->wasRecentlyCreated) {
            if ($this->rootDirectory->isIndexOutdated($rootInfo)) {
                $this->rootDirectory->updateWithInfo($rootInfo);
            } elseif (!$this->forceDirectoryTraversal) {
                return;
            }
        }

        // TODO: This could be quite a lot of entries, could we do anything better?
        $fileEntries = $this->rootDirectory->files->keyBy('name');
        $directoryEntries = $this->rootDirectory->children->keyBy('name');

        $newFileJobs = [];
        $newDirectoryJobs = [];

        $directoryIterator = new \DirectoryIterator($rootFilesystemPath);
        foreach ($directoryIterator as $childInfo) {
            $childName = $childInfo->getFilename();

            if ($childName === '.' || $childName === '..') {
                continue;
            }

            $childPath = (($this->path !== '') ? $this->path.'/' : '').$childName;

            if (preg_match($ignoreRegex, $childPath) === 1) {
                continue;
            }

            if ($childInfo->isDir()) {
                /** @var ?IndexedDirectory $childDirectory */
                $childDirectory = $directoryEntries->pull($childName);

                if ($childDirectory) {
                    if (!$this->forceDirectoryTraversal && !$childDirectory->isIndexOutdated($childInfo)) {
                        continue;
                    }
                } else {
                    $childDirectory = IndexedDirectory::create([
                        'parent_id' => $this->rootDirectory->getKey(),
                        'name' => basename($childPath),
                        'path' => $childPath,
                        'inode' => 0,
                        'mtime' => 0,
                    ]);
                }

                if ($this->maxDepth !== 0) {
                    $newDirectoryJobs[] = new self(
                        $childPath,
                        $this->maxDepth - 1,
                        $this->forceDirectoryTraversal,
                        $this->forceFileUpdate,
                        $this->generateMissing,
                        $childDirectory,
                    );
                }

                continue;
            }

            // TODO: Do something useful with symlinks.
            if (!$childInfo->isFile()) {
                continue;
            }

            /** @var IndexedFile $childFile */
            $childFile = $fileEntries->pull($childName);

            if ($childFile && (!$this->forceFileUpdate && !$childFile->isIndexOutdated($childInfo)) && (!$this->generateMissing || !$childFile->requiresGeneration())) {
                continue;
            }

            // TODO: Queuing these individually (and re-stating the files) adds a fair bit of overhead.
            //       Consider building up batches of 100(?) and passing in the $childInfo data subset.
            //       Our baseline performance here is ~6 hours
            $newFileJobs[] = new IndexFileJob(
                $childPath,
                $this->rootDirectory,
                $this->forceFileUpdate,
                $childFile,
            );
        }

        // TODO: We maybe need to handle deletions first to correctly deal with moves (as we should only
        //       consider an exact OSHash match a move if the original was deleted when we find a file).
        //       Alternatively, maybe it would be more efficient to do an extra stat to check at the time
        //       of entry? But then we'd need to check the DB in IndexFileJob for any existing OSHashes
        //       (which might even be legitimate duplicates on disk!). We probably can't just trust inodes.

        foreach ($fileEntries as $fileEntry) {
            // TODO: Switch this to a soft delete when we update everything else to work with them.
            $fileEntry->forceDelete();
        }

        foreach ($directoryEntries as $directoryEntry) {
            // TODO: Switch this to a soft delete when we update everything else to work with them.
            $directoryEntry->forceDelete();
        }

        // TODO: We'd like to split IndexFileJob and IndexDirectoryJob onto different queues so that all directory
        //       index jobs happen with a priority ahead of file indexing jobs (gives us a more complete total job
        //       estimation). Unfortunately, a batch can only contain jobs on one queue. One idea would be to create
        //       a 2nd batch to contain the file indexing jobs and hold a reference to it as part of the directory
        //       indexing jobs. Callers would need to monitor both, but that's not too bad.
        if ($jobBatch) {
            $jobBatch = $jobBatch->fresh();
            if (!$jobBatch?->cancelled()) {
                $jobBatch->add([...$newDirectoryJobs, ...$newFileJobs]);
            }
        } else {
            foreach ([...$newDirectoryJobs, ...$newFileJobs] as $job) {
                dispatch($job)->onConnection($this->connection)->onQueue($this->queue);
            }
        }
    }

    private function findOrCreateIndexedDirectory(string $path, \SplFileInfo $info): IndexedDirectory
    {
        $existingDirectory = IndexedDirectory::where('path', '=', $path)->first();
        if ($existingDirectory) {
            return $existingDirectory;
        }

        $parentPaths = [];
        $explodedPath = ($path !== '') ? explode('/', $path) : [];
        while (count($explodedPath) > 0) {
            array_pop($explodedPath);
            $parentPaths[] = implode('/', $explodedPath);
        }
        $parentPaths = array_reverse($parentPaths);

        $existingParentDirectories = IndexedDirectory::whereIn('path', $parentPaths)->get()->keyBy('path');

        $parentDirectory = null;
        foreach ($parentPaths as $parentPath) {
            $parentDirectory = $existingParentDirectories->get($parentPath) ?? IndexedDirectory::create([
                'parent_id' => $parentDirectory?->getKey(),
                'name' => basename($parentPath),
                'path' => $parentPath,
                'inode' => 0,
                'mtime' => 0,
            ]);
        }

        return IndexedDirectory::create([
            'parent_id' => $parentDirectory?->getKey(),
            'name' => basename($path),
            'path' => $path,
            'inode' => $info->getInode(),
            'mtime' => $info->getMTime(),
        ]);
    }
}
