<?php

namespace App\Jobs;

use App\Enums\ExtractedTextStatus;
use App\Media\MediaContentKind;
use App\Models\IndexedFile;
use Dom\HTMLDocument;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
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

        $text = match ($this->file->kind) {
            MediaContentKind::Image => $this->extractTextFromImageFile($path),
            MediaContentKind::Text => $this->extractTextFromTextFile($path),
            MediaContentKind::Html => $this->extractTextFromHtmlFile($path),
            default => throw new \LogicException('Unsupported media kind: '.$this->file->kind->name),
        };

        if ($text === '') {
            $this->file->update([
                'extracted_text_status' => ExtractedTextStatus::Empty,
            ]);

            return;
        }

        $this->file->load('extractedText');
        if ($this->file->extractedText) {
            $this->file->extractedText->forceDelete();
        }

        // The Typesense docs advise breaking long blocks of text up into a paragraphs
        // and indexing them as if they're separate documents to improve recall performance
        // We could turn this into a one-to-many relationship to do that
        $this->file->extractedText()->create([
            'text' => $text,
        ]);

        $this->file->update([
            'extracted_text_status' => ExtractedTextStatus::Complete,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $timedOut = $exception instanceof ProcessTimedOutException;

        $this->file->update([
            'extracted_text_status' => ($timedOut) ? ExtractedTextStatus::TimedOut : ExtractedTextStatus::Error,
        ]);
    }

    private function extractTextFromImageFile(string $path): string
    {
        $boxes = self::extractTextBoxes($path);
        $text = self::extractTextFromBoxes($boxes);

        // A bit of a hack, drop anything we get back consisting only single-line words and less than 3 lines.
        if (substr_count($text, "\n") < 3) {
            if (substr_count($text, ' ') < 2) {
                $text = '';
            }
        }

        return $text;
    }

    private function extractTextFromTextFile(string $path): string|array|null
    {
        $text = file_get_contents($path);
        $text = preg_replace('/[\t ]+/', ' ', $text);
        $text = preg_replace("/(?:\r?\n)+/", "\n", $text);

        return $text;
    }

    /**
     * In the interest of time, this function was partially generated with GPT-4
     */
    private function extractTextFromHtmlFile(string $path): string
    {
        // List of block-level HTML tags we want to respect
        $blockTags = [
            'address', 'article', 'aside', 'blockquote', 'br', 'details', 'dialog', 'div', 'dl', 'dt', 'dd',
            'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'header', 'hgroup', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul',
        ];

        $document = HTMLDocument::createFromFile($path, LIBXML_NOERROR);

        $lines = [trim($document->title), ''];
        $stack = [[$document->body, true]];

        while ($stack) {
            [$node, $isBlockContext] = array_pop($stack);

            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim($node->nodeValue);

                if ($isBlockContext) {
                    $lines[] = $text;
                } else {
                    // Only add a space if the line isn't empty
                    $lastIdx = count($lines) - 1;
                    if ($lines[$lastIdx] !== '') {
                        $lines[$lastIdx] .= ' ';
                    }

                    $lines[$lastIdx] .= $text;
                }
            } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($node->nodeName);
                $isBlock = in_array($tag, $blockTags, true);

                if ($isBlock) {
                    // Always emit a new line for this block element, even if it has no text
                    $lines[] = '';
                }

                // Gather children in order (left-to-right)
                $children = [];
                for ($child = $node->firstChild; $child; $child = $child->nextSibling) {
                    $children[] = [$child, $isBlock];
                }

                // Push children to stack in reverse order for left-to-right traversal
                for ($i = count($children) - 1; $i >= 0; $i--) {
                    $stack[] = $children[$i];
                }
            }
        }

        return implode("\n", array_filter($lines));
    }

    public static function canExtractText(MediaContentKind $kind): bool
    {
        return match ($kind) {
            MediaContentKind::Image, MediaContentKind::Text, MediaContentKind::Html => true,
            default => false,
        };
    }

    /**
     * @return list<array{left: int, top: int, width: int, height: int, lines: list<array{left: int, top: int, width: int, height: int, words: list<array{conf: float, dict?: bool, text: string}>}>}>
     */
    public static function extractTextBoxes(string $imagePath): array
    {
        // TODO: This does an alright job with screenshots (which is our primary goal),
        //       but it'd be nice to do a better job with comics and comic-like art.
        //       PaddleOCR looks very promising for future investigation here.
        //       Another alternative would be to use EAST for the segmentation instead of PSM 11.
        //       However, this is looking much better after the re-write to use confidence scores.
        // TODO: We're seeing long runtime on some comic images, needs further investigation.
        //       On one test image, it took nearly 90 seconds, and the output was complete gibberish.
        //       Turns out this occurs when there is no text in the image, so something like EAST is probably the answer.
        // TODO: There are some very large images with text that take a long time to process (300+ seconds).
        //       The answer for those appears to be tiling them up and running the jobs in parallel.
        //       Once the initial spans are extracted, merge them discarding duplicates from the overlap, then continue as normal.
        $result = Process::timeout(45)->run([
            '/usr/bin/tesseract',
            $imagePath,
            'stdout',
            '-l', 'eng',
            '--psm', '11',
            'tsv',
        ])->throw();

        $outputLines = explode("\n", rtrim($result->output()));
        $header = explode("\t", array_shift($outputLines));

        // Parse Tesseract output into structured lines and words, with confidence scores and bounding boxes.
        $lines = [];
        $lineHeights = [];
        foreach ($outputLines as $outputLine) {
            $span = array_map(fn ($value, $key) => match ($key) {
                'text' => $value,
                'conf' => (float) $value,
                default => (int) $value,
            }, explode("\t", $outputLine), $header);
            $span = array_combine($header, $span);

            // Because we're in PSM 11 mode, we only have blocks consisting of a single line, with one or more words each.
            if ($span['page_num'] !== 1 || $span['par_num'] > 1 || $span['line_num'] > 1) {
                throw new \Exception('Unexpected structure: '.json_encode($span));
            }

            // Discard all structures above lines.
            if ($span['level'] < 4) {
                continue;
            }

            if ($span['level'] === 4) {
                $lines[$span['block_num']] = [
                    'left' => $span['left'],
                    'top' => $span['top'],
                    'width' => $span['width'],
                    'height' => $span['height'],
                    'words' => [],
                ];

                $lineHeights[] = $span['height'];

                continue;
            }

            if ($span['level'] === 5) {
                $text = $span['text'];

                // Small hack, Tesseract has a habit of transposing these, and "I" is much more likely.
                if ($text === '|') {
                    $text = 'I';
                }

                $lineHeights[] = $span['height'];

                // Occasionally, Tesseract will combine a good word with absolute nonsense non-text to produce a line.
                // e.g. a username next to a profile photo. Keep the per-word BBoxes to fix these up in post.
                $lines[$span['block_num']]['words'][$span['word_num']] = [
                    'left' => $span['left'],
                    'top' => $span['top'],
                    'width' => $span['width'],
                    'height' => $span['height'],
                    'conf' => $span['conf'],
                    'text' => $text,
                ];

                continue;
            }

            throw new \Exception('Unexpected level: '.$span['level']);
        }

        if (empty($lines)) {
            return [];
        }

        sort($lineHeights);
        $medianHeight = $lineHeights[floor(count($lineHeights) * 0.5)];
        $minimumHeight = $medianHeight * (1 / 3);
        $maximumHeight = $medianHeight * 3;

        foreach ($lines as $i => $line) {
            $goodWordCount = 0;
            $initialWordCount = count($line['words']);

            foreach ($line['words'] as $j => $word) {
                // Remove words significantly smaller or larger than the median
                if ($word['height'] < $minimumHeight || $word['height'] > $maximumHeight) {
                    unset($lines[$i]['words'][$j]);

                    continue;
                }

                // Remove very low confidence words
                if ($word['conf'] < 33) {
                    unset($lines[$i]['words'][$j]);

                    continue;
                }

                // Don't bother checking very high confidence words against the dictionary
                if ($word['conf'] > 95) {
                    $goodWordCount++;

                    continue;
                }

                // Or medium confidence words with a decent length
                if ($word['conf'] > 66 && preg_match('/\w{4}/', $word['text'])) {
                    $goodWordCount++;

                    continue;
                }

                $inDictionary = static::checkDictionaryWord($word['text']);

                if ($inDictionary !== null) {
                    $lines[$i]['words'][$j]['dict'] = $inDictionary;
                }

                if ($inDictionary) {
                    $goodWordCount++;
                }
            }

            // Remove lines without any good words (including ones that are now empty)
            if ($goodWordCount == 0) {
                unset($lines[$i]);

                continue;
            }

            $wordConfidence = array_map(fn ($word) => $word['conf'], $lines[$i]['words']);
            sort($wordConfidence);
            $lineConfidence = $wordConfidence[floor(count($wordConfidence) * 0.5)];

            // Remove low confidence lines
            if ($lineConfidence < 66) {
                unset($lines[$i]);

                continue;
            }

            // If words were removed, and line bounding box is much taller than the words, rebuild it.
            if (count($lines[$i]['words']) < $initialWordCount) {
                $firstWord = reset($lines[$i]['words']);
                $newLine = [
                    'top' => $firstWord['top'],
                    'left' => $firstWord['left'],
                    'width' => $firstWord['width'],
                    'height' => $firstWord['height'],
                    'words' => [],
                ];

                foreach ($lines[$i]['words'] as $word) {
                    $newLine = self::extendBoxWithItem($newLine, $word, 'words');
                }

                $lines[$i] = $newLine;
            }

            // Pad the bounding box to overlap with neighboring lines
            $padding = (int) round($lines[$i]['height'] * 0.4);
            $lines[$i]['top'] -= $padding;
            $lines[$i]['height'] += $padding * 2;
        }

        // Merge overlapping lines to produce paragraphs.
        // The $lines array will be arranged left to right then top to bottom.
        // But they may not be contiguous, e.g. if columns are present.
        $paragraphs = [];
        foreach ($lines as $line) {
            $found = false;
            foreach ($paragraphs as $j => $paragraph) {
                if (self::doBoxesOverlap($paragraph, $line)) {
                    $paragraphs[$j] = self::extendBoxWithItem($paragraph, $line, 'lines');
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $paragraphs[] = [
                    'top' => $line['top'],
                    'left' => $line['left'],
                    'height' => $line['height'],
                    'width' => $line['width'],
                    'lines' => [$line],
                ];
            }
        }

        return self::orderBoxes($paragraphs);
    }

    public static function extractTextFromBoxes(array $boxes): string
    {
        $text = '';

        foreach ($boxes as $paragraph) {
            foreach ($paragraph['lines'] as $line) {
                foreach ($line['words'] as $word) {
                    $text .= $word['text'].' ';
                }
            }

            $text = rtrim($text)."\n";
        }

        return rtrim($text);
    }

    /**
     * @param  list<array{left: int, top: int, width: int, height: int, lines: list<array{left: int, top: int, width: int, height: int, words: list<array{conf: float, dict?: bool, text: string}>}>}>  $boxes
     */
    public static function visualizeTextBoxes(string $imagePath, array $boxes): Vips\Image
    {
        $image = Vips\Image::newFromFile($imagePath)->extract_band(0, ['n' => 3]);

        // Visualize the computation.
        foreach ($boxes as $paragraph) {
            $paragraphImage = $image->extract_area(
                $paragraph['left'],
                $paragraph['top'],
                $paragraph['width'],
                $paragraph['height'],
            );

            foreach ($paragraph['lines'] as $line) {
                $lineImage = $paragraphImage->extract_area(
                    $line['left'] - $paragraph['left'],
                    $line['top'] - $paragraph['top'],
                    $line['width'],
                    $line['height'],
                );

                foreach ($line['words'] as $word) {
                    $color = match ($word['dict'] ?? null) {
                        true => [255, 0, 255],
                        false => [0, 0, 255],
                        null => [0, 255, 255],
                    };

                    $lineImage = $lineImage->draw_rect(
                        $color,
                        $word['left'] - $line['left'],
                        $word['top'] - $line['top'],
                        $word['width'],
                        $word['height'],
                    );

                    $textScale = 0.5;
                    $black = Vips\Image::black(ceil($word['width'] * $textScale), ceil($word['height'] * $textScale));
                    $lineImage = $lineImage->composite($black, Vips\BlendMode::OVER, [
                        'x' => ($word['left'] - $line['left']) + 1,
                        'y' => ($word['top'] - $line['top']) + 1,
                    ]);

                    $pangoText = htmlspecialchars($word['text'], ENT_NOQUOTES | ENT_XML1, 'UTF-8');
                    $textImage = Vips\Image::text($pangoText, [
                        'font' => sprintf('sans %d', ceil($word['height'] * $textScale)),
                    ]);

                    $overlay = $textImage->newFromImage([255, 255, 255])
                        ->copy(['interpretation' => 'srgb'])
                        ->bandjoin($textImage);

                    $lineImage = $lineImage->composite($overlay, Vips\BlendMode::OVER, [
                        'x' => ($word['left'] - $line['left']) + 1,
                        'y' => ($word['top'] - $line['top']) + 1,
                    ])->extract_band(0, ['n' => 3]);
                }

                $lineImage = $lineImage->draw_rect(
                    [0, 255, 0],
                    0,
                    0,
                    $line['width'],
                    $line['height'],
                );

                $paragraphImage = $paragraphImage->insert(
                    $lineImage,
                    $line['left'] - $paragraph['left'],
                    $line['top'] - $paragraph['top'],
                );
            }

            $paragraphImage = $paragraphImage->draw_rect(
                [255, 0, 0],
                0,
                0,
                $paragraph['width'],
                $paragraph['height'],
            );

            $image = $image->insert($paragraphImage, $paragraph['left'], $paragraph['top']);
        }

        return $image;
    }

    private static function checkDictionaryWord(string $text): ?bool
    {
        static $approvedWords = [];

        static $dictionary = false;
        if (!$dictionary) {
            $broker = enchant_broker_init();
            if (!$broker) {
                throw new \Exception('Enchantment broker initialization failed');
            }

            // TODO: Support multiple languages.
            $dictionary = enchant_broker_request_dict($broker, 'en');
            if (!$dictionary) {
                throw new \Exception('Enchantment dictionary initialization failed');
            }
        }

        if (strlen($text) <= 1) {
            return null;
        }

        if (isset($approvedWords[$text])) {
            return $approvedWords[$text];
        }

        // Enchant's spelling providers need us to remove all surrounding punctuation
        $cleanText = preg_replace('/^\p{P}+|\p{P}+$/', '', $text);

        // There are too many single-letter "dictionary" words
        if (strlen($cleanText) <= 1) {
            return null;
        }

        if (isset($approvedWords[$cleanText])) {
            return $approvedWords[$cleanText];
        }

        $result = enchant_dict_check($dictionary, $cleanText);
        $approvedWords[$text] = $result;
        $approvedWords[$cleanText] = $result;

        return $result;
    }

    private static function doBoxesOverlap(array $a, array $b): bool
    {
        return !(
            $a['top'] > ($b['top'] + $b['height']) ||
            ($a['top'] + $a['height']) < $b['top'] ||
            $a['left'] > ($b['left'] + $b['width']) ||
            ($a['left'] + $a['width']) < $b['left']
        );
    }

    private static function extendBoxWithItem(array $parent, array $child, string $key): array
    {
        $left = min($parent['left'], $child['left']);
        $top = min($parent['top'], $child['top']);

        $right = max($parent['left'] + $parent['width'], $child['left'] + $child['width']);
        $bottom = max($parent['top'] + $parent['height'], $child['top'] + $child['height']);

        $width = $right - $left;
        $height = $bottom - $top;

        return [
            'top' => $top,
            'left' => $left,
            'width' => $width,
            'height' => $height,
            $key => [...$parent[$key], $child],
        ];
    }

    /**
     * Approximate right-to-left, top-to-bottom reading order for unknown layouts,
     * handling pure two-column layouts as well as irregular ones.
     *
     * In the interest of time, this function was generated by GPT-4
     *
     * @param  array  $boxes  Array of boxes with at least 'top', 'left', 'width', 'height'
     * @param  int  $columnThreshold  Minimum pixel gap to consider columns separate
     * @param  int  $rowThreshold  Minimum pixel gap to consider boxes in same row
     * @return array Ordered list of boxes
     */
    private static function orderBoxes(array $boxes, int $columnThreshold = 40, int $rowThreshold = 20): array
    {
        if (count($boxes) <= 1) {
            return $boxes;
        }

        // 1. Try to detect columns by finding a significant gap between sorted left edges
        usort($boxes, fn ($a, $b) => $a['left'] <=> $b['left']);

        $gaps = [];
        for ($i = 1; $i < count($boxes); $i++) {
            $gaps[] = [
                'gap' => $boxes[$i]['left'] - ($boxes[$i - 1]['left'] + $boxes[$i - 1]['width']),
                'i' => $i,
            ];
        }

        // Find the largest gap that's above the threshold
        $split = null;
        foreach ($gaps as $g) {
            if ($g['gap'] > $columnThreshold) {
                $split = $g['i'];
                break;
            }
        }

        // We have two columns! Recursively order each column, starting with the left
        if ($split !== null) {
            $left = self::orderBoxes(array_slice($boxes, 0, $split), $columnThreshold, $rowThreshold);
            $right = self::orderBoxes(array_slice($boxes, $split), $columnThreshold, $rowThreshold);

            return array_merge($left, $right);
        }

        // If no clear columns, fall back to row-based grouping as before
        // Group by rows (top)
        usort($boxes, fn ($a, $b) => $a['top'] <=> $b['top']);
        $rows = [];
        foreach ($boxes as $box) {
            $placed = false;
            foreach ($rows as &$row) {
                if (abs($box['top'] - $row['avgTop']) < $rowThreshold) {
                    $row['boxes'][] = $box;
                    $row['avgTop'] = array_sum(array_column($row['boxes'], 'top')) / count($row['boxes']);
                    $placed = true;
                    break;
                }
            }
            unset($row);
            if (!$placed) {
                $rows[] = ['avgTop' => $box['top'], 'boxes' => [$box]];
            }
        }

        // Sort rows top-to-bottom, and boxes in each row right-to-left
        usort($rows, fn ($a, $b) => $a['avgTop'] <=> $b['avgTop']);
        $ordered = [];
        foreach ($rows as $row) {
            usort($row['boxes'], fn ($a, $b) => $b['left'] <=> $a['left']);
            foreach ($row['boxes'] as $box) {
                $ordered[] = $box;
            }
        }

        return $ordered;
    }
}
