<?php
/**
 * Tests for a bot admin of the core's answer.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Bots;

use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Bots\BotAdmin;
use WooNyx\Tests\Unit\TestCase;

/**
 * Every `AdminView` field is checked and the personal data stays out of debug output.
 */
final class BotAdminTest extends TestCase {

	/**
	 * A complete `AdminView` (made-up person).
	 *
	 * @return array<string, mixed>
	 */
	private static function view(): array {
		return array(
			'id'                 => 7,
			'messenger_platform' => 'telegram',
			'messenger_user_id'  => '987654321',
			'first_name'         => 'Sara',
			'last_name'          => 'Example',
			'username'           => 'sara_example',
		);
	}

	public function test_the_fields_are_read(): void {
		$admin = BotAdmin::from_response( self::view() );

		self::assertNotNull( $admin );
		self::assertSame( 7, $admin->id );
		self::assertSame( 'telegram', $admin->platform );
		self::assertSame( '987654321', $admin->messenger_user_id );
		self::assertSame( 'Sara', $admin->first_name );
		self::assertSame( 'Example', $admin->last_name );
		self::assertSame( 'sara_example', $admin->username );
		self::assertSame( 'Sara Example', $admin->full_name() );
	}

	public function test_names_may_be_missing(): void {
		$admin = BotAdmin::from_response(
			array(
				'first_name' => null,
				'last_name'  => null,
				'username'   => null,
			) + self::view()
		);

		self::assertNotNull( $admin );
		self::assertSame( '', $admin->full_name() );
		self::assertNull( $admin->username );
	}

	public function test_full_name_with_one_part(): void {
		$first = BotAdmin::from_response( array( 'last_name' => null ) + self::view() );
		$last  = BotAdmin::from_response( array( 'first_name' => '  ' ) + self::view() );

		self::assertNotNull( $first );
		self::assertNotNull( $last );
		self::assertSame( 'Sara', $first->full_name() );
		self::assertSame( 'Example', $last->full_name() );
	}

	public function test_a_rubika_id_is_kept_as_text(): void {
		$admin = BotAdmin::from_response(
			array(
				'messenger_platform' => 'rubika',
				'messenger_user_id'  => 'u0ABcd-12_x',
			) + self::view()
		);

		self::assertNotNull( $admin );
		self::assertSame( 'rubika', $admin->platform );
		self::assertSame( 'u0ABcd-12_x', $admin->messenger_user_id );
	}

	/**
	 * @return array<string, array{string, mixed}>
	 */
	public static function malformed_fields(): array {
		return array(
			'id missing'              => array( 'id', null ),
			'id as a string'          => array( 'id', '7' ),
			'id zero'                 => array( 'id', 0 ),
			'id negative'             => array( 'id', -3 ),
			'id as a float'           => array( 'id', 7.0 ),
			'platform missing'        => array( 'messenger_platform', null ),
			'platform upper case'     => array( 'messenger_platform', 'Telegram' ),
			'platform with markup'    => array( 'messenger_platform', '<i>x</i>' ),
			'messenger id missing'    => array( 'messenger_user_id', null ),
			'messenger id empty'      => array( 'messenger_user_id', '' ),
			'messenger id as number'  => array( 'messenger_user_id', 987654321 ),
			'first name not a string' => array( 'first_name', 1 ),
			'last name not a string'  => array( 'last_name', array() ),
			'username not a string'   => array( 'username', false ),
		);
	}

	#[DataProvider( 'malformed_fields' )]
	public function test_a_malformed_field_is_refused( string $field, mixed $value ): void {
		$view           = self::view();
		$view[ $field ] = $value;

		self::assertNull( BotAdmin::from_response( $view ) );
	}

	public function test_a_non_object_is_refused(): void {
		self::assertNull( BotAdmin::from_response( null ) );
		self::assertNull( BotAdmin::from_response( 7 ) );
	}

	public function test_debug_output_hides_the_personal_data(): void {
		$admin = BotAdmin::from_response( self::view() );
		self::assertNotNull( $admin );

		ob_start();
		var_dump( $admin ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- The test checks what var_dump() prints.
		$outputs = array( (string) ob_get_clean(), print_r( $admin, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- The test checks what print_r() prints.

		foreach ( $outputs as $output ) {
			foreach ( array( 'Sara', 'Example', 'sara_example', '987654321' ) as $personal ) {
				self::assertStringNotContainsString( $personal, $output );
			}
		}
	}
}
