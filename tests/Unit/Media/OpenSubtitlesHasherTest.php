<?php

namespace Tests\Unit\Media;

use App\Media\OpenSubtitlesHasher;
use PHPUnit\Framework\Attributes\TestWith;
use Random\Engine\PcgOneseq128XslRr64;
use Random\Randomizer;
use Tests\TestCase;

class OpenSubtitlesHasherTest extends TestCase
{
    private const string RANDOM_SEED = 'OpenSubtitlesHasher';

    #[TestWith([OpenSubtitlesHasher::CHUNK_SIZE * 3, '67e86d64c0b54e54'])]
    #[TestWith([OpenSubtitlesHasher::CHUNK_SIZE * 1.5, 'c48eed9ee4b2722f'])] // TODO: Verify against another impl.
    #[TestWith([OpenSubtitlesHasher::CHUNK_SIZE / 2, '62f885b94d071d9e'])] // TODO: Verify against another impl.
    public function test_simple_hash($length, $expected)
    {
        $engine = new PcgOneseq128XslRr64(hash('xxh128', self::RANDOM_SEED, true));
        $randomizer = new Randomizer($engine);

        $data = $randomizer->getBytes($length);

        $this->assertEquals($expected, $this->hash($data));
    }

    #[TestWith([0])]
    #[TestWith([5])]
    #[TestWith([OpenSubtitlesHasher::CHUNK_SIZE >> 1])]
    #[TestWith([OpenSubtitlesHasher::CHUNK_SIZE + (OpenSubtitlesHasher::CHUNK_SIZE >> 1)])]
    #[TestWith([OpenSubtitlesHasher::CHUNK_SIZE * 3])]
    public function test_files_with_nothing_in_them_get_the_blank_hash(int $length)
    {
        $this->assertSame(OpenSubtitlesHasher::BLANK_HASH, $this->hash(str_repeat("\0", $length)));
    }

    public function test_only_the_hashed_parts_of_a_file_are_checked_for_being_blank()
    {
        $zeros = str_repeat("\0", OpenSubtitlesHasher::CHUNK_SIZE);

        $this->assertSame(OpenSubtitlesHasher::BLANK_HASH, $this->hash($zeros.'something'.$zeros));
    }

    public function test_small_files_with_something_in_them_are_not_blank()
    {
        // Too short to have any whole words to sum, so the hash is just the size.
        $this->assertSame('0000000000000005', $this->hash('hello'));

        // The same goes for anything after the last whole word.
        $this->assertSame('000000000000000a', $this->hash(str_repeat("\0", 8).'hi'));
    }

    private function hash(string $data): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'oshash_input_');

        try {
            file_put_contents($temporaryPath, $data);

            return (new OpenSubtitlesHasher)->hash($temporaryPath);
        } finally {
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
