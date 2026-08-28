<?php

declare(strict_types=1);

namespace Survos\GristBundle;

use ApiPlatform\State\ProviderInterface;
use Survos\Grist\Adapter\GristAdapterFactory;
use Survos\Grist\Service\GristApplicationLocator;
use Survos\Grist\Service\GristAttachmentManager;
use Survos\Grist\Service\GristFormManager;
use Survos\Grist\Service\GristQueryRunner;
use Survos\Grist\Service\GristSchemaManager;
use Survos\Grist\Service\GristWebhookManager;
use Survos\GristBundle\ApiPlatform\GristApiCommands;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\GristBundle\ApiPlatform\State\GristHydrator;
use Survos\GristBundle\ApiPlatform\State\GristProcessor;
use Survos\GristBundle\ApiPlatform\State\GristProvider;
use Survos\GristBundle\ApiPlatform\State\GristRecordFetcher;
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
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(SurvosRecordStoreBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosGristBundle extends AbstractSurvosBundle
{
    /**
     * The only thing worth configuring per app: how stale a Grist read may be.
     *
     * Kept as a floor rather than a knob to turn off -- there is no `enabled: false` here,
     * because "read Grist on every request" is not a supported way to run this.
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('api_platform')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('cache_ttl')->min(1)->defaultValue(900)
                            ->info('Seconds a cached table read stays usable. Overridable per resource.')
                        ->end()
                        ->integerNode('max_rows')->min(1)->defaultValue(5000)
                            ->info('Rows above which the provider refuses to serve rather than truncate.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

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

        $this->registerApiPlatform($s, $config);

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

    /**
     * The API Platform surface, registered only when API Platform is installed.
     *
     * This bundle is used for console commands and MCP tools by apps that have no HTTP API
     * at all, so api-platform/core is a "suggest", not a "require" -- the same arrangement
     * the agent tools already use for symfony/ai-agent.
     *
     * @param array<string,mixed> $config
     */
    private function registerApiPlatform(DefaultsConfigurator $s, array $config): void
    {
        if (!interface_exists(ProviderInterface::class)) {
            return;
        }

        $api = $config['api_platform'] ?? [];

        $s->set(GristResourceMetadataFactory::class);
        $s->set(GristRecordFetcher::class)
            ->arg('$cache', service('cache.app'))
            ->arg('$defaultTtl', $api['cache_ttl'] ?? 900)
            ->arg('$defaultMaxRows', $api['max_rows'] ?? 5000)
            ->arg('$logger', service('logger')->nullOnInvalid())
            ->arg('$resourceMetadata', service('api_platform.metadata.resource.metadata_collection_factory')->nullOnInvalid());
        $s->set(GristHydrator::class);

        // Public so they can be named directly in #[ApiResource(provider: ..., processor: ...)].
        $s->set(GristProvider::class)->public()->tag('api_platform.state_provider');
        $s->set(GristProcessor::class)->public()->tag('api_platform.state_processor');

        $s->set(GristApiCommands::class);
    }

}
