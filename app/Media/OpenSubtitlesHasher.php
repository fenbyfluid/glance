<?php

namespace App\Media;

use App\Utilities\Math;

readonly class OpenSubtitlesHasher
{
    public const int CHUNK_SIZE = 65536;

    private string $hash;

    public function __construct(
        private string $inputFile,
    ) {}

    public function hash(): string
    {
        if (isset($this->hash)) {
            return $this->hash;
        }

        $stream = fopen($this->inputFile, 'rb');

        $hash = $this->hashChunk($stream);

        fseek($stream, -self::CHUNK_SIZE, SEEK_END);

        $hash = Math::addUnsignedLongLong($hash, $this->hashChunk($stream));

        $size = ftell($stream);
        $hash = Math::addUnsignedLongLong($hash, [
            $size >> 32,
            $size & 0xFFFFFFFF,
        ]);

        $this->hash = sprintf('%08x%08x', $hash[0], $hash[1]);

        return $this->hash;
    }

    /**
     * @return array{int, int}
     */
    private function hashChunk($stream): array
    {
        $data = fread($stream, self::CHUNK_SIZE);
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
