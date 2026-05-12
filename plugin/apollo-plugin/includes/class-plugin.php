<?php
/**
 * Main plugin orchestrator — Vicinity Apollo v3.0
 *
 * @package Apollo\Serve
 */

namespace Apollo\Serve;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private const BOOT_MANIFEST = [
		'security/bugfixes.php',
		'security/class-nonce-guard.php',
		'security/class-rate-limiter.php',
		'security/class-input.php',
		'security/class-rest-audit.php',
		'includes/helpers.php',
		'includes/perf-config.php',
		'includes/cron-aliases.php',
		'modules/storage/s3-core.php',
		'modules/admin-ui/cloudflare-settings.php',
		'modules/storage/s3-sync.php',
		'modules/storage/s3-rename.php',
		'modules/storage/media-library.php',
		'includes/cache-control.php',
		'includes/cdn-edge.php',
		'includes/serve-future.php',
		'modules/roles/custom-roles.php',
		'modules/video/video-hub.php',
		'modules/audio/audio-hub.php',
		'modules/elections/election-hub.php',
		'modules/elections/election-apis.php',
		'modules/editorial/article-styles.php',
		'modules/editorial/breaking-news.php',
		'modules/editorial/live-blog.php',
		'modules/editorial/editorial-workflow.php',
		'modules/editorial/seo-structured.php',
		'modules/editorial/author-extended.php',
		'modules/editorial/assignment-desk.php',
		'modules/content/related-content.php',
		'modules/content/corrections.php',
		'modules/content/media-rights.php',
		'modules/content/content-warnings.php',
		'modules/content/ai-disclosure.php',
		'modules/content/fact-check.php',
		'modules/content/social-sharing.php',
		'modules/content/syndication.php',
		'modules/public-safety/public-safety.php',
		'modules/tips/tip-submission.php',
		'modules/documents/document-library.php',
		'modules/trust/trust-center.php',
		'modules/accessibility/accessibility.php',
		'modules/video/yt-live.php',
		'modules/video/live-updates.php',
		'modules/audio/live-radio.php',
		'modules/comments/comments-hub.php',
		'modules/editorial/editor-pro.php',
		'modules/editorial/author-profile.php',
		'modules/ai/claude-ai.php',
		'modules/ai/takeaways.php',
		'modules/search/search-index.php',
		'modules/newsletter/newsletter.php',
		'modules/subscriptions/frontend-subscriptions.php',
		'modules/analytics/analytics.php',
		'modules/sensitive-content/sensitive-content.php',
		'modules/ads/ad-manager.php',
		'modules/sponsored/sponsored.php',
		'modules/ethical-ai/ethical-ai-badge.php',
		'integrations/integrations.php',
		'integrations/serve-image.php',
		'integrations/serve-image-tools.php',
		'modules/bridge-register.php',
		'modules/admin-ui/customizer-app.php',
	];

	private const ADMIN_MANIFEST = [
		'modules/admin-ui/admin-theme.php',
		'modules/admin-ui/penny-admin-skin.php',
	];

	public static function boot(): void {
		self::load( self::BOOT_MANIFEST );
		if ( is_admin() ) {
			self::load( self::ADMIN_MANIFEST );
		}
		do_action( 'apollo_plugin_loaded' );
	}

	private static function load( array $manifest ): void {
		foreach ( $manifest as $rel ) {
			$path = APOLLO_PLUGIN_PATH . $rel;
			if ( is_readable( $path ) ) {
				require_once $path;
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Apollo] Missing module: ' . $rel );
			}
		}
	}

	public static function has_module( string $slug ): bool {
		return (bool) apply_filters( 'apollo_plugin_has_module', true, $slug );
	}
}
