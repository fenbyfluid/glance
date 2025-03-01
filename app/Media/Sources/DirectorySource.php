<?php

namespace App\Media\Sources;

use App\Media\MediaContentItem;
use DirectoryIterator;

class DirectorySource
{
    private readonly string $viewerConfigHash;

    public function __construct(private readonly string $path) {}

    public function getContents(): array
    {
        $iterator = new DirectoryIterator($this->path);

        $contents = [[], []];
        foreach ($iterator as $child) {
            $name = $child->getFilename();

            if ($name[0] === '.' && $name !== '..') {
                continue;
            }

            // TODO: These are legacy things.
            if ($name === 'index.php' || $name === 'viewer_config.json' || str_starts_with($name, 'preview_')) {
                continue;
            }

            if ($child->isDir()) {
                $contents[0][] = new MediaContentItem($name, null);

                continue;
            }

            $contents[1][] = new MediaContentItem($name, $this->getThumbnail($child->getRealPath()));
        }

        foreach ($contents as &$group) {
            usort($group, fn ($a, $b) => strnatcasecmp($a->name, $b->name));
        }
        unset($group);

        return $contents;
    }

    private function getThumbnail(string $path): ?string
    {
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
            $thumbnail = null;
        }

        return $thumbnail;
    }
}
