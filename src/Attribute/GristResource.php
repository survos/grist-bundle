<?php

declare(strict_types=1);

namespace Survos\GristBundle\Attribute;

/**
 * Binds a PHP class to a table in a configured Grist application.
 *
 * The class is the contract; Grist is where the rows live. That direction matters: a
 * dynamic "expose every column" resource would republish whatever a curator adds to the
 * document next, which is how an earlier endpoint came to serve 19 email addresses to
 * anonymous callers. A property has to exist in PHP, and carry a serializer group, before
 * anyone outside can see it.
 *
 * Pairs with #[ApiResource]; this attribute carries only what API Platform has no concept
 * of -- which document, which table, and which column holds the natural key.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class GristResource
{
    /**
     * @param string $application the record-store application name, as configured under
     *                            survos_record_store.applications -- not the document id
     * @param string $table       the table name as configured under that application's `tables`
     * @param string $identifier  the property (not the Grist column) holding the natural key.
     *                            Row ids are deliberately not usable here: a Grist row id means
     *                            nothing outside the document and does not survive a rebuild,
     *                            so /locations/bioes, never /locations/3.
     * @param array<string, list<bool|float|int|string|null>> $where a filter applied to every
     *                            read of this table, pushed down to Grist. This is a publication
     *                            rule, not a caller's choice: rows excluded here never enter the
     *                            cache and cannot be reached by omitting a query parameter.
     * @param list<string> $order default sort, pushed down to Grist. Grist's own syntax:
     *                            `Name` ascending, `-Year` descending. Column ids, not properties.
     * @param int|null $cacheTtl  seconds; null uses the bundle default. Grist is a live
     *                            third-party dependency on the request path, so this is not
     *                            tuning -- see docs/api-platform.md.
     * @param int|null $maxRows   refuse to serve rather than silently truncate. null uses the
     *                            bundle default.
     */
    public function __construct(
        public string $application,
        public string $table,
        public string $identifier,
        public array $where = [],
        public array $order = [],
        public ?int $cacheTtl = null,
        public ?int $maxRows = null,
    ) {
        foreach ([$this->application, $this->table, $this->identifier] as $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException('A #[GristResource] needs a non-empty application, table, and identifier.');
            }
        }

        foreach ($this->where as $column => $values) {
            if ('' === trim((string) $column) || [] === $values) {
                throw new \InvalidArgumentException('Each #[GristResource] `where` entry needs a column and at least one allowed value.');
            }
        }
    }
}
