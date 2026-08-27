<?php

declare(strict_types=1);

namespace Survos\GristBundle;

use Survos\Grist\Adapter\GristAdapterFactory;
use Survos\Grist\Service\GristApplicationLocator;
use Survos\Grist\Service\GristAttachmentManager;
use Survos\Grist\Service\GristFormManager;
use Survos\Grist\Service\GristQueryRunner;
use Survos\Grist\Service\GristSchemaManager;
use Survos\Grist\Service\GristWebhookManager;
use Survos\GristBundle\Tool\AddColumnsTool;
use Survos\GristBundle\Tool\AttachmentStoreTool;
use Survos\GristBundle\Tool\DescribeTableTool;
use Survos\GristBundle\Tool\ListApplicationsTool;
use Survos\GristBundle\Tool\ListFormsTool;
use Survos\GristBundle\Tool\ListWebhooksTool;
use Survos\GristBundle\Tool\SqlTool;
use Survos\GristBundle\Tool\UpsertFormTool;
use Survos\GristBundle\Tool\UpsertRecordsTool;
use Survos\GristBundle\Tool\UpsertWebhookTool;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\RecordStoreBundle\SurvosRecordStoreBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(SurvosRecordStoreBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosGristBundle extends AbstractSurvosBundle
{
    /** @param array<string,mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $s = $container->services()->defaults()->autowire()->autoconfigure();

        // This bundle owns survos/grist-php, so it registers the Grist record-store adapter --
        // the same way survos/quickbase-bundle registers the Quickbase one. record-store-bundle
        // stays provider-agnostic and knows about neither.
        $s->set(GristAdapterFactory::class)
            ->arg('$http', service('http_client'))
            ->tag('survos_record_store.adapter_factory');

        $s->set(GristApplicationLocator::class)->public();
        $s->set(GristFormManager::class)->public();
        $s->set(GristSchemaManager::class)->public();
        $s->set(GristQueryRunner::class)->public();
        $s->set(GristWebhookManager::class)->public();
        $s->set(GristAttachmentManager::class)->public();

        // The tools are the agent/MCP surface. They are thin wrappers over the
        // services above, so an app that only wants the services does not have to
        // pull in symfony/ai-agent or mcp/sdk -- register them only if both are
        // installed, matching the "suggest" entries in composer.json.
        if (!class_exists(\Symfony\AI\Agent\Toolbox\Attribute\AsTool::class) || !class_exists(\Mcp\Capability\Attribute\McpTool::class)) {
            return;
        }

        foreach ([
            ListApplicationsTool::class,
            DescribeTableTool::class,
            SqlTool::class,
            AddColumnsTool::class,
            AttachmentStoreTool::class,
            UpsertRecordsTool::class,
            ListFormsTool::class,
            UpsertFormTool::class,
            ListWebhooksTool::class,
            UpsertWebhookTool::class,
        ] as $tool) {
            $s->set($tool)->public();
        }
    }
}
