<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\GristBundle\Model\WebhookBlueprint;

#[CoversClass(WebhookBlueprint::class)]
final class WebhookBlueprintTest extends TestCase
{
    private static function blueprint(mixed ...$overrides): WebhookBlueprint
    {
        return new WebhookBlueprint(...[
            'application' => 'pgsc',
            'table' => 'Obras',
            'name' => 'enrich',
            'url' => 'https://example.org/hook',
            ...$overrides,
        ]);
    }

    public function testFieldsUseGristsRestContract(): void
    {
        $fields = self::blueprint(
            eventTypes: ['update'],
            watchedColIds: ['YoutubeUrl', 'Photo'],
            isReadyColumn: 'Ready',
            memo: 'transcribe + push to object storage',
        )->toFields();

        self::assertSame([
            'url' => 'https://example.org/hook',
            'eventTypes' => ['update'],
            'tableId' => 'Obras',
            'enabled' => true,
            'name' => 'enrich',
            'watchedColIds' => ['YoutubeUrl', 'Photo'],
            'isReadyColumn' => 'Ready',
            'memo' => 'transcribe + push to object storage',
        ], $fields);
    }

    public function testOptionalGuardsAreOmittedRatherThanSentAsNull(): void
    {
        $fields = self::blueprint()->toFields();

        self::assertArrayNotHasKey('watchedColIds', $fields);
        self::assertArrayNotHasKey('isReadyColumn', $fields);
        self::assertArrayNotHasKey('memo', $fields);
        self::assertArrayNotHasKey('authorization', $fields);
    }

    /** Grist only fires on add and update; a 'delete' hook would silently never run. */
    public function testUnsupportedEventTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported event types: delete/');
        self::blueprint(eventTypes: ['update', 'delete']);
    }

    public function testEmptyEventTypesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::blueprint(eventTypes: []);
    }

    public function testDuplicateWatchedColumnsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::blueprint(watchedColIds: ['Photo', 'Photo']);
    }

    #[DataProvider('badUrls')]
    public function testUrlMustBeHttp(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::blueprint(url: $url);
    }

    /** @return iterable<string,array{string}> */
    public static function badUrls(): iterable
    {
        yield 'scheme-less' => ['example.org/hook'];
        yield 'ftp' => ['ftp://example.org/hook'];
        yield 'empty' => [''];
    }

    /**
     * These blueprints are built from JSON an agent produced, so a keyed array is a
     * real possibility. Left un-normalized it would serialize as a JSON object and
     * Grist would reject the payload for a confusing reason.
     */
    public function testKeyedArraysAreNormalizedToLists(): void
    {
        $blueprint = self::blueprint(
            eventTypes: [1 => 'update'],
            watchedColIds: ['a' => 'Photo', 'b' => 'YoutubeUrl'],
        );

        self::assertSame(['update'], $blueprint->eventTypes);
        self::assertSame(['Photo', 'YoutubeUrl'], $blueprint->watchedColIds);

        $encoded = json_encode($blueprint->toFields(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"eventTypes":["update"]', $encoded);
        self::assertStringContainsString('"watchedColIds":["Photo","YoutubeUrl"]', $encoded);
    }

    public function testRequiredFieldsAreRejectedWhenBlank(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::blueprint(name: '   ');
    }
}
