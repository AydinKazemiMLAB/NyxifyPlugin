<?php
/**
 * Tests for the uninstall cleanup.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Nyxify\Features\Catalog\FlushScheduler;
use Nyxify\Features\Catalog\PlanLimitNotice;
use Nyxify\Features\Catalog\Schema;
use Nyxify\Features\Store\RestKeys;
use Nyxify\Features\Store\StatusService;
use Nyxify\Features\Sync\PullLock;
use Nyxify\Features\Sync\PullScheduler;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Uninstaller;

/**
 * The plugin's REST key, options and transients are deleted, on one site or on each site of a network, and nothing is
 * sent to the core. Options and transients are kept per site; $wpdb is mocked.
 */
final class UninstallerTest extends TestCase {

	use EmulatesPrepare;

	private const MAIN_SITE = 1;

	/**
	 * In-memory wp_options per site: site id => name => value.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $options = array();

	/**
	 * Transients deleted, as "site:name".
	 *
	 * @var list<string>
	 */
	private array $deleted_transients = array();

	/**
	 * REST keys deleted, as "table:key id".
	 *
	 * @var list<string>
	 */
	private array $deleted_keys = array();

	/**
	 * Queries run through $wpdb->query().
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * Calls to $wpdb->suppress_errors().
	 *
	 * @var list<bool>
	 */
	private array $suppress_calls = array();

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
	 * Post meta deleted by key, as "site:key".
	 *
	 * @var list<string>
	 */
	private array $deleted_meta = array();

	/**
	 * Log sources cleared in WooCommerce's log, as "site:source".
	 *
	 * @var list<string>
	 */
	private array $cleared_logs = array();

	/**
	 * The current site, and the sites switched from.
	 *
	 * @var list<int>
	 */
	private array $site_stack = array( self::MAIN_SITE );

	/**
	 * Mocked database.
	 *
	 * @var \wpdb&MockInterface
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $this->site() ][ $name ] ?? $default_value
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $this->site() ][ $name ] );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				$this->deleted_transients[] = $this->site() . ':' . $name;
				return true;
			}
		);
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $site_id ): bool {
				$this->site_stack[] = $site_id;
				$this->set_prefix();
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				array_pop( $this->site_stack );
				$this->set_prefix();
				return true;
			}
		);
		Functions\expect( 'wp_remote_request' )->never();
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook, array $args, string $group ): void {
				self::assertSame( array( array(), 'nyxify' ), array( $args, $group ) );
				if ( FlushScheduler::HOOK === $hook ) {
					$this->unscheduled[] = $this->site();
					return;
				}
				self::assertContains( $hook, array( PullScheduler::HOOK, PullScheduler::NOW_HOOK ) );
				$this->pulls_unscheduled[] = $this->site() . ':' . $hook;
			}
		);
		Functions\when( 'delete_post_meta_by_key' )->alias(
			function ( string $key ): bool {
				$this->deleted_meta[] = $this->site() . ':' . $key;
				return true;
			}
		);
		// WooCommerce's WC_Logger isn't loaded here; its clear() is all the cleanup uses.
		$record = function ( string $source ): void {
			$this->cleared_logs[] = $this->site() . ':' . $source;
		};
		$logger = new class( $record ) {
			/**
			 * Records a cleared source.
			 *
			 * @var \Closure
			 */
			private \Closure $record;

			public function __construct( \Closure $record ) {
				$this->record = $record;
			}

