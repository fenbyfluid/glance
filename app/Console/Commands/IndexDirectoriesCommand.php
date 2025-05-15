<?php

namespace App\Console\Commands;

use App\Jobs\IndexDirectoryJob;
use App\Utilities\Path;
use Illuminate\Bus\BatchRepository;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Bus;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class IndexDirectoriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:index {--a|async} {--d|depth=-1} {--r|force-recurse} {--f|force-update} {--g|generate-missing} {path?*}';

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
        $forceTraversal = $this->option('force-recurse');
        $forceUpdate = $this->option('force-update');
        $generateMissing = $this->option('generate-missing');
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

            $job = new IndexDirectoryJob($path, $maxDepth, $forceTraversal, $forceUpdate, $generateMissing);

            if ($async) {
                // TODO: Add an option to resume showing progress for an existing async batch.
                $this->handleAsync($job);
            } else {
                $this->handleSync($job, $path);
            }
        }
    }

    private function handleAsync(IndexDirectoryJob $job): void
    {
        $batch = Bus::batch([$job])
            ->onQueue('index')
            ->allowFailures();

        // Work around poor allowFailures() behaviour: https://github.com/laravel/framework/issues/36180
        $batch->finally(function ($batch) {
            if (!$batch->finished()) {
                resolve(BatchRepository::class)->markAsFinished($batch->id);
            }
        });

        $batch = $batch->dispatch();

        $this->line('Batch ID: '.$batch->id);

        $progressBar = $this->output->createProgressBar();
        $progressBar->start($batch->totalJobs, $batch->totalJobs - $batch->pendingJobs);

        do {
            sleep(1);

            $batch = $batch->fresh();

            $progressBar->setMaxSteps($batch->totalJobs);
            $progressBar->setProgress($batch->totalJobs - $batch->pendingJobs);
        } while (!$batch->finished());

        $progressBar->finish();
        $this->newLine();
    }

    private function handleSync(IndexDirectoryJob $job, string $path): void
    {
        $batch = Bus::batch([$job])
            ->onConnection('sync')
            ->allowFailures();

        $batch->progress(function ($batch) {
            // This unfortunate mess appears to be the only way to display a progress bar from a batch
            // callback. There's no equivalent of dispatch_sync, so these closures are always serialized
            // for queue dispatch, and accessing the normal command output doesn't work correctly.
            $output = new OutputStyle(new ArrayInput([]), new ConsoleOutput);

            $progressBar = $output->createProgressBar($batch->totalJobs);
            $progressBar->start($batch->totalJobs, $batch->totalJobs - $batch->pendingJobs);

            if ($batch->pendingJobs === 0) {
                $output->newLine();
            } else {
                $cursor = new Cursor($output);
                $cursor->moveToColumn(1);
            }
        });

        try {
            $batch->dispatch();
        } catch (\Throwable $e) {
            $this->error("Unable to index path '{$path}': ".$e->getMessage());
        }
    }
}
