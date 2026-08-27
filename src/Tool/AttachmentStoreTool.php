<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

use Mcp\Capability\Attribute\McpTool;
use Survos\Grist\Service\GristAttachmentManager;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('grist_attachment_store', 'Report where a document keeps attachment bytes, and optionally move them to the server external object store. External storage is opt-in per document, so a document created before the server was configured still keeps its bytes inside the document until this is called.')]
#[McpTool(name: 'grist_attachment_store')]
final readonly class AttachmentStoreTool
{
    public function __construct(private GristAttachmentManager $attachments)
    {
    }

    public function __invoke(string $application, bool $useExternal = false, bool $transferExisting = true): string
    {
        $result = $useExternal
            ? $this->attachments->useExternalStore($application, $transferExisting)
            : $this->attachments->status($application);

        return ToolResponse::encode($result);
    }
}
