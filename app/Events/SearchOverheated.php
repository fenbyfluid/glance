<?php

namespace App\Events;

use App\Models\User;

class SearchOverheated
{
    public function __construct(
        public string $model,
        public string $query,
        public User $user,
        public int $resultCount,
        public int $totalCount,
    ) {}
}
