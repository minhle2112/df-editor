# Section `hero`

Opening section of a page: eyebrow, heading, lead text, up to 2 buttons, image.

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `split` | Text + Bild | Text and image side by side (stacked on mobile). |
| `overlay` | Bild mit Overlay | Full-bleed background image, scrim in the scheme background colour. |
| `text` | Nur Text | Centred text, no image. |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `image_side` | `left`, `right` | `right` | `split` only |
| `heading_level` | `h1`, `h2` | `h1` | Exactly one H1 per page (validator warns) |
| `height` | `auto`, `70`, `full` | `auto` | Minimum height (70 % / 100 % of viewport) |
| `buttons` | `0`, `1`, `2` | `1` | Number of buttons shown |

## Fields (ID `{page_key}.{instance_id}.{field}`)
| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 |
| `title` | text | Überschrift | 80 |
| `text` | richtext | Text | 400 |
| `cta1` | button | Button 1 | 40 |
| `cta2` | button | Button 2 | 40 |
| `image` | image (4/3) | Bild | – |

The image is loaded with `fetchpriority="high"` (LCP candidate).
