<?php
/**
 * Bootstrap for the integration tests: loads WordPress' test suite (wp-env provides it), then
 * the plugin – and, when RANKSPHERE_TEST_SEO_PLUGIN names one, an SEO plugin before it.
 *
 * @package RankSphere
 */

declare(strict_types=1);

$ranksphere_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $ranksphere_tests_dir || '' === $ranksphere_tests_dir ) {
	$ranksphere_tests_dir = '/wordpress-phpunit';
}

if ( ! is_file( $ranksphere_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Run the integration tests through wp-env: npm run test:integration\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- read by WordPress' test suite.

require_once $ranksphere_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$seo_plugin = getenv( 'RANKSPHERE_TEST_SEO_PLUGIN' );

		if ( is_string( $seo_plugin ) && '' !== $seo_plugin ) {
			require WP_PLUGIN_DIR . '/' . $seo_plugin;
		}

		require dirname( __DIR__ ) . '/ranksphere.php';
	}
);

require $ranksphere_tests_dir . '/includes/bootstrap.php';
