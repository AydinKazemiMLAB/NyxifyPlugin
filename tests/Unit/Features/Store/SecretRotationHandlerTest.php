<?php
/**
 * Tests for the "Rotate secret" button's handler.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Store;

use Brain\Monkey\Functions;
use Nyxify\Features\Store\SecretRotationHandler;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Tests\Unit\TestCase;

/**
 * Capability and nonce first, then the rotation, the notice and the redirect.
 *
 * The stubbed wp_safe_redirect() throws, so handle() stops before its exit.
 */
final class SecretRotationHandlerTest extends TestCase {

	private const SETTINGS_URL = 'https://shop.example.com/wp-admin/admin.php?page=nyxify';

	/**
	 * In-memory transients: name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * How many times the rotate callable ran.
	 *
	 * @var int
	 */
	private int $rotations = 0;

	/**
	 * What the rotate callable returns.
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

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $name ): int {
				if ( 'nyxify_rotate_secret' !== $action || '_nyxify_nonce' !== $name || ! $this->nonce_valid ) {
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

	private function handler(): SecretRotationHandler {
		return new SecretRotationHandler(
			function (): ?CoreError {
				++$this->rotations;
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

		self::assertNotFalse( has_action( 'admin_post_nyxify_rotate_secret', array( $handler, 'handle' ) ) );
	}

	public function test_without_the_capability_nothing_happens(): void {
		$this->can_manage = false;

		$this->expectExceptionMessage( 'wp_die' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( 0, $this->rotations );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_without_a_valid_nonce_nothing_happens(): void {
		$this->nonce_valid = false;

		$this->expectExceptionMessage( 'nonce' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( 0, $this->rotations );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_a_rotation_redirects_with_a_success_notice(): void {
		$this->press();

		self::assertSame( 1, $this->rotations );
		self::assertSame( 'success', $this->notice()['type'] );
		self::assertSame( SecretRotationHandler::ROTATED, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}

	public function test_a_failed_rotation_redirects_with_its_error(): void {
		$this->result = new CoreError( CoreError::SECRET_NOT_SAVED, 200, 'req-0001-abcd' );

		$this->press();

		self::assertSame( 1, $this->rotations );
		self::assertSame( 'error', $this->notice()['type'] );
		self::assertSame( CoreError::SECRET_NOT_SAVED, $this->notice()['code'] );
		self::assertSame( 200, $this->notice()['status'] );
		self::assertSame( 'req-0001-abcd', $this->notice()['request_id'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}
}
