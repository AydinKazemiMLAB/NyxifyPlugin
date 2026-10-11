<?php
/**
 * Tests for the deactivation of the store on the core.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Store;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Catalog\FlushScheduler;
use WooNyx\Features\Catalog\PlanLimitNotice;
use WooNyx\Features\Catalog\Schema;
use WooNyx\Features\Store\Deactivator;
use WooNyx\Features\Store\RestKeys;
use WooNyx\Features\Store\StatusService;
use WooNyx\Features\Sync\PullLock;
use WooNyx\Features\Sync\PullScheduler;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Shared\Security\HmacSigner;
use WooNyx\Tests\Unit\EmulatesPrepare;
use WooNyx\Tests\Unit\TestCase;

/**
 * The deactivation over a mocked wp_remote_request(), $wpdb, WooCommerce logger and multisite functions, with the
 * real client, secret store and REST keys. Options and transients are kept per site.
 *
 * A fake response is an array like WordPress's; a network failure is a \stdClass, which the mocked is_wp_error()
 * treats as a WP_Error.
 */
final class DeactivatorTest extends TestCase {

	use EmulatesPrepare;

	private const BASE_URL  = 'https://core.example.com';
	private const STORE_ID  = 'shop_8f2a0c1d3e4b5a69';
	private const BASENAME  = 'woonyx/woonyx.php';
	private const KEY_ID    = 9;
	private const MAIN_SITE = 1;

	/**
	 * In-memory wp_options per site: site id => name => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $options = array();

	/**
	 * Sites with a cached store status.
	 *
	 * @var array<int, bool>
	 */
	private array $status_cached = array();

	/**
	 * The current site, and the sites switched from.
	 *
	 * @var list<int>
	 */
	private array $site_stack = array( self::MAIN_SITE );

	/**
	 * The network's sites.
	 *
	 * @var list<int>
	 */
	private array $sites = array( self::MAIN_SITE );

	/**
	 * Whether this is a multisite install.
	 *
	 * @var bool
	 */
	private bool $multisite = false;

	/**
	 * Calls to wp_remote_request(): site, url and args.
	 *
	 * @var list<array{site: int, url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * What the next wp_remote_request() returns.
	 *
	 * @var array<string, mixed>|\stdClass
	 */
	private $next_response;

	/**
	 * Seconds each request takes on the fake clock.
	 *
	 * @var float
	 */
	private float $request_seconds = 0.0;

	/**
	 * The fake clock.
	 *
	 * @var float
	 */
	private float $now = 1000.0;

	/**
	 * Key ids deleted from woocommerce_api_keys, with the table prefix.
	 *
	 * @var list<string>
	 */
	private array $deleted_keys = array();

	/**
	 * Whether deleting a REST key fails.
	 *
	 * @var bool
	 */
	private bool $key_delete_fails = false;

	/**
	 * Sites whose catalog queue was stopped, in order.
	 *
	 * @var list<int>
	 */
	private array $stopped = array();

	/**
	 * Whether stopping the catalog queue throws.
	 *
	 * @var bool
	 */
	private bool $stop_fails = false;

	/**
	 * Sites whose pending catalog flushes were cancelled, in order.
	 *
	 * @var list<int>
	 */
	private array $unscheduled = array();

