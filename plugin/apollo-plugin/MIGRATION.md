# Apollo — Theme / Plugin Split Migration Guide

This refactor splits the monolithic `w271` theme into two packages:

- **`apollo-theme/`** — presentation only: templates, views, CSS, appearance Customizer, block styles, theme supports.
- **`apollo-plugin/`** — business logic: CPTs, taxonomies, AJAX, REST, cron, integrations, auth, analytics, creator revenue, file drop, Elections, Video Hub, Audio Hub, AI, admin settings.

The split follows the surgical-refactor rule: **do not rewrite, do not break, do not leave business logic in the theme**.

---

## 1. Install order

```
1. Upload and activate  apollo-plugin.zip   (the plugin)
2. Upload and activate  apollo-theme.zip   (the theme)
3. Visit  Settings → Permalinks → Save        (re-flush rewrite rules)
4. Visit  Plugins → Apollo → Setup   (one-time config sync)
```

If you activate the theme **before** the plugin, you will see an admin notice:
> Apollo Theme: the companion plugin "Apollo" is not active.

The site will still render — reader-facing features will degrade to safe placeholders (empty for readers, visible notices for editors). No fatal errors.

---

## 2. What moved — module-by-module

| Old location (in `w271/inc/`)                              | New home                                             | Reason                                                  |
|------------------------------------------------------------|------------------------------------------------------|---------------------------------------------------------|
| `workos-auth.php`                                          | `apollo-plugin/modules/auth/`                      | SSO, login override, daily sync cron                    |
| `secure-file-drop.php`                                     | `apollo-plugin/modules/secure-drop/`               | File upload, signed URLs, audit log                     |
| `election-hub.php`, `election-apis.php`                    | `apollo-plugin/modules/elections/`                 | CPT + AP/Civic/Kalshi integrations                      |
| `video-hub.php`, `video-hub-studio.php`, `yt-live.php`, `live-updates.php` | `apollo-plugin/modules/video/`     | `serve_video` CPT + R2 upload + HLS + paywall           |
| `audio-hub.php`, `podcast-studio.php`, `live-radio.php`    | `apollo-plugin/modules/audio/`                     | `serve_podcast`/`serve_episode` CPT + RSS feeds         |
| `editorial-workflow.php`, `editor-pro.php`, `news-writer.php`, `content-hub.php`, `author-profile.php`, `internal-links.php`, `social-share-studio.php` | `apollo-plugin/modules/editorial/` | Workflow, editor tools, authoring                  |
| `claude-ai.php`, `takeaways.php`                           | `apollo-plugin/modules/ai/`                        | LLM integrations                                        |
| `article-translate.php`                                    | `apollo-plugin/modules/translation/`               | Featherless translation                                 |
| `eyesearch.php`                                            | `apollo-plugin/modules/search/`                    | AI search FAB                                           |
| `comments-hub.php`                                         | `apollo-plugin/modules/comments/`                  | Threaded comments + reactions                           |
| `creator-revenue.php`                                      | `apollo-plugin/modules/creator-revenue/`           | 30/70 split, fraud prevention, cashout                  |
| `custom-roles.php`                                         | `apollo-plugin/modules/roles/`                     | Custom caps — must survive theme switch                 |
| `newsletter.php`                                           | `apollo-plugin/modules/newsletter/`                |                                                         |
| `analytics.php`                                            | `apollo-plugin/modules/analytics/`                 |                                                         |
| `ad-manager.php`, `hilltopads.php`                         | `apollo-plugin/modules/ads/`                       |                                                         |
| `sponsored.php`                                            | `apollo-plugin/modules/sponsored/`                 |                                                         |
| `today-in-history.php`                                     | `apollo-plugin/modules/today-in-history/`          |                                                         |
| `ethical-ai-badge.php`                                     | `apollo-plugin/modules/ethical-ai/`                |                                                         |
| `marketing-hub.php`                                        | `apollo-plugin/modules/marketing/`                 |                                                         |
| `static-gen.php`                                           | `apollo-plugin/modules/static-gen/`                |                                                         |
| `integrations.php`, `donorbox.php`, `telnyx-storage.php`, `r2-pdf.php`, `serve-image.php`, `serve-image-tools.php` | `apollo-plugin/integrations/` |                                                         |
| `cache-control.php`, `cdn-edge.php`, `pagespeed.php`, `serve-future.php`, `advanced.php` | `apollo-plugin/includes/`           |                                                         |
| `infinite-scroll.php`                                      | `apollo-plugin/public/`                            | Frontend but REST-backed → plugin                       |
| `bugfixes.php`                                             | `apollo-plugin/security/`                          | Security patches                                        |
| `admin-theme.php`, `penny-admin-skin.php`, `perf-admin.php`, `dashboard-widget.php` | `apollo-plugin/modules/admin-ui/` / `modules/dashboard/` | Admin-only |
| `customizer.php` (3,157 lines, mixed)                      | Split: appearance → `apollo-theme/inc/customizer-appearance.php`. Everything else → `apollo-plugin/modules/admin-ui/customizer-app.php` |
| `cloudflare/`, `cf-worker-tribune/`                        | `apollo-plugin/cloudflare/`                        | Edge + PWA workers ship with plugin                     |

