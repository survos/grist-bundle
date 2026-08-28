# Defining a Grist-backed resource

← [Grist as an API Platform resource](api-platform.md)

## The class is the contract

Resource definition is **DTO-driven, not dynamic**. A property has to exist in PHP, and carry a
serializer group, before anyone outside can see it.

A provider that reflected the document's columns instead would republish whatever a curator
adds next — which is exactly the failure this replaces. Grist is where the rows live; the class
decides what a row *is* on the outside.

This also matches how the tables get made in the first place: `scs`/SchemaSteward generates
Grist tables from DTOs. Same direction, one more consumer of it.

## `#[GristResource]`

```php
#[GristResource(
    application: 'chijal',
    table: 'locations',
    identifier: 'code',
    where: ['Status' => ['activo']],
    order: ['Name'],
    cacheTtl: 300,
    maxRows: 200,
)]
```

| | |
|---|---|
| `application` | the record-store **application name** under `survos_record_store.applications` — not the Grist document id |
| `table` | the key under that application's `tables`, not necessarily the Grist table id |
| `identifier` | the **property** holding the natural key. Not a column, and never a row id |
| `where` | a filter on every read, pushed down to Grist. A publication rule — see [Reading](api-platform-reading.md#where-is-a-publication-rule-not-a-filter) |
| `order` | default sort, pushed down. Grist's own syntax: `Name` ascending, `-Year` descending. **Column** ids |
| `cacheTtl` | seconds; overrides the bundle default for this resource |
| `maxRows` | rows above which the provider refuses to serve rather than truncate |

## `#[GristColumn]`

Optional. Without it a property maps to `ucfirst($property)`, which is already right for
`code → Code` and `birthYear → BirthYear`. Reach for it when the names diverge, when a column
is a reference, or when a column must not be written.

```php
#[GristColumn(name: 'Tagline')]                  public ?string $slogan = null;
#[GristColumn(references: Obra::class)]          public array $obras = [];
#[GristColumn(filterable: true, sortable: true)] public ?string $barrio = null;
#[GristColumn(writable: false)]                  public ?string $computedTotal = null;
```

`filterable` and `sortable` default **closed**: a property nobody marked is not a query
parameter, and one naming it is ignored rather than honoured. `writable` defaults **open**,
because most columns are writable and a formula column that is wrongly written fails loudly
rather than leaking.

## Field exclusion is `#[Groups]`, not a list

Nothing in this bundle decides what is published. `normalizationContext` on each operation
does, and a property with no group in that context is not serialized. The consequence worth
internalizing: **a new property is invisible by default**, which is the opposite of the
allow-list this replaces.

Three axes, all needed:

| | controls |
|---|---|
| `normalizationContext` groups | what a caller can **read** |
| `denormalizationContext` groups | what a caller can **set** |
| `#[GristColumn(writable:)]` | what the processor **sends to Grist** |

The last one is not redundant with the second. The processor writes every writable mapped
property, not only the ones a client supplied — for a `PATCH` the rest hold the values the
provider just read, so they round-trip unchanged. Formula and computed columns must be
`writable: false`, because Grist rejects a write to one and it fails the **whole upsert**, not
just that field.

## Per-operation audiences

The same class serves both audiences; the operations differ, not the resource.

```php
new GetCollection(normalizationContext: ['groups' => ['location:public']]),
new GetCollection(
    uriTemplate: '/admin/locations',
    normalizationContext: ['groups' => ['location:public', 'location:admin']],
    security: "is_granted('ROLE_ADMIN')",
    extraProperties: ['grist_where' => []],
),
```

`security:` needs `symfony/expression-language` installed or the operation 500s at request
time. It fails loudly rather than silently permitting — but check for it when adopting this.

## Identifiers are natural keys

`/locations/bioes`, never `/locations/3`. A Grist row id means nothing outside the document and
does not survive a rebuild of it, so it never appears in a payload — including inside
references, where `#[GristColumn(references: Obra::class)]` turns a `Ref` or `RefList` into the
referenced resource's natural key.

Mark the property with `#[ApiProperty(identifier: true)]` so API Platform builds IRIs from it.

### A natural key may contain a dot

`alejandra.kas07` is a real one. API Platform's default item route is
`/artists/{code}.{_format}`, and Symfony's URL generator excludes the following separator from a
variable's default requirement — so generating that IRI throws, and the whole **collection**
400s, not just the offending row.

Give item operations an explicit `uriTemplate` with no format suffix:

```php
new Get(uriTemplate: '/artists/{code}', normalizationContext: [...]),
```

Content negotiation still works through the `Accept` header.

### A row with no key is dropped

It cannot have a URI, and letting API Platform fail to build one takes down the entire
response. A half-entered row is a normal state in a curation grid, so this is not fatal.

It is logged at warning level, naming the table, the identifier column and the row ids —
because a silently short collection is how a typo in the key column becomes "why is that venue
missing from the app".

## Value mapping

| Grist | PHP |
|---|---|
| `Text`, `Choice` | `string`, or a `BackedEnum` if the property is typed as one |
| `Int`, `Numeric`, `Bool` | `int`, `float`, `bool` |
| `Date`, `DateTime` | `DateTimeImmutable` (Grist stores a unix timestamp) |
| `Ref` | the referenced resource's natural key, with `references:` |
| `RefList` — `['L', 3, 7]` | a list of natural keys; the leading `'L'` is the type marker, not a row |
| a cell error — `['E', 'ValueError']` | `null`, or `[]` for a list. It is not a value |
| an absent column | `null` — never a coerced empty string. "Not set" and "set to empty" are different |

A reference pointing at a row that no longer exists resolves to `null` rather than to the row
id, and writing a natural key nobody has throws rather than silently pointing at nothing.

Both hydration directions live in one class, `GristHydrator`, because they have to agree about
exactly one thing: a reference is a natural key on the outside and a row id on the inside, and
the translation has to be reversible or a read-modify-write silently repoints the row.

## Both DTO shapes work

Public properties with defaults on the property, and promoted constructor properties with
defaults on the parameter (the `scs` shape) both hydrate. The hydrator goes *through* the
constructor when there is one — `newInstanceWithoutConstructor()` would leave every promoted
property with a parameter default uninitialized.
