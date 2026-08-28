<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use ApiPlatform\State\Pagination\PaginatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Survos\GristBundle\ApiPlatform\State\GristPaginator;
use Survos\RecordStore\Model\Record;
use Survos\RecordStore\Model\RecordPage;

#[CoversClass(GristPaginator::class)]
final class GristPaginatorTest extends TestCase
{
    public function testItReportsTheWholeSetNotThePage(): void
    {
        $paginator = self::paginator(records: 4, total: 41, page: 2, perPage: 4);

        self::assertInstanceOf(PaginatorInterface::class, $paginator);
        self::assertSame(41.0, $paginator->getTotalItems());
        self::assertSame(4, $paginator->count());
        self::assertSame(11.0, $paginator->getLastPage());
        self::assertSame(2.0, $paginator->getCurrentPage());
    }

    public function testANullTotalIsRefusedRatherThanServedAsZero(): void
    {
        // GristAdapter returns a RecordPage with no total, because it cannot know whether it
        // saw the whole table. Letting that through would serialize as totalItems: 0, which
        // reads as an empty collection rather than as the missing information it is.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/needs a RecordPage with a total/');

        new GristPaginator(new RecordPage([]), 1.0, 30.0, static fn (Record $r): object => new \stdClass());
    }

    public function testHydrationIsLazy(): void
    {
        $hydrated = 0;
        $paginator = new GristPaginator(
            new RecordPage([new Record(['Code' => 'a'], 1), new Record(['Code' => 'b'], 2)], total: 2),
            1.0,
            30.0,
            function (Record $record) use (&$hydrated): object {
                ++$hydrated;

                return (object) $record->fields;
            },
        );

        self::assertSame(0, $hydrated, 'constructing a paginator must not hydrate anything');
        self::assertCount(2, iterator_to_array($paginator));
        self::assertSame(2, $hydrated);
    }

    public function testAnEmptyLastPageStillHasOnePage(): void
    {
        self::assertSame(1.0, self::paginator(records: 0, total: 0, page: 1, perPage: 30)->getLastPage());
    }

    public function testHasNextPageFollowsTheRecordPagesOwnNextOffset(): void
    {
        self::assertTrue(self::paginator(4, 41, 2, 4, nextOffset: 8)->hasNextPage());
        self::assertFalse(self::paginator(1, 41, 11, 4, nextOffset: null)->hasNextPage());
    }

    private static function paginator(int $records, int $total, int $page, int $perPage, ?int $nextOffset = null): GristPaginator
    {
        return new GristPaginator(
            new RecordPage(array_map(static fn (int $i): Record => new Record(["Code" => "c$i"], $i), $records < 1 ? [] : range(1, $records)), $total, $nextOffset),
            (float) $page,
            (float) $perPage,
            static fn (Record $record): object => (object) $record->fields,
        );
    }
}
