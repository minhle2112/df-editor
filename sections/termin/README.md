# Section `termin`

Highlights one event (static content, no event post type): a large split teaser with image
(invitation to the next seminar) or a compact card with icon tile and key facts (live evening).

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `feature` | Einladung mit Bild | Text column (eyebrow, title, date, text, facts, buttons, note) next to a 4/3 image with optional caption; stacked below 900 px. |
| `card` | Kompakte Termin-Karte | One wide card: marker, text with arrow links, facts as a pill column (pills wrap below the text under 1000 px). |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `image_side` | `left`, `right` | `right` | `feature` only |
| `image_style` | `plain`, `rounded`, `shadow` | `shadow` | `feature` only |
| `buttons` | `0`, `1`, `2` | `1` | Number of buttons. `card`: rendered as text links with arrow. |
| `marker` | `icon`, `image`, `none` | `icon` | `card` only: icon tile, small square crop of `image`, or nothing |
| `icon` | icon names | `calendar` | `card` with `marker=icon`. The gap spec had this as a field of type `setting`; settings are list-only, so it is an option. |
| `heading_level` | `h2`, `h3` | `h2` | |
| `count` | 0 to 4 | 3 | Number of key facts (v2: child blocks). `0` is allowed. |

## Fields
| Field | Type | Label (DE) | Max | Optional |
|---|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 | yes |
| `title` | text | Titel | 100 | – |
| `date` | text | Datum / Ort | 100 | yes |
| `text` | richtext | Text | 600 | yes |
| `cta1` | button | Button 1 | 40 | yes |
| `cta2` | button | Button 2 | 40 | yes |
| `note` | text | Hinweis | 140 | yes (small muted line under the buttons) |
| `image` | image (4/3) | Bild | – | `feature`; `card` with `marker=image` |
| `caption` | text | Bildunterschrift | 120 | yes, `feature` only |

List `facts` (`{page_key}.{instance_id}.facts.{n}.label`, item label "Eckdatum", min 0, max 4):
`label` (text, 40). Empty labels are hidden for visitors. Facts are shown in both variants.
