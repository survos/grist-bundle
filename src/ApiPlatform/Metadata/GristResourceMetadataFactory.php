<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform\Metadata;

use Survos\GristBundle\Attribute\GristColumn;
use Survos\GristBundle\Attribute\GristResource;

/**
 * Reads #[GristResource] / #[GristColumn] off a class, once.
 */
final class GristResourceMetadataFactory
{
    /** @var array<class-string, GristResourceMetadata> */
    private array $metadata = [];

    /** @param class-string $resourceClass */
    public function create(string $resourceClass): GristResourceMetadata
    {
        if (isset($this->metadata[$resourceClass])) {
            return $this->metadata[$resourceClass];
        }

        $reflection = new \ReflectionClass($resourceClass);
        $attribute = $reflection->getAttributes(GristResource::class)[0]
            ?? throw new \InvalidArgumentException(sprintf(
                'Class "%s" is served by the Grist state provider but carries no #[GristResource].',
                $resourceClass,
            ));

        $grist = $attribute->newInstance();
        $properties = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $properties[$property->getName()] = self::describe($property);
        }

        $identifier = $properties[$grist->identifier] ?? throw new \InvalidArgumentException(sprintf(
            'Class "%s" declares "%s" as its Grist identifier, but has no such public property.',
            $resourceClass,
            $grist->identifier,
        ));

        return $this->metadata[$resourceClass] = new GristResourceMetadata($resourceClass, $grist, $properties, $identifier);
    }

    private static function describe(\ReflectionProperty $property): GristPropertyMetadata
    {
        $column = ($property->getAttributes(GristColumn::class)[0] ?? null)?->newInstance() ?? new GristColumn();
        $type = $property->getType();
        $name = null;
        $nullable = true;
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            $nullable = $type->allowsNull();
        }

        $enum = null !== $name && is_a($name, \BackedEnum::class, true) ? $name : null;

        return new GristPropertyMetadata(
            property: $property->getName(),
            column: $column->name ?? ucfirst($property->getName()),
            references: $column->references,
            filterable: $column->filterable,
            sortable: $column->sortable,
            writable: $column->writable,
            type: $name,
            nullable: $nullable,
            collection: 'array' === $name,
            enum: $enum,
        );
    }
}
