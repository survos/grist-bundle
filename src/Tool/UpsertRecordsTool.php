<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\Grist\Service\GristApplicationLocator;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_upsert_records', 'Insert or update rows in a Grist table, matched on a natural key column rather than row id, so re-running the same call is idempotent.')]
#[McpTool(name: 'grist_upsert_records')]
final readonly class UpsertRecordsTool
{
    public function __construct(private GristApplicationLocator $applications)
    {
    }

    /**
     * @param string                          $keyColumn the natural key to match on, e.g. a Code column
     * @param list<array<string,mixed>>       $records   each must contain $keyColumn
     */
    public function __invoke(string $application, string $table, string $keyColumn, array $records): string
    {
        [$app, $client] = $this->applications->locate($application);

        $payload = [];
        foreach ($records as $i => $fields) {
            if (!array_key_exists($keyColumn, $fields)) {
                throw new \InvalidArgumentException(sprintf('Record %d is missing the key column "%s".', $i, $keyColumn));
            }
            $payload[] = ['require' => [$keyColumn => $fields[$keyColumn]], 'fields' => $fields];
        }

        $ids = $client->upsertRecords($app->id, $table, $payload);

        return ToolResponse::encode(['table' => $table, 'written' => count($payload), 'ids' => $ids]);
    }
}
