<?php
/**
 * Tests for the bot admins' "Remove" button's handler.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Bots;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Bots\AdminRemovalHandler;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Tests\Unit\TestCase;

/**
 * Capability and nonce first, then the posted id (a positive decimal only), the notice and the redirect.
 *
 * The stubbed wp_safe_redirect() throws, so handle() stops before its exit.
 */
final class AdminRemovalHandlerTest extends TestCase {

	private const SETTINGS_URL = 'https://shop.example.com/wp-admin/admin.php?page=nyxify';

	/**
	 * In-memory transients: name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Ids the revoke callable was called with.
	 *
	 * @var list<int>
	 */
	private array $revoked = array();

	/**
	 * What the revoke callable returns.
	 *
	 * @var CoreError|null
	 */
	private ?CoreError $result = null;

	/**
	 * Where wp_safe_redirect() was asked to go.
	 *
	 * @var string|null
	 */
	private ?string $redirect = null;

	/**
	 * Whether the current user may manage WooCommerce.
	 *
	 * @var bool
	 */
	private bool $can_manage = true;

	/**
	 * Whether the nonce is valid.
	 *
	 * @var bool
	 */
	private bool $nonce_valid = true;

	protected function setUp(): void {
		parent::setUp();

		$_POST = array();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $name ): int {
				if ( 'nyxify_revoke_admin' !== $action || '_nyxify_nonce' !== $name || ! $this->nonce_valid ) {
					throw new \RuntimeException( 'nonce' );
				}
				return 1;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location ): void {
				$this->redirect = $location;
				throw new \RuntimeException( 'redirect' );
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function handler(): AdminRemovalHandler {
		return new AdminRemovalHandler(
			function ( int $id ): ?CoreError {
				$this->revoked[] = $id;
				return $this->result;
			}
		);
	}

	/**
	 * Runs the handler up to its redirect.
	 */
	private function press(): void {
		try {
			$this->handler()->handle();
			self::fail( 'handle() returned instead of redirecting.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirect', $e->getMessage() );
		}
	}

	/**
	 * The notice waiting for user 5.
	 *
	 * @return array<string, mixed>
	 */
	private function notice(): array {
		$notice = $this->transients['nyxify_notice_5'] ?? null;
		self::assertIsArray( $notice );

		return $notice;
	}

	public function test_register_hooks_the_admin_post_action(): void {
		$handler = $this->handler();

		$handler->register();

		self::assertNotFalse( has_action( 'admin_post_nyxify_revoke_admin', array( $handler, 'handle' ) ) );
	}

	public function test_without_the_capability_nothing_happens(): void {
		$this->can_manage                             = false;
		$_POST[ AdminRemovalHandler::FIELD_ADMIN_ID ] = '7';

		$this->expectExceptionMessage( 'wp_die' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->revoked );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_without_a_valid_nonce_nothing_happens(): void {
		$this->nonce_valid                            = false;
		$_POST[ AdminRemovalHandler::FIELD_ADMIN_ID ] = '7';

		$this->expectExceptionMessage( 'nonce' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->revoked );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_removes_the_posted_admin(): void {
		$_POST[ AdminRemovalHandler::FIELD_ADMIN_ID ] = '7';

		$this->press();

		self::assertSame( array( 7 ), $this->revoked );
		self::assertSame( 'success', $this->notice()['type'] );
		self::assertSame( AdminRemovalHandler::REVOKED, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}

	public function test_a_large_id_is_kept_whole(): void {
		$_POST[ AdminRemovalHandler::FIELD_ADMIN_ID ] = '123456789012345678';

		$this->press();

		self::assertSame( array( 123456789012345678 ), $this->revoked );
	}

	/**
	 * Posted values that aren't an admin id.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function invalid_ids(): array {
		return array(
			'missing'       => array( null ),
			'empty'         => array( '' ),
			'zero'          => array( '0' ),
			'negative'      => array( '-7' ),
			'plus sign'     => array( '+7' ),
			'leading zero'  => array( '07' ),
			'decimal'       => array( '7.0' ),
			'space'         => array( ' 7' ),
			'newline'       => array( "7\n" ),
			'path'          => array( '7/../../bots/telegram' ),
			'exponent'      => array( '7e2' ),
			'hex'           => array( '0x7' ), // phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- A posted string, which must be refused.
			'too long'      => array( '1234567890123456789' ),
			'array'         => array( array( '7' ) ),
			'arabic digits' => array( '٧' ),
		);
	}

	#[DataProvider( 'invalid_ids' )]
	public function test_an_invalid_id_is_refused_without_calling_the_core( mixed $value ): void {
		if ( null !== $value ) {
			$_POST[ AdminRemovalHandler::FIELD_ADMIN_ID ] = $value;
		}

		$this->press();

		self::assertSame( array(), $this->revoked );
		self::assertSame( 'error', $this->notice()['type'] );
		self::assertSame( CoreError::INVALID_PAYLOAD, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}

	public function test_a_failure_redirects_with_its_error(): void {
		$_POST[ AdminRemovalHandler::FIELD_ADMIN_ID ] = '7';
		$this->result                                 = new CoreError( 'customer_not_found', 404, 'req-0001-abcd' );

		$this->press();

		self::assertSame( 'error', $this->notice()['type'] );
		self::assertSame( 'customer_not_found', $this->notice()['code'] );
		self::assertSame( 'req-0001-abcd', $this->notice()['request_id'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}
}
