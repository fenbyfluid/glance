<?php

namespace App\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

// TODO: Back to the drawing board with this class. Everything it used to contain has moved to job classes.
readonly class MediaFile
{
    private const string FFPROBE_LOG_LEVEL = 'warning';

    public function __construct(
        private string $path,
    ) {}

    /**
     * @throws \JsonException
     * @throws \RuntimeException
     */
    public function probe(): MediaInfo
    {
        // TODO: show_packets + json_decode can run out of memory when parsing.
        //       Use metadata duration for now, if we start having trouble it's probably easiest to probe again as CSV.
        $process = Process::env([
            'AV_LOG_FORCE_NOCOLOR' => '1',
        ])->run([
            config('media.ffprobe_bin', '/usr/bin/ffprobe'),
            '-loglevel',
            '+repeat+level+'.self::FFPROBE_LOG_LEVEL,
            '-hide_banner',
            '-output_format',
            'json=compact=1',
            '-show_format',
            '-show_streams',
            // '-show_packets',
            '-show_error',
            '-show_entries',
            'stream_side_data=rotation',
            $this->path,
        ]);

        $output = $process->output();
        if (str_starts_with($output, '{') && str_ends_with(rtrim($output), '}')) {
            $output = json_decode($output, flags: JSON_THROW_ON_ERROR);
        } else {
            $output = null;
        }

        $logs = $process->errorOutput();

        // TODO: Refine this error handling.
        if ($process->failed()) {
            if (isset($output->error->string)) {
                throw new \RuntimeException(sprintf('FFprobe failed with code %d: %s',
                    $output->error->code ?? 0, $output->error->string));
            } else {
                throw new \RuntimeException(sprintf('FFprobe failed with code %d:%s%s',
                    $process->exitCode(), PHP_EOL, $logs));
            }
        }

        if (strlen($logs) > 0) {
            Log::debug('FFprobe output for "'.$this->path.'":'.PHP_EOL.$logs);
        }

        return MediaInfo::fromProbeOutput($output);
    }
}
