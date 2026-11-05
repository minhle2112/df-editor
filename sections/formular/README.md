# Section `formular`

Heading + text (DF Inhalt) and a form from **Contact Form 7** or **Fluent Forms** (free), rendered
through `DF_Forms` (`includes/forms/`). The form is a DF Inhalt managed region (provider `cf7` or
`fluentforms`, edit link to the form's admin screen). Styling for both plugins is in `style.css`
(inputs, labels, checkboxes, submit button, errors, response messages), using `--df-*` only.

## Variants
| Key | Label (DE) |
|---|---|
| `full` | Nur Formular |
| `with-contact` | Formular + Kontaktdaten (contact box beside the form from 900 px) |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `form_plugin` | `auto`, `cf7`, `fluentforms` | `auto` | `auto` = CF7 if active, else Fluent Forms |
| `form_id` | any string | `""` | Numeric ID (CF7 also hash ID). Empty = first form of the plugin |
| `heading_level` | `h2`, `h3` | `h2` | |

Find IDs with `wp df-forms`.

## Fields
| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 |
| `title` | text | Überschrift | 100 |
| `text` | richtext | Text | 600 (hidden for visitors when empty) |
| `contact_title` | text | Überschrift Kontaktbox | 80 (`with-contact` only) |

`with-contact` also prints the site-wide fields `global.contact.phone` (button),
`global.contact.email` (button), `global.contact.address`, `global.contact.hours` (text), with the
same labels, limits and defaults as the theme footer, so both places edit the same value.

## No form
Missing plugin or form: a notice for users who can edit pages, nothing for visitors.

## Spam protection
CF7: `DF_Forms` adds a hidden honeypot input (`df_hp_website`) and marks filled submissions as
spam (`wpcf7_spam`). Switch off with `df_editor_settings['honeypot'] = 0`. Fluent Forms has its
own honeypot setting (Global Settings, Security). Turnstile is configured in the form plugin.
