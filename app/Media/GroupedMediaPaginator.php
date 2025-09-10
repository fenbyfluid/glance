<?php

namespace App\Media;

use Illuminate\Pagination\LengthAwarePaginator;

class GroupedMediaPaginator extends LengthAwarePaginator
{
    protected ?int $itemCount = null;

    public function count(): int
    {
        // We need to override the item count as the underlying container is an array of arrays of items.
        return $this->itemCount ?? $this->items->count();
    }

    public function path(): ?string
    {
        // We only use this for directories, so add back the trailing slash that AbstractPaginator stripped.
        return $this->path.'/';
    }
}
