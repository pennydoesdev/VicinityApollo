<?php
/**
 * Analytics — GTM + GA4 integration
 *
 * @package Apollo
 */
defined( 'ABSPATH' ) || exit;

function serve_ga4_id(): string {
    return sanitize_text_field( get_option( 'serve_ga4_id', '' ) );
}
function serve_ga4_enabled(): bool {
    $id = serve_ga4_id();
    return ! empty( $id ) && str_starts_with( $id, 'G-' );
}
function serve_ga4_track_admins(): bool {
    return empty( get_option( 'serve_ga4_exclude_admins', '1' ) );
}
function serve_ga4_should_fire(): bool {
    if ( ! serve_ga4_enabled() && ! serve_gtm_enabled() ) return false;
    if ( ! serve_ga4_track_admins() && current_user_can( 'manage_options' ) ) return false;
    return true;
}
function serve_gtm_id(): string {
    return sanitize_text_field( get_option( 'serve_gtm_id', '' ) );
}
function serve_gtm_enabled(): bool {
    $id = serve_gtm_id();
    return ! empty( $id ) && str_starts_with( $id, 'GTM-' );
}
function apollo_show_setup_tips(): bool {
    return empty( get_option( 'apollo_hide_setup_tips', '' ) );
}

// 1. Data layer push before GTM loads
add_action( 'wp_head', static function (): void {
    if ( ! serve_ga4_should_fire() ) return;
    $dl = serve_ga4_datalayer();
    echo '<script>window.dataLayer=window.dataLayer||[];window.dataLayer.push(' . wp_json_encode( $dl ) . ');</script>';
}, 1 );

// 2. GTM container snippet
add_action( 'wp_head', static function (): void {
    if ( ! serve_gtm_enabled() ) return;
    $id = serve_gtm_id();
    ?>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js( $id ); ?>');</script>
<!-- End Google Tag Manager -->
    <?php
}, 5 );

add_action( 'wp_body_open', static function (): void {
    if ( ! serve_gtm_enabled() ) return;
    $id = serve_gtm_id();
    echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $id ) . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>';
} );

