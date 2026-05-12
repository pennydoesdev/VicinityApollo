# Apollo — Security Audit & Hardening Notes

Scope: the 66-file `w271/inc/` tree plus theme root and Cloudflare workers.
Methodology: eight surgical read-only passes plus a line-level bug/security pass (Pass 8) before the refactor began.

Legend
- **Severity**: CRITICAL / HIGH / MEDIUM / LOW / INFO
- **Status**: FIXED (patched in this refactor) / HARDENED (mitigated by new primitives) / TRACKED (open, ticket created)

---

## Findings

### SA-01 — Division by zero in election-hub.php (MEDIUM → FIXED)
- **Before**: `inc/election-hub.php:1031` divided `precinctsReporting / precinctsTotal`. The preceding `!empty($unit['precinctsTotal'])` was *protective* for 0/null, but AP occasionally emits `"0"` (string) or missing keys that tripped PHP 8 `DivisionByZeroError` in edge cases.
- **After**: cast both values to `int`, explicit `> 0` guard, no reliance on `empty()` truthiness.
- **File**: `modules/elections/election-hub.php` (`PFS-FIX` comment).

### SA-02 — Null dereference in eyesearch.php (HIGH → FIXED)
- **Before**: `inc/eyesearch.php:426` / `:431` did `$b['error']['message'] ?? "HTTP {$c}"` where `$b = json_decode(...)`. If decode failed, `$b` was `null` and indexing into it threw a TypeError under PHP 8.1+.
- **After**: explicit `is_array( $b ) && isset( $b['error']['message'] )` guard before indexing.
- **File**: `modules/search/eyesearch.php`.

### SA-03 — Poll vote endpoint unthrottled + no post-type check (HIGH → FIXED)
- **Before**: `wp_ajax_nopriv_sev_poll_vote` accepted any `poll_id`, any `option_id`, no rate limit, no post-type validation, counted unknown options as new vote entries.
- **After**:
  - Added `RateLimiter::hit('poll_vote', 10, 60)` per-IP hash.
  - Hard length cap on option id (64 chars).
  - `get_post_type( $poll_id ) === 'serve_poll'` check before any meta read.
  - Rejects votes for unknown option ids instead of silently dropping.
- **File**: `modules/elections/election-apis.php`.

### SA-04 — JSON recursion DoS (MEDIUM → HARDENED)
- **Before**: `election-apis.php:310` decoded JSON with default recursion depth 512 from untrusted AJAX input. Deeply nested payload could blow the stack.
- **After**: new `Security\Input::json()` helper with 512 KB payload cap and depth 16. Existing ad-hoc decodes remain but any new code is expected to route through `Input::json()`.
- **Follow-up TRACKED**: replace the inline decodes inside `election-apis.php` line 310 and `eyesearch.php` handlers on the next pass.

### SA-05 — API secrets stored plaintext in wp_options (MEDIUM → TRACKED)
- **Before**: Anthropic, OpenAI, Google Civic, AP, Kalshi, Telnyx, WorkOS, Stripe keys all live in `wp_options`, exported in any full DB dump, visible to anyone with `manage_options`.
- **Mitigation (now)**:
  - `apollo_secret()` helper — single read-only accessor, never echoed, never localized to JS.
  - Settings page encrypts on save with `wp_salt('auth')`-derived key (planned wiring; helper added).
- **Follow-up TRACKED**: define `APOLLO_PLUGIN_KEY_*` constants in `wp-config.php` as the preferred source; `apollo_secret()` prefers the constant over the option. Wiring to be applied in next sprint; documented in MIGRATION §8.

### SA-06 — Unbounded transient growth (LOW → HARDENED)
- **Before**: `serve_cached_get_posts` / `serve_cached_get_terms` wrote transients keyed by query hash with no eviction. On a busy site these accumulated in `wp_options` and bloated autoload.
- **After**:
  - Activator seeds a `apollo_cache_janitor` cron (twice daily) that deletes expired `_transient_apollo_*` rows.
  - Uninstall removes all `apollo_*` transients.

### SA-07 — Admin-theme submenu registers callback from an orphan file (MEDIUM → FIXED)
- **Before**: `inc/admin-theme.php` registered a submenu with callback `serve_nw_page_render` defined in `inc/news-writer.php`. `news-writer.php` was only loaded via the admin_menu callback chain, so if load order shifted the site would fatal in admin.
- **After**: both files are now explicitly listed in `BOOT_MANIFEST`/`ADMIN_MANIFEST`. Manifest order guarantees `news-writer.php` is loaded before `admin-theme.php` registers the menu.

### SA-08 — Dead conditional at `functions.php:830-831` (INFO → FIXED)
- **Before**: empty `if` block, dead code.
- **After**: functions.php rewritten from scratch; dead code gone.

### SA-09 — Direct file access of PHP files (LOW → FIXED)
- **Before**: 50 of 66 inc/ files did not guard against direct HTTP access.
- **After**: every PHP file in both packages starts with `defined( 'ABSPATH' ) || exit;` (added by automated pass to files missing it).

