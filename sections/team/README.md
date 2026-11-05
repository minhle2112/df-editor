# Section `team`

Team members in a grid. Two sources:

- `cpt` (default): published entries of the post type **Team** (`df_team`, module `includes/cpt/`),
  ordered by `menu_order` (drag-and-drop in the admin list). Name, role, photo, bio and contact
  come from WordPress and are marked as DF Inhalt **managed regions** (provider `wordpress`, edit
  link to the member's edit screen). Clients add, edit and reorder members in the admin.
- `manual`: a fixed list of DF Inhalt fields (agency sets `count`).

## Variants
| Key | Label (DE) |
|---|---|
| `grid` | Raster |

## Options
| Key | Values | Default | Notes |
|---|---|---|---|
| `source` | `cpt`, `manual` | `cpt` | |
| `columns` | `2`, `3`, `4` | `3` | 1 column on phones, 2 from 600 px |
| `photo` | `round`, `rect` | `round` | Round photos are always 1:1 |
| `ratio` | `1-1`, `4-5` | `1-1` | Photo ratio for `rect` |
| `cpt_max` | `all`, `2` … `24` | `all` | Max. members in `cpt` mode |
| `contact` | `yes`, `no` | `yes` | Email / phone (/ LinkedIn in `cpt` mode) |
| `bio` | `yes`, `no` | `no` | Excerpt as short bio (`cpt` mode only) |
| `heading_level` | `h2`, `h3` | `h2` | Member names use the next level |
| `count` | 1 to 12 | 3 | `manual` mode only |

## Fields
| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `eyebrow` | text | Dachzeile | 60 |
| `title` | text | Überschrift | 100 |
| `intro` | richtext | Einleitung | 600 (hidden for visitors when empty) |

List items, `manual` mode (`{page_key}.{instance_id}.items.{n}.{field}`):

| Field | Type | Label (DE) | Max |
|---|---|---|---|
| `name` | text | Name | 80 (item hidden for visitors when empty) |
| `role` | text | Position | 80 |
| `image` | image | Foto | ratio 1/1 or 4/5 by option |
| `email` | button | E-Mail | 80 (hidden for visitors when the text is empty) |
| `phone` | button | Telefon | 40 (default empty = hidden) |

## Post type `df_team`
Title = name, featured image = photo, excerpt = short bio, meta `_df_role`, `_df_email`,
`_df_phone`, `_df_linkedin` (meta box "Role & contact"). Disable with the filter `df_editor_cpts`;
the section then shows a notice to editors only.

## Edit state
No hidden content. In `cpt` mode DF Inhalt shows each value as locked with an "Edit" link.
