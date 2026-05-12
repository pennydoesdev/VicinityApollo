<?php
/**
 * Cron hook aliasing layer.
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

defined( 'PFS_CRON_ALIASES' ) || define( 'PFS_CRON_ALIASES', [
	'apollo_elections_poll'   => [ 'sev_ap_poll', 'sev_elections_poll' ],
	'apollo_newsletter_send'  => [ 'serve_newsletter_send', 'sah_newsletter_send' ],
	'apollo_wos_daily_sync'   => [ 'wos_daily_sync' ],
	'apollo_scr_payout_sweep' => [ 'scr_payout_sweep', 'scr_daily_sweep' ],
	'apollo_cache_janitor'    => [ 'serve_cache_janitor', 'svh_daily_maintenance' ],
] );

foreach ( PFS_CRON_ALIASES as $new => $olds ) {
	add_action( $new, static function () use ( $olds ) {
		foreach ( $olds as $old ) {
			if ( has_action( $old ) ) {
				do_action( $old );
			}
		}
	}, 5 );
}

add_action( 'init', static function () {
	$stamp = get_option( 'apollo_cron_migrated_for', '' );
	if ( $stamp === APOLLO_PLUGIN_VERSION ) {
		return;
	}
	foreach ( PFS_CRON_ALIASES as $olds ) {
		foreach ( $olds as $old ) {
			$ts = wp_next_scheduled( $old );
			while ( $ts ) { wp_unschedule_event( $ts, $old ); $ts = wp_next_scheduled( $old ); }
		}
	}
	update_option( 'apollo_cron_migrated_for', APOLLO_PLUGIN_VERSION, false );
}, 99 );