// 3. Data layer builder
function serve_ga4_datalayer(): array {
    global $post;
    $dl = [ 'platform' => 'main_site' ];

    if ( is_singular() && $post ) {
        $type   = get_post_type( $post );
        $cats   = get_the_terms( $post->ID, 'category' );
        $tags   = get_the_terms( $post->ID, 'post_tag' );
        $wc     = str_word_count( wp_strip_all_tags( $post->post_content ) );
        $rm     = max( 1, round( $wc / 238 ) );
        $author_id  = (int) $post->post_author;
        $author_tag = function_exists( 'apollo_author_tag' ) ? apollo_author_tag( $author_id ) : '';

        $dl += [
            'post_id'            => $post->ID,
            'post_title'         => $post->post_title,
            'author_id'          => $author_id,
            'author_name'        => get_the_author_meta( 'display_name', $author_id ),
            'author_tag'         => $author_tag,
            'post_category'      => $cats && ! is_wp_error( $cats ) ? implode( ', ', wp_list_pluck( $cats, 'name' ) ) : '',
            'post_tags'          => $tags && ! is_wp_error( $tags ) ? implode( ', ', wp_list_pluck( $tags, 'name' ) ) : '',
            'publish_date'       => get_the_date( 'Y-m-d', $post ),
            'days_since_publish' => (int) floor( ( time() - get_post_timestamp( $post->ID ) ) / DAY_IN_SECONDS ),
        ];

        if ( $type === 'post' || $type === 'page' ) {
            $dl += [
                'content_type'        => is_page() ? 'page' : 'article',
                'word_count'          => $wc,
                'word_count_bucket'   => $wc < 300 ? 'short' : ( $wc < 800 ? 'medium' : ( $wc < 1500 ? 'long' : 'longform' ) ),
                'reading_time_min'    => $rm,
                'reading_time_bucket' => $rm <= 1 ? '1min' : ( $rm <= 3 ? '1-3min' : ( $rm <= 7 ? '3-7min' : '7min+' ) ),
            ];
        } elseif ( $type === 'serve_episode' ) {
            $pod_id      = absint( get_post_meta( $post->ID, '_ep_podcast_id', true ) );
            $show_author = $pod_id ? (int) get_post_field( 'post_author', $pod_id ) : $author_id;
            $dl += [
                'content_type'     => 'podcast_episode',
                'episode_id'       => $post->ID,
                'episode_number'   => (int) get_post_meta( $post->ID, '_ep_number', true ),
                'episode_season'   => (int) get_post_meta( $post->ID, '_ep_season', true ),
                'episode_duration' => (string) get_post_meta( $post->ID, '_ep_duration', true ),
                'show_id'          => $pod_id,
                'show_title'       => $pod_id ? get_the_title( $pod_id ) : '',
                'author_tag'       => function_exists( 'apollo_author_tag' ) ? apollo_author_tag( $show_author ) : $author_tag,
                'author_id'        => $show_author,
            ];
        } elseif ( $type === 'serve_podcast' ) {
            $dl += [
                'content_type'  => 'podcast_show',
                'show_id'       => $post->ID,
                'show_title'    => $post->post_title,
                'episode_count' => function_exists( 'sah_ep_count' ) ? sah_ep_count( $post->ID ) : 0,
            ];
        } elseif ( $type === 'serve_video' ) {
            $vc_terms = get_the_terms( $post->ID, 'serve_video_category' );
            $dl += [
                'content_type'      => 'video',
                'video_id'          => $post->ID,
                'video_title'       => $post->post_title,
                'video_category'    => $vc_terms && ! is_wp_error( $vc_terms ) ? implode( ', ', wp_list_pluck( $vc_terms, 'name' ) ) : '',
                'video_duration'    => (string) get_post_meta( $post->ID, '_svh_duration', true ),
                'video_total_views' => absint( get_post_meta( $post->ID, '_svh_views', true ) ),
            ];
        } else {
            $dl['content_type'] = $type;
        }
    } elseif ( is_post_type_archive( 'serve_podcast' ) ) {
        $dl['content_type'] = 'podcast_hub';
    } elseif ( is_post_type_archive( 'serve_video' ) || is_tax( 'serve_video_category' ) ) {
        $dl['content_type'] = 'video_hub';
    } elseif ( is_category() ) {
        $dl['content_type']  = 'category_archive';
        $dl['post_category'] = single_cat_title( '', false );
    } elseif ( is_tag() ) {
        $dl['content_type'] = 'tag_archive';
        $dl['post_tags']    = single_tag_title( '', false );
    } elseif ( is_author() ) {
        $author = get_queried_object();
        $dl += [
            'content_type' => 'author_archive',
            'author_name'  => $author instanceof WP_User ? $author->display_name : '',
            'author_id'    => $author instanceof WP_User ? $author->ID : 0,
        ];
    } elseif ( is_search() ) {
        $dl['content_type'] = 'search_results';
        $dl['search_term']  = get_search_query();
    } elseif ( is_front_page() || is_home() ) {
        $dl['content_type'] = 'homepage';
    } elseif ( is_404() ) {
        $dl['content_type'] = '404';
    } else {
        $dl['content_type'] = 'archive';
    }
    return $dl;
}

// 4. Direct GA4 footer injection
add_action( 'wp_footer', static function (): void {
    if ( ! serve_ga4_enabled() || ! serve_ga4_should_fire() ) return;
    $id    = serve_ga4_id();
    $debug = get_option( 'serve_ga4_debug' ) ? 'true' : 'false';
    $anon  = get_option( 'serve_ga4_anonymize_ip' ) ? "'anonymize_ip':true," : '';
    ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $id ); ?>"></script>
<script>
window.dataLayer=window.dataLayer||[];
function gtag(){dataLayer.push(arguments);}
gtag('js',new Date());
gtag('config','<?php echo esc_js( $id ); ?>',{<?php echo $anon; ?>'debug_mode':<?php echo $debug; ?>,'send_page_view':true});
</script>
    <?php
}, 20 );

