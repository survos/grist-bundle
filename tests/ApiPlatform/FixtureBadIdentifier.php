<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tests\ApiPlatform;

use Survos\GristBundle\Attribute\GristColumn;
use Survos\GristBundle\Attribute\GristResource;

#[GristResource(application: 'chijal', table: 'obras', identifier: 'missingProperty')]
final class FixtureBadIdentifier
{
    public string $code = '';
}
