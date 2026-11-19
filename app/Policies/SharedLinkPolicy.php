<?php

namespace App\Policies;

use App\Models\SharedLink;
use App\Models\User;

class SharedLinkPolicy
{
    public function create(User $user): bool
    {
        return $user->can_share;
    }

    public function update(User $user, SharedLink $sharedLink): bool
    {
        return $user->is_admin || $sharedLink->user_id === $user->getKey();
    }

    public function delete(User $user, SharedLink $sharedLink): bool
    {
        return $user->is_admin || $sharedLink->user_id === $user->getKey();
    }
}
