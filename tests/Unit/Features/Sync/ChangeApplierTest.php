<?php
/**
 * Tests for ChangeApplier.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Sync;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Store\RestKeys;
use Nyxify\Features\Sync\ChangeApplier;
use Nyxify\Features\Sync\ChangeResult;
use Nyxify\Features\Sync\PayloadFilter;
use Nyxify\Features\Sync\PulledChange;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Tests\Unit\EmulatesPrepare;
use Nyxify\Tests\Unit\TestCase;

/**
 * One change through a fake in-process REST dispatcher (standing in for rest_do_request() and WooCommerce's
 * controllers), with the real filter and REST key lookup over a mocked $wpdb, user functions and WooCommerce logger.
 */
final class ChangeApplierTest extends TestCase {

	use EmulatesPrepare;

	private const KEY_USER = 5;

	private const SIGNED_LINK = 'https://bot.example.com:88/media/0123456789abcdef0123456789abcdef.jpg?expires=1760170530&signature=9f8e7d';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array( RestKeys::OPTION_KEY_ID => 17 );

	/**
	 * The key row's user_id, as get_var() returns it.
	 *
	 * @var mixed
	 */
	private $key_user = '5';

	/**
	 * Number of key row reads.
	 *
	 * @var int
	 */
	private int $key_reads = 0;

	/**
	 * Whether get_userdata() finds the key's user.
	 *
	 * @var bool
	 */
	private bool $user_exists = true;

	/**
	 * The current user.
	 *
	 * @var int
	 */
	private int $current_user = 1;

	/**
	 * What get_posts() returns for the outbox meta lookup.
	 *
	 * @var list<int>
	 */
	private array $existing = array();

	/**
	 * Arguments of each get_posts() call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $lookups = array();

	/**
	 * Each dispatched request: method, route, body, the current user and whether the timeout cap was on.
	 *
	 * @var list<array{method: string, route: string, body: array<string, mixed>, user: int, capped: bool}>
	 */
	private array $requests = array();

	/**
	 * What the dispatcher answers: status and data, or an exception to throw.
	 *
	 * @var array{int, mixed}|\Throwable
	 */
	private $answer = array( 200, array( 'id' => 13 ) );

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	/**
	 * The applier under test (the dispatcher looks at its filter).
	 *
	 * @var ChangeApplier|null
	 */
	private ?ChangeApplier $applier = null;

