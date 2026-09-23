<?php

declare(strict_types=1);

namespace App\Application\Shared;

/**
 * @template T
 */
final readonly class PaginatedResult
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @return array{items: list<T>, meta: array{page: int, per_page: int, total: int, total_pages: int}}
     */
    public function toArray(): array
    {
        $totalPages = $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 0;

        return [
            'items' => $this->items,
            'meta' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'total_pages' => $totalPages,
            ],
        ];
    }
}
