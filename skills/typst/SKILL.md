---
name: typst
description: "When the user asks for a typeset document, a PDF report, a slide deck, a structured invoice or any other printed artifact rendered from a programmatic source; OR when a workspace task produces structured data (a bill of materials, a meeting summary, a chart of accounts) that the user wants in a presentable form. Use the `typst_render` tool to compile Typst source to PDF/PNG/SVG, `typst_inspect` for fast error-only feedback before rendering, and `typst_resources` to manage the per-principal font and example libraries. Recommended tools: typst_render, typst_inspect, typst_resources."
license: Apache-2.0
metadata:
  author: spora-ai
  version: "1.0"
  allowedByDefault: false
  requiresTools: "typst_render,typst_inspect,typst_resources"
---

# Typst

You are an agent that produces typeset documents using Typst. Treat rendering as a structured iteration: write a draft, inspect for errors, render, inspect the output, iterate. **Never guess at Typst syntax** — when in doubt, write a tiny fragment and use `typst_inspect` first; only invoke `typst_render` once the source parses cleanly.

## When to use this skill

Trigger on any of:

- "Make me a PDF", "Generate a report", "Typeset this", "Render this as a slide"
- "Compile this Typst code", "Run the .typ file through Typst"
- "I have structured data — give me a presentable document"
- "Check whether this Typst source is valid" (use `typst_inspect`, no rendering needed)

Do NOT use this skill for:

- One-off prose edits to an existing markdown file (use `write_file` / `edit_file`).
- Generating a chart as an image only (use a chart-producing tool, not Typst).
- WYSIWYG document editing — Typst is a markup language, not a word processor.

## Tool selection

Three tools, three jobs:

| Tool | Purpose | When |
| --- | --- | --- |
| `typst_inspect` | Run the inspector on a source; return structured errors and warnings only | First pass on a new source. Cheap; no media-derivative row is created. |
| `typst_render` | Compile the source to PDF/PNG/SVG and persist it as a media-derivative | When the inspector returns clean, or when the operator has approved a known-imperfect render. |
| `typst_resources` | List / write / delete per-principal fonts and example templates | When the user wants to upload a brand font or save a reusable template. |

For routine compiles, the flow is `typst_inspect` → fix → `typst_render`. Skip the inspect step when the user has already iterated and the source is small enough to inspect inline.

## Source location — inline vs file

`typst_render` and `typst_inspect` accept the source in one of two ways:

```jsonc
// inline — small, ephemeral, freshly authored
{ "source": "= Hello\n", "format": "pdf" }

// file — a media asset id previously uploaded as .typ
{ "file": "01HXYZ...", "format": "png", "page": 0 }
```

Default to inline. Switch to `file` when:

- The user uploaded a `.typ` asset specifically to iterate on.
- The source is bigger than ~4 KB (paste limit).
- You want the natural-key idempotency of `media_derivatives` (re-rendering the same `file` with the same `format` refreshes the existing row instead of stacking duplicates).

Inline sources are stored as transient `text/x-typst` parent rows so the derivative has a `parent_id` to attach to. The parent row carries the source bytes in `data_url` mode and is invisible to the media library's LIST endpoint (it's marked `plugin_slug='spora-plugin-typst'`, `tool_name='typst.render'`).

## Format choice

| `format` | Use when | Tool result shape |
| --- | --- | --- |
| `pdf` (default) | The deliverable IS the PDF. Surfaces in chat as a `[Open PDF](url)` link with a first-page PNG preview alongside. | `application/pdf` |
| `png`  | The deliverable is an image — slides, social graphics, inline chat preview. One page per call. | `image/png` (with `width` + `height`) |
| `svg` | The user wants a vector graphic that scales, or wants to embed into another document. | `image/svg+xml` |

PDF renders also produce a PNG companion (the first page) so the chat UI can preview without opening the PDF. The companion is stored as a sibling derivative under the same parent — visible in the Versions UI as "PNG".

## Page parameter

For `png` and `svg`, `page` is 0-indexed. Out-of-range values are clamped to the document's last page, not rejected. Multi-page documents are accessed one page per call.

For `pdf`, `page` is ignored — the entire document is rendered.

## DPI parameter

Only meaningful for `png`. Default `144`. Range `36–600`. Lower DPI for thumbnails, higher for print.

## Error handling

When the inspector reports errors, the producer refuses to render — the tool's `error` content lists every error diagnostic with one bullet per message. Fix the source, re-call.

When the inspector reports warnings only, the producer still renders — the warnings appear in the tool's `ok` content so you can decide whether to iterate.

When the producer throws an unrelated exception (font unreadable, invalid source bytes), the tool returns the exception's message in `error`. The most common cause for fresh installs is missing tier-2 fonts — list them with `typst_resources(action="resources_list", kind="font")` and either upload the missing one or fall back to Inter.

## Tool result shape

Successful renders return:

```json
{
  "derivative_id": "01HXYZ...",
  "asset_url": "/api/v1/assets/01HXYZ....pdf",
  "format": "pdf",
  "mime": "application/pdf",
  "size": 6532,
  "width": null,
  "height": null
}
```

`width`/`height` are populated only for `png` (PNG image dimensions). `size` is the derivative's byte count. The `asset_url` is stable across re-renders — calling `typst_render` again with the same `(file, format)` tuple updates the existing row's bytes but keeps the same id, so URLs the operator has bookmarked stay valid.

## Typst syntax primer (the minimum you need)

Use Typst's markup, not LaTeX, not Markdown.

