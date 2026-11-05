# Section `beitraege`

Blog/news teaser cards: entered on the page (`manual`) or the latest posts (`posts`).

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `grid` | Raster | Head row (section head left, "Alle Beiträge" link right), card grid, optional button below. |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `source` | `manual`, `posts` | `manual` | `posts`: latest published posts (no password); number = `count` (v2: number of child blocks, whose fields are then unused). Card = featured image (else placeholder), first category, "N min read · d.m.Y" (200 wpm), excerpt (24 words), permalink. DF Inhalt editors see post cards as managed regions with an "Edit post" link. |
| `category` | free text | `""` | Category slug, `posts` only |
| `columns` | `2`, `3` | `3` | 1 column < 600 px, 2 from 600 px, 3 from 1000 px |
| `show_meta` | `yes`, `no` | `yes` | Meta line (manual: item `meta`; posts: reading time + date) |
| `heading_level` | `h2`, `h3` | `h2` | Card titles one level lower |
| `count` | 1 to 12 | 3 | |

## Fields
| Field | Type | Label (DE) | Max | Optional |
|---|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 | yes |
| `title` | text | Überschrift | 100 | – |
| `intro` | richtext | Einleitung | 600 | yes |
| `more` | button | Link oben rechts | 40 | yes |
| `cta` | button | Button unten | 40 | yes (secondary button, centred) |

List items (`items`, item label "Beitrag"):

| Field | Type | Label (DE) | Max | Notes |
|---|---|---|---|---|
| `image` | image (16/10) | Beitragsbild | – | |
| `category` | text | Kategorie | 30 | optional, badge on the image |
| `meta` | text | Meta (Lesezeit · Datum) | 40 | optional |
| `title` | text | Titel | 100 | empty → card hidden for visitors |
| `text` | richtext | Anreißer | 300 | optional |
| `link` | button | Link | 30 | stretched over the whole card; the title is appended as visually hidden text |

## Edit state
`html.df-edit-active` removes the stretched link overlay and the hover lift.
