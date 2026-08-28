<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use Survos\GristBundle\Attribute\GristColumn;
use Survos\GristBundle\Attribute\GristResource;

/** A resource whose reference is typed as the referenced class, not as its key. */
#[GristResource(application: 'chijal', table: 'obras', identifier: 'code')]
final class FixtureNested
{
    public string $code = '';

    /** The key, for a client that indexes on it. */
    #[GristColumn(name: 'Location', references: FixtureLocation::class)]
    public ?string $locationCode = null;

    /** The same column, as the whole venue, for a client that renders its name. */
    #[GristColumn(name: 'Location', references: FixtureLocation::class, writable: false)]
    public ?FixtureLocation $location = null;
}
