// templates/report.typ
//
// Agentic report template — the document shape an LLM produces
// when the user asks "give me a one-page PDF summarizing X" or
// "write a status report on Y".
//
// The template accepts a `data` dictionary with a stable shape:
//   - title      (content): document title, shown on the cover
//   - subtitle   (content): subtitle / report type, on the cover
//   - author     (string):  report author or agent name
//   - date       (content): date string (pre-formatted by caller)
//   - sections   (array):  ordered list of { heading, body } pairs;
//                          each body is markup content (a content
//                          block from the caller, NOT a string —
//                          so the agent can hand us rich markup)
//   - summary    (content): closing paragraph (optional)
//
// Render with:
//   #import "templates/report.typ": report
//   #show: report.with(data: (
//     title:    [Q4 Status],
//     subtitle: [Engineering weekly],
//     author:   "agent-spora",
//     date:     [2026-09-03],
//     sections: (
//       (heading: [Highlights], body: [...]),
//       (heading: [Risks],      body: [...]),
//     ),
//     summary: [All targets green.],
//   ))
//
// Or use the convenience wrapper at the bottom (`#report-with(...)`)
// when the agent doesn't want to deal with the show rule.

#let report(
  // Title-page content.
  title: [Untitled report],
  subtitle: [],
  author: "",
  date: [],

  // Body sections in render order. Each is a { heading, body }
  // record where `heading` is content (so it can be styled) and
  // `body` is a content block the caller authors.
  sections: (),

  // Optional closing summary.
  summary: [],

  // Document-level styling knobs. Exposed so callers can theme
  // without forking the template.
  body-font: ("Inter",),
  heading-font: ("Inter",),
  primary: rgb("#1f2937"),
  accent:  rgb("#7c3aed"),
  paper:   "a4",
) = {

  // Global setup. Kept narrow — the template shouldn't dictate
  // page numbers or margins the caller hasn't asked for.
  set document(title: title, author: author)
  set page(
    paper: paper,
    margin: (x: 2cm, y: 2.5cm),
    header: context {
      if counter(page).get().first() > 1 [
        #set text(8pt, fill: gray)
        #grid(
          columns: (1fr, auto),
          align: (left, right),
          [#title], [page #counter(page).display() of #context counter(page).final().first()],
        )
        #line(length: 100%, stroke: 0.25pt + luma(220))
      ]
    },
    footer: context {
      if counter(page).get().first() > 1 [
        #set text(8pt, fill: gray)
        #align(center)[#author]
      ]
    },
  )
  set text(font: body-font, size: 10pt, fill: primary, lang: "en")
  set par(justify: true, leading: 0.65em)

  // Headings use the accent colour and a slightly tighter leading
  // than the body. Numbered so future sections can cross-reference.
  set heading(numbering: "1.")
  show heading.where(level: 1): h => {
    pagebreak(weak: true)
    set text(font: heading-font, size: 18pt, weight: "bold", fill: accent)
    block(below: 0.6em, above: 1em)[#h]
  }
  show heading.where(level: 2): h => {
    set text(font: heading-font, size: 13pt, weight: "bold", fill: primary)
    block(below: 0.4em, above: 0.8em)[#h]
  }

  // Cover page. Centered title block, then `pagebreak()` to push
  // section 1 to its own page.
  align(center)[
    #v(1fr)
    #text(28pt, weight: "bold", fill: accent)[#title]
    #v(0.4em)
    #if subtitle != [] [
      #text(14pt, fill: gray)[#subtitle]
    ]
    #v(1em)
    #line(length: 30%, stroke: 1pt + accent)
    #v(1em)
    #text(10pt, fill: gray)[#author #h(1em) · #h(1em) #date]
    #v(1fr)
  ]
  pagebreak()

  // Body sections. Each section heading is a level-1 heading so
  // it triggers the pagebreak + accent style above.
  for section in sections [
    = #section.heading

    #section.body

    #v(0.8em)
  ]

  // Optional summary. Appears after the last section on its own
  // page so it reads as a separate "closing remarks" block.
  if summary != [] [
    #pagebreak(weak: true)
    = Summary

    #summary
  ]
}

// Convenience wrapper so an agent can render the template in one
// expression without writing the `#show` rule. The caller wraps the
// content that follows in `[ ... ]` markup.
//
// Usage:
//   #report-with(data: (title: ..., sections: ..., ...))[
//     = Highlights
//     ...
//   ]
//
// Note: the show-rule form above is preferred for production use
// because it composes cleanly with other show rules; this wrapper
// exists so one-shot agent invocations stay terse.
#let report-with(data) = report(data)

#let _demo = (
  title: [Weekly Status — Spora],
  subtitle: [Engineering, week 36],
  author: "agent-spora",
  date: [2026-09-03],
  sections: (
    (
      heading: [Highlights],
      body: [
        - The plugin shipped its first batch of reusable templates
          and pattern snippets.
        - The playground's editor now supports Unicode identifiers
          (`grüße`) without fragmenting the keyword match.
        - The media-derivative flow handles three formats (PDF,
          PNG, SVG) at the same latency budget as one.
      ],
    ),
    (
      heading: [Risks],
      body: [
        - The compile endpoint's diagnostic messages leaked the
          operator's filesystem layout; now sanitised.
        - Storage layout restructured to put images under the same
          `template_dir` as templates, so `#image("foo.jpg")` just
          works.
      ],
    ),
  ),
  summary: [
    All targets green for the week. No blocking issues.
  ],
)

// Render the demo by default — the file compiles standalone so
// `typst_render(file: "<this-asset-id>", format: "pdf")` produces
// a sample report without any caller wiring. Comment out this last
// block when consuming the template via `#import`.
#report(.._demo)