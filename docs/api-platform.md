# Grist as an API Platform resource

Grist is a good place for a person to curate records and a bad place to point an app at.
It has no anonymous access to a private document, so "read it from the app" means shipping
an API key inside a client-side PWA — and the only key Grist issues can also write. What is
needed between the two is a service that holds the one credential, decides what is public,
and caches.

This is that, as API Platform state providers.

| Page | |
|---|---|
| **[Defining a resource](api-platform-resources.md)** | `#[GristResource]`, `#[GristColumn]`, serializer groups, natural-key identifiers |
| **[Reading](api-platform-reading.md)** | which filters push down to Grist and which do not, sorting, pagination, `where` as a publication rule |
| **[Caching](api-platform-caching.md)** | why it is mandatory, and the three ways to invalidate — one of which is blocked |
| **[Writing](api-platform-writing.md)** | the processor, and why API Platform **cannot** be the only write path |
| **[API Platform's own MCP support](api-platform-mcp.md)** | what would collapse into it, and the recommendation |

## Why not a hand-written proxy

There was one, in WordPress, and it worked. It is worth saying exactly what it could not do,
because those three things are the whole reason for this:

- **Field exclusion was an allow-list.** A `PUBLIC_FIELDS` array, maintained by hand. Correct
  on the day it was written; wrong the first time someone adds a column and forgets it. The
  endpoint *before* that one had no list at all and published 19 email addresses and 23 phone
  numbers to anonymous callers. A `#[Groups]` travels with the property, so the default for a
  new property is invisible.
- **There was one audience.** No way to say "these fields to the app, those to a curator",
  short of a second endpoint duplicating the first.
- **It was emulating Hydra anyway.** It hand-built `{totalItems, member}` with a comment
  explaining that this is API Platform's shape, because `survos/js-twig-bundle`'s
  `dexieDatabase.js` calls `bulkAdd(data.member)`. Emulating a framework is the signal to
  adopt it.

## Install

`api-platform/core` is a **suggest**, not a require. This bundle is used for console commands
and MCP tools by apps with no HTTP API at all, and it stays installable without it — the
provider, processor and commands register only when `ApiPlatform\State\ProviderInterface`
exists.

```bash
composer require survos/grist-bundle api-platform/core
```

Connections and applications come from `survos/record-store-bundle` as usual:

```yaml
# config/packages/survos_record_store.yaml
survos_record_store:
    connections:
        chijal:
            driver: grist
            options:
                base_uri: '%env(GRIST_HOST)%/api/'
                token: '%env(GRIST_API_KEY)%'
    applications:
        chijal:
            connection: chijal
            id: '%env(GRIST_CHIJAL_DOC_ID)%'
            tables:
                artists:   { id: Artists }
                locations: { id: Locations }
                obras:     { id: Obras }
```

```yaml
# config/packages/survos_grist.yaml
survos_grist:
    api_platform:
        cache_ttl: 900   # seconds; per-resource override on #[GristResource]
        max_rows: 5000   # refuse rather than truncate
```

If any operation uses `security:`, install `symfony/expression-language` too. Without it the
operation fails with a 500 at request time. It fails loudly rather than silently permitting,
but it fails.

## A resource, end to end

```php
#[ApiResource(
    operations: [
        new GetCollection(normalizationContext: ['groups' => ['location:public']]),
        new Get(uriTemplate: '/locations/{code}', normalizationContext: ['groups' => ['location:public']]),
        new GetCollection(
            uriTemplate: '/admin/locations',
            normalizationContext: ['groups' => ['location:public', 'location:admin']],
            security: "is_granted('ROLE_ADMIN')",
            extraProperties: ['grist_where' => []],
        ),
    ],
    provider: GristProvider::class,
    processor: GristProcessor::class,
)]
#[GristResource(
    application: 'chijal',      // the record-store application name, not the document id
    table: 'locations',         // the key under that application's `tables`
    identifier: 'code',         // the PROPERTY holding the natural key
    where: ['Status' => ['activo']],
    order: ['Name'],            // Grist's own sort syntax: `Name`, `-Year`
)]
final class Location
{
    #[ApiProperty(identifier: true)]
    #[Groups(['location:public'])]
    public string $code = '';

    #[GristColumn(name: 'Name')]
    #[Groups(['location:public', 'location:write'])]
    public string $label = '';

    #[GristColumn(references: Obra::class)]           // RefList -> list of Obra codes
    #[Groups(['location:public'])]
    public array $obras = [];

    #[Groups(['location:admin', 'location:write'])]   // never public
    public ?string $phone = null;
}
```

`#[GristResource]` is the only new concept. Everything else is stock API Platform.

## Console

```bash
bin/console grist:api:resources            # which resources are Grist-backed, and their tables
bin/console grist:api:refresh [Resource]   # drop the cached reads; --dry-run to look first
```

## Classes

| Class | |
|---|---|
| `Survos\GristBundle\Attribute\GristResource` | binds a class to application + table + identifier |
| `Survos\GristBundle\Attribute\GristColumn` | property ↔ column, references, filterable, sortable, writable |
| `…\ApiPlatform\State\GristProvider` | item and collection reads |
| `…\ApiPlatform\State\GristProcessor` | upsert on the natural key |
| `…\ApiPlatform\State\GristPaginator` | `PaginatorInterface` over record-store's `RecordPage` |
| `…\ApiPlatform\State\GristRecordFetcher` | the one cached table read |
| `…\ApiPlatform\State\GristHydrator` | row ↔ object, both directions |

Reads go through `GristAdapter` and `survos/record-store`'s `RecordQuery` / `RecordPage`
rather than touching `GristClient` directly. Two properties of that layer shape the design and
are worth knowing before reading the code:

- **`GristAdapter::query()` rejects a non-zero offset**, because Grist's records endpoint has
  none. That is one of the two reasons the provider reads whole tables and paginates in PHP;
  see [Reading](api-platform-reading.md).
- **`RecordPage::$total` comes back null**, because the adapter cannot know whether it saw the
  whole table. `GristProvider` can, because it holds the whole table, so it builds its own
  `RecordPage` with a real count. `GristPaginator` refuses a null total rather than letting it
  serialize as `totalItems: 0` — an empty collection and a missing number are different
  things, and only one of them is true.
