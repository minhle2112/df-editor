# Section `newsletter`

Newsletter sign-up band (usually scheme `dunkel`) with first name, e-mail, consent checkbox,
button and note. Independent of a contact-form plugin; the provider is an agency setting.

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `centered` | Zentriert | Head and text centred (max. 46 rem), form below. |
| `split` | Text + Formular nebeneinander | Text left, form right; stacked below 900 px. |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `provider` | `none`, `action`, `shortcode` | `none` | `none`: built-in form without action and with a disabled submit (editors see a notice in DF Inhalt edit mode and in the block preview, never visitors). `action`: the form posts to `action_url`. `shortcode`: `do_shortcode( shortcode )` replaces the built-in form (the form fields are then unused). |
| `action_url` | free text | `""` | Provider form URL (Brevo, CleverReach, Mailchimp …). Input names via the filter `df_newsletter_field_names` (keys `name`, `email`, `consent`; defaults are the same words). |
| `shortcode` | free text | `""` | Must look like `[tag …]`; anything else is ignored. |
| `show_name` | `yes`, `no` | `yes` | First-name input |
| `heading_level` | `h2`, `h3` | `h2` | |

## Fields
| Field | Type | Label (DE) | Max | Optional |
|---|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 | yes |
| `title` | text | Überschrift | 80 | – |
| `text` | richtext | Text | 300 | yes |
| `name_label` | text | Feld Vorname | 40 | – |
| `name_placeholder` | text | Platzhalter Vorname | 40 | yes |
| `email_label` | text | Feld E-Mail | 40 | – |
| `email_placeholder` | text | Platzhalter E-Mail | 60 | yes |
| `consent` | richtext | Einwilligung | 300 | – (checkbox name via `aria-labelledby`, links allowed) |
| `button_text` | text | Button | 30 | – |
| `note` | text | Hinweis | 120 | yes |

Placeholders are attributes, so DF Inhalt editors get extra visible fields for them (edit state only).
Inputs sit in 2 columns from 600 px (e-mail only: 1); consent and button are full width.
