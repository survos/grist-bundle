<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\Grist\Model\FormBlueprint;
use Survos\Grist\Service\GristFormManager;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_upsert_form', 'Create or update a Grist form from an ordered field blueprint, optionally publishing it.')]
#[McpTool(name: 'grist_upsert_form')]
final readonly class UpsertFormTool
{
    public function __construct(private GristFormManager $forms)
    {
    }

    /** @param list<string> $fields */
    public function __invoke(string $application, string $table, string $title, string $intro, array $fields, string $submitLabel = 'Submit', bool $publish = false, ?string $linkId = null): string
    {
        $f = $this->forms->upsert(new FormBlueprint($application, $table, $title, $intro, $fields, $submitLabel, $publish, $linkId));

        return json_encode($f->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}
