<?php

declare(strict_types=1);

namespace Survos\GristBundle\Command;

use Survos\Grist\Service\GristAttachmentManager;
use Survos\Grist\Service\GristQueryRunner;
use Survos\Grist\Service\GristSchemaManager;
use Survos\Grist\Service\GristWebhookManager;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The same capabilities the agent tools expose, on the console.
 *
 * Worth having separately from the tools: this is how you check what an agent did,
 * and it works in an app that has not installed the agent stack at all.
 */
final readonly class GristCommands
{
    public function __construct(
        private GristSchemaManager $schema,
        private GristQueryRunner $queries,
        private GristWebhookManager $webhooks,
        private GristAttachmentManager $attachments,
    ) {
    }

    #[AsCommand('grist:describe', 'List a Grist application tables, or one table columns')]
    public function describe(
        SymfonyStyle $io,
        #[Argument('Configured application name')] string $application,
        #[Argument('Table id; omit to list tables')] ?string $table = null,
        #[Option('Emit JSON')] bool $json = false,
    ): int {
        if (null === $table) {
            $tables = $this->schema->tables($application);
            $json ? $io->writeln($this->encode($tables)) : $io->listing($tables);

            return Command::SUCCESS;
        }

        $columns = $this->schema->describeTable($application, $table);
        if ($json) {
            $io->writeln($this->encode($columns));

            return Command::SUCCESS;
        }

        $io->title(sprintf('%s.%s', $application, $table));
        $io->table(
            ['Column', 'Type', 'Label', 'Formula'],
            array_map(static fn (array $c): array => [
                $c['id'],
                $c['type'],
                $c['label'],
                '' === $c['formula'] ? '' : mb_substr($c['formula'], 0, 40),
            ], $columns),
        );

        return Command::SUCCESS;
    }

    #[AsCommand('grist:sql', 'Run a read-only SELECT against a Grist document')]
    public function sql(
        SymfonyStyle $io,
        #[Argument('Configured application name')] string $application,
        #[Argument('A SELECT statement; use ? placeholders')] string $sql,
        /** @var list<string> */
        #[Option('Bind a value to the next ? placeholder (repeatable)', name: 'arg')] array $args = [],
        #[Option('Emit JSON')] bool $json = false,
    ): int {
        $rows = $this->queries->sql($application, $sql, $args);
        if ([] === $rows) {
            $io->warning('No rows.');

            return Command::SUCCESS;
        }

        if ($json) {
            $io->writeln($this->encode($rows));

            return Command::SUCCESS;
        }

        $io->table(array_keys($rows[0]), array_map(
            static fn (array $r): array => array_map(
                static fn (mixed $v): string => is_scalar($v) || null === $v ? (string) $v : json_encode($v, JSON_THROW_ON_ERROR),
                $r,
            ),
            $rows,
        ));

        return Command::SUCCESS;
    }

    #[AsCommand('grist:webhooks', 'List the outgoing webhooks on a Grist document')]
    public function webhookList(
        SymfonyStyle $io,
        #[Argument('Configured application name')] string $application,
        #[Option('Emit JSON')] bool $json = false,
    ): int {
        $hooks = array_map(static fn ($w): array => $w->toArray(), $this->webhooks->list($application));
        if ($json) {
            $io->writeln($this->encode($hooks));

            return Command::SUCCESS;
        }

        if ([] === $hooks) {
            $io->warning('No webhooks. Note that a Grist server refuses every URL unless ALLOWED_WEBHOOK_DOMAINS is set.');

            return Command::SUCCESS;
        }

        $io->table(['Name', 'Table', 'Events', 'Watches', 'Ready col', 'Enabled', 'URL'], array_map(
            static fn (array $w): array => [
                $w['name'],
                $w['table'],
                implode(',', $w['eventTypes']),
                [] === $w['watchedColIds'] ? '(any)' : implode(',', $w['watchedColIds']),
                $w['isReadyColumn'] ?? '',
                $w['enabled'] ? 'yes' : 'no',
                $w['url'],
            ],
            $hooks,
        ));

        return Command::SUCCESS;
    }

    #[AsCommand('grist:attachments', 'Show, and optionally switch, where a document keeps attachment bytes')]
    public function attachments(
        SymfonyStyle $io,
        #[Argument('Configured application name')] string $application,
        #[Option('Switch this document to the server external object store')] bool $external = false,
        #[Option('When switching, leave existing attachments where they are')] bool $skipTransfer = false,
    ): int {
        $result = $external
            ? $this->attachments->useExternalStore($application, !$skipTransfer)
            : $this->attachments->status($application);

        $io->definitionList(
            ['store' => $result['store']],
            ...array_map(
                static fn (string $k, mixed $v): array => [$k => is_scalar($v) ? (string) $v : json_encode($v, JSON_THROW_ON_ERROR)],
                array_keys($result['transfer']),
                array_values($result['transfer']),
            ),
        );

        return Command::SUCCESS;
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
