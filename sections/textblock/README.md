# Section `textblock`

Plain text section: eyebrow, heading, rich text (paragraphs, lists, links, h2/h3).

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `narrow` | Schmal (Lesebreite) | Max width = theme content size (760 px). |
| `wide` | Breit | Full container width. |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `align` | `left`, `center` | `left` | `center` centres the block and its text (lists stay left-aligned) |
| `heading_level` | `h2`, `h3`, `h1` | `h2` | `h1` only when the textblock opens the page (one H1 per page). |

## Fields
| Field | Type | Label (DE) | Max | Notes |
|---|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 | Default empty; hidden for visitors when empty |
| `title` | text | Überschrift | 100 | Hidden for visitors when empty |
| `text` | richtext | Text | 5000 | |