// 5. Client-side event listeners
add_action( 'wp_footer', static function (): void {
    if ( ! serve_ga4_should_fire() ) return;
    ?>
<script id="serve-ga4-tracking">
(function(){
'use strict';
function push(ev,params){
  var dl=window.dataLayer=window.dataLayer||[];
  dl.push(Object.assign({event:ev},params||{}));
  if(typeof window.gtag==='function') window.gtag('event',ev,params||{});
}
// Scroll depth
(function(){
  var art=document.querySelector('.entry-content,.post-content,article .content');
  if(!art)return;
  var fired={};
  window.addEventListener('scroll',function(){
    var r=art.getBoundingClientRect(),p=Math.min(100,((window.scrollY+window.innerHeight-r.top)/r.height)*100);
    [25,50,75,100].forEach(function(cp){
      if(p>=cp&&!fired[cp]){fired[cp]=true;push('scroll_depth',{'percent_scrolled':cp,'non_interaction':cp<50});}
    });
  },{passive:true});
})();
// Engagement time
(function(){
  var active=0,last=Date.now(),hidden=false,f30=false,f60=false;
  setInterval(function(){
    if(!hidden)active+=Date.now()-last;
    last=Date.now();
    var s=active/1000;
    if(!f30&&s>=30){f30=true;push('article_engaged',{'engagement_seconds':30});}
    if(!f60&&s>=60){f60=true;push('article_engaged',{'engagement_seconds':60});}
  },5000);
  document.addEventListener('visibilitychange',function(){hidden=document.hidden;if(!hidden)last=Date.now();});
})();
// Outbound clicks
document.addEventListener('click',function(e){
  var a=e.target.closest('a[href]');
  if(!a)return;
  try{var u=new URL(a.href);if(u.hostname&&u.hostname!==location.hostname)push('outbound_click',{'link_url':a.href,'link_domain':u.hostname});}catch(_){}
});
})();
</script>
    <?php
}, 99 );

// 6. REST endpoint for server-side time tracking
add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'apollo/v1', '/track-time', [
        'methods'             => 'POST',
        'callback'            => static function ( WP_REST_Request $req ): WP_REST_Response {
            $body = $req->get_json_params();
            if ( ! is_array( $body ) ) return new WP_REST_Response( [ 'ok' => false ], 400 );
            $post_id = absint( $body['post_id'] ?? 0 );
            $cap     = 43200;
            foreach ( [
                'read_seconds'   => '_apollo_total_read_seconds',
                'listen_seconds' => '_apollo_total_listen_seconds',
                'watch_seconds'  => '_apollo_total_watch_seconds',
            ] as $field => $meta_key ) {
                $secs = min( absint( $body[ $field ] ?? 0 ), $cap );
                if ( $post_id > 0 && $secs > 0 ) {
                    update_post_meta( $post_id, $meta_key, (int) get_post_meta( $post_id, $meta_key, true ) + $secs );
                }
            }
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        },
        'permission_callback' => '__return_true',
    ] );
} );

// 7. Server-side Measurement Protocol — workflow stage changes
add_action( 'serve_workflow_stage_changed', static function ( int $post_id, string $stage, string $prev ): void {
    if ( ! serve_ga4_enabled() ) return;
    $secret = get_option( 'serve_ga4_api_secret', '' );
    if ( empty( $secret ) ) return;
    wp_remote_post(
        'https://www.google-analytics.com/mp/collect?measurement_id=' . rawurlencode( serve_ga4_id() ) . '&api_secret=' . rawurlencode( $secret ),
        [
            'blocking' => false,
            'timeout'  => 5,
            'headers'  => [ 'Content-Type' => 'application/json' ],
            'body'     => wp_json_encode( [
                'client_id' => 'server_' . md5( get_bloginfo( 'url' ) ),
                'events'    => [ [
                    'name'   => 'workflow_stage_change',
                    'params' => [ 'from_stage' => $prev, 'to_stage' => $stage, 'post_id' => $post_id, 'platform' => 'server' ],
                ] ],
            ] ),
        ]
    );
}, 10, 3 );

// 8. Admin settings page
add_action( 'admin_menu', static function (): void {
    add_options_page( 'Analytics', '📊 Analytics', 'manage_options', 'serve-analytics', 'serve_ga4_settings_page' );
} );

