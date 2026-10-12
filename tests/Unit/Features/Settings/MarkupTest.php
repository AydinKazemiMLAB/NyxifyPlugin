<?php
/**
 * Tests for the settings page's small pieces of markup.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Settings;

use Brain\Monkey\Functions;
use Nyxify\Features\Settings\Markup;
use Nyxify\Tests\Unit\TestCase;

/**
 * Badges, icons and card headings: escaped, and only the icons the stylesheet knows.
 */
final class MarkupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubEscapeFunctions();
	}

	/**
	 * What a callback prints.
	 *
	 * @param callable $callback The callback.
	 */
	private static function printed( callable $callback ): string {
		ob_start();
		$callback();

		return (string) ob_get_clean();
	}

	public function test_a_pill_is_escaped(): void {
		self::assertSame(
			'<span class="nyxify-pill nyxify-pill--ok">&lt;b&gt;Active&lt;/b&gt;</span>',
			self::printed( fn() => Markup::pill( Markup::OK, '<b>Active</b>' ) )
		);
	}

	public function test_a_busy_pill_pulses(): void {
		self::assertSame(
			'<span class="nyxify-pill nyxify-pill--info nyxify-pill--busy">Syncing…</span>',
			self::printed( fn() => Markup::pill( Markup::INFO, 'Syncing…', true ) )
		);
	}

	public function test_icons_are_hidden_and_only_known_ones(): void {
		self::assertSame( '<span class="nyxify-icon nyxify-icon--sync" aria-hidden="true"></span>', self::printed( fn() => Markup::icon( 'sync' ) ) );
		self::assertSame( '', self::printed( fn() => Markup::icon( 'telegram' ) ) );
	}

	public function test_a_card_heading(): void {
		self::assertSame(
			'<div class="nyxify-card__head"><span class="nyxify-icon nyxify-icon--bots" aria-hidden="true"></span><h2 id="nyxify-bots-heading">Bots &amp; more</h2></div>',
			self::printed( fn() => Markup::card_heading( 'bots', 'Bots & more', 'nyxify-bots-heading' ) )
		);
	}

	public function test_every_icon_has_a_rule_in_the_stylesheet(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/css/admin.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( array( 'store', 'plan', 'products', 'sync', 'bots', 'pin', 'admins', 'connection', 'lock', 'alert' ) as $icon ) {
			self::assertStringContainsString( '.nyxify-icon--' . $icon . ' {', $css, $icon );
			self::assertSame( '<span class="nyxify-icon nyxify-icon--' . $icon . '" aria-hidden="true"></span>', self::printed( fn() => Markup::icon( $icon ) ) );
		}
	}
}
