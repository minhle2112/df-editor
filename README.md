# DF Editor

WordPress plugin by **Digitale Formate**: a reusable CSS framework, page layouts built from sections that live in the client theme, and front-end content editing. A lightweight, licence-free replacement for page builders.

## Features

- **CSS framework** (`assets/css/base.css`): design tokens from the theme's `theme.json` mapped to colour schemes (`hell`, `hell-alt`, `dunkel`, `primaer`, `akzent`), section wrapper with spacing and width, and a small set of mobile-first layout, type, media and surface utilities (`df-stack`, `df-grid--3`, `df-split`, `df-card`, `df-ratio-4x3`, …). One breakpoint (48em), everything else fluid.
- **Mockup kit** (`framework/`): the framework as a plain CSS file, a generated `tokens.css`, a complete example page and a placeholder image. Mockups built with it are responsive by themselves and convert to theme sections almost 1:1. `bin/tokens-css.php` turns any `theme.json` into `tokens.css` without WordPress.
- **Theme sections**: every section is written per client in `<theme>/df-sections/<type>/` (`section.json` + `section.php` + optional `style.css` / `view.js`), registered from the theme and child theme. The starter theme ships three examples (hero, text & image, cards).
- **Page layouts as JSON** (post meta `_df_layout`): ordered sections with variant, scheme, spacing, width, options and initial content; CLI import/export/validation, history of 30 revisions, admin metabox, plain-text fallback when the plugin is off.
- **DF Inhalt**: click-to-edit texts, buttons and images directly on the page, plus page comments, for clients and editors. Clients edit content, the agency changes layouts in code.
- **DF Design**: global colours, fonts, radius, button style and scroll animations in the Customizer, with contrast checks (logo: core Site Identity / Website-Informationen, `custom_logo`).
- **DF Motion**: optional scroll entrance animations (fade / fade-up with staggered items), switched on in DF Design and declared per section (`section.json` `motion`); off in edit mode, with reduced motion and without JS.
- **Forms adapter** for Contact Form 7 and Fluent Forms (honeypot, assets only where a form is printed).
- **Post types** `df_team` and `df_referenz` for lists the client maintains.
- Section helpers (`df_field_filled()`, `df_heading_tag()`, `df_section_head()`, `df_item_setting()`) and an icon set (`df_icon()`), loaded on `after_setup_theme` (a theme may define its own version first).
- Updates from GitHub releases (`DF_EDITOR_UPDATE_REPO`).

## Requirements

- WordPress 6.4+ (tested on 7.1)
- PHP 8.0+
- A theme with `theme.json` design tokens and its own `df-sections/` (company starter theme recommended)

## Installation

1. Download the latest release zip and upload it under **Plugins → Add New → Upload Plugin**, or clone this repository into `wp-content/plugins/df-editor`.
2. Activate **DF Editor**.
3. Optional, for automatic updates, add to `wp-config.php`:

   ```php
   define( 'DF_EDITOR_UPDATE_REPO', 'https://github.com/minhle2112/df-editor/' );
   ```

## WP-CLI

```bash
wp df validate --all                          # validate all page layouts
wp df import layout.json --create --title="Home" --front
wp df export home > home.json
wp df fallback --all                          # rewrite plain-text fallbacks (before deploys)
wp df orphans                                 # saved texts whose field no longer exists
wp df sections                                # list the theme's sections
wp df docs                                    # write <theme>/df-sections/SECTIONS.md
wp df preview-token --type=hero               # single-section preview URL (screenshots)
wp df-forms                                   # list CF7 / Fluent Forms forms
```

Without WordPress:

```bash
php bin/tokens-css.php <theme>/theme.json > tokens.css   # tokens for a plain-HTML mockup
php bin/tokens-css.php --framework                       # refresh framework/df-framework.css after base.css changes
```

## Development

- Conventions (section.json schema, field IDs, layout JSON, CLI): `docs/CONVENTIONS.md` in the DF Blocks project; framework classes and mockup workflow: `docs/FRAMEWORK.md`; mockup kit: [`framework/README.md`](framework/README.md).
- Field IDs (`{page_key}.{instance_id}.{field}`) must never change once published: never rename or delete a field, list or option key of a live section.
- Releases: push a tag `vX.Y.Z` matching the `Version:` header; the GitHub workflow lints, checks the version and attaches the plugin zip.

## Upgrading from 0.x (breaking: 1.0.0)

1.0.0 removes the plugin section library (`sections/`) and the Gutenberg integration (`df/*` blocks,
`wp df to-blocks` / `to-layout`, REST `df/v1/blocks`). Sections now live in the client theme.

- Pages whose layout uses a removed library section show a "Section not available" notice to editors
  (nothing for visitors) until the section exists in the theme (`<theme>/df-sections/<type>/`) or the
  layout is rebuilt. `wp df fallback` / `wp df import` keep the existing plain-text fallback of such
  pages (status `skipped-missing-sections`) instead of emptying it.
- Pages built from DF blocks (`<!-- wp:df/… -->` in the content) show the same notice per block to
  editors. On update, pages converted with `wp df to-blocks` get their JSON layout back from the
  converter backup (`_df_layout_converted`, kept); other block pages must be rebuilt as layouts.

## Licence

GPL-2.0-or-later, see [LICENSE](LICENSE). Third-party notices: [`content/CREDITS.md`](content/CREDITS.md), [`vendor/plugin-update-checker/license.txt`](vendor/plugin-update-checker/license.txt).

© Digitale Formate
