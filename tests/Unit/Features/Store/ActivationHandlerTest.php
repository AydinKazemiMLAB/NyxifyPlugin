<?php
/**
 * Tests for the license form's handler.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Store;

use Brain\Monkey\Functions;
use WooNyx\Features\Store\ActivationHandler;
use WooNyx\Features\Store\LicenseKey;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Tests\Unit\TestCase;

/**
 * Capability and nonce first, then the key, the activation, the notice and the redirect.
 *
 * The stubbed wp_safe_redirect() throws, so handle() stops before its exit.
 */
final class ActivationHandlerTest extends TestCase {

	private const LICENSE_KEY  = 'WN-ABCDEF-GHIJKL-MNOPQR-ST2345';
	private const SETTINGS_URL = 'https://shop.example.com/wp-admin/admin.php?page=woonyx';

	/**
	 * In-memory transients: name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Calls of the activate callable: license key value and user id.
	 *
	 * @var list<array{string, int}>
	 */
	private array $activations = array();

	/**
	 * What the activate callable returns.
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
				if ( 'woonyx_activate' !== $action || '_woonyx_nonce' !== $name || ! $this->nonce_valid ) {
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
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
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
		unset( $_POST[ ActivationHandler::FIELD ] );
		parent::tearDown();
	}

	private function handler(): ActivationHandler {
		return new ActivationHandler(
			function ( LicenseKey $license, int $user_id ): ?CoreError {
				$this->activations[] = array( $license->value(), $user_id );
				return $this->result;
			}
		);
	}

	/**
	 * Posts the form and runs the handler up to its redirect.
	 *
	 * @param mixed $key Posted license key (null: no field).
	 */
	private function post( $key ): void {
		if ( null !== $key ) {
			$_POST[ ActivationHandler::FIELD ] = $key;
		}

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
		$notice = $this->transients['woonyx_notice_5'] ?? null;
		self::assertIsArray( $notice );

		return $notice;
	}

	/**
	 * JSON of a value for assertions.
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_register_hooks_the_admin_post_action(): void {
		$handler = $this->handler();

		$handler->register();

		self::assertNotFalse( has_action( 'admin_post_woonyx_activate', array( $handler, 'handle' ) ) );
	}

	public function test_without_the_capability_nothing_happens(): void {
		$this->can_manage                  = false;
		$_POST[ ActivationHandler::FIELD ] = self::LICENSE_KEY;

		$this->expectExceptionMessage( 'wp_die' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->activations );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_without_a_valid_nonce_nothing_happens(): void {
		$this->nonce_valid                 = false;
		$_POST[ ActivationHandler::FIELD ] = self::LICENSE_KEY;

		$this->expectExceptionMessage( 'nonce' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->activations );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_a_valid_key_activates_and_redirects_with_a_success_notice(): void {
		$this->post( addslashes( '  ' . strtolower( self::LICENSE_KEY ) . ' ' ) );

		self::assertSame( array( array( self::LICENSE_KEY, 5 ) ), $this->activations );
		self::assertSame( 'success', $this->notice()['type'] );
		self::assertSame( ActivationHandler::ACTIVATED, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}

	public function test_a_failed_activation_redirects_with_its_error(): void {
		$this->result = new CoreError( 'license_already_used', 409, 'req-0001-abcd' );

		$this->post( self::LICENSE_KEY );

		self::assertCount( 1, $this->activations );
		self::assertSame( 'error', $this->notice()['type'] );
		self::assertSame( 'license_already_used', $this->notice()['code'] );
		self::assertSame( 409, $this->notice()['status'] );
		self::assertSame( 'req-0001-abcd', $this->notice()['request_id'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}

	public function test_a_malformed_key_is_not_sent(): void {
		$this->post( 'WN-ABCDEF-GHIJKL-MNOPQR' );

		self::assertSame( array(), $this->activations );
		self::assertSame( CoreError::LICENSE_KEY_FORMAT, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}

	public function test_a_missing_or_non_string_key_is_not_sent(): void {
		$this->post( null );
		self::assertSame( CoreError::LICENSE_KEY_FORMAT, $this->notice()['code'] );

		$this->post( array( self::LICENSE_KEY ) );
		self::assertSame( CoreError::LICENSE_KEY_FORMAT, $this->notice()['code'] );

		self::assertSame( array(), $this->activations );
	}

	public function test_the_key_never_reaches_the_redirect_or_the_notice(): void {
		$this->result = new CoreError( 'invalid_license_key', 400, 'req-0001-abcd' );

		$this->post( self::LICENSE_KEY );

		$stored = self::json( $this->transients );
		self::assertStringNotContainsString( 'GHIJKL', $stored );
		self::assertStringNotContainsString( 'GHIJKL', (string) $this->redirect );
		self::assertStringNotContainsString( 'ghijkl', strtolower( (string) $this->redirect ) );
	}
}
