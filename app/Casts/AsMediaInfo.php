<?php

namespace App\Casts;

use App\Media\MediaInfo;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class AsMediaInfo implements CastsAttributes
{
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

        $parsed = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return MediaInfo::fromArray($parsed);
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

        if (!($value instanceof MediaInfo)) {
            throw new \InvalidArgumentException('Value must be an instance of '.MediaInfo::class);
        }

        $array = $value->toArray();

        return json_encode($array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
