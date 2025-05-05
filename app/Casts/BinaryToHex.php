<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class BinaryToHex implements CastsAttributes
{
    public function __construct(
        private readonly int $byteLength = 0,
    ) {}

    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return str_pad(bin2hex($value), $this->byteLength * 2, '0', STR_PAD_LEFT);
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        return hex2bin($value);
    }

    public static function withLength(int $byteLength): string
    {
        return self::class.':'.$byteLength;
    }
}
