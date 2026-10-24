<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A length-aware paginator whose page links are path segments (<base>/page/2), never ?page=:
 * page 1 is the base address itself. The page comes from the route ({page}, whereNumber).
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends LengthAwarePaginator<TKey, TValue>
 */
class PathPaginator extends LengthAwarePaginator
{
    public static function of(EloquentBuilder|QueryBuilder $query, int $perPage, int $page, string $base): self
    {
        $found = $query->paginate($perPage, ['*'], 'page', max(1, $page));

        return new self($found->getCollection(), $found->total(), $found->perPage(), $found->currentPage(), ['path' => rtrim($base, '/')]);
    }

    public function url($page): string
    {
        $page = max(1, (int) $page);

        return $this->path().($page > 1 ? '/page/'.$page : '').$this->buildFragment();
    }
}
