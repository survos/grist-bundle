<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform\State;

use Survos\GristBundle\ApiPlatform\Metadata\GristPropertyMetadata;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadata;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\RecordStore\Model\Record;

/**
 * Grist row <-> resource object.
 *
 * Both directions live here because they have to agree about one thing: a reference is a
 * natural key on the outside and a row id on the inside, and the translation has to be
 * exactly reversible or a read-modify-write silently repoints a row.
 */
final readonly class GristHydrator
{
    public function __construct(
        private GristResourceMetadataFactory $factory,
        private GristRecordFetcher $fetcher,
    ) {
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

    /** Row id(s) -> natural key(s). */
    private function dereference(GristPropertyMetadata $property, mixed $value): mixed
    {
        /** @var class-string $references */
        $references = $property->references;
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

    /** Natural key(s) -> row id(s). */
    private function reference(GristPropertyMetadata $property, mixed $value): mixed
    {
        /** @var class-string $references */
        $references = $property->references;
        $rowIds = array_flip($this->fetcher->identifierMap($this->factory->create($references)));

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
