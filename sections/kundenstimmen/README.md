# Section `kundenstimmen`

Testimonials as grid, single large quote or scroll-snap slider. Content from DF Inhalt (manual) or
from the post type `df_referenz`.

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `grid` | Raster | 1 / 2 / 3 columns. |
| `single` | Einzelne Stimme | Only the first testimonial, large and centred. |
| `slider` | Slider | CSS scroll-snap track (works without JS), prev/next buttons from `view.js`. |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `source` | `manual`, `cpt` | `manual` | `cpt`: published `df_referenz` posts (menu order, then newest) |
| `photos` | `yes`, `no` | `yes` | Show the round photo |
| `heading_level` | `h2`, `h3` | `h2` | |
| `count` | 1 to 9 | 3 | Manual: number of list items. `cpt`: maximum number of posts |

## Fields
| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 |
| `title` | text | Überschrift | 100 |

List items (`source=manual`, `{page_key}.{instance_id}.items.{n}.{field}`):

| Field | Type | Label (DE) | Max | Notes |
|---|---|---|---|---|
| `quote` | richtext | Zitat | 400 | Empty → item hidden for visitors |
| `name` | text | Name | 60 | |
| `company` | text | Firma / Ort | 80 | Hidden for visitors when empty |
| `image` | image (1/1) | Foto | – | |
| `rating` | setting | – | – | Agency only: `content.items[i].rating` 0–5 (0 = no stars). Rendered as 5 decorative stars + visually hidden "4 out of 5 stars". |

## Source `cpt` (`df_referenz`)
Title = name, post content = quote (blocks rendered, rich-text tags only), meta `_df_company`,
meta `_df_rating` (0–5), featured image = photo. Each card is a DF Inhalt managed region
(`provider` `wordpress`, edit link to the post), so editors see "Edit testimonial" instead of inline
fields. The post type itself is not registered by this section. No posts: editors see a notice,
visitors see nothing (empty section wrapper).

## Slider / edit state
- Track: `[data-df-slides]`, `tabindex="0"`, `role="group"`; arrow keys scroll while the track has focus.
- Buttons are real `<button>`s with `aria-controls`, hidden until JS runs and only shown when the
  track overflows; disabled at the start/end. No cloned slides.
- `html.df-edit-active`: track becomes a grid (base.css + section CSS), buttons hidden.

## Assets
`section.json` sets `"script": "on-demand"`: `view.js` is enqueued by `section.php` only when the instance needs it (variant slider). A theme override of `section.php` must keep the `DF_Assets::enqueue_section_script()` call.
