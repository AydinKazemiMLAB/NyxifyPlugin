<?php
/**
 * Tests for the messenger platforms.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Bots;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Bots\BotPlatform;
use WooNyx\Tests\Unit\TestCase;

/**
 * Known values, form input, labels and bot links.
 */
final class BotPlatformTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
	}

	public function test_the_platforms_match_the_core(): void {
		$json = file_get_contents( dirname( __DIR__, 4 ) . '/docs/core-api/openapi.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		self::assertIsString( $json );

		$spec = json_decode( $json, true );
		self::assertIsArray( $spec );
		self::assertSame( $spec['components']['schemas']['MessengerPlatform']['enum'], BotPlatform::ALL );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function known_inputs(): array {
		return array(
			'telegram' => array( 'telegram' ),
			'bale'     => array( 'bale' ),
			'rubika'   => array( 'rubika' ),
		);
	}

	#[DataProvider( 'known_inputs' )]
	public function test_known_values( string $platform ): void {
		self::assertTrue( BotPlatform::is_known( $platform ) );
		self::assertSame( $platform, BotPlatform::from_input( $platform ) );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function unknown_inputs(): array {
		return array(
			'missing'     => array( null ),
			'not a value' => array( array( 'telegram' ) ),
			'number'      => array( 1 ),
			'empty'       => array( '' ),
			'upper case'  => array( 'Telegram' ),
			'spaces'      => array( ' telegram ' ),
			'other'       => array( 'whatsapp' ),
			'path'        => array( 'telegram/../admins' ),
		);
	}

	#[DataProvider( 'unknown_inputs' )]
	public function test_other_input_is_refused( mixed $raw ): void {
		self::assertNull( BotPlatform::from_input( $raw ) );
		self::assertFalse( BotPlatform::is_known( $raw ) );
	}

	public function test_slashed_input_is_unslashed(): void {
		self::assertSame( 'bale', BotPlatform::from_input( 'b\\ale' ) );
	}

	public function test_labels(): void {
		self::assertSame( 'Telegram', BotPlatform::label( 'telegram' ) );
		self::assertSame( 'Bale', BotPlatform::label( 'bale' ) );
		self::assertSame( 'Rubika', BotPlatform::label( 'rubika' ) );
		self::assertSame( 'eitaa', BotPlatform::label( 'eitaa' ) );
	}

	public function test_bot_links(): void {
		self::assertSame( 'https://t.me/woonyx_bot', BotPlatform::bot_url( 'telegram', 'woonyx_bot' ) );
		self::assertSame( 'https://t.me/woonyx_bot', BotPlatform::bot_url( 'telegram', '@woonyx_bot' ) );
		self::assertSame( 'https://ble.ir/WooNyxBot', BotPlatform::bot_url( 'bale', 'WooNyxBot' ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function no_link(): array {
		return array(
			'rubika'         => array( 'rubika', 'woonyx_bot' ),
			'unknown'        => array( 'eitaa', 'woonyx_bot' ),
			'empty username' => array( 'telegram', '' ),
			'only @'         => array( 'telegram', '@' ),
			'path'           => array( 'telegram', 'bot/../x' ),
			'query'          => array( 'bale', 'bot?x=1' ),
			'markup'         => array( 'telegram', '<b>bot</b>' ),
			'non-ASCII'      => array( 'bale', 'ربات' ),
			'too long'       => array( 'telegram', str_repeat( 'a', 65 ) ),
		);
	}

	#[DataProvider( 'no_link' )]
	public function test_no_link_when_unsafe_or_unsupported( string $platform, string $username ): void {
		self::assertNull( BotPlatform::bot_url( $platform, $username ) );
	}
}
