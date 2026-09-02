// examples/showcase.typ
//
// One-page reference of the Typst language features the agent
// reaches for most often. The SKILL.md primer cites this file
// as the canonical "what does Typst look like" reference — a
// single file the LLM can `typst_inspect` and see every common
// syntactic form in one place.
//
// Categories, in reading order:
//   1. Headings                 (sectioning + numbering)
//   2. Text formatting          (emphasis, code, math, raw)
//   3. Lists                    (bullet, numbered, term)
//   4. Tables                   (with header row + fill)
//   5. Code expressions         (#let, #for, #if, content blocks)
//   6. References and labels    (figure with caption, @label)
//   7. Math                     (inline + display)
//   8. Images and figures       (#image with width + caption)
//
// Render with: `typst_render(file: "<this-asset-id>", format: "pdf")`
// Output: a one-page (or two-page) PDF that demonstrates each form
// in context. Useful as a "does this look right?" smoke test after
// upgrading the plugin or changing the bundled fonts.

// ─────────────────────────────────────────────────────────────────
// 1. Headings — six levels, plus an outline-friendly numbered list.
//    Enable auto-numbering so the @label reference in section 6
//    can resolve to a real page number.
// ─────────────────────────────────────────────────────────────────

#set heading(numbering: "1.")

= Top-level heading
This paragraph sits under a level-1 heading. Body text is the
default flow content; line breaks are paragraph breaks (a blank
line), not `\\` soft breaks.

== Second-level heading
=== Third-level heading

You can have up to six levels of nesting. Heading text follows
the same emphasis rules as the body.

// ─────────────────────────────────────────────────────────────────
// 2. Text formatting — emphasis, code spans, math, raw blocks.
// ─────────────────────────────────────────────────────────────────

*Bold text*, _italic text_, `inline code`, and inline math
delimiters (the `$ ... $` pair — see section 7).

You can also write #emph[emphasised] or #strong[strongly emphasised]
inline, and #raw(block: true, lang: "typst", "#let x = 1")
to embed a raw Typst fragment.

Sub- and superscripts: H~2~O, 1~st~, E = m c^2 (Einstein's formula).

// ─────────────────────────────────────────────────────────────────
// 3. Lists — bullet, numbered, term.
// ─────────────────────────────────────────────────────────────────

- Bullet item one
- Bullet item two
  - Nested (use 2-space indent)
  - Another nested item
- Bullet item three

+ Numbered item one
+ Numbered item two
+ Numbered item three

/ Term list: a definition list where the `/` introduces
  the term and the indented text is the description.

/ Another term: this one has a longer description that
  wraps to two lines. The leading `/` is the marker.

// ─────────────────────────────────────────────────────────────────
// 4. Tables — header row + alternating row fills + alignment.
// ─────────────────────────────────────────────────────────────────

#table(
  columns: (auto, 1fr, auto),
  align: (left, left, right),
  stroke: 0.5pt + gray,
  fill: (col, row) => if row == 0 { luma(220) } else if calc.odd(row) { luma(245) } else { none },
  table.header(
    [*Name*], [*Role*], [*Salary*],
    [Alice], [Engineer], [\$90k],
    [Bob],   [Designer], [\$85k],
    [Carol], [Manager],  [\$110k],
  ),
)

// ─────────────────────────────────────────────────────────────────
// 5. Code expressions — bindings, loops, conditionals, content blocks.
//    Content blocks `[...]` are markup; they're how you splice
//    list items, table cells, and template fragments together.
// ─────────────────────────────────────────────────────────────────

#let project = "Spora"
#let tasks = ("design", "implement", "review", "ship")

Tasks for #project:
#for (i, task) in tasks.enumerate() [
  + Task #(i + 1): #task \
    #if i == tasks.len() - 1 [
      _(final step)_
    ]
]

// ─────────────────────────────────────────────────────────────────
// 6. References — assign a label with `<...>`, cite with `@...`.
//    The output of `query(<label>)` is also useful in code mode.
// ─────────────────────────────────────────────────────────────────

= Methodology <methodology>

See @methodology for the full methodology. (Typst resolves the
reference to the section heading and page number automatically.)

// ─────────────────────────────────────────────────────────────────
// 7. Math — inline `$...$` and display-mode `$ ... $`. The display
//    form uses the same delimiter pair, distinguished by being on
//    its own line with surrounding blank lines.
// ─────────────────────────────────────────────────────────────────

Euler's identity: $e^(i pi) + 1 = 0$.

A display equation, set off from surrounding text:

$ sum_(k=1)^n k = (n (n + 1)) / 2 $

// ─────────────────────────────────────────────────────────────────
// 8. Figures — an image with width + caption, where the `<fig:..>`
//    label lets other parts of the document reference it via
//    `@fig:..`. The image path is the plugin's canonical URL; if
//    you have nothing on hand, comment this block out.
// ─────────────────────────────────────────────────────────────────

// #figure(
//   image("/api/v1/typst/images/REPLACE-WITH-NAME.png", width: 80%),
//   caption: [A figure caption — references resolve via @fig:demo.],
// ) <fig:demo>

#v(1fr)
#align(center)[
  #text(8pt, fill: gray)[End of showcase — see SKILL.md for the rest.]
]