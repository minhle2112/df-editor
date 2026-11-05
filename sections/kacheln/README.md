# Section `kacheln`

Grid of small cards (link tiles, process steps, facts, entry points, values, always-open FAQ
cards), 2–5 columns, optionally next to a text column. Complements `leistungen` (service cards
with images), which stays unchanged.

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `grid` | Raster | Head above the grid (left or centred), optional button below the grid. |
| `split` | Text links + Kacheln rechts | Head + `text` + button in a left column (5/12), tiles right in max. 2 columns; stacked below 900 px. |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `columns` | `2`–`5` | `3` | 1 column < 600 px, 2 columns 600–1000 px, `columns` from 1000 px. `5`: centred rows, 3 + 2 between 1000 and 1200 px. Ignored in `split` (max. 2). |
| `card_style` | `border`, `tinted`, `plain` | `border` | Card with 1px border on the section background (on `hell-alt`: the light base), tinted surface card, or no card. |
| `marker` | `none`, `icon`, `number`, `arrow` | `none` | `number`: step number from the order (reorder = renumber, the list becomes `<ol>`). `icon`: item setting `icon`. `arrow`: arrow circle top right (button colours on hover of a linked card). |
| `card_link` | `yes`, `no` | `no` | `yes`: the item link stretches over the whole card (one link per card, no nested links). Needs a filled item link. |
| `head_align` | `left`, `center` | `left` | `grid` only. |
| `divider` | `yes`, `no` | `no` | Short accent line under the heading. |
| `highlight_first` | `yes`, `no` | `no` | First visible card gets an accent border and shadow. |
| `heading_level` | `h2`, `h3` | `h2` | Card titles are one level lower. |
| `count` | 1 to 12 | 3 | Number of cards (v2 blocks: number of child blocks). |

## Fields
| Field | Type | Label (DE) | Max | Optional |
|---|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 | yes |
| `title` | text | Überschrift | 100 | – |
| `intro` | richtext | Einleitung | 600 | yes |
| `text` | richtext | Text | 1200 | yes (meant for `split`, shown in both variants) |
| `cta` | button | Button | 40 | yes |

List items (`{page_key}.{instance_id}.items.{n}.{field}`, item label "Kachel"):

| Field | Type | Label (DE) | Max | Notes |
|---|---|---|---|---|
| `kicker` | text | Kicker | 40 | optional, small uppercase line above the title |
| `title` | text | Titel | 60 | empty → card hidden for visitors |
| `text` | richtext | Text | 400 | optional |
| `link` | button | Link | 40 | optional; the card title is appended as visually hidden text |
| `icon` | setting | Icon | – | `""` = automatic cycle, `none` = no icon, else a name from `_shared/icons.php`. Only used with `marker=icon`. Editable in the block sidebar (v2) and in the layout JSON. |

## Edit state
`html.df-edit-active` removes the stretched link overlay and the hover lift.
