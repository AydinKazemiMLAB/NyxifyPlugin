<?php
/**
 * Tests for the "Back button" tab and the button look fields.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Appearance\Tabs\BackButtonTab;
use Nyxify\Features\Appearance\Tabs\ButtonFields;
use Nyxify\Features\Appearance\Tabs\Fields;
use Nyxify\Tests\Unit\TestCase;

/**
 * The back button's title per language, icon (an emoji, and a premium emoji's id) and color: read, checked like the
 * bot's editors check them, and printed back.
 */
final class BackButtonTabTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'sanitize_text_field' )->alias( fn( string $value ): string => trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
		Functions\when( 'selected' )->alias( fn( $selected, $current ): string => (string) $selected === (string) $current ? " selected='selected'" : '' );
	}

	public function test_the_tab_is_the_back_button_part(): void {
		self::assertSame( 'back_button', ( new BackButtonTab() )->part() );
		self::assertSame( 'Back button', ( new BackButtonTab() )->label() );
	}

	public function test_every_field_becomes_the_look(): void {
		$read = ( new BackButtonTab() )->read(
			array(
				'title' => array(
					'fa' => ' برگشت ',
					'en' => 'Go back',
				),
				'icon'  => array(
					'emoji'           => '🔙',
					'custom_emoji_id' => '۵۳۶۸۳۲۴۱۷۰۶۷۱۲۰۲۲۸۶',
				),
				'style' => 'success',
			)
		);

		self::assertSame( array(), $read->errors );
		self::assertSame(
			array(
				'title' => array(
					'fa' => 'برگشت',
					'en' => 'Go back',
				),
				'icon'  => array(
					'emoji'           => '🔙',
					'custom_emoji_id' => '5368324170671202286',
				),
				'style' => 'success',
			),
			$read->part
		);
		self::assertSame( '5368324170671202286', $read->values['icon.custom_emoji_id'] );
	}

	public function test_an_empty_form_keeps_the_defaults(): void {
		$read = ( new BackButtonTab() )->read(
			array(
				'title' => array(
					'fa' => '',
					'en' => ' ',
				),
				'icon'  => array(
					'emoji'           => '',
					'custom_emoji_id' => '',
				),
				'style' => '',
			)
		);

		self::assertNull( $read->part );
		self::assertSame( array(), $read->errors );
		self::assertCount( 5, $read->values );
	}

	public function test_a_title_in_one_language_and_an_emoji_alone(): void {
		$read = ( new BackButtonTab() )->read(
			array(
				'title' => array( 'en' => 'Back' ),
				'icon'  => array( 'emoji' => '⬅️' ),
			)
		);

		self::assertSame(
			array(
				'title' => array( 'en' => 'Back' ),
				'icon'  => array( 'emoji' => '⬅️' ),
			),
			$read->part
		);
	}

	public function test_an_id_and_emoji_pasted_into_the_emoji_box_are_split(): void {
		$read = ( new BackButtonTab() )->read( array( 'icon' => array( 'emoji' => '5368324170671202286 🛒' ) ) );

		self::assertSame(
			array(
				'icon' => array(
					'emoji'           => '🛒',
					'custom_emoji_id' => '5368324170671202286',
				),
			),
			$read->part
		);
		self::assertSame( '🛒', $read->values['icon.emoji'] );
		self::assertSame( '5368324170671202286', $read->values['icon.custom_emoji_id'] );
	}

	public function test_a_premium_id_needs_its_emoji(): void {
		$read = ( new BackButtonTab() )->read( array( 'icon' => array( 'custom_emoji_id' => '5368324170671202286' ) ) );

		self::assertSame( array( 'icon.emoji' => Fields::MISSING ), $read->errors );
		self::assertStringContainsString( 'Add the emoji too', (string) ButtonFields::error_message( 'icon.emoji', Fields::MISSING, '' ) );
	}

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalid_fields(): array {
		return array(
			'a word'            => array( array( 'icon' => array( 'emoji' => 'back' ) ), 'icon.emoji' ),
			'an emoji and text' => array( array( 'icon' => array( 'emoji' => '🔙 back' ) ), 'icon.emoji' ),
			'a digit alone'     => array( array( 'icon' => array( 'emoji' => '1' ) ), 'icon.emoji' ),
			'too many emoji'    => array( array( 'icon' => array( 'emoji' => str_repeat( '🔙', 17 ) ) ), 'icon.emoji' ),
			'an id with text'   => array(
				array(
					'icon' => array(
						'emoji'           => '🔙',
						'custom_emoji_id' => 'abc',
					),
				),
				'icon.custom_emoji_id',
			),
			'an id too long'    => array(
				array(
					'icon' => array(
						'emoji'           => '🔙',
						'custom_emoji_id' => str_repeat( '1', 21 ),
					),
				),
				'icon.custom_emoji_id',
			),
			'another color'     => array( array( 'style' => 'purple' ), 'style' ),
			'a long title'      => array( array( 'title' => array( 'fa' => str_repeat( 'ب', 41 ) ) ), 'title.fa' ),
		);
	}

	/**
	 * @param array<string, mixed> $input The posted fields.
	 * @param string               $field The field that fails.
	 */
	#[DataProvider( 'invalid_fields' )]
	public function test_an_invalid_field_is_refused( array $input, string $field ): void {
		$read = ( new BackButtonTab() )->read( $input );

		self::assertSame( array( $field ), array_keys( $read->errors ) );
		self::assertNotNull( ButtonFields::error_message( $field, $read->errors[ $field ], '' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function emoji(): array {
		return array(
			'plain'       => array( '🛒' ),
			'selector'    => array( '⬅️' ),
			'skin tone'   => array( '👋🏽' ),
			'family'      => array( '👨‍👩‍👧' ),
			'flag'        => array( '🇮🇷' ),
			'keycap'      => array( '1️⃣' ),
			'two of them' => array( '✨🛍️' ),
		);
	}

	#[DataProvider( 'emoji' )]
	public function test_emoji_are_taken( string $emoji ): void {
		self::assertTrue( ButtonFields::is_emoji( $emoji ) );
	}

	public function test_a_message_names_the_limit(): void {
		self::assertSame( 'Up to 40 characters.', ButtonFields::error_message( 'title.en', Fields::TOO_LONG, '' ) );
		self::assertSame( 'The Nyxify server did not accept this value.', ButtonFields::error_message( 'style', Fields::REJECTED, '' ) );
		self::assertNull( ButtonFields::error_message( 'other', Fields::INVALID, '' ) );
		self::assertNull( ButtonFields::error_message( 'title.en', Fields::INVALID, 'buttons.cart.' ) );
	}

	public function test_a_prefix_names_the_button_inside_the_part(): void {
		$values = ButtonFields::values(
			array(
				'icon'  => array( 'emoji' => '🛒' ),
				'style' => 'danger',
			),
			'buttons.cart.',
			false
		);

		self::assertSame(
			array(
				'buttons.cart.icon.emoji'           => '🛒',
				'buttons.cart.icon.custom_emoji_id' => '',
				'buttons.cart.style'                => 'danger',
			),
			$values
		);
	}

	public function test_the_stored_part_fills_the_form(): void {
		$tab    = new BackButtonTab();
		$values = $tab->values(
			array(
				'title' => array(
					'fa' => 'برگشت',
					'en' => null,
				),
				'icon'  => array(
					'emoji'           => '🔙',
					'custom_emoji_id' => '5368324170671202286',
				),
				'style' => null,
			)
		);

		self::assertSame(
			array(
				'title.fa'             => 'برگشت',
				'title.en'             => '',
				'icon.emoji'           => '🔙',
				'icon.custom_emoji_id' => '5368324170671202286',
				'style'                => '',
			),
			$values
		);
		self::assertCount( 5, $tab->values( null ) );
	}

	public function test_render_prints_the_fields_with_the_color_chosen(): void {
		$tab    = new BackButtonTab();
		$values = $tab->values(
			array(
				'icon'  => array( 'emoji' => '🔙' ),
				'style' => 'success',
			)
		);

		$html = $this->render( $values, array(), null );

		self::assertStringContainsString( 'name="nyxify_back_button[title][fa]" dir="rtl" maxlength="40"', $html );
		self::assertStringContainsString( 'name="nyxify_back_button[icon][emoji]"', $html );
		self::assertStringContainsString( 'class="nyxify-emoji-input" type="text" value="🔙"', $html );
		self::assertStringContainsString( '<select id="nyxify-back-button-style" name="nyxify_back_button[style]" dir="auto">', $html );
		self::assertStringContainsString( '<option value="">Default (blue)</option>', $html );
		self::assertStringContainsString( "<option value=\"success\" selected='selected'>Green</option>", $html );
		self::assertStringNotContainsString( 'Saved now:', $html );
	}

	public function test_render_marks_errors_and_shows_the_saved_color_by_name(): void {
		$tab   = new BackButtonTab();
		$saved = $tab->values( array( 'style' => 'danger' ) );
		$mine  = $tab->values( array( 'style' => 'success' ) );

		$html = $this->render(
			$mine,
			array(
				'icon.emoji' => Fields::INVALID,
				''           => Fields::REJECTED,
			),
			$saved
		);

		self::assertStringContainsString( '<p class="nyxify-field-error" id="nyxify-back-button-icon-emoji-error">One emoji', $html );
		self::assertStringContainsString( 'The Nyxify server did not accept these details.', $html );
		self::assertStringContainsString( '<strong>Saved now:</strong> <span dir="auto">Red</span>', $html );
		self::assertSame( 1, substr_count( $html, 'Saved now:' ) );
	}

	/**
	 * The tab's fields as printed.
	 *
	 * @param array<string, string>      $values Values.
	 * @param array<string, string>      $errors Errors.
	 * @param array<string, string>|null $saved  Saved values.
	 */
	private function render( array $values, array $errors, ?array $saved ): string {
		ob_start();
		( new BackButtonTab() )->render( $values, $errors, $saved );

		return (string) ob_get_clean();
	}
}
