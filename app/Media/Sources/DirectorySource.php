<?php

namespace App\Media\Sources;

use App\Media\MediaContentItem;
use App\Models\IndexedDirectory;

readonly class DirectorySource
{
    private string $viewerConfigHash;

    /**
     * @param  array<string, bool>  $accessControlInfo
     */
    public function __construct(private IndexedDirectory $directoryEntry, private array $accessControlInfo) {}

    public function getContents(): array
    {
        $childDirectoryEntries = $this->directoryEntry->children()->havingAccess($this->accessControlInfo)->get();
        $childFileEntries = $this->directoryEntry->files()->havingAccess($this->accessControlInfo)->get();

        $contents = [];

        foreach ($childDirectoryEntries as $childDirectoryEntry) {
            $contents[] = new MediaContentItem($childDirectoryEntry->name.'/', null, null);
        }

        foreach ($childFileEntries as $childFileEntry) {
            // TODO: These are legacy things.
            if ($childFileEntry->name === 'index.php' || $childFileEntry->name === 'viewer_config.json') {
                continue;
            }

            if ($childFileEntry->name == 'README.html') {
                continue;
            }

            $itemPath = config('media.path').'/'.$this->directoryEntry->path.'/'.$childFileEntry->name;

            $contents[] = new MediaContentItem($childFileEntry->name, $childFileEntry->mime_type, $this->getThumbnailWebPath($itemPath));
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

    private function getThumbnailWebPath(string $path): ?string
    {
        // TODO: This needs a complete refactor to generate locally.
        // TODO: It also seems like it doesn't really belong at this level.

        // This lets us find the thumbnails from the old code, so we have something for testing.
        if (!isset($this->viewerConfigHash)) {
            $config = [
                'width' => 320,
                'height' => 180,
                'skip' => 15.0,
            ];

            $configPath = config('media.path').'/'.$this->directoryEntry->path.'/viewer_config.json';
            if (file_exists($configPath)) {
                $config = json_decode(file_get_contents($configPath), true) + $config;
            }

            $this->viewerConfigHash = sha1(json_encode($config));
        }

        $hash = $this->viewerConfigHash.'_'.sha1($path);

        $thumbnail = '.thumbs/'.$hash.'.jpg';
        if (!is_file(config('media.path').'/'.$thumbnail)) {
            return null;
        }

        return media_url($thumbnail);
    }
}