	/**
	 * Cancelled pulls: site and hook, in order.
	 *
	 * @var list<string>
	 */
	private array $pulls_unscheduled = array();

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	/**
	 * Mocked database.
	 *
	 * @var \wpdb&MockInterface
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->next_response = self::deactivated();

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $this->site() ][ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $this->site() ][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $this->site() ][ $name ] );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				if ( StatusService::TRANSIENT === $name ) {
					$this->status_cached[ $this->site() ] = false;
				}
				return true;
			}
		);

		Functions\when( 'is_multisite' )->alias( fn(): bool => $this->multisite );
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook, array $args, string $group ): void {
				self::assertSame( array( array(), 'woonyx' ), array( $args, $group ) );
				if ( FlushScheduler::HOOK === $hook ) {
					$this->unscheduled[] = $this->site();
					return;
				}
				self::assertContains( $hook, array( PullScheduler::HOOK, PullScheduler::NOW_HOOK ) );
				$this->pulls_unscheduled[] = $this->site() . ':' . $hook;
			}
		);
		Functions\when( 'get_sites' )->alias(
			function ( array $args ): array {
				self::assertSame( 'ids', $args['fields'] );
				self::assertSame( 0, $args['number'] );
				return $this->sites;
			}
		);
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $site_id ): bool {
				$this->site_stack[] = $site_id;
				$this->wpdb->prefix = $this->prefix();
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				array_pop( $this->site_stack );
				$this->wpdb->prefix = $this->prefix();
				return true;
			}
		);

		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array(
					'site' => $this->site(),
					'url'  => $url,
					'args' => $args,
				);
				$this->now       += $this->request_seconds;
				return $this->next_response;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $thing ): bool => $thing instanceof \stdClass );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);

		$this->wpdb         = Mockery::mock( \wpdb::class );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( string $table, array $where ) {
				if ( $this->key_delete_fails ) {
					return false;
				}
				$this->deleted_keys[] = $table . ':' . $where['key_id'];
				return 1;
			}
		);
		$GLOBALS['wpdb'] = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function site(): int {
		return $this->site_stack[ count( $this->site_stack ) - 1 ];
	}

	/**
	 * The current site's table prefix, as WordPress sets it.
	 */
	private function prefix(): string {
		return self::MAIN_SITE === $this->site() ? 'wp_' : 'wp_' . $this->site() . '_';
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
			'headers'  => array( 'x-request-id' => 'req-core-0001' ),
		);
	}

	/**
	 * JSON of a value (wp_json_encode() is only stubbed inside a test).
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	private static function secret(): string {
		return 'sec_' . str_repeat( '01', 32 );
	}

	/**
	 * The core's 200 answer (the store's status).
	 *
	 * @return array<string, mixed>
	 */
	private static function deactivated(): array {
		return self::response(
			200,
			self::json(
				array(
					'store_id'    => self::STORE_ID,
					'website_url' => 'https://shop.example.com',
					'status'      => 'deactivated',
				)
			)
		);
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	/**
	 * Connects the current site: a connection, a remembered REST key and a cached status.
	 */
	private function connect(): void {
		$this->store()->save_connection( new Connection( self::STORE_ID, self::secret() ) );
		$this->options[ $this->site() ][ RestKeys::OPTION_KEY_ID ] = self::KEY_ID;
		$this->status_cached[ $this->site() ]                      = true;
	}

	/**
	 * Connects another site of the network.
	 *
	 * @param int $site_id Site id.
	 */
	private function connect_site( int $site_id ): void {
		$this->site_stack[] = $site_id;
		$this->connect();
		array_pop( $this->site_stack );
	}

	private function deactivator(): Deactivator {
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

		$store = $this->store();

		return new Deactivator(
			new CoreClient( $store, self::BASE_URL ),
			$store,
			new RestKeys(),
			new Logger( $wc_logger ),
			fn(): float => $this->now,
			function (): void {
				if ( $this->stop_fails ) {
					throw new \RuntimeException( 'Queue table locked' );
				}
				$this->stopped[] = $this->site();
			}
		);
	}

	/**
	 * Whether a site still holds its connection, REST key id and cached status.
	 *
	 * @param int $site_id Site id.
	 */
	private function assert_site_kept( int $site_id ): void {
		self::assertArrayHasKey( SecretStore::OPTION_CONNECTION, $this->options[ $site_id ] );
		self::assertSame( self::KEY_ID, $this->options[ $site_id ][ RestKeys::OPTION_KEY_ID ] );
		self::assertTrue( $this->status_cached[ $site_id ] );
	}

	/**
	 * Whether a site's connection, REST key id and cached status are gone.
	 *
	 * @param int $site_id Site id.
	 */
	private function assert_site_cleaned( int $site_id ): void {
		self::assertArrayNotHasKey( SecretStore::OPTION_CONNECTION, $this->options[ $site_id ] );
		self::assertArrayNotHasKey( RestKeys::OPTION_KEY_ID, $this->options[ $site_id ] );
		self::assertFalse( $this->status_cached[ $site_id ] );
	}

	/**
	 * Level and code (or message) of each log entry.
	 *
	 * @return list<string>
	 */
	private function logged(): array {
		return array_map(
			fn( array $entry ): string => $entry['level'] . ':' . (string) ( $entry['context']['error_code'] ?? $entry['message'] ),
			$this->entries
		);
	}

	public function test_sends_a_signed_post_with_an_empty_body_and_a_short_timeout(): void {
		$this->connect();

		self::assertTrue( $this->deactivator()->deactivate() );

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/store/deactivate', $request['url'] );
		self::assertSame( 'POST', $request['args']['method'] );
		self::assertSame( Deactivator::TIMEOUT_SECONDS, $request['args']['timeout'] );
		self::assertArrayNotHasKey( 'body', $request['args'] );

		$headers = $request['args']['headers'];
		self::assertSame( self::STORE_ID, $headers[ HmacSigner::HEADER_SHOP_ID ] );
		self::assertSame(
			'sha256=' . hash_hmac( 'sha256', $headers[ HmacSigner::HEADER_TIMESTAMP ] . '.' . $headers[ HmacSigner::HEADER_NONCE ] . '.', self::secret() ),
			$headers[ HmacSigner::HEADER_SIGNATURE ]
		);
	}

	public function test_success_deletes_the_connection_the_rest_key_and_the_cache(): void {
		$this->connect();

		$this->deactivator()->deactivate();

		$this->assert_site_cleaned( self::MAIN_SITE );
		self::assertSame( array( 'wp_woocommerce_api_keys:' . self::KEY_ID ), $this->deleted_keys );
		self::assertSame( array( 'info:Store deactivated.' ), $this->logged() );
		self::assertSame( self::STORE_ID, $this->entries[0]['context']['store_id'] );
		self::assertSame( 'req-core-0001', $this->entries[0]['context']['request_id'] );
		self::assertStringNotContainsString( 'sec_', self::json( $this->entries ) );
	}

	public function test_success_without_a_remembered_key_deletes_no_key(): void {
		$this->connect();
		unset( $this->options[ self::MAIN_SITE ][ RestKeys::OPTION_KEY_ID ] );

		self::assertTrue( $this->deactivator()->deactivate() );

		self::assertSame( array(), $this->deleted_keys );
		self::assertArrayNotHasKey( SecretStore::OPTION_CONNECTION, $this->options[ self::MAIN_SITE ] );
	}

	public function test_a_key_that_cannot_be_deleted_keeps_its_id(): void {
		$this->connect();
		$this->key_delete_fails = true;

		self::assertTrue( $this->deactivator()->deactivate() );

		self::assertArrayNotHasKey( SecretStore::OPTION_CONNECTION, $this->options[ self::MAIN_SITE ] );
		self::assertSame( self::KEY_ID, $this->options[ self::MAIN_SITE ][ RestKeys::OPTION_KEY_ID ] );
		self::assertSame( array( 'warning:A WooNyx REST key could not be deleted.', 'info:Store deactivated.' ), $this->logged() );
	}

	public function test_not_connected_sends_and_deletes_nothing(): void {
		$this->options[ self::MAIN_SITE ][ RestKeys::OPTION_KEY_ID ] = self::KEY_ID;

		self::assertFalse( $this->deactivator()->deactivate() );

		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->deleted_keys );
		self::assertSame( self::KEY_ID, $this->options[ self::MAIN_SITE ][ RestKeys::OPTION_KEY_ID ] );
		self::assertSame( array(), $this->entries );
	}

	public function test_an_unreadable_connection_sends_and_deletes_nothing(): void {
		$this->options[ self::MAIN_SITE ][ SecretStore::OPTION_CONNECTION ] = 'v1:garbage';

		self::assertFalse( $this->deactivator()->deactivate() );

		self::assertSame( array(), $this->requests );
		self::assertSame( 'v1:garbage', $this->options[ self::MAIN_SITE ][ SecretStore::OPTION_CONNECTION ] );
	}

	/**
	 * Failed answers: status, body and the code logged.
	 *
	 * @return array<string, array{int, string, string}>
	 */
	public static function failures(): array {
		$error = static fn( string $code ): string => (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'message'    => 'Rejected.',
				'error_code' => $code,
			)
		);

		return array(
			'bad signature'   => array( 401, $error( 'invalid_request_signature' ), 'warning:invalid_request_signature' ),
			'not active'      => array( 403, $error( 'store_not_active' ), 'warning:store_not_active' ),
			'no subscription' => array( 403, $error( 'no_active_subscription' ), 'warning:no_active_subscription' ),
			'server error'    => array( 500, $error( 'internal_server_error' ), 'error:internal_server_error' ),
			'proxy page'      => array( 502, '<html>Bad gateway</html>', 'error:' . CoreError::UNKNOWN_ERROR ),
			'not json'        => array( 200, '<html>', 'warning:' . CoreError::INVALID_RESPONSE ),
		);
	}

	#[DataProvider( 'failures' )]
	public function test_a_failure_keeps_everything_and_logs_the_code( int $status, string $body, string $logged ): void {
		$this->connect();
		$this->next_response = self::response( $status, $body );

		self::assertFalse( $this->deactivator()->deactivate() );

		$this->assert_site_kept( self::MAIN_SITE );
		self::assertSame( array(), $this->deleted_keys );
		self::assertSame( array( $logged ), $this->logged() );
		self::assertSame( Deactivator::OPERATION, $this->entries[0]['context']['operation'] );
	}

	public function test_a_network_failure_keeps_everything(): void {
		$this->connect();
		$this->next_response = new \stdClass();

		self::assertFalse( $this->deactivator()->deactivate() );

		$this->assert_site_kept( self::MAIN_SITE );
		self::assertSame( array( 'error:' . CoreError::NETWORK_ERROR ), $this->logged() );
	}

	public function test_handle_never_throws(): void {
		$this->connect();
		Functions\when( 'wp_remote_request' )->alias(
			static function (): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test exception that must not reach the log.
				throw new \RuntimeException( 'Boom with ' . self::secret() );
			}
		);

		$this->deactivator()->handle( false, self::BASENAME );

		$this->assert_site_kept( self::MAIN_SITE );
		self::assertSame( array( 'error:The deactivation cleanup failed.' ), $this->logged() );
		self::assertSame( \RuntimeException::class, $this->entries[0]['context']['exception'] );
		self::assertStringNotContainsString( 'Boom', self::json( $this->entries ) );
		self::assertSame( array( self::MAIN_SITE ), $this->stopped );
	}

	public function test_a_single_site_deactivation_stops_the_catalog_even_without_a_connection(): void {
		$this->deactivator()->handle( false, self::BASENAME );

		self::assertSame( array(), $this->requests );
		self::assertSame( array( self::MAIN_SITE ), $this->stopped );
	}

	public function test_a_failed_deactivation_still_stops_the_catalog(): void {
		$this->connect();
		$this->next_response = new \stdClass();

		$this->deactivator()->handle( false, self::BASENAME );

		$this->assert_site_kept( self::MAIN_SITE );
		self::assertSame( array( self::MAIN_SITE ), $this->stopped );
	}

	public function test_deactivate_alone_does_not_stop_the_catalog(): void {
		$this->connect();

		self::assertTrue( $this->deactivator()->deactivate() );

		self::assertSame( array(), $this->stopped );
	}

	public function test_a_failure_to_stop_the_catalog_is_logged_and_never_stops_the_deactivation(): void {
		$this->multisite  = true;
		$this->sites      = array( 1, 2 );
		$this->stop_fails = true;
		$this->connect();
		$this->connect_site( 2 );

		$this->deactivator()->handle( true, self::BASENAME );

		$this->assert_site_cleaned( 1 );
		$this->assert_site_cleaned( 2 );
		self::assertSame(
			array( 'error:The deactivation cleanup failed.', 'info:Store deactivated.', 'error:The deactivation cleanup failed.', 'info:Store deactivated.' ),
			$this->logged()
		);
		self::assertStringNotContainsString( 'locked', self::json( $this->entries ) );
	}

	public function test_stopping_the_catalog_of_a_site_without_the_table_cancels_its_flushes_and_forgets_its_options(): void {
		$this->wpdb->shouldNotReceive( 'query' );
		$this->options[ self::MAIN_SITE ] = array(
			FlushScheduler::OPTION_RETRY_AT => 1_791_000_000,
			PlanLimitNotice::OPTION         => array(
				'count' => 3,
				'time'  => 1_791_000_000,
			),
			PullScheduler::OPTION_RETRY_AT  => 1_791_000_000,
			PullLock::OPTION                => str_repeat( 'a', 32 ) . ':1791000000',
			'blogname'                      => 'Shop',
		);

		Deactivator::stop_site_catalog();

		self::assertFalse( Schema::is_installed() );
		self::assertSame( array( self::MAIN_SITE ), $this->unscheduled );
		self::assertSame( array( self::MAIN_SITE . ':woonyx_sync_pull', self::MAIN_SITE . ':woonyx_sync_pull_now' ), $this->pulls_unscheduled );
		self::assertSame( array( 'blogname' => 'Shop' ), $this->options[ self::MAIN_SITE ] );
	}

	public function test_stopping_the_catalog_empties_the_current_sites_queue(): void {
		$queries = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( string $query, ...$args ): string => self::prepare_query( $query, ...$args ) );
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $query ) use ( &$queries ): int {
				$queries[] = $query;
				return 3;
			}
		);
		$this->site_stack[] = 2;
		$this->wpdb->prefix = $this->prefix();
		$this->options[2]   = array(
			Schema::OPTION_VERSION          => Schema::VERSION,
			FlushScheduler::OPTION_RETRY_AT => 1_791_000_000,
			PlanLimitNotice::OPTION         => array(
				'count' => 3,
				'time'  => 1_791_000_000,
			),
		);

		Deactivator::stop_site_catalog();

		self::assertSame( array( 'DELETE FROM `wp_2_woonyx_catalog_queue`' ), $queries );
		self::assertSame( array( 2 ), $this->unscheduled );
		self::assertSame( array( '2:woonyx_sync_pull', '2:woonyx_sync_pull_now' ), $this->pulls_unscheduled );
		self::assertSame( array( Schema::OPTION_VERSION => Schema::VERSION ), $this->options[2] );
	}

	public function test_a_single_site_deactivation_on_a_network_only_touches_that_site(): void {
		$this->multisite = true;
		$this->sites     = array( 1, 2 );
		$this->connect();
		$this->connect_site( 2 );

		$this->deactivator()->handle( false, self::BASENAME );

		self::assertSame( array( 1 ), array_column( $this->requests, 'site' ) );
		$this->assert_site_cleaned( 1 );
		$this->assert_site_kept( 2 );
	}

	public function test_a_network_deactivation_on_a_single_site_install_deactivates_this_site(): void {
		$this->connect();

		$this->deactivator()->handle( true, self::BASENAME );

		self::assertCount( 1, $this->requests );
		$this->assert_site_cleaned( self::MAIN_SITE );
	}

	public function test_a_network_deactivation_reaches_every_connected_site(): void {
		$this->multisite = true;
		$this->sites     = array( 1, 2, 3, 4 );
		$this->connect();
		$this->connect_site( 3 );
		$this->connect_site( 4 );
		// Site 4 activated the plugin on its own: it stays active there.
		$this->options[4]['active_plugins'] = array( 'other/other.php', self::BASENAME );

		$this->deactivator()->handle( true, self::BASENAME );

		self::assertSame( array( 1, 3 ), array_column( $this->requests, 'site' ) );
		$this->assert_site_cleaned( 1 );
		$this->assert_site_cleaned( 3 );
		$this->assert_site_kept( 4 );
		self::assertSame( array( 'wp_woocommerce_api_keys:9', 'wp_3_woocommerce_api_keys:9' ), $this->deleted_keys );
		self::assertSame( array( self::MAIN_SITE ), $this->site_stack );
		// Every site where the plugin stops, connected or not; not site 4.
		self::assertSame( array( 1, 2, 3 ), $this->stopped );
	}

	public function test_a_failed_site_does_not_stop_the_others(): void {
		$this->multisite = true;
		$this->sites     = array( 1, 2 );
		$this->connect();
		$this->connect_site( 2 );
		$this->next_response = new \stdClass();

		$this->deactivator()->handle( true, self::BASENAME );

		self::assertSame( array( 1, 2 ), array_column( $this->requests, 'site' ) );
		$this->assert_site_kept( 1 );
		$this->assert_site_kept( 2 );
		self::assertSame( array( self::MAIN_SITE ), $this->site_stack );
	}

	public function test_a_network_deactivation_stays_within_its_time_budget(): void {
		$this->multisite       = true;
		$this->sites           = array( 1, 2, 3 );
		$this->request_seconds = 25.0;
		$this->connect();
		$this->connect_site( 2 );
		$this->connect_site( 3 );

		$this->deactivator()->handle( true, self::BASENAME );

		self::assertSame( array( 1, 2 ), array_column( $this->requests, 'site' ) );
		self::assertSame( array( 10, 5 ), array_map( fn( array $request ) => $request['args']['timeout'], $this->requests ) );
		$this->assert_site_kept( 3 );
		self::assertSame( 'warning', $this->entries[2]['level'] );
		self::assertSame( 3, $this->entries[2]['context']['site_id'] );
		self::assertSame( array( self::MAIN_SITE ), $this->site_stack );
	}

	public function test_an_exception_on_one_site_restores_the_current_site(): void {
		$this->multisite = true;
		$this->sites     = array( 2 );
		$this->connect_site( 2 );
		Functions\when( 'wp_remote_request' )->alias(
			static function (): void {
				throw new \RuntimeException( 'Boom' );
			}
		);

		$this->deactivator()->handle( true, self::BASENAME );

		self::assertSame( array( self::MAIN_SITE ), $this->site_stack );
		self::assertSame( 'wp_', $this->wpdb->prefix );
		$this->assert_site_kept( 2 );
	}
}
