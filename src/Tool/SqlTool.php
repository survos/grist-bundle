<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\GristBundle\Service\GristQueryRunner;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_sql', 'Run a read-only SELECT against a Grist document. A document is SQLite, so tables can be joined -- resolve Ref columns to their labels in one query instead of looking up row ids afterwards. Bind values with ? and the args array; never interpolate them.')]
#[McpTool(name: 'grist_sql')]
final readonly class SqlTool
{
    public function __construct(private GristQueryRunner $queries)
    {
    }

    /** @param list<scalar|null> $args */
    public function __invoke(string $application, string $sql, array $args = [], int $limit = 500): string
    {
        $rows = $this->queries->sql($application, $sql, $args, $limit);

        return ToolResponse::encode(['count' => count($rows), 'records' => $rows]);
    }
}
