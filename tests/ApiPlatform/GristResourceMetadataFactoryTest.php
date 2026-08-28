<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadata;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\GristBundle\Attribute\GristColumn;
use Survos\GristBundle\Attribute\GristResource;

#[CoversClass(GristResourceMetadataFactory::class)]
#[CoversClass(GristResourceMetadata::class)]
#[CoversClass(GristResource::class)]
#[CoversClass(GristColumn::class)]
final class GristResourceMetadataFactoryTest extends TestCase
{
    public function testAPropertyMapsToItsUcfirstColumnWithoutAnAttribute(): void
    {
        $metadata = (new GristResourceMetadataFactory())->create(FixturePromoted::class);

        self::assertSame('Code', $metadata->properties['code']->column);
        self::assertSame('BirthYear', $metadata->properties['birthYear']->column);
        // ... and the attribute wins where the names diverge.
        self::assertSame('Tagline', $metadata->properties['slogan']->column);
    }

    public function testTheIdentifierResolvesToAPropertyNotAColumn(): void
    {
        $metadata = (new GristResourceMetadataFactory())->create(FixtureLocation::class);

        self::assertSame('code', $metadata->grist->identifier);
        self::assertSame('Code', $metadata->identifier->column);
    }

    public function testAnIdentifierNamingNoPropertyFailsAtMetadataTimeNotAtRequestTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declares "missingProperty" as its Grist identifier/');

        (new GristResourceMetadataFactory())->create(FixtureBadIdentifier::class);
    }

    public function testAClassWithoutTheAttributeIsRejectedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/carries no #\[GristResource\]/');

        (new GristResourceMetadataFactory())->create(\stdClass::class);
    }

    public function testColumnFlagsDefaultClosed(): void
    {
        $metadata = (new GristResourceMetadataFactory())->create(FixtureLocation::class);

        // A property nobody marked is neither filterable nor sortable: a query parameter
        // naming it has to be ignored rather than silently honoured.
        self::assertFalse($metadata->properties['label']->filterable);
        self::assertFalse($metadata->properties['label']->sortable);
        self::assertTrue($metadata->properties['barrio']->filterable);
        self::assertTrue($metadata->properties['barrio']->sortable);
        // writable is the exception: it defaults open, because most columns are writable and
        // a formula column that is wrongly written fails loudly rather than leaking.
        self::assertTrue($metadata->properties['label']->writable);
        self::assertFalse($metadata->properties['computed']->writable);
    }

    public function testCacheKeysSeparateDifferentWheresAndSurviveRepeatedCalls(): void
    {
        $metadata = (new GristResourceMetadataFactory())->create(FixtureLocation::class);

        self::assertSame($metadata->cacheKey('rows'), $metadata->cacheKey('rows'));
        self::assertNotSame($metadata->cacheKey('rows'), $metadata->cacheKey('ids'));
        // An operation that overrides `where` must not read the resource's cached set.
        self::assertNotSame($metadata->cacheKey('rows'), $metadata->cacheKey('rows', []));
        self::assertMatchesRegularExpression('/^survos_grist\.chijal\.locations\.rows\./', $metadata->cacheKey('rows'));
    }

    public function testMetadataIsResolvedOnce(): void
    {
        $factory = new GristResourceMetadataFactory();

        self::assertSame($factory->create(FixtureLocation::class), $factory->create(FixtureLocation::class));
    }
}
