<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform\State;

use Survos\GristBundle\ApiPlatform\Metadata\GristPropertyMetadata;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadata;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\RecordStore\Model\Record;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Grist row <-> resource object.
 *
 * Both directions live here because they have to agree about one thing: a reference is a
 * natural key on the outside and a row id on the inside, and the translation has to be
 * exactly reversible or a read-modify-write silently repoints a row.
 */
final class GristHydrator implements ResetInterface
{
    /**
     * Rows of a referenced table, indexed by Grist row id, for the duration of one request.
     *
     * A collection of nine obras resolving two references each would otherwise scan the
     * artists and locations tables eighteen times. The rows themselves are already cached by
     * GristRecordFetcher; this only avoids re-indexing them per lookup.
     *
     * @var array<class-string, array<int|string, Record>>
     */
    private array $rowsById = [];

    /** @var array<class-string, true> classes currently being hydrated, to stop a reference cycle */
    private array $hydrating = [];

    public function __construct(
        private readonly GristResourceMetadataFactory $factory,
        private readonly GristRecordFetcher $fetcher,
    ) {
    }

    /**
     * Drop the per-request index.
     *
     * Autoconfigured onto kernel.reset, which is what makes this safe under a worker runtime:
     * without it the first request's view of the artists table would be handed to every
     * request the process went on to serve, TTL or no TTL.
     */
    public function reset(): void
    {
        $this->rowsById = [];
        $this->hydrating = [];
    }

    public function hydrate(GristResourceMetadata $metadata, Record $record): object
    {
        $values = [];
        foreach ($metadata->properties as $name => $property) {
            $values[$name] = $this->toPhp($property, $record->fields[$property->column] ?? null);
        }

        $reflection = new \ReflectionClass($metadata->resourceClass);
        $constructor = $reflection->getConstructor();

        if (null !== $constructor && $constructor->getNumberOfParameters() > 0) {
            // Promoted constructor properties have their defaults on the parameter, not the
            // property, so newInstanceWithoutConstructor() would leave them uninitialized.
            $arguments = [];
            foreach ($constructor->getParameters() as $parameter) {
                $arguments[] = array_key_exists($parameter->getName(), $values)
                    ? $values[$parameter->getName()]
                    : self::fallback($parameter, $metadata->resourceClass);
                unset($values[$parameter->getName()]);
            }
            $object = $reflection->newInstanceArgs($arguments);
        } else {
            $object = $reflection->newInstanceWithoutConstructor();
        }

        foreach ($values as $name => $value) {
            $reflection->getProperty($name)->setValue($object, $value);
        }

        return $object;
    }

    /**
     * The Grist fields for one object, ready for an upsert.
     *
     * @return array<string, mixed>
     */
    public function toGristFields(GristResourceMetadata $metadata, object $object): array
    {
        $fields = [];
        foreach ($metadata->properties as $name => $property) {
            if (!$property->writable) {
                continue;
            }

            $reflection = new \ReflectionProperty($metadata->resourceClass, $name);
            if (!$reflection->isInitialized($object)) {
                continue;
            }

            $fields[$property->column] = $this->toGrist($property, $reflection->getValue($object));
        }

        return $fields;
    }

    private function toPhp(GristPropertyMetadata $property, mixed $value): mixed
    {
        // Grist reports a cell error as ['E', 'ValueError', ...]. It is not a value.
        if (is_array($value) && 'E' === ($value[0] ?? null)) {
            return $property->collection ? [] : null;
        }

        if (null !== $property->references) {
            return $this->dereference($property, $value);
        }

        if ($property->collection) {
            return self::listValue($value);
        }

        if (null !== $property->enum) {
            /** @var class-string<\BackedEnum> $enum */
            $enum = $property->enum;

            return is_scalar($value) ? $enum::tryFrom(is_bool($value) ? (int) $value : $value) : null;
        }

        return match ($property->type) {
            'int' => is_numeric($value) ? (int) $value : ($property->nullable ? null : 0),
            'float' => is_numeric($value) ? (float) $value : ($property->nullable ? null : 0.0),
            'bool' => (bool) $value,
            'string' => is_scalar($value) ? (string) $value : ($property->nullable ? null : ''),
            \DateTimeImmutable::class, \DateTimeInterface::class => self::dateValue($value),
            default => $value,
        };
    }

    private function toGrist(GristPropertyMetadata $property, mixed $value): mixed
    {
        if (null !== $property->references) {
            return $this->reference($property, $value);
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_array($value)) {
            return array_merge(['L'], array_values($value));
        }

        return $value;
    }

