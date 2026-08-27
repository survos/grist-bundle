<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\Grist\Model\FormBlueprint;

final class FormBlueprintTest extends TestCase
{
    public function testBlueprintPreservesFormDesign(): void
    {
        $blueprint = new FormBlueprint('pgsc', 'artworks', 'Artwork intake', 'Tell us about the artwork.', ['title', 'artist'], 'Save artwork', true, 'artwork-intake');

        self::assertSame(['title', 'artist'], $blueprint->fields);
        self::assertSame('Save artwork', $blueprint->submitLabel);
        self::assertTrue($blueprint->publish);
    }

    /** @return iterable<string, array{string, string, string, list<string>}> */
    public static function invalidBlueprints(): iterable
    {
        yield 'missing application' => ['', 'artworks', 'Artwork intake', ['title']];
        yield 'missing table' => ['pgsc', '', 'Artwork intake', ['title']];
        yield 'missing title' => ['pgsc', 'artworks', '', ['title']];
        yield 'missing fields' => ['pgsc', 'artworks', 'Artwork intake', []];
        yield 'duplicate fields' => ['pgsc', 'artworks', 'Artwork intake', ['title', 'title']];
    }

    /** @param list<string> $fields */
    #[DataProvider('invalidBlueprints')]
    public function testInvalidBlueprintIsRejected(string $application, string $table, string $title, array $fields): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormBlueprint($application, $table, $title, '', $fields);
    }
}
