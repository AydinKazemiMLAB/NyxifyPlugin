<?php
/**
 * Tests for OrderPayloadBuilder.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Orders;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Catalog\Batch;
use Nyxify\Features\Orders\OrderPayloadBuilder;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Tests\Unit\TestCase;

/**
 * The builder over orders and line items that are strict mocks: only the getters of the whitelist (and the type and
 * marker) exist on them, so a test fails if the builder reads anything else, personal data included.
 */
final class OrderPayloadBuilderTest extends TestCase {

	private const DECIMALS = 2;

	private const SECRET_NAME = 'Sara Ahmadi 09121112233';

	/**
	 * Orders wc_get_order() returns: id => order.
	 *
	 * @var array<int, mixed>
	 */
	private array $orders = array();

	/**
	 * Ids passed to wc_get_order().
	 *
	 * @var list<mixed>
	 */
	private array $reads = array();

	/**
	 * Arguments of each wc_format_decimal() call.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $decimal_calls = array();

	/**
	 * Arguments of each wc_rest_prepare_date_response() call.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $date_calls = array();

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				$this->reads[] = $id;
				$order         = $this->orders[ $id ] ?? false;
				if ( $order instanceof \Throwable ) {
					throw $order;
				}
				return $order;
			}
		);
		Functions\when( 'wc_get_price_decimals' )->justReturn( self::DECIMALS );
		Functions\when( 'wc_format_decimal' )->alias(
			function ( $number, $dp = false ): string {
				$this->decimal_calls[] = array( $number, $dp );
				return number_format( (float) $number, (int) $dp, '.', '' );
			}
		);
		Functions\when( 'wc_rest_prepare_date_response' )->alias(
			function ( $date, $utc = true ) {
				$this->date_calls[] = array( $date, $utc );
				if ( ! $date instanceof \DateTimeInterface ) {
					return null;
				}
				// As WooCommerce: the UTC timestamp, or with $utc = false the shop's local time (getOffsetTimestamp()).
				return gmdate( 'Y-m-d\TH:i:s', $utc ? $date->getTimestamp() : $date->getTimestamp() + $date->getOffset() );
			}
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	private function builder(): OrderPayloadBuilder {
		return new OrderPayloadBuilder( new Logger( $this->recording_logger() ) );
	}

	/**
	 * A Nyxify order with only the whitelisted getters.
	 *
	 * @param int                  $id     Id.
	 * @param array<int, mixed>    $items  Line items.
	 * @param array<string, mixed> $values Getter values that differ from the defaults (a Throwable is thrown).
	 * @return MockInterface&\WC_Order
	 */
	private static function order( int $id, array $items = array(), array $values = array() ) {
		$values += array(
			'get_type'          => 'shop_order',
			'marker'            => '1',
			'get_status'        => 'processing',
			'get_order_number'  => (string) $id,
			'get_currency'      => 'IRT',
			'get_total'         => '2400000',
			'get_date_created'  => new \DateTimeImmutable( '2026-09-14 10:00:00', new \DateTimeZone( 'UTC' ) ),
			'get_date_modified' => new \DateTimeImmutable( '2026-09-14 11:00:00', new \DateTimeZone( 'UTC' ) ),
			'get_date_paid'     => new \DateTimeImmutable( '2026-09-14 10:59:30', new \DateTimeZone( 'UTC' ) ),
		);

		$order = Mockery::mock( 'WC_Order' );
		$order->allows( 'get_id' )->andReturn( $id );
		$order->allows( 'get_meta' )->with( '_nyxify_order' )->andReturn( $values['marker'] );
		$order->allows( 'get_items' )->with( 'line_item' )->andReturn( $items );
		foreach ( array( 'get_type', 'get_status', 'get_order_number', 'get_currency', 'get_total', 'get_date_created', 'get_date_modified', 'get_date_paid' ) as $getter ) {
			if ( $values[ $getter ] instanceof \Throwable ) {
				$order->allows( $getter )->andThrow( $values[ $getter ] );
			} else {
				$order->allows( $getter )->andReturn( $values[ $getter ] );
			}
		}

		return $order;
	}

