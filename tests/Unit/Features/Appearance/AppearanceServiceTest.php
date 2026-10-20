<?php
/**
 * Tests for the bot appearance service.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Appearance\AppearanceService;
use Nyxify\Features\Appearance\AppearanceView;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Shared\Security\HmacSigner;
use Nyxify\Tests\Unit\TestCase;

/**
 * GET/PATCH /appearance over a mocked wp_remote_request() and WooCommerce logger, with the real client and secret
 * store; a queue of answers stands in for the core.
 */
final class AppearanceServiceTest extends TestCase {

	private const BASE_URL = 'https://core.example.com';
	private const STORE_ID = 'shop_8f2a0c1d3e4b5a69';

	private const CONTACT = array(
		'phone'    => '021 1234 5678',
		'telegram' => 'sara_shop',
		'address'  => array( 'fa' => 'تهران، خیابان آزادی' ),
	);

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Calls to wp_remote_request(): url and args.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * What the next calls to wp_remote_request() return, in order.
	 *
	 * @var list<array<string, mixed>|\stdClass>
	 */
	private array $answers = array();

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				self::assertNotSame( array(), $this->answers, 'No answer left for this request.' );
				return array_shift( $this->answers );
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $thing ): bool => $thing instanceof \stdClass );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);
	}

	/**
	 * A fake HTTP response.
	 *
	 * @param int   $status HTTP status.
	 * @param mixed $body   Value sent as JSON.
	 * @return array<string, mixed>
	 */
	private static function response( int $status, $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => self::json( $body ),
			'headers'  => array( 'x-request-id' => 'req-core-0001' ),
		);
	}

	/**
	 * JSON of a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * An `AppearanceView`.
	 *
	 * @param int                  $version  Version.
	 * @param array<string, mixed> $settings Parts.
	 * @return array<string, mixed>
	 */
	private static function view( int $version, array $settings = array() ): array {
		return array(
			'version'    => $version,
			'updated_at' => 0 === $version ? null : '2026-10-19T10:15:00',
			'settings'   => $settings + array(
				'menu'    => null,
				'contact' => null,
			),
		);
	}

	/**
	 * A core error answer.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Error code.
	 * @return array<string, mixed>
	 */
	private static function core_error( int $status, string $code ): array {
		return self::response(
			$status,
			array(
				'message'    => 'Rejected.',
				'error_code' => $code,
			)
		);
	}

	private static function secret(): string {
		return 'sec_' . str_repeat( 'ab', 32 );
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	private function connect(): void {
		$this->store()->save_connection( new Connection( self::STORE_ID, self::secret() ) );
	}

	private function service(): AppearanceService {
		$wc_logger = Mockery::mock( 'WC_Logger_Interface' );
		$wc_logger->shouldReceive( 'log' )->andReturnUsing(
			function ( string $level, string $message, array $context ): void {
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		);

		return new AppearanceService( new CoreClient( $this->store(), self::BASE_URL ), new Logger( $wc_logger ) );
	}

	/**
	 * The decoded body of a request.
	 *
	 * @param int $index Which request.
	 * @return array<string, mixed>
	 */
	private function body( int $index ): array {
		$body = json_decode( (string) $this->requests[ $index ]['args']['body'], true );
		self::assertIsArray( $body );

		return $body;
	}

	/**
	 * The hash a form loaded with this part carries.
	 *
	 * @param mixed $part The part as stored.
	 */
	private static function hash_of( $part ): string {
		$view = AppearanceView::from_response( self::view( 1, array( 'contact' => $part ) ) );
		self::assertNotNull( $view );

		return $view->hash( 'contact' );
	}

	/**
	 * None of the shop's details in any log entry.
	 */
	private function assert_logs_hold_no_values(): void {
		$logged = self::json( $this->entries );
		foreach ( array( '1234', 'sara_shop', 'تهران' ) as $value ) {
			self::assertStringNotContainsString( $value, $logged );
		}
	}

	public function test_without_a_connection_nothing_is_sent_or_logged(): void {
		$service = $this->service();

		$view = $service->get();
		$save = $service->save( 'contact', self::CONTACT, 0, self::hash_of( null ) );

		self::assertInstanceOf( CoreError::class, $view );
		self::assertSame( CoreError::NOT_ACTIVATED, $view->code );
		self::assertInstanceOf( CoreError::class, $save );
		self::assertSame( CoreError::NOT_ACTIVATED, $save->code );
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
	}

	public function test_get_reads_the_signed_view(): void {
		$this->connect();
		$this->answers[] = self::response( 200, self::view( 3, array( 'contact' => self::CONTACT ) ) );

		$view = $this->service()->get();

		self::assertInstanceOf( AppearanceView::class, $view );
		self::assertSame( 3, $view->version );
		self::assertSame( '2026-10-19T10:15:00', $view->updated_at );
		self::assertSame( self::CONTACT, $view->part( 'contact' ) );
		self::assertNull( $view->part( 'menu' ) );
		self::assertNull( $view->part( 'welcome' ) );
		self::assertSame( self::BASE_URL . '/api/v1/appearance', $this->requests[0]['url'] );
		self::assertSame( 'GET', $this->requests[0]['args']['method'] );
		self::assertSame( self::STORE_ID, $this->requests[0]['args']['headers'][ HmacSigner::HEADER_SHOP_ID ] );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function bad_views(): array {
		return array(
			'a list'            => array( array( 1, 2 ) ),
			'no version'        => array( array( 'settings' => array() ) ),
			'negative version'  => array(
				array(
					'version'    => -1,
					'updated_at' => null,
					'settings'   => array(),
				),
			),
			'settings a list'   => array(
				array(
					'version'    => 1,
					'updated_at' => null,
					'settings'   => array( 'x' ),
				),
			),
			'updated_at a date' => array(
				array(
					'version'    => 1,
					'updated_at' => 5,
					'settings'   => array(),
				),
			),
		);
	}

	/**
	 * @param mixed $answer The answer.
	 */
	#[DataProvider( 'bad_views' )]
	public function test_an_unreadable_view_is_an_invalid_response( $answer ): void {
		$this->connect();
		$this->answers[] = self::response( 200, $answer );

		$view = $this->service()->get();

		self::assertInstanceOf( CoreError::class, $view );
		self::assertSame( CoreError::INVALID_RESPONSE, $view->code );
		self::assertSame( 'warning:invalid_response', $this->entries[0]['level'] . ':' . $this->entries[0]['context']['error_code'] );
	}

	public function test_save_sends_only_its_part_on_the_form_version(): void {
		$this->connect();
		$this->answers[] = self::response( 200, self::view( 4, array( 'contact' => self::CONTACT ) ) );

		$error = $this->service()->save( 'contact', self::CONTACT, 3, self::hash_of( null ) );

		self::assertNull( $error );
		self::assertCount( 1, $this->requests );
		self::assertSame( 'PATCH', $this->requests[0]['args']['method'] );
		self::assertSame( self::BASE_URL . '/api/v1/appearance', $this->requests[0]['url'] );
		self::assertSame(
			array(
				'version'  => 3,
				'settings' => array( 'contact' => self::CONTACT ),
			),
			$this->body( 0 )
		);
		self::assertSame( 'info', $this->entries[0]['level'] );
		self::assertSame( 'contact', $this->entries[0]['context']['part'] );
		self::assertSame( 4, $this->entries[0]['context']['version'] );
		$this->assert_logs_hold_no_values();
	}

	public function test_an_empty_form_sends_null_for_the_part(): void {
		$this->connect();
		$this->answers[] = self::response( 200, self::view( 5 ) );

		self::assertNull( $this->service()->save( 'contact', null, 4, self::hash_of( self::CONTACT ) ) );

		self::assertSame( '{"version":4,"settings":{"contact":null}}', $this->requests[0]['args']['body'] );
	}

	public function test_a_conflict_on_another_part_is_sent_again_on_the_new_version(): void {
		$this->connect();
		$this->answers = array(
			self::core_error( 409, 'appearance_version_conflict' ),
			// A bot admin changed the menu meanwhile; the contact info is as the form loaded it.
			self::response( 200, self::view( 7, array( 'menu' => array( 'layout' => array( array( 'products' ) ) ) ) ) ),
			self::response( 200, self::view( 8, array( 'contact' => self::CONTACT ) ) ),
		);

		$error = $this->service()->save( 'contact', self::CONTACT, 6, self::hash_of( null ) );

		self::assertNull( $error );
		self::assertSame( array( 'PATCH', 'GET', 'PATCH' ), array_map( fn( array $request ): string => $request['args']['method'], $this->requests ) );
		self::assertSame( 6, $this->body( 0 )['version'] );
		self::assertSame( 7, $this->body( 2 )['version'] );
		self::assertSame( array( 'contact' => self::CONTACT ), $this->body( 2 )['settings'] );
		self::assertSame( array( 'info' ), array_column( $this->entries, 'level' ) );
	}

	public function test_a_conflict_on_its_own_part_saves_nothing(): void {
		$this->connect();
		$this->answers = array(
			self::core_error( 409, 'appearance_version_conflict' ),
			self::response( 200, self::view( 7, array( 'contact' => array( 'phone' => '021 9999 0000' ) ) ) ),
		);

		$error = $this->service()->save( 'contact', self::CONTACT, 6, self::hash_of( null ) );

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( AppearanceService::VERSION_CONFLICT, $error->code );
		self::assertSame( 409, $error->status );
		self::assertCount( 2, $this->requests );
		self::assertSame( 'warning:appearance_version_conflict', $this->entries[0]['level'] . ':' . $this->entries[0]['context']['error_code'] );
		$this->assert_logs_hold_no_values();
	}

	public function test_a_second_conflict_is_returned(): void {
		$this->connect();
		$this->answers = array(
			self::core_error( 409, 'appearance_version_conflict' ),
			self::response( 200, self::view( 7 ) ),
			self::core_error( 409, 'appearance_version_conflict' ),
		);

		$error = $this->service()->save( 'contact', self::CONTACT, 6, self::hash_of( null ) );

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( AppearanceService::VERSION_CONFLICT, $error->code );
		self::assertCount( 3, $this->requests );
	}

	public function test_a_conflict_whose_view_cannot_be_read_returns_that_error(): void {
		$this->connect();
		$this->answers = array(
			self::core_error( 409, 'appearance_version_conflict' ),
			new \stdClass(),
		);

		$error = $this->service()->save( 'contact', self::CONTACT, 6, self::hash_of( null ) );

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( CoreError::NETWORK_ERROR, $error->code );
		self::assertCount( 2, $this->requests );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function refusals(): array {
		return array(
			'too large'  => array( 413, 'appearance_too_large' ),
			'validation' => array( 422, 'validation_error' ),
			'inactive'   => array( 403, 'store_not_active' ),
		);
	}

	#[DataProvider( 'refusals' )]
	public function test_a_refusal_is_returned_and_logged_without_values( int $status, string $code ): void {
		$this->connect();
		$this->answers[] = self::core_error( $status, $code );

		$error = $this->service()->save( 'contact', self::CONTACT, 6, self::hash_of( null ) );

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( $code, $error->code );
		self::assertCount( 1, $this->requests );
		self::assertSame( 'warning:' . $code, $this->entries[0]['level'] . ':' . $this->entries[0]['context']['error_code'] );
		$this->assert_logs_hold_no_values();
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public static function bad_saves(): array {
		return array(
			'part with a slash' => array( 'contact/../x', 1 ),
			'part in capitals'  => array( 'Contact', 1 ),
			'negative version'  => array( 'contact', -1 ),
		);
	}

	#[DataProvider( 'bad_saves' )]
	public function test_a_bad_part_or_version_sends_nothing( string $part, int $version ): void {
		$this->connect();

		$error = $this->service()->save( $part, null, $version, self::hash_of( null ) );

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( CoreError::INVALID_PAYLOAD, $error->code );
		self::assertSame( array(), $this->requests );
	}
}
