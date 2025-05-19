<?php

namespace App\Utilities;

use Illuminate\Contracts\Support\Arrayable;

readonly class Fraction implements Arrayable
{
    public function __construct(
        public int $n,
        public int $d,
    ) {}

    public function toFloat(int $precision = -1): float
    {
        $float = $this->n / $this->d;

        if ($precision >= 0) {
            $float = round($float, $precision);
        }

        return $float;
    }

    public function toArray(): array
    {
        return [
            'n' => $this->n,
            'd' => $this->d,
        ];
    }

    public static function fromArray(self|array|null $data): ?self
    {
        if ($data === null) {
            return null;
        }

        if ($data instanceof self) {
            return $data;
        }

        return new self($data['n'], $data['d']);
    }

    public static function fromString(string $data): ?self
    {
        [$n, $d] = explode('/', $data, 2);
        if ($d == 0) {
            return null;
        }

        return new self($n, $d);
    }
}
