---
name: typst
description: "When the user asks for a typeset document, a PDF report, a slide deck, a structured invoice or any other printed artifact rendered from a programmatic source; OR when a workspace task produces structured data (a bill of materials, a meeting summary, a chart of accounts) that the user wants in a presentable form. Use the `typst_compile` tool to compile Typst source to PDF/PNG/SVG (action=render) or run an error-only pre-check (action=inspect), and `typst_resources` to manage the per-principal font, template, example, and image libraries. Recommended tools: typst_compile, typst_resources."
license: Apache-2.0
metadata:
  author: spora-ai
  version: "1.1"
  allowedByDefault: false
  requiresTools: "typst_compile,typst_resources"
---

# Typst

You are an agent that produces typeset documents using Typst. Treat rendering as a structured iteration: write a draft, inspect for errors, render, inspect the output, iterate. **Never guess at Typst syntax** — when in doubt, write a tiny fragment and call `typst_compile(action: "inspect")` first; only call `typst_compile(action: "render")` once the source parses cleanly.

## When to use this skill

Trigger on any of:

- "Make me a PDF", "Generate a report", "Typeset this", "Render this as a slide"
- "Compile this Typst code", "Run the .typ file through Typst"
- "I have structured data — give me a presentable document"
- "Check whether this Typst source is valid" (use `typst_compile(action: "inspect")`, no rendering needed)

Do NOT use this skill for:

- One-off prose edits to an existing markdown file (use `write_file` / `edit_file`).
- Generating a chart as an image only (use a chart-producing tool, not Typst).
- WYSIWYG document editing — Typst is a markup language, not a word processor.

## Tool selection

Two tools, three jobs:

| Tool | Operation | Purpose | When |
| --- | --- | --- | --- |
| `typst_compile` | `action: "inspect"` | Run the inspector on a source; return structured errors and warnings only | First pass on a new source. Cheap; no media-derivative row is created. |
| `typst_compile` | `action: "render"` | Compile the source to PDF/PNG/SVG and persist it as a media-derivative | When the inspector returns clean, or when the operator has approved a known-imperfect render. |
| `typst_resources` | `action: "fonts" / "templates" / "examples" / "images"`, `op: "list" / "write" / "delete"` | Manage per-principal resources | When the user wants to upload a brand font, save a reusable template, or store an image asset. |

For routine compiles, the flow is `typst_compile(action: "inspect")` → fix → `typst_compile(action: "render")`. Skip the inspect step when the user has already iterated and the source is small enough to inspect inline.

## Source location — inline vs file

`typst_compile` accepts the source in one of two ways (for both `render` and `inspect`):

```jsonc
// inline — small, ephemeral, freshly authored (render needs a `filename`)
// filename becomes the playground pool row name; .typ is auto-appended
{ "source": "= Hello\n", "filename": "letter.typ", "format": "pdf" }

// file — a media asset id previously uploaded as .typ
{ "file": "01HXYZ...", "format": "png", "page": 0 }
```

Default to inline. Switch to `file` when:

- The user uploaded a `.typ` asset specifically to iterate on.
- The source is bigger than ~4 KB (paste limit).
- You want the natural-key idempotency of `media_derivatives` (re-rendering the same `file` with the same `format` refreshes the existing row instead of stacking duplicates).

`filename` is **required** when `action="render"` is called with inline `source`. Pick a basename the user can recognise in the playground picker — `"letter.typ"`, `"cover-letter.typ"`, `"playground.typ"`. Without it the render is rejected with a clear error: every inline render must produce a named row, otherwise the parent becomes invisible in the file picker and accumulates as an orphan. Two renders with the same `filename` produce sibling rows (the previous in-place overwrite behaviour is gone) so the user can compare revisions; the file picker surfaces both.

`inspect` never persists. It runs `inspectString($bytes)` only and returns the structured diagnostics; no `MediaAsset` row is written, so an inspect call cannot leave orphan rows. A `filename` on an inspect call is ignored — `filename` is render-only.

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

When the producer throws an unrelated exception (font unreadable, invalid source bytes), the tool returns the exception's message in `error`. The most common cause for fresh installs is missing tier-2 fonts — list them with `typst_resources(action="resources_list", kind="font")` and either upload the missing one or fall back to the bundled fonts.

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

`width`/`height` are populated only for `png` (PNG image dimensions). `size` is the derivative's byte count. The `asset_url` is stable across re-renders — calling `typst_compile(action: "render")` again with the same `(file, format)` tuple updates the existing row's bytes but keeps the same id, so URLs the operator has bookmarked stay valid.

## Typst syntax primer (the minimum you need)

Use Typst's markup, not LaTeX, not Markdown. For the canonical
one-page reference of every feature below, read the bundled
`examples/showcase.typ` — it renders a complete demo document
and is the file the operator should `typst_compile(action: "inspect")` first when
in doubt about syntax.

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
- Math is `#expr` mode with TeX-like syntax: `$x^2 + y^2 = z^2$` for inline, `$ ... $` block form same syntax (no `\[...\]`). Math mode requires a math font — see the **Fonts** section below.
- The plugin ships Inter, DejaVu, and Latin Modern Math — see the **Fonts** section below for which families are available and the recommended cascade.

## Fonts

