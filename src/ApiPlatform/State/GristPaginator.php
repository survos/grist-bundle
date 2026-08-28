<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform\State;

use ApiPlatform\State\Pagination\HasNextPagePaginatorInterface;
use ApiPlatform\State\Pagination\PaginatorInterface;
use Survos\RecordStore\Model\Record;
use Survos\RecordStore\Model\RecordPage;

/**
 * API Platform's paginator, over record-store's RecordPage.
 *
 * RecordPage already carries the page's records and the size of the whole set, which is
 * exactly what Hydra needs for `totalItems` and `view`. Rows are hydrated lazily: a
 * collection that is serialized with a narrow group still pays for every property, but a
 * collection that is never iterated -- a HEAD, a cache hit -- pays for none.
 *
 * `total` is required. GristAdapter leaves it null because it cannot know whether it saw the
 * whole table; GristProvider can, because it holds the whole table, and constructs the page
 * with a real count. A null total would silently become `totalItems: 0`, which reads as an
 * empty collection rather than as the missing information it is.
 *
 * @implements PaginatorInterface<object>
 */
final class GristPaginator implements \IteratorAggregate, PaginatorInterface, HasNextPagePaginatorInterface
{
    /** @param \Closure(Record): object $hydrate */
    public function __construct(
        private readonly RecordPage $page,
        private readonly float $currentPage,
        private readonly float $itemsPerPage,
        private readonly \Closure $hydrate,
    ) {
        if (null === $this->page->total) {
            throw new \LogicException('A GristPaginator needs a RecordPage with a total; without one the collection cannot report totalItems.');
        }
    }

    public function getIterator(): \Traversable
    {
        foreach ($this->page->records as $record) {
            yield ($this->hydrate)($record);
        }
    }

    public function count(): int
    {
        return count($this->page->records);
    }

    public function getCurrentPage(): float
    {
        return $this->currentPage;
    }

    public function getItemsPerPage(): float
    {
        return $this->itemsPerPage;
    }

    public function getTotalItems(): float
    {
        return (float) $this->page->total;
    }

    public function getLastPage(): float
    {
        if (0.0 >= $this->itemsPerPage) {
            return 1.0;
        }

        return max(ceil($this->getTotalItems() / $this->itemsPerPage), 1.0);
    }

    public function hasNextPage(): bool
    {
        return null !== $this->page->nextOffset;
    }
}
