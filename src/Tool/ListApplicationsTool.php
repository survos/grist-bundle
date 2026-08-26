<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\RecordStoreBundle\Registry\RecordStoreRegistry;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_list_applications', 'List the configured record-store applications and their tables. Call this first to discover the application name every other Grist tool needs.')]
#[McpTool(name: 'grist_list_applications')]
final readonly class ListApplicationsTool
{
    public function __construct(private RecordStoreRegistry $registry)
    {
    }

    public function __invoke(): string
    {
        $out = [];
        foreach ($this->registry->applicationNames() as $name) {
            $app = $this->registry->application($name);
            $out[] = [
                'application' => $name,
                'connection' => $app->connection,
                'documentId' => $app->id,
                'tables' => array_keys($app->tables),
            ];
        }

        return ToolResponse::encode($out);
    }
}