			public function clear( string $source ): bool {
				( $this->record )( $source );
				return true;
			}
		};
		Functions\when( 'wc_get_logger' )->justReturn( $logger );

		$this->wpdb = Mockery::mock( \wpdb::class );
		$this->set_prefix();
		$this->wpdb->shouldReceive( 'delete' )->andReturnUsing(
			function ( string $table, array $where ) {
				$this->deleted_keys[] = $table . ':' . $where['key_id'];
				return 1;
			}
		);
		$this->wpdb->shouldReceive( 'suppress_errors' )->andReturnUsing(
			function ( bool $suppress ): bool {
				$this->suppress_calls[] = $suppress;
				return false;
			}
		);
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn( string $text ): string => addcslashes( $text, '_%\\' ) );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( string $query, ...$args ): string => self::prepare_query( $query, ...$args ) );
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $query ): int {
				$this->queries[] = $query;
				return 0;
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
	 * Sets the table prefixes of the current site, as WordPress does.
	 */
	private function set_prefix(): void {
		$this->wpdb->prefix  = self::MAIN_SITE === $this->site() ? 'wp_' : 'wp_' . $this->site() . '_';
		$this->wpdb->options = $this->wpdb->prefix . 'options';
	}

	/**
	 * Everything the plugin stores on a site, plus an option of someone else.
	 *
	 * @param int $site_id Site id.
	 * @param int $key_id  Id of the plugin's REST key.
	 */
	private function install( int $site_id, int $key_id = 9 ): void {
		$this->options[ $site_id ] = array(
			SecretStore::OPTION_CONNECTION  => 'v1:connection',
			SecretStore::OPTION_ADMIN_PIN   => 'v1:pin',
			RestKeys::OPTION_KEY_ID         => $key_id,
			Schema::OPTION_VERSION          => Schema::VERSION,
			FlushScheduler::OPTION_RETRY_AT => 1791892800,
			'blogname'                      => 'Shop',
		);
	}

	/**
	 * The query that deletes the notices of a site.
	 *
	 * @param string $prefix Table prefix.
	 */
	private static function notices_query( string $prefix ): string {
		return "DELETE FROM {$prefix}options WHERE option_name LIKE '\\\\_transient\\\\_nyxify\\\\_notice\\\\_%' OR option_name LIKE '\\\\_transient\\\\_timeout\\\\_nyxify\\\\_notice\\\\_%'";
	}

	/**
	 * The queries that clean a site: its queue table, then its notices.
	 *
	 * @param string $prefix Table prefix.
	 * @return list<string>
	 */
	private static function site_queries( string $prefix ): array {
		return array( "DROP TABLE IF EXISTS `{$prefix}nyxify_catalog_queue`", self::notices_query( $prefix ) );
	}

	public function test_single_site_deletes_the_rest_key_options_and_transients(): void {
		$this->install( self::MAIN_SITE );

		Uninstaller::run();

		self::assertSame( array( 'wp_woocommerce_api_keys:9' ), $this->deleted_keys );
		self::assertSame( array( 'blogname' => 'Shop' ), $this->options[ self::MAIN_SITE ] );
		self::assertSame( array( '1:' . StatusService::TRANSIENT ), $this->deleted_transients );
		self::assertSame( self::site_queries( 'wp_' ), $this->queries );
		self::assertSame( array( self::MAIN_SITE ), $this->unscheduled );
		self::assertSame( array( self::MAIN_SITE . ':nyxify_sync_pull', self::MAIN_SITE . ':nyxify_sync_pull_now' ), $this->pulls_unscheduled );
		self::assertSame( array( '1:_nyxify_outbox_id' ), $this->deleted_meta );
		self::assertSame( array( '1:nyxify' ), $this->cleared_logs );
	}

	public function test_a_logger_without_clear_is_left_alone(): void {
		Functions\when( 'wc_get_logger' )->justReturn( Mockery::mock( \WC_Logger_Interface::class ) );
		$this->install( self::MAIN_SITE );

		Uninstaller::run();

		self::assertSame( array(), $this->cleared_logs );
		self::assertSame( array( 'blogname' => 'Shop' ), $this->options[ self::MAIN_SITE ] );
	}

	public function test_database_errors_are_hidden_while_deleting_the_key_and_restored(): void {
		$this->install( self::MAIN_SITE );

		Uninstaller::run();

		self::assertSame( array( true, false ), $this->suppress_calls );
	}

	public function test_without_a_remembered_key_no_key_is_deleted(): void {
		$this->install( self::MAIN_SITE );
		unset( $this->options[ self::MAIN_SITE ][ RestKeys::OPTION_KEY_ID ] );

		Uninstaller::run();

		self::assertSame( array(), $this->deleted_keys );
		self::assertSame( array(), $this->suppress_calls );
		self::assertSame( array( 'blogname' => 'Shop' ), $this->options[ self::MAIN_SITE ] );
	}

	public function test_a_site_that_never_connected_is_cleaned_too(): void {
		Uninstaller::run();

		self::assertSame( array(), $this->deleted_keys );
		self::assertSame( array( '1:' . StatusService::TRANSIENT ), $this->deleted_transients );
		self::assertSame( self::site_queries( 'wp_' ), $this->queries );
	}

	public function test_every_option_and_transient_is_listed(): void {
		self::assertSame(
			array( SecretStore::OPTION_CONNECTION, SecretStore::OPTION_ADMIN_PIN, RestKeys::OPTION_KEY_ID, Schema::OPTION_VERSION, FlushScheduler::OPTION_RETRY_AT, PlanLimitNotice::OPTION, PullScheduler::OPTION_RETRY_AT, PullLock::OPTION ),
			Uninstaller::OPTIONS
		);
		self::assertSame( array( StatusService::TRANSIENT ), Uninstaller::TRANSIENTS );
	}

	public function test_multisite_cleans_each_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->alias(
			function ( array $args ): array {
				self::assertSame( 'ids', $args['fields'] );
				self::assertSame( 0, $args['number'] );
				return array( '1', '2', '3' );
			}
		);
		$this->install( 1, 9 );
		$this->install( 2, 14 );

		Uninstaller::run();

		self::assertSame( array( 'wp_woocommerce_api_keys:9', 'wp_2_woocommerce_api_keys:14' ), $this->deleted_keys );
		self::assertSame( array( 'blogname' => 'Shop' ), $this->options[1] );
		self::assertSame( array( 'blogname' => 'Shop' ), $this->options[2] );
		self::assertSame(
			array( '1:' . StatusService::TRANSIENT, '2:' . StatusService::TRANSIENT, '3:' . StatusService::TRANSIENT ),
			$this->deleted_transients
		);
		self::assertSame(
			array_merge( self::site_queries( 'wp_' ), self::site_queries( 'wp_2_' ), self::site_queries( 'wp_3_' ) ),
			$this->queries
		);
		self::assertSame( array( 1, 2, 3 ), $this->unscheduled );
		self::assertSame( array( '1:nyxify_sync_pull', '1:nyxify_sync_pull_now', '2:nyxify_sync_pull', '2:nyxify_sync_pull_now', '3:nyxify_sync_pull', '3:nyxify_sync_pull_now' ), $this->pulls_unscheduled );
		self::assertSame( array( '1:_nyxify_outbox_id', '2:_nyxify_outbox_id', '3:_nyxify_outbox_id' ), $this->deleted_meta );
		self::assertSame( array( '1:nyxify', '2:nyxify', '3:nyxify' ), $this->cleared_logs );
		self::assertSame( array( self::MAIN_SITE ), $this->site_stack );
		self::assertSame( 'wp_', $this->wpdb->prefix );
	}

	public function test_a_failing_site_still_restores_the_current_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( array( 2 ) );
		Functions\when( 'delete_transient' )->alias(
			static function (): bool {
				throw new \RuntimeException( 'cache down' );
			}
		);

		try {
			Uninstaller::run();
			self::fail( 'The exception was swallowed.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'cache down', $e->getMessage() );
		}

		self::assertSame( array( self::MAIN_SITE ), $this->site_stack );
	}
}
