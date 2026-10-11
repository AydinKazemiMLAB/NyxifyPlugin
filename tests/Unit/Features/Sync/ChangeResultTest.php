<?php
/**
 * Tests for ChangeResult.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Sync;

use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Sync\ChangeResult;
use Nyxify\Tests\Unit\TestCase;

/**
 * The ack's results stay within the core's limits (contract §5.2), so one result can't make it refuse the whole ack.
 */
final class ChangeResultTest extends TestCase {

	public function test_a_success_sends_its_id_only(): void {
		$result = ChangeResult::success( 41 );

		self::assertTrue( $result->success );
		self::assertNull( $result->error_code );
		self::assertSame(
			array(
				'id'      => 41,
				'success' => true,
			),
			$result->to_ack()
		);
	}

	public function test_a_success_keeps_the_product_id_and_date(): void {
		$result = ChangeResult::success( 44, 77 )->with_date( '2026-10-11T08:16:03' );

		self::assertSame(
			array(
				'id'                => 44,
				'success'           => true,
				'woo_product_id'    => 77,
				'date_modified_gmt' => '2026-10-11T08:16:03',
			),
			$result->to_ack()
		);
	}

	public function test_with_date_leaves_the_original_unchanged(): void {
		$result = ChangeResult::success( 41, 13 );

		$dated = $result->with_date( '2026-10-11T08:16:03' );

		self::assertNull( $result->date_modified_gmt );
		self::assertSame( '2026-10-11T08:16:03', $dated->date_modified_gmt );
		self::assertSame( 13, $dated->woo_product_id );
		self::assertSame( 41, $dated->id );
	}

	public function test_a_null_date_clears_it(): void {
		$result = ChangeResult::success( 41 )->with_date( '2026-10-11T08:16:03' )->with_date( null );

		self::assertArrayNotHasKey( 'date_modified_gmt', $result->to_ack() );
	}

	/**
	 * Product ids the core refuses.
	 *
	 * @return array<string, array{int}>
	 */
	public static function invalid_product_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -5 ),
		);
	}

	#[DataProvider( 'invalid_product_ids' )]
	public function test_a_product_id_below_one_is_left_out( int $id ): void {
		$result = ChangeResult::success( 41, $id );

		self::assertNull( $result->woo_product_id );
		self::assertArrayNotHasKey( 'woo_product_id', $result->to_ack() );
	}

	/**
	 * Dates the core would refuse (or read differently): only `Y-m-d\TH:i:s` of a real moment is sent.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_dates(): array {
		return array(
			'empty'            => array( '' ),
			'date only'        => array( '2026-10-11' ),
			'with an offset'   => array( '2026-10-11T08:16:03+03:30' ),
			'with Z'           => array( '2026-10-11T08:16:03Z' ),
			'with fractions'   => array( '2026-10-11T08:16:03.5' ),
			'a space'          => array( '2026-10-11 08:16:03' ),
			'31 February'      => array( '2026-02-31T08:16:03' ),
			'hour 25'          => array( '2026-10-11T25:16:03' ),
			'not a date'       => array( 'yesterday' ),
			'a trailing break' => array( "2026-10-11T08:16:03\n" ),
		);
	}

	#[DataProvider( 'invalid_dates' )]
	public function test_a_date_the_core_would_refuse_is_left_out( string $date ): void {
		$result = ChangeResult::success( 41 )->with_date( $date );

		self::assertNull( $result->date_modified_gmt );
		self::assertArrayNotHasKey( 'date_modified_gmt', $result->to_ack() );
	}

	public function test_a_failure_sends_its_code_and_message(): void {
		$result = ChangeResult::failure( 45, 'woocommerce_rest_product_invalid_sku', 'Invalid or duplicated SKU.' );

		self::assertFalse( $result->success );
		self::assertSame(
			array(
				'id'            => 45,
				'success'       => false,
				'error_code'    => 'woocommerce_rest_product_invalid_sku',
				'error_message' => 'Invalid or duplicated SKU.',
			),
			$result->to_ack()
		);
	}

	public function test_a_failure_without_a_message_leaves_it_out(): void {
		self::assertSame(
			array(
				'id'         => 46,
				'success'    => false,
				'error_code' => ChangeResult::INVALID_CHANGE,
			),
			ChangeResult::failure( 46, ChangeResult::INVALID_CHANGE, "  \n " )->to_ack()
		);
	}

	public function test_a_failure_keeps_no_date(): void {
		$result = ChangeResult::failure( 45, ChangeResult::PLUGIN_ERROR )->with_date( '2026-10-11T08:16:03' );

		self::assertNull( $result->date_modified_gmt );
		self::assertArrayNotHasKey( 'date_modified_gmt', $result->to_ack() );
		self::assertSame( ChangeResult::PLUGIN_ERROR, $result->error_code );
	}

	/**
	 * Codes the core refuses (`[a-z0-9_]{1,100}` only).
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_codes(): array {
		return array(
			'empty'        => array( '' ),
			'upper case'   => array( 'Invalid_SKU' ),
			'a dot'        => array( 'rest.invalid' ),
			'a space'      => array( 'invalid sku' ),
			'a line break' => array( "invalid_sku\n" ),
			'101 long'     => array( str_repeat( 'a', 101 ) ),
			'not ascii'    => array( 'خطا' ),
			'a dash'       => array( 'rest-invalid' ),
		);
	}

	#[DataProvider( 'invalid_codes' )]
	public function test_a_code_the_core_would_refuse_becomes_plugin_error( string $code ): void {
		self::assertSame( ChangeResult::PLUGIN_ERROR, ChangeResult::failure( 45, $code, 'Refused.' )->error_code );
	}

	public function test_a_code_of_a_hundred_characters_is_kept(): void {
		$code = str_repeat( 'a', 100 );

		self::assertSame( $code, ChangeResult::failure( 45, $code )->error_code );
	}

	public function test_a_long_message_is_cut_to_a_thousand_characters(): void {
		$message = str_repeat( 'خطای ووکامرس ', 120 );

		$result = ChangeResult::failure( 45, 'woocommerce_error', $message );

		self::assertSame( ChangeResult::MAX_MESSAGE_LENGTH, mb_strlen( $result->error_message, 'UTF-8' ) );
		self::assertSame( mb_substr( $message, 0, ChangeResult::MAX_MESSAGE_LENGTH, 'UTF-8' ), $result->error_message );
		self::assertSame( 1, preg_match( '//u', $result->error_message ) );
	}

	public function test_a_message_is_trimmed(): void {
		self::assertSame( 'Refused.', ChangeResult::failure( 45, 'woocommerce_error', "  Refused.\n" )->error_message );
	}

	public function test_a_message_that_isnt_utf8_is_dropped(): void {
		$result = ChangeResult::failure( 45, 'woocommerce_error', "Bad \xC3\x28 bytes" );

		self::assertSame( '', $result->error_message );
		self::assertArrayNotHasKey( 'error_message', $result->to_ack() );
	}

	public function test_the_message_stays_out_of_debug_output(): void {
		$dumped = print_r( ChangeResult::failure( 45, 'woocommerce_error', 'Product Kafsh refused.' ), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		self::assertStringContainsString( 'woocommerce_error', $dumped );
		self::assertStringNotContainsString( 'Kafsh', $dumped );
	}
}
