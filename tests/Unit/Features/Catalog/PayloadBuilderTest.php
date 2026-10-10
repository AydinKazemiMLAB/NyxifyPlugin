<?php
/**
 * Tests for PayloadBuilder.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Catalog;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Catalog\Batch;
use WooNyx\Features\Catalog\PayloadBuilder;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Tests\Unit\TestCase;

/**
 * The builder over fake WooCommerce controllers (they answer with the REST data kept per id), products, variations
 * and terms kept in memory, and a logger that records its entries.
 */
final class PayloadBuilderTest extends TestCase {

	private const SECRET_TEXT = 'Customer note 0912 shop secret';

	/**
	 * Products and variations: id => [type, status].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $products = array();

	/**
	 * Variation ids per product, in the order get_posts() returns them.
	 *
	 * @var array<int, list<int>>
	 */
	private array $children = array();

	/**
	 * Category ids that exist.
	 *
	 * @var list<int>
	 */
	private array $terms = array();

	/**
	 * REST data the controllers return: "product:id", "variation:id" or "category:id" => data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data = array();

	/**
	 * Ids whose controller call throws, as "kind:id".
	 *
	 * @var list<string>
	 */
	private array $throws = array();

	/**
	 * Controller classes created, in order.
	 *
	 * @var list<string>
	 */
	private array $created = array();

	/**
	 * Parameters of each request created.
	 *
	 * @var list<array<string, string>>
	 */
	private array $requests = array();

	/**
	 * Calls made to the controllers: "kind:id:request index".
	 *
	 * @var list<string>
	 */
	private array $calls = array();

	/**
	 * Arguments of each get_posts() call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $post_queries = array();

	/**
	 * Arguments of each get_term() call.
	 *
	 * @var list<array{0: int, 1: string}>
	 */
	private array $term_queries = array();

