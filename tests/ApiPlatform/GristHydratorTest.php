<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\GristBundle\ApiPlatform\State\GristHydrator;
use Survos\GristBundle\ApiPlatform\State\GristRecordFetcher;
use Survos\RecordStore\Model\Record;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(GristHydrator::class)]
#[CoversClass(GristRecordFetcher::class)]
final class GristHydratorTest extends TestCase
{
    private GristResourceMetadataFactory $factory;
    private GristHydrator $hydrator;
    private InMemoryGristStore $store;

    protected function setUp(): void
    {
        $this->store = InMemoryGristStore::withRows([
            'Obras' => [
                [3, ['Code' => 'el2', 'Title' => 'Uno']],
                [7, ['Code' => 'vic1', 'Title' => 'Dos']],
                [9, ['Code' => '', 'Title' => 'Half-entered']],
            ],
        ]);
        $this->factory = new GristResourceMetadataFactory();
        $this->hydrator = new GristHydrator(
            $this->factory,
            new GristRecordFetcher($this->store->registry(), $this->factory, new ArrayAdapter()),
        );
    }

    public function testARefListBecomesNaturalKeysAndTheLeadingMarkerIsNotARow(): void
    {
        $location = $this->hydrate(['Code' => 'bioes', 'Obras' => ['L', 3, 7]]);

        self::assertSame(['el2', 'vic1'], $location->obras);
    }

    public function testAScalarRefBecomesOneNaturalKey(): void
    {
        self::assertSame('vic1', $this->hydrate(['Code' => 'x', 'Featured' => 7])->featured);
    }

    public function testAReferenceToARowThatIsGoneResolvesToNothingRatherThanARowId(): void
    {
        $location = $this->hydrate(['Code' => 'x', 'Obras' => ['L', 3, 404], 'Featured' => 404]);

        self::assertSame(['el2'], $location->obras);
        self::assertNull($location->featured);
    }

    public function testAGristCellErrorIsNotAValue(): void
    {
        $location = $this->hydrate(['Code' => 'x', 'Latitude' => ['E', 'ValueError'], 'Obras' => ['E', 'ValueError']]);

        self::assertNull($location->latitude);
        self::assertSame([], $location->obras);
    }

    public function testValuesAreCastToTheDeclaredPhpType(): void
    {
        $location = $this->hydrate(['Code' => 'x', 'Latitude' => '16.73', 'Capacity' => '40', 'Name' => 12]);

        self::assertSame(16.73, $location->latitude);
        self::assertSame(40, $location->capacity);
        self::assertSame('12', $location->label);
    }

    public function testAMissingNullableColumnIsNullNotAnEmptyString(): void
    {
        // Distinguishing "not set" from "set to empty" is the point: a |default() here would
        // turn an absent column into a value the document does not contain.
        self::assertNull($this->hydrate(['Code' => 'x'])->barrio);
    }

    public function testPromotedConstructorPropertiesKeepTheirParameterDefaults(): void
    {
        $metadata = $this->factory->create(FixturePromoted::class);
        $artist = $this->hydrator->hydrate($metadata, new Record(['Code' => 'az', 'Name' => 'Jazmine'], 10));

        self::assertSame('az', $artist->code);
        self::assertSame('Jazmine', $artist->name);
        // Absent from the row, and its default lives on the constructor parameter -- which is
        // why the hydrator has to go through the constructor rather than around it.
        self::assertNull($artist->birthYear);
        self::assertNull($artist->slogan);
    }

    public function testWritingBackIsTheExactInverseOfReading(): void
    {
        $metadata = $this->factory->create(FixtureLocation::class);
        $fields = ['Code' => 'bioes', 'Name' => 'Espiral', 'Obras' => ['L', 3, 7], 'Featured' => 7];

        $fromGrist = $this->hydrator->hydrate($metadata, new Record($fields, 1));
        $backToGrist = $this->hydrator->toGristFields($metadata, $fromGrist);

        // References must survive the round trip as row ids, or a read-modify-write silently
        // repoints the row.
        self::assertSame(['L', 3, 7], $backToGrist['Obras']);
        self::assertSame(7, $backToGrist['Featured']);
        self::assertSame('bioes', $backToGrist['Code']);
        self::assertSame('Espiral', $backToGrist['Name']);
    }

    public function testANonWritableColumnIsNeverSentToGrist(): void
    {
        $metadata = $this->factory->create(FixtureLocation::class);
        $object = $this->hydrator->hydrate($metadata, new Record(['Code' => 'x', 'Computed' => 'derived'], 1));

        self::assertArrayNotHasKey('Computed', $this->hydrator->toGristFields($metadata, $object));
    }

    public function testWritingAReferenceNobodyHasFailsInsteadOfPointingAtNothing(): void
    {
        $metadata = $this->factory->create(FixtureLocation::class);
        $object = $this->hydrator->hydrate($metadata, new Record(['Code' => 'x'], 1));
        $object->featured = 'no-such-obra';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/has the identifier "no-such-obra"/');

        $this->hydrator->toGristFields($metadata, $object);
    }

    /** @param array<string, mixed> $fields */
    private function hydrate(array $fields): FixtureLocation
    {
        return $this->hydrator->hydrate($this->factory->create(FixtureLocation::class), new Record($fields, 1));
    }
}
