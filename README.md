# WP MyLinks

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/wp-mylinks)](https://wordpress.org/plugins/wp-mylinks/)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/wp-mylinks)](https://wordpress.org/plugins/wp-mylinks/)
[![Rating](https://img.shields.io/wordpress/plugin/rating/wp-mylinks)](https://wordpress.org/plugins/wp-mylinks/reviews/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](LICENSE.txt)

The self-hosted Linktree alternative for WordPress. Build unlimited link-in-bio pages on your own domain: your links, your data, your branding, no monthly fee.

**[Plugin page on WordPress.org](https://wordpress.org/plugins/wp-mylinks/)** · [Website](https://www.onlinestorekit.com/wp-mylinks/) · [Documentation](https://www.onlinestorekit.com/docs/wml/) · [Changelog](https://www.onlinestorekit.com/changelog/wp-mylinks/) · [Support](https://www.onlinestorekit.com/support/)

## Why self-hosted?

A bio link service puts your audience behind someone else's domain, analytics, and terms. WP MyLinks renders a fast, standalone bio page straight from your WordPress site. Visitors never leave your domain and no visitor data is sent to third parties: every asset ships with the plugin and is served locally.

## Features

- **Unlimited MyLink pages** with avatar, name, bio, and per-page SEO (meta, Open Graph, schema)
- **Link rows of every kind**: buttons with or without thumbnails, image cards, YouTube players, oEmbeds (TikTok, Spotify, X and more), section headings, and HTML blocks
- **16 built-in themes** plus per-page accent colors and custom fonts
- **Social icon row** for 8 major platforms plus unlimited additional platforms with custom icons
- **Search bar** to filter long link lists, client-side
- **Click counter** with a per-link Clicks column, cache-safe by design
- **QR code** for every page, generated in the browser
- **Import/export tools** for settings and pages, including single-page transplants
- **Google Tag Manager** dataLayer event on link clicks
- **Collections** for reusable link libraries across pages

## Requirements

- WordPress 6.0 or newer (tested up to 7.1)
- PHP 7.4 or newer

## Installation

Install from the WordPress plugin directory (search for "WP MyLinks"), or upload the zip from the [wp.org listing](https://wordpress.org/plugins/wp-mylinks/). Then create your first page under **MyLinks → Add New**.

## For developers

The plugin exposes a documented public API: filters and actions for the link render loop, a native metabox/field framework you can register your own boxes and field types against, settings tabs, and click-tracking events. See [`API.md`](API.md) for the full surface with examples. Extensions built on these hooks survive updates; the same API powers [WP MyLinks Pro](https://www.onlinestorekit.com/wp-mylinks/).

This repository contains the production source tree that ships to WordPress.org. Development tooling and release scripts live outside the public repo.

## Reporting issues

- Bugs and questions: [wp.org support forum](https://wordpress.org/support/plugin/wp-mylinks/) or the [support form](https://www.onlinestorekit.com/support/)
- Security reports: please email support@onlinestorekit.com rather than opening a public issue

## License

GPL-2.0 or later. See [`LICENSE.txt`](LICENSE.txt). Bundled third-party libraries are credited in [`README.txt`](README.txt).
