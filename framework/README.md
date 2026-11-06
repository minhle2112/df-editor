# DF mockup kit

Plain-HTML mockups built with the **same CSS framework** as DF Editor sites. A mockup made here is
responsive by itself and turns into theme sections almost 1:1. Full class reference and the
conversion workflow: `docs/FRAMEWORK.md` (project root).

| File | What it is |
|---|---|
| `df-framework.css` | Generated copy of `df-editor/assets/css/base.css` (the single source). Never edit it. |
| `tokens.css` | Generated from a theme.json (here: `df-theme-starter`). Same variable names as WordPress. |
| `mockup-starter.html` | Complete one-page example (header, hero, text + image, 3 cards, FAQ, CTA, footer). |
| `img/platzhalter.svg` | Neutral placeholder image. |

## Start a mockup

1. Copy this folder, e.g. to `clients/<kunde>/mockup/`.
2. Generate the client's tokens (the client theme = a copy of `df-theme-starter` with its own theme.json):

   ```bash
   php df-editor/bin/tokens-css.php path/to/<client-theme>/theme.json > clients/<kunde>/mockup/tokens.css
   ```

   Child themes are merged over their parent automatically. Self-hosted fonts (`fontFace`) point to the
   theme folder by default; to make the mockup portable, copy the theme's `assets/fonts` folder to
   `<mockup>/assets/fonts` (keep the path) and add `--font-base=./`.
   `bin/tokens-css.php` ships with the plugin (`wp-content/plugins/df-editor/bin/`) and runs on any
   PHP 8 CLI, no WordPress needed.
3. Rename `mockup-starter.html`, keep the comment block on top, open it in the browser (from disk works).
4. Build every block as a `<section class="df-section df-<type> …" data-df-section="<type>">` with
   framework classes only; extra CSS goes into the `<style>` block, grouped per section, tokens only.
   Mark editable content with `data-df-field`, lists with `data-df-list` + `data-df-count`.
5. Check at **360, 768 and 1280 px** (DevTools device toolbar). No horizontal scrolling, text before
   image on mobile, buttons stacked, menu as burger.

## Keep the copy fresh

After a change to `assets/css/base.css`:

```bash
php df-editor/bin/tokens-css.php --framework     # rewrites framework/df-framework.css
```

Copied mockup folders keep their old `df-framework.css`; copy the new one in when needed.
