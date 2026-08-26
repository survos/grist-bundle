# survos/grist-bundle

Grist forms, schema, queries, webhooks — as Symfony services and as agent/MCP tools.

Grist is a spreadsheet whose documents are SQLite files with a REST API. That makes it
a good **hand-curation surface**: a non-developer edits records in a familiar grid, and
an app reads them over the API instead of parsing a spreadsheet export.

This bundle exists so that surface can be *declared* — forms, columns, and webhooks
described in code and reconciled — rather than clicked together and then re-clicked on
the next document.

## Install

```bash
composer require survos/grist-bundle
```

Connections and applications come from `survos/record-store-bundle`:

```yaml
# config/packages/survos_record_store.yaml
survos_record_store:
    connections:
        prod:
            driver: grist
            options:
                base_uri: '%env(GRIST_HOST)%/api'
                token: '%env(GRIST_API_KEY)%'
    applications:
        pgsc:
            connection: prod
            id: '%env(GRIST_DOC_ID)%'
            tables:
                Artists:   { id: Artists }
                Locations: { id: Locations }
                Obras:     { id: Obras }
```

`application` in every tool below is the key under `applications` — `pgsc` here.

## Services

| Service | Does |
|---|---|
| `GristSchemaManager` | list tables, describe columns, **additively** add columns |
| `GristQueryRunner` | read-only SQL, parameterized |
| `GristWebhookManager` | declare outgoing webhooks, matched by name |
| `GristFormManager` | create/update/publish Grist forms |
| `GristApplicationLocator` | resolve an application name to `[reference, client]` |

## Agent / MCP tools

Registered only when **both** `symfony/ai-agent` and `mcp/sdk` are installed, so an app
that only wants the services doesn't pull in the agent stack.

| Tool | Purpose |
|---|---|
| `grist_list_applications` | discover configured applications and their tables — call first |
| `grist_describe_table` | columns with type, label, and formula |
| `grist_sql` | read-only SELECT, with `?` placeholders |
| `grist_add_columns` | add missing columns; never drops or retypes |
| `grist_upsert_records` | write rows matched on a natural key |
| `grist_list_forms` / `grist_upsert_form` | form design |
| `grist_attachment_store` | where attachment bytes live; switch to object storage |
| `grist_list_webhooks` / `grist_upsert_webhook` | event wiring |

### Exposing them

The tools are plain invokable services carrying both `#[AsTool]` (symfony/ai-agent)
and `#[McpTool]` (mcp/sdk). How they reach a client depends on which transport the
app installs:

- **In-process agent** — `symfony/ai-agent` picks up `#[AsTool]` services via its
  toolbox; nothing further to do.
- **MCP server** — discovery is directory-based: `Mcp\Capability\Discovery\Discoverer`
  scans `(basePath, directories)` for the attribute, so the server must be pointed at
  this bundle's `src/Tool`. `symfony/mcp-bundle` wires that up; consult its config for
  the current key names rather than copying a snippet from here.

Note that discovery reads **docblocks** to build each tool's input schema, so the
`@param` lines on `__invoke` are not decoration — they are what a model sees.

### Why `grist_sql` matters

A document is SQLite, so `Ref` columns join. One query returns rows already resolved
to labels, instead of fetching rows and then looking up every row id:

```sql
select o.Code, o.Title, a.Name as Artist, l.Name as Location
  from Obras o
  left join Artists a on a.id = o.Artist
  left join Locations l on l.id = o.Location
 where o.Exhibition = ?
```

Grist rejects anything but `SELECT` server-side; `GristQueryRunner` also rejects it
before the round-trip, including verbs hidden behind a leading comment.

### Why `watchedColIds` matters

The usual failure of "enrich a row on update" is a loop: the callback writes a derived
column, that write fires the webhook, forever.

```php
$webhooks->upsert(new WebhookBlueprint(
    application: 'pgsc',
    table: 'Obras',
    name: 'pgsc-enrich',
    url: 'https://chijal.org/grist/obra/enrich',
    eventTypes: ['update'],
    watchedColIds: ['YoutubeUrl', 'Photo'], // human-edited columns ONLY
    isReadyColumn: 'Ready',                 // half-filled rows never fire
));
```

Because the derived columns aren't watched, writing them back can't re-trigger. Grist
provides both guards natively — you don't build them.

`upsert` matches on `name`, so re-applying a blueprint updates in place. Column ids are
validated first: Grist accepts a wrong `watchedColIds` silently and the hook then simply
never fires, which is close to undebuggable later.

## Console

The same capabilities, for checking what an agent did — and usable in an app that
never installs the agent stack:

```bash
bin/console grist:describe pgsc                 # tables
bin/console grist:describe pgsc Obras           # columns, types, formulas
bin/console grist:sql pgsc 'select Code, Title from Obras where Exhibition = ?' --arg=aniversario
bin/console grist:webhooks pgsc
bin/console grist:attachments pgsc              # where the bytes live
bin/console grist:attachments pgsc --external   # move them to object storage
```

Every command takes `--json`.

## Attachments and object storage

With `GRIST_DOCS_MINIO_*` set on the server, attachment bytes go to S3-compatible
storage keyed by content hash:

```
docs/attachments/{docId}/{sha1}.{ext}
```

Content-addressed, so identical uploads dedupe and an image server can read the key
directly — the app never proxies bytes back out of Grist.

**External storage is opt-in per document.** Setting the server env vars changes
nothing about documents that already exist; the decision is made at creation.
`grist:attachments <app> --external` switches a document over and moves existing
attachments across. The document *file* itself cannot be moved after the fact — if
that matters, recreate the document.

## Deployment gotchas

- **`ALLOWED_WEBHOOK_DOMAINS` must be set on the Grist server** or *every* webhook URL is
  refused with `{"error":"Provided url is forbidden"}`. Comma-separated base domains;
  subdomains match. `*` allows everything and logs an SSRF warning unless
  `GRIST_PROXY_FOR_UNTRUSTED_URLS` is set — prefer naming the domains.
- **`http://` is rejected except for `localhost`.** Callback endpoints need TLS.
- **`X-Requested-With: XMLHttpRequest`** is required on non-JSON requests; the client
  sends it.
- **Attachments columns** store bytes in the server's object storage when
  `GRIST_DOCS_MINIO_*` is configured, keyed by content hash. That storage decision is
  made **per document at creation** — setting the env vars later does not migrate
  existing documents.

## Tests

```bash
vendor/bin/phpunit -c phpunit.xml.dist
```

Unit tests cover blueprint validation, the SQL guard, and the tool contract (names
match between `#[AsTool]` and `#[McpTool]`, are prefixed and unique, and descriptions
are substantial enough for a model to select on).
