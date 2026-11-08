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
| `viewer.html` | Mockup viewer: the page shrinks / expands in place to **Desktop / Tablet 768 / Mobil 390** (landscape 1024 / 844), drag handles for any width, rotate, reload. Same look, sizes and keys as the View switcher of DF Editor 1.1.0+. |

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
   Put the new page names into `PAGES` at the top of `viewer.html` (the first one is the viewer's default page).
4. Build every block as a `<section class="df-section df-<type> …" data-df-section="<type>">` with
   framework classes only; extra CSS goes into the `<style>` block, grouped per section, tokens only.
   Mark editable content with `data-df-field`, lists with `data-df-list` + `data-df-count`.
5. **Mobile first**: build each block at 390 px first, then widen. Checklist (text ≥ 16 px, tap targets
   ≥ 44 px, buttons stacked, burger with visible close, no horizontal scroll at 360/320, image crops,
   hero 70svh, forms one column …): `docs/FRAMEWORK.md` §5.
6. Check every page in **`viewer.html`**: Mobil 390, drag to 360 and 320, Tablet 768 portrait + landscape,
   Desktop / Breite 1280. Screenshots at 360 / 390 / 768 / 1280 for the report.

## Viewer (`viewer.html`)

Double-click `viewer.html` (Chrome/Edge, works from disk). It shows the first page of `PAGES`
(`mockup-starter.html` until you change it) and loads only pages of its own folder: for a mockup not
built from the kit, copy `viewer.html` next to its HTML files.

- Other page of the same folder: `viewer.html?src=leistungen.html`, or add the file names to
  `PAGES` at the top of the viewer's script so they appear in the "Seite" list.
- The controls sit in a small pill at the bottom centre (like the DF Inhalt toolbar in WordPress).
  **Desktop** shows the page full width; **Tablet** (768) and **Mobil** (390) shrink it in place with a
  short animation to a white canvas on a light grey background, always the full window height.
- **Drehen** (only in Tablet/Mobil) switches to landscape: Tablet 1024, Mobil 844.
- **Drag handles** left and right of the page set any width (symmetric, centred, min 320 px, max = window
  width); a small label shows the width while dragging and briefly after each switch. Double click a
  handle = back to the preset width of the view. Keyboard: Tab to a handle, arrow keys ±10 px,
  Shift ±50 px, Home/End = min/max, Enter = preset. **Breite** in the pill sets any width too (Enter).
- **Esc** goes back to Desktop; **Neu laden** reloads the page; **Öffnen** opens it without the viewer.
- Start in a view: `viewer.html?src=kontakt.html&view=mobile` (`view=desktop|tablet|mobile|custom`,
  `w=360` for an own width, `rot=1` for landscape). The last view, rotation and width are remembered per
  browser tab. With reduced motion (system setting) there is no animation.
- A preset wider than the window (e.g. Tablet landscape 1024 in a small window) is scaled down, never
  cropped; the label then says `1024 px · 80%`. The desktop scrollbar of the page is clipped in narrow
  views so it lays out at exactly 390 / 768 px; with classic (non-overlay) scrollbars, media queries and
  `100vw` see the width plus the scrollbar, and the label adds `MQ … px`.
- File names: letters, digits, `-`, `_`, `.`, sub folders allowed; no spaces, no `..`.

## Keep the copy fresh

After a change to `assets/css/base.css`:

```bash
php df-editor/bin/tokens-css.php --framework     # rewrites framework/df-framework.css
```

Copied mockup folders keep their old `df-framework.css`; copy the new one in when needed.
