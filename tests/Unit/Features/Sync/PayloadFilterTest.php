<?php
/**
 * Tests for PayloadFilter.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Sync;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Sync\ChangeResult;
use Nyxify\Features\Sync\PayloadFilter;
use Nyxify\Features\Sync\PulledChange;
use Nyxify\Tests\Unit\TestCase;

/**
 * The whitelist per action (contract §5.1), nested fields included; the request body or `invalid_change`.
 */
final class PayloadFilterTest extends TestCase {

	/**
	 * WP_ENVIRONMENT_TYPE.
	 *
	 * @var string
	 */
	private string $environment = 'production';

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->alias( fn(): string => $this->environment );
	}

	/**
	 * A change as the core sends it.
	 *
	 * @param string               $action     Action.
	 * @param array<string, mixed> $payload    Payload.
	 * @param int|null             $product_id Product id.
	 * @param int|null             $variation  Variation id.
	 */
	private static function change( string $action, array $payload, ?int $product_id = 13, ?int $variation = null ): PulledChange {
		$change = PulledChange::from_response(
			array(
				'id'               => 41,
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

	/**
	 * Every product field the core can send (`ProductFields::to_woo()`, images resolved).
	 *
	 * @return array<string, mixed>
	 */
	private static function product_fields(): array {
		return array(
			'name'               => 'کفش راحتی',
			'status'             => 'publish',
			'catalog_visibility' => 'visible',
			'description'        => '<p>چرم طبیعی</p>',
			'short_description'  => 'راحت',
			'sku'                => 'SH-12',
			'regular_price'      => '1250000',
			'sale_price'         => '990000.5',
			'manage_stock'       => true,
			'stock_quantity'     => 7,
			'stock_status'       => 'instock',
			'backorders'         => 'no',
			'categories'         => array( array( 'id' => 15 ), array( 'id' => 16 ) ),
			'images'             => array(
				array(
					'src'  => 'https://bot.example.com:88/media/0123456789abcdef0123456789abcdef.jpg?expires=1760170530&signature=abc',
					'name' => 'جلو',
					'alt'  => 'کفش از جلو',
				),
				array( 'id' => 12 ),
			),
		);
	}

	/**
	 * Every variation field the core can send (`UpdateVariationRequest::to_woo()`).
	 *
	 * @return array<string, mixed>
	 */
	private static function variation_fields(): array {
		return array(
			'status'         => 'private',
			'description'    => 'سایز ۴۲',
			'sku'            => 'SH-12-42',
			'regular_price'  => '1300000',
			'sale_price'     => '',
			'manage_stock'   => false,
			'stock_quantity' => 0,
			'stock_status'   => 'outofstock',
			'backorders'     => 'notify',
			'image'          => array( 'src' => 'https://bot.example.com:88/media/0123456789abcdef0123456789abcdef.png?expires=1&signature=s' ),
		);
	}

	/**
	 * Asserts an invalid_change result whose message names fields but no value.
	 *
	 * @param array<string, mixed>|ChangeResult $result Result.
	 * @param string                            $text   Text the message contains.
	 */
	private static function assert_invalid( $result, string $text ): void {
		self::assertInstanceOf( ChangeResult::class, $result );
		self::assertFalse( $result->success );
		self::assertSame( 41, $result->id );
		self::assertSame( ChangeResult::INVALID_CHANGE, $result->error_code );
		self::assertStringContainsString( $text, $result->error_message );
	}

	// ------------------------------------------------------------------ accepted

	public function test_an_update_with_every_product_field_passes_as_it_is(): void {
		self::assertSame( self::product_fields(), ( new PayloadFilter() )->check( self::change( PulledChange::PRODUCT_UPDATE, self::product_fields() ) ) );
	}

	public function test_a_create_gets_the_plugins_own_outbox_meta(): void {
		$payload = array( 'type' => 'simple' ) + self::product_fields() + array(
			'meta_data' => array(
				array(
					'key'   => PayloadFilter::OUTBOX_META_KEY,
					'value' => '999',
				),
			),
		);

		$body = ( new PayloadFilter() )->check( self::change( PulledChange::PRODUCT_CREATE, $payload, null ) );

		self::assertIsArray( $body );
		self::assertSame(
			array(
				array(
					'key'   => '_nyxify_outbox_id',
					'value' => '41',
				),
			),
			$body['meta_data']
		);
		unset( $body['meta_data'], $payload['meta_data'] );
		self::assertSame( $payload, $body );
	}

	public function test_a_create_without_meta_still_gets_it(): void {
		$body = ( new PayloadFilter() )->check(
			self::change(
				PulledChange::PRODUCT_CREATE,
				array(
					'type' => 'simple',
					'name' => 'کیف',
				),
				null
			)
		);

		self::assertIsArray( $body );
		self::assertSame( '41', $body['meta_data'][0]['value'] );
	}

	public function test_a_delete_has_no_body(): void {
		self::assertSame( array(), ( new PayloadFilter() )->check( self::change( PulledChange::PRODUCT_DELETE, array() ) ) );
	}

	public function test_a_variation_edit_with_every_field_passes_as_it_is(): void {
		self::assertSame(
			self::variation_fields(),
			( new PayloadFilter() )->check( self::change( PulledChange::VARIATION_UPDATE, self::variation_fields(), 13, 70 ) )
		);
	}

	public function test_clearing_a_sale_and_removing_every_image_pass(): void {
		$payload = array(
			'sale_price' => '',
			'images'     => array(),
		);

		self::assertSame( $payload, ( new PayloadFilter() )->check( self::change( PulledChange::PRODUCT_UPDATE, $payload ) ) );
	}

	public function test_plain_http_images_pass_only_where_plain_http_to_the_core_is_allowed(): void {
		$payload = array( 'images' => array( array( 'src' => 'http://localhost:8000/media/a.jpg' ) ) );
		$change  = self::change( PulledChange::PRODUCT_UPDATE, $payload );

		self::assert_invalid( ( new PayloadFilter() )->check( $change ), 'images' );

		$this->environment = 'local';
		self::assertSame( $payload, ( new PayloadFilter() )->check( $change ) );
	}

	// ------------------------------------------------------------------ refused

	public function test_an_unknown_action_is_refused(): void {
		self::assert_invalid( ( new PayloadFilter() )->check( self::change( 'category.update', array( 'name' => 'x' ) ) ), 'Unknown action' );
	}

	/**
	 * Changes without the ids their action needs.
	 *
	 * @return array<string, array{string, int|null, int|null}>
	 */
	public static function missing_ids(): array {
		return array(
			'update without product'      => array( PulledChange::PRODUCT_UPDATE, null, null ),
			'delete without product'      => array( PulledChange::PRODUCT_DELETE, null, null ),
			'variation without product'   => array( PulledChange::VARIATION_UPDATE, null, 70 ),
			'variation without variation' => array( PulledChange::VARIATION_UPDATE, 13, null ),
		);
	}

	#[DataProvider( 'missing_ids' )]
	public function test_a_change_without_its_ids_is_refused( string $action, ?int $product_id, ?int $variation ): void {
		$payload = PulledChange::PRODUCT_DELETE === $action ? array() : array( 'sku' => 'A' );

		self::assert_invalid( ( new PayloadFilter() )->check( self::change( $action, $payload, $product_id, $variation ) ), 'Missing' );
	}

	/**
	 * Updates with nothing to change.
	 *
	 * @return array<string, array{string, int|null}>
	 */
	public static function empty_edits(): array {
		return array(
			'product update'   => array( PulledChange::PRODUCT_UPDATE, null ),
			'variation update' => array( PulledChange::VARIATION_UPDATE, 70 ),
		);
	}

	#[DataProvider( 'empty_edits' )]
	public function test_an_edit_without_fields_is_refused( string $action, ?int $variation ): void {
		self::assert_invalid( ( new PayloadFilter() )->check( self::change( $action, array(), 13, $variation ) ), 'Nothing to change' );
	}

	public function test_a_delete_with_fields_is_refused(): void {
		self::assert_invalid( ( new PayloadFilter() )->check( self::change( PulledChange::PRODUCT_DELETE, array( 'force' => true ) ) ), 'force' );
	}

	/**
	 * Product fields outside the whitelist, of the wrong type or with a value the core never sends.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function invalid_product_fields(): array {
		$image = array( 'src' => 'https://media.example.com/a.jpg' );

		return array(
			'unknown field'           => array( 'price', '100' ),
			'meta_data on an update'  => array( 'meta_data', array() ),
			'type on an update'       => array( 'type', 'simple' ),
			'a read-only field'       => array( 'date_modified', '2026-10-11T08:00:00' ),
			'empty name'              => array( 'name', '   ' ),
			'name a number'           => array( 'name', 12 ),
			'status unknown'          => array( 'status', 'trash' ),
			'visibility unknown'      => array( 'catalog_visibility', 'everywhere' ),
			'description null'        => array( 'description', null ),
			'sku a number'            => array( 'sku', 12 ),
			'price a number'          => array( 'regular_price', 1250000 ),
			'price negative'          => array( 'regular_price', '-5' ),
			'price with comma'        => array( 'regular_price', '1,250' ),
			'price five decimals'     => array( 'regular_price', '1.12345' ),
			'price exponent'          => array( 'regular_price', '1E+3' ),
			'regular price empty'     => array( 'regular_price', '' ),
			'sale price a bool'       => array( 'sale_price', false ),
			'manage_stock as text'    => array( 'manage_stock', 'yes' ),
			'quantity as text'        => array( 'stock_quantity', '7' ),
			'quantity a float'        => array( 'stock_quantity', 7.5 ),
			'stock status unknown'    => array( 'stock_status', 'available' ),
			'backorders a bool'       => array( 'backorders', true ),
			'categories ids only'     => array( 'categories', array( 15, 16 ) ),
			'category with a name'    => array(
				'categories',
				array(
					array(
						'id'   => 15,
						'name' => 'Shoes',
					),
				),
			),
			'category id zero'        => array( 'categories', array( array( 'id' => 0 ) ) ),
			'category id as text'     => array( 'categories', array( array( 'id' => '15' ) ) ),
			'categories an object'    => array( 'categories', array( 'first' => array( 'id' => 15 ) ) ),
			'101 categories'          => array( 'categories', array_fill( 0, 101, array( 'id' => 15 ) ) ),
			'images an object'        => array( 'images', $image ),
			'21 images'               => array( 'images', array_fill( 0, 21, $image ) ),
			'image an upload id'      => array( 'images', array( array( 'upload_id' => '0123456789abcdef0123456789abcdef.jpg' ) ) ),
			'image src and id'        => array(
				'images',
				array(
					array(
						'src' => 'https://media.example.com/a.jpg',
						'id'  => 12,
					),
				),
			),
			'image without src or id' => array( 'images', array( array( 'alt' => 'x' ) ) ),
			'image src null'          => array(
				'images',
				array(
					array(
						'src' => null,
						'id'  => 12,
					),
				),
			),
			'image id as text'        => array( 'images', array( array( 'id' => '12' ) ) ),
			'image id zero'           => array( 'images', array( array( 'id' => 0 ) ) ),
			'image with a position'   => array(
				'images',
				array(
					array(
						'id'       => 12,
						'position' => 0,
					),
				),
			),
			'image alt a number'      => array(
				'images',
				array(
					array(
						'id'  => 12,
						'alt' => 5,
					),
				),
			),
			'image over http'         => array( 'images', array( array( 'src' => 'http://media.example.com/a.jpg' ) ) ),
			'image over ftp'          => array( 'images', array( array( 'src' => 'ftp://media.example.com/a.jpg' ) ) ),
			'image a file path'       => array( 'images', array( array( 'src' => 'file:///etc/passwd' ) ) ),
			'image without a host'    => array( 'images', array( array( 'src' => 'https:///a.jpg' ) ) ),
			'image relative'          => array( 'images', array( array( 'src' => '/wp-content/a.jpg' ) ) ),
			'image with a space'      => array( 'images', array( array( 'src' => 'https://media.example.com/a b.jpg' ) ) ),
			'image src too long'      => array( 'images', array( array( 'src' => 'https://media.example.com/' . str_repeat( 'a', 2000 ) ) ) ),
			'image not an object'     => array( 'images', array( 'https://media.example.com/a.jpg' ) ),
		);
	}

	#[DataProvider( 'invalid_product_fields' )]
	public function test_an_update_with_an_invalid_field_is_refused_by_name( string $field, mixed $value ): void {
		$payload           = array( 'sku' => 'SH-12' );
		$payload[ $field ] = $value;

		$result = ( new PayloadFilter() )->check( self::change( PulledChange::PRODUCT_UPDATE, $payload ) );

		self::assert_invalid( $result, $field );
		self::assertInstanceOf( ChangeResult::class, $result );
		if ( 'sku' !== $field ) {
			self::assertStringNotContainsString( 'sku', $result->error_message );
		}
		self::assertStringNotContainsString( 'media.example.com', $result->error_message );
		self::assertStringNotContainsString( 'Shoes', $result->error_message );
	}

	public function test_every_refused_field_is_named(): void {
		$result = ( new PayloadFilter() )->check(
			self::change(
				PulledChange::PRODUCT_UPDATE,
				array(
					'price'  => '1',
					'sku'    => 'SH-12',
					'weight' => '2',
				)
			)
		);

		self::assert_invalid( $result, 'Fields not allowed or invalid: price, weight.' );
	}

	public function test_a_field_name_that_isnt_plain_is_counted_not_quoted(): void {
		$result = ( new PayloadFilter() )->check(
			self::change(
				PulledChange::PRODUCT_UPDATE,
				array(
					'<b>name</b>' => 'x',
					'price'       => '1',
				)
			)
		);

		self::assert_invalid( $result, 'price, 1 other.' );
		self::assertInstanceOf( ChangeResult::class, $result );
		self::assertStringNotContainsString( '<b>', $result->error_message );
	}

	/**
	 * Create-only fields with values the core never sends.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function invalid_create_fields(): array {
		return array(
			'type variable'      => array( 'type', 'variable' ),
			'type a number'      => array( 'type', 1 ),
			'another meta key'   => array(
				'meta_data',
				array(
					array(
						'key'   => '_price',
						'value' => '1',
					),
				),
			),
			'meta besides ours'  => array(
				'meta_data',
				array(
					array(
						'key'   => '_nyxify_outbox_id',
						'value' => '41',
					),
					array(
						'key'   => 'owner',
						'value' => 'x',
					),
				),
			),
			'meta an object'     => array(
				'meta_data',
				array(
					'key'   => '_nyxify_outbox_id',
					'value' => '41',
				),
			),
			'meta item a string' => array( 'meta_data', array( '_nyxify_outbox_id' ) ),
		);
	}

	#[DataProvider( 'invalid_create_fields' )]
	public function test_a_create_with_an_invalid_field_is_refused( string $field, mixed $value ): void {
		$payload           = array(
			'type' => 'simple',
			'name' => 'کیف',
		);
		$payload[ $field ] = $value;

		self::assert_invalid( ( new PayloadFilter() )->check( self::change( PulledChange::PRODUCT_CREATE, $payload, null ) ), $field );
	}

	/**
	 * Product fields a variation edit can't carry, and variation values the core never sends.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function invalid_variation_fields(): array {
		return array(
			'name'              => array( 'name', 'x' ),
			'images'            => array( 'images', array( array( 'id' => 12 ) ) ),
			'categories'        => array( 'categories', array( array( 'id' => 15 ) ) ),
			'short description' => array( 'short_description', 'x' ),
			'status draft'      => array( 'status', 'draft' ),
			'image a list'      => array( 'image', array( array( 'id' => 12 ) ) ),
			'image over http'   => array( 'image', array( 'src' => 'http://media.example.com/a.jpg' ) ),
			'image null'        => array( 'image', null ),
			'attributes'        => array( 'attributes', array() ),
		);
	}

	#[DataProvider( 'invalid_variation_fields' )]
	public function test_a_variation_edit_with_an_invalid_field_is_refused( string $field, mixed $value ): void {
		$payload = array(
			'sku'  => 'SH-12-42',
			$field => $value,
		);

		self::assert_invalid( ( new PayloadFilter() )->check( self::change( PulledChange::VARIATION_UPDATE, $payload, 13, 70 ) ), $field );
	}
}
