<?php
/**
 * Fires only when the site owner clicks Plugins -> Delete.
 *
 * @package Apollo\Serve
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$prefixes = [ 'apollo\_', 'pfs\_', 'scr\_', 'svh\_', 'sev\_', 'wos\_', 'serve\_', 'sah\_', 'art\_' ];
foreach ( $prefixes as $p ) {
	$wpdb->query( $wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$p . '%'
	) );
}

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_pfs\_%'
	    OR option_name LIKE '\_transient\_timeout\_pfs\_%'"
);

$hooks = [
	'apollo_elections_poll',
	'apollo_newsletter_send',
	'apollo_wos_daily_sync',
	'apollo_scr_payout_sweep',
	'apollo_cache_janitor',
];
foreach ( $hooks as $h ) {
	$ts = wp_next_scheduled( $h );
	while ( $ts ) {
		wp_unschedule_event( $ts, $h );
		$ts = wp_next_scheduled( $h );
	}
}
