<?php
/**
 * Release channels.
 *
 * @package RankSphere
 */

declare(strict_types=1);

namespace RankSphere\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RankSphere\Updates\Channel;

/**
 * Alpha < beta < release candidate < stable; a channel gets its stage and every more stable one.
 */
final class ChannelTest extends TestCase {

	public function test_the_stage_comes_from_the_version(): void {
		self::assertSame( Channel::ALPHA, Channel::of( '1.0.0-alpha.1' ) );
		self::assertSame( Channel::BETA, Channel::of( '1.0.0-beta.12' ) );
		self::assertSame( Channel::RC, Channel::of( '1.0.0-rc.1' ) );
		self::assertSame( Channel::STABLE, Channel::of( '1.0.0' ) );
	}

	public function test_a_channel_receives_its_stage_and_more_stable_ones(): void {
		self::assertTrue( Channel::accepts( Channel::ALPHA, '1.0.0-alpha.2' ) );
		self::assertTrue( Channel::accepts( Channel::ALPHA, '1.0.0' ) );
		self::assertTrue( Channel::accepts( Channel::BETA, '1.0.0-rc.1' ) );
		self::assertFalse( Channel::accepts( Channel::BETA, '1.0.0-alpha.3' ) );
		self::assertFalse( Channel::accepts( Channel::STABLE, '1.0.0-rc.1' ) );
		self::assertTrue( Channel::accepts( Channel::STABLE, '1.0.1' ) );
	}

	public function test_versions_are_ordered_as_wordpress_compares_them(): void {
		$ordered = array( '0.1.0', '1.0.0-alpha.1', '1.0.0-alpha.2', '1.0.0-alpha.10', '1.0.0-beta.1', '1.0.0-rc.1', '1.0.0', '1.0.1' );

		foreach ( array_slice( $ordered, 1 ) as $i => $newer ) {
			self::assertSame( -1, version_compare( $ordered[ $i ], $newer ), $ordered[ $i ] . ' < ' . $newer );
		}
	}

	public function test_unknown_channels_fall_back(): void {
		self::assertSame( Channel::BETA, Channel::sanitize( 'nightly', Channel::BETA ) );
		self::assertSame( Channel::RC, Channel::sanitize( 'rc', Channel::BETA ) );
		self::assertSame( Channel::STABLE, Channel::sanitize( null, Channel::STABLE ) );
	}
}
