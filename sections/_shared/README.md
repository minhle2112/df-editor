# `sections/_shared/`

Helpers for section templates. Not a section (no `section.json`, ignored by the registry).
Load from a template with `require_once DF_EDITOR_DIR . 'sections/_shared/helpers.php';`
(this also loads `icons.php`). Every function is guarded with `function_exists`.

## `icons.php`
`df_icon( $name, $args )` prints, `df_get_icon()` returns, `df_icon_names()` lists.
24×24 line icons, `stroke="currentColor"`, decorative (`aria-hidden="true"`) unless
`$args['label']` is given (then `role="img"` + `aria-label`). `$args['class']` adds classes.
Size comes from the section CSS (`.df-<type> .df-icon { width; height }`).

Icons: `check`, `phone`, `mail`, `clock`, `map-pin`, `star`, `shield`, `wrench`, `flame`, `droplet`,
`home`, `leaf`, `bolt`, `users`, `calendar`, `arrow-right`, `arrow-left`.
Themes can add/replace icons with the filter `df_editor_icons` (name => inner SVG markup).

Note: `wp_kses_post` strips SVG, so icons cannot go into a button's `before`/`after` args;
print them next to the link instead.

## `helpers.php`
| Function | Purpose |
|---|---|
| `df_item_setting( $s, $n, $key, $fallback )` | Agency-only setting of list item `$n` (1-based) from the layout JSON (`content.items[n-1].<key>`). Never passed to DF Inhalt, so the client cannot edit it. |
| `df_field_filled( $value )` | Whether a text/richtext/button value has visible text ("hide when empty"). |
| `df_heading_tag( $value, $default )` | Whitelists `h1`–`h4`. |
| `df_section_head( $s, $type, $h )` | Prints `df-<type>__head` with eyebrow (`p.df-eyebrow`), title (`df-<type>__heading df-title`) and intro (`df-<type>__intro`), only for fields that exist; empty ones are hidden for visitors, always shown to editors. |

## List "settings" (`"type": "setting"`)
Per-item values the agency sets in the layout JSON (icon, star rating) are declared as list fields
with `"type": "setting"` (with `values` and `default`). The validator accepts them as known subfields
(no type check), and DF Inhalt never sees them because templates read them with `df_item_setting()`
instead of `$item->text()`.
