<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use Survos\GristBundle\Attribute\GristColumn;
use Survos\GristBundle\Attribute\GristResource;

/** Public properties, defaults on the property -- the shape the docs use. */
#[GristResource(
    application: 'chijal',
    table: 'locations',
    identifier: 'code',
    where: ['Status' => ['activo']],
    order: ['Name'],
)]
final class FixtureLocation
{
    public string $code = '';

    #[GristColumn(name: 'Name')]
    public string $label = '';

    #[GristColumn(filterable: true, sortable: true)]
    public ?string $barrio = null;

    public ?float $latitude = null;

    public ?int $capacity = null;

    #[GristColumn(references: FixtureObra::class)]
    public array $obras = [];

    #[GristColumn(references: FixtureObra::class, filterable: true)]
    public ?string $featured = null;

    #[GristColumn(writable: false)]
    public ?string $computed = null;
}
