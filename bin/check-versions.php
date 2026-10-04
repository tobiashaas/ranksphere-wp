<?php
/**
 * Fails when the version differs between plugin header, VERSION constant and readme.txt
 * "Stable tag" – the three must move together (WordPress.org guideline 15).
 *
 * @package RankSphere
 */

declare(strict_types=1);

$ranksphere_root   = dirname( __DIR__ );
$ranksphere_main   = (string) file_get_contents( $ranksphere_root . '/ranksphere.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ranksphere_readme = (string) file_get_contents( $ranksphere_root . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $ranksphere_main, $ranksphere_header );
preg_match( "/const VERSION\s*=\s*'([^']+)'/", $ranksphere_main, $ranksphere_constant );
preg_match( '/^Stable tag:\s*(\S+)/m', $ranksphere_readme, $ranksphere_stable );

$ranksphere_versions = array(
	'plugin header'         => $ranksphere_header[1] ?? null,
	'VERSION constant'      => $ranksphere_constant[1] ?? null,
	'readme.txt Stable tag' => $ranksphere_stable[1] ?? null,
);

if ( in_array( null, $ranksphere_versions, true ) || count( array_unique( $ranksphere_versions ) ) !== 1 ) {
	fwrite( STDERR, "Versions differ:\n" . print_r( $ranksphere_versions, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.DevelopmentFunctions.error_log_print_r
	exit( 1 );
}

echo 'Version ' . $ranksphere_versions['plugin header'] . " everywhere.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script.
