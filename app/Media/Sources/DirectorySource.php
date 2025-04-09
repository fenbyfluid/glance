<?php

namespace App\Media\Sources;

use App\Media\MediaContentItem;
use DirectoryIterator;

readonly class DirectorySource
{
    private string $viewerConfigHash;

    public function __construct(private string $path) {}

    // TODO: At some point we'll want a hybrid provider that loads cached metadata rather
    //       than re-parsing files, and augments it with long-term configured metadata.
    public function getContents(): array
    {
        $iterator = new DirectoryIterator($this->path);

        $contents = [[], []];
        foreach ($iterator as $child) {
            $name = $child->getFilename();

            if ($name[0] === '.') {
                continue;
            }

            // TODO: These are legacy things.
            if ($name === 'index.php' || $name === 'viewer_config.json' || str_starts_with($name, 'preview_')) {
                continue;
            }

            if ($child->isDir()) {
                $contents[0][] = new MediaContentItem($name, $name.'/', null, null);

                continue;
            }

            // TODO: Deal with symlinks sanely.
            if (!$child->isFile()) {
                continue;
            }

            $itemPath = $child->getRealPath();
            if ($itemPath === false) {
                continue;
            }

            $contents[1][] = new MediaContentItem($name, $name, $this->getMimeType($itemPath), $this->getThumbnailWebPath($itemPath));
        }

        foreach ($contents as $i => &$group) {
            if (empty($group)) {
                unset($contents[$i]);

                continue;
            }

            usort($group, fn ($a, $b) => strnatcasecmp($a->label, $b->label));
        }
        unset($group);

        return $contents;
    }

    private function getMimeType(string $itemPath): ?string
    {
        $mimeType = mime_content_type($itemPath);

        return ($mimeType !== false) ? $mimeType : null;
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

            $configPath = $this->path.'/viewer_config.json';
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
