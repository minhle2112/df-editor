# Section `cta`

Call to action: heading, text, up to 2 buttons; `contact` adds a person photo and a phone link.

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `centered` | Zentriert | Centred text and buttons. |
| `split` | Text + Buttons nebeneinander | Text left, buttons right (from 900 px). |
| `contact` | Kontakt mit Ansprechpartner | Round photo, text, phone link + buttons. |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `buttons` | `0`, `1`, `2` | `1` | Number of buttons (`cta1`, `cta2`) |
| `phone_source` | `global`, `own` | `global` | `contact` only: `global` prints the site-wide `global.contact.phone` (args via `df_contact_field_args`), `own` prints this section's `phone` field |
| `heading_level` | `h2`, `h3` | `h2` | |

## Fields
| Field | Type | Label (DE) | Max | Notes |
|---|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 | Hidden for visitors when empty |
| `title` | text | Überschrift | 80 | |
| `text` | richtext | Text | 400 | |
| `cta1` | button | Button 1 | 40 | Primary |
| `cta2` | button | Button 2 | 40 | Secondary |
| `image` | image (1/1) | Foto Ansprechpartner | – | `contact` only |
| `phone` | button | Telefon | 40 | `contact` only; URL as `tel:+49…`; decorative phone icon next to the link; hidden for visitors when empty; used only with `phone_source` = `own` |
