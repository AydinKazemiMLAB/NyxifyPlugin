<?php
/**
 * Tests for the "Bot description" tab.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use Nyxify\Features\Appearance\Tabs\BotProfileTab;
use Nyxify\Features\Appearance\Tabs\Fields;
use Nyxify\Tests\Unit\TestCase;

/**
 * The description and short description per language, in plain text, within Telegram's limits.
 */
final class BotProfileTabTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'sanitize_textarea_field' )->alias( fn( string $value ): string => trim( strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
	}

	public function test_the_tab_is_the_bot_profile_part(): void {
		self::assertSame( 'bot_profile', ( new BotProfileTab() )->part() );
		self::assertSame( 'Bot description', ( new BotProfileTab() )->label() );
	}

	public function test_the_texts_become_the_part(): void {
		$read = ( new BotProfileTab() )->read(
			array(
				'description'       => array(
					'fa' => "فروشگاه سارا\r\nسفارش آنلاین <b>لباس</b>",
					'en' => '',
				),
				'short_description' => array( 'en' => ' Sara shop ' ),
			)
		);

		self::assertSame( array(), $read->errors );
		self::assertSame(
			array(
				'description'       => array( 'fa' => "فروشگاه سارا\nسفارش آنلاین لباس" ),
				'short_description' => array( 'en' => 'Sara shop' ),
			),
			$read->part
		);
		self::assertCount( 4, $read->values );
	}

	public function test_an_empty_form_keeps_the_defaults(): void {
		self::assertNull( ( new BotProfileTab() )->read( array() )->part );
	}

	public function test_texts_over_their_limit_are_refused(): void {
		$read = ( new BotProfileTab() )->read(
			array(
				'description'       => array( 'en' => str_repeat( 'a', 512 ) ),
				'short_description' => array( 'fa' => str_repeat( 'س', 121 ) ),
			)
		);

		self::assertSame( array( 'short_description.fa' => Fields::TOO_LONG ), $read->errors );
		self::assertSame( 'Up to 120 characters.', BotProfileTab::error_message( 'short_description.fa', Fields::TOO_LONG ) );
		self::assertSame( 'Up to 512 characters.', BotProfileTab::error_message( 'description.en', Fields::TOO_LONG ) );
		self::assertSame( 'The Nyxify server did not accept this value.', BotProfileTab::error_message( 'description.en', Fields::REJECTED ) );
	}

	public function test_the_stored_part_fills_the_form(): void {
		$values = ( new BotProfileTab() )->values(
			array(
				'description'       => array( 'fa' => 'فروشگاه سارا' ),
				'short_description' => null,
			)
		);

		self::assertSame(
			array(
				'description.fa'       => 'فروشگاه سارا',
				'description.en'       => '',
				'short_description.fa' => '',
				'short_description.en' => '',
			),
			$values
		);
	}

	public function test_render_prints_both_texts_per_language(): void {
		$tab = new BotProfileTab();
		ob_start();
		$tab->render( $tab->values( array( 'short_description' => array( 'en' => 'Sara & co' ) ) ), array( 'description.fa' => Fields::TOO_LONG ), null );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="nyxify_bot_profile[description][fa]" dir="rtl" maxlength="512"', $html );
		self::assertStringContainsString( 'name="nyxify_bot_profile[short_description][en]" dir="ltr" maxlength="120"', $html );
		self::assertStringContainsString( '>Sara &amp; co</textarea>', $html );
		self::assertStringContainsString( 'id="nyxify-bot-profile-description-fa-error">Up to 512 characters.', $html );
		self::assertStringContainsString( 'Bale and Rubika have no bot descriptions', $html );
	}
}
