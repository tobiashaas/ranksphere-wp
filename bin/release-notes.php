<?php
/**
 * Prints the readme.txt changelog of one version as Markdown, for the GitHub release:
 * php bin/release-notes.php 1.0.0-alpha.1
 *
 * @package RankSphere
 */

declare(strict_types=1);

$ranksphere_version = $argv[1] ?? '';
$ranksphere_readme  = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ranksphere_pattern = '/^= ' . preg_quote( $ranksphere_version, '/' ) . ' =\s*\n(.*?)(?=^= |\z)/ms';

if ( '' === $ranksphere_version || 1 !== preg_match( $ranksphere_pattern, $ranksphere_readme, $ranksphere_match ) ) {
	fwrite( STDERR, "No changelog for version {$ranksphere_version} in readme.txt.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script.
	exit( 1 );
}

echo trim( $ranksphere_match[1] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script.
