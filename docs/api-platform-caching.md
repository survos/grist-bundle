# Caching

← [Grist as an API Platform resource](api-platform.md)

## It is mandatory, not an optimisation

Grist is a live third-party dependency sitting on the request path. Every read is cached; the
only question is for how long.

There is deliberately no `enabled: false`. "Read Grist on every request" is not a supported way
to run this — it makes every page load depend on another service being up and fast, for data
that a person edits a few times a week.

```yaml
survos_grist:
    api_platform:
        cache_ttl: 900   # the floor, not a target
```

900s is what the WordPress proxy this replaces already ran with. Per-resource override:
`#[GristResource(cacheTtl: 300)]`.

## What is cached

Two entries per resource, in `cache.app`:

| key suffix | holds |
|---|---|
| `rows` | the whole table, narrowed by a declared `where` and sorted by `order` |
| `ids` | row id → natural key, for resolving `Ref` and `RefList` columns |

A resource whose operations override `where` gets one `rows` entry per distinct `where` — the
admin read and the public read are genuinely different sets.

The `ids` map **deliberately ignores `where`.** A closed venue is still the venue an obra hangs
in, and resolving that reference to nothing because the venue is not *published* would corrupt
the record rather than hide it. `where` decides what is listed, not what a reference may name.

Because filters are applied in PHP, a table is read once however many distinct queries arrive
against it — that is the whole trade described in [Reading](api-platform-reading.md).

## Invalidation

In order of preference:

### 1. After a write through the processor — automatic

`GristProcessor` drops the cached reads immediately, so the response body and the next
collection agree rather than waiting out the TTL.

It drops **every** `where` variant, discovered from the resource's own operations. Dropping
only the default entry would leave the admin view showing the row as it was before the write —
exactly the confusion invalidation exists to prevent. The overrides are discovered rather than
passed in, because a caller that has to remember them is a caller that will eventually forget
one.

### 2. By hand — the working mechanism today

```bash
bin/console grist:api:resources             # what is Grist-backed, and its table
bin/console grist:api:refresh               # drop everything
bin/console grist:api:refresh Location      # one resource, by short name or FQCN
bin/console grist:api:refresh --dry-run     # look first
```

Anything that changes the document out of band needs to call this. Given that (3) is blocked,
this is not a convenience.

### 3. From Grist, over a webhook — blocked

`UpsertWebhookTool` and `ListWebhookTool` already declare webhooks, and `watchedColIds` plus
`isReadyColumn` mean a write-back cannot re-trigger the hook. That is the right mechanism.

**It is blocked on `ALLOWED_WEBHOOK_DOMAINS` being set on the Grist server.** Until it is,
every hook URL is refused:

```json
{"error":"Provided url is forbidden"}
```

Comma-separated base domains; subdomains match. `*` allows everything and logs an SSRF warning
unless `GRIST_PROXY_FOR_UNTRUSTED_URLS` is set — prefer naming the domains.

Note also that Grist rejects `http://` except for `localhost`, so the callback endpoint needs
TLS.

## HTTP caching

Independent of the above, and worth setting — the responses are public and change rarely:

```php
new GetCollection(cacheHeaders: ['max_age' => 300, 'shared_max_age' => 900])
```

Behind a CDN this is what actually keeps load off the origin. The server-side cache is what
keeps load off *Grist*; they are different problems and both are worth solving.