```typst
= Heading 1
== Heading 2
=== Heading 3

#lorem(20)        // 20 words of Lorem ipsum
*bold* / _italic_ / `code`

#let name = "World"
Hello, #name!

#for x in range(1, 4) [
  - bullet #x
]

#table(
  columns: 3,
  [a], [b], [c],
  [1], [2], [3],
)
```

Common pitfalls:

- `= Heading + #unclosed raw` fails to parse. Match every `[` with `]`, every `#(` with `)`.
- `pagebreak()` cannot appear inside a `for`/`while` content block — put it at top level only.
- Math is `#expr` mode with TeX-like syntax: `$x^2 + y^2 = z^2$` for inline, `$ ... $` block form same syntax (no `\[...\]`).
- The plugin ships **Inter OFL** (Regular + Bold) by default — use `font: ("Inter",)` to pick it explicitly.

## Resources

Three resource kinds, two tiers each:

| Kind        | Purpose                                                 | Tier 1 (skill-shipped, read-only)             | Tier 2 (principal, writable)                  |
|-------------|---------------------------------------------------------|----------------------------------------------|------------------------------------------------|
| `font`      | Custom fonts (`.ttf`/`.otf`/`.woff`/`.woff2`)            | `<plugin>/skills/typst/fonts/` (Inter OFL)   | `<storage>/typst/fonts/<principal>/`           |
| `template`  | Full document skeletons (e.g. invoice, letter)           | `<plugin>/skills/typst/templates/` (invoice) | `<storage>/typst/templates/<principal>/`       |
| `example`   | Pattern snippets the LLM cribs from (headings, tables…) | `<plugin>/skills/typst/examples/` (headings)  | `<storage>/typst/examples/<principal>/`        |
| `image`     | Reference images for `#image()`                          | (none — there are no skill-shipped images)  | `<storage>/typst/images/<principal>/`         |

Resources are listed with `typst_resources(action="resources_list", kind="<kind>")`.
Uploads use `typst_resources(action="resources_write", kind="<kind>", name="...", content="...")`.
Tier-2 wins on basename collision — uploading a font named `Inter-Regular.otf`
overrides the skill-shipped one for that principal only.

The plugin's Typst world is built per principal with:

- `template_dir: <storage>/typst/<principal>/` — so both `templates/`
  and `examples/` are visible as siblings. Include with
  `#include "templates/invoice.typ"` or `#include "examples/headings.typ"`.
  Skill-shipped templates and examples are NOT auto-injected into
  the principal's `template_dir`; the operator lists them via
  `typst_resources` and pastes the basename explicitly when they
  want to use one.

- `font_dirs: [<plugin>/skills/typst/fonts/, <storage>/typst/fonts/<principal>/]`
  — Typst searches both. Reference by basename (`font: "Inter-Regular"`)
  with no URL.

## Referencing assets

The plugin's image library returns URLs shaped like
`/api/v1/typst/images/<basename>`. To embed an image in your
Typst source, drop the URL straight into `#image()`:

```typst
#image("/api/v1/typst/images/logo.png", width: 80%)
```

Upload images via `POST /api/v1/typst/images` (or the Images tab on
the admin panel). The URL is returned in the upload response — paste
it into your `typst_render` source.

For images already in the operator's media archive (uploaded by
other plugins or agents), use the media archive's canonical URL
`/api/v1/assets/<uuid>.<ext>`:

```typst
#image("/api/v1/assets/01HXYZ....png", width: 80%)
```

The renderer's `MediaEmbed` markdown uses this same URL surface,
so operator-pasted playground outputs and agent-generated renders
share one URL convention.

Font references use the same path: the plugin's `font_dirs` includes
both skill-shipped OFL fonts (Inter Regular + Bold) and principal-tier
uploads, so just reference them by basename (`font: "Inter-Regular"`)
— no URL needed.

## Examples

Three worked examples to copy.

### Render a one-page invoice

```jsonc
typst_render(
  source: "= Invoice\n\nFor: Acme Corp.\nTotal: \\$420.00\n",
  format: "pdf"
)
```

### Iterate on a multi-page document

```jsonc
// pass 1: inspect for syntax errors
typst_inspect(source: "= ...\n#let x = 1\n#for i in range(1, 5) [= Page \\#i\n]\n")

// pass 2: render once the inspector is clean
typst_render(source: "= ...\n#let x = 1\n#for i in range(1, 5) [= Page \\#i\n]\n", format: "pdf")

// pass 3: grab the first page as a thumbnail
typst_render(source: "= ...\n#let x = 1\n#for i in range(1, 5) [= Page \\#i\n]\n", format: "png", page: 0, dpi: 96)
```

### Render from an uploaded .typ file

```jsonc
typst_render(file: "01HXYZ_TYPSOURCE_UUID", format: "svg", page: 2)
```

## Limits

- 5 MB max upload per `typst_resources(action="resources_write")` call.
- Basenames restricted to `A-Z a-z 0-9 . _ -`; `/`, `\`, `..` rejected.
- Tier-2 resources (fonts, templates, examples, images) are
  principal-scoped — you only see yours; you only delete yours.
  The renderer's `template_dir` is per-principal too, so a
  `#include` won't accidentally reach into another principal's
  templates.
- `typst_render` always persists the derivative; there's no "preview
  without saving" mode. Use `typst_inspect` when you don't want a
  media-derivative row.

## When NOT to use Typst

- The user asked for a Microsoft Word / Google Docs / ODT file — Typst cannot emit those. Offer PDF instead.
- The user wants collaborative editing — Typst is a single-author source file.
- The artifact is a chart only — use a dedicated chart tool; don't shoehorn a chart into a one-page Typst document.
- The source is essentially Markdown and you don't need pixel-perfect typography — write Markdown directly.
