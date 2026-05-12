<?php
/**
 * Custom Roles for Apollo
 *
 * Roles:
 *  - Video Content Coordinator  : Editor-level access to serve_video + serve_video_category only
 *  - Audio Content Creator      : Editor-level access to own serve_podcast + serve_episode only
 *  - Elections Content Coordinator : Editor-level access to serve_election + serve_candidate only
 *
 * All roles use WordPress's native capability system.
 * Roles are registered on `after_switch_theme` (install) and cleaned on `switch_theme` (uninstall).
 * Capabilities are enforced via `user_has_cap` filter for the "own content only" podcast restriction.
 *
 * @package Apollo
 */
defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════
// 1. CAPABILITY SETS
// ═══════════════════════════════════════════════════════════════════════════

function sev_editor_post_caps(): array {
    return [
        'read'                   => true,
        'edit_posts'             => true,
        'edit_others_posts'      => true,
        'edit_published_posts'   => true,
        'edit_private_posts'     => true,
        'publish_posts'          => true,
        'delete_posts'           => true,
        'delete_others_posts'    => true,
        'delete_published_posts' => true,
        'delete_private_posts'   => true,
        'read_private_posts'     => true,
        'create_posts'           => true,
        'upload_files'           => true,
        'manage_categories'      => true,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. REGISTER ROLES ON THEME ACTIVATION
// ═══════════════════════════════════════════════════════════════════════════

add_action('after_switch_theme', 'sev_register_custom_roles');
function sev_register_custom_roles(): void {

    remove_role('video_content_coordinator');
    add_role('video_content_coordinator', 'Video Content Coordinator',
        array_merge(sev_editor_post_caps(), [
            'manage_serve_video_category' => true,
        ])
    );

    remove_role('audio_content_creator');
    add_role('audio_content_creator', 'Audio Content Creator',
        array_merge(sev_editor_post_caps(), [
            'manage_serve_podcast_category' => true,
        ])
    );

    remove_role('elections_content_coordinator');
    add_role('elections_content_coordinator', 'Elections Content Coordinator',
        array_merge(sev_editor_post_caps(), [
            'manage_serve_election_type'  => true,
            'manage_serve_election_state' => true,
            'manage_serve_election_year'  => true,
        ])
    );

    flush_rewrite_rules(false);
}

add_action('switch_theme', function(): void {
    remove_role('video_content_coordinator');
    remove_role('audio_content_creator');
    remove_role('elections_content_coordinator');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. REGISTER ON INIT (in case roles were wiped from DB)
// ═══════════════════════════════════════════════════════════════════════════

add_action('init', function(): void {
    if (!get_role('video_content_coordinator'))       sev_register_custom_roles();
    elseif (!get_role('audio_content_creator'))       sev_register_custom_roles();
    elseif (!get_role('elections_content_coordinator')) sev_register_custom_roles();
}, 1);

// ═══════════════════════════════════════════════════════════════════════════
// 4. CAPABILITY FILTERS
// ═══════════════════════════════════════════════════════════════════════════

add_filter('user_has_cap', 'sev_enforce_role_caps', 10, 4);
function sev_enforce_role_caps(
    array  $allcaps,
    array  $caps,
    array  $args,
    WP_User $user
): array {

    $role = sev_get_primary_role($user);
    if (!$role) return $allcaps;

    $cap     = $args[0] ?? '';
    $post_id = isset($args[2]) ? (int)$args[2] : 0;

    $post_type = '';
    if ($post_id) {
        $post_type = get_post_type($post_id) ?: '';
    }
    if (!$post_type && isset($args[2]) && is_string($args[2])) {
        $post_type = $args[2];
    }

    switch ($role) {

        case 'video_content_coordinator':
            $allowed_types = ['serve_video', 'attachment', ''];
            if ($post_id && in_array($post_type, ['post', 'page', 'serve_podcast',
                'serve_episode', 'serve_election', 'serve_candidate', 'serve_poll'], true)) {
                foreach ($caps as $c) {
                    $allcaps[$c] = false;
                }
            }
            break;

        case 'audio_content_creator':
            if ($post_id && in_array($post_type, ['post', 'page', 'serve_video',
                'serve_election', 'serve_candidate', 'serve_poll'], true)) {
                foreach ($caps as $c) {
                    $allcaps[$c] = false;
                }
            }

            if ($post_id && in_array($post_type, ['serve_podcast', 'serve_episode'], true)) {
                $post = get_post($post_id);
                if ($post && (int)$post->post_author !== (int)$user->ID) {
                    $restricted = [
                        'edit_post', 'delete_post', 'edit_published_post',
                        'delete_published_post', 'edit_private_post', 'delete_private_post',
                    ];
                    if (in_array($cap, $restricted, true)) {
                        foreach ($caps as $c) {
                            $allcaps[$c] = false;
                        }
                    }
                }
            }
            break;

        case 'elections_content_coordinator':
            if ($post_id && in_array($post_type, ['post', 'page', 'serve_video',
                'serve_podcast', 'serve_episode'], true)) {
                foreach ($caps as $c) {
                    $allcaps[$c] = false;
                }
            }
            break;
    }

    return $allcaps;
}

function sev_get_primary_role(WP_User $user): string {
    $custom = ['video_content_coordinator', 'audio_content_creator', 'elections_content_coordinator'];
    foreach ($custom as $role) {
        if (in_array($role, (array)$user->roles, true)) {
            return $role;
        }
    }
    return '';
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. ADMIN UI — Restrict wp-admin menus per role
// ═══════════════════════════════════════════════════════════════════════════

add_action('admin_menu', 'sev_restrict_admin_menus', 999);
function sev_restrict_admin_menus(): void {
    $user = wp_get_current_user();
    $role = sev_get_primary_role($user);
    if (!$role) return;

    $always_remove = [
        'edit.php',
        'edit.php?post_type=page',
        'edit-comments.php',
    ];
    foreach ($always_remove as $menu) {
        remove_menu_page($menu);
    }

    $remove_by_role = [
        'video_content_coordinator' => [
            'edit.php?post_type=serve_podcast',
            'edit.php?post_type=serve_episode',
            'edit.php?post_type=serve_election',
            'edit.php?post_type=serve_candidate',
            'edit.php?post_type=serve_poll',
        ],
        'audio_content_creator' => [
            'edit.php?post_type=serve_video',
            'edit.php?post_type=serve_election',
            'edit.php?post_type=serve_candidate',
            'edit.php?post_type=serve_poll',
        ],
        'elections_content_coordinator' => [
            'edit.php?post_type=serve_video',
            'edit.php?post_type=serve_podcast',
            'edit.php?post_type=serve_episode',
        ],
    ];

    foreach (($remove_by_role[$role] ?? []) as $slug) {
        remove_menu_page($slug);
    }
}

add_filter('login_redirect', 'sev_role_login_redirect', 10, 3);
function sev_role_login_redirect(string $redirect_to, string $requested_redirect_to, $user): string {
    if (is_wp_error($user)) return $redirect_to;
    $role = sev_get_primary_role($user);
    return match($role) {
        'video_content_coordinator'      => admin_url('edit.php?post_type=serve_video'),
        'audio_content_creator'          => admin_url('edit.php?post_type=serve_podcast'),
        'elections_content_coordinator'  => admin_url('edit.php?post_type=serve_election'),
        default                          => $redirect_to,
    };
}

add_action('wp_dashboard_setup', function(): void {
    $user = wp_get_current_user();
    if (!sev_get_primary_role($user)) return;
    remove_meta_box('dashboard_quick_press',       'dashboard', 'side');
    remove_meta_box('dashboard_right_now',         'dashboard', 'normal');
    remove_meta_box('dashboard_recent_comments',   'dashboard', 'normal');
    remove_meta_box('dashboard_incoming_links',    'dashboard', 'normal');
    remove_meta_box('dashboard_plugins',           'dashboard', 'normal');
    remove_meta_box('dashboard_recent_drafts',     'dashboard', 'side');
    remove_meta_box('dashboard_primary',           'dashboard', 'side');
    remove_meta_box('dashboard_secondary',         'dashboard', 'side');
});

add_action('current_screen', 'sev_restrict_admin_screens');
function sev_restrict_admin_screens(): void {
    if (!is_admin() || wp_doing_ajax()) return;

    $user = wp_get_current_user();
    $role = sev_get_primary_role($user);
    if (!$role) return;

    $screen = get_current_screen();
    if (!$screen) return;

    $allowed_post_types = [
        'video_content_coordinator'     => ['serve_video', 'attachment'],
        'audio_content_creator'         => ['serve_podcast', 'serve_episode', 'attachment'],
        'elections_content_coordinator' => ['serve_election', 'serve_candidate', 'serve_poll', 'attachment'],
    ];

    $allowed = $allowed_post_types[$role] ?? [];

    if (!empty($screen->post_type) && !in_array($screen->post_type, $allowed, true)) {
        wp_die(
            '<h1>' . esc_html__('Access Denied', 'serve') . '</h1>'
            . '<p>' . esc_html__('You do not have permission to manage this content type.', 'serve') . '</p>',
            403
        );
    }

    if ($screen->base === 'edit' && $screen->id === 'edit-post') {
        wp_die('<h1>Access Denied</h1><p>You do not have permission to manage posts.</p>', 403);
    }
    if ($screen->base === 'edit' && $screen->id === 'edit-page') {
        wp_die('<h1>Access Denied</h1><p>You do not have permission to manage pages.</p>', 403);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 6. AUDIO CREATOR: Filter podcast list to own content only
// ═══════════════════════════════════════════════════════════════════════════

add_action('pre_get_posts', function(WP_Query $query): void {
    if (!is_admin() || !$query->is_main_query()) return;

    $user = wp_get_current_user();
    if (!in_array('audio_content_creator', (array)$user->roles, true)) return;

    $pt = $query->get('post_type');
    if (in_array($pt, ['serve_podcast', 'serve_episode'], true)) {
        $query->set('author', $user->ID);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. ADMIN USER MANAGEMENT
// ═══════════════════════════════════════════════════════════════════════════

add_filter('editable_roles', function(array $roles): array {
    if (!current_user_can('manage_options')) {
        unset(
            $roles['video_content_coordinator'],
            $roles['audio_content_creator'],
            $roles['elections_content_coordinator']
        );
    }
    return $roles;
});

add_filter('gettext', function(string $translation, string $text): string {
    $role_names = [
        'Video Content Coordinator'    => 'Video Content Coordinator',
        'Audio Content Creator'        => 'Audio Content Creator',
        'Elections Content Coordinator'=> 'Elections Content Coordinator',
    ];
    return $role_names[$text] ?? $translation;
}, 10, 2);

add_action('admin_notices', function(): void {
    $user = wp_get_current_user();
    $role = sev_get_primary_role($user);
    if (!$role) return;

    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'dashboard') return;

    $messages = [
        'video_content_coordinator' => [
            'icon'  => '🎬',
            'title' => 'Video Content Coordinator',
            'desc'  => 'You can upload, edit, publish, and manage videos in the Video Hub. Use the Video Hub menu to manage video content and categories.',
        ],
        'audio_content_creator' => [
            'icon'  => '🎙',
            'title' => 'Audio Content Creator',
            'desc'  => 'You can upload, edit, publish, and manage your own podcasts and episodes. You can only see and edit content you have created.',
        ],
        'elections_content_coordinator' => [
            'icon'  => '🗳',
            'title' => 'Elections Content Coordinator',
            'desc'  => 'You can manage election races, candidates, polls, and AP data imports. Use the Elections menu to manage all election content.',
        ],
    ];

    $m = $messages[$role] ?? null;
    if (!$m) return;
    ?>
    <div class="notice notice-info" style="display:flex;align-items:center;gap:12px;padding:12px 16px">
        <span style="font-size:2rem;line-height:1"><?php echo esc_html($m['icon']); ?></span>
        <div>
            <strong><?php echo esc_html($m['title']); ?></strong>
            <p style="margin:4px 0 0;color:#555;font-size:13px"><?php echo esc_html($m['desc']); ?></p>
        </div>
    </div>
    <?php
});
