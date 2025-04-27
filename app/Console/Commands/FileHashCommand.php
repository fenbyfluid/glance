<?php

namespace App\Console\Commands;

use App\Media\OpenSubtitlesHasher;
use App\Media\VideoPerceptualHasher;
use App\Utilities\Math;
use Illuminate\Console\Command;

class FileHashCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:hash {--T|table} {--m|methods=*} {--r|reference=} {path?*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate StashDB-compatible hashes for files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $showAsTable = $this->option('table');
        $referenceHash = $this->option('reference');
        $methods = $this->option('methods') ?: ['phash'];
        $paths = $this->argument('path');

        $knownMethods = ['oshash', 'phash'];
        if (count(array_diff($methods, $knownMethods)) > 0) {
            $this->fail('Requested hash method unknown, known methods: '.implode(', ', $knownMethods));
        }

        if ($referenceHash !== null && !in_array('phash', $methods, true)) {
            $this->fail('Reference hash provided but phash method not requested');
        }

        if ($showAsTable) {
            $header = ['file'];
            foreach ($methods as $method) {
                $header[] = $method;
                if ($method === 'phash' && $referenceHash !== null) {
                    $header[] = 'Distance';
                }
            }

            $this->table($header, array_map(function ($path) use ($methods, $referenceHash) {
                $row = [basename($path)];

                foreach ($methods as $method) {
                    $hasher = match ($method) {
                        'phash' => new VideoPerceptualHasher($path),
                        'oshash' => new OpenSubtitlesHasher($path),
                    };

                    $hash = $hasher->hash();
                    $row[] = $hash;

                    if ($method === 'phash' && $referenceHash !== null) {
                        $row[] = Math::calculateHashHammingDistance($hash, $referenceHash);
                    }
                }

                return $row;
            }, $paths));
        } else {
            foreach ($paths as $path) {
                $line = '';
                foreach ($methods as $method) {
                    $hasher = match ($method) {
                        'phash' => new VideoPerceptualHasher($path),
                        'oshash' => new OpenSubtitlesHasher($path),
                    };

                    $hash = $hasher->hash();
                    $line .= $hash.' ';

                    if ($method === 'phash' && $referenceHash !== null) {
                        $hammingDistance = Math::calculateHashHammingDistance($hash, $referenceHash);
                        $line .= '('.$hammingDistance.') ';
                    }
                }

                $this->line($line.$path);
            }
        }
    }
}
