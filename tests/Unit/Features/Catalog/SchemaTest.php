<?php
/**
 * Tests for the catalog queue's table.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Catalog;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use Nyxify\Features\Catalog\Schema;
use Nyxify\Tests\Unit\EmulatesPrepare;
use Nyxify\Tests\Unit\TestCase;

/**
 * The table is created only when the stored version differs, the version is stored only once the table exists, and
 * the uninstall drops it. dbDelta() and $wpdb are mocked.
 */
final class SchemaTest extends TestCase {

	use EmulatesPrepare;

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Autoload flags given to update_option().
	 *
	 * @var array<string, mixed>
	 */
	private array $autoload = array();

	/**
	 * Statements given to dbDelta().
	 *
	 * @var list<string>
	 */
	private array $deltas = array();

	/**
	 * Queries sent through $wpdb->query() and get_var().
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * What SHOW TABLES finds.
	 *
	 * @var string|null
	 */
	private ?string $found = 'wp_nyxify_catalog_queue';

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

		Functions\when( 'get_option' )->alias( fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'dbDelta' )->alias(
			function ( string $sql ): array {
				$this->deltas[] = $sql;
				return array();
			}
		);

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
		Functions\when( 'wc_get_logger' )->justReturn( $wc_logger );

		$this->wpdb         = Mockery::mock( \wpdb::class );
		$this->wpdb->prefix = 'wp_';
		$this->wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing( fn( string $text ): string => addcslashes( $text, '_%\\' ) );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( string $query, ...$args ): string => self::prepare_query( $query, ...$args ) );
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $query ): ?string {
				$this->queries[] = $query;
				return $this->found;
			}
		);
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $query ): bool {
				$this->queries[] = $query;
				return true;
			}
		);
		$GLOBALS['wpdb'] = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_the_definition_is_in_dbdeltas_format(): void {
		self::assertSame(
			"CREATE TABLE wp_nyxify_catalog_queue (\n"
			. "kind varchar(20) NOT NULL,\n"
			. "object_id bigint(20) unsigned NOT NULL,\n"
			. "queued_at datetime NOT NULL,\n"
			. "claim char(32) DEFAULT NULL,\n"
			. "claimed_at datetime DEFAULT NULL,\n"
			. "dirty tinyint(1) NOT NULL DEFAULT 0,\n"
			. "attempts smallint(5) unsigned NOT NULL DEFAULT 0,\n"
			. "PRIMARY KEY  (kind,object_id),\n"
			. "KEY claim (claim)\n"
			. ') DEFAULT CHARSET=utf8mb4;',
			Schema::definition( 'wp_nyxify_catalog_queue', 'DEFAULT CHARSET=utf8mb4' )
		);
	}

	public function test_the_table_name_follows_the_current_sites_prefix(): void {
		self::assertSame( 'wp_nyxify_catalog_queue', Schema::table_name() );

		$this->wpdb->prefix = 'wp_2_';
		self::assertSame( 'wp_2_nyxify_catalog_queue', Schema::table_name() );
	}

	public function test_a_new_site_gets_the_table_and_the_version(): void {
		Schema::maybe_install();

		self::assertSame(
			array( Schema::definition( 'wp_nyxify_catalog_queue', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' ) ),
			$this->deltas
		);
		self::assertSame( array( "SHOW TABLES LIKE 'wp\\\\_nyxify\\\\_catalog\\\\_queue'" ), $this->queries );
		self::assertSame( Schema::VERSION, $this->options[ Schema::OPTION_VERSION ] );
		self::assertTrue( $this->autoload[ Schema::OPTION_VERSION ] );
		self::assertTrue( Schema::is_installed() );
		self::assertSame( array(), $this->entries );
	}

	public function test_the_current_version_does_nothing(): void {
		$this->options[ Schema::OPTION_VERSION ] = Schema::VERSION;

		Schema::maybe_install();

		self::assertSame( array(), $this->deltas );
		self::assertSame( array(), $this->queries );
	}

	public function test_an_older_version_is_upgraded(): void {
		$this->options[ Schema::OPTION_VERSION ] = '0';

		Schema::maybe_install();

		self::assertCount( 1, $this->deltas );
		self::assertSame( Schema::VERSION, $this->options[ Schema::OPTION_VERSION ] );
	}

	public function test_a_table_that_was_not_created_stores_no_version_and_is_logged(): void {
		$this->found = null;

		self::assertFalse( Schema::install() );

		self::assertArrayNotHasKey( Schema::OPTION_VERSION, $this->options );
		self::assertFalse( Schema::is_installed() );
		self::assertCount( 1, $this->entries );
		self::assertSame( 'warning', $this->entries[0]['level'] );
		self::assertSame( 'The catalog queue table could not be created.', $this->entries[0]['message'] );
		self::assertSame( 'wp_nyxify_catalog_queue', $this->entries[0]['context']['table'] );
	}

	public function test_drop_removes_the_table_and_its_version(): void {
		$this->options[ Schema::OPTION_VERSION ] = Schema::VERSION;
		$this->wpdb->prefix                      = 'wp_4_';

		Schema::drop();

		self::assertSame( array( 'DROP TABLE IF EXISTS `wp_4_nyxify_catalog_queue`' ), $this->queries );
		self::assertArrayNotHasKey( Schema::OPTION_VERSION, $this->options );
	}
}