	/**
	 * A product line with only the whitelisted getters.
	 *
	 * @param string $name         Name.
	 * @param int    $product_id   Product id.
	 * @param int    $variation_id Variation id.
	 * @param int    $quantity     Quantity.
	 * @param string $total        Line total.
	 * @return MockInterface&\WC_Order_Item_Product
	 */
	private static function line( string $name, int $product_id, int $variation_id, int $quantity, string $total ) {
		$line = Mockery::mock( 'WC_Order_Item_Product' );
		$line->allows( 'get_name' )->andReturn( $name );
		$line->allows( 'get_product_id' )->andReturn( $product_id );
		$line->allows( 'get_variation_id' )->andReturn( $variation_id );
		$line->allows( 'get_quantity' )->andReturn( $quantity );
		$line->allows( 'get_total' )->andReturn( $total );

		return $line;
	}

	public function test_an_order_is_built_from_the_whitelist_in_order(): void {
		$this->orders[501] = self::order(
			501,
			array(
				7 => self::line( 'Blue shirt', 42, 0, 2, '1600000' ),
				8 => self::line( 'Mug - Red', 43, 51, 1, '800000.5' ),
			)
		);

		$batch = $this->builder()->orders( array( 501 ) );

		self::assertSame(
			array(
				array(
					'id'                => 501,
					'status'            => 'processing',
					'number'            => '501',
					'currency'          => 'IRT',
					'total'             => '2400000.00',
					'date_created_gmt'  => '2026-09-14T10:00:00',
					'date_modified_gmt' => '2026-09-14T11:00:00',
					'date_paid_gmt'     => '2026-09-14T10:59:30',
					'line_items'        => array(
						array(
							'name'         => 'Blue shirt',
							'product_id'   => 42,
							'variation_id' => 0,
							'quantity'     => 2,
							'total'        => '1600000.00',
						),
						array(
							'name'         => 'Mug - Red',
							'product_id'   => 43,
							'variation_id' => 51,
							'quantity'     => 1,
							'total'        => '800000.50',
						),
					),
				),
			),
			$batch->items
		);
		self::assertSame( OrderPayloadBuilder::ORDER_FIELDS, array_keys( $batch->items[0] ) );
		self::assertSame( OrderPayloadBuilder::LINE_ITEM_FIELDS, array_keys( $batch->items[0]['line_items'][0] ) );
		self::assertSame( array( 501 ), $batch->built );
		self::assertSame( array(), $batch->skipped );
		self::assertSame( array(), $batch->left );
		self::assertSame( array(), $this->entries );
	}

	public function test_the_formats_are_the_rest_apis(): void {
		$this->orders[501] = self::order( 501, array( self::line( 'Blue shirt', 42, 0, 2, '1600000' ) ) );

		$this->builder()->orders( array( 501 ) );

		self::assertSame( array( array( '2400000', self::DECIMALS ), array( '1600000', self::DECIMALS ) ), $this->decimal_calls );
		self::assertCount( 3, $this->date_calls );
		foreach ( $this->date_calls as $call ) {
			self::assertTrue( $call[1], 'GMT dates are read with $utc = true, as the REST API does for *_gmt.' );
		}
	}

	public function test_gmt_dates_are_utc_in_a_shop_outside_utc(): void {
		$tehran            = new \DateTimeZone( 'Asia/Tehran' );
		$this->orders[501] = self::order(
			501,
			array(),
			array(
				'get_date_created'  => new \DateTimeImmutable( '2026-09-14 13:30:00', $tehran ),
				'get_date_modified' => new \DateTimeImmutable( '2026-09-14 14:30:00', $tehran ),
				'get_date_paid'     => new \DateTimeImmutable( '2026-09-14 14:29:30', $tehran ),
			)
		);

		$item = $this->builder()->orders( array( 501 ) )->items[0];

		self::assertSame( '2026-09-14T10:00:00', $item['date_created_gmt'] );
		self::assertSame( '2026-09-14T11:00:00', $item['date_modified_gmt'] );
		self::assertSame( '2026-09-14T10:59:30', $item['date_paid_gmt'] );
	}

	public function test_an_unpaid_order_has_a_null_paid_date_and_a_number_is_always_a_string(): void {
		$this->orders[501] = self::order(
			501,
			array(),
			array(
				'get_date_paid'    => null,
				'get_order_number' => 501,
				'get_status'       => 'pending',
			)
		);

		$item = $this->builder()->orders( array( 501 ) )->items[0];

		self::assertNull( $item['date_paid_gmt'] );
		self::assertSame( '501', $item['number'] );
		self::assertSame( 'pending', $item['status'] );
		self::assertSame( array(), $item['line_items'] );
	}

