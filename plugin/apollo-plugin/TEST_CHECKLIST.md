# Apollo — Test Checklist (Pre-Deploy)

Run this on a staging site that is a copy of production. Do not run on production.

## A. Install & activate

- [ ] Upload `apollo-plugin.zip` via Plugins → Add New → Upload. Activate.
- [ ] Upload `apollo-theme.zip` via Appearance → Themes → Add New → Upload. Activate.
- [ ] Visit Settings → Permalinks → Save (re-flush rewrites).
- [ ] Check `wp-content/debug.log` — no PHP warnings/notices on activation.

## B. Plugin-inactive degradation

- [ ] With theme active, deactivate Apollo plugin.
- [ ] Visit the homepage as an anonymous reader. Page renders, no PHP errors, no white screen.
- [ ] Visit a single video post. Instead of player, nothing visible to the reader.
- [ ] Log in as editor. Visit same pages. Placeholder boxes are visible with the feature slug.
- [ ] Reactivate plugin. All features return.

## C. Core CPTs

- [ ] `serve_video` archive at `/videos/` lists videos.
- [ ] `serve_podcast` / `serve_episode` archive lists episodes.
- [ ] `serve_election` archive renders.
- [ ] `serve_poll` votes tracked.
- [ ] All CPT permalinks 200.

## D. Paywall / access

- [ ] Visit a paywalled video as anonymous. See "subscribe" placeholder.
- [ ] Log in as a paid Jetpack subscriber (`_jetpack_membership_paid` meta set). Player appears.
- [ ] Theme function `apollo_is_post_paywalled( $id )` returns correct bool for both cases.

## E. AJAX surfaces

For each, confirm (i) no nonce → 403, (ii) valid nonce → success, (iii) rate-limit kicks in.

- [ ] `sev_poll_vote` — vote once, vote again in same session → same-vote response; 11 votes in 60s from same IP → 429.
- [ ] `sev_poll_vote` with bogus `poll_id` (not a serve_poll) → error.
- [ ] `sev_poll_vote` with bogus `option_id` → "Unknown option" error.
- [ ] `svh_upload_*` — upload requires `upload_files` cap.
- [ ] `serve_eyesearch_query` — returns AI result; excessive requests throttled.
- [ ] `pt_comment_react` — add reaction emoji, reload → count reflects.
- [ ] `art_translate` — translate an article to RU/FR/ES.

## F. REST surfaces

- [ ] `/wp-json/pfs/v1/*` routes all return 401/403 without nonce or appropriate cap.
- [ ] Schema visible via `?context=help` (where applicable).

## G. Cron

- [ ] `wp cron event list` shows `apollo_elections_poll` (2 min), `apollo_newsletter_send` (hourly), `apollo_wos_daily_sync` (daily), `apollo_scr_payout_sweep` (daily), `apollo_cache_janitor` (twicedaily).
- [ ] Run `wp cron event run apollo_cache_janitor` → transients prefixed `_transient_apollo_` below TTL are removed.

## H. Auth

- [ ] Visiting `/wp-login.php` redirects to WorkOS SSO (if enabled).
- [ ] SSO callback with invalid return URL is blocked (not redirected off-site).
- [ ] Daily `apollo_wos_daily_sync` runs without error in staging.

## I. Secure file drop

- [ ] Uploading a file with client-spoofed `Content-Type: application/pdf` but body is PHP → rejected.
- [ ] File > configured size cap → rejected server-side.
- [ ] Filename with `../../` → sanitized, stored with UUID name.
- [ ] Audit row written to `{prefix}psrv_secure_drop_log`.

## J. Elections live data

- [ ] AP Elections polling populates `_sev_*` post meta.
- [ ] Race with `precinctsTotal=0` does NOT throw DivisionByZeroError (SA-01 fix).
- [ ] Kalshi / Civic routes return 200 with valid nonce, 403 without.

## K. AI search (eyesearch)

- [ ] Click search FAB on frontend → modal opens.
- [ ] Submit query with invalid Anthropic key → polite error (no TypeError) (SA-02 fix).
- [ ] Submit with valid key → streamed/complete answer.

## L. Creator revenue

- [ ] View a video for ≥ 45 s → row inserted in `{prefix}scr_view_log`.
- [ ] Earnings aggregated in `{prefix}scr_earnings` after cron run.
- [ ] Same IP watching >80× in 24h → cut off at cap.
- [ ] Cashout request below threshold → rejected.

## M. Comments

- [ ] 4-level threading visible.
- [ ] Emoji reactions register.
- [ ] `pt_comment_react` does not accept reactions from unauthenticated users beyond rate limit.

## N. Appearance (theme)

- [ ] Customizer → Theme Appearance panel appears.
- [ ] Color/font/size changes apply via CSS custom properties.
- [ ] All legacy app-level Customizer sections now appear under the plugin's Customizer panel, not the theme's.

## O. Performance (CSS pipeline)

- [ ] `serve_add_consolidated_css()` still defined (theme side, presentational).
- [ ] A single `<style id="serve-consolidated-css">` emitted in `<head>`.
- [ ] Emoji script not loaded on frontend.
- [ ] LCP / CLS no regression from baseline.

## P. Security smoke tests

- [ ] `curl https://site/wp-content/plugins/apollo-plugin/modules/elections/election-hub.php` → empty response or 200 with empty body (ABSPATH exits).
- [ ] `grep -r "wp_localize_script" plugin/` → no script is passed an API key or option value containing `key`/`secret`/`token`.
- [ ] All uploads in `{prefix}psrv_secure_drop_log` tagged with status and size.

## Q. Rollback

- [ ] Deactivate plugin → no fatal errors, content intact.
- [ ] Switch back to original `w271` theme → site renders as before.
- [ ] Reactivate both → no duplicate CPT / role registration (idempotent).
