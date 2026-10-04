<?php
/**
 * Cleaning SEO fields.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RankSphere\Seo\SeoFields;

/**
 * Only known fields, plain text, empty means "the SEO plugin's default", wrong types are refused.
 */
final class SeoFieldsTest extends TestCase {

	public function test_only_sent_fields_are_changes(): void {
		self::assertSame(
			array(
				'title'   => 'Brot vom Bäcker',
				'noindex' => false,
			),
			SeoFields::changes(
				array(
					'title'   => '  Brot   vom <b>Bäcker</b> ',
					'noindex' => false,
					'unknown' => 'x',
				)
			)
		);
	}

	public function test_empty_values_mean_the_default(): void {
		self::assertSame(
			array(
				'description'    => null,
				'focus_keywords' => null,
				'canonical'      => null,
			),
			SeoFields::changes(
				array(
					'description'    => '   ',
					'focus_keywords' => array( '', ' ' ),
					'canonical'      => '',
				)
			)
		);
	}

	public function test_keywords_are_cleaned_and_deduplicated(): void {
		self::assertSame( array( 'brot', 'torte' ), SeoFields::clean( 'focus_keywords', array( ' brot ', 'torte', 'brot' ) ) );
	}

	public function test_scripts_never_reach_a_title(): void {
		self::assertSame( 'Hallo', SeoFields::clean( 'title', '<script>alert(1)</script>Hallo' ) );
	}

	public function test_wrong_types_are_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		SeoFields::changes( array( 'noindex' => 'yes' ) );
	}

	public function test_canonical_must_be_a_web_address(): void {
		$this->expectException( \InvalidArgumentException::class );

		SeoFields::clean( 'canonical', 'javascript:alert(1)' );
	}
}
