<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use Survos\GristBundle\Attribute\GristColumn;
use Survos\GristBundle\Attribute\GristResource;

/** A resource that points at itself -- legal in a document, unbounded if hydrated eagerly. */
#[GristResource(application: 'chijal', table: 'obras', identifier: 'code')]
final class FixtureSelfReference
{
    public string $code = '';

    #[GristColumn(name: 'Parent', references: FixtureSelfReference::class)]
    public ?FixtureSelfReference $parent = null;
}