	public function test_a_trashed_order_goes_with_its_status_and_a_custom_status_as_it_is(): void {
		$this->orders[501] = self::order( 501, array(), array( 'get_status' => 'trash' ) );
		$this->orders[502] = self::order( 502, array(), array( 'get_status' => 'shipped' ) );

		$items = $this->builder()->orders( array( 501, 502 ) )->items;

		self::assertSame( array( 'trash', 'shipped' ), array_column( $items, 'status' ) );
	}

	public function test_lines_other_than_products_are_left_out(): void {
		$fee = Mockery::mock( 'WC_Order_Item_Fee' );
		$fee->shouldNotReceive( 'get_name', 'get_total' );

		$this->orders[501] = self::order( 501, array( $fee, self::line( 'Blue shirt', 42, 0, 2, '1600000' ) ) );

		$lines = $this->builder()->orders( array( 501 ) )->items[0]['line_items'];

		self::assertSame( array( 'Blue shirt' ), array_column( $lines, 'name' ) );
	}

	/**
	 * Orders with nothing to send.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unsendable(): array {
		return array(
			'missing'               => array( 'missing' ),
			'not created by nyxify' => array( 'no marker' ),
			'another order type'    => array( 'subscription' ),
			'a refund'              => array( 'refund' ),
		);
	}

	/**
	 * An order with nothing to send is skipped without a warning and without reading its fields.
	 *
	 * @param string $what What wc_get_order() returns.
	 */
	#[DataProvider( 'unsendable' )]
	public function test_an_order_with_nothing_to_send_is_skipped( string $what ): void {
		switch ( $what ) {
			case 'no marker':
				$order = Mockery::mock( 'WC_Order' );
				$order->allows( 'get_type' )->andReturn( 'shop_order' );
				$order->allows( 'get_meta' )->with( '_nyxify_order' )->andReturn( '' );
				break;
			case 'subscription':
				$order = Mockery::mock( 'WC_Order' );
				$order->allows( 'get_type' )->andReturn( 'shop_subscription' );
				break;
			case 'refund':
				$order = Mockery::mock( 'WC_Order_Refund' );
				break;
			default:
				$order = false;
		}
		$this->orders[501] = $order;
		$this->orders[502] = self::order( 502 );

		$batch = $this->builder()->orders( array( 501, 502 ) );

		self::assertSame( array( 502 ), $batch->built );
		self::assertSame( array( 501 ), $batch->skipped );
		self::assertSame( array( 501, 502 ), $this->reads );
		self::assertSame( array(), $this->entries );
	}

