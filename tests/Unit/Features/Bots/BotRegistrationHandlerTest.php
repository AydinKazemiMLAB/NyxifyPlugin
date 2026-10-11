<?php
/**
 * Tests for the bot token forms' handler.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Bots;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Bots\BotRegistrationHandler;
use Nyxify\Features\Bots\BotToken;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Tests\Unit\TestCase;

/**
 * Capability and nonce first, then the platform and token checks, the registration, the notice and the redirect.
 *
 * The stubbed wp_safe_redirect() throws, so handle() stops before its exit.
 */
final class BotRegistrationHandlerTest extends TestCase {

	private const SETTINGS_URL = 'https://shop.example.com/wp-admin/admin.php?page=nyxify';
	private const TOKEN        = '123456789:AAFsecretTokenValue_xyz';

	/**
	 * In-memory transients: name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Calls of the register callable: platform and token.
	 *
	 * @var list<array{string, string}>
	 */
	private array $registrations = array();

	/**
	 * What the register callable returns.
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

		$_POST = array(
			'nyxify_platform'  => 'telegram',
			'nyxify_bot_token' => self::TOKEN,
		);

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $name ): int {
				if ( 'nyxify_register_bot' !== $action || '_nyxify_nonce' !== $name || ! $this->nonce_valid ) {
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

	private function handler(): BotRegistrationHandler {
		return new BotRegistrationHandler(
			function ( string $platform, BotToken $token ): ?CoreError {
				$this->registrations[] = array( $platform, $token->value() );
				return $this->result;
			}
		);
	}

	/**
	 * Runs the handler up to its redirect.
	 */
	private function submit(): void {
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

		self::assertNotFalse( has_action( 'admin_post_nyxify_register_bot', array( $handler, 'handle' ) ) );
	}

	public function test_without_the_capability_nothing_happens(): void {
		$this->can_manage = false;

		$this->expectExceptionMessage( 'wp_die' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->registrations );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_without_a_valid_nonce_nothing_happens(): void {
		$this->nonce_valid = false;

		$this->expectExceptionMessage( 'nonce' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->registrations );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_a_token_is_registered_and_kept_out_of_the_notice_and_redirect(): void {
		$_POST['nyxify_platform']  = 'bale';
		$_POST['nyxify_bot_token'] = '  ' . self::TOKEN . "\n";

		$this->submit();

		self::assertSame( array( array( 'bale', self::TOKEN ) ), $this->registrations );
		self::assertSame( 'success', $this->notice()['type'] );
		self::assertSame( BotRegistrationHandler::REGISTERED, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
		self::assertStringNotContainsString( 'AAFsecret', (string) json_encode( $this->transients ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_a_failed_registration_redirects_with_its_error(): void {
		$this->result = new CoreError( 'invalid_bot_token', 400, 'req-0001-abcd' );

		$this->submit();

		self::assertCount( 1, $this->registrations );
		self::assertSame( 'error', $this->notice()['type'] );
		self::assertSame( 'invalid_bot_token', $this->notice()['code'] );
		self::assertSame( 400, $this->notice()['status'] );
		self::assertSame( 'req-0001-abcd', $this->notice()['request_id'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
		self::assertStringNotContainsString( 'AAFsecret', (string) json_encode( $this->transients ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function bad_platforms(): array {
		return array(
			'missing' => array( null ),
			'unknown' => array( 'whatsapp' ),
			'array'   => array( array( 'telegram' ) ),
			'path'    => array( 'telegram/../admins' ),
		);
	}

	#[DataProvider( 'bad_platforms' )]
	public function test_an_unknown_platform_sends_nothing( mixed $platform ): void {
		if ( null === $platform ) {
			unset( $_POST['nyxify_platform'] );
		} else {
			$_POST['nyxify_platform'] = $platform;
		}

		$this->submit();

		self::assertSame( array(), $this->registrations );
		self::assertSame( CoreError::INVALID_PAYLOAD, $this->notice()['code'] );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function bad_tokens(): array {
		return array(
			'missing'      => array( null ),
			'empty'        => array( '' ),
			'space inside' => array( '123456789:AAF0 dummy' ),
			'non-ASCII'    => array( 'توکن' ),
			'array'        => array( array( self::TOKEN ) ),
		);
	}

	#[DataProvider( 'bad_tokens' )]
	public function test_a_malformed_token_sends_nothing( mixed $token ): void {
		if ( null === $token ) {
			unset( $_POST['nyxify_bot_token'] );
		} else {
			$_POST['nyxify_bot_token'] = $token;
		}

		$this->submit();

		self::assertSame( array(), $this->registrations );
		self::assertSame( 'error', $this->notice()['type'] );
		self::assertSame( CoreError::BOT_TOKEN_FORMAT, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}
}
