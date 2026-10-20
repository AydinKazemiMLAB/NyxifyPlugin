<?php
/**
 * Tests for the checks of the bots' markup.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Appearance\Tabs\Markup;
use Nyxify\Tests\Unit\TestCase;

/**
 * Variables and doubled braces as the core and the bot read them; the length Telegram counts.
 */
final class MarkupTest extends TestCase {

	/**
	 * @return array<string, array{string, list<string>, bool}>
	 */
	public static function texts(): array {
		return array(
			'no braces'         => array( 'Hello', array(), true ),
			'allowed variables' => array( '{name} at {shop}', array( 'name', 'shop' ), true ),
			'doubled braces'    => array( '{{name}} and }}', array(), true ),
			'another variable'  => array( 'Hello {order}', array( 'name' ), false ),
			'no variables'      => array( 'Hello {name}', array(), false ),
			'a stray brace'     => array( 'a { b', array( 'name' ), false ),
			'a closing brace'   => array( 'a } b', array( 'name' ), false ),
			'not a name'        => array( 'Hello {Name}', array( 'Name' ), false ),
			'a space inside'    => array( 'Hello { name }', array( 'name' ), false ),
		);
	}

	/**
	 * @param string       $text      The text.
	 * @param list<string> $variables Its variables.
	 * @param bool         $ok        Whether it passes.
	 */
	#[DataProvider( 'texts' )]
	public function test_variables( string $text, array $variables, bool $ok ): void {
		self::assertSame( $ok, Markup::variables_ok( $text, $variables ) );
	}

	public function test_the_visible_length_skips_tags_reads_entities_and_counts_utf16(): void {
		self::assertSame( 5, Markup::visible_length( '<b>Hello</b>' ) );
		self::assertSame( 5, Markup::visible_length( 'a &amp; b' ) );
		self::assertSame( 5, Markup::visible_length( 'a &lt; b' ) );
		self::assertSame( 2, Markup::visible_length( '🛒' ) );
		self::assertSame( 4, Markup::visible_length( 'سلام' ) );
		self::assertSame( 2, Markup::visible_length( '<tg-emoji emoji-id="5368324170671202286">🛒</tg-emoji>' ) );
	}
}
