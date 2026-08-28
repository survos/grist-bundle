<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\GristBundle\ApiPlatform\State\GristRecordFetcher;
use Survos\GristBundle\Attribute\GristResource;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The console side of the API Platform integration.
 *
 * Mostly it exists for the invalidation problem. Grist can push changes over a webhook, but
 * that needs ALLOWED_WEBHOOK_DOMAINS set on the Grist server -- and until it is, every hook
 * URL is refused. So there has to be a way to say "the document changed, forget what you
 * cached" that does not depend on the server being configured for it.
 */
final readonly class GristApiCommands
{
    public function __construct(
        private ResourceNameCollectionFactoryInterface $resourceNames,
        private GristResourceMetadataFactory $factory,
        private GristRecordFetcher $fetcher,
    ) {
    }

    #[AsCommand('grist:api:resources', 'List the API Platform resources backed by Grist')]
    public function resources(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->gristResources() as $class) {
            $metadata = $this->factory->create($class);
            $rows[] = [
                $class,
                sprintf('%s.%s', $metadata->grist->application, $metadata->grist->table),
                $metadata->identifier->column,
                [] === $metadata->grist->where ? '' : json_encode($metadata->grist->where, JSON_THROW_ON_ERROR),
                count($metadata->properties),
            ];
        }

        if ([] === $rows) {
            $io->warning('No class carries both #[ApiResource] and #[GristResource].');

            return Command::SUCCESS;
        }

        $io->table(['Resource', 'Table', 'Key column', 'Where', 'Properties'], $rows);

        return Command::SUCCESS;
    }

    #[AsCommand('grist:api:refresh', 'Drop the cached Grist reads behind the API resources')]
    public function refresh(
        SymfonyStyle $io,
        #[Argument('Resource class or short name; omit for all')] ?string $resource = null,
        #[Option('Only report what would be dropped')] bool $dryRun = false,
    ): int {
        $matched = [];
        foreach ($this->gristResources() as $class) {
            if (null === $resource || $class === $resource || str_ends_with($class, '\\'.$resource)) {
                $matched[] = $class;
            }
        }

        if ([] === $matched) {
            $io->error(sprintf('No Grist-backed resource matches "%s".', (string) $resource));

            return Command::FAILURE;
        }

        foreach ($matched as $class) {
            $dryRun || $this->fetcher->invalidate($class);
            $io->writeln(sprintf('%s %s', $dryRun ? 'would drop' : 'dropped', $class));
        }

        return Command::SUCCESS;
    }

    /** @return list<class-string> */
    private function gristResources(): array
    {
        $classes = [];
        foreach ($this->resourceNames->create() as $class) {
            if ([] !== (new \ReflectionClass($class))->getAttributes(GristResource::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
