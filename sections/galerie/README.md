# Section `galerie`

Image gallery with an optional lightbox (`view.js`, native `<dialog>`, no library).

## Variants
| Key | Label (DE) | Notes |
|---|---|---|
| `grid` | Raster | Fixed ratio (option `ratio`) or original |
| `masonry` | Masonry | CSS columns, always original ratio |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `columns` | `2` … `5` | `3` | 1 column below 480 px, 2 up to 900 px |
| `ratio` | `1-1`, `4-3`, `16-9`, `original` | `4-3` | `grid` only |
| `lightbox` | `yes`, `no` | `yes` | |
| `captions` | `yes`, `no` | `no` | Captions under the images (the lightbox shows them anyway) |
| `heading_level` | `h2`, `h3` | `h2` | |
| `count` | 1 to 24 | 6 | Number of images |

## Fields
| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 (default empty) |
| `title` | text | Überschrift | 100 |
| `intro` | richtext | Einleitung | 600 |

Empty head fields are hidden for visitors; editors still see them.

List items (`{page_key}.{instance_id}.items.{n}.{field}`):

| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `image` | image | Bild | ratio from option |
| `caption` | text | Bildunterschrift | 120 (also lightbox caption and button label) |

## Lightbox
A transparent `<button>` over each image opens the dialog. Keyboard: Enter/Space opens, Esc closes,
ArrowLeft / ArrowRight switch images, focus returns to the image button. The dialog is created by
JS inside the section (scheme colours apply) and only copies image URLs, never editable elements.
The largest `srcset` candidate is shown, so a replaced image is correct without reload.

## Edit state
In `html.df-edit-active` the buttons are hidden (`display: none`) and the click handler does not
open the dialog, so DF Inhalt receives the click on the image. An open lightbox closes when
editing starts.

## Assets
`section.json` sets `"script": "on-demand"`: `view.js` is enqueued by `section.php` only when the instance needs it (lightbox = yes). A theme override of `section.php` must keep the `DF_Assets::enqueue_section_script()` call.