    /**
     * Row id(s) -> natural key(s), or -> the referenced object.
     *
     * Which one depends on how the property is typed. A `?string $locationCode` reads as the
     * key; a `?Location $location` reads as the whole resource, so a consumer that needs the
     * venue's name on a list screen does not have to fetch it separately. Both can map the
     * same Grist column -- they are two views of one value, and the app that indexes on the
     * key also renders the label.
     */
    private function dereference(GristPropertyMetadata $property, mixed $value): mixed
    {
        /** @var class-string $references */
        $references = $property->references;

        if ($this->hydratesToObject($property)) {
            return is_numeric($value) ? $this->referencedObject($references, (int) $value) : null;
        }

        $codes = $this->fetcher->identifierMap($this->factory->create($references));

        if (!$property->collection) {
            return is_numeric($value) ? ($codes[(int) $value] ?? null) : null;
        }

        $out = [];
        foreach (self::listValue($value) as $id) {
            if (is_numeric($id) && isset($codes[(int) $id])) {
                $out[] = $codes[(int) $id];
            }
        }

        return $out;
    }

    /**
     * The referenced resource, or null when the row it names is gone.
     *
     * Guarded against a cycle: a resource that references itself, directly or through another,
     * would otherwise hydrate forever. The guard returns null rather than throwing, because a
     * cycle is a modelling choice a document may legitimately contain -- it is only unbounded
     * *eager* hydration of one that cannot work.
     *
     * @param class-string $references
     */
    private function referencedObject(string $references, int $rowId): ?object
    {
        if (isset($this->hydrating[$references])) {
            return null;
        }

        $metadata = $this->factory->create($references);

        if (!isset($this->rowsById[$references])) {
            $indexed = [];
            // Without the resource's `where`: a closed venue is still the venue an obra hangs
            // in, for the same reason identifierMap ignores it.
            foreach ($this->fetcher->rows($metadata, []) as $record) {
                if (null !== $record->id) {
                    $indexed[$record->id] = $record;
                }
            }
            $this->rowsById[$references] = $indexed;
        }

        $record = $this->rowsById[$references][$rowId] ?? null;
        if (null === $record) {
            return null;
        }

        $this->hydrating[$references] = true;
        try {
            return $this->hydrate($metadata, $record);
        } finally {
            unset($this->hydrating[$references]);
        }
    }

    /** A reference property typed as a class wants the object; typed as a string, the key. */
    private function hydratesToObject(GristPropertyMetadata $property): bool
    {
        return !$property->collection
            && null !== $property->type
            && class_exists($property->type);
    }

    /** Natural key(s) -> row id(s). */
    private function reference(GristPropertyMetadata $property, mixed $value): mixed
    {
        /** @var class-string $references */
        $references = $property->references;
        $rowIds = array_flip($this->fetcher->identifierMap($this->factory->create($references)));

        // A property that read as an object writes as its natural key, so the round trip is
        // still exactly reversible.
        if (is_object($value)) {
            $identifier = $this->factory->create($value::class)->identifier->property;
            $value = (new \ReflectionProperty($value::class, $identifier))->getValue($value);
        }

        if (!$property->collection) {
            if (null === $value || '' === $value) {
                return 0; // Grist's "no reference".
            }

            return $rowIds[(string) $value] ?? throw new \InvalidArgumentException(sprintf(
                'No %s has the identifier "%s", so "%s" cannot point at it.',
                $references,
                (string) $value,
                $property->property,
            ));
        }

        $ids = ['L'];
        foreach (self::listValue($value) as $code) {
            $ids[] = $rowIds[(string) $code] ?? throw new \InvalidArgumentException(sprintf(
                'No %s has the identifier "%s", so "%s" cannot include it.',
                $references,
                (string) $code,
                $property->property,
            ));
        }

        return $ids;
    }

    /**
     * A Grist list arrives as ['L', 3, 7]; the leading marker is the type, not a member.
     *
     * @return list<mixed>
     */
    private static function listValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if ('L' === ($value[0] ?? null)) {
            array_shift($value);
        }

        return array_values($value);
    }

    private static function dateValue(mixed $value): ?\DateTimeImmutable
    {
        if (is_numeric($value)) {
            return (new \DateTimeImmutable())->setTimestamp((int) $value);
        }

        return is_string($value) && '' !== $value ? new \DateTimeImmutable($value) : null;
    }

    private static function fallback(\ReflectionParameter $parameter, string $resourceClass): mixed
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new \LogicException(sprintf(
            'Constructor parameter "$%s" of %s maps to no public property, so the Grist provider cannot supply it.',
            $parameter->getName(),
            $resourceClass,
        ));
    }
}
