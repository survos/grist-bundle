<?php

declare(strict_types=1);

namespace Survos\GristBundle\Attribute;

/**
 * Maps one property to one Grist column.
 *
 * Optional: without it a property maps to `ucfirst($property)`, which is already right for
 * `code` -> `Code` and `birthYear` -> `BirthYear`. Reach for it when the names diverge
 * (`slogan` <- `Tagline`), when a column is a reference, or when a column must not be written.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final readonly class GristColumn
{
    /**
     * @param string|null $name       Grist column id; defaults to `ucfirst($property)`
     * @param class-string|null $references another #[GristResource] class. A `Ref` column then
     *                            reads as that class's natural key instead of a row id, and a
     *                            `RefList` as a list of them. This is what keeps row ids out of
     *                            the payload entirely.
     * @param bool $filterable    expose as a collection query parameter
     * @param bool $sortable      expose to the `order` query parameter
     * @param bool $writable      false for formula and computed columns. Grist rejects writes to
     *                            them, and a processor that sends one fails the whole upsert.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $references = null,
        public bool $filterable = false,
        public bool $sortable = false,
        public bool $writable = true,
    ) {
        if (null !== $this->name && '' === trim($this->name)) {
            throw new \InvalidArgumentException('A #[GristColumn] name cannot be empty; omit it to use the default.');
        }
    }
}
