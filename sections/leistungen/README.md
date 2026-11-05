# Section `leistungen`

Service cards (icon, image or bordered), 2–4 columns, optional whole-card link.

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `cards-icon` | Karten mit Icon | Icon tile on a surface card. |
| `cards-image` | Karten mit Bild | Image (4/3) on top, no icon. |
| `cards-border` | Karten mit Rahmen | Transparent card, border + accent line, icon. |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `columns` | `2`, `3`, `4` | `3` | Max columns (1 on mobile, 2 from 600 px, 4 from 1100 px) |
| `card_link` | `yes`, `no` | `yes` | `yes`: the item `link` button is shown and stretched over the whole card (one link per card, no nested links; links inside the text stay clickable on top). `no`: the `link` button is still shown when filled, but only the link itself is clickable (no stretched card link). |
| `heading_level` | `h2`, `h3` | `h2` | Section title; card titles are one level lower (`h3`/`h4`) |
| `count` | 2 to 12 | 3 | Number of cards |

## Fields
| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 |
| `title` | text | Überschrift | 100 |
| `intro` | richtext | Einleitung | 600 |

Head fields are hidden for visitors when empty (set `"title": ""` and `"eyebrow": ""` in the layout
content for cards without a head).

List items (`{page_key}.{instance_id}.items.{n}.{field}`):

| Field | Type | Label (DE) | Max | Notes |
|---|---|---|---|---|
| `title` | text | Titel | 60 | Empty → card hidden for visitors |
| `text` | richtext | Text | 400 | |
| `image` | image (4/3) | Bild | – | `cards-image` only |
| `link` | button | Link | 40 | Hidden for visitors when empty; the card title is appended as visually hidden text ("Mehr erfahren: Heizung") |
| `icon` | setting | – | – | Agency only: layout JSON `content.items[i].icon` = icon name from `_shared/icons.php`, `none` = no icon. Missing → fallback cycle (wrench, flame, droplet, bolt, home, shield, …). |

## Edit state
`html.df-edit-active` removes the stretched link overlay and the hover lift, so title, text and
link are directly clickable.
