<?php
/**
 * PHPUnit bootstrap — uses WP_Mock for fast unit tests that don't load WP.
 */
require_once __DIR__ . '/../vendor/autoload.php';

WP_Mock::bootstrap();

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}
if ( ! defined( 'APOLLO_PLUGIN_VERSION' ) ) {
	define( 'APOLLO_PLUGIN_VERSION', '1.0.0-test' );
}
if ( ! defined( 'APOLLO_PLUGIN_PATH' ) ) {
	define( 'APOLLO_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
}
