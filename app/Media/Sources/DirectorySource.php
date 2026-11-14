<?php

namespace App\Media\Sources;

use App\Media\MediaContentItem;
use App\Models\IndexedDirectory;

readonly class DirectorySource
{
    /**
     * @param  array<string|int, bool>  $accessControlInfo
     */
    public function __construct(private IndexedDirectory $directoryEntry, private array $accessControlInfo) {}

    public function getContents(): array
    {
        $childDirectoryEntries = $this->directoryEntry->children()->havingAccess($this->accessControlInfo)->get();
        $childFileEntries = $this->directoryEntry->files()->havingAccess($this->accessControlInfo)->get();

        $contents = [];

        foreach ($childDirectoryEntries as $childDirectoryEntry) {
            $contents[] = new MediaContentItem(
                $childDirectoryEntry->name.'/',
                $childDirectoryEntry->mtime,
                null,
                null,
                null,
            );
        }

        foreach ($childFileEntries as $childFileEntry) {
            // TODO: These are legacy things.
            if ($childFileEntry->name === 'index.php' || $childFileEntry->name === 'viewer_config.json') {
                continue;
            }

            if ($childFileEntry->name == 'README.html') {
                continue;
            }

            $contents[] = new MediaContentItem(
                $childFileEntry->name,
                $childFileEntry->mtime,
                $childFileEntry->mime_type,
                $childFileEntry->thumbnail,
                $childFileEntry->filmstrip,
            );
        }

        return $contents;
    }

    public function getReadmeHtml(): ?string
    {
        $readmePath = config('media.path').'/'.$this->directoryEntry->path.'/README.html';
        if (!file_exists($readmePath)) {
            return null;
        }

        return file_get_contents($readmePath);
    }
}
