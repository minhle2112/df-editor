# DF Editor

WordPress plugin by **Digitale Formate**: a shared section library, page layouts and front-end content editing, built as a lightweight, licence-free replacement for page builders.

## Features

- **Section library** (`sections/<type>/`): hero, text & image, services, benefits, testimonials, call to action, FAQ, text block, team, gallery, form, page content. Each section has variants, colour schemes, spacing and width options, and reads design tokens from the theme's `theme.json`.
- **Gutenberg blocks**: sections are registered as native dynamic blocks (`df/<type>`) generated from `section.json`, so pages can be arranged in the block editor.
- **Page layouts as JSON** (post meta), with CLI import/export and validation.
- **DF Inhalt**: click-to-edit texts and images directly on the page, for clients and editors.
- **DF Design**: global colours and fonts in the Customizer, with contrast checks.
- Forms adapter (Contact Form 7, Fluent Forms), team and reference post types, plain-text fallback when the plugin is off.
- Updates from GitHub releases (`DF_EDITOR_UPDATE_REPO`).

## Requirements

- WordPress 6.4+ (tested on 7.1)
- PHP 8.0+
- A theme with `theme.json` design tokens (company starter theme recommended)

## Installation

1. Download the latest release zip and upload it under **Plugins → Add New → Upload Plugin**, or clone this repository into `wp-content/plugins/df-editor`.
2. Activate **DF Editor**.
3. Optional, for automatic updates, add to `wp-config.php`:

   ```php
   define( 'DF_EDITOR_UPDATE_REPO', 'https://github.com/minhle2112/df-editor/' );
   ```

## WP-CLI

```bash
wp df validate --all              # validate all page layouts
wp df import layout.json --create --title="Home"
wp df export home
wp df to-blocks <page>            # JSON layout -> Gutenberg blocks
wp df to-layout <page>            # Gutenberg blocks -> JSON layout
wp df fallback --all              # rewrite plain-text fallbacks
wp df sections                    # list sections
```

## Development

- Section conventions: [`docs/SECTIONS.md`](docs/SECTIONS.md)
- Field IDs (`{page_key}.{instance_id}.{field}`) must never change once published; `php bin/check-field-ids.php` checks this against `field-ids.lock.json`.
- Releases: push a tag `vX.Y.Z` matching the `Version:` header; the GitHub workflow lints, checks field IDs and attaches the plugin zip.

## Licence

GPL-2.0-or-later, see [LICENSE](LICENSE). Third-party notices: [`content/CREDITS.md`](content/CREDITS.md), [`vendor/plugin-update-checker/license.txt`](vendor/plugin-update-checker/license.txt).

© Digitale Formate
