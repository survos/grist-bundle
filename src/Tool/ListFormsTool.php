<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\Grist\Service\GristFormManager;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_list_forms', 'List forms in a configured Grist application before changing its form design.')]
#[McpTool(name: 'grist_list_forms')]
final readonly class ListFormsTool
{
    public function __construct(private GristFormManager $forms)
    {
    }

    public function __invoke(string $application): string
    {
        return json_encode(array_map(static fn ($f): array => $f->toArray(), $this->forms->list($application)), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
