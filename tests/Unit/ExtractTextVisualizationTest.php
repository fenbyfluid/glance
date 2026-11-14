<?php

namespace Tests\Unit;

use App\Jobs\ExtractTextJob;
use Jcupitt\Vips\Image;
use PHPUnit\Framework\TestCase;

class ExtractTextVisualizationTest extends TestCase
{
    public function test_visualizes_padded_boxes_at_image_edges(): void
    {
        $image = Image::black(1100, 1280)->newFromImage([0, 0, 0]);
        $path = tempnam(sys_get_temp_dir(), 'text-boxes-');

        try {
            file_put_contents($path, $image->pngsave_buffer());

            $word = ['left' => 1058, 'top' => 1232, 'width' => 39, 'height' => 40, 'conf' => 96.8, 'text' => 'Ey'];
            $line = ['left' => 1058, 'top' => 1216, 'width' => 39, 'height' => 72, 'words' => [$word]];
            $paragraph = ['left' => 1058, 'top' => 1216, 'width' => 39, 'height' => 72, 'lines' => [$line]];

            $topLine = ['left' => -5, 'top' => -10, 'width' => 30, 'height' => 30, 'words' => []];
            $topParagraph = ['left' => -5, 'top' => -10, 'width' => 30, 'height' => 30, 'lines' => [$topLine]];
            $partialLine = ['left' => 45, 'top' => 45, 'width' => 20, 'height' => 20, 'words' => []];
            $outsideLine = ['left' => 80, 'top' => 80, 'width' => 10, 'height' => 10, 'words' => []];
            $partialParagraph = ['left' => 50, 'top' => 50, 'width' => 20, 'height' => 20, 'lines' => [$partialLine, $outsideLine]];
            $outside = ['left' => 1100, 'top' => 0, 'width' => 5, 'height' => 5, 'lines' => []];

            $result = ExtractTextJob::visualizeTextBoxes($path, [$paragraph, $topParagraph, $partialParagraph, $outside]);

            self::assertSame(1100, $result->width);
            self::assertSame(1280, $result->height);
            self::assertSame([255.0, 0.0, 0.0], $result->getpoint(1058, 1216));
            self::assertSame([255.0, 165.0, 0.0], $result->getpoint(0, 0));
            self::assertSame([255.0, 165.0, 0.0], $result->getpoint(500, 720));
            self::assertSame([255.0, 165.0, 0.0], $result->getpoint(500, 799));
            self::assertSame([255.0, 165.0, 0.0], $result->getpoint(500, 1279));
            self::assertSame([0.0, 0.0, 0.0], $result->getpoint(500, 900));
            self::assertSame([0.0, 255.0, 0.0], $result->getpoint(64, 55));
            self::assertNotEmpty($result->pngsave_buffer());
        } finally {
            unlink($path);
        }
    }

    public function test_visualizes_overlapping_tile_boundaries(): void
    {
        $image = Image::black(2500, 3000)->newFromImage([0, 0, 0]);
        $path = tempnam(sys_get_temp_dir(), 'text-boxes-');

        try {
            file_put_contents($path, $image->pngsave_buffer());
            $result = ExtractTextJob::visualizeTextBoxes($path, []);

            foreach ([0, 720, 799, 1440, 1519, 2160, 2239, 2880, 2959, 2999] as $y) {
                self::assertSame([255.0, 165.0, 0.0], $result->getpoint(100, $y));
            }
            self::assertSame([0.0, 0.0, 0.0], $result->getpoint(100, 800));
        } finally {
            unlink($path);
        }
    }

    public function test_visualization_uses_oriented_image_coordinates(): void
    {
        $image = Image::black(40, 20)->newFromImage([0, 0, 0])->copy();
        $image->set('orientation', 6);
        $path = tempnam(sys_get_temp_dir(), 'text-boxes-');

        try {
            file_put_contents($path, $image->jpegsave_buffer());
            $line = ['left' => 5, 'top' => 30, 'width' => 10, 'height' => 8, 'words' => []];
            $paragraph = ['left' => 5, 'top' => 30, 'width' => 10, 'height' => 8, 'lines' => [$line]];

            $result = ExtractTextJob::visualizeTextBoxes($path, [$paragraph]);

            self::assertSame(20, $result->width);
            self::assertSame(40, $result->height);
            self::assertSame([255.0, 0.0, 0.0], $result->getpoint(5, 30));
        } finally {
            unlink($path);
        }
    }
}