	/**
	 * Ids passed to wc_get_product().
	 *
	 * @var list<int>
	 */
	private array $loaded = array();

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_json_encode' )->alias(
			static fn( $value, int $flags = 0 ) => json_encode( $value, $flags ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stands in for wp_json_encode().
		);
		Functions\when( 'wc_get_product' )->alias(
			function ( int $id ) {
				$this->loaded[] = $id;
				return isset( $this->products[ $id ] ) ? $this->product_object( $id ) : false;
			}
		);
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				$this->post_queries[] = $args;
				return array_map( 'strval', $this->children[ $args['post_parent'] ] ?? array() );
			}
		);
		Functions\when( 'get_term' )->alias(
			function ( int $id, string $taxonomy ) {
				$this->term_queries[] = array( $id, $taxonomy );
				if ( 404 === $id ) {
					return Mockery::mock( 'WP_Error' );
				}
				if ( ! in_array( $id, $this->terms, true ) ) {
					return null;
				}

				$term          = Mockery::mock( 'WP_Term' );
				$term->term_id = $id;
				return $term;
			}
		);
	}

	/**
	 * A mocked WooCommerce product or variation.
	 *
	 * @param int $id Id.
	 * @return MockInterface A WC_Product, or a WC_Product_Variation for a variation.
	 */
	private function product_object( int $id ) {
		list( $type, $status ) = $this->products[ $id ];

		$object = Mockery::mock( 'variation' === $type ? 'WC_Product_Variation' : 'WC_Product' );
		$object->shouldReceive( 'get_id' )->andReturn( $id );
		$object->shouldReceive( 'get_status' )->andReturn( $status );
		$object->shouldReceive( 'is_type' )->andReturnUsing( fn( string $asked ): bool => $asked === $type );

		return $object;
	}

	/**
	 * A fake controller answering with the data kept for each id.
	 *
	 * @param string $class_name Controller class.
	 * @param string $kind       "product", "variation" or "category".
	 * @return MockInterface
	 */
	private function controller( string $class_name, string $kind ) {
		$controller = Mockery::mock( $class_name );
		$respond    = function ( $item, $request ) use ( $kind ) {
			$id    = 'category' === $kind ? $item->term_id : $item->get_id();
			$index = array_search( $request, $this->request_objects, true );

			$this->calls[] = $kind . ':' . $id . ':' . (string) $index;
			if ( in_array( $kind . ':' . $id, $this->throws, true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test exception that must not reach the log.
				throw new \RuntimeException( self::SECRET_TEXT );
			}

			$data = $this->data[ $kind . ':' . $id ];
			if ( 'not a response' === $data ) {
				return $data;
			}

			$response = Mockery::mock( 'WP_REST_Response' );
			$response->shouldReceive( 'get_data' )->andReturn( $data );
			return $response;
		};

		$method = 'category' === $kind ? 'prepare_item_for_response' : 'prepare_object_for_response';
		$controller->shouldReceive( $method )->andReturnUsing( $respond );

		return $controller;
	}

	/**
	 * Request objects created, in order (matched by identity in the controllers).
	 *
	 * @var list<object>
	 */
	private array $request_objects = array();

	/**
	 * The builder, with fake controllers and requests.
	 *
	 * @param callable|null $controllers Controller factory; the fakes by default.
	 */
	private function builder( ?callable $controllers = null ): PayloadBuilder {
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

		$fakes = array(
			'WC_REST_Products_Controller'           => 'product',
			'WC_REST_Product_Variations_Controller' => 'variation',
			'WC_REST_Product_Categories_Controller' => 'category',
		);

		return new PayloadBuilder(
			new Logger( $wc_logger ),
			$controllers ?? function ( string $class_name ) use ( $fakes ): object {
				$this->created[] = $class_name;
				return $this->controller( $class_name, $fakes[ $class_name ] );
			},
			function ( array $params ): \WP_REST_Request {
				$this->requests[] = $params;
				$request          = Mockery::mock( 'WP_REST_Request' );

				$this->request_objects[] = $request;
				return $request;
			}
		);
	}

	/**
	 * A product as WooCommerce's controller returns it: the core's fields among others it doesn't read.
	 *
	 * @param int                  $id    Id.
	 * @param array<string, mixed> $extra Fields to change or add.
	 * @return array<string, mixed>
	 */
	private static function product_data( int $id, array $extra = array() ): array {
		return array_merge(
			array(
				'id'                 => $id,
				'name'               => 'Product ' . $id,
				'slug'               => 'product-' . $id,
				'permalink'          => 'https://shop.test/p/' . $id,
				'date_created'       => '2026-10-01T10:00:00',
				'date_created_gmt'   => '2026-10-01T06:30:00',
				'date_modified'      => '2026-10-12T10:00:00',
				'date_modified_gmt'  => '2026-10-12T06:30:00',
				'type'               => 'simple',
				'status'             => 'publish',
				'featured'           => false,
				'catalog_visibility' => 'visible',
				'description'        => '<p>Long text</p>',
				'short_description'  => 'Short',
				'sku'                => 'SKU-' . $id,
				'price'              => '120000',
				'regular_price'      => '150000',
				'sale_price'         => '120000',
				'price_html'         => '<span>120,000</span>',
				'on_sale'            => true,
				'purchasable'        => true,
				'manage_stock'       => true,
				'stock_quantity'     => 4,
				'backorders_allowed' => false,
				'related_ids'        => array( 3, 9 ),
				'categories'         => array(
					array(
						'id'   => 22,
						'name' => 'Shoes',
						'slug' => 'shoes',
					),
				),
				'brands'             => array(),
				'images'             => array(),
				'attributes'         => array(),
				'variations'         => array( 70, 71 ),
				'meta_data'          => array(
					array(
						'id'    => 1,
						'key'   => '_other_plugin_token',
						'value' => self::SECRET_TEXT,
					),
				),
				'stock_status'       => 'instock',
				'_links'             => array( 'self' => array() ),
			),
			$extra
		);
	}

	/**
	 * A variation as WooCommerce's controller returns it.
	 *
	 * @param int    $id     Id.
	 * @param string $status Status.
	 * @return array<string, mixed>
	 */
	private static function variation_data( int $id, string $status = 'publish' ): array {
		return array(
			'id'                 => $id,
			'type'               => 'variation',
			'date_modified_gmt'  => '2026-10-12T06:30:00',
			'description'        => '',
			'permalink'          => 'https://shop.test/p/13?attribute_size=' . $id,
			'sku'                => 'V-' . $id,
			'price'              => '90000',
			'regular_price'      => '90000',
			'sale_price'         => '',
			'on_sale'            => false,
			'status'             => $status,
			'purchasable'        => true,
			'manage_stock'       => false,
			'stock_quantity'     => null,
			'stock_status'       => 'instock',
			'backorders_allowed' => false,
			'weight'             => '',
			'image'              => null,
			'attributes'         => array(
				array(
					'id'     => 1,
					'name'   => 'Size',
					'option' => (string) $id,
				),
			),
			'menu_order'         => 0,
			'meta_data'          => array(
				array(
					'key'   => '_secret',
					'value' => self::SECRET_TEXT,
				),
			),
		);
	}

	/**
	 * Adds a product (and its REST data) to the shop.
	 *
	 * @param int                  $id     Id.
	 * @param string               $type   Product type.
	 * @param string               $status Status.
	 * @param array<string, mixed> $extra  REST fields to change or add.
	 */
	private function add_product( int $id, string $type = 'simple', string $status = 'publish', array $extra = array() ): void {
		$this->products[ $id ]          = array( $type, $status );
		$this->data[ 'product:' . $id ] = self::product_data(
			$id,
			array_merge(
				array(
					'type'   => $type,
					'status' => $status,
				),
				$extra
			)
		);
	}

	/**
	 * Adds variations of a product.
	 *
	 * @param int                   $parent_id Product id.
	 * @param array<int, string>    $statuses  Variation id => status, in menu order.
	 */
	private function add_variations( int $parent_id, array $statuses ): void {
		foreach ( $statuses as $id => $status ) {
			$this->products[ $id ]            = array( 'variation', $status );
			$this->data[ 'variation:' . $id ] = self::variation_data( $id, $status );
			$this->children[ $parent_id ][]   = $id;
		}
	}

	/**
	 * Checks that a batch's size is the size of its body as the client encodes it.
	 *
	 * @param Batch $batch Batch.
	 */
	private static function assert_bytes( Batch $batch ): void {
		self::assertSame( strlen( (string) json_encode( array( 'items' => $batch->items ), CoreClient::JSON_FLAGS ) ), $batch->bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * The log entries, encoded (to check what they don't contain).
	 */
	private function log_text(): string {
		return (string) json_encode( $this->entries ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_a_product_carries_exactly_the_core_fields_in_order(): void {
		$this->add_product( 5 );

		$batch = $this->builder()->products( array( 5 ) );

		self::assertSame( array( 5 ), $batch->built );
		self::assertSame( PayloadBuilder::PRODUCT_FIELDS, array_keys( $batch->items[0]['product'] ) );
		self::assertSame( 'Product 5', $batch->items[0]['product']['name'] );
		self::assertSame( '2026-10-12T06:30:00', $batch->items[0]['product']['date_modified_gmt'] );
		self::assertStringNotContainsString( self::SECRET_TEXT, (string) json_encode( $batch->items ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		self::assert_bytes( $batch );
	}

	public function test_the_core_reads_24_product_fields_18_variation_fields_and_8_category_fields(): void {
		self::assertCount( 24, PayloadBuilder::PRODUCT_FIELDS );
		self::assertCount( 18, PayloadBuilder::VARIATION_FIELDS );
		self::assertCount( 8, PayloadBuilder::CATEGORY_FIELDS );
		self::assertNotContains( 'meta_data', array_merge( PayloadBuilder::PRODUCT_FIELDS, PayloadBuilder::VARIATION_FIELDS, PayloadBuilder::CATEGORY_FIELDS ) );
	}

	public function test_a_product_is_requested_in_view_context_with_the_core_fields(): void {
		$this->add_product( 5 );

		$this->builder()->products( array( 5 ) );

		self::assertSame(
			array(
				array(
					'context' => 'view',
					'_fields' => implode( ',', PayloadBuilder::PRODUCT_FIELDS ),
				),
			),
			$this->requests
		);
		self::assertSame( array( 'product:5:0' ), $this->calls );
	}

	public function test_fields_a_product_lacks_stay_out(): void {
		$this->add_product( 5 );
		unset( $this->data['product:5']['sku'], $this->data['product:5']['images'] );

		$batch = $this->builder()->products( array( 5 ) );

		self::assertSame( array_values( array_diff( PayloadBuilder::PRODUCT_FIELDS, array( 'sku', 'images' ) ) ), array_keys( $batch->items[0]['product'] ) );
	}

	public function test_each_controller_is_created_once_per_builder(): void {
		$this->add_product( 5 );
		$this->add_product( 6 );
		$this->add_product( 13, 'variable' );
		$this->add_variations(
			13,
			array(
				70 => 'publish',
				71 => 'private',
			)
		);
		$this->terms = array( 22, 23 );
		$this->data += array(
			'category:22' => array( 'id' => 22 ),
			'category:23' => array( 'id' => 23 ),
		);
		$builder     = $this->builder();

		$builder->products( array( 5, 13 ) );
		$builder->products( array( 6 ) );
		$builder->categories( array( 22, 23 ) );

		self::assertSame( array( 'WC_REST_Products_Controller', 'WC_REST_Product_Variations_Controller', 'WC_REST_Product_Categories_Controller' ), $this->created );
	}

	public function test_a_variable_product_carries_every_variation_of_every_status_in_menu_order(): void {
		$this->add_product( 13, 'variable' );
		$this->add_variations(
			13,
			array(
				72 => 'publish',
				70 => 'private',
				71 => 'draft',
				73 => 'pending',
			)
		);

		$batch = $this->builder()->products( array( 13 ) );

		$item = $batch->items[0];
		self::assertSame( array( 'product', 'variations' ), array_keys( $item ) );
		self::assertSame( PayloadBuilder::PRODUCT_FIELDS, array_keys( $item['product'] ) );
		self::assertSame( array( 72, 70, 71, 73 ), array_column( $item['variations'], 'id' ) );
		self::assertSame( array( 'publish', 'private', 'draft', 'pending' ), array_column( $item['variations'], 'status' ) );
		foreach ( $item['variations'] as $variation ) {
			self::assertSame( PayloadBuilder::VARIATION_FIELDS, array_keys( $variation ) );
		}
		self::assertStringNotContainsString( self::SECRET_TEXT, (string) json_encode( $batch->items ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		self::assertSame(
			array(
				array(
					'post_parent' => 13,
					'post_type'   => 'product_variation',
					'post_status' => 'any',
					'fields'      => 'ids',
					'numberposts' => -1,
					'orderby'     => array(
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
					),
				),
			),
			$this->post_queries
		);
		self::assertSame( array( 'context' => 'view' ), $this->requests[1] );
		self::assertSame( array( 'product:13:0', 'variation:72:1', 'variation:70:2', 'variation:71:3', 'variation:73:4' ), $this->calls );
		self::assert_bytes( $batch );
	}

	public function test_a_variable_product_without_variations_carries_an_empty_list(): void {
		$this->add_product( 13, 'variable' );

		$batch = $this->builder()->products( array( 13 ) );

		self::assertSame( array(), $batch->items[0]['variations'] );
	}

	public function test_a_variation_that_cannot_be_loaded_is_left_out_of_the_list(): void {
		$this->add_product( 13, 'variable' );
		$this->add_variations( 13, array( 70 => 'publish' ) );
		$this->children[13][] = 99;

		$batch = $this->builder()->products( array( 13 ) );

		self::assertSame( array( 70 ), array_column( $batch->items[0]['variations'], 'id' ) );
	}

	/**
	 * Product types without variations.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function other_types(): array {
		return array(
			'simple'   => array( 'simple' ),
			'grouped'  => array( 'grouped' ),
			'external' => array( 'external' ),
			'custom'   => array( 'subscription' ),
		);
	}

	/**
	 * Other product types carry no `variations` key and list none.
	 *
	 * @param string $type Product type.
	 */
	#[DataProvider( 'other_types' )]
	public function test_other_types_carry_no_variations( string $type ): void {
		$this->add_product( 5, $type );

		$batch = $this->builder()->products( array( 5 ) );

		self::assertSame( array( 'product' ), array_keys( $batch->items[0] ) );
		self::assertSame( array(), $this->post_queries );
	}

	public function test_more_variations_than_the_core_accepts_leave_the_key_out_and_warn(): void {
		$this->add_product( 13, 'variable' );
		$this->children[13] = range( 1001, 2001 );

		$batch = $this->builder()->products( array( 13 ) );

		self::assertSame( array( 'product' ), array_keys( $batch->items[0] ) );
		self::assertSame( array( 13 ), $this->loaded );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'A product has more variations than the core accepts; its variations are not sent.',
					'context' => array(
						'product_id' => 13,
						'count'      => 1001,
						'source'     => 'woonyx',
					),
				),
			),
			$this->entries
		);
	}

	public function test_exactly_as_many_variations_as_the_core_accepts_are_sent(): void {
		$this->add_product( 13, 'variable' );
		$this->add_variations( 13, array_fill_keys( range( 1001, 2000 ), 'publish' ) );

		$batch = $this->builder()->products( array( 13 ) );

		self::assertCount( PayloadBuilder::MAX_VARIATIONS, $batch->items[0]['variations'] );
		self::assertSame( array(), $this->entries );
	}

	public function test_products_with_nothing_to_send_are_skipped_silently(): void {
		$this->add_product( 5 );
		$this->add_product( 8, 'simple', 'trash' );
		$this->add_product( 9, 'simple', 'auto-draft' );
		$this->add_product( 6, 'simple', 'draft' );
		$this->products[7] = array( 'variation', 'publish' );

		$batch = $this->builder()->products( array( 99, 5, 7, 9, 8, 6 ) );

		self::assertSame( array( 5, 8, 6 ), $batch->built );
		self::assertSame( array( 'publish', 'trash', 'draft' ), array_column( array_column( $batch->items, 'product' ), 'status' ) );
		self::assertSame( array( 99, 7, 9 ), $batch->skipped );
		self::assertSame( array(), $batch->left );
		self::assertSame( array(), $this->entries );
		self::assert_bytes( $batch );
	}

	public function test_a_variation_queued_as_a_product_object_is_skipped(): void {
		$this->products[5] = array( 'simple', 'publish' );
		Functions\when( 'wc_get_product' )->alias(
			static function () {
				$object = Mockery::mock( 'WC_Product' );
				$object->shouldReceive( 'get_status' )->andReturn( 'publish' );
				$object->shouldReceive( 'is_type' )->andReturnUsing( fn( string $type ): bool => 'variation' === $type );
				return $object;
			}
		);

		$batch = $this->builder()->products( array( 5 ) );

		self::assertSame( array( 5 ), $batch->skipped );
		self::assertSame( array(), $this->calls );
	}

	/**
	 * A failure while building one product skips it with a warning that carries its id and the exception class only.
	 */
	public function test_a_product_that_fails_to_build_is_skipped_with_its_id_only(): void {
		$this->add_product( 5 );
		$this->add_product( 6 );
		$this->throws[] = 'product:5';

		$batch = $this->builder()->products( array( 5, 6 ) );

		self::assertSame( array( 6 ), $batch->built );
		self::assertSame( array( 5 ), $batch->skipped );
		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'An object could not be built; it is skipped.',
					'context' => array(
						'kind'      => 'product',
						'id'        => 5,
						'exception' => 'RuntimeException',
						'source'    => 'woonyx',
					),
				),
			),
			$this->entries
		);
		self::assertStringNotContainsString( '0912', $this->log_text() );
	}

	public function test_a_variation_that_fails_skips_the_whole_product(): void {
		$this->add_product( 13, 'variable' );
		$this->add_variations(
			13,
			array(
				70 => 'publish',
				71 => 'publish',
			)
		);
		$this->throws[] = 'variation:71';

		$batch = $this->builder()->products( array( 13 ) );

		self::assertSame( array(), $batch->items );
		self::assertSame( array( 13 ), $batch->skipped );
	}

	/**
	 * Controller answers that hold no object.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function bad_answers(): array {
		return array(
			'not a response'    => array( 'not a response' ),
			'data not an array' => array( 'text' ),
			'null data'         => array( null ),
		);
	}

	/**
	 * A controller answer without an object skips the product.
	 *
	 * @param mixed $answer What the controller returns, or the data of its response.
	 */
	#[DataProvider( 'bad_answers' )]
	public function test_an_answer_without_an_object_is_skipped( $answer ): void {
		$this->add_product( 5 );
		$this->data['product:5'] = $answer;

		$batch = $this->builder()->products( array( 5 ) );

		self::assertSame( array( 5 ), $batch->skipped );
		self::assertSame( 'UnexpectedValueException', $this->entries[0]['context']['exception'] );
	}

	public function test_a_factory_returning_another_class_skips_the_object(): void {
		$this->add_product( 5 );

		$batch = $this->builder( static fn(): object => new \stdClass() )->products( array( 5 ) );

		self::assertSame( array( 5 ), $batch->skipped );
		self::assertSame( 'UnexpectedValueException', $this->entries[0]['context']['exception'] );
	}

	public function test_an_object_that_cannot_be_encoded_is_skipped(): void {
		$this->add_product( 5, 'simple', 'publish', array( 'price' => INF ) );
		$this->add_product( 6 );

		$batch = $this->builder()->products( array( 5, 6 ) );

		self::assertSame( array( 6 ), $batch->built );
		self::assertSame( array( 5 ), $batch->skipped );
		self::assertSame( 'An object could not be encoded; it is skipped.', $this->entries[0]['message'] );
		self::assertSame( 5, $this->entries[0]['context']['id'] );
	}

	public function test_a_category_carries_exactly_the_core_fields_in_order(): void {
		$this->terms               = array( 22 );
		$this->data['category:22'] = array(
			'id'          => 22,
			'name'        => 'Shoes',
			'slug'        => 'shoes',
			'parent'      => 0,
			'description' => '',
			'display'     => 'default',
			'image'       => null,
			'menu_order'  => 3,
			'count'       => 12,
			'_links'      => array(),
		);

		$batch = $this->builder()->categories( array( 22 ) );

		self::assertSame( array( 22 ), $batch->built );
		self::assertSame( PayloadBuilder::CATEGORY_FIELDS, array_keys( $batch->items[0] ) );
		self::assertSame( array( array( 22, 'product_cat' ) ), $this->term_queries );
		self::assertSame( array( array( 'context' => 'view' ) ), $this->requests );
		self::assertSame( array( 'category:22:0' ), $this->calls );
		self::assert_bytes( $batch );
	}

	public function test_missing_categories_are_skipped(): void {
		$this->terms               = array( 22 );
		$this->data['category:22'] = array( 'id' => 22 );

		$batch = $this->builder()->categories( array( 30, 404, 22 ) );

		self::assertSame( array( 22 ), $batch->built );
		self::assertSame( array( 30, 404 ), $batch->skipped );
		self::assertSame( array(), $this->entries );
	}

	public function test_a_batch_holds_at_most_100_objects(): void {
		$ids = range( 1, 120 );
		foreach ( $ids as $id ) {
			$this->add_product( $id );
		}

		$batch = $this->builder()->products( $ids );

		self::assertSame( range( 1, 100 ), $batch->built );
		self::assertSame( range( 101, 120 ), $batch->left );
		self::assertSame( range( 1, 100 ), $this->loaded );
		self::assert_bytes( $batch );
	}

	public function test_a_batch_stops_before_passing_4_mb_and_leaves_the_rest(): void {
		$text = str_repeat( 'x', 1500000 );
		foreach ( array( 1, 2, 3, 4 ) as $id ) {
			$this->add_product( $id, 'simple', 'publish', array( 'description' => $text ) );
		}

		$batch = $this->builder()->products( array( 1, 2, 3, 4 ) );

		self::assertSame( array( 1, 2 ), $batch->built );
		self::assertSame( array( 3, 4 ), $batch->left );
		self::assertLessThanOrEqual( PayloadBuilder::BATCH_BYTES, $batch->bytes );
		self::assert_bytes( $batch );
	}

	public function test_skipped_ids_do_not_count_against_the_batch(): void {
		$this->add_product( 1 );
		$this->add_product( 3 );

		$batch = $this->builder()->products( array( 1, 2, 3 ) );

		self::assertSame( array( 1, 3 ), $batch->built );
		self::assertSame( array( 2 ), $batch->skipped );
		self::assert_bytes( $batch );
	}

	public function test_an_object_over_4_mb_goes_alone(): void {
		$this->add_product( 1, 'simple', 'publish', array( 'description' => str_repeat( 'x', 5000000 ) ) );
		$this->add_product( 2 );

		$batch = $this->builder()->products( array( 1, 2 ) );

		self::assertSame( array( 1 ), $batch->built );
		self::assertSame( array( 2 ), $batch->left );
		self::assertGreaterThan( PayloadBuilder::BATCH_BYTES, $batch->bytes );
		self::assert_bytes( $batch );
	}

	public function test_an_object_over_4_mb_after_others_waits_for_the_next_batch(): void {
		$this->add_product( 1 );
		$this->add_product( 2, 'simple', 'publish', array( 'description' => str_repeat( 'x', 5000000 ) ) );
		$this->add_product( 3 );

		$batch = $this->builder()->products( array( 1, 2, 3 ) );

		self::assertSame( array( 1 ), $batch->built );
		self::assertSame( array( 2, 3 ), $batch->left );
		self::assertSame( array(), $batch->skipped );
	}

	public function test_an_object_over_9_mb_is_skipped_with_its_id_and_size(): void {
		$this->add_product( 1, 'simple', 'publish', array( 'description' => str_repeat( 'x', 9500000 ) ) );
		$this->add_product( 2 );

		$batch = $this->builder()->products( array( 1, 2 ) );

		self::assertSame( array( 2 ), $batch->built );
		self::assertSame( array( 1 ), $batch->skipped );
		self::assertSame( 'An object is too large to send; it is skipped.', $this->entries[0]['message'] );
		self::assertSame( 'product', $this->entries[0]['context']['kind'] );
		self::assertSame( 1, $this->entries[0]['context']['id'] );
		self::assertGreaterThan( PayloadBuilder::MAX_ITEM_BYTES, $this->entries[0]['context']['bytes'] );
		self::assertLessThan( 1000, strlen( $this->log_text() ) );
	}

	public function test_no_ids_make_an_empty_batch(): void {
		$batch = $this->builder()->products( array() );

		self::assertSame( array(), $batch->items );
		self::assertSame( 12, $batch->bytes );
		self::assert_bytes( $batch );
		self::assertSame( array(), $this->created );
	}
}
