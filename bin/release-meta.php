<?php
/**
 * Prints what RankSphere needs to offer this release as an update (ranksphere.json, attached to the
 * GitHub release): version and the requirements WordPress checks before updating.
 *
 * @package RankSphere
 */

declare(strict_types=1);

$ranksphere_root   = dirname( __DIR__ );
$ranksphere_main   = (string) file_get_contents( $ranksphere_root . '/ranksphere.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ranksphere_readme = (string) file_get_contents( $ranksphere_root . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

/**
 * A "Name: value" header line.
 *
 * @param string $source File contents.
 * @param string $name   Header name.
 */
function ranksphere_header( string $source, string $name ): string {
	return 1 === preg_match( '/^[\s*]*' . preg_quote( $name, '/' ) . ':\s*(\S+)/m', $source, $match ) ? $match[1] : '';
}

echo json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script.
	array(
		'version'      => ranksphere_header( $ranksphere_main, 'Version' ),
		'requires'     => ranksphere_header( $ranksphere_main, 'Requires at least' ),
		'requires_php' => ranksphere_header( $ranksphere_main, 'Requires PHP' ),
		'tested'       => ranksphere_header( $ranksphere_readme, 'Tested up to' ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
