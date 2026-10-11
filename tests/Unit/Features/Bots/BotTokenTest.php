<?php
/**
 * Tests for the bot token value.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Bots;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Bots\BotToken;
use Nyxify\Tests\Unit\TestCase;

/**
 * Trimming, the format check and keeping the token out of debug output.
 */
final class BotTokenTest extends TestCase {

	private const TOKEN = '123456789:AAF0dummy-Token_value';

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function tokens(): array {
		return array(
			'telegram style'      => array( self::TOKEN, self::TOKEN ),
			'surrounding spaces'  => array( "  \t" . self::TOKEN . "\r\n", self::TOKEN ),
			'non-breaking space'  => array( "\u{00A0}" . self::TOKEN . "\u{00A0}", self::TOKEN ),
			'percent kept'        => array( 'ab%41cd%2Fef', 'ab%41cd%2Fef' ),
			'punctuation kept'    => array( 'a!"#$&\'()*+,-./:;<=>?@[]^_`{|}~z', 'a!"#$&\'()*+,-./:;<=>?@[]^_`{|}~z' ),
			'one character'       => array( 'x', 'x' ),
			'three hundred chars' => array( str_repeat( 'A', 300 ), str_repeat( 'A', 300 ) ),
		);
	}

	#[DataProvider( 'tokens' )]
	public function test_well_formed_tokens_are_kept( string $raw, string $expected ): void {
		$token = BotToken::parse( $raw );

		self::assertNotNull( $token );
		self::assertSame( $expected, $token->value() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformed_tokens(): array {
		return array(
			'empty'               => array( '' ),
			'only spaces'         => array( "  \t\n" ),
			'space inside'        => array( '123456789:AAF0 dummy' ),
			'tab inside'          => array( "123456789:\tAAF0" ),
			'line break inside'   => array( "123456789:\nAAF0" ),
			'non-ASCII'           => array( '123456789:AAFé' ),
			'Persian digits'      => array( '۱۲۳۴۵۶:AAF0' ),
			'control character'   => array( "123456789:AAF0\x7F" ),
			'null byte'           => array( "123456789:AAF0\0" ),
			'invalid UTF-8'       => array( "123456789:\xC3\x28" ),
			'301 characters'      => array( str_repeat( 'A', 301 ) ),
			'non-breaking inside' => array( "123456789:\u{00A0}AAF0" ),
		);
	}

	#[DataProvider( 'malformed_tokens' )]
	public function test_malformed_tokens_are_refused( string $raw ): void {
		self::assertNull( BotToken::parse( $raw ) );
	}

	public function test_form_input_is_unslashed(): void {
		$token = BotToken::from_input( 'ab\\\\cd\\"ef' );

		self::assertNotNull( $token );
		self::assertSame( 'ab\\cd"ef', $token->value() );
	}

	public function test_form_input_must_be_a_string(): void {
		self::assertNull( BotToken::from_input( null ) );
		self::assertNull( BotToken::from_input( array( self::TOKEN ) ) );
		self::assertNull( BotToken::from_input( 123456 ) );
	}

	public function test_debug_output_hides_the_token(): void {
		$token = BotToken::parse( self::TOKEN );
		self::assertNotNull( $token );

		ob_start();
		var_dump( $token ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- The test checks what var_dump() prints.
		$dumped = (string) ob_get_clean();

		self::assertStringNotContainsString( 'AAF0dummy', $dumped );
		self::assertStringNotContainsString( 'AAF0dummy', print_r( $token, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- The test checks what print_r() prints.
	}
}
