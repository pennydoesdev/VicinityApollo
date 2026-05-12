<?php

defined( 'ABSPATH' ) || exit;

add_action( 'enqueue_block_editor_assets', function(): void {
    global $post;
    if ( ! $post || $post->post_type !== 'post' ) return;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

    $updates    = get_post_meta( $post->ID, '_flavor_live_updates', true ) ?: [];
    $is_live    = get_post_meta( $post->ID, '_flavor_is_live', true ) === '1';
    $live_label = get_post_meta( $post->ID, '_flavor_live_label', true ) ?: 'LIVE';
    $auto_ins   = get_post_meta( $post->ID, '_flavor_live_auto_insert', true ) === '1';

    wp_register_script( 'serve-live-sidebar', false,
        [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'jquery' ],
        false, true );
    wp_enqueue_script( 'serve-live-sidebar' );

    $nonce   = wp_create_nonce( 'serve_live_updates_save' );
    $ajax    = admin_url( 'admin-ajax.php' );
    $post_id = $post->ID;

    $data_js = sprintf(
        'window._serveLiveData=%s;',
        wp_json_encode( [
            'postId'    => $post_id,
            'nonce'     => $nonce,
            'ajaxUrl'   => $ajax,
            'isLive'    => $is_live,
            'liveLabel' => $live_label,
            'autoInsert'=> $auto_ins,
            'entries'   => array_values( $updates ),
        ] )
    );
    wp_add_inline_script( 'serve-live-sidebar', $data_js, 'before' );
    wp_add_inline_script( 'serve-live-sidebar', serve_live_sidebar_js() );
} );

add_action( 'wp_ajax_serve_live_updates_save', function(): void {
    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id )
        || ! check_ajax_referer( 'serve_live_updates_save', 'nonce', false ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    update_post_meta( $post_id, '_flavor_is_live',         ! empty( $_POST['is_live'] ) ? '1' : '0' );
    update_post_meta( $post_id, '_flavor_live_label',       sanitize_text_field( wp_unslash( $_POST['live_label'] ?? 'LIVE' ) ) );
    update_post_meta( $post_id, '_flavor_live_auto_insert', ! empty( $_POST['auto_insert'] ) ? '1' : '0' );

    $raw   = json_decode( wp_unslash( $_POST['entries'] ?? '[]' ), true ) ?: [];
    $clean = [];
    foreach ( $raw as $e ) {
        if ( empty( $e['headline'] ) && empty( $e['content'] ) ) continue;
        $clean[] = [
            'time'     => sanitize_text_field( $e['time']     ?? '' ),
            'status'   => sanitize_text_field( $e['status']   ?? 'update' ),
            'author'   => sanitize_text_field( $e['author']   ?? '' ),
            'headline' => sanitize_text_field( $e['headline'] ?? '' ),
            'content'  => wp_kses_post( $e['content'] ?? '' ),
            'media'    => esc_url_raw( $e['media'] ?? '' ),
            'pullquote'=> sanitize_text_field( $e['pullquote'] ?? '' ),
            'source'   => sanitize_text_field( $e['source']   ?? '' ),
            'type'     => sanitize_text_field( $e['type']     ?? 'update' ),
        ];
    }
    usort( $clean, fn( $a, $b ) => strcmp( $b['time'], $a['time'] ) );
    update_post_meta( $post_id, '_flavor_live_updates', $clean );
    wp_send_json_success( [ 'count' => count( $clean ), 'saved_at' => current_time( 'g:i:s a' ) ] );
} );

