<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\Grist\Service\GristApplicationLocator;
use Survos\Grist\Service\GristQueryRunner;

/**
 * The guard is exercised without a locator on purpose: it must reject a bad
 * statement BEFORE resolving an application, so a typo never costs a round-trip
 * and the error names the statement rather than a connection failure.
 */
#[CoversClass(GristQueryRunner::class)]
final class GristQueryRunnerTest extends TestCase
{
    private function runner(): GristQueryRunner
    {
        // The locator is final readonly, so it cannot be doubled -- and it does not
        // need to be. Validation runs before the locator is touched, so an
        // uninitialised instance is enough and any use of it would surface as an
        // error rather than passing silently.
        $locator = (new \ReflectionClass(GristApplicationLocator::class))->newInstanceWithoutConstructor();

        return new GristQueryRunner($locator);
    }

    #[DataProvider('writeStatements')]
    public function testNonSelectStatementsAreRejected(string $sql): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Only SELECT statements/');
        $this->runner()->sql('pgsc', $sql);
    }

    /** @return iterable<string,array{string}> */
    public static function writeStatements(): iterable
    {
        yield 'delete' => ['delete from Obras'];
        yield 'update' => ['update Obras set Title = ""'];
        yield 'insert' => ['insert into Obras (Code) values ("x")'];
        yield 'drop' => ['drop table Obras'];
        yield 'pragma' => ['pragma table_info(Obras)'];
        yield 'attach' => ['attach database "x.db" as x'];
        yield 'empty' => ['   '];
        yield 'hidden behind a line comment' => ["-- select something\ndelete from Obras"];
        yield 'hidden behind a block comment' => ['/* select */ delete from Obras'];
    }

    #[DataProvider('readStatements')]
    public function testSelectStatementsPassValidation(string $sql): void
    {
        // Validation happens first, so reaching the stubbed locator proves the
        // statement was accepted. Any other exception would fail the test.
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/^(?!.*Only SELECT statements).*$/s');
        $this->runner()->sql('pgsc', $sql);
    }

    /** @return iterable<string,array{string}> */
    public static function readStatements(): iterable
    {
        yield 'plain select' => ['select * from Obras'];
        yield 'leading whitespace' => ["\n   select 1"];
        yield 'uppercase' => ['SELECT Code FROM Obras'];
        yield 'cte' => ['with recent as (select * from Obras) select * from recent'];
        yield 'join, the reason this exists' => [
            'select o.Code, a.Name from Obras o left join Artists a on a.id = o.Artist',
        ];
        yield 'comment then select' => ["-- labels for one exhibition\nselect * from Obras"];
    }

    public function testErrorMessageQuotesTheOffendingStatement(): void
    {
        try {
            $this->runner()->sql('pgsc', 'delete from Obras where Code = "av2"');
            self::fail('Expected the guard to reject a delete.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('delete from Obras', $e->getMessage());
        }
    }

    public function testLongStatementsAreTruncatedInTheError(): void
    {
        try {
            $this->runner()->sql('pgsc', 'delete from '.str_repeat('VeryLongTableName', 20));
            self::fail('Expected the guard to reject a delete.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringEndsWith('...', $e->getMessage());
            self::assertLessThan(200, mb_strlen($e->getMessage()));
        }
    }
}
