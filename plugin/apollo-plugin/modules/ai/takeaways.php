<?php
/**
 * Penny AI — AI Takeaways for articles
 * Powered by the active AI provider configured in Settings → AI Settings.
 * Cached in post meta until post is updated.
 *
 * @package Apollo
 */
defined( 'ABSPATH' ) || exit;

// ── Clear cache + locks when post is updated ─────────────────────────────
add_action('save_post', function(int $id): void {
    if (wp_is_post_revision($id) || wp_is_post_autosave($id)) return;
    delete_post_meta($id, '_pt_takeaways_cache');
    delete_post_meta($id, '_pt_takeaways_model');
    delete_post_meta($id, '_pt_takeaways_failed_at');
    delete_transient('serve_ai_gen_lock_' . $id);
}, 10, 1);

// ── Generate takeaways via the unified AI provider ────────────────────────
function pt_generate_takeaways(int $post_id): array {
    if (!function_exists('serve_ai_call_with_system') || !function_exists('serve_ai_has_key')) return [];
    if (!serve_ai_has_key()) return [];

    $lock_key = 'serve_ai_gen_lock_' . $post_id;
    if (get_transient($lock_key)) return [];
    set_transient($lock_key, 1, 60);

    $post    = get_post($post_id);
    if (!$post) { delete_transient($lock_key); return []; }

    $title   = $post->post_title;
    $content = wp_strip_all_tags(do_shortcode($post->post_content));
    $content = preg_replace('/\s+/', ' ', trim($content));
    $excerpt = wp_trim_words($content, 800);

    $system = 'You are a news summarization assistant. Given an article, extract exactly 4 key takeaways as a JSON array of short, clear sentences (each under 18 words). Respond ONLY with a valid JSON array like: ["Takeaway one.","Takeaway two.","Takeaway three.","Takeaway four."] — no markdown, no extra text.';
    $prompt = "Article title: {$title}\n\nContent:\n{$excerpt}";

    $result = serve_ai_call_with_system($system, $prompt, ['task' => 'takeaways', 'post_id' => $post_id]);
    delete_transient($lock_key);

    if (is_wp_error($result) || empty($result)) {
        update_post_meta($post_id, '_pt_takeaways_failed_at', time());
        return [];
    }

    $text   = preg_replace('/^```(?:json)?\s*/m', '', trim($result));
    $text   = preg_replace('/\s*```$/m', '', $text);
    $parsed = json_decode(trim($text), true);

    if (!is_array($parsed) || empty($parsed)) {
        update_post_meta($post_id, '_pt_takeaways_failed_at', time());
        return [];
    }

    return array_slice(array_map('sanitize_text_field', $parsed), 0, 5);
}

// ── Render takeaways box ──────────────────────────────────────────────────
function pt_render_takeaways($post_id = 0): void {
    $post_id = (int) $post_id;
    if ($post_id <= 0) $post_id = (int) get_the_ID();
    if ($post_id <= 0) return;
    if (!is_singular('post')) return;

    if (!function_exists('serve_ai_has_key') || !serve_ai_has_key()) return;

    $cached = json_decode(get_post_meta($post_id, '_pt_takeaways_cache', true) ?: '', true);
    if (is_array($cached) && count($cached) >= 2) {
        pt_output_takeaways_html($cached);
        return;
    }

    $failed_at = (int) get_post_meta($post_id, '_pt_takeaways_failed_at', true);
    if ($failed_at && (time() - $failed_at) < 7200) return;

    $takeaways = pt_generate_takeaways($post_id);
    if (empty($takeaways)) return;

    update_post_meta($post_id, '_pt_takeaways_cache', wp_json_encode($takeaways));
    delete_post_meta($post_id, '_pt_takeaways_failed_at');
    pt_output_takeaways_html($takeaways);
}

