<?php
/**
 * Tests for a bot of the core's answer.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Bots;

use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Bots\Bot;
use Nyxify\Tests\Unit\TestCase;

/**
 * Every `BotView` field is checked.
 */
final class BotTest extends TestCase {

	/**
	 * A complete `BotView`.
	 *
	 * @return array<string, mixed>
	 */
	private static function view(): array {
		return array(
			'platform'       => 'telegram',
			'username'       => 'nyxify_shop_bot',
			'name'           => 'Nyxify Shop',
			'status'         => 'active',
			'webhook_active' => true,
			'last_error'     => null,
			'deployed_at'    => '2026-10-07T10:15:00',
		);
	}

	public function test_the_fields_are_read(): void {
		$bot = Bot::from_response( self::view() );

		self::assertNotNull( $bot );
		self::assertSame( 'telegram', $bot->platform );
		self::assertSame( 'nyxify_shop_bot', $bot->username );
		self::assertSame( 'Nyxify Shop', $bot->name );
		self::assertSame( Bot::ACTIVE, $bot->status );
		self::assertTrue( $bot->webhook_active );
		self::assertNull( $bot->last_error );
		self::assertSame( '2026-10-07T10:15:00', $bot->deployed_at );
	}

	public function test_a_failed_bot_keeps_its_error_and_nulls(): void {
		$bot = Bot::from_response(
			array(
				'username'       => null,
				'name'           => null,
				'status'         => 'error',
				'webhook_active' => false,
				'last_error'     => 'network error (ConnectError)',
				'deployed_at'    => null,
			) + self::view()
		);

		self::assertNotNull( $bot );
		self::assertSame( Bot::ERROR, $bot->status );
		self::assertFalse( $bot->webhook_active );
		self::assertSame( 'network error (ConnectError)', $bot->last_error );
		self::assertNull( $bot->username );
		self::assertNull( $bot->name );
		self::assertNull( $bot->deployed_at );
	}

	public function test_values_added_by_the_core_later_are_kept(): void {
		$bot = Bot::from_response(
			array(
				'platform' => 'eitaa',
				'status'   => 'paused',
				'extra'    => 'ignored',
			) + self::view()
		);

		self::assertNotNull( $bot );
		self::assertSame( 'eitaa', $bot->platform );
		self::assertSame( 'paused', $bot->status );
	}

	/**
	 * @return array<string, array{string, mixed}>
	 */
	public static function malformed_fields(): array {
		return array(
			'platform missing'      => array( 'platform', null ),
			'platform not a string' => array( 'platform', 1 ),
			'platform empty'        => array( 'platform', '' ),
			'platform with a slash' => array( 'platform', 'telegram/x' ),
			'platform upper case'   => array( 'platform', 'Telegram' ),
			'status missing'        => array( 'status', null ),
			'status not a string'   => array( 'status', true ),
			'status with markup'    => array( 'status', '<b>active</b>' ),
			'webhook missing'       => array( 'webhook_active', null ),
			'webhook as a string'   => array( 'webhook_active', 'true' ),
			'webhook as a number'   => array( 'webhook_active', 1 ),
			'username not a string' => array( 'username', 42 ),
			'name not a string'     => array( 'name', array( 'x' ) ),
			'last error not string' => array( 'last_error', false ),
			'deployed not a string' => array( 'deployed_at', 1759832100 ),
		);
	}

	#[DataProvider( 'malformed_fields' )]
	public function test_a_malformed_field_is_refused( string $field, mixed $value ): void {
		$view           = self::view();
		$view[ $field ] = $value;

		self::assertNull( Bot::from_response( $view ) );
	}

	public function test_a_non_object_is_refused(): void {
		self::assertNull( Bot::from_response( null ) );
		self::assertNull( Bot::from_response( 'telegram' ) );
	}
}
