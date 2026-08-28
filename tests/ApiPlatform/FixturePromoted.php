<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use Survos\GristBundle\Attribute\GristColumn;
use Survos\GristBundle\Attribute\GristResource;

/** Promoted constructor properties, defaults on the parameter -- the scs DTO shape. */
#[GristResource(application: 'chijal', table: 'artists', identifier: 'code')]
final class FixturePromoted
{
    public function __construct(
        public string $code = '',
        public string $name = '',
        public ?int $birthYear = null,
        #[GristColumn(name: 'Tagline')]
        public ?string $slogan = null,
    ) {
    }
}