	public function test_an_order_that_fails_to_build_is_skipped_with_a_warning_holding_no_field(): void {
		$this->orders[501] = self::order( 501, array( self::line( self::SECRET_NAME, 42, 0, 1, '1' ) ), array( 'get_total' => new \RuntimeException( self::SECRET_NAME ) ) );
		$this->orders[502] = self::order( 502 );

		$batch = $this->builder()->orders( array( 501, 502 ) );

		self::assertSame( array( 502 ), $batch->built );
		self::assertSame( array( 501 ), $batch->skipped );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'An object could not be built; it is skipped.',
					'context' => array(
						'kind'      => 'order',
						'id'        => 501,
						'exception' => \RuntimeException::class,
						'source'    => 'nyxify',
					),
				),
			),
			$this->entries
		);
	}

	public function test_deleted_orders_go_as_trash_without_a_date_and_without_a_lookup(): void {
		$batch = $this->builder()->deleted( array( 501, 502 ) );

		self::assertSame(
			array(
				array(
					'id'     => 501,
					'status' => 'trash',
				),
				array(
					'id'     => 502,
					'status' => 'trash',
				),
			),
			$batch->items
		);
		self::assertSame( array( 501, 502 ), $batch->built );
		self::assertSame( array(), $this->reads );
		self::assertSame( strlen( '{"orders":[{"id":501,"status":"trash"},{"id":502,"status":"trash"}]}' ), $batch->bytes );
	}

	public function test_a_batch_holds_at_most_100_orders(): void {
		foreach ( range( 1, 101 ) as $id ) {
			$this->orders[ $id ] = self::order( $id );
		}

		$batch = $this->builder()->orders( range( 1, 101 ) );

		self::assertCount( 100, $batch->items );
		self::assertSame( array( 101 ), $batch->left );
		self::assertSame( array( 101 ), array_values( array_diff( range( 1, 101 ), $this->reads ) ) );
	}

	public function test_deletes_are_also_at_most_100_per_batch(): void {
		$batch = $this->builder()->deleted( range( 1, 150 ) );

		self::assertCount( 100, $batch->items );
		self::assertSame( range( 101, 150 ), $batch->left );
	}

	public function test_the_body_size_is_the_clients_encoding_of_the_orders(): void {
		$this->orders[501] = self::order( 501, array( self::line( 'Chaî "ne" / ✓', 42, 0, 2, '1600000' ) ) );
		$this->orders[502] = self::order( 502 );

		$batch = $this->builder()->orders( array( 501, 502 ) );

		self::assertSame( strlen( (string) json_encode( array( 'orders' => $batch->items ), CoreClient::JSON_FLAGS ) ), $batch->bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_an_empty_batch(): void {
		$batch = $this->builder()->orders( array() );

		self::assertSame( array(), $batch->items );
		self::assertSame( strlen( '{"orders":[]}' ), $batch->bytes );
	}

	public function test_a_batch_stops_before_4_mib_and_leaves_the_rest(): void {
		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->orders[ $id ] = self::order( $id, array( self::line( str_repeat( 'a', 1536 * 1024 ), 42, 0, 1, '1' ) ) );
		}

		$batch = $this->builder()->orders( array( 1, 2, 3 ) );

		self::assertSame( array( 1, 2 ), $batch->built );
		self::assertSame( array( 3 ), $batch->left );
		self::assertLessThanOrEqual( Batch::BATCH_BYTES, $batch->bytes );
	}

	public function test_an_order_over_9_mib_is_skipped_with_its_id_and_size_only(): void {
		$this->orders[1] = self::order( 1, array( self::line( self::SECRET_NAME . str_repeat( 'a', 9 * 1024 * 1024 ), 42, 0, 1, '1' ) ) );
		$this->orders[2] = self::order( 2 );

		$batch = $this->builder()->orders( array( 1, 2 ) );

		self::assertSame( array( 2 ), $batch->built );
		self::assertSame( array( 1 ), $batch->skipped );
		self::assertCount( 1, $this->entries );
		self::assertSame( 'An object is too large to send; it is skipped.', $this->entries[0]['message'] );
		self::assertSame( 'order', $this->entries[0]['context']['kind'] );
		self::assertSame( 1, $this->entries[0]['context']['id'] );
		self::assertGreaterThan( Batch::MAX_ITEM_BYTES, $this->entries[0]['context']['bytes'] );
		self::assertStringNotContainsString( 'Sara', (string) json_encode( $this->entries ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_no_personal_getter_is_ever_called(): void {
		$order = self::order( 501, array( self::line( 'Blue shirt', 42, 0, 2, '1600000' ) ) );
		$order->shouldNotReceive(
			'get_data',
			'get_meta_data',
			'get_billing_first_name',
			'get_billing_last_name',
			'get_billing_phone',
			'get_billing_email',
			'get_billing_address_1',
			'get_shipping_address_1',
			'get_formatted_billing_full_name',
			'get_customer_id',
			'get_customer_ip_address',
			'get_customer_user_agent',
			'get_customer_note',
			'get_payment_method',
			'get_payment_method_title',
			'get_transaction_id',
			'get_checkout_payment_url',
			'get_order_key'
		);
		$this->orders[501] = $order;

		$item = $this->builder()->orders( array( 501 ) )->items[0];

		self::assertSame( OrderPayloadBuilder::ORDER_FIELDS, array_keys( $item ) );
		self::assertArrayNotHasKey( 'payment_url', $item );
		self::assertArrayNotHasKey( 'billing', $item );
		self::assertArrayNotHasKey( 'meta_data', $item );
	}

	/**
	 * A WooCommerce logger that records into $entries.
	 *
	 * @return \WC_Logger_Interface&MockInterface
	 */
	private function recording_logger() {
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

		return $wc_logger;
	}
}
