<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform\State;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Psr\Log\LoggerInterface;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadata;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\RecordStore\Model\Record;
use Survos\RecordStore\Model\RecordQuery;
use Survos\RecordStore\Model\RecordSort;
use Survos\RecordStore\Model\SortDirection;
use Survos\RecordStore\Model\TableReference;
use Survos\RecordStore\Registry\RecordStoreRegistry;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The one place a Grist table is read, and the one place the result is cached.
 *
 * It reads the *whole* table (subject to the resource's declared `where`) and caches that,
 * rather than issuing a query per request. Grist is a live third-party dependency sitting on
 * the request path; a filter-shaped cache key would mean a fresh round-trip for every
 * distinct combination of query parameters, which is strictly worse for a hand-curated table
 * of a few dozen rows. Caller-supplied filtering, sorting and pagination happen in PHP
 * against this set -- see GristProvider.
 *
 * The assumption -- that a table fits comfortably in memory -- is checked, not hoped for.
 * `maxRows` is a refusal, not a truncation: a table that outgrows it should move behind a
 * Grist view or the SQL endpoint, and finding that out from an exception beats finding it out
 * from a collection that quietly stops at row 5000.
 */
final readonly class GristRecordFetcher
{
    public function __construct(
        private RecordStoreRegistry $registry,
        private GristResourceMetadataFactory $factory,
        private CacheInterface $cache,
        private int $defaultTtl = 900,
        private int $defaultMaxRows = 5000,
        private ?LoggerInterface $logger = null,
        private ?ResourceMetadataCollectionFactoryInterface $resourceMetadata = null,
    ) {
    }

    /**
     * Every row of the resource's table, narrowed by a declared `where`.
     *
     * @param array<string, list<bool|float|int|string|null>>|null $where null uses the resource's own;
     *                                                                   [] deliberately narrows nothing
     *
     * @return list<Record>
     */
    public function rows(GristResourceMetadata $metadata, ?array $where = null): array
    {
        $where ??= $metadata->grist->where;

        return $this->cache->get(
            $metadata->cacheKey('rows', $where),
            function (ItemInterface $item) use ($metadata, $where): array {
                $item->expiresAfter($metadata->grist->cacheTtl ?? $this->defaultTtl);

                return $this->fetch($metadata, $where);
            },
        );
    }

    /**
     * Grist row id => natural key, for resolving Ref and RefList columns.
     *
     * Deliberately ignores the resource's `where`. A closed venue is still the venue an obra
     * hangs in, and resolving that reference to an empty string because the venue is not
     * *published* would corrupt the record rather than hide it. `where` decides what is
     * listed, not what a reference is allowed to name.
     *
     * @return array<int|string, string>
     */
    public function identifierMap(GristResourceMetadata $metadata): array
    {
        return $this->cache->get(
            $metadata->cacheKey('ids'),
            function (ItemInterface $item) use ($metadata): array {
                $item->expiresAfter($metadata->grist->cacheTtl ?? $this->defaultTtl);

                $map = [];
                foreach ($this->fetch($metadata, []) as $record) {
                    if (null !== $record->id) {
                        $map[$record->id] = (string) $record->fields[$metadata->identifier->column];
                    }
                }

                return $map;
            },
        );
    }

    /**
     * Forget every cached read of a resource's table.
     *
     * An operation that overrides `where` has its own cache entry, so dropping only the
     * resource's default entry would leave the admin view showing the row as it was before
     * the write -- the exact confusion the invalidation exists to prevent. The overrides are
     * discovered from the resource's own operations rather than passed in, because a caller
     * that has to remember them is a caller that will eventually forget one.
     *
     * @param class-string $resourceClass
     */
    public function invalidate(string $resourceClass): void
    {
        $metadata = $this->factory->create($resourceClass);
        $this->cache->delete($metadata->cacheKey('ids'));

        $wheres = [$metadata->grist->where];
        foreach ($this->resourceMetadata?->create($resourceClass) ?? [] as $resource) {
            foreach ($resource->getOperations() ?? [] as $operation) {
                $where = $operation->getExtraProperties()['grist_where'] ?? null;
                if (is_array($where)) {
                    $wheres[] = $where;
                }
            }
        }

        foreach ($wheres as $where) {
            $this->cache->delete($metadata->cacheKey('rows', $where));
        }
    }

    public function table(GristResourceMetadata $metadata): TableReference
    {
        return $this->registry->application($metadata->grist->application)->table($metadata->grist->table);
    }

    /**
     * @param array<string, list<bool|float|int|string|null>> $where
     *
     * @return list<Record>
     */
    private function fetch(GristResourceMetadata $metadata, array $where): array
    {
        $table = $this->table($metadata);
        $maxRows = $metadata->grist->maxRows ?? $this->defaultMaxRows;

        // One over the ceiling, so a full page is distinguishable from a truncated one.
        $page = $this->registry->adapterFor($table)->query($table, new RecordQuery(
            filters: $where,
            sorts: self::sorts($metadata->grist->order),
            limit: $maxRows + 1,
        ));

        if (count($page->records) > $maxRows) {
            throw new \OverflowException(sprintf(
                'Grist table "%s.%s" holds more than %d rows, which is more than the API Platform provider reads into memory. '
                .'Narrow it with a #[GristResource] `where`, put it behind a Grist view, or query it through GristQueryRunner instead of raising maxRows.',
                $metadata->grist->application,
                $metadata->grist->table,
                $maxRows,
            ));
        }

        $records = [];
        $keyless = [];
        foreach ($page->records as $record) {
            $key = $record->fields[$metadata->identifier->column] ?? null;
            if (is_scalar($key) && '' !== trim((string) $key)) {
                $records[] = $record;
                continue;
            }
            $keyless[] = $record->id;
        }

        // A row with no natural key has no URI, so it cannot be a member of the collection --
        // API Platform would fail to build an IRI for it and take the whole response down. In
        // a curation grid a half-entered row is a normal state, not a broken pipeline, so it
        // is dropped rather than fatal. Logged, though: silently short collections are how a
        // typo'd key column turns into "why is that venue missing from the app".
        if ([] !== $keyless) {
            $this->logger?->warning('Grist rows dropped: no value in the identifier column', [
                'application' => $metadata->grist->application,
                'table' => $metadata->grist->table,
                'column' => $metadata->identifier->column,
                'rowIds' => $keyless,
            ]);
        }

        $this->logger?->debug('Grist table read for API Platform', [
            'application' => $metadata->grist->application,
            'table' => $metadata->grist->table,
            'rows' => count($records),
            'dropped' => count($keyless),
            'where' => $where,
        ]);

        return $records;
    }

    /**
     * @param list<string> $order
     *
     * @return list<RecordSort>
     */
    private static function sorts(array $order): array
    {
        $sorts = [];
        foreach ($order as $column) {
            $descending = str_starts_with($column, '-');
            $sorts[] = new RecordSort(
                ltrim($column, '-'),
                $descending ? SortDirection::Descending : SortDirection::Ascending,
            );
        }

        return $sorts;
    }
}
