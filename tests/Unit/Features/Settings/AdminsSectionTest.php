<?php
/**
 * Tests for the settings page's "Bot admins" section.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Settings;

use Brain\Monkey\Functions;
use Mockery;
use Nyxify\Features\Bots\AdminService;
use Nyxify\Features\Settings\AdminsSection;
use Nyxify\Features\Store\StoreStatus;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * The rows (name, username, the messenger id only as a fallback), the "Remove" forms, the escaping of the core's
 * strings, and when nothing is sent.
 */
final class AdminsSectionTest extends TestCase {

	private const CORE_URL = 'https://core.example.com';

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
	 * What `GET /bots/admins` returns.
	 *
	 * @var array<string, mixed>|\stdClass
	 */
	private $admins_response;

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	protected function setUp(): void {
		parent::setUp();

		$this->admins_response = self::response( 200, '[]' );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action, string $name ): void {
				printf( '<input type="hidden" name="%s" value="nonce-for-%s" />', $name, $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->admins_response;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $thing ): bool => $thing instanceof \stdClass );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);

		$store = new SecretStore( Encryptor::from_secret( 'site secret' ) );
		$store->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );
	}

	/**
	 * A fake HTTP response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw body.
	 * @return array<string, mixed>
	 */
	private static function response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => array( 'x-request-id' => 'req-core-0004' ),
		);
	}

	/**
	 * JSON of a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * The core answers GET /bots/admins with these admins.
	 *
	 * @param array<array<string, mixed>> $admins `AdminView`s.
	 */
	private function admins( array $admins ): void {
		$this->admins_response = self::response( 200, self::json( $admins ) );
	}

	/**
	 * An `AdminView`.
	 *
	 * @param array<string, mixed> $changes Fields to replace.
	 * @return array<string, mixed>
	 */
	private static function admin( array $changes = array() ): array {
		return $changes + array(
			'id'                 => 7,
			'messenger_platform' => 'telegram',
			'messenger_user_id'  => '987654321',
			'first_name'         => 'Sara',
			'last_name'          => 'Ahmadi',
			'username'           => 'sara_shop',
		);
	}

	/**
	 * A store status.
	 *
	 * @param string $state The store's state.
	 */
	private static function store_status( string $state = 'active' ): StoreStatus {
		$status = StoreStatus::from_response(
			array(
				'store_id'     => 'shop_8f2a0c1d3e4b5a69',
				'website_url'  => 'https://shop.example.com',
				'status'       => $state,
				'subscription' => array(
					'plan_name'         => 'Pro',
					'expires_at'        => '2026-12-01T00:00:00',
					'allowed_platforms' => array( 'telegram', 'bale', 'rubika' ),
					'max_products'      => null,
				),
				'catalog'      => array(
					'status'          => 'succeeded',
					'last_synced_at'  => null,
					'error'           => null,
					'limit_reached'   => false,
					'active_products' => 3,
				),
			),
			1759832100
		);
		self::assertNotNull( $status );

		return $status;
	}

	private function rendered( ?StoreStatus $status = null ): string {
		$store     = new SecretStore( Encryptor::from_secret( 'site secret' ) );
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

		ob_start();
		( new AdminsSection( new AdminService( new CoreClient( $store, self::CORE_URL ), new Logger( $wc_logger ) ) ) )->render( $status ?? self::store_status() );

		return (string) ob_get_clean();
	}

	/**
	 * The rows of the admins table.
	 *
	 * @param string $html The section.
	 * @return list<string>
	 */
	private static function rows( string $html ): array {
		preg_match( '#<tbody>(.*?)</tbody>#s', $html, $body );
		preg_match_all( '#<tr>.*?</tr>#s', $body[1] ?? '', $matches );

		return $matches[0];
	}

	/**
	 * The cells of a row, trimmed.
	 *
	 * @param string $row A row.
	 * @return list<string>
	 */
	private static function cells( string $row ): array {
		preg_match_all( '#<td>(.*?)</td>#s', $row, $matches );

		return array_map( 'trim', $matches[1] );
	}

	public function test_lists_each_admin_with_a_remove_form(): void {
		$this->admins(
			array(
				self::admin(),
				self::admin(
					array(
						'id'                 => 12,
						'messenger_platform' => 'bale',
						'messenger_user_id'  => '55501',
						'first_name'         => 'Reza',
						'last_name'          => null,
						'username'           => null,
					)
				),
			)
		);

		$html = $this->rendered();

		self::assertCount( 1, $this->requests );
		self::assertSame( self::CORE_URL . '/api/v1/bots/admins', $this->requests[0]['url'] );
		self::assertStringContainsString( '<section class="nyxify-card nyxify-admins-card" aria-labelledby="nyxify-admins-heading">', $html );
		self::assertStringContainsString( '<h2 id="nyxify-admins-heading">Bot admins</h2>', $html );
		self::assertStringContainsString( '<table class="nyxify-table nyxify-admins">', $html );
		self::assertStringNotContainsString( 'No one is a bot admin yet.', $html );

		$rows = self::rows( $html );
		self::assertCount( 2, $rows );

		$first = self::cells( $rows[0] );
		self::assertSame( '<span class="nyxify-tag">Telegram</span>', $first[0] );
		self::assertSame( 'Sara Ahmadi', $first[1] );
		self::assertSame( '<span dir="ltr">@sara_shop</span>', $first[2] );
		self::assertStringContainsString( 'action="https://shop.example.com/wp-admin/admin-post.php"', $first[3] );
		self::assertStringContainsString( '<input type="hidden" name="action" value="nyxify_revoke_admin" />', $first[3] );
		self::assertStringContainsString( 'name="_nyxify_nonce" value="nonce-for-nyxify_revoke_admin"', $first[3] );
		self::assertStringContainsString( '<input type="hidden" name="nyxify_admin_id" value="7" />', $first[3] );
		self::assertStringContainsString( 'data-nyxify-confirm="Remove Sara Ahmadi as a bot admin? The current PIN still works for them until you issue a new one."', $first[3] );
		self::assertStringContainsString( '>Remove</button>', $first[3] );

		$second = self::cells( $rows[1] );
		self::assertSame( '<span class="nyxify-tag">Bale</span>', $second[0] );
		self::assertSame( 'Reza', $second[1] );
		self::assertSame( '&mdash;', $second[2] );
		self::assertStringContainsString( 'name="nyxify_admin_id" value="12"', $second[3] );
	}

	public function test_the_messenger_id_is_shown_only_without_a_name_and_a_username(): void {
		$this->admins(
			array(
				self::admin(),
				self::admin(
					array(
						'id'         => 8,
						'first_name' => null,
						'last_name'  => '',
					)
				),
				self::admin(
					array(
						'id'                => 9,
						'messenger_user_id' => '44402',
						'first_name'        => ' ',
						'last_name'         => null,
						'username'          => '',
					)
				),
			)
		);

		$html = $this->rendered();
		$rows = self::rows( $html );

		self::assertStringNotContainsString( '987654321', $html );

		$no_name = self::cells( $rows[1] );
		self::assertSame( '&mdash;', $no_name[1] );
		self::assertSame( '<span dir="ltr">@sara_shop</span>', $no_name[2] );
		self::assertStringContainsString( 'data-nyxify-confirm="Remove @sara_shop as a bot admin?', $no_name[3] );

		$neither = self::cells( $rows[2] );
		self::assertSame( 'Messenger ID: <code dir="ltr">44402</code>', $neither[1] );
		self::assertSame( '&mdash;', $neither[2] );
		self::assertStringContainsString( 'data-nyxify-confirm="Remove 44402 as a bot admin?', $neither[3] );
	}

	public function test_a_username_is_shown_with_one_at_sign(): void {
		$this->admins( array( self::admin( array( 'username' => '@sara_shop' ) ) ) );

		self::assertSame( '<span dir="ltr">@sara_shop</span>', self::cells( self::rows( $this->rendered() )[0] )[2] );
	}

	public function test_every_string_from_the_core_is_escaped(): void {
		$this->admins(
			array(
				self::admin(
					array(
						'messenger_platform' => 'eitaa',
						'first_name'         => '<script>alert(1)</script>',
						'last_name'          => '"quoted"',
						'username'           => '<b>user</b>',
					)
				),
				self::admin(
					array(
						'id'                => 8,
						'messenger_user_id' => '<img src=x onerror=alert(2)>',
						'first_name'        => null,
						'last_name'         => null,
						'username'          => null,
					)
				),
			)
		);

		$html = $this->rendered();

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>user', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( '"quoted"', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; &quot;quoted&quot;', $html );
		self::assertStringContainsString( '@&lt;b&gt;user&lt;/b&gt;', $html );
		self::assertStringContainsString( '<code dir="ltr">&lt;img src=x onerror=alert(2)&gt;</code>', $html );
		self::assertSame( '<span class="nyxify-tag">eitaa</span>', self::cells( self::rows( $html )[0] )[0] );
	}

	public function test_without_admins(): void {
		$html = $this->rendered();

		self::assertStringContainsString( 'No one is a bot admin yet.', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_a_list_error_is_shown_and_logged_without_personal_data(): void {
		$this->admins_response = self::response( 403, '{"message":"No subscription.","error_code":"no_active_subscription"}' );

		$html = $this->rendered();

		self::assertStringContainsString( 'notice-warning', $html );
		self::assertStringContainsString( 'req-core-0004', $html );
		self::assertStringNotContainsString( '<table', $html );
		self::assertCount( 1, $this->entries );
	}

	public function test_an_invalid_answer_shows_no_admin_and_logs_no_personal_data(): void {
		$this->admins( array( self::admin(), self::admin( array( 'id' => 'eight' ) ) ) );

		$html = $this->rendered();

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringNotContainsString( 'Sara', $html );
		self::assertStringNotContainsString( '<table', $html );

		$logged = self::json( $this->entries );
		foreach ( array( 'Sara', 'Ahmadi', 'sara_shop', '987654321' ) as $personal ) {
			self::assertStringNotContainsString( $personal, $logged );
		}
	}

	public function test_a_network_error_is_shown(): void {
		$this->admins_response = new \stdClass();

		$html = $this->rendered();

		self::assertStringContainsString( 'notice-error', $html );
		self::assertStringNotContainsString( '<table', $html );
	}

	public function test_nothing_is_sent_while_the_store_is_not_active(): void {
		$html = $this->rendered( self::store_status( 'deactivated' ) );

		self::assertSame( array(), $this->requests );
		self::assertStringContainsString( 'The bot admins can be managed here once the store is active on Nyxify again.', $html );
		self::assertStringNotContainsString( '<form', $html );
	}
}