function serve_live_sidebar_js(): string {
    return <<<'JS'
(function(){
var el=wp.element.createElement,useState=wp.element.useState,useEffect=wp.element.useEffect,Fragment=wp.element.Fragment;
var PluginDocumentSettingPanel=wp.editPost.PluginDocumentSettingPanel;
var Spinner=wp.components.Spinner;

var D=window._serveLiveData||{};

var STATUS_OPTS=[
    {label:'Update',    value:'update',    color:'#2563eb', dot:'🔵'},
    {label:'Breaking',  value:'breaking',  color:'#c62828', dot:'🔴'},
    {label:'Developing',value:'developing',color:'#d97706', dot:'🟡'},
    {label:'Confirmed', value:'confirmed', color:'#059669', dot:'🟢'},
    {label:'Correction',value:'correction',color:'#7c3aed', dot:'🟣'},
    {label:'Resolved',  value:'resolved',  color:'#6b7280', dot:'⚫'},
];
var TYPE_OPTS=[
    {label:'Text update', value:'update',    icon:'📝'},
    {label:'Photo/Media', value:'photo',     icon:'📷'},
    {label:'Pull quote',  value:'pullquote', icon:'💬'},
    {label:'Data/Stat',   value:'data',      icon:'📊'},
    {label:'Source cite', value:'source',    icon:'🔗'},
    {label:'Video embed', value:'video',     icon:'🎥'},
];

function doSave(entries,isLive,liveLabel,autoInsert,cb){
    jQuery.post(D.ajaxUrl,{
        action:'serve_live_updates_save',
        post_id:D.postId, nonce:D.nonce,
        is_live:isLive?'1':'', live_label:liveLabel,
        auto_insert:autoInsert?'1':'',
        entries:JSON.stringify(entries)
    },function(r){cb(r.success,r.success?(r.data.count+' update'+(r.data.count===1?'':'s')+' saved'):(r.data||'Error'));})
    .fail(function(){cb(false,'Network error');});
}

function newEntry(){
    var now=new Date(),pad=function(n){return String(n).padStart(2,'0');};
    var ts=now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate())+'T'+pad(now.getHours())+':'+pad(now.getMinutes());
    return {time:ts,status:'update',type:'update',author:'',headline:'',content:'',media:'',pullquote:'',source:''};
}

var _drawerRoot=null, _drawerSetOpen=null, _drawerSetEntries=null, _drawerGetState=null;
window._drawerAddEntry=null;

function mountDrawer(){
    if(_drawerRoot) return;
    _drawerRoot=document.createElement('div');
    _drawerRoot.id='slive-drawer-root';
    document.body.appendChild(_drawerRoot);
    var render=wp.element.render;
    if(!render&&window.ReactDOM) render=window.ReactDOM.render;
    if(render) render(el(DrawerApp),_drawerRoot);
}

function DrawerApp(){
    var or=useState(false),open=or[0],setOpen=or[1];
    var er=useState((D.entries||[])),entries=er[0],setEntries=er[1];
    var fr=useState(null),focusIdx=fr[0],setFocusIdx=fr[1];
    var ir=useState(D.isLive||false),isLive=ir[0],setIsLive=ir[1];
    var llr=useState(D.liveLabel||'LIVE'),liveLabel=llr[0],setLiveLabel=llr[1];
    var air=useState(D.autoInsert||false),autoInsert=air[0],setAutoInsert=air[1];
    var sr=useState('idle'),saving=sr[0],setSaving=sr[1];
    var mr=useState(''),saveMsg=mr[0],setSaveMsg=mr[1];

    useEffect(function(){
        _drawerSetOpen=setOpen;
        _drawerSetEntries=setEntries;
        _drawerGetState=function(){return{entries:entries,isLive:isLive,liveLabel:liveLabel,autoInsert:autoInsert};};
        window._drawerAddEntry=function(){
            var e=newEntry();
            setEntries(function(prev){return [e].concat(prev);});
            setFocusIdx(0);
            setOpen(true);
        };
    });

    function updateEntry(idx,updated){setEntries(function(prev){return prev.map(function(en,i){return i===idx?updated:en;});});}
    function removeEntry(idx){if(!window.confirm('Delete this update?'))return;setEntries(function(prev){return prev.filter(function(_,i){return i!==idx;});});setFocusIdx(null);}
    function save(){
        if(!_drawerGetState) return;
        var s=_drawerGetState();
        setSaving('saving');setSaveMsg('');
        doSave(s.entries,s.isLive,s.liveLabel,s.autoInsert,function(ok,msg){
            setSaving('done');setSaveMsg(ok?'✓ '+msg:'✗ '+msg);
            setTimeout(function(){setSaving('idle');setSaveMsg('');},3000);
        });
    }

    var isSaving=saving==='saving';
    if(!open) return null;

    return el(Fragment,null,
        el('div',{onClick:function(){setOpen(false);},style:{position:'fixed',inset:0,background:'rgba(15,15,15,.55)',zIndex:159999,backdropFilter:'blur(3px)'}}),
        el('div',{style:{position:'fixed',left:0,right:0,bottom:0,height:'84vh',zIndex:160000,background:'#fff',borderTop:'3px solid #c62828',boxShadow:'0 -12px 60px rgba(0,0,0,.22)',display:'flex',flexDirection:'column',fontFamily:'-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,sans-serif',borderRadius:'14px 14px 0 0'}},

            el('div',{style:{position:'absolute',top:'8px',left:'50%',transform:'translateX(-50%)',width:'44px',height:'4px',borderRadius:'2px',background:'#e0e0e0',cursor:'pointer'},onClick:function(){setOpen(false);}}),

            el('div',{style:{display:'flex',alignItems:'center',gap:'12px',padding:'20px 20px 14px',borderBottom:'1px solid #f0f0f0',flexShrink:0}},
                el('button',{
                    onClick:function(){setIsLive(function(v){return !v;});},
                    style:{display:'flex',alignItems:'center',gap:'6px',padding:'6px 14px',borderRadius:'20px',background:isLive?'#c62828':'#f3f4f6',color:isLive?'#fff':'#6b7280',border:'none',cursor:'pointer',fontSize:'11px',fontWeight:700,letterSpacing:'.06em',textTransform:'uppercase',transition:'all .15s',flexShrink:0}
                },
                    el('span',{style:{width:'7px',height:'7px',borderRadius:'50%',background:isLive?'rgba(255,255,255,.7)':'#9ca3af',display:'inline-block',boxShadow:isLive?'0 0 0 2px rgba(255,255,255,.3)':'none'}}),
                    isLive?'● Live':'○ Offline'
                ),
                el('div',{style:{flex:1}},
                    el('h2',{style:{margin:0,fontSize:'15px',fontWeight:700,color:'#111',letterSpacing:'-.01em'}},'📡 Live Updates Timeline'),
                    el('p',{style:{margin:'1px 0 0',fontSize:'11px',color:'#9ca3af'}},entries.length+' update'+(entries.length===1?'':'s'))
                ),
                el('button',{onClick:save,disabled:isSaving,style:{padding:'8px 18px',background:isSaving?'#9ca3af':'#111',color:'#fff',border:'none',borderRadius:'8px',fontSize:'12px',fontWeight:700,cursor:isSaving?'not-allowed':'pointer',display:'flex',alignItems:'center',gap:'6px',letterSpacing:'.02em'}},
                    isSaving?el(Spinner,{style:{width:14,height:14}}):'Save All'
                ),
                el('button',{onClick:function(){setOpen(false);},style:{background:'none',border:'1px solid #e5e7eb',borderRadius:'8px',padding:'7px 12px',cursor:'pointer',color:'#6b7280',fontSize:'13px',lineHeight:1,flexShrink:0}},'✕')
            ),

            saveMsg&&el('div',{style:{padding:'6px 20px',fontSize:'11px',fontWeight:600,background:saveMsg.startsWith('✓')?'#f0fdf4':'#fef2f2',color:saveMsg.startsWith('✓')?'#166534':'#991b1b',borderBottom:'1px solid '+(saveMsg.startsWith('✓')?'#bbf7d0':'#fecaca'),flexShrink:0}},saveMsg),

            el('div',{style:{display:'flex',flex:1,overflow:'hidden'}},

                el('div',{style:{width:'250px',flexShrink:0,borderRight:'1px solid #f0f0f0',overflowY:'auto',display:'flex',flexDirection:'column',background:'#fafaf8'}},
                    el('div',{style:{padding:'10px',borderBottom:'1px solid #f0f0f0',flexShrink:0}},
                        el('button',{
                            onClick:function(){if(window._drawerAddEntry) window._drawerAddEntry();},
                            style:{width:'100%',padding:'9px',background:'#c62828',color:'#fff',border:'none',borderRadius:'8px',fontSize:'12px',fontWeight:700,cursor:'pointer',display:'flex',alignItems:'center',justifyContent:'center',gap:'5px',letterSpacing:'.02em'}
                        },'＋ Add Update')
                    ),
                    el('div',{style:{flex:1,overflowY:'auto',padding:'8px'}},
                        entries.length===0&&el('div',{style:{padding:'24px 10px',textAlign:'center',color:'#9ca3af',fontSize:'12px',lineHeight:1.7}},'No updates yet.',el('br'),'Click + Add Update to begin.'),
                        entries.map(function(e,i){
                            var st=STATUS_OPTS.find(function(s){return s.value===e.status;})||STATUS_OPTS[0];
                            var active=focusIdx===i;
                            return el('div',{
                                key:i,onClick:function(){setFocusIdx(i);},
                                style:{padding:'9px 10px',borderRadius:'8px',marginBottom:'3px',cursor:'pointer',
                                       background:active?'#fff':'transparent',
                                       border:active?'1px solid #e5e7eb':'1px solid transparent',
                                       boxShadow:active?'0 1px 6px rgba(0,0,0,.07)':'none',
                                       borderLeft:'3px solid '+(active?st.color:'transparent'),
                                       transition:'all .1s'}
                            },
                                el('div',{style:{display:'flex',alignItems:'center',gap:'4px',marginBottom:'2px'}},
                                    el('span',{style:{fontSize:'9px',fontWeight:800,color:st.color,textTransform:'uppercase',letterSpacing:'.07em'}},st.label),
                                    el('span',{style:{fontSize:'10px',color:'#9ca3af',marginLeft:'auto',fontFamily:'monospace'}},e.time?e.time.slice(5,16).replace('T',' '):'')
                                ),
                                el('div',{style:{fontSize:'12px',color:'#374151',overflow:'hidden',textOverflow:'ellipsis',whiteSpace:'nowrap',lineHeight:1.3}},
                                    e.headline||el('em',{style:{color:'#bbb'}},'(no headline)')
                                )
                            );
                        })
                    )
                ),

                focusIdx!==null&&entries[focusIdx]
                    ?el(EntryForm,{entry:entries[focusIdx],idx:focusIdx,onChange:updateEntry,onRemove:removeEntry,total:entries.length,
                        onNext:function(){setFocusIdx(function(i){return Math.min(i+1,entries.length-1);});},
                        onPrev:function(){setFocusIdx(function(i){return Math.max(i-1,0);}); }})
                    :el('div',{style:{flex:1,display:'flex',flexDirection:'column',alignItems:'center',justifyContent:'center',gap:'8px',color:'#9ca3af'}},
                        el('div',{style:{fontSize:'28px'}},'📡'),
                        el('div',{style:{fontSize:'13px',fontWeight:600}},'Select an update to edit'),
                        el('div',{style:{fontSize:'11px'}}, 'or click + Add Update to create one')
                      )
            )
        )
    );
}

function EntryForm(props){
    var e=props.entry,idx=props.idx;
    function set(k,v){var u=Object.assign({},e);u[k]=v;props.onChange(idx,u);}
    var I={width:'100%',padding:'9px 11px',border:'1px solid #e5e7eb',borderRadius:'8px',fontSize:'13px',fontFamily:'inherit',outline:'none',boxSizing:'border-box',background:'#fff',transition:'border-color .12s'};
    var TA=Object.assign({},I,{resize:'vertical',minHeight:'88px'});
    var L={display:'block',fontSize:'10px',fontWeight:700,textTransform:'uppercase',letterSpacing:'.08em',color:'#6b7280',marginBottom:'5px'};
    var S={marginBottom:'18px'};

    return el('div',{style:{flex:1,overflowY:'auto',padding:'20px 24px',fontFamily:'-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,sans-serif'}},

        el('div',{style:{display:'flex',alignItems:'center',gap:'6px',marginBottom:'18px',paddingBottom:'14px',borderBottom:'1px solid #f0f0f0'}},
            el('button',{onClick:props.onPrev,disabled:idx===0,style:{background:'none',border:'1px solid #e5e7eb',borderRadius:'6px',padding:'5px 10px',cursor:idx===0?'not-allowed':'pointer',color:idx===0?'#d1d5db':'#374151',fontSize:'12px'}},'← Prev'),
            el('span',{style:{fontSize:'11px',color:'#9ca3af',flex:1,textAlign:'center',fontFamily:'monospace'}},(idx+1)+' / '+props.total),
            el('button',{onClick:props.onNext,disabled:idx===props.total-1,style:{background:'none',border:'1px solid #e5e7eb',borderRadius:'6px',padding:'5px 10px',cursor:idx===props.total-1?'not-allowed':'pointer',color:idx===props.total-1?'#d1d5db':'#374151',fontSize:'12px'}},'Next →'),
            el('button',{onClick:function(){props.onRemove(idx);},style:{marginLeft:'6px',background:'none',border:'1px solid #fecaca',borderRadius:'6px',padding:'5px 10px',cursor:'pointer',color:'#ef4444',fontSize:'12px'}},'🗑 Delete')
        ),

        el('div',{style:S},
            el('label',{style:L},'Status'),
            el('div',{style:{display:'flex',flexWrap:'wrap',gap:'5px'}},
                STATUS_OPTS.map(function(s){
                    var a=e.status===s.value;
                    return el('button',{key:s.value,onClick:function(){set('status',s.value);},style:{padding:'6px 12px',borderRadius:'20px',border:'1.5px solid '+(a?s.color:'#e5e7eb'),background:a?s.color:'#fff',color:a?'#fff':'#374151',fontSize:'11px',fontWeight:a?700:400,cursor:'pointer',transition:'all .12s',letterSpacing:'.01em'}},s.dot+' '+s.label);
                })
            )
        ),

        el('div',{style:S},
            el('label',{style:L},'Entry Type'),
            el('div',{style:{display:'flex',flexWrap:'wrap',gap:'5px'}},
                TYPE_OPTS.map(function(t){
                    var a=(e.type||'update')===t.value;
                    return el('button',{key:t.value,onClick:function(){set('type',t.value);},style:{padding:'6px 12px',borderRadius:'20px',border:'1.5px solid '+(a?'#111':'#e5e7eb'),background:a?'#111':'#fff',color:a?'#fff':'#374151',fontSize:'11px',cursor:'pointer',transition:'all .12s'}},t.icon+' '+t.label);
                })
            )
        ),

        el('div',{style:S},
            el('label',{style:L},'Timestamp'),
            el('input',{type:'datetime-local',style:I,value:e.time||'',onChange:function(ev){set('time',ev.target.value);}})
        ),

        el('div',{style:S},
            el('label',{style:L},'Headline'),
            el('input',{type:'text',style:Object.assign({},I,{fontSize:'16px',fontWeight:600,letterSpacing:'-.01em'}),value:e.headline||'',placeholder:'Short, punchy headline…',onChange:function(ev){set('headline',ev.target.value);}})
        ),

        el('div',{style:S},
            el('label',{style:L},'Details / Body'),
            el('textarea',{style:Object.assign({},TA,{minHeight:'100px'}),value:e.content||'',placeholder:'Full update text. HTML supported…',onChange:function(ev){set('content',ev.target.value);}})
        ),

        (e.type==='pullquote')&&el('div',{style:S},el('label',{style:L},'Pull Quote'),el('textarea',{style:Object.assign({},TA,{fontStyle:'italic',fontSize:'14px',minHeight:'60px'}),value:e.pullquote||'',placeholder:'"The quote…"',onChange:function(ev){set('pullquote',ev.target.value);}})),
        (e.type==='source')&&el('div',{style:S},el('label',{style:L},'Source / Citation'),el('textarea',{style:Object.assign({},TA,{minHeight:'52px'}),value:e.source||'',placeholder:'Source name, publication, link…',onChange:function(ev){set('source',ev.target.value);}})),
        (e.type==='photo'||e.type==='video')&&el('div',{style:S},el('label',{style:L},e.type==='photo'?'Image URL':'Video Embed URL'),el('input',{type:'url',style:I,value:e.media||'',placeholder:'https://…',onChange:function(ev){set('media',ev.target.value);}})),

        el('div',{style:S},
            el('label',{style:L},'Reporter / Byline'),
            el('input',{type:'text',style:I,value:e.author||'',placeholder:'Optional — leave blank to omit',onChange:function(ev){set('author',ev.target.value);}})
        )
    );
}

function LiveSidebarPanel(){
    var er=useState(D.entries||[]),entries=er[0],setEntries=er[1];
    var ir=useState(D.isLive||false),isLive=ir[0],setIsLive=ir[1];
    var llr=useState(D.liveLabel||'LIVE'),liveLabel=llr[0],setLiveLabel=llr[1];
    var air=useState(D.autoInsert||false),autoInsert=air[0],setAutoInsert=air[1];
    var sr=useState('idle'),saving=sr[0],setSaving=sr[1];
    var mr=useState(''),msg=mr[0],setMsg=mr[1];

    useEffect(function(){
        var id=setInterval(function(){
            if(_drawerGetState){var s=_drawerGetState();setEntries(s.entries);}
        },600);
        return function(){clearInterval(id);};
    },[]);

    function saveNow(){
        var cur=_drawerGetState?_drawerGetState():{entries:entries,isLive:isLive,liveLabel:liveLabel,autoInsert:autoInsert};
        setSaving('saving');
        doSave(cur.entries,isLive,liveLabel,autoInsert,function(ok,m){
            setSaving('done');setMsg(ok?'✓ '+m:'✗ '+m);
            setTimeout(function(){setSaving('idle');setMsg('');},3000);
        });
    }

    var isSaving=saving==='saving';
    var F='-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif';

    return el('div',{style:{fontFamily:F,padding:'2px 0'}},

        el('div',{
            onClick:function(){setIsLive(function(v){return !v;});},
            style:{display:'flex',alignItems:'center',gap:'8px',padding:'10px 12px',borderRadius:'8px',background:isLive?'#fff5f5':'#f9fafb',border:'1.5px solid '+(isLive?'#fca5a5':'#e5e7eb'),cursor:'pointer',marginBottom:'10px',userSelect:'none'}
        },
            el('div',{style:{width:'10px',height:'10px',borderRadius:'50%',background:isLive?'#c62828':'#9ca3af',flexShrink:0,boxShadow:isLive?'0 0 0 3px rgba(198,40,40,.2)':'none'}}),
            el('div',{style:{flex:1}},
                el('div',{style:{fontSize:'12px',fontWeight:700,color:isLive?'#c62828':'#374151'}},'● '+(isLive?'STORY IS LIVE':'Story offline')),
                el('div',{style:{fontSize:'10px',color:'#9ca3af',marginTop:'1px'}},entries.length+' update'+(entries.length===1?'':'s'))
            ),
            el('span',{style:{fontSize:'10px',fontWeight:700,textTransform:'uppercase',letterSpacing:'.04em',color:isLive?'#c62828':'#9ca3af'}},isLive?'On':'Off')
        ),

        isLive&&el('div',{style:{marginBottom:'10px'}},
            el('label',{style:{display:'block',fontSize:'10px',fontWeight:700,textTransform:'uppercase',letterSpacing:'.07em',color:'#6b7280',marginBottom:'4px'}},'Badge Label'),
            el('input',{type:'text',style:{width:'100%',padding:'6px 8px',border:'1px solid #e5e7eb',borderRadius:'6px',fontSize:'12px',fontFamily:F,boxSizing:'border-box'},value:liveLabel,onChange:function(ev){setLiveLabel(ev.target.value);}})
        ),

        el('label',{style:{display:'flex',alignItems:'center',gap:'6px',fontSize:'11px',color:'#374151',cursor:'pointer',marginBottom:'12px',userSelect:'none'}},
            el('input',{type:'checkbox',checked:autoInsert,onChange:function(ev){setAutoInsert(ev.target.checked);},style:{accentColor:'#c62828'}}),
            'Auto-insert timeline'
        ),

        el('button',{
            onClick:function(){if(_drawerSetOpen) _drawerSetOpen(true);},
            style:{width:'100%',padding:'9px',background:'#c62828',color:'#fff',border:'none',borderRadius:'8px',fontSize:'12px',fontWeight:700,cursor:'pointer',marginBottom:'6px',display:'flex',alignItems:'center',justifyContent:'center',gap:'5px',letterSpacing:'.02em'}
        },'📡 Manage Updates'+(entries.length?' ('+entries.length+')':'')),

        el('button',{onClick:saveNow,disabled:isSaving,style:{width:'100%',padding:'7px',background:'#fff',color:isSaving?'#9ca3af':'#111',border:'1px solid '+(isSaving?'#e5e7eb':'#d1d5db'),borderRadius:'8px',fontSize:'11px',fontWeight:600,cursor:isSaving?'not-allowed':'pointer',display:'flex',alignItems:'center',justifyContent:'center',gap:'5px'}},
            isSaving?el(Spinner,{style:{width:12,height:12}}):'Save Status'
        ),

        msg&&el('div',{style:{marginTop:'6px',fontSize:'11px',padding:'4px 8px',borderRadius:'4px',background:msg.startsWith('✓')?'#f0fdf4':'#fef2f2',color:msg.startsWith('✓')?'#166534':'#991b1b'}},msg)
    );
}

function injectFAB(){
    if(document.getElementById('slive-fab')) return;
    var fab=document.createElement('button');
    fab.id='slive-fab';
    fab.innerHTML='<span style="font-size:15px">📡</span> Add Live Update';
    fab.style.cssText='position:fixed;bottom:28px;left:50%;transform:translateX(-50%);z-index:99998;background:#c62828;color:#fff;border:none;border-radius:100px;padding:11px 24px;font-size:13px;font-weight:700;letter-spacing:.03em;cursor:pointer;box-shadow:0 4px 24px rgba(198,40,40,.4),0 1px 4px rgba(0,0,0,.15);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;display:flex;align-items:center;gap:8px;user-select:none;transition:transform .15s cubic-bezier(.34,1.56,.64,1),box-shadow .15s;';
    fab.onmouseenter=function(){fab.style.transform='translateX(-50%) translateY(-3px) scale(1.03)';fab.style.boxShadow='0 8px 32px rgba(198,40,40,.45),0 2px 8px rgba(0,0,0,.18)';};
    fab.onmouseleave=function(){fab.style.transform='translateX(-50%)';fab.style.boxShadow='0 4px 24px rgba(198,40,40,.4),0 1px 4px rgba(0,0,0,.15)';};
    fab.onclick=function(){if(window._drawerAddEntry) window._drawerAddEntry();};
    document.body.appendChild(fab);
    setInterval(function(){
        var backdrop=document.querySelector('[style*="rgba(15,15,15,.55)"]')||document.querySelector('[style*="rgba(0,0,0,.5)"]');
        if(backdrop){fab.style.opacity='0';fab.style.pointerEvents='none';}
        else{fab.style.opacity='1';fab.style.pointerEvents='auto';}
    },250);
}

function boot(){mountDrawer();injectFAB();}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',boot);}
else{boot();}

wp.plugins.registerPlugin('serve-live-timeline',{
    render:function(){
        return el(PluginDocumentSettingPanel,{
            name:'serve-live-panel',
            title:'📡 Live Updates Timeline',
            className:'serve-live-panel',
            initialOpen:false
        },el(LiveSidebarPanel));
    }
});
})();
JS;
}

