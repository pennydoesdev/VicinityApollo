<?php
/**
 * Activation / deactivation handlers.
 *
 * @package Apollo\Serve
 */

namespace Apollo\Serve;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		self::create_tables();
		self::seed_options();
		self::schedule_cron();
		flush_rewrite_rules( false );
	}

	public static function deactivate(): void {
		self::unschedule_cron();
		self::clear_transients();
		flush_rewrite_rules( false );
	}

	private static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$prefix = $wpdb->prefix;

		dbDelta( "CREATE TABLE IF NOT EXISTS {$prefix}scr_earnings (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(32) NOT NULL,
			cents INT NOT NULL DEFAULT 0,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			KEY user_id (user_id),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) $charset;" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$prefix}scr_view_log (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NOT NULL,
			ip_hash CHAR(64) NOT NULL,
			ua_hash CHAR(64) NOT NULL,
			event_type VARCHAR(16) NOT NULL,
			seconds INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			KEY post_id (post_id),
			KEY ip_hash (ip_hash),
			KEY created_at (created_at)
		) $charset;" );
	}

	private static function seed_options(): void {
		$defaults = [
			'apollo_plugin_enabled_modules'   => [ 'all' ],
			'apollo_plugin_rate_limit_rpm'    => 30,
			'apollo_plugin_rate_limit_window' => 60,
		];
		foreach ( $defaults as $k => $v ) {
			if ( false === get_option( $k, false ) ) {
				add_option( $k, $v, '', false );
			}
		}
	}

	private static function schedule_cron(): void {
		$jobs = [
			'apollo_elections_poll'   => 'ap_elections_2min',
			'apollo_newsletter_send'  => 'hourly',
			'apollo_wos_daily_sync'   => 'daily',
			'apollo_scr_payout_sweep' => 'daily',
			'apollo_cache_janitor'    => 'twicedaily',
		];
		foreach ( $jobs as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 60, $recurrence, $hook );
			}
		}
	}

	private static function unschedule_cron(): void {
		$hooks = [ 'apollo_elections_poll', 'apollo_newsletter_send', 'apollo_wos_daily_sync', 'apollo_scr_payout_sweep', 'apollo_cache_janitor' ];
		foreach ( $hooks as $hook ) {
			$ts = wp_next_scheduled( $hook );
			while ( $ts ) { wp_unschedule_event( $ts, $hook ); $ts = wp_next_scheduled( $hook ); }
		}
	}

	private static function clear_transients(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_pfs\_%' OR option_name LIKE '\_transient\_timeout\_pfs\_%'" );
	}
}

add_filter( 'cron_schedules', static function ( $s ) {
	$s['ap_elections_2min'] = [ 'interval' => 120, 'display' => 'Every 2 minutes (AP Elections)' ];
	return $s;
} );