The plugin ships these font families in `<plugin>/skills/typst/fonts/`:

| Family             | Weights shipped                | License             | Use for                                  |
|--------------------|--------------------------------|---------------------|------------------------------------------|
| `Inter`            | Regular, Bold                  | OFL-1.1             | Body / headings (designed for UI)        |
| `DejaVu Sans`      | Regular, Bold, Italic, BoldItalic | DejaVu License      | Body fallback (broader Unicode coverage) |
| `DejaVu Sans Mono` | Regular, Bold, Italic, BoldItalic | DejaVu License      | Code blocks (`#raw`, fenced `\`\`\``)    |
| `DejaVu Serif`     | Regular, Bold, Italic, BoldItalic | DejaVu License      | Serif body fallback                      |
| `Latin Modern Math`| Regular                        | OFL-1.1             | Math mode (`$...$`, display equations)   |

ext-typst does NOT auto-discover system fonts — it only sees what's in
the `font_dirs` we hand it. So even on a host that has STIX, Cambria,
or whatever else installed, math and code blocks fail with "no font
could be found" without the bundled fonts above.

**Recommended cascade** for new documents:

```typst
#set text(font: ("Inter", "DejaVu Sans", "DejaVu Serif"))
#show math.equation: set text(font: ("Latin Modern Math",))
#show raw: set text(font: ("DejaVu Sans Mono",))
```

Inter is first because it's the most UI-tuned; DejaVu Sans takes over
for characters Inter doesn't cover; DejaVu Serif is the serif fallback
for italic body. `show math.equation` wires math mode to Latin Modern
Math (the bundled math font), and `show raw` pins code blocks to DejaVu
Sans Mono. The bundled `templates/report.typ` and `examples/showcase.typ`
already declare all three — import them and the cascade comes for free.

`typst_resources(action="fonts", op="write")` adds more fonts at the
principal tier. Tier-2 wins on basename collision, so uploading a font
named `Inter-Regular.ttf` shadows the bundled one for that principal.

## Resources

Three resource kinds, two tiers each:

| Kind        | Purpose                                                 | Tier 1 (skill-shipped, read-only)             | Tier 2 (principal, writable)                  |
|-------------|---------------------------------------------------------|----------------------------------------------|------------------------------------------------|
| `font`      | Custom fonts (`.ttf`/`.otf`/`.woff`/`.woff2`)            | `<plugin>/skills/typst/fonts/` (Inter + DejaVu + Latin Modern Math) | `<storage>/typst/fonts/<principal>/`           |
| `template`  | Full document skeletons the agent composes from data     | `<plugin>/skills/typst/templates/` (report)   | `<storage>/typst/templates/<principal>/`       |
| `example`   | Pattern snippets the LLM cribs from (the full showcase) | `<plugin>/skills/typst/examples/` (showcase) | `<storage>/typst/examples/<principal>/`        |
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
  — Typst searches both. Reference by family name (`font: "Inter"`)
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
it into your `typst_compile(action: "render")` source.

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
both skill-shipped fonts (Inter, DejaVu Sans/Mono/Serif, Latin Modern
Math — see the **Fonts** section above) and principal-tier uploads, so
just reference them by family name (`font: "Inter"`).
— no URL needed.

## Examples

Three worked examples to copy.

### Render a one-page invoice

```jsonc
typst_compile(action: "render", 
  source: "= Invoice\n\nFor: Acme Corp.\nTotal: \\$420.00\n",
  format: "pdf"
)
```

### Iterate on a multi-page document

```jsonc
// pass 1: inspect for syntax errors
typst_compile(action: "inspect", source: "= ...\n#let x = 1\n#for i in range(1, 5) [= Page \\#i\n]\n")

// pass 2: render once the inspector is clean
typst_compile(action: "render", source: "= ...\n#let x = 1\n#for i in range(1, 5) [= Page \\#i\n]\n", format: "pdf")

// pass 3: grab the first page as a thumbnail
typst_compile(action: "render", source: "= ...\n#let x = 1\n#for i in range(1, 5) [= Page \\#i\n]\n", format: "png", page: 0, dpi: 96)
```

### Render from an uploaded .typ file

```jsonc
typst_compile(action: "render", file: "01HXYZ_TYPSOURCE_UUID", format: "svg", page: 2)
```

## Limits

- 5 MB max upload per `typst_resources(action="resources_write")` call.
- Basenames restricted to `A-Z a-z 0-9 . _ -`; `/`, `\`, `..` rejected.
- Tier-2 resources (fonts, templates, examples, images) are
  principal-scoped — you only see yours; you only delete yours.
  The renderer's `template_dir` is per-principal too, so a
  `#include` won't accidentally reach into another principal's
  templates.
- `typst_compile(action: "render")` always persists the derivative; there's no "preview
  without saving" mode. Use `typst_compile(action: "inspect")` when you don't want a
  media-derivative row.

## When NOT to use Typst

- The user asked for a Microsoft Word / Google Docs / ODT file — Typst cannot emit those. Offer PDF instead.
- The user wants collaborative editing — Typst is a single-author source file.
- The artifact is a chart only — use a dedicated chart tool; don't shoehorn a chart into a one-page Typst document.
- The source is essentially Markdown and you don't need pixel-perfect typography — write Markdown directly.
