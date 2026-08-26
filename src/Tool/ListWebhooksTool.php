<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\GristBundle\Service\GristWebhookManager;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_list_webhooks', 'List the outgoing webhooks on a Grist document, including which columns each one watches and its delivery status.')]
#[McpTool(name: 'grist_list_webhooks')]
final readonly class ListWebhooksTool
{
    public function __construct(private GristWebhookManager $webhooks)
    {
    }

    public function __invoke(string $application): string
    {
        return ToolResponse::encode(array_map(
            static fn ($w): array => $w->toArray(),
            $this->webhooks->list($application),
        ));
    }
}