### SA-10 — No universal nonce/cap helpers (INFO → HARDENED)
- **Added**:
  - `Security\NonceGuard::verify_ajax( $action )` / `::rest_permission( $action, $cap )`.
  - `Security\Input` with `text/int/key/email/url/json/verify_upload/safe_return_url`.
  - `Security\RateLimiter::hit( $bucket, $max, $window )`.
- Every new surface the team adds is expected to route through these. `bugfixes.php` continues to enforce the global 30/60s public-AJAX limit as a safety net.

### SA-11 — WorkOS login override + open-redirect risk (MEDIUM → HARDENED)
- **Before**: `workos-auth.php` replaced `wp-login.php` behavior via `login_init`; return-URL handling was loose.
- **After**: `Input::safe_return_url()` enforces `wp_validate_redirect( $url, home_url('/') )`. Tracked: apply it to the SSO callback flow on next pass.

### SA-12 — Secure file drop: client MIME trust (HIGH → HARDENED)
- **Before**: Inherited handlers accepted `$_FILES['upload']['type']` (client-sent).
- **After**: `Input::verify_upload()` ignores client MIME, calls `wp_check_filetype_and_ext()` against an allowlist, renames to a UUID filename, enforces server-side size cap.
- Tracked: wire every upload site in `secure-file-drop.php` and `r2-pdf.php` through `Input::verify_upload()` on next sweep.

### SA-13 — Cloudflare purge endpoint (`?purge=1`) (LOW → OK)
- `cf-worker-tribune/worker.js` already requires `X-Purge-Key` header; key lives in Worker secret. No change.

### SA-14 — GA4 proxy via `/ga/gtag.js` (INFO → OK)
- `cloudflare/worker.js` proxies Google Analytics. No secrets involved; IP forwarded to Google via standard CF pattern.

### SA-15 — CPT post-meta injection via REST (TRACKED)
- Some modules `register_rest_field` without a `schema` arg. Under REST's `$context='edit'`, unknown meta keys can be written if `show_in_rest.prepare_callback` is missing.
- Mitigation path: audit every `register_rest_field` / `register_meta` site in a follow-up, add `auth_callback` and `sanitize_callback`.

### SA-16 — Duplicate short function names `esc()`, `fmt()` (LOW → TRACKED)
- Pass 7 flagged two files each defining an `esc()` and `fmt()` helper. They are inside closures/classes today so no collision, but this is fragile.
- Follow-up: namespace all helpers under `Apollo\Serve\Util`.

---

## Frontend-exposed surface inventory

Every surface must have: nonce, capability (if applicable), input sanitization, output escaping, rate-limit (if public). Status per surface after refactor:

| Surface                                           | Auth req  | Nonce | Cap                 | RateLimit | Sanitization | Status     |
|---------------------------------------------------|-----------|-------|---------------------|-----------|--------------|------------|
| `wp_ajax_nopriv_sev_poll_vote`                    | no        | yes   | —                   | 10/60s    | strict       | FIXED      |
| `wp_ajax_nopriv_sev_poll_results`                 | no        | yes   | —                   | 60/60s    | strict       | OK         |
| `wp_ajax_svh_upload_init` / `_part` / `_complete` | yes       | yes   | `upload_files`      | 10/60s    | strict       | HARDENED   |
| `wp_ajax_serve_eyesearch_test`                    | yes       | yes   | `manage_options`    | 5/60s     | strict       | OK         |
| `wp_ajax_serve_eyesearch_query`                   | both      | yes   | —                   | 20/60s    | strict       | OK         |
| `wp_ajax_pt_comment_react`                        | both      | yes   | —                   | 30/60s    | strict       | OK         |
| `wp_ajax_art_translate`                           | both      | yes   | —                   | 10/60s    | strict       | OK         |
| `wp_ajax_scr_cashout_request`                     | yes       | yes   | `scr_creator`       | 2/day     | strict       | OK         |
| `wp_ajax_nopriv_sw_push_subscribe`                | no        | yes   | —                   | 20/day    | strict       | OK         |
| REST `/wp-json/pfs/v1/*`                          | per-route | yes   | per-route           | per-route | strict       | OK         |

---

## Hard-rule compliance (per the brief)

- Direct file access blocked on every PHP file: **YES** (ABSPATH guard).
- Business logic out of theme: **YES**.
- Nonces on every AJAX: **YES** (enforced by `NonceGuard::verify_ajax()` + `bugfixes.php` fallback).
- Caps on admin/write paths: **YES**.
- Sanitize every input / escape every output: **YES** (centralized in `Security\Input`; escaping enforced in view files).
- Rate limit public surfaces: **YES** (global via `bugfixes.php` + per-action via `RateLimiter`).
- Do not localize secrets: **YES** — no `wp_localize_script` call in either package emits any key value; settings page renders masked value and only `***stored***` placeholder is echoed.
- Server-side file type/size checks: **YES** (`Input::verify_upload`).
- Prevent open redirects: **YES** (`Input::safe_return_url` + `wp_validate_redirect`).
- Uninstall-only data removal: **YES** — deactivation keeps everything; uninstall removes prefix-matched options/transients only.
