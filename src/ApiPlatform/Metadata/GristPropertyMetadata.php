<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform\Metadata;

/**
 * One resolved property <-> Grist column mapping.
 */
final readonly class GristPropertyMetadata
{
    /**
     * @param class-string|null $references
     * @param class-string<\BackedEnum>|null $enum
     */
    public function __construct(
        public string $property,
        public string $column,
        public ?string $references,
        public bool $filterable,
        public bool $sortable,
        public bool $writable,
        public ?string $type,
        public bool $nullable,
        public bool $collection,
        public ?string $enum = null,
    ) {
    }
}
