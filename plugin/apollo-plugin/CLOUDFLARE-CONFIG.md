# Apollo Plugin — Cloudflare & R2 Configuration Guide

**Version:** 1.75+  
**For developers and future maintainers**

---

## Overview

All Cloudflare credentials and R2 bucket configuration for the Apollo plugin live in **one place**: the `Cloudflare` top-level menu in the WordPress admin sidebar.

This page manages credentials for:
- **Video Hub** — video uploads and player URLs
- **Audio Hub** — podcast/episode audio uploads and RSS feed URLs
- **Secure Drop** — encrypted file drops
- **R2-PDF** — PDF embedding in articles

One set of API credentials (Account ID, Access Key, Secret Key) signs requests for **all** buckets. Each hub can optionally use its own dedicated bucket and custom domain.

---

## Architecture

### Config Function Hierarchy

```
wp-config.php constants (highest priority — never disappear)
    ↓
apollo_cf_config()             ← central store (apollo_cf_* options)
    ↓
apollo_cf_video_config()       ← video bucket/URL resolved (video_bucket ?: bucket)
apollo_cf_audio_config()       ← audio bucket/URL resolved (audio_bucket ?: bucket)
    ↓
svh_r2_config()                ← alias for apollo_cf_video_config() — used by video MPU handlers
sah_r2_config()                ← alias for apollo_cf_audio_config() — used by audio MPU handlers
```

### Signing Functions (video-hub.php)

Both signing functions accept an optional `$cfg` parameter:

```php
svh_r2_aws_auth_headers( $method, $key, $ct, $sha256, $extra_query, ?array $cfg = null )
svh_r2_presign_part_url( $key, $upload_id, $part_num, $expires, ?array $cfg = null )
svh_r2_public_url( $key, ?array $cfg = null )
```

- When `$cfg` is `null` (default), they use `svh_r2_config()` → video bucket.
- Audio hub passes `sah_r2_config()` explicitly → audio bucket.
- This is **backward-compatible** — all existing callers that omit `$cfg` still work.

---

## Configuration

### Option A — WordPress Admin (stores in wp_options)

Go to **WordPress Admin → Cloudflare** and fill in:

| Field | Description |
|---|---|
| Account ID | 32-char hex from Cloudflare sidebar. **NOT an email address.** |
| Access Key ID | From Cloudflare → R2 → Manage R2 API Tokens |
| Secret Access Key | From same API token creation flow |
| Video Bucket Name | R2 bucket for video files (e.g. `pennytribune`) |
| Video Custom Domain | Custom domain pointed at video bucket (e.g. `https://serve.pennycdn.com`) |
| Audio Bucket Name | R2 bucket for audio/podcast files (e.g. `pennytribune`) |
| Audio Custom Domain | Custom domain pointed at audio bucket (e.g. `https://serve.pennycdn.com`) |
| Default Bucket | Fallback bucket if a hub has no specific bucket set |
| Default Public URL | Fallback custom domain |
| API Token | Cloudflare API token with Cache Purge permission (for auto cache purging) |
| Zone ID | Cloudflare Zone ID for the site domain |

### Option B — wp-config.php Constants (recommended for production)

Constants take priority over database values and **cannot disappear** if options get cleared.

```php
// Shared credentials (required)
define( 'SVH_R2_ACCOUNT_ID',      'your-32-char-account-id' );
define( 'SVH_R2_ACCESS_KEY',      'your-access-key-id' );
define( 'SVH_R2_SECRET_KEY',      'your-secret-key' );

// Default / shared bucket (fallback for hubs with no specific bucket)
define( 'SVH_R2_BUCKET',          'pennytribune' );
define( 'SVH_R2_PUBLIC_URL',      'https://serve.pennycdn.com' );

// Video Hub — specific bucket + custom domain
define( 'APOLLO_VIDEO_BUCKET',    'pennytribune' );
define( 'APOLLO_VIDEO_PUBLIC_URL','https://serve.pennycdn.com' );

// Audio Hub — specific bucket + custom domain
define( 'APOLLO_AUDIO_BUCKET',    'pennytribune' );
define( 'APOLLO_AUDIO_PUBLIC_URL','https://serve.pennycdn.com' );

// Cloudflare CDN — cache purging (optional)
define( 'APOLLO_CF_API_TOKEN',    'your-cf-api-token' );
define( 'APOLLO_CF_ZONE_ID',      'your-cf-zone-id' );
```

---

## R2 CORS Policy

Each bucket needs a CORS policy for direct browser uploads (multipart upload). Add this rule in:
**Cloudflare → R2 → [bucket-name] → Settings → CORS Policy**

