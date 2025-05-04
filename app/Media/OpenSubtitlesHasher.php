<?php

namespace App\Media;

use App\Utilities\Math;

readonly class OpenSubtitlesHasher
{
    public const int CHUNK_SIZE = 65536;

    public function hash(string $path): string
    {
        $stream = fopen($path, 'rb');

        $hash = $this->hashChunk($stream, self::CHUNK_SIZE);

        fseek($stream, -self::CHUNK_SIZE, SEEK_END);

        $hash = Math::addUnsignedLongLong($hash, $this->hashChunk($stream, self::CHUNK_SIZE));

        $size = ftell($stream);
        $hash = Math::addUnsignedLongLong($hash, [
            $size >> 32,
            $size & 0xFFFFFFFF,
        ]);

        return sprintf('%08x%08x', $hash[0], $hash[1]);
    }

    /**
     * @return array{int, int}
     */
    private function hashChunk($stream, int $length): array
    {
        $data = fread($stream, $length);
        if ($data === false) {
            throw new \RuntimeException('Unable to read chunk data');
        }

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
