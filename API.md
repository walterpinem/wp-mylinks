# WP MyLinks — public API

The hooks and data shapes below are **public API**: they follow semantic
versioning and will not break within a major version. Anything not listed here
is internal and may change without notice.

Applies to WP MyLinks 1.1.0+.

---

## Template actions

All fire in the standalone bio-page template and receive the current MyLink
post ID.

| Hook | Where |
|---|---|
| `wp_mylinks_head` | Inside `<head>`, after the plugin's own assets and user scripts |
| `wp_mylinks_body_open` | Immediately after `<body>` |
| `wp_mylinks_before_links` | Before the `<div class="links">` list |
| `wp_mylinks_after_links` | After the links list, inside `<main>` |
| `wp_mylinks_footer` | Inside `<footer>`, before its closing tag |

## Rendering filters

### `wp_mylinks_link_data( array $link, int $post_id, int $link_index )`

Filters one link row's data before it renders. `$link` is the stored group
row — keys: `title`, `select_url`, `url`, `image`, `image_id`, `card-layout`,
`youtube-video`, `media-embed` (empty subvalues may be absent). Return an
empty value to skip the link entirely (scheduling, targeting, A/B).

### `wp_mylinks_link_render( string $item_html, array $link, int $post_id, int $link_index )`

Filters one rendered link item's escaped HTML (URL rewriting, extra
attributes, wrappers). Return `''` to drop the item.

### `wp_mylinks_schema_data( array $data, int $post_id )`

The Schema.org array (`@context` + `@graph`) before JSON encoding.

### `wp_mylinks_og_meta( array $og_lines, int $post_id )`

The pre-escaped Open Graph / Twitter Card `<meta>` lines.

### `wp_mylinks_cpt_rewrite_slug( string $slug )`

The internal CPT rewrite slug (default `mylink`). Public URLs are
slug-stripped either way.

### `wpmylinks_url_params( array $params, string $base_url )`

Campaign parameters appended to the plugin's own outbound links.

## Admin extension surface

### `wp_mylinks_meta_boxes( array $boxes )`

The registered metabox definitions, keyed by box id. Each definition is the
array shape accepted by `wp_mylinks_register_metabox()` (a CMB2-like array:
`id`, `title`, `description`, `object_types`, `context`, `priority`,
`fields[]`). This is the registration surface for extensions — add, remove,
or amend boxes here.

### `wp_mylinks_field_types( array $map )`

Field type string → class map. Built-in types: `text`, `text_url`,
`textarea_small`, `select`, `radio`, `radio_inline`, `wysiwyg`, `file`,
`oembed`, `group`, `pw_select`, `colorpicker`. Classes must extend
`Wp_Mylinks_Field`.

### `wp_mylinks_settings_tabs( array $tabs )`

Settings-page tabs, keyed by `?tab=` slug. Each entry:
`array( 'dashicon-slug', 'Label', callable $render )` — the callable renders
the tab body and is required for added tabs. Keep added tabs before
`tutorial_support` (Support stays last by house convention).

## Analytics

### Action `wp_mylinks_track_link_click( int $post_id, string $url, string $hash, int $count )`

Fires after a link click is recorded by the built-in counter.

### Data shapes

| Meta key | Shape |
|---|---|
| `wp_mylinks_count_visits` | `int` — page views, incremented per non-bot render |
| `wp_mylinks_link_clicks` | `array( md5( link URL ) => int )` — clicks per link; keyed by URL hash so counts survive row reordering. `wp_mylinks_get_link_clicks_total( $post_id )` sums it. |

## Not public

Everything else — including the `Wp_Mylinks_*` class internals, the template's
local variables, CSS class names on the admin screens, and the `_wml`-prefixed
request keys — is internal. If you need something exposed, open a feature
request instead of reaching into internals.