function pt_output_takeaways_html(array $takeaways): void {
    ?>
    <aside class="pt-takeaways" aria-label="AI Key Takeaways">
        <div class="pt-takeaways__header">
            <span class="pt-takeaways__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="18" height="18">
                    <circle cx="12" cy="12" r="2" fill="currentColor"/>
                    <circle cx="5"  cy="5"  r="1.2" fill="currentColor" opacity=".9"/>
                    <circle cx="19" cy="6"  r="1"   fill="currentColor" opacity=".8"/>
                    <circle cx="20" cy="17" r="1.3" fill="currentColor" opacity=".9"/>
                    <circle cx="4"  cy="18" r="1"   fill="currentColor" opacity=".7"/>
                    <circle cx="12" cy="2"  r=".8"  fill="currentColor" opacity=".6"/>
                    <line x1="12" y1="12" x2="5"  y2="5"  stroke="currentColor" stroke-width=".8" opacity=".5"/>
                    <line x1="12" y1="12" x2="19" y2="6"  stroke="currentColor" stroke-width=".8" opacity=".5"/>
                    <line x1="12" y1="12" x2="20" y2="17" stroke="currentColor" stroke-width=".8" opacity=".5"/>
                    <line x1="12" y1="12" x2="4"  y2="18" stroke="currentColor" stroke-width=".8" opacity=".5"/>
                    <line x1="12" y1="12" x2="12" y2="2"  stroke="currentColor" stroke-width=".8" opacity=".4"/>
                    <line x1="5"  y1="5"  x2="12" y2="2"  stroke="currentColor" stroke-width=".6" opacity=".3"/>
                    <line x1="19" y1="6"  x2="20" y2="17" stroke="currentColor" stroke-width=".6" opacity=".3"/>
                </svg>
            </span>
            <span class="pt-takeaways__title">Penny AI</span>
            <span class="pt-takeaways__badge">AI Summary</span>
        </div>
        <ul class="pt-takeaways__list">
            <?php foreach ($takeaways as $item): ?>
            <li class="pt-takeaways__item"><?php echo esc_html($item); ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="pt-takeaways__disclaimer">
            <svg viewBox="0 0 16 16" fill="none" width="12" height="12" aria-hidden="true" style="display:inline;vertical-align:middle;margin-right:3px;flex-shrink:0"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M8 7v5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="8" cy="4.5" r=".75" fill="currentColor"/></svg>
            Penny AI is AI and can make mistakes. Double Check Responses.
        </p>
    </aside>
    <style id="pt-takeaways-style">
    .pt-takeaways{
        margin:0 0 1.75rem;
        width:100%;
        max-width:var(--flavor-narrow-width,720px);
        margin-left:auto;
        margin-right:auto;
        background:transparent;
        border-radius:8px;
        border:2px solid var(--flavor-accent,#c62828);
        overflow:hidden;
        color:var(--flavor-text,#111);
        box-sizing:border-box;
    }
    .pt-takeaways__header{
        display:flex;
        align-items:center;
        gap:.5rem;
        padding:.65rem 1rem;
        border-bottom:1px solid rgba(198,40,40,.2);
        background:rgba(198,40,40,.04);
    }
    .pt-takeaways__icon{color:var(--flavor-accent,#c62828);display:flex;align-items:center;flex-shrink:0}
    .pt-takeaways__title{font-family:var(--flavor-font-headline,Georgia,serif);font-size:.92rem;font-weight:800;color:var(--flavor-text,#111);letter-spacing:.01em;flex:1}
    .pt-takeaways__badge{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--flavor-accent,#c62828);border:1px solid var(--flavor-accent,#c62828);padding:2px 6px;border-radius:99px;flex-shrink:0;opacity:.7}
    .pt-takeaways__list{margin:0;padding:.8rem 1rem;list-style:none;display:flex;flex-direction:column;gap:.45rem}
    .pt-takeaways__item{font-size:.875rem;line-height:1.6;color:var(--flavor-text,#111);padding-left:1.2em;position:relative}
    .pt-takeaways__item::before{content:'';position:absolute;left:0;top:.6em;width:5px;height:5px;border-radius:50%;background:var(--flavor-accent,#c62828);flex-shrink:0}
    .pt-takeaways__disclaimer{margin:0;padding:.45rem 1rem .6rem;font-size:.7rem;color:#999;border-top:1px solid rgba(198,40,40,.15);line-height:1.5;display:flex;align-items:flex-start;gap:4px}
    </style>
    <?php
}

// ── Render published/updated date meta box ────────────────────────────────
function pt_render_post_dates($post_id = 0): void {
    $post_id = (int) $post_id;
    if ($post_id <= 0) $post_id = (int) get_the_ID();
    if ($post_id <= 0) return;
    if (!is_singular('post')) return;
    $post = get_post($post_id);
    if (!$post) return;

    $published = get_post_time('U', true, $post);
    $modified  = get_post_modified_time('U', true, $post);
    $is_updated = ($modified - $published) > 60;

    $pub_fmt  = get_post_time('F j, Y \a\t g:i a T', false, $post);
    $mod_fmt  = get_post_modified_time('F j, Y \a\t g:i a T', false, $post);
    ?>
    <div class="pt-post-dates">
        <div class="pt-post-dates__item">
            <svg viewBox="0 0 16 16" fill="none" width="13" height="13" aria-hidden="true"><rect x="1" y="2" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.3"/><path d="M5 1v2M11 1v2M1 6h14" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
            <span>Published</span>
            <time datetime="<?php echo esc_attr(get_post_time('c', true, $post)); ?>"><?php echo esc_html($pub_fmt); ?></time>
        </div>
        <?php if ($is_updated): ?>
        <div class="pt-post-dates__item pt-post-dates__item--updated">
            <svg viewBox="0 0 16 16" fill="none" width="13" height="13" aria-hidden="true"><path d="M13.5 2.5v4h-4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.34 6.5A6 6 0 1 1 11.5 3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
            <span>Updated</span>
            <time datetime="<?php echo esc_attr(get_post_modified_time('c', true, $post)); ?>"><?php echo esc_html($mod_fmt); ?></time>
        </div>
        <?php endif; ?>
    </div>
    <style id="pt-post-dates-style">
    .pt-post-dates{display:flex;align-items:center;flex-wrap:wrap;gap:.35rem 1.25rem;padding:.35rem 0 .85rem;border-bottom:1px solid #f0f0f0;margin-bottom:1.25rem;width:100%;max-width:var(--flavor-narrow-width,720px);margin-left:auto;margin-right:auto;box-sizing:border-box}
    .pt-post-dates__item{display:inline-flex;align-items:center;gap:.35rem;font-size:.75rem;color:#888;line-height:1}
    .pt-post-dates__item svg{color:#bbb;flex-shrink:0}
    .pt-post-dates__item span:first-of-type{font-weight:700;text-transform:uppercase;letter-spacing:.05em;font-size:.68rem;color:#aaa}
    .pt-post-dates__item time{color:#666}
    .pt-post-dates__item--updated svg{color:#e22}
    .pt-post-dates__item--updated span:first-of-type{color:#e22;font-weight:800}
    .pt-post-dates__item--updated time{color:#e22;font-weight:700}
    </style>
    <?php
}
