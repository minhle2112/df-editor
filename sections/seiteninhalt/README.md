# Section `seiteninhalt`

Prints the WordPress content (`post_content`) of the page being rendered, with all `the_content`
filters (blocks, shortcodes, embeds), in a readable prose container (headings, lists, tables,
quotes, links, images). For Impressum, Datenschutz and other long texts with headings.

The content is written in the WordPress editor (agency), so the output is a DF Inhalt managed
region (provider `wordpress`, edit link to the page). No DF Inhalt fields.

## Variants
| Key | Label (DE) |
|---|---|
| `narrow` | Schmal (Lesebreite, max. 70ch) |
| `wide` | Breit (full container width) |

## Options / fields
None.

## Notes
- Recursion guard: if a `the_content` filter renders the DF layout again, the nested
  Seiteninhalt section outputs nothing.
- Password-protected pages show the WordPress password form.
- Empty content: notice with an edit link for editors, nothing for visitors.
- Pages using this section need the WordPress editor (the block editor switch-off must skip them).
