<?php
/**
 * Tests for CategoryWriter.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Sync;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Sync\CategoryWriter;
use Nyxify\Features\Sync\ChangeResult;
use Nyxify\Features\Sync\PayloadFilter;
use Nyxify\Features\Sync\PulledChange;
use Nyxify\Tests\Unit\TestCase;

/**
 * One category change through a fake in-process REST dispatcher (standing in for WooCommerce's category controller),
 * with the term lookup, the term meta and the default category option faked.
 */
final class CategoryWriterTest extends TestCase {

	private const ROUTE = '/wc/v3/products/categories';

	/**
	 * What the dispatcher answers: status and data.
	 *
	 * @var array{int, mixed}
	 */
	private array $answer = array( 201, array( 'id' => 77 ) );

	/**
	 * Each dispatched request: method, route and body.
	 *
	 * @var list<array{method: string, route: string, body: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * What get_terms() returns for the outbox meta lookup.
	 *
	 * @var mixed
	 */
	private $existing = array();

	/**
	 * Arguments of each get_terms() call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $lookups = array();

	/**
	 * Term meta written: term id, key and value.
	 *
	 * @var list<array{int, string, string}>
	 */
	private array $meta = array();

	/**
	 * The default product category's id (WooCommerce's `default_product_cat` option).
	 *
	 * @var int
	 */
	private int $default_category = 15;

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_terms' )->alias(
			function ( array $args ) {
				$this->lookups[] = $args;
				return $this->existing;
			}
		);
		Functions\when( 'update_term_meta' )->alias(
			function ( int $term_id, string $key, string $value ): int {
				$this->meta[] = array( $term_id, $key, $value );
				return 1;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default_value = false ) {
				if ( 'default_product_cat' === $name ) {
					return $this->default_category;
				}
				return $default_value;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $text ): string => trim( strip_tags( $text ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		);
	}

	private function writer(): CategoryWriter {
		return new CategoryWriter(
			function ( string $method, string $route, array $body ): array {
				$this->requests[] = array(
					'method' => $method,
					'route'  => $route,
					'body'   => $body,
				);
				return $this->answer;
			}
		);
	}

	/**
	 * A category change as the core sends it.
	 *
	 * @param string               $action   Action.
	 * @param array<string, mixed> $payload  Payload.
	 * @param int|null             $category Category id.
	 * @param int                  $id       Outbox id.
	 */
	private static function change( string $action, array $payload = array(), ?int $category = 12, int $id = 52 ): PulledChange {
		$change = PulledChange::from_response(
			array(
				'id'               => $id,
				'action'           => $action,
				'woo_product_id'   => null,
				'woo_variation_id' => null,
				'woo_category_id'  => $category,
				'payload'          => $payload,
				'created_at'       => '2026-11-15T08:15:30Z',
			)
		);
		self::assertNotNull( $change );

		return $change;
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

	// ------------------------------------------------------------------ create

	public function test_a_create_posts_the_category_and_keeps_the_outbox_id_as_term_meta(): void {
		$body   = array(
			'name'   => 'کیف',
			'parent' => 3,
		);
		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_CREATE, $body, null ), $body );

		self::assertTrue( $result->success );
		self::assertSame( 77, $result->woo_category_id );
		self::assertNull( $result->woo_product_id );
		self::assertSame(
			array(
				array(
					'method' => 'POST',
					'route'  => self::ROUTE,
					'body'   => $body,
				),
			),
			$this->requests
		);
		self::assertSame( array( array( 77, '_nyxify_outbox_id', '52' ) ), $this->meta );
	}

	public function test_a_create_first_looks_for_the_outbox_id(): void {
		$this->writer()->write( self::change( PulledChange::CATEGORY_CREATE, array( 'name' => 'x' ), null ), array( 'name' => 'x' ) );

		self::assertCount( 1, $this->lookups );
		self::assertSame( 'product_cat', $this->lookups[0]['taxonomy'] );
		self::assertSame( PayloadFilter::OUTBOX_META_KEY, $this->lookups[0]['meta_key'] );
		self::assertSame( '52', $this->lookups[0]['meta_value'] );
		self::assertSame( 'ids', $this->lookups[0]['fields'] );
		self::assertSame( 1, $this->lookups[0]['number'] );
		self::assertFalse( $this->lookups[0]['hide_empty'] );
	}

	public function test_a_create_already_made_is_reported_and_not_made_twice(): void {
		$this->existing = array( '88' );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_CREATE, array( 'name' => 'x' ), null ), array( 'name' => 'x' ) );

		self::assertTrue( $result->success );
		self::assertSame( 88, $result->woo_category_id );
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->meta );
	}

	public function test_a_failed_lookup_does_not_stop_the_create(): void {
		$this->existing = new \stdClass(); // A WP_Error.

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_CREATE, array( 'name' => 'x' ), null ), array( 'name' => 'x' ) );

		self::assertTrue( $result->success );
		self::assertCount( 1, $this->requests );
	}

	public function test_a_refused_create_fails_with_woocommerces_code_and_leaves_no_meta(): void {
		$this->answer = self::error( 400, 'term_exists', 'A term with the name provided already exists.' );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_CREATE, array( 'name' => 'x' ), null ), array( 'name' => 'x' ) );

		self::assertFalse( $result->success );
		self::assertSame( 'term_exists', $result->error_code );
		self::assertSame( 'A term with the name provided already exists.', $result->error_message );
		self::assertSame( array(), $this->meta );
	}

	/**
	 * Answers without a usable category id.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function answers_without_an_id(): array {
		return array(
			'no id'     => array( array() ),
			'zero'      => array( array( 'id' => 0 ) ),
			'as a text' => array( array( 'id' => '77' ) ),
			'not data'  => array( 'created' ),
		);
	}

	#[DataProvider( 'answers_without_an_id' )]
	public function test_a_create_answered_without_an_id_fails( mixed $data ): void {
		$this->answer = array( 201, $data );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_CREATE, array( 'name' => 'x' ), null ), array( 'name' => 'x' ) );

		self::assertFalse( $result->success );
		self::assertSame( ChangeResult::WOOCOMMERCE_ERROR, $result->error_code );
		self::assertSame( array(), $this->meta );
	}

	// ------------------------------------------------------------------ update

	public function test_an_update_puts_the_fields_to_change(): void {
		$this->answer = array( 200, array( 'id' => 12 ) );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_UPDATE, array( 'menu_order' => 2 ) ), array( 'menu_order' => 2 ) );

		self::assertTrue( $result->success );
		self::assertSame( 12, $result->woo_category_id );
		self::assertSame(
			array(
				array(
					'method' => 'PUT',
					'route'  => self::ROUTE . '/12',
					'body'   => array( 'menu_order' => 2 ),
				),
			),
			$this->requests
		);
		self::assertSame( array(), $this->lookups );
	}

	public function test_an_update_of_a_category_woocommerce_does_not_know_is_category_not_found(): void {
		$this->answer = self::error( 404, 'woocommerce_rest_term_invalid', 'Resource does not exist.' );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_UPDATE, array( 'name' => 'x' ) ), array( 'name' => 'x' ) );

		self::assertFalse( $result->success );
		self::assertSame( 'category_not_found', $result->error_code );
		self::assertSame( 'Resource does not exist.', $result->error_message );
	}

	public function test_other_update_errors_keep_their_code(): void {
		$this->answer = self::error( 400, 'woocommerce_rest_invalid_parent', 'Invalid parent.' );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_UPDATE, array( 'parent' => 999 ) ), array( 'parent' => 999 ) );

		self::assertFalse( $result->success );
		self::assertSame( 'woocommerce_rest_invalid_parent', $result->error_code );
	}

	public function test_an_error_message_loses_its_tags_and_links(): void {
		$this->answer = self::error( 400, 'woocommerce_rest_image_upload_error', 'Cannot <b>download</b> https://bot.example.com/m/a.png?signature=s now' );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_UPDATE, array( 'name' => 'x' ) ), array( 'name' => 'x' ) );

		self::assertSame( 'Cannot download [link] now', $result->error_message );
	}

	// ------------------------------------------------------------------ delete

	public function test_a_delete_is_for_good(): void {
		$this->answer = array( 200, array( 'deleted' => true ) );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_DELETE ), array() );

		self::assertTrue( $result->success );
		self::assertSame( 12, $result->woo_category_id );
		self::assertSame(
			array(
				array(
					'method' => 'DELETE',
					'route'  => self::ROUTE . '/12',
					'body'   => array( 'force' => true ),
				),
			),
			$this->requests
		);
	}

	public function test_the_default_category_is_never_deleted(): void {
		$this->default_category = 12;

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_DELETE ), array() );

		self::assertFalse( $result->success );
		self::assertSame( 'default_category_protected', $result->error_code );
		self::assertSame( array(), $this->requests );
	}

	public function test_a_category_already_gone_counts_as_deleted(): void {
		$this->answer = self::error( 404, 'woocommerce_rest_term_invalid', 'Resource does not exist.' );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_DELETE ), array() );

		self::assertTrue( $result->success );
		self::assertSame( 12, $result->woo_category_id );
	}

	public function test_other_delete_errors_fail_with_their_code(): void {
		$this->answer = self::error( 500, 'woocommerce_rest_cannot_delete', 'The category cannot be deleted.' );

		$result = $this->writer()->write( self::change( PulledChange::CATEGORY_DELETE ), array() );

		self::assertFalse( $result->success );
		self::assertSame( 'woocommerce_rest_cannot_delete', $result->error_code );
	}
}
