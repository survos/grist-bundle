# Writing

← [Grist as an API Platform resource](api-platform.md)

## The processor

```php
#[ApiResource(provider: GristProvider::class, processor: GristProcessor::class)]
```

Every write is an **upsert matched on the natural key**, never an update of a row id. That is
the same rule the URIs follow, and it is what makes a write replayable against a rebuilt
document.

A `POST` therefore requires the client to supply the key. The server does not invent one:

```
A App\Chijal\Dto\Obra needs a "code" before it can be written: rows are matched on their
natural key, which the client supplies rather than the server inventing one.
```

The processor sends every property marked `#[GristColumn(writable: true)]` — the default. For a
`PATCH` the ones the client did not send hold the values the provider just read, so they round
trip unchanged. Formula and computed columns **must** be `writable: false`: Grist rejects a
write to one and it fails the whole upsert, not just that field.

After the write it drops the cached reads — see [Caching](api-platform-caching.md#invalidation).

## Deletes are refused

`GristClient` has no delete, and adding one here would be the wrong place to decide that a
curated row may vanish over HTTP. Retire rows with a status column and a `#[GristResource]`
`where`, the way `Location` does — the row stays in the document for the curators and leaves
the public collection.

## API Platform cannot be the only write path

**Grist's own grid is a second write path, and we send editors to it deliberately** —
wp-chijal renders "Edit on Grist" links pointing at specific rows.

So `#[Assert\...]` in a resource is **advisory** for anything a Grist user can also do. A
constraint enforced in the processor is enforced for callers of the API and for nobody else.
The same row can be edited to violate it thirty seconds later through the grid, and nothing in
PHP will ever hear about it.

This is not solvable at this layer, and the design does not pretend otherwise. Do not build as
though a processor-side rule holds. Real invariants belong in one of two places:

- **In Grist.** A formula column, a data validation rule, a `Choice` column that cannot hold an
  unlisted value. These bind both paths, because they *are* the document.
- **In a reconciliation.** A scheduled read that reports rows violating a rule it cannot
  enforce. Slower, and honest about being after the fact.

Validation in a processor is still worth having, for the error message a caller gets. It is not
worth mistaking for an invariant.

The same caveat applies to anything derived from a write — a search index, a generated file, a
notification. If it hangs off the processor it will be wrong whenever an editor uses the grid,
which is most of the time. Hang it off a Grist webhook instead, once
[`ALLOWED_WEBHOOK_DOMAINS`](api-platform-caching.md#3-from-grist-over-a-webhook--blocked) is
unblocked.

## Scoped credentials

This is the reason the service exists at all. Grist issues one kind of key and it can write, so
the alternative to a service is a writable credential inside every installed PWA.

What exists today is the shape of the answer: a public `GetCollection` with a `read:public`
group, and admin operations behind `security:` with a wider group and HTTP basic auth. The one
Grist key stays server-side.

What is still missing is **per-consumer read-only tokens** — so that revoking the app's access
does not mean rotating the key every other consumer uses. Basic auth stands in until they
exist; it is a placeholder, not the design.
