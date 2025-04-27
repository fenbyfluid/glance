<?php

namespace Tests\Unit\Media;

use App\Media\OpenSubtitlesHasher;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Random\Engine\PcgOneseq128XslRr64;
use Random\Randomizer;

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

        $temporaryPath = tempnam(sys_get_temp_dir(), 'oshash_input_');

        try {
            file_put_contents($temporaryPath, $data);

            $hasher = new OpenSubtitlesHasher($temporaryPath);

            $this->assertEquals($expected, $hasher->hash());
        } finally {
            if (file_exists($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
