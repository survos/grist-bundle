<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\GristBundle\ApiPlatform\State\GristHydrator;
use Survos\GristBundle\ApiPlatform\State\GristPaginator;
use Survos\GristBundle\ApiPlatform\State\GristProcessor;
use Survos\GristBundle\ApiPlatform\State\GristProvider;
use Survos\GristBundle\ApiPlatform\State\GristRecordFetcher;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(GristProvider::class)]
#[CoversClass(GristProcessor::class)]
#[CoversClass(GristRecordFetcher::class)]
final class GristProviderTest extends TestCase
{
    private InMemoryGristStore $store;
    private GristProvider $provider;
    private GristProcessor $processor;
    private GristRecordFetcher $fetcher;

    protected function setUp(): void
    {
        $this->store = InMemoryGristStore::withRows([
            'Obras' => [
                [3, ['Code' => 'el2', 'Title' => 'Uno']],
                [7, ['Code' => 'vic1', 'Title' => 'Dos']],
            ],
            'Locations' => [
                [1, ['Code' => 'bioes', 'Name' => 'Espiral', 'Status' => 'activo', 'Barrio' => 'San Ramon', 'Obras' => ['L', 3]]],
                [2, ['Code' => 'cora', 'Name' => 'Mucho Corazon', 'Status' => 'inactivo', 'Barrio' => 'Centro']],
                [3, ['Code' => 'laez', 'Name' => 'La Ensenanza', 'Status' => 'activo', 'Barrio' => 'Cerrillo', 'Featured' => 7]],
                [4, ['Code' => '', 'Name' => 'Half-entered', 'Status' => 'activo']],
                [5, ['Code' => 'chijal', 'Name' => 'Oficina', 'Status' => 'activo', 'Barrio' => 'Cerrillo']],
            ],
        ]);

        $factory = new GristResourceMetadataFactory();
        $registry = $this->store->registry();
        $this->fetcher = new GristRecordFetcher($registry, $factory, new ArrayAdapter());
        $hydrator = new GristHydrator($factory, $this->fetcher);
        $this->provider = new GristProvider($factory, $this->fetcher, $hydrator, new Pagination());
        $this->processor = new GristProcessor($registry, $factory, $this->fetcher, $hydrator);
    }

    public function testTheDeclaredWhereIsNotSomethingACallerCanTurnOff(): void
    {
        $codes = $this->codes($this->collection());

        self::assertNotContains('cora', $codes, 'an inactivo venue must never reach a public collection');
        // Espiral, La Ensenanza, Oficina -- the declared order: ['Name'], pushed down to Grist.
        self::assertSame(['bioes', 'laez', 'chijal'], $codes);
    }

    public function testAnOperationCanOptOutOfThePublicationRule(): void
    {
        $admin = new GetCollection(class: FixtureLocation::class, extraProperties: ['grist_where' => []]);

        self::assertContains('cora', $this->codes($this->provider->provide($admin, [], ['filters' => []])));
    }

    public function testARowWithNoNaturalKeyIsDroppedRatherThanServedWithoutAUri(): void
    {
        self::assertNotContains('', $this->codes($this->collection()));
        self::assertCount(3, iterator_to_array($this->collection()));
    }

    public function testAnItemIsFoundByItsNaturalKey(): void
    {
        $item = $this->provider->provide(new Get(class: FixtureLocation::class), ['code' => 'bioes']);

        self::assertInstanceOf(FixtureLocation::class, $item);
        self::assertSame('Espiral', $item->label);
        // A row id is not an identifier, so it must not resolve to anything.
        self::assertNull($this->provider->provide(new Get(class: FixtureLocation::class), ['code' => '1']));
        // Neither does a row the publication rule excludes.
        self::assertNull($this->provider->provide(new Get(class: FixtureLocation::class), ['code' => 'cora']));
    }

    public function testOnlyDeclaredFilterablePropertiesFilter(): void
    {
        self::assertSame(['laez', 'chijal'], $this->codes($this->collection(['barrio' => 'Cerrillo'])));
        // `label` is not marked filterable: honouring it would let an undeclared parameter
        // reshape the collection, and ignoring it means a typo cannot widen one either.
        self::assertCount(3, iterator_to_array($this->collection(['label' => 'no such venue'])));
    }

