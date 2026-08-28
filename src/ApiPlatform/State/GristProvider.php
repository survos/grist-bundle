<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use Survos\GristBundle\ApiPlatform\Metadata\GristPropertyMetadata;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadata;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\RecordStore\Model\Record;
use Survos\RecordStore\Model\RecordPage;

/**
 * Reads Grist rows as API Platform resources.
 *
 *     #[ApiResource(provider: GristProvider::class, processor: GristProcessor::class)]
 *
 * Item and collection are one class because they read the same cached set: an item lookup is
 * a search of rows already in memory, not a second request to Grist.
 *
 * @implements ProviderInterface<object>
 */
final readonly class GristProvider implements ProviderInterface
{
    public function __construct(
        private GristResourceMetadataFactory $factory,
        private GristRecordFetcher $fetcher,
        private GristHydrator $hydrator,
        private Pagination $pagination,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        /** @var class-string $resourceClass */
        $resourceClass = $operation->getClass() ?? throw new \LogicException('The Grist provider needs an operation bound to a class.');
        $metadata = $this->factory->create($resourceClass);
        $records = $this->fetcher->rows($metadata, self::operationWhere($operation));

        if (!$operation instanceof CollectionOperationInterface) {
            $record = $this->find($metadata, $records, $uriVariables);

            return null === $record ? null : $this->hydrator->hydrate($metadata, $record);
        }

        $filters = $context['filters'] ?? [];
        $records = $this->filter($metadata, $records, is_array($filters) ? $filters : []);
        $records = self::order($metadata, $records, is_array($filters['order'] ?? null) ? $filters['order'] : []);

        if (!$this->pagination->isEnabled($operation, $context)) {
            return array_map(fn (Record $record): object => $this->hydrator->hydrate($metadata, $record), $records);
        }

        $total = count($records);
        [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);
        $window = array_slice($records, $offset, $limit);

        return new GristPaginator(
            new RecordPage(
                records: $window,
                total: $total,
                nextOffset: $offset + $limit < $total ? $offset + $limit : null,
            ),
            (float) $page,
            (float) $limit,
            fn (Record $record): object => $this->hydrator->hydrate($metadata, $record),
        );
    }

    /**
     * An operation's override of the resource's declared `where`.
     *
     *     new Get(uriTemplate: '/admin/locations/{code}', extraProperties: ['grist_where' => []])
     *
     * The resource-level rule is the publication rule and the right default. But it is a rule
     * about what is *published*, and an admin operation is not publishing -- a curator whose
     * job is to reopen a closed venue has to be able to see it. Expressing that as `[]` on the
     * one operation keeps the default closed: an operation that says nothing gets the
     * resource's `where`, so forgetting to think about it is safe.
     *
     * @return array<string, list<bool|float|int|string|null>>|null
     */
    private static function operationWhere(Operation $operation): ?array
    {
        $where = $operation->getExtraProperties()['grist_where'] ?? null;

        return is_array($where) ? $where : null;
    }

    /**
     * The row whose identifier column holds the URI's natural key.
     *
     * @param list<Record> $records
     * @param array<string, mixed> $uriVariables
     */
    private function find(GristResourceMetadata $metadata, array $records, array $uriVariables): ?Record
    {
        $value = $uriVariables[$metadata->grist->identifier] ?? (1 === count($uriVariables) ? reset($uriVariables) : null);
        if (null === $value) {
            return null;
        }

        foreach ($records as $record) {
            if ((string) $value === (string) ($record->fields[$metadata->identifier->column] ?? '')) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Caller-supplied filtering, in PHP, against the cached set.
     *
     * Only properties marked `filterable` participate; an unknown or unmarked parameter is
     * ignored rather than mistaken for a column, so a typo cannot widen a collection.
     *
     * @param list<Record> $records
     * @param array<array-key, mixed> $filters a query string yields int keys for numeric names
     *
     * @return list<Record>
     */
    private function filter(GristResourceMetadata $metadata, array $records, array $filters): array
    {
        foreach ($filters as $name => $criterion) {
            $property = $metadata->property(is_string($name) ? $name : '');
            if (null === $property || !$property->filterable) {
                continue;
            }

            $allowed = array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                is_array($criterion) ? array_values($criterion) : [$criterion],
            );

            // A reference column holds a row id, but the caller filters with the natural key
            // that everything else in this API speaks -- ?locationCode=libjo, not =5. Translate
            // the values once, rather than dereferencing every row to compare it.
            if (null !== $property->references) {
                $allowed = $this->rowIds($property->references, $allowed);
            }

            $records = array_values(array_filter(
                $records,
                static fn (Record $record): bool => in_array(
                    (string) (is_scalar($record->fields[$property->column] ?? null) ? $record->fields[$property->column] : ''),
                    $allowed,
                    true,
                ),
            ));
        }

        return $records;
    }

    /**
     * Natural keys -> the row ids a Ref column actually stores.
     *
     * A key nobody has keeps its own string, which matches nothing -- an unknown value
     * filters everything out, rather than being dropped and silently widening the result.
     *
     * @param class-string $references
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function rowIds(string $references, array $keys): array
    {
        $rowIds = array_flip($this->fetcher->identifierMap($this->factory->create($references)));

        return array_map(static fn (string $key): string => (string) ($rowIds[$key] ?? $key), $keys);
    }

    /**
     * @param list<Record> $records
     * @param array<array-key, mixed> $order
     *
     * @return list<Record>
     */
    private static function order(GristResourceMetadata $metadata, array $records, array $order): array
    {
        foreach (array_reverse($order, true) as $name => $direction) {
            $property = $metadata->property(is_string($name) ? $name : '');
            if (null === $property || !$property->sortable) {
                continue;
            }

            $descending = 'desc' === strtolower(is_string($direction) ? $direction : 'asc');
            usort($records, static function (Record $a, Record $b) use ($property, $descending): int {
                $comparison = self::compare($property, $a, $b);

                return $descending ? -$comparison : $comparison;
            });
        }

        return $records;
    }

    private static function compare(GristPropertyMetadata $property, Record $a, Record $b): int
    {
        $left = $a->fields[$property->column] ?? null;
        $right = $b->fields[$property->column] ?? null;

        if (is_numeric($left) && is_numeric($right)) {
            return $left <=> $right;
        }

        return strcasecmp(
            is_scalar($left) ? (string) $left : '',
            is_scalar($right) ? (string) $right : '',
        );
    }
}
