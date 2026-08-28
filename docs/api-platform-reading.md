# Reading: what pushes down to Grist, and what does not

← [Grist as an API Platform resource](api-platform.md)

## The read path, plainly

1. Fetch the **whole table** once, narrowed by the resource's declared `where`, sorted by its
   declared `order`. Cache it.
2. Filter, sort and paginate that set **in PHP**.

So: **only the resource's declared `where` and `order` reach Grist.** Everything a caller sends
is applied against the cached set.

## Why not push caller filters down

`GristClient::queryRecords()` does take a filter argument, and forwarding query parameters into
it would be easy. The reason not to is the cache.

A filter-shaped cache key means every distinct combination of query parameters is its own cache
entry and its own round-trip to a third-party service on the request path. For a hand-curated
table of a few dozen rows, one cached full read beats N narrow live ones — and the resource's
`where` is *already* pushed down, so the rows that must never be public never enter the cache
in the first place.

There is a second reason the design lands here anyway: `GristAdapter::query()` rejects a
non-zero offset, because Grist's records endpoint has none. Server-side pagination is not
available to build on even if we wanted it.

## The full read is bounded, not silent

A full-table read is the only mode, and it is checked rather than hoped for.

`max_rows` is a **refusal**:

```
Grist table "chijal.locations" holds more than 5000 rows, which is more than the API Platform
provider reads into memory. Narrow it with a #[GristResource] `where`, put it behind a Grist
view, or query it through GristQueryRunner instead of raising maxRows.
```

The provider asks Grist for `maxRows + 1` so a full page is distinguishable from a truncated
one. Past the ceiling it throws. A collection that quietly stops at row 5000 is the failure
mode this exists to prevent — raising the ceiling is not the fix, and the message says so.

For a table that has genuinely outgrown it, `GristQueryRunner` reads the document's SQL
endpoint, which *does* support `LIMIT`/`OFFSET` and joins across `Ref` columns.

## Caller-supplied filters

```
GET /api/locations?barrio=Cerrillo
GET /api/obras?artistCode=az&exhibition=2025_09_expo
GET /api/obras?order[year]=desc
```

- Only properties marked `#[GristColumn(filterable: true)]` participate. An unknown or unmarked
  parameter is **ignored**, never mistaken for a column — so a typo cannot widen a collection.
- Reference properties filter by **natural key**: `?locationCode=libjo`, not `?locationCode=5`.
  The value is translated to a row id once, rather than dereferencing every row to compare it.
- A key nobody has matches nothing, rather than being dropped and silently widening the result.
- Multiple values behave as `IN`.
- `order[property]=asc|desc` needs `#[GristColumn(sortable: true)]`, same rule. Numeric columns
  compare numerically; everything else case-insensitively.

## `where` is a publication rule, not a filter

The live example: Grist marks each `Location` activo or inactivo, and **28 of 41 are
inactivo** — closed venues and past hosts. The endpoint that preceded all of this ignored the
column and published every one, so the site and the app were sending people to closed doors.

That rule belongs in `where` rather than in a filter, because **a filter can be omitted**. A
caller who leaves `?status=activo` off gets everything; a caller cannot omit a declared `where`.
Rows it excludes never enter the cache, so no combination of parameters reaches them — and
neither does a direct item URL:

```
GET /api/locations             -> totalItems: 13   (41 rows in the document)
GET /api/locations/cora        -> 404              (inactivo)
GET /api/locations?status=inactivo -> 0 rows
```

The test is for `activo` specifically rather than "not inactivo": a row with no status set has
not been *declared* open, and guessing in favour of publishing is the wrong way round when the
cost is someone travelling across town.

### Why not a Grist view

A view could express the same rule and would move it to where the curators are, which is
usually better. It is in the resource instead because a view hides those rows from the curators
too — and a curator whose job is to reopen a venue has to see it.

An operation overrides the rule:

```php
new GetCollection(
    uriTemplate: '/admin/locations',
    security: "is_granted('ROLE_ADMIN')",
    extraProperties: ['grist_where' => []],   // [] narrows nothing
)
```

```
GET /api/admin/locations   (as ROLE_ADMIN)  -> totalItems: 41
GET /api/admin/locations/cora               -> 200, status: "inactivo"
```

An operation that says nothing keeps the resource's `where`, so forgetting to think about it is
safe. Each distinct `where` gets its own cache entry, which is the point — the admin read and
the public read are different sets, and [invalidation](api-platform-caching.md) drops both.

## Pagination

`GristPaginator` implements API Platform's `PaginatorInterface` over record-store's
`RecordPage`, which already models the page's records plus the size of the whole set — exactly
what Hydra needs.

Rows hydrate **lazily**, as the paginator is iterated. A collection that is never iterated —
a `HEAD`, a cache hit — pays for none of them.

The resulting envelope is the one `survos/js-twig-bundle`'s `dexieDatabase.js` expects, because
it calls `bulkAdd(data.member)`:

```json
{
  "@context": "/api/contexts/Location",
  "@id": "/api/locations",
  "@type": "Collection",
  "totalItems": 13,
  "member": [ … ]
}
```

For a client that syncs a whole collection in one request, raise
`pagination_maximum_items_per_page` and set `paginationClientItemsPerPage: true` on the
operation, rather than disabling pagination — a bounded response is still worth having.
