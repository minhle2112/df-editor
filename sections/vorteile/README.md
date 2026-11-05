# Section `vorteile`

USP bar: 3–6 short benefits with icon, title and one line of text. No heading (meant for right
below the hero or between sections).

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `row` | Leiste (eine Zeile) | All items in one row from 1000 px (2 columns from 600 px), icon left of the text. |
| `grid` | Raster | Up to 3 columns, larger icon above the text. |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `divider` | `yes`, `no` | `no` | Hairlines between items (vertical in `row` on desktop) |
| `count` | 3 to 6 | 4 | Number of items |

## Fields
List items (`{page_key}.{instance_id}.items.{n}.{field}`):

| Field | Type | Label (DE) | Max | Notes |
|---|---|---|---|---|
| `title` | text | Titel | 40 | |
| `text` | text | Text | 100 | |
| `icon` | setting | – | – | Agency only: `content.items[i].icon` (icon name or `none`). Missing → fallback cycle (check, clock, shield, star, users, map-pin). |

An item with empty title and text is hidden for visitors.