```json
[{
  "AllowedOrigins": ["https://pennytribune.com"],
  "AllowedMethods": ["PUT"],
  "AllowedHeaders": ["*"],
  "ExposeHeaders": ["ETag"],
  "MaxAgeSeconds": 3600
}]
```

The Cloudflare Settings admin page generates the exact CORS snippet for each configured bucket.

---

## How Uploads Work

### Video Upload Flow

1. Editor opens a Video post → `svh-video-editor-panels.js` is enqueued.
2. User drags a file → JS calls `wp_ajax_svh_r2_mpu_init`.
3. PHP handler calls `svh_r2_config()` → resolves **video bucket**.
4. `svh_r2_aws_auth_headers()` signs a CreateMultipartUpload request.
5. PHP sends the request to R2, returns an `upload_id`.
6. JS requests presigned part URLs via `svh_r2_presign_part_url()` — all pointing at **video bucket**.
7. Browser PUTs chunks directly to R2 (no PHP proxy).
8. JS calls `wp_ajax_svh_upload_complete` → PHP saves `_svh_r2_key` and `_svh_video_url` meta.
9. Player calls `svh_r2_public_url($key)` → builds URL from **video custom domain**.

### Audio Upload Flow

1. Editor opens an Episode post → `sah-episode-admin.js` is enqueued.
2. User drags a file → JS calls `wp_ajax_sah_r2_audio_init`.
3. PHP handler calls `sah_r2_config()` → resolves **audio bucket**.
4. `svh_r2_aws_auth_headers('POST', $key, ..., $cfg)` signs request with **audio bucket**.
5. `svh_r2_presign_part_url($key, $upload_id, $i, 7200, $cfg)` generates presigned URLs for **audio bucket**.
6. Browser PUTs chunks directly to audio bucket.
7. JS calls `wp_ajax_sah_save_episode_audio` → saves `_ep_audio_url` and `_ep_audio_r2_key`.
8. `svh_r2_public_url($key, $cfg)` builds URL from **audio custom domain**.

---

## Relink Scans

Both hubs have a "Scan & Re-link" tool in their settings pages:

- **Video Hub** → uses `svh_r2_config()['public_url']` (video custom domain) to derive R2 keys from stored video URLs.
- **Audio Hub** → uses `sah_r2_config()['public_url']` (audio custom domain) to derive R2 keys from stored audio URLs.

If you change a custom domain, run the relink scan to repair any keys.

---

## Option Keys (wp_options table)

| Option Key | Description |
|---|---|
| `apollo_cf_account_id` | Cloudflare Account ID |
| `apollo_cf_access_key` | R2 Access Key ID |
| `apollo_cf_secret_key` | R2 Secret Access Key |
| `apollo_cf_bucket` | Default/shared bucket name |
| `apollo_cf_public_url` | Default/shared public URL |
| `apollo_cf_video_bucket` | Video Hub bucket override |
| `apollo_cf_video_public_url` | Video Hub custom domain override |
| `apollo_cf_audio_bucket` | Audio Hub bucket override |
| `apollo_cf_audio_public_url` | Audio Hub custom domain override |
| `apollo_cf_api_token` | Cloudflare API token (cache purging) |
| `apollo_cf_zone_id` | Cloudflare Zone ID (cache purging) |
| `apollo_cf_browser_ttl` | Browser cache TTL in seconds |
| `apollo_cf_migrated` | Migration flag — set to '1' after first-run migration |

Legacy options (`svh_r2_account_id`, `svh_r2_access_key`, etc.) are kept in sync on each save for backward compatibility with any third-party code that reads them directly.

---

## Migration from v1.73 and Earlier

When v1.74+ is first activated, `apollo_cf_maybe_migrate()` runs once and copies any existing `svh_r2_*` and `serve_r2_config` values into the new `apollo_cf_*` options. No manual steps needed.

---

## Security Notes

- Secret keys are stored in wp_options without additional encryption (standard WordPress pattern). For enhanced security, use wp-config.php constants instead.
- All AJAX handlers verify `current_user_can()` and `check_ajax_referer()` before accessing credentials.
- R2 API tokens should have **Object Read & Write** permissions scoped to the specific bucket only — not account-wide.
- Cloudflare CDN API tokens should have **Cache Purge** permission only, scoped to the specific zone.

---

## Adding a New Hub

If a future hub needs its own R2 bucket:

1. Add `new_hub_bucket` and `new_hub_public_url` to `apollo_cf_config()` in `cloudflare-settings.php`.
2. Create `apollo_cf_newhub_config()` that merges overrides (see `apollo_cf_video_config()` as template).
3. Add a function `newhub_r2_config()` that delegates to `apollo_cf_newhub_config()`.
4. Add admin fields to the Cloudflare Settings page.
5. Pass the config explicitly when calling `svh_r2_aws_auth_headers()` and related functions.
