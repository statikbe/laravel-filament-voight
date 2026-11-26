<?php

namespace Statikbe\FilamentVoight\Services\Mcp;

use Illuminate\Support\Collection;

/**
 * One page of a list tool's results: `limit` rows starting at `offset`. Callers count the total
 * matching rows themselves, in SQL or in PHP, and pass it to meta() for the paging fields.
 *
 * @phpstan-type PageMeta array{total: int, truncated: bool, next_offset: int|null}
 */
final readonly class Page
{
    public function __construct(
        public int $offset,
        public int $limit,
    ) {}

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  Collection<TKey, TValue>  $rows
     * @return Collection<int, TValue>
     */
    public function slice(Collection $rows): Collection
    {
        return $rows->slice($this->offset, $this->limit)->values();
    }

    /**
     * `truncated` means more rows follow this page; `next_offset` is where they start.
     *
     * @return PageMeta
     */
    public function meta(int $total): array
    {
        $nextOffset = $this->offset + $this->limit;
        $hasMore = $nextOffset < $total;

        return [
            'total' => $total,
            'truncated' => $hasMore,
            'next_offset' => $hasMore ? $nextOffset : null,
        ];
    }
}