    public function testAReferenceFiltersByNaturalKeyNotRowId(): void
    {
        self::assertSame(['laez'], $this->codes($this->collection(['featured' => 'vic1'])));
        // An unknown key matches nothing, rather than being dropped and widening the result.
        self::assertSame([], $this->codes($this->collection(['featured' => 'no-such-obra'])));
    }

    public function testTheCollectionPaginatesOverTheWholeSet(): void
    {
        $page = $this->provider->provide(
            new GetCollection(class: FixtureLocation::class, paginationClientItemsPerPage: true),
            [],
            ['filters' => ['itemsPerPage' => 2, 'page' => 2]],
        );

        self::assertInstanceOf(GristPaginator::class, $page);
        self::assertSame(3.0, $page->getTotalItems());
        self::assertCount(1, iterator_to_array($page));
    }

    public function testOneTableIsReadOncePerCacheEntryHoweverManyRequestsArrive(): void
    {
        $this->store->queries = [];
        $this->codes($this->collection());
        $this->codes($this->collection(['barrio' => 'Cerrillo']));
        $this->provider->provide(new Get(class: FixtureLocation::class), ['code' => 'bioes']);

        // Filters and item lookups are served from the cached set, not by re-querying Grist.
        // Obras is read once too -- resolving references needs its natural keys, and that map
        // is cached on the same terms.
        self::assertSame(['Locations' => 1, 'Obras' => 1], $this->store->queries);
    }

    public function testAWriteGoesThroughAsAnUpsertOnTheNaturalKeyAndDropsTheCachedRead(): void
    {
        $this->codes($this->collection());
        $this->store->queries = [];

        $location = $this->provider->provide(new Get(class: FixtureLocation::class), ['code' => 'bioes']);
        $location->label = 'Espiral II';
        $this->processor->process($location, new \ApiPlatform\Metadata\Patch(class: FixtureLocation::class));

        [$table, $request] = $this->store->writes[0];
        self::assertSame('Locations', $table->id);
        self::assertSame(['Code'], $request->keyFields, 'rows are matched on the natural key, never a row id');
        self::assertSame('Espiral II', $request->records[0]->fields['Name']);

        // The next read must not be the pre-write cache entry.
        $this->codes($this->collection());
        self::assertSame(1, $this->store->queries['Locations'] ?? 0);
    }

    public function testDeletingIsRefusedRatherThanQuietlyIgnored(): void
    {
        $this->expectExceptionMessageMatches('/rows are retired in Grist/');

        $this->processor->process(
            new FixtureLocation(),
            new \ApiPlatform\Metadata\Delete(class: FixtureLocation::class),
        );
    }

    public function testAWriteWithNoNaturalKeyIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/needs a "code" before it can be written/');

        $this->processor->process(new FixtureLocation(), new \ApiPlatform\Metadata\Patch(class: FixtureLocation::class));
    }

    public function testATableBiggerThanMaxRowsIsRefusedNotTruncated(): void
    {
        $rows = [];
        for ($i = 1; $i <= 5; ++$i) {
            $rows[] = [$i, ['Code' => "c$i", 'Status' => 'activo']];
        }
        $store = InMemoryGristStore::withRows(['Locations' => $rows]);
        $factory = new GristResourceMetadataFactory();
        $fetcher = new GristRecordFetcher($store->registry(), $factory, new ArrayAdapter(), defaultMaxRows: 3);

        $this->expectException(\OverflowException::class);
        $this->expectExceptionMessageMatches('/more than 3 rows/');

        $fetcher->rows($factory->create(FixtureLocation::class));
    }

    /** @param array<string, mixed> $filters */
    private function collection(array $filters = [], array $context = []): iterable
    {
        return $this->provider->provide(
            new GetCollection(class: FixtureLocation::class),
            [],
            ['filters' => $filters, ...$context],
        );
    }

    /** @return list<string> */
    private function codes(iterable $items): array
    {
        return array_map(static fn (FixtureLocation $l): string => $l->code, iterator_to_array($items, false));
    }
}
