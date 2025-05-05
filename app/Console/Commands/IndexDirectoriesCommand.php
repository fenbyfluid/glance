<?php

namespace App\Console\Commands;

use App\Jobs\IndexDirectoryJob;
use App\Utilities\Path;
use Illuminate\Console\Command;

class IndexDirectoriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:index {--a|async} {--d|depth=0} {--r|force-recurse} {--f|force-update} {path?*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the cached file index';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $mediaBase = config('media.path');

        $async = $this->option('async');
        $maxDepth = (int) $this->option('depth');
        $forceUpdate = $this->option('force-update');
        $forceTraversal = $forceUpdate || $this->option('force-recurse');
        $paths = $this->argument('path');

        foreach ($paths as $unsafePath) {
            if (strlen($unsafePath) > 0 && $unsafePath[0] === '/') {
                if (!str_starts_with($unsafePath, $mediaBase)) {
                    $this->warn("Path '{$unsafePath}' does not start with media base '{$mediaBase}', ignoring");

                    continue;
                }

                $unsafePath = substr($unsafePath, strlen($mediaBase) + 1);
            }

            $path = Path::resolve($unsafePath);

            try {
                $pendingDispatch = dispatch(new IndexDirectoryJob($path, $maxDepth, $forceTraversal, $forceUpdate));
                if (!$async) {
                    $pendingDispatch->onConnection('sync');
                }
            } catch (\Exception $e) {
                $this->error("Unable to index path '{$path}': ".$e->getMessage());
            }
        }
    }
}
