// Starter invoice — drop into your workflow with `#let total = 420` etc.
// Renders cleanly under ext-typst 0.2 with the Inter OFL fonts the
// plugin ships.

#let invoice(
  recipient: "Acme Corp.",
  issuer: "Spora Studio",
  lines: (
    (label: "Implementation", amount: 240.00),
    (label: "Design",          amount: 120.00),
    (label: "Review",          amount:  60.00),
  ),
  due-in-days: 14,
) = {
  let total = lines.fold(0, (acc, l) => acc + l.amount)
  let due = datetime.today().display("[month repr:long] [day], [year]")

  set document(title: "Invoice", author: issuer)
  set page(
    margin: (x: 2.5cm, y: 3cm),
    header: align(right, text(9pt, fill: gray)[Invoice]),
  )
  set text(font: ("Inter",), size: 10pt)

  align(center)[
    #text(22pt, weight: "bold")[Invoice]
    #v(0.5em)
    #text(11pt, fill: gray)[Issued by #issuer]
  ]

  v(1.5em)

  grid(
    columns: (1fr, 1fr),
    [*Bill to*], [*Due*],
    [#recipient], [#due],
  )

  v(1em)

  #table(
    columns: (1fr, auto),
    stroke: 0.5pt + gray,
    fill: (col, row) => if row == 0 or row == lines.len() + 1 { luma(240) } else { none },
    table.header(
      [*Item*], [*Amount*],
      ..lines.map(l => ([#l.label], [$#l.amount.to-fixed(2)])).flatten()
    ),
    [*Total*], [*$#total.to-fixed(2)*],
  )

  v(1fr)
  align(center)[
    #text(9pt, fill: gray)[Thank you for your business.]
  ]
}

#invoice()
