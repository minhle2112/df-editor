# Section `faq`

Accordion of questions and answers, built on native `<details>/<summary>` (works without JS).

## Variants
| Key | Label (DE) |
|---|---|
| `one-col` | Eine Spalte |
| `two-col` | Zwei Spalten (from 900 px) |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `open_first` | `yes`, `no` | `no` | First item open on load |
| `single_open` | `yes`, `no` | `no` | Opening one item closes the others (`view.js`) |
| `schema` | `yes`, `no` | `no` | Outputs FAQPage JSON-LD (only when the page really is an FAQ) |
| `heading_level` | `h2`, `h3` | `h2` | |
| `count` | 1 to 12 | 5 | Number of items (list `min` / `max` / `default_count`) |

## Fields
Section fields (`{page_key}.{instance_id}.{field}`):

| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 |
| `title` | text | Überschrift | 100 |
| `intro` | richtext | Einleitung | 600 (hidden for visitors when empty) |

List items (`{page_key}.{instance_id}.items.{n}.{field}`, n = 1 … count):

| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `question` | text | Frage | 160 |
| `answer` | richtext | Antwort | 1500 |

`{n}` in a list default is replaced by the item number. Lowering `count` hides items; their content
stays saved. An item with an empty question is hidden for visitors but stays visible to editors.

## Edit state
When DF Inhalt is active (`html.df-edit-active`) every item is opened (`view.js` + `base.css`)
so all answers are editable; the previous open state is restored afterwards.
