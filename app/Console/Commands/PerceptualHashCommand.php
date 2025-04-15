<?php

namespace App\Console\Commands;

use App\Media\VideoPerceptualHasher;
use App\Utilities\Math;
use Illuminate\Console\Command;

class PerceptualHashCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:phash {--T|table} {--r|reference=} {path?*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the StashDB-compatible phash for files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $showAsTable = $this->option('table');
        $referenceHash = $this->option('reference');
        $paths = $this->argument('path');

        if ($showAsTable) {
            $header = ['File', 'Hash'];
            if ($referenceHash !== null) {
                $header[] = 'Distance';
            }

            $this->table($header, array_map(function ($path) use ($referenceHash) {
                $hasher = new VideoPerceptualHasher($path);
                $hash = $hasher->hash();

                $row = [basename($path), $hash];
                if ($referenceHash !== null) {
                    $row[] = Math::calculateHashHammingDistance($hash, $referenceHash);
                }

                return $row;
            }, $paths));
        } else {
            foreach ($paths as $path) {
                $hasher = new VideoPerceptualHasher($path);
                $hash = $hasher->hash();

                if ($referenceHash !== null) {
                    $hammingDistance = Math::calculateHashHammingDistance($hash, $referenceHash);
                    $this->line($hash.' ('.$hammingDistance.') '.$path);
                } else {
                    $this->line($hash.' '.$path);
                }
            }
        }
    }
}
