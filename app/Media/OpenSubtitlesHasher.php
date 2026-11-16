<?php

namespace App\Media;

use App\Utilities\Math;

readonly class OpenSubtitlesHasher
{
    public const int CHUNK_SIZE = 65536;

    /**
     * The hash we give to files with nothing in them, see hash().
     */
    public const string BLANK_HASH = '0000000000000000';

    public function hash(string $path): string
    {
        $stream = fopen($path, 'rb');

        $head = $this->readChunk($stream, self::CHUNK_SIZE);

        fseek($stream, -self::CHUNK_SIZE, SEEK_END);

        $tail = $this->readChunk($stream, self::CHUNK_SIZE);

        // This is where we deliberately differ from the real algorithm, which would give a file of nothing but
        // zeros its size as its hash. Those files (and empty ones) are of no use to anyone and not worth looking
        // up anywhere, so they all get the one hash, which lets everything else recognise and ignore them.
        // We only see what we've read, so this includes files with something between a zeroed start and end.
        if (trim($head.$tail, "\0") === '') {
            return self::BLANK_HASH;
        }

        $hash = Math::addUnsignedLongLong($this->hashChunk($head), $this->hashChunk($tail));

        $size = ftell($stream);
        $hash = Math::addUnsignedLongLong($hash, [
            $size >> 32,
            $size & 0xFFFFFFFF,
        ]);

        return sprintf('%08x%08x', $hash[0], $hash[1]);
    }

    private function readChunk($stream, int $length): string
    {
        $data = fread($stream, $length);
        if ($data === false) {
            throw new \RuntimeException('Unable to read chunk data');
        }

        return $data;
    }

    /**
     * @return array{int, int}
     */
    private function hashChunk(string $data): array
    {
        $sum = [0, 0];

        $offset = 0;
        while (($offset + 8) <= strlen($data)) {
            $bytes = unpack('V2', $data, $offset);
            $offset += 8;

            $sum = Math::addUnsignedLongLong($sum, [$bytes[2], $bytes[1]]);
        }

        return $sum;
    }
}
