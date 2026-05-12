<?php
/**
 * Penny AI Search Index
 *
 * Maintains a full-text search index table covering all content types:
 * posts, pages, videos (serve_video), podcast episodes (serve_podcast_ep).
 *
 * Indexed fields per row:
 *   post_id, post_type, title, seo_title, seo_description, seo_keywords,
 *   excerpt, content_preview (first 400 chars), url, thumbnail_url,
 *   category, post_date, updated_at
 *
 * AJAX handlers:
 *   serve_search_query    — live search, returns results + match reason
 *   serve_search_suggest  — recent/featured articles for empty state
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Table name helper.
// ---------------------------------------------------------------------------

function apollo_search_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'apollo_search_index';
}

// ---------------------------------------------------------------------------
// Create / upgrade the table on plugin activation or first use.
// ---------------------------------------------------------------------------

function apollo_search_create_table(): void {
	global $wpdb;
	$table    = apollo_search_table();
	$charset  = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS {$table} (
		id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		post_id      BIGINT(20) UNSIGNED NOT NULL,
		post_type    VARCHAR(50)  NOT NULL DEFAULT 'post',
		title        TEXT         NOT NULL,
		seo_title    TEXT         NOT NULL DEFAULT '',
		seo_desc     TEXT         NOT NULL DEFAULT '',
		seo_keywords TEXT         NOT NULL DEFAULT '',
		excerpt      TEXT         NOT NULL DEFAULT '',
		content      TEXT         NOT NULL DEFAULT '',
		url          TEXT         NOT NULL DEFAULT '',
		thumbnail    TEXT         NOT NULL DEFAULT '',
		category     VARCHAR(255) NOT NULL DEFAULT '',
		post_date    DATETIME     NOT NULL DEFAULT '0000-00-00 00:00:00',
		updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY   post_id (post_id),
		KEY          post_type (post_type),
		KEY          post_date (post_date),
		FULLTEXT KEY ft_main (title, seo_title, seo_desc, seo_keywords, excerpt, content)
	) ENGINE=InnoDB {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
add_action( 'init', function(): void {
	if ( get_option( 'apollo_search_index_ver' ) !== '1.1' ) {
		apollo_search_create_table();
		update_option( 'apollo_search_index_ver', '1.1' );
	}
}, 5 );

// ---------------------------------------------------------------------------
// Index a single post/CPT when saved or trashed.
// ---------------------------------------------------------------------------

function apollo_search_index_post( int $post_id ): void {
	global $wpdb;

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;

	$post = get_post( $post_id );
	if ( ! $post ) return;

	$allowed_types = [ 'post', 'page', 'serve_video', 'serve_podcast_ep' ];
	if ( ! in_array( $post->post_type, $allowed_types, true ) ) return;

	// Remove from index if not published.
	if ( $post->post_status !== 'publish' ) {
		$wpdb->delete( apollo_search_table(), [ 'post_id' => $post_id ], [ '%d' ] );
		return;
	}

	$table = apollo_search_table();

	// Content preview — strip shortcodes, HTML, collapse whitespace.
	$raw_content = strip_shortcodes( $post->post_content );
	$raw_content = wp_strip_all_tags( $raw_content );
	$raw_content = preg_replace( '/\s+/', ' ', trim( $raw_content ) );
	$content     = mb_substr( $raw_content, 0, 400 );

	// Excerpt.
	$excerpt = $post->post_excerpt
		? wp_strip_all_tags( $post->post_excerpt )
		: wp_trim_words( $raw_content, 30 );

	// Thumbnail URL.
	$thumb_id  = get_post_thumbnail_id( $post_id );
	$thumb_url = $thumb_id ? ( wp_get_attachment_image_url( $thumb_id, 'medium' ) ?: '' ) : '';

	// Category.
	$cats = get_the_category( $post_id );
	$cat  = '';
	foreach ( $cats as $c ) {
		if ( $c->name !== 'Uncategorized' ) { $cat = $c->name; break; }
	}
	if ( ! $cat ) {
		// Podcast shows / video categories.
		$terms = get_the_terms( $post_id, 'serve_video_category' )
		      ?: get_the_terms( $post_id, 'serve_podcast_category' );
		if ( $terms && ! is_wp_error( $terms ) ) $cat = $terms[0]->name;
	}

	// SEO meta.
	$seo_title    = (string) get_post_meta( $post_id, 'serve_seo_title', true );
	$seo_desc     = (string) get_post_meta( $post_id, 'serve_seo_description', true );
	$seo_keywords = (string) get_post_meta( $post_id, 'serve_seo_keywords', true );
	// serve_seo_keywords is sometimes an array (tags array).
	if ( is_array( get_post_meta( $post_id, 'serve_seo_keywords', true ) ) ) {
		$seo_keywords = implode( ', ', array_map( 'sanitize_text_field', (array) get_post_meta( $post_id, 'serve_seo_keywords', true ) ) );
	}

	$row = [
		'post_id'      => $post_id,
		'post_type'    => $post->post_type,
		'title'        => wp_strip_all_tags( $post->post_title ),
		'seo_title'    => $seo_title,
		'seo_desc'     => $seo_desc,
		'seo_keywords' => $seo_keywords,
		'excerpt'      => $excerpt,
		'content'      => $content,
		'url'          => get_permalink( $post_id ),
		'thumbnail'    => $thumb_url,
		'category'     => $cat,
		'post_date'    => $post->post_date,
	];

	$formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ];

	// Upsert.
	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d", $post_id ) );
	if ( $existing ) {
		$wpdb->update( $table, $row, [ 'post_id' => $post_id ], $formats, [ '%d' ] );
	} else {
		$wpdb->insert( $table, $row, $formats );
	}
}

add_action( 'save_post',   'apollo_search_index_post', 20 );
add_action( 'post_updated', function( $id ) { apollo_search_index_post( $id ); }, 20 );
add_action( 'trashed_post', function( $id ) {
	global $wpdb;
	$wpdb->delete( apollo_search_table(), [ 'post_id' => $id ], [ '%d' ] );
} );

// ---------------------------------------------------------------------------
// Bulk re-index (runs as background WP-Cron job, also callable from admin).
// ---------------------------------------------------------------------------

function apollo_search_bulk_index( int $batch = 200, int $offset = 0 ): int {
	$posts = get_posts( [
		'post_type'   => [ 'post', 'page', 'serve_video', 'serve_podcast_ep' ],
		'post_status' => 'publish',
		'numberposts' => $batch,
		'offset'      => $offset,
		'fields'      => 'ids',
	] );
	foreach ( $posts as $id ) {
		apollo_search_index_post( $id );
	}
	return count( $posts );
}

// Admin AJAX: trigger full re-index.
add_action( 'wp_ajax_apollo_reindex_search', function(): void {
	check_ajax_referer( 'apollo_reindex', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die();
	$count = apollo_search_bulk_index( 500, 0 );
	wp_send_json_success( [ 'indexed' => $count ] );
} );

// One-time background index on first activation.
add_action( 'init', function(): void {
	if ( ! get_option( 'apollo_search_initial_indexed' ) ) {
		// Schedule to run 30 seconds after this request.
		if ( ! wp_next_scheduled( 'apollo_search_bulk_index_event' ) ) {
			wp_schedule_single_event( time() + 30, 'apollo_search_bulk_index_event' );
		}
		update_option( 'apollo_search_initial_indexed', 1 );
	}
} );
add_action( 'apollo_search_bulk_index_event', function(): void {
	apollo_search_bulk_index( 1000, 0 );
} );

// ---------------------------------------------------------------------------
// AJAX: live search query.
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_nopriv_apollo_search_query', 'apollo_search_handle_query' );
add_action( 'wp_ajax_apollo_search_query',         'apollo_search_handle_query' );

function apollo_search_handle_query(): void {
	// Light rate-limiting: no nonce needed for read-only public search.
	$query = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
	if ( strlen( $query ) < 2 || strlen( $query ) > 200 ) {
		wp_send_json_success( [] );
	}

	global $wpdb;
	$table = apollo_search_table();
	$limit = max( 4, min( 20, absint( $_GET['limit'] ?? 8 ) ) );

	// Build weighted LIKE search across all indexed fields.
	// Weight: title > seo_title > seo_keywords > seo_desc > excerpt > content
	$like = '%' . $wpdb->esc_like( $query ) . '%';

	$sql = $wpdb->prepare(
		"SELECT post_id, post_type, title, seo_title, seo_desc, seo_keywords,
		        excerpt, content, url, thumbnail, category, post_date,
		        (
		            (CASE WHEN title        LIKE %s THEN 100 ELSE 0 END) +
		            (CASE WHEN seo_title    LIKE %s THEN 80  ELSE 0 END) +
		            (CASE WHEN seo_keywords LIKE %s THEN 60  ELSE 0 END) +
		            (CASE WHEN seo_desc     LIKE %s THEN 40  ELSE 0 END) +
		            (CASE WHEN excerpt      LIKE %s THEN 30  ELSE 0 END) +
		            (CASE WHEN content      LIKE %s THEN 20  ELSE 0 END)
		        ) AS score
		 FROM {$table}
		 WHERE title LIKE %s
		    OR seo_title    LIKE %s
		    OR seo_keywords LIKE %s
		    OR seo_desc     LIKE %s
		    OR excerpt      LIKE %s
		    OR content      LIKE %s
		 ORDER BY score DESC, post_date DESC
		 LIMIT %d",
		$like, $like, $like, $like, $like, $like,   // score CASEs
		$like, $like, $like, $like, $like, $like,   // WHERE
		$limit
	);

	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( ! $rows ) {
		wp_send_json_success( [] );
	}

	$q_lower = strtolower( $query );
	$results = [];
	foreach ( $rows as $row ) {
		// Determine match reason(s).
		$reasons = [];
		if ( str_contains( strtolower( $row['title'] ),        $q_lower ) ) $reasons[] = 'title';
		if ( str_contains( strtolower( $row['seo_title'] ),    $q_lower ) ) $reasons[] = 'seo_title';
		if ( str_contains( strtolower( $row['seo_keywords'] ), $q_lower ) ) $reasons[] = 'keyword';
		if ( str_contains( strtolower( $row['seo_desc'] ),     $q_lower ) ) $reasons[] = 'description';
		if ( str_contains( strtolower( $row['excerpt'] ),      $q_lower ) ) $reasons[] = 'excerpt';
		if ( str_contains( strtolower( $row['content'] ),      $q_lower ) ) $reasons[] = 'content';

		// Snippet: first part of content/excerpt that contains the query.
		$snippet = apollo_search_snippet( $row['excerpt'] ?: $row['content'], $query, 160 );

		// Post type label.
		$type_label = match ( $row['post_type'] ) {
			'serve_video'      => 'Video',
			'serve_podcast_ep' => 'Podcast',
			'page'             => 'Page',
			default            => $row['category'] ?: 'Article',
		};

		$results[] = [
			'post_id'    => (int) $row['post_id'],
			'type'       => $row['post_type'],
			'type_label' => $type_label,
			'title'      => $row['seo_title'] ?: $row['title'],
			'raw_title'  => $row['title'],
			'url'        => $row['url'],
			'thumbnail'  => $row['thumbnail'],
			'category'   => $row['category'],
			'date'       => mysql2date( 'M j, Y', $row['post_date'] ),
			'snippet'    => $snippet,
			'reasons'    => $reasons,
			'score'      => (int) $row['score'],
		];
	}

	// Cache for 60 seconds via transient (key includes query + limit).
	$cache_key = 'apollo_srch_' . md5( $query . '|' . $limit );
	set_transient( $cache_key, $results, 60 );

	wp_send_json_success( $results );
}

// ---------------------------------------------------------------------------
// AJAX: suggestions (recent/popular content for empty state).
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_nopriv_apollo_search_suggest', 'apollo_search_handle_suggest' );
add_action( 'wp_ajax_apollo_search_suggest',         'apollo_search_handle_suggest' );

function apollo_search_handle_suggest(): void {
	$limit = max( 4, min( 12, absint( $_GET['limit'] ?? 6 ) ) );

	// Try transient cache first (5 minutes).
	$cache_key = 'apollo_suggest_' . $limit;
	$cached    = get_transient( $cache_key );
	if ( $cached ) { wp_send_json_success( $cached ); return; }

	global $wpdb;
	$table = apollo_search_table();

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id, post_type, title, seo_title, seo_desc, url, thumbnail, category, post_date
		 FROM {$table}
		 WHERE post_type = 'post'
		 ORDER BY post_date DESC
		 LIMIT %d",
		$limit
	), ARRAY_A );

	$results = array_map( function( $row ) {
		return [
			'post_id'    => (int) $row['post_id'],
			'type'       => $row['post_type'],
			'type_label' => $row['category'] ?: 'Article',
			'title'      => $row['seo_title'] ?: $row['title'],
			'url'        => $row['url'],
			'thumbnail'  => $row['thumbnail'],
			'category'   => $row['category'],
			'date'       => mysql2date( 'M j, Y', $row['post_date'] ),
			'reasons'    => [],
			'snippet'    => wp_trim_words( $row['seo_desc'] ?: '', 18 ),
		];
	}, $rows ?: [] );

	set_transient( $cache_key, $results, 300 );
	wp_send_json_success( $results );
}

// ---------------------------------------------------------------------------
// Helper: extract a contextual snippet around the query term.
// ---------------------------------------------------------------------------

function apollo_search_snippet( string $text, string $query, int $max_chars = 160 ): string {
	$text   = wp_strip_all_tags( $text );
	$text   = preg_replace( '/\s+/', ' ', trim( $text ) );
	$pos    = stripos( $text, $query );

	if ( $pos === false ) {
		return mb_substr( $text, 0, $max_chars ) . ( mb_strlen( $text ) > $max_chars ? '…' : '' );
	}

	$start  = max( 0, $pos - 60 );
	$length = $max_chars;
	$chunk  = mb_substr( $text, $start, $length );

	if ( $start > 0 )                              $chunk = '…' . $chunk;
	if ( $start + $length < mb_strlen( $text ) )   $chunk .= '…';

	return $chunk;
}

// ---------------------------------------------------------------------------
// Enqueue localised data so JS knows the AJAX URL + nonces.
// ---------------------------------------------------------------------------

add_action( 'wp_enqueue_scripts', function(): void {
	wp_localize_script( 'serve-search-trigger', 'apolloSearch', [
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'homeUrl'  => home_url( '/' ),
		'siteName' => get_bloginfo( 'name' ),
	] );
}, 25 );

// ---------------------------------------------------------------------------
// Admin re-index button in AI Settings page (shown as a small note).
// ---------------------------------------------------------------------------

add_action( 'serve_ai_settings_after_form', function(): void {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$nonce = wp_create_nonce( 'apollo_reindex' );
	$ajax  = admin_url( 'admin-ajax.php' );
	?>
	<div style="margin-top:20px;max-width:700px;padding:14px 18px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
		<strong style="font-size:13px;">Search Index</strong>
		<p style="font-size:12px;color:#6b7280;margin:.35rem 0 .75rem;">
			The search index is updated automatically when posts are published or updated.
			If search results seem stale, click below to rebuild the full index now.
		</p>
		<button id="apollo-reindex-btn" type="button"
			style="padding:6px 16px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;border:none;background:#1d4ed8;color:#fff;">
			Rebuild Search Index
		</button>
		<span id="apollo-reindex-status" style="margin-left:12px;font-size:12px;color:#6b7280;"></span>
	</div>
	<script>
	document.getElementById('apollo-reindex-btn').addEventListener('click',function(){
		var btn=this,status=document.getElementById('apollo-reindex-status');
		btn.disabled=true;status.textContent='Indexing…';
		var fd=new FormData();fd.append('action','apollo_reindex_search');fd.append('nonce','<?php echo esc_js( $nonce ); ?>');
		fetch('<?php echo esc_url( $ajax ); ?>',{method:'POST',credentials:'same-origin',body:fd})
		.then(function(r){return r.json();})
		.then(function(d){
			status.textContent=d.success?'Done — '+d.data.indexed+' posts indexed.':'Error rebuilding index.';
			btn.disabled=false;
		}).catch(function(){status.textContent='Error.';btn.disabled=false;});
	});
	</script>
	<?php
} );
