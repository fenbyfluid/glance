<?php

namespace App\Exceptions;

class ImageExtractionIncompleteException extends \Exception
{
    public function __construct(
        public readonly int $nextOffset,
        public readonly array $boxesSoFar,
    ) {
        parent::__construct();
    }

    /**
     * @return array{offset: int, boxes: array}
     */
    public function getStateForNextJob(): array
    {
        return [
            'offset' => $this->nextOffset,
            'boxes' => $this->boxesSoFar,
        ];
    }
}
