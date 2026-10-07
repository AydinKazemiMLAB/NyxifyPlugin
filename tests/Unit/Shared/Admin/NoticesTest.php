<?php
/**
 * Tests for the one-time admin notices.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Shared\Admin;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Shared\Admin\Notice;
use WooNyx\Shared\Admin\Notices;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Tests\Unit\TestCase;

/**
 * Notices kept per user in an in-memory transient store.
 */
final class NoticesTest extends TestCase {

	/**
	 * In-memory transients: name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Lifetimes passed to set_transient().
	 *
	 * @var array<string, int>
	 */
	private array $lifetimes = array();

	/**
	 * Current user id.
	 *
	 * @var int
	 */
	private int $user_id = 5;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->user_id );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value, int $expiration ): bool {
				$this->transients[ $name ] = $value;
				$this->lifetimes[ $name ]  = $expiration;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
	}

	public function test_a_success_is_kept_for_a_minute_and_shown_once(): void {
		Notices::push( Notice::success( 'activated' ) );

		self::assertSame( 60, $this->lifetimes['woonyx_notice_5'] );

		$notice = Notices::take();
		self::assertNotNull( $notice );
		self::assertSame( Notice::SUCCESS, $notice->type );
		self::assertSame( 'activated', $notice->code );

		self::assertNull( Notices::take() );
		self::assertSame( array(), $this->transients );
	}

	public function test_an_error_keeps_code_status_request_id_and_fields_only(): void {
		Notices::push( Notice::error( new CoreError( 'validation_error', 422, 'req-0001-abcd', array( 'body.site_url' ) ) ) );

		self::assertSame(
			array(
				'type'       => 'error',
				'code'       => 'validation_error',
				'status'     => 422,
				'request_id' => 'req-0001-abcd',
				'fields'     => array( 'body.site_url' ),
			),
			$this->transients['woonyx_notice_5']
		);

		$notice = Notices::take();
		self::assertNotNull( $notice );
		self::assertSame( Notice::ERROR, $notice->type );
		self::assertSame( 'validation_error', $notice->code );
		self::assertSame( 422, $notice->status );
		self::assertSame( 'req-0001-abcd', $notice->request_id );
		self::assertSame( array( 'body.site_url' ), $notice->fields );
	}

	public function test_notices_are_per_user(): void {
		Notices::push( Notice::success( 'activated' ) );

		$this->user_id = 6;

		self::assertNull( Notices::take() );
		self::assertArrayHasKey( 'woonyx_notice_5', $this->transients );
	}

	public function test_nothing_is_kept_without_a_user(): void {
		$this->user_id = 0;

		Notices::push( Notice::success( 'activated' ) );

		self::assertSame( array(), $this->transients );
		self::assertNull( Notices::take() );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function malformed_values(): array {
		$valid = array(
			'type'       => 'error',
			'code'       => 'network_error',
			'status'     => 0,
			'request_id' => '',
			'fields'     => array(),
		);

		return array(
			'not an array'    => array( 'activated' ),
			'unknown type'    => array( array( 'type' => 'info' ) + $valid ),
			'no code'         => array( array( 'code' => null ) + $valid ),
			'markup in code'  => array( array( 'code' => '<b>x</b>' ) + $valid ),
			'status a string' => array( array( 'status' => '500' ) + $valid ),
			'fields a string' => array( array( 'fields' => 'body.site_url' ) + $valid ),
		);
	}

	#[DataProvider( 'malformed_values' )]
	public function test_a_malformed_value_is_dropped( mixed $value ): void {
		$this->transients['woonyx_notice_5'] = $value;

		self::assertNull( Notices::take() );
		self::assertSame( array(), $this->transients );
	}

	public function test_non_string_fields_are_dropped(): void {
		$this->transients['woonyx_notice_5'] = array(
			'type'   => 'error',
			'code'   => 'validation_error',
			'status' => 422,
			'fields' => array( 'body.site_url', 42, array( 'x' ) ),
		);

		self::assertSame( array( 'body.site_url' ), Notices::take()?->fields );
	}
}
