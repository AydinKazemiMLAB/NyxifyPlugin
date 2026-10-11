<?php
/**
 * Tests for the safe logger.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Shared\Logging;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Tests\Unit\TestCase;

/**
 * Redaction, context cleaning and the WooCommerce logger calls.
 */
final class LoggerTest extends TestCase {

	/**
	 * Entries written to the fake WooCommerce logger.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	/**
	 * A WooCommerce logger that records its entries.
	 *
	 * @return \WC_Logger_Interface&\Mockery\MockInterface
	 */
	private function wc_logger() {
		$logger = Mockery::mock( 'WC_Logger_Interface' );
		$logger->shouldReceive( 'log' )->andReturnUsing(
			function ( string $level, string $message, array $context ): void {
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		);

		return $logger;
	}

	/**
	 * Values that must never be logged.
	 *
	 * @return array<string, array{string}>
	 */
	public static function secrets(): array {
		return array(
			'shared secret'          => array( 'sec_' . str_repeat( 'a1', 32 ) ),
			'consumer key'           => array( 'ck_' . str_repeat( 'b2', 20 ) ),
			'consumer secret'        => array( 'cs_' . str_repeat( 'c3', 20 ) ),
			'license key'            => array( 'NX-ABCDEF-GHIJKL-MNOPQR-STUVWX' ),
			'license key, lowercase' => array( 'nx-abcdef-ghijkl-mnopqr-stuvwx' ),
			'license key, spaces'    => array( 'NX-ABCDEF GHIJKL MNOPQR STUVWX' ),
			'telegram token'         => array( '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw' ),
			'bale token'             => array( '1234567890:' . str_repeat( 'x', 40 ) ),
			'rubika token'           => array( str_repeat( 'Ab1_', 16 ) ),
			'mobile number'          => array( '09121234567' ),
			'mobile number, +98'     => array( '+989121234567' ),
		);
	}

	/**
	 * Every secret pattern is redacted, in the middle of a sentence too.
	 *
	 * @param string $secret A secret value.
	 */
	#[DataProvider( 'secrets' )]
	public function test_secrets_are_redacted( string $secret ): void {
		$redacted = Logger::redact( "before {$secret} after" );

		self::assertStringNotContainsString( $secret, $redacted );
		self::assertStringContainsString( Logger::REDACTED, $redacted );
		self::assertStringStartsWith( 'before ', $redacted );
		self::assertStringEndsWith( ' after', $redacted );
	}

	/**
	 * Values the log needs and that are not secret.
	 *
	 * @return array<string, array{string}>
	 */
	public static function safe_values(): array {
		return array(
			'store id'           => array( 'shop_8f2a0c1d3e4b5a69' ),
			'plugin request id'  => array( 'nx-0123456789abcdef01234567' ),
			'core request id'    => array( 'f47ac10b58cc4372a5670e02b2c3d479' ),
			'uuid'               => array( 'f47ac10b-58cc-4372-a567-0e02b2c3d479' ),
			'license key prefix' => array( 'NX-ABCDEF' ),
			'error code'         => array( 'woocommerce_authentication_failed' ),
			'timestamp'          => array( '1789234567' ),
			'time of day'        => array( '12:30' ),
			'order number'       => array( '#4521' ),
		);
	}

	/**
	 * Ids, codes and numbers stay readable.
	 *
	 * @param string $value A value that isn't secret.
	 */
	#[DataProvider( 'safe_values' )]
	public function test_safe_values_are_kept( string $value ): void {
		self::assertSame( "value {$value}.", Logger::redact( "value {$value}." ) );
	}

	public function test_line_breaks_cannot_forge_entries(): void {
		self::assertSame( 'a\nb\rc', Logger::redact( "a\nb\rc" ) );
	}

	public function test_entries_have_the_source_and_clean_context(): void {
		$secret = 'sec_' . str_repeat( 'f0', 32 );

		( new Logger( $this->wc_logger() ) )->warning(
			"Rotation failed for {$secret}",
			array(
				'request_id' => 'nx-0123456789abcdef01234567',
				'detail'     => "token {$secret}",
				'status'     => 401,
				'retry'      => false,
				'payload'    => array( 'secret' => $secret ),
				'Bad Key'    => 'x',
				'source'     => 'other-plugin',
			)
		);

		self::assertCount( 1, $this->entries );
		self::assertSame( 'warning', $this->entries[0]['level'] );
		self::assertSame( 'Rotation failed for ' . Logger::REDACTED, $this->entries[0]['message'] );
		self::assertSame(
			array(
				'request_id' => 'nx-0123456789abcdef01234567',
				'detail'     => 'token ' . Logger::REDACTED,
				'status'     => 401,
				'retry'      => false,
				'source'     => Logger::SOURCE,
			),
			$this->entries[0]['context']
		);
	}

	public function test_levels(): void {
		$logger = new Logger( $this->wc_logger() );
		$logger->error( 'e' );
		$logger->warning( 'w' );
		$logger->info( 'i' );
		$logger->debug( 'd' );

		self::assertSame( array( 'error', 'warning', 'info', 'debug' ), array_column( $this->entries, 'level' ) );
	}

	/**
	 * Core failures and the level they are logged at.
	 *
	 * @return array<string, array{string, int, string}>
	 */
	public static function core_errors(): array {
		return array(
			'network'      => array( CoreError::NETWORK_ERROR, 0, 'error' ),
			'server error' => array( 'internal_server_error', 500, 'error' ),
			'owner action' => array( 'no_active_subscription', 403, 'warning' ),
		);
	}

	/**
	 * A core failure logs only the operation, code, status and request id.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @param string $level  Expected level.
	 */
	#[DataProvider( 'core_errors' )]
	public function test_core_error( string $code, int $status, string $level ): void {
		( new Logger( $this->wc_logger() ) )->core_error( 'store/status', new CoreError( $code, $status, 'nx-0123456789abcdef01234567' ) );

		self::assertSame( $level, $this->entries[0]['level'] );
		self::assertSame(
			array(
				'operation'  => 'store/status',
				'error_code' => $code,
				'status'     => $status,
				'request_id' => 'nx-0123456789abcdef01234567',
				'source'     => Logger::SOURCE,
			),
			$this->entries[0]['context']
		);
	}

	public function test_the_woocommerce_logger_is_looked_up_once(): void {
		Functions\expect( 'wc_get_logger' )->once()->andReturn( $this->wc_logger() );

		$logger = new Logger();
		$logger->info( 'one' );
		$logger->info( 'two' );

		self::assertCount( 2, $this->entries );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_nothing_happens_without_woocommerce(): void {
		self::assertFalse( function_exists( 'wc_get_logger' ) );

		( new Logger() )->error( 'WooCommerce is inactive' );

		$this->addToAssertionCount( 1 );
	}
}
