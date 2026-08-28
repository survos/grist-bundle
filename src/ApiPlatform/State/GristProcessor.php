<?php

declare(strict_types=1);

namespace Survos\GristBundle\ApiPlatform\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Survos\GristBundle\ApiPlatform\Metadata\GristResourceMetadataFactory;
use Survos\RecordStore\Exception\UnsupportedRecordStoreOperation;
use Survos\RecordStore\Model\Record;
use Survos\RecordStore\Model\UpsertRequest;
use Survos\RecordStore\Registry\RecordStoreRegistry;

/**
 * Writes a resource back to Grist, matched on its natural key.
 *
 * Every write is an upsert on the identifier column rather than an update of a row id. That
 * is the same rule the URIs follow, and it is what makes a write replayable against a
 * rebuilt document.
 *
 * It is not, and cannot be, the only write path -- see docs/api-platform.md. Grist's own grid
 * is a second one, and we send editors to it deliberately.
 *
 * @implements ProcessorInterface<mixed, object>
 */
final readonly class GristProcessor implements ProcessorInterface
{
    public function __construct(
        private RecordStoreRegistry $registry,
        private GristResourceMetadataFactory $factory,
        private GristRecordFetcher $fetcher,
        private GristHydrator $hydrator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): object
    {
        if (!is_object($data)) {
            throw new \InvalidArgumentException('The Grist processor writes objects.');
        }

        if ($operation instanceof DeleteOperationInterface) {
            // GristClient has no delete, and adding one here would be the wrong place to
            // decide that a curated row may vanish over HTTP. Retire rows with a status
            // column and a #[GristResource] `where`, the way Locations does.
            throw new UnsupportedRecordStoreOperation(sprintf(
                'Deleting %s over the API is not supported: rows are retired in Grist, not removed by a caller.',
                $data::class,
            ));
        }

        $metadata = $this->factory->create($data::class);
        $fields = $this->hydrator->toGristFields($metadata, $data);
        $key = $fields[$metadata->identifier->column] ?? null;

        if (!is_scalar($key) || '' === trim((string) $key)) {
            throw new \InvalidArgumentException(sprintf(
                'A %s needs a "%s" before it can be written: rows are matched on their natural key, which the client supplies rather than the server inventing one.',
                $data::class,
                $metadata->grist->identifier,
            ));
        }

        $table = $this->fetcher->table($metadata);
        $this->registry->adapterFor($table)->upsert($table, new UpsertRequest(
            records: [new Record($fields)],
            keyFields: [$metadata->identifier->column],
        ));

        // The write went to Grist; the cached read still shows the old row. Drop it now so the
        // response body and the next collection agree, rather than waiting out the TTL.
        $this->fetcher->invalidate($metadata->resourceClass);
        // The hydrator's per-request index was built from the rows that were just replaced.
        $this->hydrator->reset();

        return $data;
    }
}
