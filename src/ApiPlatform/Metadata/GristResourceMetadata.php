<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform\Metadata;

use Survos\GristBundle\Attribute\GristResource;

final readonly class GristResourceMetadata
{
    /**
     * @param class-string $resourceClass
     * @param array<string, GristPropertyMetadata> $properties keyed by property name
     */
    public function __construct(
        public string $resourceClass,
        public GristResource $grist,
        public array $properties,
        public GristPropertyMetadata $identifier,
    ) {
    }

    public function property(string $name): ?GristPropertyMetadata
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * Distinguishes one cached read from another.
     *
     * Only a declared `where` and `order` take part: caller-supplied filters are applied in
     * PHP against the cached set, precisely so that they do not each mint a new cache entry
     * and a new round-trip to Grist. An operation that overrides `where` gets its own entry,
     * which is the point -- the admin read and the public read are different sets.
     *
     * @param array<string, list<bool|float|int|string|null>>|null $where null uses the resource's own
     */
    public function cacheKey(string $suffix = 'rows', ?array $where = null): string
    {
        $shape = json_encode([$where ?? $this->grist->where, $this->grist->order], JSON_THROW_ON_ERROR);

        return sprintf(
            'survos_grist.%s.%s.%s.%s',
            preg_replace('/[^a-zA-Z0-9_]/', '_', $this->grist->application),
            preg_replace('/[^a-zA-Z0-9_]/', '_', $this->grist->table),
            $suffix,
            substr(hash('xxh128', $shape), 0, 12),
        );
    }
}
