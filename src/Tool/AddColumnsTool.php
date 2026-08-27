<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\Grist\Service\GristSchemaManager;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_add_columns', 'Add missing columns to a Grist table. Additive only: existing columns are left untouched, never dropped or retyped. Types include Text, Numeric, Int, Bool, Date, DateTime, Choice, ChoiceList, Attachments, and Ref:OtherTable.')]
#[McpTool(name: 'grist_add_columns')]
final readonly class AddColumnsTool
{
    public function __construct(private GristSchemaManager $schema)
    {
    }

    /** @param array<string,string|array<string,mixed>> $columns colId => type, or colId => full fields array */
    public function __invoke(string $application, string $table, array $columns): string
    {
        $created = $this->schema->addColumns($application, $table, $columns);

        return ToolResponse::encode([
            'table' => $table,
            'created' => $created,
            'skipped' => array_values(array_diff(array_map(strval(...), array_keys($columns)), $created)),
        ]);
    }
}
