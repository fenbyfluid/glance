<?php

namespace App\Jobs;

use App\Models\IndexedFile;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Process;
use Jcupitt\Vips;

class ExtractTextJob implements ShouldQueue
{
    use Batchable, Queueable;

    // TODO: We could require a passed in IndexedFile to always have its directory relationship populated, and thus get rid of the path argument.
    public function __construct(
        #[WithoutRelations]
        public IndexedFile $file,
        public string $path,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $path = $this->path;
        if (!str_starts_with($path, '/')) {
            $path = config('media.path').'/'.$path;
        }

        $spans = self::extractTextSpans($path);

        // TODO: Re-filter out entries where all the spans are nonsense.
        $text = implode("\n", array_map(fn($span) => $span[0], $spans));

        // TODO: Indicate the state of text extraction on IndexedFile instead.
        $this->file->extractedText()->create([
            'text' => $text,
        ]);
    }

    /**
     * @return array{string, array{int, int, int, int}, int}
     */
    public static function extractTextSpans(string $imagePath): array {
        // TODO: Tesseract get's pretty confused if any denied characters are actually present in the text.
        //       The big reason for this is "|", which often gets mixed up with "I", so only deny that for now.
        $tesseractCharacterDenyList = '|'; // '()[]{}/\_|';

        // TODO: This does an alright job with screenshots (which is our primary goal),
        //       but it'd be nice to do a better job with comics and comic-like art.
        //       PaddleOCR looks very promising for future investigation here.
        //       Another alternative would be to use EAST for the segmentation instead of PSM 11.
        $result = Process::timeout(30)->run([
            '/usr/bin/tesseract',
            $imagePath,
            'stdout',
            '-l', 'eng',
            '--psm', '11',
            '-c', 'tessedit_char_blacklist='.$tesseractCharacterDenyList,
            'wordstrbox',
        ]);

        $spans = [];

        $lines = explode("\n", rtrim($result->output()));
        foreach ($lines as $line) {
            // Ignore the padded box lines.
            if ($line[0] === "\t") {
                continue;
            }

            $parsed = explode(' ', $line, 7);
            if ($parsed[0] !== 'WordStr') {
                throw new \Exception('Unexpected line: '.$line);
            }

            // TODO: Using one of the outputs with a confidence score (tsv, alto) would let us discard much more reliably.
            //       This check mainly exists to avoid noise turning the whole image into a single merged box, so instead
            //       discarding individual unreliable "words" would meet that goal more effectively, and not filter out
            //       two-letter words.
            $text = substr($parsed[6], 1);
            if (preg_match('/[a-z\']{3}/i', $text) !== 1) {
                // Ignore any text lines without at least 3 letters side-by-side.
                continue;
            }

            // left, bottom, right, top - relative to the bottom left corner of the image
            $coords = [
                (int)$parsed[1],
                (int)$parsed[2],
                (int)$parsed[3],
                (int)$parsed[4],
            ];

            // Pad the bounding box to overlap with neighboring lines.
            $height = $coords[3] - $coords[1];
            $widthPadding = round($height * 0.5);
            $heightPadding = round($height * 0.5);
            $coords[0] -= $widthPadding;
            $coords[1] -= $heightPadding;
            $coords[2] += $widthPadding;
            $coords[3] += $heightPadding;

            $spans[] = [
                trim($text),
                $coords,
                [$height],
            ];
        }

        // Merge overlapping spans to compute bounding boxes.
        // Originally, we extracted the bounding boxes and used them to re-detect the text in column node,
        // but the output was worse, so now we just concat the text spans during this pass.
        do {
            // Repeat until no more spans are merged.
            $spansChanged = false;

            foreach ($spans as $i => $span) {
                foreach ($spans as $j => $otherSpan) {
                    if ($i <= $j) {
                        continue;
                    }

                    if ($span[1][0] >= $otherSpan[1][2] || $span[1][2] <= $otherSpan[1][0]) {
                        continue;
                    }

                    if ($span[1][1] >= $otherSpan[1][3] || $span[1][3] <= $otherSpan[1][1]) {
                        continue;
                    }

                    $spans[$i][0] = $otherSpan[0].' '.$span[0];
                    $spans[$i][1][0] = min($span[1][0], $otherSpan[1][0]);
                    $spans[$i][1][1] = min($span[1][1], $otherSpan[1][1]);
                    $spans[$i][1][2] = max($span[1][2], $otherSpan[1][2]);
                    $spans[$i][1][3] = max($span[1][3], $otherSpan[1][3]);
                    $spans[$i][2] = [...$span[2], ...$otherSpan[2]];
                    unset($spans[$j]);

                    $spansChanged = true;
                    break;
                }
            }
        } while ($spansChanged);

        foreach ($spans as $i => $span) {
            // Lazy median.
            sort($span[2]);
            $spans[$i][2] = $span[2][floor(count($span[2]) / 2)];
        }

        // Sort by height.
        // Not strictly necessary but makes the output easier to read.
        usort($spans, function ($a, $b) {
            return $b[1][1] <=> $a[1][1];
        });

        return $spans;
    }

    /**
     * @param array{string, array{int, int, int, int}, int} $spans
     */
    public static function visualizeTextSpans(string $imagePath, array $spans): Vips\Image {
        $image = Vips\Image::newFromFile($imagePath);

        // Visualize the computation.
        foreach ($spans as $span) {
            [$text, $coords, $lineHeight] = $span;

            // Ensure the bounding box is within the image.
            $coords[0] = max(0, $coords[0]);
            $coords[1] = max(0, $coords[1]);
            $coords[2] = min($image->width, $coords[2]);
            $coords[3] = min($image->height, $coords[3]);

            $black = Vips\Image::black($coords[2] - $coords[0], $coords[3] - $coords[1]);
            $image = $image->composite($black, Vips\BlendMode::OVER, [
                'x' => $coords[0],
                'y' => $image->height - $coords[3],
            ]);

            $textPadding = $lineHeight * 0.5;
            $pangoText = htmlspecialchars($text, ENT_NOQUOTES | ENT_XML1, 'UTF-8');
            $textImage = Vips\Image::text($pangoText, [
                'font' => 'sans ' . $lineHeight,
                'width' => ($coords[2] - $coords[0]) - ($textPadding * 2),
            ]);

            $overlay = $textImage->newFromImage([255, 0, 0])
                ->copy(['interpretation' => 'srgb'])
                ->bandjoin($textImage);

            $image = $image->composite($overlay, Vips\BlendMode::OVER, [
                'x' => $coords[0] + $textPadding,
                'y' => ($image->height - $coords[3]) + $textPadding,
            ]);
        }

        return $image;
    }
}
