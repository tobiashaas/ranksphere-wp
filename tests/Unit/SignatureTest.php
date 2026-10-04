<?php
/**
 * Request signatures.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RankSphere\Security\Signature;

/**
 * A request counts only with the right secret, unchanged and fresh.
 */
final class SignatureTest extends TestCase {

	private const SECRET = 'a-secret-that-is-long-enough-for-hmac-sha256';

	public function test_a_signed_request_is_accepted(): void {
		$signature = new Signature( self::SECRET );
		$sent      = $signature->sign( 1_000_000, 'post', '/wp-json/ranksphere/v1/drafts', '{"title":"Hallo"}' );

		self::assertTrue( $signature->verify( $sent, 1_000_000, 'POST', '/wp-json/ranksphere/v1/drafts', '{"title":"Hallo"}', 1_000_060 ) );
	}

	public function test_a_changed_body_path_or_secret_is_rejected(): void {
		$signature = new Signature( self::SECRET );
		$sent      = $signature->sign( 1_000_000, 'POST', '/wp-json/ranksphere/v1/drafts', '{"title":"Hallo"}' );

		self::assertFalse( $signature->verify( $sent, 1_000_000, 'POST', '/wp-json/ranksphere/v1/drafts', '{"title":"Hallo!"}', 1_000_000 ) );
		self::assertFalse( $signature->verify( $sent, 1_000_000, 'POST', '/wp-json/ranksphere/v1/other', '{"title":"Hallo"}', 1_000_000 ) );
		self::assertFalse( ( new Signature( str_repeat( 'x', 32 ) ) )->verify( $sent, 1_000_000, 'POST', '/wp-json/ranksphere/v1/drafts', '{"title":"Hallo"}', 1_000_000 ) );
	}

	public function test_an_old_request_cannot_be_replayed(): void {
		$signature = new Signature( self::SECRET );
		$sent      = $signature->sign( 1_000_000, 'POST', '/x', '' );

		self::assertTrue( $signature->verify( $sent, 1_000_000, 'POST', '/x', '', 1_000_000 + Signature::TOLERANCE ) );
		self::assertFalse( $signature->verify( $sent, 1_000_000, 'POST', '/x', '', 1_000_001 + Signature::TOLERANCE ) );
	}

	public function test_the_query_is_signed_in_sorted_order(): void {
		self::assertSame( '/ranksphere/v1/status', Signature::canonical_path( '/ranksphere/v1/status', array() ) );
		self::assertSame(
			'/ranksphere/v1/status?a=1&b=x%20y',
			Signature::canonical_path(
				'/ranksphere/v1/status',
				array(
					'b' => 'x y',
					'a' => '1',
				)
			)
		);
	}

	/**
	 * The same vector is in RankSphere's tests (WordPressSignatureTest) – both sides must agree.
	 */
	public function test_the_shared_test_vector(): void {
		$signature = new Signature( self::SECRET );
		$path      = Signature::canonical_path(
			'/ranksphere/v1/status',
			array(
				'b' => 'x y',
				'a' => '1',
			)
		);

		self::assertSame( '1385b09a84e589be88f16929e87e77f4be98f4f4157112516b2495e6aed27be6', $signature->sign( 1_700_000_000, 'GET', $path, '' ) );
	}

	public function test_a_short_secret_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Signature( 'too-short' );
	}
}
