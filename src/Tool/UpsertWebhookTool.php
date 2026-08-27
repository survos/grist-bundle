<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\Grist\Model\WebhookBlueprint;
use Survos\Grist\Service\GristWebhookManager;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_upsert_webhook', 'Create or update an outgoing Grist webhook, matched by name so it is safe to re-apply. Set watchedColIds to the columns a human edits so a callback writing derived columns back cannot re-trigger itself, and isReadyColumn to a boolean column so half-filled rows never fire.')]
#[McpTool(name: 'grist_upsert_webhook')]
final readonly class UpsertWebhookTool
{
    public function __construct(private GristWebhookManager $webhooks)
    {
    }

    /**
     * @param list<string> $eventTypes    'add' and/or 'update'
     * @param list<string> $watchedColIds empty means any column change fires it
     */
    public function __invoke(
        string $application,
        string $table,
        string $name,
        string $url,
        array $eventTypes = ['add', 'update'],
        array $watchedColIds = [],
        ?string $isReadyColumn = null,
        bool $enabled = true,
        ?string $memo = null,
    ): string {
        $webhook = $this->webhooks->upsert(new WebhookBlueprint(
            application: $application,
            table: $table,
            name: $name,
            url: $url,
            eventTypes: $eventTypes,
            watchedColIds: $watchedColIds,
            isReadyColumn: $isReadyColumn,
            enabled: $enabled,
            memo: $memo,
        ));

        return ToolResponse::encode($webhook->toArray());
    }
}
