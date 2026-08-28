<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use Survos\RecordStore\Contract\AdapterFactoryInterface;
use Survos\RecordStore\Contract\RecordStoreAdapterInterface;
use Survos\RecordStore\Model\ApplicationReference;
use Survos\RecordStore\Model\ApplicationSchema;
use Survos\RecordStore\Model\ConnectionConfiguration;
use Survos\RecordStore\Model\ProviderCapability;
use Survos\RecordStore\Model\Record;
use Survos\RecordStore\Model\RecordPage;
use Survos\RecordStore\Model\RecordQuery;
use Survos\RecordStore\Model\SortDirection;
use Survos\RecordStore\Model\TableReference;
use Survos\RecordStore\Model\UpsertRequest;
use Survos\RecordStore\Model\WriteResult;
use Survos\RecordStore\Registry\RecordStoreRegistry;

/**
 * A record-store adapter over arrays, standing in for the Grist REST API.
 *
 * It reproduces the parts of GristAdapter's behaviour the provider actually depends on --
 * server-side filtering and sorting, a hard `limit`, no offset, and a RecordPage with a null
 * total -- so the tests exercise the real provider rather than a mock of it.
 */
final class InMemoryGristStore implements RecordStoreAdapterInterface, AdapterFactoryInterface
{
    /** @var array<string, int> reads per Grist table id */
    public array $queries = [];

    /** @var list<array{TableReference, UpsertRequest}> */
    public array $writes = [];

    /** @param array<string, list<Record>> $tables keyed by Grist table id */
    public function __construct(private array $tables = [])
    {
    }

    /** @param array<string, list<array{int, array<string, mixed>}>> $rows */
    public static function withRows(array $rows): self
    {
        $tables = [];
        foreach ($rows as $table => $records) {
            $tables[$table] = array_map(static fn (array $r): Record => new Record($r[1], $r[0]), $records);
        }

        return new self($tables);
    }

    public function registry(): RecordStoreRegistry
    {
        return new RecordStoreRegistry(
            ['chijal' => ['driver' => 'grist']],
            ['chijal' => ['connection' => 'chijal', 'id' => 'doc1', 'tables' => [
                'artists' => ['id' => 'Artists'],
                'locations' => ['id' => 'Locations'],
                'obras' => ['id' => 'Obras'],
            ]]],
            [$this],
        );
    }

    public function supports(string $driver): bool
    {
        return 'grist' === $driver;
    }

    public function create(ConnectionConfiguration $connection): RecordStoreAdapterInterface
    {
        return $this;
    }

    public function provider(): string
    {
        return 'grist';
    }

    public function capabilities(): array
    {
        return [ProviderCapability::RecordRead, ProviderCapability::RecordUpsert];
    }

    public function schema(ApplicationReference $application): ApplicationSchema
    {
        return new ApplicationSchema($application->id, $application->name, []);
    }

    public function query(TableReference $table, RecordQuery $query): RecordPage
    {
        $this->queries[$table->id] = ($this->queries[$table->id] ?? 0) + 1;

        if (0 !== $query->offset) {
            throw new \LogicException('Grist has no offset; the provider must never ask for one.');
        }

        $records = $this->tables[$table->id] ?? [];
        foreach ($query->filters as $field => $allowed) {
            $records = array_values(array_filter(
                $records,
                static fn (Record $r): bool => in_array($r->fields[$field] ?? null, $allowed, false),
            ));
        }
        foreach (array_reverse($query->sorts) as $sort) {
            usort($records, static function (Record $a, Record $b) use ($sort): int {
                $c = ($a->fields[$sort->field] ?? null) <=> ($b->fields[$sort->field] ?? null);

                return SortDirection::Descending === $sort->direction ? -$c : $c;
            });
        }

        // Like GristAdapter: a page with no total, because the adapter cannot know whether it
        // saw the whole table.
        return new RecordPage(array_slice($records, 0, $query->limit));
    }

    public function upsert(TableReference $table, UpsertRequest $request): WriteResult
    {
        $this->writes[] = [$table, $request];

        return new WriteResult(affectedIds: [1]);
    }
}