### What stayed in the theme (presentation only)

Root view files: `index.php`, `single*.php`, `archive*.php`, `taxonomy-*.php`, `page.php`, `header.php`, `footer.php`, `sidebar.php`, `comments.php`, `search.php`, `searchform.php`, `author.php`, `category.php`, `404.php`, `front-page.php`, `sah-subscriptions.php`.

Templates: `templates/tpl-*.php`.

Template parts: `template-parts/*`.

Presentational helpers: `inc/performance.php` (CSS consolidation pipeline — critical per CONTEXT.md), `inc/minifier.php`, `inc/template-tags.php`, `inc/block-patterns.php`, `inc/blocks/`, `inc/homepage-layout.php`, `inc/homepage-extras.php`, `inc/layout-renderers.php`, `inc/sidebar-blocks.php`, `inc/video-hub-layout.php`, `inc/single-bottom.php`, `inc/news-ticker.php`, `inc/category-helpers.php`, `inc/extras.php`, `inc/extended.php`.

New theme files:
- `inc/plugin-bridge.php` — the ONLY way the theme talks to the plugin.
- `inc/customizer-appearance.php` — appearance-only Customizer.
- slimmed `functions.php`.

Assets unchanged: `assets/css/*`, `assets/js/*`, `assets/fonts/*`, `assets/images/*`, `images/*`, `style.css`, `style.min.css`, `screenshot.png`, `sw.js`.

---

## 3. Compatibility / bridge layer

All theme templates now call plugin features through wrappers in `apollo-theme/inc/plugin-bridge.php`. If the plugin is inactive, each wrapper returns `''` (for readers) or an editor-visible placeholder.

| Theme helper                                  | Replaces direct call to                  |
|-----------------------------------------------|------------------------------------------|
| `apollo_plugin_active()`                   | —                                        |
| `apollo_call( $fn, ...$args )`             | any plugin function                      |
| `apollo_has_module( $slug )`               | `Plugin::has_module()`                   |
| `apollo_render( $slug, $args )`            | runs `apollo_render_{slug}` filter    |
| `apollo_is_post_paywalled( $id )`          | `svh_post_is_paywalled()` (shimmed)      |
| `apollo_user_can_access( $id )`            | `svh_current_user_has_access()` (shimmed)|
| `apollo_video_player_html( $id )`          | `svh_player_html()`                      |
| `apollo_election_race_html( $id )`         | `sev_render_race()`                      |
| `apollo_takeaways_html( $id )`             | `serve_takeaways_render()`               |
| `apollo_comments_hub_html( $id )`          | `pt_comments_render()`                   |
| `apollo_eyesearch_fab_html()`              | `serve_eyesearch_fab()`                  |
| `apollo_translate_button_html( $id )`      | `art_translate_button()`                 |
| `apollo_newsletter_form_html( $args )`     | `newsletter form render`                 |
| `apollo_today_in_history_html()`           | `tih_render()`                           |

Back-compat shims `svh_post_is_paywalled()` and `svh_current_user_has_access()` are declared in the bridge only when the plugin is inactive, so inherited template calls do not fatal.

### Plugin side

Each module that needs to be callable by the theme registers with:

