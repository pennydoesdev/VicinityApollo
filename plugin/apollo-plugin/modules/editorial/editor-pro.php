<?php

defined( 'ABSPATH' ) || exit;

add_action( 'init', function(): void {
    foreach ( [ 'post', 'page' ] as $pt ) {
        register_post_meta( $pt, '_serve_coauthors', [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string', // JSON array of user IDs
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
        register_post_meta( $pt, '_serve_pub_notes', [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string', // JSON array of note objects
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

add_filter( 'heartbeat_received', function( array $response, array $data ): array {
    if ( empty( $data['serve_editor_presence'] ) ) return $response;

    $post_id = absint( $data['serve_editor_presence']['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) return $response;

    $user    = wp_get_current_user();
    $uid     = $user->ID;
    $now     = time();
    $key     = '_serve_presence_' . $post_id;

    $presence = get_transient( $key );
    if ( ! is_array( $presence ) ) $presence = [];

    $presence[ $uid ] = [
        'uid'    => $uid,
        'name'   => $user->display_name ?: $user->user_login,
        'avatar' => get_avatar_url( $uid, [ 'size' => 32 ] ),
        'ts'     => $now,
    ];

    foreach ( $presence as $id => $entry ) {
        if ( $now - $entry['ts'] > 75 ) unset( $presence[ $id ] );
    }

    set_transient( $key, $presence, 90 );

    $others = array_values( array_filter( $presence, fn( $e ) => $e['uid'] !== $uid ) );
    $response['serve_presence'] = $others;
    return $response;
}, 10, 2 );

add_filter( 'heartbeat_settings', function( array $settings ): array {
    $settings['interval'] = 20; // seconds
    return $settings;
} );

add_action( 'enqueue_block_editor_assets', function(): void {
    if ( ! current_user_can( 'edit_posts' ) ) return;
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && ! in_array( $screen->post_type ?? '', [ 'post', 'page' ], true ) ) return;

    wp_register_script( 'serve-editor-pro', false,
        [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data',
          'wp-compose', 'wp-hooks', 'jquery', 'heartbeat' ],
        false, true );
    wp_enqueue_script( 'serve-editor-pro' );

    global $post;
    $post_id    = $post ? $post->ID : 0;
    $coauthors  = [];
    if ( $post_id ) {
        $raw = get_post_meta( $post_id, '_serve_coauthors', true );
        $ids = $raw ? json_decode( $raw, true ) : [];
        if ( is_array( $ids ) ) {
            foreach ( $ids as $id ) {
                $u = get_userdata( (int) $id );
                if ( $u ) $coauthors[] = [ 'id' => $u->ID, 'name' => $u->display_name, 'avatar' => get_avatar_url( $u->ID, ['size'=>32] ) ];
            }
        }
    }

    $all_users = get_users( [
        'role__in' => [ 'administrator', 'editor', 'author', 'contributor' ],
        'fields'   => [ 'ID', 'display_name' ],
        'number'   => 100,
        'orderby'  => 'display_name',
    ] );
    $users_list = array_map( fn($u) => [
        'id'     => $u->ID,
        'name'   => $u->display_name,
        'avatar' => get_avatar_url( $u->ID, ['size'=>32] ),
    ], $all_users );

    $pub_notes_raw = $post_id ? get_post_meta( $post_id, '_serve_pub_notes', true ) : '';
    $pub_notes     = $pub_notes_raw ? json_decode( $pub_notes_raw, true ) : [];
    if ( ! is_array( $pub_notes ) ) $pub_notes = [];

    $current_user = wp_get_current_user();

    wp_add_inline_script( 'serve-editor-pro',
        'window._serveEditorPro=' . wp_json_encode([
            'postId'      => $post_id,
            'nonce'       => wp_create_nonce( 'serve_editor_pro' ),
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'coauthors'   => $coauthors,
            'allUsers'    => $users_list,
            'pubNotes'    => $pub_notes,
            'currentUser' => [
                'id'     => $current_user->ID,
                'name'   => $current_user->display_name,
                'avatar' => get_avatar_url( $current_user->ID, ['size'=>32] ),
            ],
        ]) . ';',
        'before'
    );

    wp_add_inline_script( 'serve-editor-pro', serve_editor_pro_js() );
    wp_add_inline_style( 'wp-edit-post', serve_editor_pro_css() );
} );

add_action( 'wp_ajax_serve_save_coauthors', function(): void {
    check_ajax_referer( 'serve_editor_pro', 'nonce' );
    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $ids = array_map( 'absint', json_decode( wp_unslash( $_POST['ids'] ?? '[]' ), true ) ?: [] );
    $ids = array_filter( $ids, fn($id) => get_userdata($id) !== false );
    update_post_meta( $post_id, '_serve_coauthors', wp_json_encode( array_values($ids) ) );
    wp_send_json_success();
} );

add_action( 'wp_ajax_serve_save_pub_notes', function(): void {
    check_ajax_referer( 'serve_editor_pro', 'nonce' );
    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( 'Permission denied.' );
    }
    $raw   = json_decode( wp_unslash( $_POST['notes'] ?? '[]' ), true ) ?: [];
    $clean = [];
    foreach ( $raw as $note ) {
        if ( empty( $note['text'] ) ) continue;
        $clean[] = [
            'id'   => sanitize_text_field( $note['id']   ?? uniqid('n') ),
            'text' => sanitize_textarea_field( $note['text'] ),
            'uid'  => absint( $note['uid'] ?? get_current_user_id() ),
            'name' => sanitize_text_field( $note['name'] ?? '' ),
            'ts'   => absint( $note['ts'] ?? time() ),
        ];
    }
    update_post_meta( $post_id, '_serve_pub_notes', wp_json_encode( $clean ) );
    wp_send_json_success();
} );

add_filter( 'the_author', function( string $author ): string {
    if ( ! is_singular() || ! in_the_loop() ) return $author;
    $post_id = get_the_ID();
    $raw     = get_post_meta( $post_id, '_serve_coauthors', true );
    if ( ! $raw ) return $author;
    $ids = json_decode( $raw, true );
    if ( ! is_array( $ids ) || empty( $ids ) ) return $author;
    $names = [ $author ];
    foreach ( $ids as $id ) {
        $u = get_userdata( (int) $id );
        if ( $u && $u->ID !== get_post_field( 'post_author', $post_id ) ) {
            $names[] = $u->display_name;
        }
    }
    $names = array_unique( $names );
    return implode( ', ', $names );
} );

function serve_editor_pro_css(): string { return <<<CSS

/* ── Presence bar ──────────────────────────────────────────────────── */
.sep-presence-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 16px;
    background: #f9f9f9;
    border-bottom: 1px solid #e0e0e0;
    font-size: 12px;
    color: #555;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    min-height: 38px;
    flex-wrap: wrap;
}
.sep-presence-label { font-weight: 600; color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
.sep-presence-avatar {
    width: 26px; height: 26px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #10b981;
    margin-left: -6px;
    object-fit: cover;
}
.sep-presence-avatar:first-of-type { margin-left: 0; }
.sep-presence-name { font-size: 11px; color: #374151; font-weight: 600; }
.sep-presence-alone { font-size: 11px; color: #aaa; font-style: italic; }
.sep-saved-badge {
    margin-left: auto;
    font-size: 11px;
    color: #10b981;
    display: flex;
    align-items: center;
    gap: 4px;
}
.sep-saved-badge.unsaved { color: #f59e0b; }

/* ── Writing stats bar ─────────────────────────────────────────────── */
.sep-stats-bar {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 5px 16px;
    background: #fff;
    border-bottom: 1px solid #e9e9e9;
    font-size: 12px;
    color: #666;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    flex-wrap: wrap;
}
.sep-stat { display: flex; align-items: center; gap: 5px; }
.sep-stat-val { font-weight: 700; color: #111; }
.sep-stat-lbl { color: #888; }
.sep-readability { display: flex; align-items: center; gap: 5px; }
.sep-readability-bar {
    width: 52px; height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
}
.sep-readability-fill {
    height: 100%;
    border-radius: 3px;
    transition: width .4s, background .4s;
}
.sep-focus-btn {
    margin-left: auto;
    font-size: 11px;
    font-weight: 700;
    color: #2563eb;
    background: none;
    border: 1.5px solid #2563eb;
    border-radius: 5px;
    padding: 3px 10px;
    cursor: pointer;
    font-family: inherit;
    transition: background .15s, color .15s;
}
.sep-focus-btn:hover { background: #2563eb; color: #fff; }
body.sep-focus-mode .interface-interface-skeleton__sidebar,
body.sep-focus-mode .interface-interface-skeleton__secondary-sidebar,
body.sep-focus-mode .edit-post-header,
body.sep-focus-mode .block-editor-block-breadcrumb,
body.sep-focus-mode .sep-presence-bar,
body.sep-focus-mode .sep-stats-bar { display: none !important; }
body.sep-focus-mode .editor-styles-wrapper { max-width: 720px !important; margin: 48px auto !important; padding: 0 24px !important; }
body.sep-focus-mode .interface-interface-skeleton__content { background: #fafaf8 !important; }
body.sep-focus-mode::after {
    content: "Press Esc to exit focus mode";
    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
    background: rgba(0,0,0,.65); color: #fff;
    font-size: 12px; padding: 6px 14px; border-radius: 20px;
    font-family: -apple-system, sans-serif;
    pointer-events: none; opacity: .7;
}

/* ── Publish checklist ─────────────────────────────────────────────── */
.sep-checklist { padding: 4px 0; }
.sep-check-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    font-size: 12px;
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
}
.sep-check-item:last-child { border-bottom: none; }
.sep-check-icon { font-size: 14px; flex-shrink: 0; width: 18px; text-align: center; }
.sep-check-label { flex: 1; }
.sep-check-action { font-size: 11px; color: #2563eb; cursor: pointer; text-decoration: underline; }

/* ── Co-authors panel ──────────────────────────────────────────────── */
.sep-author-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #f3f4f6;
    border-radius: 16px;
    padding: 3px 8px 3px 4px;
    font-size: 12px;
    margin: 2px;
}
.sep-author-chip img { width: 20px; height: 20px; border-radius: 50%; }
.sep-author-remove {
    background: none; border: none; cursor: pointer;
    color: #9ca3af; font-size: 14px; line-height: 1;
    padding: 0; margin-left: 2px;
}
.sep-author-remove:hover { color: #c62828; }

/* ── Publisher notes ───────────────────────────────────────────────── */
.sep-note-item {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-left: 4px solid #f59e0b;
    border-radius: 4px;
    padding: 8px 10px;
    margin-bottom: 8px;
    font-size: 12px;
    color: #374151;
    position: relative;
}
.sep-note-meta { font-size: 11px; color: #9ca3af; margin-bottom: 4px; }
.sep-note-text { line-height: 1.5; }
.sep-note-del {
    position: absolute; top: 6px; right: 8px;
    background: none; border: none; cursor: pointer;
    color: #d97706; font-size: 14px; padding: 0;
}
.sep-note-del:hover { color: #c62828; }
CSS;
}

function serve_editor_pro_js(): string { return <<<'JS'
(function(){
'use strict';

var el       = wp.element.createElement;
var useState = wp.element.useState;
var useEffect= wp.element.useEffect;
var useRef   = wp.element.useRef;
var Fragment = wp.element.Fragment;
var useSelect= wp.data.useSelect;
var useDispatch = wp.data.useDispatch;
var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
var PluginPrePublishPanel      = wp.editPost.PluginPrePublishPanel;
var Button   = wp.components.Button;
var Notice   = wp.components.Notice;
var TextareaControl = wp.components.TextareaControl;
var D        = window._serveEditorPro || {};
var AJAX     = D.ajaxUrl || '';
var NONCE    = D.nonce   || '';
var POST_ID  = D.postId  || 0;

function ajax(action, data, cb){
    jQuery.post(AJAX, Object.assign({action:action, nonce:NONCE, post_id:POST_ID}, data), cb);
}
function humanTime(ts){
    var d=new Date(ts*1000);
    return d.toLocaleDateString('en-US',{month:'short',day:'numeric'}) + ' ' +
           d.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit'});
}

function insertPresenceBar(){
    if(document.getElementById('sep-presence-bar')) return;
    var bar = document.createElement('div');
    bar.id  = 'sep-presence-bar';
    bar.className = 'sep-presence-bar';
    bar.innerHTML = '<span class="sep-presence-label">Editing now</span>'
                  + '<span class="sep-presence-alone">Just you</span>'
                  + '<span class="sep-saved-badge" id="sep-saved-badge">● Saved</span>';

    var statsBar = document.createElement('div');
    statsBar.id  = 'sep-stats-bar';
    statsBar.className = 'sep-stats-bar';
    statsBar.innerHTML =
        '<span class="sep-stat"><span class="sep-stat-val" id="sep-wc">0</span><span class="sep-stat-lbl">words</span></span>' +
        '<span class="sep-stat"><span class="sep-stat-val" id="sep-rt">0</span><span class="sep-stat-lbl">min read</span></span>' +
        '<span class="sep-stat"><span class="sep-stat-val" id="sep-cc">0</span><span class="sep-stat-lbl">chars</span></span>' +
        '<span class="sep-readability">' +
            '<span class="sep-stat-lbl">Readability:</span>' +
            '<span class="sep-readability-bar"><span class="sep-readability-fill" id="sep-rb-fill" style="width:0%;background:#10b981"></span></span>' +
            '<span class="sep-stat-val" id="sep-rb-score">—</span>' +
        '</span>' +
        '<button class="sep-focus-btn" id="sep-focus-btn" title="Distraction-free writing mode">⛶ Focus</button>';

    var target = document.querySelector('.edit-post-header') || document.querySelector('.interface-interface-skeleton__header');
    if(target && target.parentNode){
        target.parentNode.insertBefore(statsBar, target.nextSibling);
        target.parentNode.insertBefore(bar, target.nextSibling);
    }

    document.getElementById('sep-focus-btn').addEventListener('click', function(){
        document.body.classList.toggle('sep-focus-mode');
    });
    document.addEventListener('keydown', function(e){
        if(e.key==='Escape' && document.body.classList.contains('sep-focus-mode')){
            document.body.classList.remove('sep-focus-mode');
        }
    });
}

function updateStats(){
    try {
        var store    = wp.data.select('core/editor');
        var content  = store.getEditedPostContent() || '';
        var text     = content.replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim();
        var words    = text ? text.split(/\s+/).length : 0;
        var chars    = text.replace(/\s/g,'').length;
        var readTime = Math.max(1, Math.ceil(words / 238));

        var sentences = (text.match(/[.!?]+/g)||[]).length || 1;
        var syllables = 0;
        (text.match(/[a-zA-Z]+/g)||[]).forEach(function(w){
            var s = w.toLowerCase().replace(/(?:[^laeiouy]|ed|[^laeiouy]e)$/, '')
                      .replace(/^[^aeiouy]/,'').match(/[aeiouy]{1,2}/g);
            syllables += s ? s.length : 1;
        });
        var asl  = words / sentences;
        var asw  = syllables / (words||1);
        var flesch = Math.min(100, Math.max(0, Math.round(206.835 - 1.015*asl - 84.6*asw)));
        var color  = flesch >= 60 ? '#10b981' : flesch >= 30 ? '#f59e0b' : '#ef4444';
        var label  = flesch >= 70 ? 'Easy' : flesch >= 50 ? 'OK' : flesch >= 30 ? 'Hard' : 'V. Hard';

        var wcEl = document.getElementById('sep-wc');
        var rtEl = document.getElementById('sep-rt');
        var ccEl = document.getElementById('sep-cc');
        var fill = document.getElementById('sep-rb-fill');
        var score= document.getElementById('sep-rb-score');
        if(wcEl) wcEl.textContent = words.toLocaleString();
        if(rtEl) rtEl.textContent = readTime;
        if(ccEl) ccEl.textContent = chars.toLocaleString();
        if(fill){ fill.style.width = flesch + '%'; fill.style.background = color; }
        if(score){ score.textContent = label; score.style.color = color; }
    } catch(e){}
}

jQuery(document).on('heartbeat-send', function(e, data){
    data.serve_editor_presence = { post_id: POST_ID };
});
jQuery(document).on('heartbeat-tick', function(e, data){
    if(!data.serve_presence) return;
    var others = data.serve_presence;
    var bar    = document.getElementById('sep-presence-bar');
    if(!bar) return;
    var alone  = bar.querySelector('.sep-presence-alone');
    bar.querySelectorAll('.sep-dyn-user').forEach(function(el){ el.remove(); });
    if(!others.length){
        if(alone) alone.style.display='';
    } else {
        if(alone) alone.style.display='none';
        others.forEach(function(u){
            var wrap = document.createElement('span');
            wrap.className = 'sep-dyn-user';
            wrap.style.cssText = 'display:flex;align-items:center;gap:5px;';
            var img  = document.createElement('img');
            img.src  = u.avatar; img.className = 'sep-presence-avatar';
            img.title= u.name;   img.alt = u.name;
            var name = document.createElement('span');
            name.className = 'sep-presence-name';
            name.textContent = u.name;
            wrap.appendChild(img);
            wrap.appendChild(name);
            var badge = document.getElementById('sep-saved-badge');
            bar.insertBefore(wrap, badge);
        });
    }
});

function watchSaveState(){
    var badge = document.getElementById('sep-saved-badge');
    if(!badge) return;
    wp.data.subscribe(function(){
        try {
            var isDirty = wp.data.select('core/editor').isEditedPostDirty();
            badge.textContent = isDirty ? '● Unsaved changes' : '● Saved';
            badge.className   = 'sep-saved-badge' + (isDirty ? ' unsaved' : '');
        } catch(e){}
    });
}

function CoAuthorsPanel(){
    var init = (D.coauthors||[]);
    var ar = useState(init), authors = ar[0], setAuthors = ar[1];
    var sr = useState(''), search = sr[0], setSearch = sr[1];
    var mr = useState(''), msg = mr[0], setMsg = mr[1];
    var allUsers = (D.allUsers||[]);

    function addAuthor(u){
        if(authors.find(function(a){return a.id===u.id;})) return;
        var next = authors.concat([u]);
        setAuthors(next);
        save(next);
        setSearch('');
    }
    function removeAuthor(id){
        var next = authors.filter(function(a){return a.id!==id;});
        setAuthors(next);
        save(next);
    }
    function save(list){
        ajax('serve_save_coauthors', {ids: JSON.stringify(list.map(function(a){return a.id;}))}, function(r){
            setMsg(r.success ? '✓ Saved' : '✗ Error');
            setTimeout(function(){setMsg('');},2000);
        });
    }

    var filtered = search.length > 1
        ? allUsers.filter(function(u){
            return u.name.toLowerCase().indexOf(search.toLowerCase())>=0
                && !authors.find(function(a){return a.id===u.id;});
          })
        : [];

    return el(Fragment, null,
        authors.length
            ? el('div', {style:{marginBottom:'8px',display:'flex',flexWrap:'wrap',gap:'2px'}},
                authors.map(function(a){
                    return el('span', {key:a.id, className:'sep-author-chip'},
                        el('img',{src:a.avatar,alt:a.name,width:20,height:20,style:{borderRadius:'50%'}}),
                        a.name,
                        el('button',{className:'sep-author-remove',onClick:function(){removeAuthor(a.id);},title:'Remove','aria-label':'Remove '+a.name},'×')
                    );
                })
              )
            : el('p',{style:{fontSize:'12px',color:'#9ca3af',margin:'0 0 8px'}},'No co-authors added.'),
        el('input',{
            type:'text',
            placeholder:'Search authors…',
            value:search,
            onChange:function(e){setSearch(e.target.value);},
            style:{width:'100%',padding:'6px 8px',border:'1.5px solid #d1d5db',borderRadius:'5px',fontSize:'12px',fontFamily:'inherit',boxSizing:'border-box',marginBottom:'4px'}
        }),
        filtered.length ? el('div',{style:{border:'1px solid #e5e7eb',borderRadius:'5px',overflow:'hidden',marginBottom:'8px'}},
            filtered.slice(0,6).map(function(u){
                return el('button',{
                    key:u.id,
                    onClick:function(){addAuthor(u);},
                    style:{display:'flex',alignItems:'center',gap:'7px',width:'100%',padding:'6px 10px',background:'#fff',border:'none',borderBottom:'1px solid #f3f4f6',cursor:'pointer',fontSize:'12px',fontFamily:'inherit',textAlign:'left'}
                },
                    el('img',{src:u.avatar,alt:u.name,width:22,height:22,style:{borderRadius:'50%'}}),
                    u.name
                );
            })
        ) : null,
        msg ? el('p',{style:{fontSize:'11px',color:'#10b981',margin:'0'}},msg) : null
    );
}

function PubNotesPanel(){
    var nr = useState(D.pubNotes||[]), notes = nr[0], setNotes = nr[1];
    var tr = useState(''), text = tr[0], setText = tr[1];
    var mr = useState(''), msg = mr[0], setMsg = mr[1];
    var cu = D.currentUser||{};

    function save(list){
        ajax('serve_save_pub_notes',{notes:JSON.stringify(list)},function(r){
            setMsg(r.success?'✓ Saved':'✗ Error');
            setTimeout(function(){setMsg('');},1500);
        });
    }
    function addNote(){
        if(!text.trim()) return;
        var note = {id:'n'+Date.now(),text:text.trim(),uid:cu.id,name:cu.name,ts:Math.floor(Date.now()/1000)};
        var next = notes.concat([note]);
        setNotes(next);
        save(next);
        setText('');
    }
    function deleteNote(id){
        var next = notes.filter(function(n){return n.id!==id;});
        setNotes(next);
        save(next);
    }

    return el(Fragment, null,
        notes.length
            ? el('div',{style:{marginBottom:'10px'}},
                notes.map(function(n){
                    return el('div',{key:n.id,className:'sep-note-item'},
                        el('div',{className:'sep-note-meta'},n.name+' · '+humanTime(n.ts)),
                        el('div',{className:'sep-note-text'},n.text),
                        el('button',{className:'sep-note-del',onClick:function(){deleteNote(n.id);},'aria-label':'Delete note',title:'Delete'},'×')
                    );
                })
              )
            : el('p',{style:{fontSize:'12px',color:'#9ca3af',margin:'0 0 8px'}},'No notes yet. Notes are never published.'),
        el('textarea',{
            placeholder:'Add an editor note…',
            value:text,
            onChange:function(e){setText(e.target.value);},
            rows:2,
            style:{width:'100%',padding:'7px 9px',border:'1.5px solid #d1d5db',borderRadius:'5px',fontSize:'12px',fontFamily:'inherit',boxSizing:'border-box',resize:'vertical',marginBottom:'5px'}
        }),
        el('button',{
            onClick:addNote,
            style:{padding:'5px 14px',background:'#f59e0b',color:'#fff',border:'none',borderRadius:'5px',fontSize:'12px',fontWeight:'700',cursor:'pointer',fontFamily:'inherit'}
        },'Add note'),
        msg ? el('span',{style:{marginLeft:'8px',fontSize:'11px',color:'#10b981'}},msg) : null
    );
}

function PrePublishChecklist(){
    var checks = useSelect(function(s){
        var store    = s('core/editor');
        var meta     = store.getEditedPostAttribute('meta') || {};
        var content  = store.getEditedPostContent() || '';
        var text     = content.replace(/<[^>]+>/g,' ').trim();
        var words    = text ? text.split(/\s+/).length : 0;
        return {
            hasFeaturedImage : !!store.getEditedPostAttribute('featured_media'),
            hasExcerpt       : !!(store.getEditedPostAttribute('excerpt') || '').trim(),
            hasCategory      : (store.getEditedPostAttribute('categories') || []).length > 0,
            hasSeoTitle      : !!(meta['serve_seo_title'] || '').trim(),
            wordCount        : words,
            hasEnoughWords   : words >= 150,
        };
    });

    var items = [
        { key:'hasFeaturedImage', icon: checks.hasFeaturedImage ?'✅':'⚠️', label:'Featured image',      ok:checks.hasFeaturedImage },
        { key:'hasExcerpt',       icon: checks.hasExcerpt       ?'✅':'⚠️', label:'Excerpt',              ok:checks.hasExcerpt },
        { key:'hasCategory',      icon: checks.hasCategory      ?'✅':'⚠️', label:'Category assigned',    ok:checks.hasCategory },
        { key:'hasSeoTitle',      icon: checks.hasSeoTitle      ?'✅':'💡', label:'SEO title set',        ok:checks.hasSeoTitle, soft:true },
        { key:'hasEnoughWords',   icon: checks.hasEnoughWords   ?'✅':'💡', label:'150+ words ('+checks.wordCount+')', ok:checks.hasEnoughWords, soft:true },
    ];

    return el('div', {className:'sep-checklist'},
        items.map(function(item){
            return el('div',{key:item.key, className:'sep-check-item'},
                el('span',{className:'sep-check-icon'},item.icon),
                el('span',{className:'sep-check-label', style:{color:item.ok?'#374151':'#b45309'}},item.label)
            );
        }),
        !checks.hasFeaturedImage || !checks.hasExcerpt || !checks.hasCategory
            ? el('p',{style:{fontSize:'11px',color:'#b45309',marginTop:'8px',marginBottom:'0',lineHeight:'1.5'}},
                '⚠️ Please fix the items above before publishing for best results.')
            : el('p',{style:{fontSize:'11px',color:'#10b981',marginTop:'8px',marginBottom:'0'}},
                '✅ Looks good to publish!')
    );
}

wp.plugins.registerPlugin('serve-editor-pro', {
    render: function(){
        return el(Fragment, null,
            el(PluginDocumentSettingPanel,{
                name:'serve-coauthors',
                title:'Co-Authors',
                className:'serve-coauthors-panel',
                initialOpen:false,
                order:60
            }, el(CoAuthorsPanel)),
            el(PluginDocumentSettingPanel,{
                name:'serve-pub-notes',
                title:'Editor Notes',
                className:'serve-pub-notes-panel',
                initialOpen:false,
                order:70
            }, el(PubNotesPanel)),
            el(PluginPrePublishPanel,{
                name:'serve-publish-checklist',
                title:'📋 Pre-Publish Checklist',
                className:'serve-publish-checklist',
                initialOpen:true
            }, el(PrePublishChecklist))
        );
    }
});

wp.domReady(function(){
    setTimeout(function(){
        insertPresenceBar();
        watchSaveState();
        wp.data.subscribe(function(){
            updateStats();
        });
        updateStats();
    }, 800);
});

})();
JS;
}
