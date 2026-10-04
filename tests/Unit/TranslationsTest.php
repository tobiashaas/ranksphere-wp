<?php
/**
 * The bundled German translation is complete.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every text of the plugin (`__()`, `esc_html_e()`, `_n()` … with the domain "ranksphere") has a
 * German translation, and the translation has nothing left over: a new text without German would
 * show up in English in a German admin.
 */
final class TranslationsTest extends TestCase {

	public function test_every_text_has_a_german_translation(): void {
		$messages = self::german();
		$texts    = self::texts();

		self::assertNotEmpty( $texts );
		self::assertSame( array(), array_values( array_diff( $texts, array_keys( $messages ) ) ), 'texts without German' );
		self::assertSame( array(), array_values( array_diff( array_keys( $messages ), $texts ) ), 'German for texts that are gone' );
	}

	public function test_placeholders_survive_the_translation(): void {
		foreach ( self::german() as $source => $german ) {
			foreach ( explode( "\0", $german ) as $form ) {
				preg_match_all( '/%(\d\$)?[sd%]/', explode( "\0", $source )[0], $expected );
				preg_match_all( '/%(\d\$)?[sd%]/', $form, $found );
				sort( $expected[0] );
				sort( $found[0] );
				self::assertSame( $expected[0], $found[0], $source );
			}
		}
	}

	/**
	 * The German messages.
	 *
	 * @return array<string, string>
	 */
	private static function german(): array {
		defined( 'ABSPATH' ) || define( 'ABSPATH', '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress' constant, the file checks it.
		$file = require dirname( __DIR__, 2 ) . '/languages/ranksphere-de_DE.l10n.php';
		self::assertIsArray( $file );
		self::assertIsArray( $file['messages'] );

		/**
		 * Source text => German.
		 *
		 * @var array<string, string>
		 */
		return $file['messages'];
	}

	/**
	 * Every translatable text in the plugin; plurals as "singular\0plural" like the translation file.
	 *
	 * @return list<string>
	 */
	private static function texts(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/ranksphere.php' );
		$found = array();

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		$quoted = "'((?:[^'\\\\]|\\\\.)*)'";

		foreach ( $files as $path ) {
			$code = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads source files.

			preg_match_all( '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*' . $quoted . '\s*,\s*\'ranksphere\'/', $code, $single );
			preg_match_all( '/\b_n\(\s*' . $quoted . '\s*,\s*' . $quoted . '\s*,[^;]*?\'ranksphere\'/s', $code, $plural );

			foreach ( $single[1] as $text ) {
				$found[] = stripslashes( $text );
			}

			foreach ( $plural[1] as $i => $text ) {
				$found[] = stripslashes( $text ) . "\0" . stripslashes( $plural[2][ $i ] );
			}
		}

		// The plugin header's description is translated by WordPress on the plugins screen.
		preg_match( '/^ \* Description:\s*(.+)$/m', (string) file_get_contents( $root . '/ranksphere.php' ), $description ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads source files.
		$found[] = trim( $description[1] ?? '' );

		return array_values( array_unique( $found ) );
	}
}
