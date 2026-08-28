# API Platform's own MCP support — what would collapse into it

← [Grist as an API Platform resource](api-platform.md)

This bundle hand-writes ten agent/MCP tools. API Platform 4.3 ships an experimental MCP
component. The obvious question is whether the tools can just be resources.

**Answer: one of the ten could, and it is not clearly a win. The other nine cannot.**
Recommendation is to leave them where they are for now.

## How API Platform's MCP works

Opt-in per resource:

```php
#[ApiResource(mcp: ['get_location' => new McpTool(...)])]
```

`ApiPlatform\Mcp\Capability\Registry\Loader` walks the resource name collection, and for each
`McpTool` or `McpResource` builds the input and output JSON schema from the **resource class**
via `SchemaFactory`. `McpTool` extends `HttpOperation`, so it is an operation — same
serializer groups, same security, same state provider.

That is the shape of the question it answers: *a typed operation on a typed record*. It decides
what can be folded in and what cannot.

## Measured against this bundle's tools

| Tool | Could it become an `McpTool`? |
|---|---|
| `grist_upsert_records` | **Yes, per resource.** `Obra`, `Location` and `Artist` would each get a typed write tool with a generated schema — strictly better for a model than today's untyped `array $records`. But it stops being *one* generic tool, and it only covers tables that have a resource class. |
| `grist_sql` | No. Arbitrary `SELECT` over a document has no resource class and no row identity. Its value is precisely that it answers questions nobody modelled. |
| `grist_describe_table` | No. Schema-shaped, not row-shaped. It answers "what columns exist", which is what an agent calls *before* there is a resource to model them with. |
| `grist_list_applications` | No. Same: it is the discovery step that precedes everything. |
| `grist_add_columns` | No, and shouldn't. It mutates the document's structure, not its records. |
| `grist_list_forms`, `grist_upsert_form` | No. A Grist form is a design, not a record. |
| `grist_list_webhooks`, `grist_upsert_webhook` | No. Server configuration. |
| `grist_attachment_store` | No. Storage backend, per document. |

So the overlap is **one tool of ten**, and folding it in trades a generic tool for N typed
ones. That is arguably an improvement for an agent — a generated schema beats a free-form array
— but it is not a consolidation, and it loses coverage of every table nobody has written a DTO
for.

The nine that stay are document-shaped in a way `#[ApiResource]` has no vocabulary for. That is
not a gap in API Platform; those operations are about the container, and API Platform models
the contents.

## Practical blockers, if someone tries it anyway

- `api-platform/mcp` requires `mcp/sdk ^0.6`. **mono is on `0.5.0`**, so this needs a bump
  first. An app already running `symfony/mcp-bundle` 0.12 has `mcp/sdk` 0.7 and is fine.
- The component is marked `@experimental` upstream, in both its README and the attribute
  docblocks.

## Recommendation

Not yet. Revisit when the component drops `@experimental`, and then only for the **write**
tools, where the typed schema is a real gain. The schema, SQL, form and webhook tools stay
hand-written — they are not resources and pretending otherwise would cost coverage to buy
nothing.
