<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\GristBundle\ApiPlatform\State\GristRecordFetcher;
use Survos\GristBundle\Attribute\GristResource;
use Survos\Grist\Service\GristApplicationLocator;
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
        private GristApplicationLocator $applications,
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

    /**
     * Compare what the DTOs claim against what the document actually has.
     *
     * A #[GristResource] is descriptive, not prescriptive: the Grist tables came first and
     * the class is a typed view over them. Nothing enforces the match, so a column renamed
     * or removed in Grist does not break loudly -- the property simply reads null forever,
     * and an endpoint quietly starts serving blanks.
     *
     * This is the check that turns the DTO from a claim into a contract. It is deliberately
     * read-only: it will not create or rename anything, because the document is edited by
     * people and guessing at their intent is worse than reporting the difference.
     *
     * Columns present in Grist but absent from the DTO are reported separately and are not
     * failures -- a document may hold working notes an API has no business exposing. A
     * property with no column behind it always is.
     */
    #[AsCommand('grist:schema:diff', 'Check the Grist-backed DTOs against the live document')]
    public function diff(
        SymfonyStyle $io,
        #[Argument('Resource class or short name; omit for all')] ?string $resource = null,
        #[Option('Also list columns the document has that no property maps')] bool $unmapped = false,
    ): int {
        $classes = [];
        foreach ($this->gristResources() as $class) {
            if (null === $resource || $class === $resource || str_ends_with($class, '\\'.$resource)) {
                $classes[] = $class;
            }
        }

        if ([] === $classes) {
            $io->error(sprintf('No Grist-backed resource matches "%s".', (string) $resource));

            return Command::FAILURE;
        }

        $problems = 0;

        foreach ($classes as $class) {
            $metadata = $this->factory->create($class);
            $io->section(sprintf('%s -> %s.%s', $class, $metadata->grist->application, $metadata->grist->table));

            try {
                $columns = $this->columns($metadata->grist->application, $metadata->grist->table);
            } catch (\Throwable $e) {
                // A table the document does not have at all is the loudest possible failure,
                // and the one most likely after a re-import under a different name.
                $io->error($e->getMessage());
                ++$problems;

                continue;
            }

            $rows = [];
            foreach ($metadata->properties as $property) {
                $known = isset($columns[$property->column]);
                $known || ++$problems;
                $rows[] = [
                    $property->property,
                    $property->column,
                    $known ? $columns[$property->column] : '—',
                    $known ? 'ok' : 'MISSING in Grist',
                ];
            }

            $io->table(['property', 'column', 'grist type', ''], $rows);

            $extra = array_diff(array_keys($columns), array_map(
                static fn ($p) => $p->column,
                $metadata->properties,
            ));

            if ($unmapped && [] !== $extra) {
                $io->writeln(sprintf('  not mapped by any property: %s', implode(', ', $extra)));
            } elseif ([] !== $extra) {
                $io->writeln(sprintf('  %d column(s) not mapped; --unmapped to list them', count($extra)));
            }
        }

        if ($problems > 0) {
            $io->error(sprintf('%d propert%s reference a column the document does not have.', $problems, 1 === $problems ? 'y' : 'ies'));

            return Command::FAILURE;
        }

        $io->success('Every mapped property has a column behind it.');

        return Command::SUCCESS;
    }

    /**
     * column id => Grist type, for one table.
     *
     * @return array<string, string>
     */
    private function columns(string $application, string $table): array
    {
        [$app, $client] = $this->applications->locate($application);
        $columns = [];

        foreach ($client->columns($app->id, $table) as $column) {
            $id = (string) ($column['id'] ?? '');
            if ('' !== $id) {
                $columns[$id] = (string) ($column['fields']['type'] ?? 'Any');
            }
        }

        if ([] === $columns) {
            throw new \RuntimeException(sprintf('The document has no table "%s", or it has no columns.', $table));
        }

        return $columns;
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
