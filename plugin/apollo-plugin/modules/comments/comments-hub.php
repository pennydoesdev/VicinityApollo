<?php
/**
 * Comments Hub — Disqus-style threaded comments with emoji reactions
 * Uses Twitter/Twemoji for emoji, WordPress native comments as backend
 *
 * @package Apollo
 */
defined( 'ABSPATH' ) || exit;

// ── Emoji reactions stored as comment meta ─────────────────────────────────
// _pt_reactions = JSON: {"❤️":["user1","user2"],"🔥":["user3"]}
// For logged-out users, we use a hashed IP+UA as anonymous key

define('PT_REACTION_EMOJIS', ['❤️','🔥','😮','😂','😢','👍','💯','🤔']);

// ── AJAX: toggle reaction ──────────────────────────────────────────────────
add_action('wp_ajax_pt_react',        'pt_handle_reaction');
add_action('wp_ajax_nopriv_pt_react', 'pt_handle_reaction');

function pt_handle_reaction(): void {
    if (!check_ajax_referer('pt_react_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid nonce', 403);
    }
    // Rate limit: max 20 reactions per minute per IP (prevents reaction spam/flooding)
    if ( class_exists('Rate_Limiter') && ! Rate_Limiter::hit('pt_react', 20, 60) ) {
        wp_send_json_error('Too many requests — slow down.', 429);
    }
    $comment_id = absint($_POST['comment_id'] ?? 0);
    $emoji      = sanitize_text_field(wp_unslash($_POST['emoji'] ?? ''));
    if (!$comment_id || !in_array($emoji, PT_REACTION_EMOJIS, true)) {
        wp_send_json_error('Invalid params', 400);
    }
    $comment = get_comment($comment_id);
    if (!$comment || $comment->comment_approved !== '1') {
        wp_send_json_error('Comment not found', 404);
    }

    // User key: user ID for logged-in, hashed IP+UA for anonymous
    $user_key = is_user_logged_in()
        ? 'u' . get_current_user_id()
        : 'a' . substr(md5(($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 12);

    $reactions = json_decode(get_comment_meta($comment_id, '_pt_reactions', true) ?: '{}', true);
    if (!is_array($reactions)) $reactions = [];
    if (!isset($reactions[$emoji])) $reactions[$emoji] = [];

    // Toggle
    $pos = array_search($user_key, $reactions[$emoji], true);
    if ($pos !== false) {
        array_splice($reactions[$emoji], $pos, 1);
        $added = false;
    } else {
        $reactions[$emoji][] = $user_key;
        $added = true;
    }

    // Clean empty
    if (empty($reactions[$emoji])) unset($reactions[$emoji]);
    update_comment_meta($comment_id, '_pt_reactions', wp_json_encode($reactions));

    // Return updated counts
    $counts = [];
    foreach (PT_REACTION_EMOJIS as $e) {
        $counts[$e] = count($reactions[$e] ?? []);
    }
    wp_send_json_success(['counts' => $counts, 'added' => $added, 'emoji' => $emoji, 'user_key' => $user_key]);
}

// ── AJAX: load more comments ───────────────────────────────────────────────
add_action('wp_ajax_pt_comments_page',        'pt_comments_page');
add_action('wp_ajax_nopriv_pt_comments_page', 'pt_comments_page');

function pt_comments_page(): void {
    check_ajax_referer('pt_react_nonce', 'nonce');
    $post_id = absint($_POST['post_id'] ?? 0);
    $page    = max(1, absint($_POST['page'] ?? 1));
    if (!$post_id) wp_send_json_error('No post');
    $per_page = absint(get_option('comments_per_page', 20));
    $args = [
        'post_id'       => $post_id,
        'status'        => 'approve',
        'number'        => $per_page,
        'offset'        => ($page - 1) * $per_page,
        'orderby'       => 'comment_date',
        'order'         => 'ASC',
        'parent'        => 0,
        'hierarchical'  => 'threaded',
    ];
    $comments = get_comments($args);
    ob_start();
    foreach ($comments as $comment) {
        pt_render_comment($comment);
    }
    $html = ob_get_clean();
    $total = (int) get_comments(['post_id'=>$post_id,'status'=>'approve','count'=>true,'parent'=>0]);
    wp_send_json_success(['html'=>$html, 'total'=>$total, 'page'=>$page, 'per_page'=>$per_page]);
}

// ── Render a single comment (recursive for replies) ───────────────────────
function pt_render_comment($comment, int $depth = 0): void {
    if ( ! ( $comment instanceof WP_Comment ) ) {
        if ( is_array( $comment ) || is_object( $comment ) ) {
            $comment = get_comment( is_object( $comment ) ? ( $comment->comment_ID ?? 0 ) : ( $comment['comment_ID'] ?? 0 ) );
        }
        if ( ! ( $comment instanceof WP_Comment ) ) {
            return;
        }
    }

    $cid      = (int) $comment->comment_ID;
    if ( $cid <= 0 ) return;
    $author   = get_comment_author($cid);
    $avatar   = get_avatar($comment, 40, '', '', ['class'=>'pt-comment__avatar','loading'=>'lazy']);
    $ts       = $comment->comment_date_gmt ? strtotime( $comment->comment_date_gmt ) : false;
    $date     = $ts ? ( human_time_diff( $ts, time() ) . ' ago' ) : '';
    $full_date= get_comment_date('F j, Y \a\t g:i a', $cid) ?: '';
    $content  = get_comment_text($cid);
    $can_reply= comments_open($comment->comment_post_ID) && ($depth < 4);
    $is_author= $comment->user_id && (int)$comment->user_id === (int)get_post_field('post_author', $comment->comment_post_ID);
    $user_key = is_user_logged_in() ? 'u'.get_current_user_id() : 'a'.substr(md5(($_SERVER['REMOTE_ADDR']??'').($_SERVER['HTTP_USER_AGENT']??'')),0,12);

    $reactions_raw = json_decode(get_comment_meta($cid,'_pt_reactions',true) ?: '{}', true);
    if (!is_array($reactions_raw)) $reactions_raw = [];

    $replies = get_comments(['parent'=>$cid,'status'=>'approve','order'=>'ASC']);
    if ( ! is_array( $replies ) ) $replies = [];
    ?>
    <div class="pt-comment<?php echo $depth > 0 ? ' pt-comment--reply' : ''; ?>" id="pt-c-<?php echo $cid; ?>" data-id="<?php echo $cid; ?>" style="--depth:<?php echo min($depth,4); ?>">
        <div class="pt-comment__inner">
            <div class="pt-comment__head">
                <div class="pt-comment__avatar-wrap"><?php echo $avatar; ?></div>
                <div class="pt-comment__meta">
                    <span class="pt-comment__author"><?php echo esc_html($author); ?><?php if ($is_author): ?><span class="pt-comment__badge">Author</span><?php endif; ?></span>
                    <time class="pt-comment__time" title="<?php echo esc_attr($full_date); ?>"><?php echo esc_html($date); ?></time>
                </div>
            </div>
            <div class="pt-comment__body"><?php echo wp_kses_post(wpautop($content)); ?></div>
            <div class="pt-comment__actions">
                <div class="pt-reactions" data-cid="<?php echo $cid; ?>">
                    <?php foreach (PT_REACTION_EMOJIS as $emoji):
                        $count     = count($reactions_raw[$emoji] ?? []);
                        $reacted   = in_array($user_key, $reactions_raw[$emoji] ?? [], true);
                    ?>
                    <button class="pt-reaction<?php echo $reacted ? ' is-active' : ''; ?>"
                            data-emoji="<?php echo esc_attr($emoji); ?>"
                            data-cid="<?php echo $cid; ?>"
                            title="<?php echo esc_attr($emoji); ?>"
                            aria-label="React with <?php echo esc_attr($emoji); ?>"
                            aria-pressed="<?php echo $reacted ? 'true' : 'false'; ?>">
                        <img src="https://cdn.jsdelivr.net/gh/jdecked/twemoji@latest/assets/svg/<?php echo esc_attr(pt_emoji_to_codepoint($emoji)); ?>.svg"
                             class="pt-reaction__emoji" alt="<?php echo esc_attr($emoji); ?>" width="18" height="18" loading="lazy">
                        <?php if ($count > 0): ?><span class="pt-reaction__count"><?php echo esc_html($count); ?></span><?php endif; ?>
                    </button>
                    <?php endforeach; ?>
                    <button class="pt-reaction pt-reaction--add" aria-label="Add reaction" title="Add reaction">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="16" height="16"><circle cx="12" cy="12" r="10"/><path d="M8 13s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                    </button>
                    <div class="pt-reaction-picker" hidden>
                        <?php foreach (PT_REACTION_EMOJIS as $emoji): ?>
                        <button class="pt-reaction-picker__item" data-emoji="<?php echo esc_attr($emoji); ?>" data-cid="<?php echo $cid; ?>" title="<?php echo esc_attr($emoji); ?>">
                            <img src="https://cdn.jsdelivr.net/gh/jdecked/twemoji@latest/assets/svg/<?php echo esc_attr(pt_emoji_to_codepoint($emoji)); ?>.svg"
                                 alt="<?php echo esc_attr($emoji); ?>" width="22" height="22" loading="lazy">
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($can_reply): ?>
                <button class="pt-comment__reply-btn" data-cid="<?php echo $cid; ?>" data-author="<?php echo esc_attr($author); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>
                    Reply
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($replies): ?>
        <div class="pt-comment__replies">
            <?php foreach ($replies as $reply) pt_render_comment($reply, $depth + 1); ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
}

function pt_emoji_to_codepoint(string $emoji): string {
    $map = [
        '❤️' => '2764',
        '🔥' => '1f525',
        '😮' => '1f62e',
        '😂' => '1f602',
        '😢' => '1f622',
        '👍' => '1f44d',
        '💯' => '1f4af',
        '🤔' => '1f914',
    ];
    return $map[$emoji] ?? '2764';
}

add_action('wp_enqueue_scripts', function(): void {
    if (!is_singular() || !comments_open()) return;
    wp_enqueue_script('pt-comments',
        get_template_directory_uri() . '/assets/js/pt-comments.js',
        [], '1.0', true);
    wp_localize_script('pt-comments', 'ptComments', [
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('pt_react_nonce'),
        'postId'   => get_the_ID(),
        'isLoggedIn' => is_user_logged_in() ? '1' : '0',
        'loginUrl' => wp_login_url(get_permalink()),
    ]);
});

add_action('wp_head', function(): void {
    if (!is_singular() || !comments_open()) return;
    ?>
    <style id="pt-comments-css">
    .pt-comments-wrap{max-width:720px;margin:0 auto;padding:2rem 0 4rem;font-family:var(--flavor-font-ui,system-ui,-apple-system,sans-serif)}
    .pt-comments-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;padding-bottom:.75rem;border-bottom:2px solid var(--flavor-text,#111);gap:1rem;flex-wrap:wrap}
    .pt-comments-title{font-family:var(--flavor-font-headline,Georgia,serif);font-size:1.3rem;font-weight:900;margin:0;color:var(--flavor-text,#111)}
    .pt-comments-count{font-size:.78rem;color:#888;font-weight:600}
    .pt-sort{display:flex;gap:.35rem;align-items:center;font-size:.78rem}
    .pt-sort-btn{background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:4px;color:#888;font-weight:600;font-size:.78rem;transition:all .15s}
    .pt-sort-btn.is-active,.pt-sort-btn:hover{background:var(--flavor-text,#111);color:#fff}
    .pt-form{background:#f8f9fa;border-radius:10px;padding:1.1rem;margin-bottom:1.5rem;border:1px solid #e9eaec}
    .pt-form__head{display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem}
    .pt-form__avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;background:#e5e7eb}
    .pt-form__label{font-size:.82rem;color:#555;font-weight:600}
    .pt-form__label a{color:var(--flavor-accent,#c62828);text-decoration:none;font-weight:700}
    .pt-form textarea{width:100%;border:1.5px solid #e9eaec;border-radius:8px;padding:.7rem .85rem;font-size:.9rem;font-family:inherit;resize:vertical;min-height:80px;max-height:260px;outline:none;transition:border-color .15s;box-sizing:border-box;background:#fff}
    .pt-form textarea:focus{border-color:var(--flavor-accent,#c62828)}
    .pt-form__footer{display:flex;align-items:center;justify-content:space-between;margin-top:.6rem;gap:.5rem;flex-wrap:wrap}
    .pt-form__info{font-size:.72rem;color:#aaa}
    .pt-form__submit{padding:.45rem 1.2rem;background:var(--flavor-text,#111);color:#fff;border:none;border-radius:6px;font-weight:700;font-size:.82rem;cursor:pointer;transition:opacity .15s}
    .pt-form__submit:hover{opacity:.85}
    .pt-form__submit:disabled{opacity:.5;cursor:not-allowed}
    .pt-comment{--depth:0;margin-bottom:.15rem}
    .pt-comment__inner{padding:.85rem .95rem;border-radius:8px;transition:background .15s;background:#fff}
    .pt-comment:hover > .pt-comment__inner{background:#fafafa}
    .pt-comment--reply{margin-left:calc(var(--depth) * 28px);border-left:2px solid #f0f0f0;padding-left:0}
    .pt-comment__replies{margin-top:.15rem}
    .pt-comment__head{display:flex;align-items:center;gap:.6rem;margin-bottom:.55rem}
    .pt-comment__avatar{width:36px;height:36px;border-radius:50%;object-fit:cover;display:block;flex-shrink:0}
    .pt-comment__avatar-wrap img{width:36px!important;height:36px!important;border-radius:50%!important}
    .pt-comment__meta{display:flex;align-items:baseline;gap:.5rem;flex-wrap:wrap}
    .pt-comment__author{font-weight:700;font-size:.88rem;color:var(--flavor-text,#111)}
    .pt-comment__badge{background:var(--flavor-accent,#c62828);color:#fff;font-size:.6rem;font-weight:800;padding:1px 5px;border-radius:3px;text-transform:uppercase;letter-spacing:.04em;vertical-align:middle;margin-left:4px}
    .pt-comment__time{font-size:.72rem;color:#aaa}
    .pt-comment__body{font-size:.9rem;line-height:1.65;color:#333;margin-bottom:.6rem}
    .pt-comment__body p{margin:.3rem 0}
    .pt-comment__body p:first-child{margin-top:0}
    .pt-comment__actions{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
    .pt-comment__reply-btn{background:none;border:none;cursor:pointer;color:#aaa;font-size:.75rem;font-weight:600;padding:3px 7px;border-radius:4px;display:inline-flex;align-items:center;gap:4px;transition:color .15s,background .15s}
    .pt-comment__reply-btn:hover{color:var(--flavor-text,#111);background:#f0f0f0}
    .pt-reactions{display:flex;align-items:center;flex-wrap:wrap;gap:3px;flex:1;position:relative}
    .pt-reaction{display:inline-flex;align-items:center;gap:3px;padding:3px 7px;border-radius:99px;border:1.5px solid #e9eaec;background:#fff;cursor:pointer;font-size:.72rem;font-weight:700;color:#666;transition:all .15s;white-space:nowrap}
    .pt-reaction:hover,.pt-reaction.is-active{border-color:var(--flavor-accent,#c62828);background:#fff1f0;color:var(--flavor-accent,#c62828)}
    .pt-reaction__emoji{width:16px;height:16px;display:block;flex-shrink:0}
    .pt-reaction__count{line-height:1;min-width:10px}
    .pt-reaction--add{padding:3px 6px;color:#aaa;border-color:#e9eaec;border-style:dashed}
    .pt-reaction--add:hover{border-color:#999;color:#555;border-style:solid;background:#f8f9fa}
    .pt-reaction-picker{position:absolute;bottom:calc(100% + 6px);left:0;background:#fff;border:1.5px solid #e9eaec;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.12);display:flex;gap:4px;padding:8px;z-index:200;flex-wrap:wrap;max-width:220px}
    .pt-reaction-picker[hidden]{display:none}
    .pt-reaction-picker__item{background:none;border:none;cursor:pointer;border-radius:6px;padding:5px;transition:background .1s;line-height:1}
    .pt-reaction-picker__item:hover{background:#f0f0f0;transform:scale(1.2)}
    .pt-reaction-picker__item img{width:22px;height:22px;display:block}
    .pt-inline-reply{margin:.5rem 0 .5rem calc(var(--depth,0) * 28px + 28px);background:#f0f4ff;border-radius:8px;padding:.85rem;border:1px solid #c7d2fe;display:none}
    .pt-inline-reply.is-open{display:block}
    .pt-inline-reply textarea{width:100%;border:1.5px solid #c7d2fe;border-radius:6px;padding:.6rem .75rem;font-size:.875rem;font-family:inherit;resize:none;min-height:70px;box-sizing:border-box;background:#fff;outline:none}
    .pt-inline-reply textarea:focus{border-color:var(--flavor-accent,#c62828)}
    .pt-inline-reply__footer{display:flex;justify-content:flex-end;gap:.5rem;margin-top:.5rem}
    .pt-inline-reply__cancel{background:none;border:1px solid #e9eaec;border-radius:5px;padding:.35rem .8rem;font-size:.78rem;cursor:pointer;color:#666;transition:all .15s}
    .pt-inline-reply__cancel:hover{background:#f0f0f0}
    .pt-inline-reply__submit{background:var(--flavor-text,#111);color:#fff;border:none;border-radius:5px;padding:.35rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;transition:opacity .15s}
    .pt-inline-reply__submit:hover{opacity:.85}
    .pt-load-more{text-align:center;margin-top:1.25rem}
    .pt-load-more-btn{padding:.55rem 1.5rem;border:1.5px solid #e9eaec;border-radius:99px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:700;color:var(--flavor-text,#111);transition:all .15s}
    .pt-load-more-btn:hover{border-color:var(--flavor-text,#111);background:var(--flavor-text,#111);color:#fff}
    .pt-empty{text-align:center;padding:2.5rem 1rem;color:#aaa}
    .pt-empty__icon{font-size:2.5rem;margin-bottom:.75rem}
    .pt-empty__title{font-weight:700;font-size:1rem;color:#555;margin-bottom:.3rem}
    .pt-empty__sub{font-size:.82rem}
    .pt-login-prompt{background:#fafafa;border:1px solid #e9eaec;border-radius:8px;padding:1rem;text-align:center;font-size:.88rem;color:#555}
    .pt-login-prompt a{color:var(--flavor-accent,#c62828);font-weight:700;text-decoration:none}
    @media(max-width:540px){.pt-comment--reply{margin-left:12px}.pt-reaction-picker{right:0;left:auto}}
    </style>
    <?php
}, 20);

add_action('wp_ajax_pt_submit_comment',        'pt_submit_comment');
add_action('wp_ajax_nopriv_pt_submit_comment', 'pt_submit_comment');

function pt_submit_comment(): void {
    check_ajax_referer('pt_react_nonce', 'nonce');
    $post_id = absint($_POST['post_id'] ?? 0);
    $content = sanitize_textarea_field(wp_unslash($_POST['comment_content'] ?? ''));
    $parent  = absint($_POST['comment_parent'] ?? 0);

    if (!$post_id || !$content) wp_send_json_error('Missing content');
    if (!comments_open($post_id))  wp_send_json_error('Comments are closed');

    $user = wp_get_current_user();
    if ($user->exists()) {
        $author = $user->display_name;
        $email  = $user->user_email;
        $url    = $user->user_url;
        $uid    = $user->ID;
    } else {
        $author = sanitize_text_field(wp_unslash($_POST['comment_author'] ?? ''));
        $email  = sanitize_email(wp_unslash($_POST['comment_author_email'] ?? ''));
        $url    = '';
        $uid    = 0;
        if (!$author || !$email) wp_send_json_error('Name and email required');
        if (!is_email($email))   wp_send_json_error('Invalid email address');
    }

    $data = [
        'comment_post_ID'      => $post_id,
        'comment_author'       => $author,
        'comment_author_email' => $email,
        'comment_author_url'   => $url,
        'comment_content'      => $content,
        'comment_parent'       => $parent,
        'user_id'              => $uid,
        'comment_author_IP'    => preg_replace('/[^0-9a-fA-F:.,]/', '', $_SERVER['REMOTE_ADDR'] ?? ''),
        'comment_agent'        => sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
    ];

    $comment_id = wp_new_comment($data, true);
    if (is_wp_error($comment_id)) wp_send_json_error($comment_id->get_error_message());

    $comment = get_comment($comment_id);
    $approved = $comment && $comment->comment_approved === '1';

    ob_start();
    if ($approved && $comment) pt_render_comment($comment);
    $html = ob_get_clean();

    $count = (int) get_comments(['post_id'=>$post_id,'status'=>'approve','count'=>true]);
    wp_send_json_success([
        'html'     => $approved ? $html : '',
        'approved' => $approved,
        'message'  => $approved ? '' : 'Your comment is awaiting moderation.',
        'count'    => $count,
    ]);
}

// ── apollo_render bridge ─────────────────────────────────────────────────────
add_filter( 'apollo_render_comments-hub', function( $html, array $args ): string {
    $post_id = (int) ( $args['post_id'] ?? get_the_ID() );
    if ( ! $post_id ) return '';
    ob_start();
    echo '<div class="pt-comments-wrap">';
    $comments = get_comments(['post_id'=>$post_id,'status'=>'approve','parent'=>0,'order'=>'ASC']);
    if ( is_array( $comments ) ) {
        foreach ( $comments as $c ) pt_render_comment( $c );
    }
    echo '</div>';
    return ob_get_clean();
}, 10, 2 );
