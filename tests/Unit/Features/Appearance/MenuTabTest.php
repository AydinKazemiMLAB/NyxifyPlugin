<?php
/**
 * Tests for the "Menu" tab.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Appearance\Tabs\Fields;
use Nyxify\Features\Appearance\Tabs\MenuTab;
use Nyxify\Tests\Unit\TestCase;

/**
 * The bot's buttons (shown or hidden, their look), the shop's own buttons in five slots and the layout typed as rows
 * of keys: read and checked as the core checks them, and printed back.
 */
final class MenuTabTest extends TestCase {

	private const STORED = array(
		'layout'  => array(
			array( 'products', 'search' ),
			array( 'insta', 'cart' ),
			array( 'support' ),
		),
		'buttons' => array(
			'cart'     => array(
				'title' => array( 'fa' => 'سبد' ),
				'style' => 'success',
			),
			'language' => array( 'enabled' => false ),
		),
		'custom'  => array(
			array(
				'key'   => 'insta',
				'title' => array( 'fa' => 'اینستاگرام' ),
				'icon'  => array( 'emoji' => '📸' ),
				'url'   => 'https://instagram.com/sara.shop',
			),
			array(
				'key'   => 'btn1',
				'title' => array( 'en' => 'Hours' ),
				'text'  => array( 'en' => 'Open <b>9 to 5</b>, {name}.' ),
			),
		),
	);

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'sanitize_text_field' )->alias( fn( string $value ): string => trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
		Functions\when( 'sanitize_textarea_field' )->alias( fn( string $value ): string => trim( strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
		Functions\when( 'wp_check_invalid_utf8' )->alias( fn( string $value ): string => $value );
		Functions\when( 'selected' )->alias( fn( $selected, $current ): string => (string) $selected === (string) $current ? " selected='selected'" : '' );
		Functions\when( 'checked' )->alias( fn( $checked, $current ): string => (string) $checked === (string) $current ? " checked='checked'" : '' );
	}

	/**
	 * Every built-in button shown and nothing else set: what an untouched form posts.
	 *
	 * @return array<string, mixed>
	 */
	private static function untouched(): array {
		$buttons = array();
		foreach ( MenuTab::BUILT_IN as $key ) {
			$buttons[ $key ] = array( 'enabled' => '1' );
		}

		return array( 'buttons' => $buttons );
	}

	/**
	 * A form with one of the shop's buttons in slot 0.
	 *
	 * @param array<string, mixed> $button The slot's fields.
	 * @param string               $layout The layout box.
	 * @return array<string, mixed>
	 */
	private static function with_custom( array $button, string $layout = '' ): array {
		return self::untouched() + array(
			'custom' => array( array( 'key' => 'btn1' ) + $button ),
			'layout' => $layout,
		);
	}

	public function test_the_tab_is_the_menu_part(): void {
		self::assertSame( 'menu', ( new MenuTab() )->part() );
		self::assertSame( 'Menu', ( new MenuTab() )->label() );
	}

	public function test_an_untouched_form_keeps_the_defaults(): void {
		$read = ( new MenuTab() )->read( self::untouched() + array( 'layout' => "\n " ) );

		self::assertNull( $read->part );
		self::assertSame( array(), $read->errors );
	}

	public function test_the_stored_part_fills_the_form_and_reads_back_the_same(): void {
		$tab    = new MenuTab();
		$values = $tab->values( self::STORED );

		self::assertCount( 8 * 6 + 5 * 9 + 1, $values );
		self::assertSame( '', $values['buttons.language.enabled'] );
		self::assertSame( '1', $values['buttons.cart.enabled'] );
		self::assertSame( 'success', $values['buttons.cart.style'] );
		self::assertSame( 'insta', $values['custom.0.key'] );
		self::assertSame( 'btn1', $values['custom.1.key'] );
		self::assertSame( 'btn2', $values['custom.2.key'] );
		self::assertSame( 'btn4', $values['custom.4.key'] );
		self::assertSame( "products search\ninsta cart\nsupport", $values['layout'] );

		$read = $tab->read( self::as_input( $values ) );

		self::assertSame( array(), $read->errors );
		self::assertSame( self::STORED, $read->part );
		$read_values = $read->values;
		ksort( $values );
		ksort( $read_values );
		self::assertSame( $values, $read_values );
	}

	public function test_an_unchecked_button_is_hidden(): void {
		$input = self::untouched();
		unset( $input['buttons']['website']['enabled'] );

		$read = ( new MenuTab() )->read( $input );

		self::assertSame( array( 'buttons' => array( 'website' => array( 'enabled' => false ) ) ), $read->part );
	}

	public function test_the_layout_is_read_loosely(): void {
		$read = ( new MenuTab() )->read( self::untouched() + array( 'layout' => "  Products,  SEARCH \r\n\r\ncart،orders\n" ) );

		self::assertSame( array(), $read->errors );
		self::assertSame(
			array(
				'layout' => array(
					array( 'products', 'search' ),
					array( 'cart', 'orders' ),
				),
			),
			$read->part
		);
		self::assertSame( "products search\ncart orders", $read->values['layout'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function invalid_layouts(): array {
		return array(
			'an unknown key'         => array( "products shop\ncart", MenuTab::LAYOUT_UNKNOWN ),
			'an empty slot of yours' => array( 'products btn3', MenuTab::LAYOUT_UNKNOWN ),
			'a key twice'            => array( "products cart\ncart", MenuTab::LAYOUT_TWICE ),
			'nine rows'              => array( "products\nsearch\ncart\norders\naccount\nlanguage\nwebsite\nsupport\nbtn1", MenuTab::LAYOUT_ROWS ),
			'four in a row'          => array( 'products search cart orders', MenuTab::LAYOUT_ROW ),
		);
	}

	#[DataProvider( 'invalid_layouts' )]
	public function test_an_invalid_layout_is_refused( string $layout, string $code ): void {
		$read = ( new MenuTab() )->read(
			self::with_custom(
				array(
					'title' => array( 'en' => 'Hours' ),
					'text'  => array( 'en' => 'Nine to five' ),
				),
				$layout
			)
		);

		self::assertSame( array( 'layout' => $code ), $read->errors );
		self::assertNotSame( '', MenuTab::error_message( 'layout', $code ) );
	}

	public function test_a_button_of_yours_with_a_link(): void {
		$read = ( new MenuTab() )->read(
			self::with_custom(
				array(
					'title' => array(
						'fa' => 'وبلاگ',
						'en' => '',
					),
					'icon'  => array( 'emoji' => '📰' ),
					'style' => 'danger',
					'url'   => ' https://shop.example.com/blog ',
				),
				'btn1 products'
			)
		);

		self::assertSame( array(), $read->errors );
		self::assertSame(
			array(
				'layout' => array( array( 'btn1', 'products' ) ),
				'custom' => array(
					array(
						'key'   => 'btn1',
						'title' => array( 'fa' => 'وبلاگ' ),
						'icon'  => array( 'emoji' => '📰' ),
						'style' => 'danger',
						'url'   => 'https://shop.example.com/blog',
					),
				),
			),
			$read->part
		);
	}

	/**
	 * @return array<string, array{array<string, mixed>, string, string}>
	 */
	public static function invalid_buttons(): array {
		return array(
			'no title'          => array( array( 'url' => 'https://shop.example.com' ), 'custom.0.title.fa', Fields::MISSING ),
			'a link and a text' => array(
				array(
					'title' => array( 'en' => 'Blog' ),
					'url'   => 'https://shop.example.com',
					'text'  => array( 'en' => 'Hi' ),
				),
				'custom.0.url',
				MenuTab::LINK_OR_TEXT,
			),
			'neither'           => array( array( 'title' => array( 'en' => 'Blog' ) ), 'custom.0.url', MenuTab::LINK_OR_TEXT ),
			'an http link'      => array(
				array(
					'title' => array( 'en' => 'Blog' ),
					'url'   => 'http://shop.example.com',
				),
				'custom.0.url',
				Fields::INVALID,
			),
			'another variable'  => array(
				array(
					'title' => array( 'en' => 'Hours' ),
					'text'  => array( 'fa' => 'سلام {customer}' ),
				),
				'custom.0.text.fa',
				Fields::VARIABLES,
			),
			'a text too long'   => array(
				array(
					'title' => array( 'en' => 'Hours' ),
					'text'  => array( 'en' => str_repeat( 'a', 2001 ) ),
				),
				'custom.0.text.en',
				Fields::TOO_LONG,
			),
		);
	}

	/**
	 * @param array<string, mixed> $button The slot's fields.
	 * @param string               $field  The field that fails.
	 * @param string               $code   Its error code.
	 */
	#[DataProvider( 'invalid_buttons' )]
	public function test_an_invalid_button_of_yours_is_refused( array $button, string $field, string $code ): void {
		$read = ( new MenuTab() )->read( self::with_custom( $button ) );

		self::assertSame( array( $field => $code ), $read->errors );
		self::assertNotSame( '', MenuTab::error_message( $field, $code ) );
	}

	public function test_a_key_that_is_taken_or_malformed_marks_the_form(): void {
		$button = array(
			'title' => array( 'en' => 'Blog' ),
			'url'   => 'https://shop.example.com',
		);

		foreach ( array( 'cart', 'A b', '' ) as $key ) {
			$input                     = self::with_custom( $button );
			$input['custom'][0]['key'] = $key;
			self::assertSame( array( '' => Fields::INVALID ), ( new MenuTab() )->read( $input )->errors, $key );
		}

		$input             = self::with_custom( $button );
		$input['custom'][] = array( 'key' => 'btn1' ) + $button;
		self::assertSame( array( '' => Fields::INVALID ), ( new MenuTab() )->read( $input )->errors );
	}

	public function test_messages_name_the_limits(): void {
		self::assertSame( 'Up to 8 rows.', MenuTab::error_message( 'layout', MenuTab::LAYOUT_ROWS ) );
		self::assertSame( 'Up to 3 buttons in a row.', MenuTab::error_message( 'layout', MenuTab::LAYOUT_ROW ) );
		self::assertSame( 'Give the button a title in at least one language.', MenuTab::error_message( 'custom.2.title.fa', Fields::MISSING ) );
		self::assertSame( 'Up to 40 characters.', MenuTab::error_message( 'buttons.cart.title.en', Fields::TOO_LONG ) );
		self::assertStringContainsString( 'One emoji', MenuTab::error_message( 'custom.0.icon.emoji', Fields::INVALID ) );
		self::assertSame( 'Up to 2000 characters.', MenuTab::error_message( 'custom.0.text.en', Fields::TOO_LONG ) );
		self::assertStringContainsString( '<blockquote>', MenuTab::error_message( 'custom.0.text.en', Fields::REJECTED ) );
		self::assertSame( 'The Nyxify server did not accept this value.', MenuTab::error_message( 'buttons.cart.enabled', Fields::REJECTED ) );
	}

	public function test_render_prints_every_button_and_slot(): void {
		$tab  = new MenuTab();
		$html = $this->render( $tab->values( self::STORED ), array(), null );

		self::assertSame( 13, substr_count( $html, '<details class="nyxify-menu-button"' ) );
		self::assertStringContainsString( 'name="nyxify_menu[buttons][cart][enabled]" checked=\'checked\' /> Show this button</label>', $html );
		self::assertStringContainsString( 'name="nyxify_menu[buttons][language][enabled]" /> Show this button</label>', $html );
		self::assertSame( 1, substr_count( $html, '<span class="nyxify-badge">Hidden</span>' ) );
		self::assertSame( 1, substr_count( $html, '<span class="nyxify-badge">Changed</span>' ) );
		self::assertStringContainsString( "<option value=\"\" selected='selected'>Default (no color)</option>", $html );
		self::assertStringContainsString( '<input type="hidden" name="nyxify_menu[custom][0][key]" value="insta" />', $html );
		self::assertStringContainsString( '<code>btn2</code>', $html );
		self::assertStringContainsString( 'Your buttons not in the layout, so not shown: btn1', $html );
		self::assertStringContainsString( "products search\ninsta cart\nsupport</textarea>", $html );
		self::assertSame( 2, substr_count( $html, '<details class="nyxify-menu-button" open>' ) );
	}

	public function test_render_opens_what_failed_and_shows_a_saved_switch_by_name(): void {
		$tab   = new MenuTab();
		$saved = $tab->values( null );
		$mine  = $tab->values( self::STORED );

		$html = $this->render( $mine, array( 'buttons.search.title.en' => Fields::TOO_LONG ), $saved );

		self::assertStringContainsString( '<strong>Saved now:</strong> <span dir="auto">Shown</span>', $html );
		self::assertStringContainsString( 'id="nyxify-menu-buttons-search-title-en-error">Up to 40 characters.', $html );
		self::assertSame( 1, substr_count( $html, 'Your buttons not in the layout' ) );
	}

	/**
	 * Flat form values as a posted form (an unchecked box isn't posted).
	 *
	 * @param array<string, string> $values Flat values.
	 * @return array<string, mixed>
	 */
	private static function as_input( array $values ): array {
		$input = array();
		foreach ( $values as $key => $value ) {
			if ( ! str_ends_with( $key, '.enabled' ) || '' !== $value ) {
				$input = self::put( $input, explode( '.', $key ), $value );
			}
		}

		return $input;
	}

	/**
	 * A tree with a value put at a path.
	 *
	 * @param array<string, mixed> $tree  The tree.
	 * @param string[]             $steps The path.
	 * @param string               $value The value.
	 * @return array<string, mixed>
	 */
	private static function put( array $tree, array $steps, string $value ): array {
		$step = (string) array_shift( $steps );
		if ( array() === $steps ) {
			$tree[ $step ] = $value;
		} else {
			$tree[ $step ] = self::put( is_array( $tree[ $step ] ?? null ) ? $tree[ $step ] : array(), $steps, $value );
		}

		return $tree;
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
		( new MenuTab() )->render( $values, $errors, $saved );

		return (string) ob_get_clean();
	}
}
