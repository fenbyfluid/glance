<?php

namespace App\Utilities;

use Illuminate\Contracts\Support\Arrayable;

trait ArrayableWithoutNullValues
{
    public function toArray(): array
    {
        return array_filter(array_map(function ($v) {
            if (is_array($v)) {
                return array_map(function ($v) {
                    if ($v instanceof Arrayable) {
                        return $v->toArray();
                    } else {
                        return $v;
                    }
                }, $v);
            } elseif ($v instanceof Arrayable) {
                return $v->toArray();
            } elseif ($v instanceof \BackedEnum) {
                return $v->value;
            } else {
                return $v;
            }
        }, (array) $this), fn ($v) => $v !== null);
    }
}