function serve_ga4_settings_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['serve_ga4_save'] ) && check_admin_referer( 'serve_ga4_save' ) ) {
        update_option( 'serve_gtm_id',             sanitize_text_field( wp_unslash( $_POST['serve_gtm_id']         ?? '' ) ) );
        update_option( 'serve_ga4_id',             sanitize_text_field( wp_unslash( $_POST['serve_ga4_id']         ?? '' ) ) );
        update_option( 'serve_ga4_api_secret',     sanitize_text_field( wp_unslash( $_POST['serve_ga4_api_secret'] ?? '' ) ) );
        update_option( 'serve_ga4_exclude_admins', isset( $_POST['serve_ga4_exclude_admins'] ) ? '1' : '' );
        update_option( 'serve_ga4_anonymize_ip',   isset( $_POST['serve_ga4_anonymize_ip'] )   ? '1' : '' );
        update_option( 'serve_ga4_debug',          isset( $_POST['serve_ga4_debug'] )           ? '1' : '' );
        update_option( 'apollo_hide_setup_tips',   isset( $_POST['apollo_hide_setup_tips'] )   ? '1' : '' );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Analytics settings saved.</p></div>';
    }

    $gtm  = serve_gtm_id();
    $id   = serve_ga4_id();
    ?>
    <div class="wrap" style="max-width:900px">
    <h1>📊 Analytics Settings</h1>

    <?php if ( serve_gtm_enabled() || serve_ga4_enabled() ) : ?>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px;margin-bottom:22px">
      ✅ <strong>Tracking active</strong>
      <?php if ( serve_gtm_enabled() ) : ?>— GTM: <code><?php echo esc_html($gtm); ?></code><?php endif; ?>
      <?php if ( serve_ga4_enabled() ) : ?>— GA4: <code><?php echo esc_html($id); ?></code><?php endif; ?>
    </div>
    <?php else : ?>
    <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:14px 18px;margin-bottom:22px">
      ⚠️ <strong>Not configured yet.</strong> Enter your GTM Container ID below to activate tracking.
    </div>
    <?php endif; ?>

    <form method="post">
    <?php wp_nonce_field( 'serve_ga4_save' ); ?>
    <table class="form-table">
      <tr>
        <th><label for="serve_gtm_id">GTM Container ID</label></th>
        <td>
          <input type="text" id="serve_gtm_id" name="serve_gtm_id" value="<?php echo esc_attr($gtm); ?>"
            placeholder="GTM-XXXXXXX" class="regular-text" style="font-family:monospace">
          <p class="description">Format: <code>GTM-XXXXXXX</code>. Found in GTM → Admin → Container Settings.</p>
        </td>
      </tr>
      <tr>
        <th><label for="serve_ga4_id">GA4 Measurement ID</label></th>
        <td>
          <input type="text" id="serve_ga4_id" name="serve_ga4_id" value="<?php echo esc_attr($id); ?>"
            placeholder="G-XXXXXXXXXX" class="regular-text" style="font-family:monospace">
          <p class="description">Format: <code>G-XXXXXXXXXX</code>. Optional if GTM is configured.</p>
        </td>
      </tr>
      <tr>
        <th><label for="serve_ga4_api_secret">Measurement Protocol Secret</label></th>
        <td>
          <input type="password" id="serve_ga4_api_secret" name="serve_ga4_api_secret"
            value="<?php echo esc_attr( get_option( 'serve_ga4_api_secret', '' ) ); ?>" class="regular-text" style="font-family:monospace">
          <p class="description">Optional. Enables server-side workflow stage events.</p>
        </td>
      </tr>
      <tr>
        <th>Options</th>
        <td>
          <label style="display:block;margin-bottom:8px">
            <input type="checkbox" name="serve_ga4_exclude_admins" <?php checked( get_option( 'serve_ga4_exclude_admins', '1' ), '1' ); ?>>
            Don't track administrators
          </label>
          <label style="display:block;margin-bottom:8px">
            <input type="checkbox" name="serve_ga4_anonymize_ip" <?php checked( get_option( 'serve_ga4_anonymize_ip', '' ), '1' ); ?>>
            Anonymize IP addresses
          </label>
          <label style="display:block">
            <input type="checkbox" name="serve_ga4_debug" <?php checked( get_option( 'serve_ga4_debug', '' ), '1' ); ?>>
            Debug mode
          </label>
        </td>
      </tr>
    </table>
    <input type="hidden" name="serve_ga4_save" value="1">
    <?php submit_button('Save Analytics Settings'); ?>
    </form>
    </div>
    <?php
}
