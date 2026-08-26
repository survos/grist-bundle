<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\GristBundle\Service\GristSchemaManager;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_describe_table', 'Describe a Grist table: every column with its type, label, and formula. Use this before writing a query or a form so column ids and types are known rather than guessed.')]
#[McpTool(name: 'grist_describe_table')]
final readonly class DescribeTableTool
{
    public function __construct(private GristSchemaManager $schema)
    {
    }

    public function __invoke(string $application, ?string $table = null): string
    {
        if (null === $table) {
            return ToolResponse::encode(['tables' => $this->schema->tables($application)]);
        }

        return ToolResponse::encode([
            'table' => $table,
            'columns' => $this->schema->describeTable($application, $table),
        ]);
    }
}
