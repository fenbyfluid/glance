<?php

namespace App\Console\Commands;

use App\Models\IndexedFile;
use App\Utilities\Path;
use Illuminate\Console\Command;

class CleanImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean-images {--dry-run} {--delete-filmstrip}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Repair missing thumbnails and filmstrips, and optionally delete filmstrips';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        if ($dryRun) {
            $this->info('Dry run enabled. No changes will be made.');
        }

        $files = IndexedFile::whereNotNull('thumbnail')->orWhereNotNull('filmstrip');
        $count = $files->count();

        $progress = $this->output->createProgressBar($count);

        foreach ($files->lazyById() as $file) {
            if ($this->output->isVeryVerbose()) {
                $this->info(sprintf('Checking %s', $file->name));
            }

            $this->checkImage($file, 'thumbnail');
            $this->checkImage($file, 'filmstrip');

            if ($this->option('delete-filmstrip') && $file->filmstrip) {
                if ($this->output->isVerbose()) {
                    $this->info(sprintf('Deleting filmstrip %s', $file->filmstrip));
                }

                if (!$dryRun) {
                    $file->filmstrip = null;
                }
            }

            if (!$dryRun && $file->isDirty()) {
                $file->save();
            }

            $progress->advance();
        }

        $progress->finish();
    }

    /**
     * @param  "thumbnail"|"filmstrip"  $key
     */
    private function checkImage(IndexedFile $file, string $key)
    {
        $dryRun = $this->option('dry-run');

        $imageId = $file->{$key};
        if (!$imageId) {
            return;
        }

        $imagePath = Path::makeThumbnailPath($imageId);
        if (file_exists($imagePath)) {
            return;
        }

        $legacyPath = sprintf('%s/.thumbs/%s.avif', config('media.path'), $imageId);
        if (file_exists($legacyPath)) {
            if ($this->output->isVerbose()) {
                $this->info(sprintf('Moving legacy %s for %s', $key, $imageId));
            }

            if (!$dryRun && !@rename($legacyPath, $imagePath)) {
                $this->warn(sprintf('Failed to move legacy %s for %s', $key, $imageId));
            }

            return;
        }

        if ($this->output->isVerbose()) {
            $this->info(sprintf('%s for %s does not exist', $key, $imageId));
        }

        if (!$dryRun) {
            $file->{$key} = null;
        }
    }
}
