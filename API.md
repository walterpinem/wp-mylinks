# WP MyLinks — public API

The hooks and data shapes below are **public API**: they follow semantic
versioning and will not break within a major version. Anything not listed here
is internal and may change without notice.

Applies to WP MyLinks 1.1.0+. Items marked 1.2.0 are new in that release.

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

### `wp_mylinks_url_params( array $params, string $base_url )`

Campaign parameters appended to the plugin's own outbound links. This filter
was named `wpmylinks_url_params` before 1.2.0; the old name still works but is
deprecated and logs a notice when a callback is hooked to it.

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

### The `'agent' => false` flag (1.2.0)

A metabox definition, or any field inside it, can carry `'agent' => false`. That box or field is out of reach for AI agents entirely: it is never in an ability schema, never returned by a read and never written. The built-in Custom Scripts & Styles box uses it. Without the flag a field is available to agents, provided its type has a schema (see `wp_mylinks_agent_field_schema`).

## Analytics

### Action `wp_mylinks_track_link_click( int $post_id, string $url, string $hash, int $count )`

Fires after a link click is recorded by the built-in counter.

### Data shapes

| Meta key | Shape |
|---|---|
| `wp_mylinks_count_visits` | `int` — page views, incremented per non-bot render |
| `wp_mylinks_link_clicks` | `array( md5( link URL ) => int )` — clicks per link; keyed by URL hash so counts survive row reordering. `wp_mylinks_get_link_clicks_total( $post_id )` sums it. |

### `wp_mylinks_is_bot_request( bool $is_bot )` (1.2.0)

Filters whether the current request is left out of page-visit and link-click counts. The built-in check is a user-agent match for common crawlers. Return `true` to leave a request uncounted. The plugin returns `true` while a person previews an agent's proposed change, so looking at a proposal never counts as a visit.

## AI agents (1.2.0)

AI agents reach MyLinks through WordPress Abilities (WordPress 6.9 and newer), exposed over MCP by the separately installed MCP Adapter plugin. Access is a site setting: Off (default), Read only or Read and edit. Add-ons that register their own abilities, such as WP MyLinks Pro, use the functions and filters below and never read the settings themselves.

### `wp_mylinks_agent_allows( string $level ): bool`

May agents work at this level on this site right now? `$level` is `'read'` or `'write'`. It returns `false` when WordPress is older than 6.9, when the setting is lower than `$level`, or when code has lowered the level with `wp_mylinks_agent_access_level`. Call it before registering an ability, so nothing is registered when access is off.

### `wp_mylinks_agent_gate( string $level ): true|WP_Error`

The check every ability callback should make first, in both its permission callback and its execute callback, because the setting can change between registration and a call. It returns `true`, or a `WP_Error` with status 401 (no signed-in user) or 403 (access below `$level`). Return the error unchanged.

### `wp_mylinks_agent_ability_meta( bool $readonly, bool $destructive, bool $idempotent ): array`

The `meta` array to pass to `wp_register_ability()`. It sets the three annotations, exposes the ability over MCP (`mcp.public` and `mcp.type` set to `tool`) and keeps it out of core's Abilities REST routes (`show_in_rest` false). Register abilities in the `wp-mylinks` category, after WP MyLinks has registered it.

### `wp_mylinks_agent_field_schema( array|null $schema, array $field )`

Filters the JSON Schema an agent gets for one field. `$field` is the field's registry definition. `$schema` is `null` for field types WP MyLinks has no mapping for, and such a field stays out of reach unless a schema array is returned. Return `$schema` unchanged for types you do not own. Use it to map the custom field types an add-on registers through `wp_mylinks_field_types`.

### `wp_mylinks_agent_access_level( string $level )`

Filters the access level in force: `'off'`, `'read'` or `'write'`. A filter can only lower the level the site owner chose. Returning a higher level, or anything that is not a level, is ignored. Use it to switch agents off for a staging site or to force read-only access.

### Action `wp_mylinks_agent_changed( int $post_id, array $entry )`

Fires after an agent event is added to a page's change log. `$entry` is `array( 'time' => int, 'user' => int, 'event' => string, 'ability' => string, 'fields' => string[] )`. `event` is one of `create`, `write`, `propose`, `approved`, `discard`, `undo` or `keep`. `ability` is the ability name for agent events and an empty string for a person's action (`approved`, `discard`, `undo`, `keep`). `fields` lists the agent field keys involved. It fires on every logged event, so use it for notifications or an audit trail.

## Not public

Everything else — including the `Wp_Mylinks_*` class internals, the template's
local variables, CSS class names on the admin screens, and the `_wml`-prefixed
request keys — is internal. If you need something exposed, open a feature
request instead of reaching into internals.