```php
apollo_bridge( 'video-player', function ( array $args ) : string {
    $post_id = (int) ( $args['post_id'] ?? 0 );
    return svh_player_html_impl( $post_id );
} );
```

---

## 4. Changed hook / function names

| Old                                      | New                                          | Notes                                               |
|------------------------------------------|----------------------------------------------|-----------------------------------------------------|
| `SERVE_VERSION` (theme)                  | `APOLLO_PLUGIN_VERSION` (plugin)           | `SERVE_VERSION` is aliased in plugin for back-compat|
| Direct `svh_player_html()` call          | `apollo_video_player_html( $id )`         | via bridge                                          |
| Direct `svh_post_is_paywalled()` call    | `apollo_is_post_paywalled( $id )`         | shim retained                                       |
| `serve_add_consolidated_css()`           | unchanged — stays in theme `inc/performance.php` | 12 modules depended on it              |

No existing AJAX actions or REST routes were renamed. Nonce names (`sev_public_nonce`, `svh_upload_nonce`, etc.) are preserved so in-flight JS keeps working.

---

## 5. Activation / deactivation behavior

**Activation** (`plugins.php → Activate`):
- Creates `{prefix}scr_earnings`, `{prefix}scr_view_log`, `{prefix}psrv_secure_drop_log` tables via `dbDelta`.
- Seeds default options (rate limits, enabled modules).
- Schedules cron: `apollo_elections_poll` (2 min), `apollo_newsletter_send` (hourly), `apollo_wos_daily_sync` (daily), `apollo_scr_payout_sweep` (daily), `apollo_cache_janitor` (twicedaily).
- Flushes rewrite rules.

**Deactivation**:
- Unschedules all plugin cron.
- Clears `_transient_apollo_*` transients.
- Flushes rewrites.
- **Does not drop tables or posts.**

**Uninstall** (`Plugins → Delete`, runs `uninstall.php`):
- Removes options matching prefix allowlist: `apollo_`, `apollo_`, `scr_`, `svh_`, `sev_`, `wos_`.
- Clears `apollo_*` transients.
- Final sweep of cron events.
- **Still does not drop tables or delete CPT posts** — users re-installing should not lose content.

A separate "Erase all data" action will live in the plugin admin for destructive cleanup.

---

## 6. Database / options / meta impacts

No schema migrations required.

- CPTs (`serve_video`, `serve_podcast`, `serve_episode`, `serve_election`, `serve_poll`) are re-registered by the plugin with identical slugs, labels, and supports arrays — existing posts keep working.
- Custom roles (`serve_editor`, `serve_contributor`, etc.) are re-seeded by the plugin's `modules/roles/custom-roles.php` with matching names. Existing users retain their roles.
- Post meta keys (`_svh_hls`, `_svh_thumb`, `_jetpack_membership_paid`, `_sev_ap_race_id`, `_scr_cashout_threshold`, etc.) are untouched.
- Options (`serve_ai_provider`, `serve_ai_anthropic_key`, `serve_cf_r2_endpoint`, etc.) are read from the same option names by the plugin.
- wp-cron hooks are renamed to the `apollo_*` prefix. A one-shot migration inside `Activator::schedule_cron()` unschedules the old `svh_*`/`sev_*`/`wos_*` hooks on first activation (see code).

If you previously stored API keys by hand, they continue to work — no re-entry needed.

---

## 7. Rollback

- Deactivate the plugin → the theme renders with placeholders, no data loss.
- Re-upload the original `w271` theme and deactivate `apollo-theme` → site reverts to pre-refactor behavior.
- All data (posts, meta, options, tables) is preserved.

---

## 8. Known limitations of this first cut

- `creator-revenue.php` was formerly "orphaned" (loaded via `admin_menu` callback only). It is now explicitly loaded by the plugin manifest.
- `customizer.php` was split mechanically: the **entire** original file still lives inside the plugin as `customizer-app.php`. Manual cleanup of appearance-duplicated sections is a follow-up task.
- Cloudflare worker code ships inside the plugin zip under `cloudflare/`. Deploy it with `wrangler` from that path.
- Service worker (`sw.js`) stays with the theme because it is served at site root and references presentational assets.
