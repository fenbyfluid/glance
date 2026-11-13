<?php

namespace App\Utilities;

use Illuminate\Database\Eloquent\Builder;

trait ScopeHavingAccess
{
    public function scopeHavingAccess(Builder $query, array $accessControlInfo): void
    {
        $hasAccessToPath = $accessControlInfo[''];
        unset($accessControlInfo['']);

        if ($hasAccessToPath) {
            $query->whereNotIn('name', array_map(strval(...), array_keys(array_filter($accessControlInfo, fn ($access) => !$access))));
        } else {
            $query->whereIn('name', array_map(strval(...), array_keys(array_filter($accessControlInfo, fn ($access) => $access))));
        }
    }
}