	protected function setUp(): void {
		parent::setUp();

		$wpdb         = Mockery::mock( \wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( string $query, ...$args ): string => self::prepare_query( $query, ...$args ) );
		$wpdb->shouldReceive( 'get_var' )
			->with( 'SELECT user_id FROM `wp_woocommerce_api_keys` WHERE key_id = 17' )
			->andReturnUsing(
				function () {
					++$this->key_reads;
					return $this->key_user;
				}
			);
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.

		Functions\when( 'get_option' )->alias( fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value );
		Functions\when( 'get_userdata' )->alias( fn( int $id ) => $this->user_exists && self::KEY_USER === $id ? (object) array( 'ID' => $id ) : false );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->current_user );
		Functions\when( 'wp_set_current_user' )->alias(
			function ( int $id ) {
				$this->current_user = $id;
				return (object) array( 'ID' => $id );
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				$this->lookups[] = $args;
				return $this->existing;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function applier(): ChangeApplier {
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

		$this->applier = new ChangeApplier(
			new PayloadFilter(),
			new RestKeys(),
			new Logger( $wc_logger ),
			function ( string $method, string $route, array $body ): array {
				$this->requests[] = array(
					'method' => $method,
					'route'  => $route,
					'body'   => $body,
					'user'   => $this->current_user,
					'capped' => false !== has_filter( 'http_request_args', array( $this->applier, 'cap_timeout' ) ),
				);
				if ( $this->answer instanceof \Throwable ) {
					throw $this->answer;
				}
				return $this->answer;
			}
		);

		return $this->applier;
	}

	/**
	 * A change as the core sends it.
	 *
	 * @param string               $action     Action.
	 * @param array<string, mixed> $payload    Payload.
	 * @param int|null             $product_id Product id.
	 * @param int|null             $variation  Variation id.
	 * @param int                  $id         Outbox id.
	 */
	private static function change( string $action, array $payload = array(), ?int $product_id = 13, ?int $variation = null, int $id = 41 ): PulledChange {
		$change = PulledChange::from_response(
			array(
				'id'               => $id,
				'action'           => $action,
				'woo_product_id'   => $product_id,
				'woo_variation_id' => $variation,
				'payload'          => $payload,
				'created_at'       => '2026-10-11T08:15:30Z',
			)
		);
		self::assertNotNull( $change );

		return $change;
	}

	private static function update(): PulledChange {
		return self::change(
			PulledChange::PRODUCT_UPDATE,
			array(
				'name'   => 'کفش راحتی',
				'images' => array( array( 'src' => self::SIGNED_LINK ) ),
			)
		);
	}

	private static function create(): PulledChange {
		return self::change(
			PulledChange::PRODUCT_CREATE,
			array(
				'type'      => 'simple',
				'name'      => 'کیف',
				'meta_data' => array(
					array(
						'key'   => PayloadFilter::OUTBOX_META_KEY,
						'value' => '41',
					),
				),
			),
			null
		);
	}

	/**
	 * A WooCommerce error answer.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return array{int, array<string, mixed>}
	 */
	private static function error( int $status, string $code, string $message = 'Refused.' ): array {
		return array(
			$status,
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
		);
	}

	/**
	 * Asserts the user and the cap were in place during the request and are back as before afterwards.
	 */
	private function assert_applied_as_the_key_user_and_restored(): void {
		self::assertNotEmpty( $this->requests );
		foreach ( $this->requests as $request ) {
			self::assertSame( self::KEY_USER, $request['user'] );
			self::assertTrue( $request['capped'] );
		}
		self::assertSame( 1, $this->current_user );
		self::assertFalse( has_filter( 'http_request_args', array( $this->applier, 'cap_timeout' ) ) );
	}

	/**
	 * No product content, link or message in any log entry.
	 */
	private function assert_logs_hold_no_content(): void {
		$logged = (string) json_encode( $this->entries, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		foreach ( array( 'کفش', 'کیف', 'bot.example.com', 'signature', 'Refused', 'secret detail' ) as $content ) {
			self::assertStringNotContainsString( $content, $logged );
		}
	}

	// ------------------------------------------------------------------ requests

	/**
	 * Each action's method, route and body.
	 *
	 * @return array<string, array{PulledChange, string, string, array<string, mixed>}>
	 */
	public static function requests(): array {
		return array(
			'create'    => array(
				self::create(),
				'POST',
				'/wc/v3/products',
				array(
					'type'      => 'simple',
					'name'      => 'کیف',
					'meta_data' => array(
						array(
							'key'   => '_nyxify_outbox_id',
							'value' => '41',
						),
					),
				),
			),
			'update'    => array(
				self::update(),
				'PUT',
				'/wc/v3/products/13',
				array(
					'name'   => 'کفش راحتی',
					'images' => array( array( 'src' => self::SIGNED_LINK ) ),
				),
			),
			'delete'    => array( self::change( PulledChange::PRODUCT_DELETE ), 'DELETE', '/wc/v3/products/13', array() ),
			'variation' => array(
				self::change( PulledChange::VARIATION_UPDATE, array( 'stock_quantity' => 3 ), 13, 70 ),
				'PUT',
				'/wc/v3/products/13/variations/70',
				array( 'stock_quantity' => 3 ),
			),
		);
	}

	/**
	 * Each change goes to its route with its checked body, as the key's user and with the cap.
	 *
	 * @param PulledChange         $change The change.
	 * @param string               $method Expected method.
	 * @param string               $route  Expected route.
	 * @param array<string, mixed> $body   Expected body.
	 */
	#[DataProvider( 'requests' )]
	public function test_each_action_is_sent_to_its_route( PulledChange $change, string $method, string $route, array $body ): void {
		$this->answer = array( 201, array( 'id' => 77 ) );

		$result = $this->applier()->apply( $change );

		self::assertTrue( $result->success );
		self::assertCount( 1, $this->requests );
		self::assertSame( $method, $this->requests[0]['method'] );
		self::assertSame( $route, $this->requests[0]['route'] );
		self::assertSame( $body, $this->requests[0]['body'] );
		$this->assert_applied_as_the_key_user_and_restored();
		self::assertSame( array(), $this->entries );
	}

	public function test_an_update_reports_its_product(): void {
		$result = $this->applier()->apply( self::update() );

		self::assertSame(
			array(
				'id'             => 41,
				'success'        => true,
				'woo_product_id' => 13,
			),
			$result->to_ack()
		);
	}

	public function test_a_variation_edit_reports_its_parent(): void {
		$this->answer = array( 200, array( 'id' => 70 ) );

		$result = $this->applier()->apply( self::change( PulledChange::VARIATION_UPDATE, array( 'sku' => 'A' ), 13, 70 ) );

		self::assertTrue( $result->success );
		self::assertSame( 13, $result->woo_product_id );
	}

	public function test_a_create_reports_the_created_product(): void {
		$this->answer = array( 201, array( 'id' => 77 ) );

		$result = $this->applier()->apply( self::create() );

		self::assertTrue( $result->success );
		self::assertSame( 77, $result->woo_product_id );
	}

	/**
	 * Successful create answers without a usable product id.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function answers_without_an_id(): array {
		return array(
			'no id'      => array( array( 'name' => 'x' ) ),
			'id as text' => array( array( 'id' => '77' ) ),
			'id zero'    => array( array( 'id' => 0 ) ),
			'not data'   => array( null ),
		);
	}

	#[DataProvider( 'answers_without_an_id' )]
	public function test_a_create_answered_without_a_product_id_fails( mixed $data ): void {
		$this->answer = array( 201, $data );

		$result = $this->applier()->apply( self::create() );

		self::assertFalse( $result->success );
		self::assertSame( ChangeResult::WOOCOMMERCE_ERROR, $result->error_code );
	}

	// ------------------------------------------------------------------ create lookup

	public function test_a_create_already_applied_is_reported_without_creating_again(): void {
		$this->existing = array( 88 );

		$result = $this->applier()->apply( self::create() );

		self::assertTrue( $result->success );
		self::assertSame( 88, $result->woo_product_id );
		self::assertSame( array(), $this->requests );
		self::assertSame( 1, $this->current_user );
		self::assertSame( 'info', $this->entries[0]['level'] );
		self::assertSame(
			array(
				'change_id'  => 41,
				'product_id' => 88,
				'source'     => 'nyxify',
			),
			$this->entries[0]['context']
		);
	}

	public function test_the_lookup_finds_the_changes_outbox_id_in_any_status_and_the_trash(): void {
		$this->answer = array( 201, array( 'id' => 77 ) );

		$this->applier()->apply( self::create() );

		self::assertCount( 1, $this->lookups );
		$args = $this->lookups[0];
		self::assertSame( 'product', $args['post_type'] );
		self::assertSame( array( 'any', 'trash' ), $args['post_status'] );
		self::assertSame( '_nyxify_outbox_id', $args['meta_key'] );
		self::assertSame( '41', $args['meta_value'] );
		self::assertSame( 'ids', $args['fields'] );
		self::assertSame( 1, $args['posts_per_page'] );
		self::assertArrayNotHasKey( 'suppress_filters', $args ); // get_posts() suppresses filters by default.
		self::assertCount( 1, $this->requests );
	}

	public function test_only_creates_are_looked_up(): void {
		$this->applier()->apply( self::update() );
		$this->applier()->apply( self::change( PulledChange::PRODUCT_DELETE ) );

		self::assertSame( array(), $this->lookups );
	}

	// ------------------------------------------------------------------ deletes

	/**
	 * Delete answers that mean the product is already in the trash or gone.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function deleted_already(): array {
		return array(
			'already trashed' => array( 410, 'woocommerce_rest_already_trashed' ),
			'gone'            => array( 404, 'woocommerce_rest_product_invalid_id' ),
		);
	}

	#[DataProvider( 'deleted_already' )]
	public function test_a_delete_of_a_product_already_trashed_or_gone_succeeds( int $status, string $code ): void {
		$this->answer = self::error( $status, $code );

		$result = $this->applier()->apply( self::change( PulledChange::PRODUCT_DELETE ) );

		self::assertTrue( $result->success );
		self::assertSame( 13, $result->woo_product_id );
	}

	public function test_other_delete_errors_fail(): void {
		$this->answer = self::error( 501, 'woocommerce_rest_trash_not_supported', 'The product does not support trashing.' );

		$result = $this->applier()->apply( self::change( PulledChange::PRODUCT_DELETE ) );

		self::assertFalse( $result->success );
		self::assertSame( 'woocommerce_rest_trash_not_supported', $result->error_code );
	}

	public function test_a_missing_product_fails_an_update(): void {
		$this->answer = self::error( 400, 'woocommerce_rest_product_invalid_id', 'Invalid ID.' );

		$result = $this->applier()->apply( self::update() );

		self::assertFalse( $result->success );
		self::assertSame( 'woocommerce_rest_product_invalid_id', $result->error_code );
		self::assertSame( 'Invalid ID.', $result->error_message );
	}

	// ------------------------------------------------------------------ errors

	public function test_an_image_download_error_loses_its_signed_link(): void {
		$this->answer = self::error(
			400,
			'woocommerce_product_image_upload_error',
			'Error getting remote image ' . self::SIGNED_LINK . '. Error: cURL error 28: Operation timed out after 15001 milliseconds'
		);

		$result = $this->applier()->apply( self::update() );

		self::assertSame( 'woocommerce_product_image_upload_error', $result->error_code );
		self::assertSame( 'Error getting remote image [link] Error: cURL error 28: Operation timed out after 15001 milliseconds', $result->error_message );
		$this->assert_applied_as_the_key_user_and_restored();
		$this->assert_logs_hold_no_content();
	}

	public function test_tags_and_every_link_are_removed_from_a_message(): void {
		$this->answer = self::error(
			400,
			'woocommerce_rest_invalid_image',
			"<p>Bad\n file <a href=\"https://shop.example.com/wp-admin/upload.php\">here</a> and http://other.example.com/x?a=1.</p><script>alert('secret detail')</script>"
		);

		$result = $this->applier()->apply( self::update() );

		self::assertSame( 'Bad file here and [link]', $result->error_message );
	}

	public function test_a_long_message_is_cut_to_a_thousand_characters(): void {
		$this->answer = self::error( 400, 'product_invalid_sku', str_repeat( 'تکراری', 200 ) );

		$result = $this->applier()->apply( self::update() );

		self::assertSame( ChangeResult::MAX_MESSAGE_LENGTH, mb_strlen( $result->error_message, 'UTF-8' ) );
	}

	/**
	 * Error codes the core can't take.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function unusable_codes(): array {
		return array(
			'upper case' => array( 'Product_Invalid_SKU' ),
			'a dash'     => array( 'rest-error' ),
			'too long'   => array( str_repeat( 'a', 101 ) ),
			'a number'   => array( 400 ),
			'missing'    => array( null ),
		);
	}

	#[DataProvider( 'unusable_codes' )]
	public function test_a_code_the_core_cant_take_becomes_woocommerce_error( mixed $code ): void {
		$this->answer = array(
			400,
			array(
				'code'    => $code,
				'message' => 'Refused.',
			),
		);

		$result = $this->applier()->apply( self::update() );

		self::assertFalse( $result->success );
		self::assertSame( ChangeResult::WOOCOMMERCE_ERROR, $result->error_code );
		self::assertSame( 'Refused.', $result->error_message );
	}

	public function test_an_error_without_a_message_names_its_status(): void {
		$this->answer = array( 500, '<html>Fatal</html>' );

		$result = $this->applier()->apply( self::update() );

		self::assertSame( ChangeResult::WOOCOMMERCE_ERROR, $result->error_code );
		self::assertSame( 'WooCommerce answered with HTTP 500.', $result->error_message );
	}

	public function test_errors_are_not_logged_here(): void {
		$this->answer = self::error( 400, 'product_invalid_sku', 'Invalid or duplicated SKU.' );

		$this->applier()->apply( self::update() );

		self::assertSame( array(), $this->entries );
	}

	// ------------------------------------------------------------------ the plugin's failures

	public function test_an_exception_is_a_plugin_error_with_only_its_class_logged(): void {
		$this->answer = new \RuntimeException( 'secret detail of کفش at ' . self::SIGNED_LINK );

		$result = $this->applier()->apply( self::update() );

		self::assertFalse( $result->success );
		self::assertSame( ChangeResult::PLUGIN_ERROR, $result->error_code );
		self::assertSame( ChangeApplier::PLUGIN_ERROR_MESSAGE, $result->error_message );
		$this->assert_applied_as_the_key_user_and_restored();

		self::assertCount( 1, $this->entries );
		self::assertSame( 'error', $this->entries[0]['level'] );
		self::assertSame(
			array(
				'change_id' => 41,
				'action'    => 'product.update',
				'exception' => 'RuntimeException',
				'source'    => 'nyxify',
			),
			$this->entries[0]['context']
		);
		$this->assert_logs_hold_no_content();
	}

	public function test_an_error_answer_restores_the_user_and_removes_the_cap(): void {
		$this->answer = self::error( 400, 'product_invalid_sku' );

		$this->applier()->apply( self::update() );

		$this->assert_applied_as_the_key_user_and_restored();
	}

	public function test_an_invalid_change_is_refused_without_writing_or_switching_user(): void {
		$result = $this->applier()->apply( self::change( PulledChange::PRODUCT_UPDATE, array( 'weight' => '2' ) ) );

		self::assertSame( ChangeResult::INVALID_CHANGE, $result->error_code );
		self::assertSame( array(), $this->requests );
		self::assertSame( 0, $this->key_reads );
		self::assertSame( 1, $this->current_user );
	}

	/**
	 * Ways the REST key's user can be missing.
	 *
	 * @return array<string, array{string}>
	 */
	public static function missing_users(): array {
		return array(
			'no key remembered' => array( 'option' ),
			'key row gone'      => array( 'row' ),
			'user deleted'      => array( 'user' ),
		);
	}

	#[DataProvider( 'missing_users' )]
	public function test_without_the_rest_keys_user_nothing_is_written( string $missing ): void {
		if ( 'option' === $missing ) {
			$this->options = array();
		} elseif ( 'row' === $missing ) {
			$this->key_user = null;
		} else {
			$this->user_exists = false;
		}

		$result = $this->applier()->apply( self::create() );

		self::assertFalse( $result->success );
		self::assertSame( ChangeResult::REST_USER_MISSING, $result->error_code );
		self::assertSame( ChangeApplier::REST_USER_MISSING_MESSAGE, $result->error_message );
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->lookups );
		self::assertSame( 1, $this->current_user );
	}

	public function test_the_rest_keys_user_is_read_once_per_applier(): void {
		$applier = $this->applier();

		$applier->apply( self::update() );
		$applier->apply( self::change( PulledChange::PRODUCT_DELETE ) );
		$applier->apply( self::change( PulledChange::VARIATION_UPDATE, array( 'sku' => 'A' ), 13, 70 ) );

		self::assertSame( 1, $this->key_reads );
		self::assertCount( 3, $this->requests );
	}

	// ------------------------------------------------------------------ the timeout cap

	/**
	 * Request arguments before and after the cap.
	 *
	 * @return array<string, array{mixed, mixed}>
	 */
	public static function timeouts(): array {
		return array(
			'image download default' => array( array( 'timeout' => 300 ), array( 'timeout' => 15 ) ),
			'longer as a float'      => array( array( 'timeout' => 20.5 ), array( 'timeout' => 15 ) ),
			'shorter kept'           => array( array( 'timeout' => 5 ), array( 'timeout' => 5 ) ),
			'exactly the cap'        => array( array( 'timeout' => 15 ), array( 'timeout' => 15 ) ),
			'none set'               => array( array( 'stream' => true ), array( 'stream' => true, 'timeout' => 15 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'not a number'           => array( array( 'timeout' => 'long' ), array( 'timeout' => 15 ) ),
			'not arguments'          => array( 'x', 'x' ),
		);
	}

	#[DataProvider( 'timeouts' )]
	public function test_the_cap_shortens_long_timeouts_only( mixed $args, mixed $expected ): void {
		self::assertSame( $expected, $this->applier()->cap_timeout( $args ) );
	}
}