function flavor_live_updates_meta_box() {
    if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( 'post' ) ) return;
    add_meta_box(
        'flavor_live_updates',
        esc_html__( 'Live Updates Timeline', 'serve' ),
        'flavor_live_updates_meta_box_html',
        'post',
        'normal',
        'high'
    );
}
add_action( 'add_meta_boxes', 'flavor_live_updates_meta_box' );

function flavor_live_updates_meta_box_html( $post ) {
    wp_nonce_field( 'flavor_live_updates_nonce', 'flavor_live_updates_nonce_field' );

    $updates     = get_post_meta( $post->ID, '_flavor_live_updates', true );
    $is_live     = get_post_meta( $post->ID, '_flavor_is_live', true );
    $live_label  = get_post_meta( $post->ID, '_flavor_live_label', true );
    $auto_insert = get_post_meta( $post->ID, '_flavor_live_auto_insert', true );

    if ( ! is_array( $updates ) ) {
        $updates = [];
    }
    if ( empty( $live_label ) ) {
        $live_label = 'LIVE';
    }
    ?>
    <style>
        .flavor-lu-settings { display: flex; gap: 1.5rem; align-items: center; margin-bottom: 1rem; padding: 0.75rem; background: #f9f9f9; border: 1px solid #ddd; }
        .flavor-lu-settings label { font-weight: 600; font-size: 13px; }
        .flavor-lu-settings input[type="text"] { width: 120px; }
        .flavor-lu-entries { margin-top: 1rem; }
        .flavor-lu-entry { background: #fff; border: 1px solid #e0e0e0; padding: 1rem; margin-bottom: 0.75rem; position: relative; }
        .flavor-lu-entry:hover { border-color: #c62828; }
        .flavor-lu-entry .flavor-lu-remove { position: absolute; top: 0.5rem; right: 0.5rem; color: #c62828; cursor: pointer; background: none; border: none; font-size: 18px; line-height: 1; }
        .flavor-lu-entry label { display: block; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 0.25rem; color: #555; }
        .flavor-lu-entry input[type="text"],
        .flavor-lu-entry input[type="datetime-local"],
        .flavor-lu-entry select { width: 100%; padding: 0.4rem; border: 1px solid #ddd; margin-bottom: 0.5rem; }
        .flavor-lu-entry textarea { width: 100%; min-height: 80px; padding: 0.4rem; border: 1px solid #ddd; margin-bottom: 0.5rem; }
        .flavor-lu-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; }
        #flavor-lu-add { background: #c62828; color: #fff; border: none; padding: 0.5rem 1.5rem; cursor: pointer; font-weight: 600; text-transform: uppercase; font-size: 12px; letter-spacing: 0.04em; }
    </style>

    <div class="flavor-lu-settings">
        <label>
            <input type="checkbox" name="flavor_is_live" value="1" <?php checked( $is_live, '1' ); ?> />
            <?php esc_html_e( 'Story is LIVE', 'serve' ); ?>
        </label>
        <label>
            <?php esc_html_e( 'Badge Label:', 'serve' ); ?>
            <input type="text" name="flavor_live_label" value="<?php echo esc_attr( $live_label ); ?>" />
        </label>
        <label>
            <input type="checkbox" name="flavor_live_auto_insert" value="1" <?php checked( $auto_insert, '1' ); ?> />
            <?php esc_html_e( 'Auto-insert timeline before post content', 'serve' ); ?>
        </label>
    </div>

    <p class="description"><?php esc_html_e( 'Add timestamped updates for developing stories. Newest updates appear first. Use [flavor_live_updates] shortcode to place manually.', 'serve' ); ?></p>

    <div class="flavor-lu-entries" id="flavor-lu-entries">
        <?php foreach ( $updates as $i => $update ) : ?>
            <div class="flavor-lu-entry" data-index="<?php echo (int) $i; ?>">
                <button type="button" class="flavor-lu-remove" onclick="this.closest('.flavor-lu-entry').remove();">&times;</button>
                <div class="flavor-lu-row">
                    <div>
                        <label><?php esc_html_e( 'Timestamp', 'serve' ); ?></label>
                        <input type="datetime-local" name="flavor_lu[<?php echo (int) $i; ?>][time]" value="<?php echo esc_attr( $update['time'] ?? '' ); ?>" />
                    </div>
                    <div>
                        <label><?php esc_html_e( 'Status', 'serve' ); ?></label>
                        <select name="flavor_lu[<?php echo (int) $i; ?>][status]">
                            <option value="update" <?php selected( $update['status'] ?? '', 'update' ); ?>><?php esc_html_e( 'Update', 'serve' ); ?></option>
                            <option value="breaking" <?php selected( $update['status'] ?? '', 'breaking' ); ?>><?php esc_html_e( 'Breaking', 'serve' ); ?></option>
                            <option value="developing" <?php selected( $update['status'] ?? '', 'developing' ); ?>><?php esc_html_e( 'Developing', 'serve' ); ?></option>
                            <option value="confirmed" <?php selected( $update['status'] ?? '', 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'serve' ); ?></option>
                            <option value="correction" <?php selected( $update['status'] ?? '', 'correction' ); ?>><?php esc_html_e( 'Correction', 'serve' ); ?></option>
                            <option value="resolved" <?php selected( $update['status'] ?? '', 'resolved' ); ?>><?php esc_html_e( 'Resolved', 'serve' ); ?></option>
                        </select>
                    </div>
                    <div>
                        <label><?php esc_html_e( 'Author (optional)', 'serve' ); ?></label>
                        <input type="text" name="flavor_lu[<?php echo (int) $i; ?>][author]" value="<?php echo esc_attr( $update['author'] ?? '' ); ?>" />
                    </div>
                </div>
                <label><?php esc_html_e( 'Headline', 'serve' ); ?></label>
                <input type="text" name="flavor_lu[<?php echo (int) $i; ?>][headline]" value="<?php echo esc_attr( $update['headline'] ?? '' ); ?>" />
                <label><?php esc_html_e( 'Details (HTML allowed)', 'serve' ); ?></label>
                <textarea name="flavor_lu[<?php echo (int) $i; ?>][content]"><?php echo esc_textarea( $update['content'] ?? '' ); ?></textarea>
                <label><?php esc_html_e( 'Media URL (image/video — optional)', 'serve' ); ?></label>
                <input type="text" name="flavor_lu[<?php echo (int) $i; ?>][media]" value="<?php echo esc_url( $update['media'] ?? '' ); ?>" />
            </div>
        <?php endforeach; ?>
    </div>

    <button type="button" id="flavor-lu-add"><?php esc_html_e( '+ Add Update', 'serve' ); ?></button>

    <script>
    (function(){
        var idx = <?php echo count( $updates ); ?>;
        document.getElementById('flavor-lu-add').addEventListener('click', function(){
            var now = new Date();
            var local = now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0')+'T'+String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');
            var html = '<div class="flavor-lu-entry" data-index="'+idx+'">'
                +'<button type="button" class="flavor-lu-remove" onclick="this.closest(\'.flavor-lu-entry\').remove();">&times;</button>'
                +'<div class="flavor-lu-row">'
                +'<div><label>Timestamp</label><input type="datetime-local" name="flavor_lu['+idx+'][time]" value="'+local+'" /></div>'
                +'<div><label>Status</label><select name="flavor_lu['+idx+'][status]"><option value="update">Update</option><option value="breaking">Breaking</option><option value="developing">Developing</option><option value="confirmed">Confirmed</option><option value="correction">Correction</option><option value="resolved">Resolved</option></select></div>'
                +'<div><label>Author</label><input type="text" name="flavor_lu['+idx+'][author]" value="" /></div>'
                +'</div>'
                +'<label>Headline</label><input type="text" name="flavor_lu['+idx+'][headline]" value="" />'
                +'<label>Details</label><textarea name="flavor_lu['+idx+'][content]"></textarea>'
                +'<label>Media URL</label><input type="text" name="flavor_lu['+idx+'][media]" value="" />'
                +'</div>';
            document.getElementById('flavor-lu-entries').insertAdjacentHTML('beforeend', html);
            document.getElementById('flavor-lu-entries').lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            idx++;
        });
    })();
    </script>
    <?php
}

function flavor_live_updates_save( $post_id ) {
    if ( ! isset( $_POST['flavor_live_updates_nonce_field'] ) ||
         ! wp_verify_nonce( $_POST['flavor_live_updates_nonce_field'], 'flavor_live_updates_nonce' ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    update_post_meta( $post_id, '_flavor_is_live', isset( $_POST['flavor_is_live'] ) ? '1' : '0' );
    update_post_meta( $post_id, '_flavor_live_label', sanitize_text_field( $_POST['flavor_live_label'] ?? 'LIVE' ) );
    update_post_meta( $post_id, '_flavor_live_auto_insert', isset( $_POST['flavor_live_auto_insert'] ) ? '1' : '0' );

    $raw = $_POST['flavor_lu'] ?? [];
    $clean = [];
    if ( is_array( $raw ) ) {
        foreach ( $raw as $entry ) {
            if ( empty( $entry['headline'] ) && empty( $entry['content'] ) ) {
                continue;
            }
            $clean[] = array(
                'time'     => sanitize_text_field( $entry['time'] ?? '' ),
                'status'   => sanitize_text_field( $entry['status'] ?? 'update' ),
                'author'   => sanitize_text_field( $entry['author'] ?? '' ),
                'headline' => sanitize_text_field( $entry['headline'] ?? '' ),
                'content'  => wp_kses_post( $entry['content'] ?? '' ),
                'media'    => esc_url_raw( $entry['media'] ?? '' ),
            );
        }
        usort($clean, fn($a, $b) => strcmp( $b['time'], $a['time'] ));
    }
    update_post_meta( $post_id, '_flavor_live_updates', $clean );
}
add_action( 'save_post', 'flavor_live_updates_save' );

function flavor_render_live_timeline( ?int $post_id = null ) {
    if ( ! $post_id ) {
        $post_id = get_the_ID();
    }

    $updates    = get_post_meta( $post_id, '_flavor_live_updates', true );
    $is_live    = get_post_meta( $post_id, '_flavor_is_live', true );
    $live_label = get_post_meta( $post_id, '_flavor_live_label', true ) ?: 'LIVE';

    if ( empty( $updates ) || ! is_array( $updates ) ) {
        return '';
    }

    $status_colors = array(
        'update'     => 'var(--flavor-accent)',
        'breaking'   => '#D50000',
        'developing' => '#FF6D00',
        'confirmed'  => '#2E7D32',
        'correction' => '#6A1B9A',
        'resolved'   => '#546E7A',
    );

    ob_start();
    ?>
    <div class="flavor-live-timeline <?php echo $is_live === '1' ? 'is-live' : 'is-ended'; ?>">
        <div class="live-timeline-header">
            <?php if ( $is_live === '1' ) : ?>
                <span class="live-badge live-badge-pulse"><?php echo esc_html( $live_label ); ?></span>
            <?php else : ?>
                <span class="live-badge live-badge-ended"><?php esc_html_e( 'ENDED', 'serve' ); ?></span>
            <?php endif; ?>
            <span class="live-update-count">
                <?php printf( esc_html( _n( '%d update', '%d updates', count( $updates ), 'serve' ) ), count( $updates ) ); ?>
            </span>
        </div>

        <div class="live-timeline-entries">
            <?php foreach ( $updates as $update ) :
                $timestamp = $update['time'] ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $update['time'] ) ) : '';
                $status_color = $status_colors[ $update['status'] ] ?? 'var(--flavor-accent)';
            ?>
                <div class="live-entry" data-status="<?php echo esc_attr( $update['status'] ); ?>">
                    <div class="live-entry-marker">
                        <span class="live-dot" style="background:<?php echo esc_attr( $status_color ); ?>;"></span>
                        <span class="live-line"></span>
                    </div>
                    <div class="live-entry-content">
                        <div class="live-entry-meta">
                            <span class="live-entry-status" style="color:<?php echo esc_attr( $status_color ); ?>;">
                                <?php echo esc_html( ucfirst( $update['status'] ) ); ?>
                            </span>
                            <?php if ( $timestamp ) : ?>
                                <time class="live-entry-time"><?php echo esc_html( $timestamp ); ?></time>
                            <?php endif; ?>
                            <?php if ( ! empty( $update['author'] ) ) : ?>
                                <span class="live-entry-author"><?php echo esc_html( $update['author'] ); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ( ! empty( $update['headline'] ) ) : ?>
                            <h4 class="live-entry-headline"><?php echo esc_html( $update['headline'] ); ?></h4>
                        <?php endif; ?>
                        <?php if ( ! empty( $update['content'] ) ) : ?>
                            <div class="live-entry-body"><?php echo wp_kses_post( $update['content'] ); ?></div>
                        <?php endif; ?>
                        <?php
                        $entry_type = $update['type'] ?? 'update';
                        if ( $entry_type === 'pullquote' && ! empty( $update['pullquote'] ) ) : ?>
                            <blockquote class="live-entry-pullquote">
                                <p><?php echo esc_html( $update['pullquote'] ); ?></p>
                                <?php if ( ! empty( $update['author'] ) ) : ?>
                                    <cite><?php echo esc_html( $update['author'] ); ?></cite>
                                <?php endif; ?>
                            </blockquote>
                        <?php elseif ( $entry_type === 'source' && ! empty( $update['source'] ) ) : ?>
                            <div class="live-entry-source">📎 <?php echo wp_kses_post( $update['source'] ); ?></div>
                        <?php elseif ( ( $entry_type === 'photo' || $entry_type === 'video' || ! empty( $update['media'] ) ) ) : ?>
                            <div class="live-entry-media">
                                <?php
                                $ext = strtolower( pathinfo( $update['media'] ?? '', PATHINFO_EXTENSION ) );
                                if ( $entry_type === 'video' || in_array( $ext, [ 'mp4', 'webm', 'ogg' ], true ) ) {
                                    echo '<video controls preload="metadata" loading="lazy"><source src="' . esc_url( $update['media'] ) . '"></video>';
                                } elseif ( ! empty( $update['media'] ) ) {
                                    echo '<img src="' . esc_url( $update['media'] ) . '" alt="" loading="lazy" decoding="async" />';
                                }
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function flavor_live_updates_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'id' => get_the_ID() ), $atts, 'flavor_live_updates' );
    return flavor_render_live_timeline( (int) $atts['id'] );
}
add_shortcode( 'flavor_live_updates', 'flavor_live_updates_shortcode' );

function flavor_live_updates_auto_insert( $content ) {
    if ( ! is_singular( 'post' ) ) {
        return $content;
    }
    $auto = get_post_meta( get_the_ID(), '_flavor_live_auto_insert', true );
    if ( $auto !== '1' ) {
        return $content;
    }
    $timeline = flavor_render_live_timeline();
    if ( empty( $timeline ) ) {
        return $content;
    }
    return $timeline . $content;
}
add_filter( 'the_content', 'flavor_live_updates_auto_insert', 5 );

function flavor_live_badge_on_title( $title, $id = null ) {
    if ( ! $id || is_admin() || ! in_the_loop() ) {
        return $title;
    }
    $is_live = get_post_meta( $id, '_flavor_is_live', true );
    if ( $is_live === '1' ) {
        $label = get_post_meta( $id, '_flavor_live_label', true ) ?: 'LIVE';
        $title = '<span class="live-badge live-badge-inline live-badge-pulse">' . esc_html( $label ) . '</span> ' . $title;
    }
    return $title;
}
add_filter( 'the_title', 'flavor_live_badge_on_title', 10, 2 );

function flavor_live_timeline_styles() {
    if ( ! is_singular( 'post' ) ) return; // Only on single posts
    $css = '
/* Live Timeline */
.flavor-live-timeline{border:1px solid var(--flavor-border);margin:2em 0;background:var(--flavor-bg)}
.live-timeline-header{display:flex;align-items:center;gap:.75rem;padding:.75rem 1.25rem;border-bottom:1px solid var(--flavor-border);background:var(--flavor-bg-alt)}
.live-badge{display:inline-flex;align-items:center;gap:.35rem;padding:.15rem .6rem;font-family:var(--flavor-font-ui);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;border-radius:2px;line-height:1.4}
.live-badge-pulse{background:var(--flavor-accent);color:#fff}
.live-badge-pulse::before{content:"";width:6px;height:6px;border-radius:50%;background:#fff;animation:flavor-pulse 1.5s ease-in-out infinite}
@keyframes flavor-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(1.3)}}
.live-badge-ended{background:var(--flavor-text-light);color:#fff}
.live-badge-inline{font-size:9px;vertical-align:middle;margin-right:.25rem}
.live-update-count{font-family:var(--flavor-font-ui);font-size:var(--flavor-size-xs);color:var(--flavor-text-light)}
.live-timeline-entries{padding:1.25rem}
.live-entry{display:flex;gap:1rem;margin-bottom:0}
.live-entry-marker{display:flex;flex-direction:column;align-items:center;width:16px;flex-shrink:0}
.live-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:4px}
.live-line{width:2px;flex:1;background:var(--flavor-border);margin:4px 0}
.live-entry:last-child .live-line{display:none}
.live-entry-content{flex:1;padding-bottom:1.5rem}
.live-entry:last-child .live-entry-content{padding-bottom:0}
.live-entry-meta{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin-bottom:.35rem;font-family:var(--flavor-font-ui);font-size:var(--flavor-size-xs)}
.live-entry-status{font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.live-entry-time{color:var(--flavor-text-light)}
.live-entry-author{color:var(--flavor-text-secondary);font-weight:600}
.live-entry-author::before{content:"— "}
.live-entry-headline{font-family:var(--flavor-font-headline);font-size:var(--flavor-size-md);margin:0 0 .35rem;line-height:var(--flavor-line-height-snug)}
.live-entry-body{font-size:var(--flavor-size-sm);line-height:var(--flavor-line-height-normal);color:var(--flavor-text-secondary)}
.live-entry-body p{margin:0 0 .5em}
.live-entry-media{margin-top:.75rem}
.live-entry-media img,.live-entry-media video{max-width:100%;height:auto;border:1px solid var(--flavor-border)}
.live-entry-pullquote{margin:.75rem 0;padding:.75rem 1rem;border-left:3px solid var(--flavor-accent,#c62828);background:var(--flavor-bg-alt,#f9fafb);font-style:italic;font-size:var(--flavor-size-base)}
.live-entry-pullquote p{margin:0 0 .25rem;font-size:1.05em;line-height:1.5}
.live-entry-pullquote cite{font-size:.85em;font-style:normal;color:var(--flavor-text-muted,#6b7280)}
.live-entry-source{margin-top:.5rem;font-size:.8rem;color:var(--flavor-text-muted,#6b7280);padding:.4rem .6rem;background:var(--flavor-bg-alt,#f9fafb);border-radius:4px;border:1px solid var(--flavor-border,#e5e7eb)}
@media(max-width:768px){
    .live-timeline-entries{padding:.75rem}
    .live-entry-headline{font-size:var(--flavor-size-base)}
}
';
    serve_add_consolidated_css( 'live-updates-1', $css );
}
add_action( 'wp_enqueue_scripts', 'flavor_live_timeline_styles', 20 );

function flavor_live_updates_block_pattern() {
    register_block_pattern( 'flavor/live-updates-timeline', array(
        'title'       => esc_html__( 'Live Updates Timeline', 'serve' ),
        'description' => esc_html__( 'Embed the live updates timeline for the current post.', 'serve' ),
        'categories'  => [ 'serve' ],
        'content'     => '<!-- wp:shortcode -->[flavor_live_updates]<!-- /wp:shortcode -->',
    ) );
}
add_action( 'init', 'flavor_live_updates_block_pattern' );
