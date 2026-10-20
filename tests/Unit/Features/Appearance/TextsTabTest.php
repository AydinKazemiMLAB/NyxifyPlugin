<?php
/**
 * Tests for the "Texts" tab and the bot's text catalog.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use Nyxify\Features\Appearance\AppearanceSaveHandler;
use Nyxify\Features\Appearance\Tabs\Fields;
use Nyxify\Features\Appearance\Tabs\TextsTab;
use Nyxify\Features\Appearance\TextCatalog;
use Nyxify\Tests\Unit\TestCase;

/**
 * The texts of the bot's catalog per language, checked for the variables and length each key allows, with the texts
 * of keys the catalog doesn't know sent back as they were.
 */
final class TextsTabTest extends TestCase {

	private const CATALOG = array(
		'markup_limit' => 2000,
		'sections'     => array(
			array(
				'key'   => 'cart',
				'title' => array(
					'fa' => 'سبد خرید',
					'en' => 'Cart',
				),
			),
			array(
				'key'   => 'errors',
				'title' => array(
					'fa' => 'خطاها',
					'en' => 'Errors and warnings',
				),
			),
			array(
				'key'   => 'unused',
				'title' => array(
					'fa' => 'بی‌متن',
					'en' => 'Unused',
				),
			),
		),
		'texts'        => array(
			array(
				'key'       => 'errors.generic',
				'section'   => 'errors',
				'markup'    => false,
				'variables' => array(),
				'limit'     => 20,
				'default'   => array(
					'fa' => 'مشکلی پیش آمد.',
					'en' => 'Something went wrong.',
				),
			),
			array(
				'key'       => 'cart.title',
				'section'   => 'cart',
				'markup'    => true,
				'variables' => array( 'count' ),
				'limit'     => 500,
				'default'   => array(
					'fa' => '🛒 <b>سبد خرید ({count})</b>',
					'en' => '🛒 <b>Cart ({count})</b>',
				),
			),
		),
	);

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_check_invalid_utf8' )->alias( fn( string $value ): string => $value );
		Functions\when( 'wp_kses' )->alias( fn( string $html ): string => strip_tags( $html, '<b><i><u><s><code><blockquote>' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
		Functions\when( 'wp_json_encode' )->alias( fn( $data, int $flags = 0 ) => json_encode( $data, $flags ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- A stand-in for WordPress.
	}

	public function test_the_tab_is_the_texts_part(): void {
		self::assertSame( 'texts', self::tab()->part() );
		self::assertSame( 'Texts', self::tab()->label() );
	}

	public function test_the_shipped_catalog_reads_and_matches_the_core(): void {
		$catalog = TextCatalog::load();

		self::assertNotNull( $catalog );
		self::assertGreaterThanOrEqual( 90, count( $catalog->texts ) );
		self::assertSame( 2000, $catalog->markup_limit );
		self::assertSame( 'catalog', $catalog->sections[0]['key'] );
		self::assertSame( array( 'name' ), $catalog->texts['account.name']['variables'] );
		foreach ( $catalog->texts as $key => $text ) {
			self::assertMatchesRegularExpression( '/^[a-z0-9_.]{0,77}$/D', $key, 'A form draft takes the key with ".fa".' );
			self::assertNotSame( '', $text['default']['fa'] );
		}
	}

	public function test_a_malformed_entry_is_skipped_and_a_section_without_texts_left_out(): void {
		$data            = self::CATALOG;
		$data['texts'][] = array( 'key' => 'Bad Key' ) + $data['texts'][0];
		$data['texts'][] = array( 'key' => 'cart.title.more' ) + $data['texts'][1];
		$data['texts'][] = array( 'variables' => array( 'Count' ) ) + array( 'key' => 'cart.other' ) + $data['texts'][1];
		$catalog         = new TextCatalog( $data );

		self::assertSame( array( 'errors.generic', 'cart.title' ), array_keys( $catalog->texts ) );
		self::assertSame( array( 'cart', 'errors' ), array_column( $catalog->sections, 'key' ) );
		self::assertNull( TextCatalog::load( __DIR__ . '/missing.json' ) );
	}

	public function test_the_stored_part_fills_the_form_and_reads_back_the_same_with_unknown_keys(): void {
		$stored = array(
			'cart.title'     => array( 'fa' => '<b>سبد ({count})</b>' ),
			'old.key'        => array(
				'fa' => 'قدیمی',
				'en' => 'Old',
			),
			'errors.generic' => array( 'en' => 'Oops.' ),
		);
		$tab    = self::tab();
		$values = $tab->values( $stored );

		self::assertSame(
			array( 'errors.generic.fa', 'errors.generic.en', 'cart.title.fa', 'cart.title.en', TextsTab::KEPT ),
			array_keys( $values )
		);
		self::assertSame( '{"old.key":{"fa":"قدیمی","en":"Old"}}', $values[ TextsTab::KEPT ] );

		$read = $tab->read( self::posted( $values ) );

		self::assertSame( array(), $read->errors );
		self::assertSame(
			array(
				'errors.generic' => array( 'en' => 'Oops.' ),
				'cart.title'     => array( 'fa' => '<b>سبد ({count})</b>' ),
				'old.key'        => array(
					'fa' => 'قدیمی',
					'en' => 'Old',
				),
			),
			$read->part
		);
	}

	public function test_an_empty_form_removes_the_part(): void {
		$read = self::tab()->read( self::posted( self::tab()->values( null ) ) );

		self::assertSame( array(), $read->errors );
		self::assertNull( $read->part );
	}

	public function test_a_forged_kept_field_keeps_only_well_formed_unknown_texts(): void {
		$posted                   = self::posted( self::tab()->values( null ) );
		$posted[ TextsTab::KEPT ] = '{"cart.title":{"fa":"x"},"Bad":{"fa":"x"},"ok.key":{"fa":"","en":"y","de":"z"},"no.text":{"fa":""}}';

		self::assertSame( array( 'ok.key' => array( 'en' => 'y' ) ), self::tab()->read( $posted )->part );
	}

	public function test_texts_are_checked_for_their_keys_variables_and_lengths(): void {
		$values                      = self::tab()->values( null );
		$values['errors.generic.fa'] = 'خطا {count}';
		$values['errors.generic.en'] = str_repeat( 'a', 21 );
		$values['cart.title.fa']     = '<b>' . str_repeat( 'a', 20 ) . '</b> {name}';
		$values['cart.title.en']     = str_repeat( 'a', 2001 );

		$read = self::tab()->read( self::posted( $values ) );

		self::assertSame(
			array(
				'errors.generic.fa' => Fields::VARIABLES,
				'errors.generic.en' => Fields::TOO_LONG,
				'cart.title.fa'     => Fields::VARIABLES,
				'cart.title.en'     => TextsTab::MARKUP_TOO_LONG,
			),
			$read->errors
		);

		$tab = self::tab();
		self::assertSame( 'This text takes no variables; write {{ and }} for a brace.', $tab->error_message( 'errors.generic.fa', Fields::VARIABLES ) );
		self::assertSame( 'This text can use only {count}; write {{ and }} for a brace.', $tab->error_message( 'cart.title.fa', Fields::VARIABLES ) );
		self::assertSame( 'Up to 20 characters, not counting tags.', $tab->error_message( 'errors.generic.en', Fields::TOO_LONG ) );
		self::assertSame( 'Up to 2000 characters with the tags.', $tab->error_message( 'cart.title.en', TextsTab::MARKUP_TOO_LONG ) );
		self::assertStringContainsString( 'did not accept this text', $tab->error_message( 'cart.title.en', Fields::REJECTED ) );
	}

	public function test_tags_dont_count_towards_the_length(): void {
		$values                      = self::tab()->values( null );
		$values['errors.generic.en'] = '<b>' . str_repeat( 'a', 20 ) . '</b>';

		self::assertSame( array(), self::tab()->read( self::posted( $values ) )->errors );
	}

	public function test_a_form_cut_short_or_without_a_catalog_saves_nothing(): void {
		$posted = self::posted( self::tab()->values( null ) );
		unset( $posted[ TextsTab::COMPLETE ] );
		$none = new TextsTab( fn(): ?TextCatalog => null );

		self::assertSame( array( '' => TextsTab::TRUNCATED ), self::tab()->read( $posted )->errors );
		self::assertSame( array( '' => TextsTab::UNAVAILABLE ), $none->read( array( TextsTab::COMPLETE => '1' ) )->errors );
		self::assertSame( array(), $none->values( array( 'cart.title' => array( 'fa' => 'x' ) ) ) );
		self::assertStringContainsString( 'couldn’t be read', self::render( $none, array(), array(), null ) );
	}

	public function test_the_cores_refusal_marks_the_texts_field(): void {
		self::assertSame(
			array( 'cart.title.fa' => Fields::REJECTED ),
			AppearanceSaveHandler::refused_fields( 'texts', array( 'body.settings.texts.cart.title.fa' ) )
		);
	}

	public function test_render_prints_each_section_with_its_texts_and_defaults(): void {
		$tab    = self::tab();
		$values = $tab->values(
			array(
				'cart.title' => array( 'fa' => 'سبد' ),
				'old.key'    => array( 'en' => 'Old' ),
			)
		);
		$html   = self::render( $tab, $values, array(), null );

		self::assertSame( 2, substr_count( $html, '<details' ) );
		self::assertLessThan( strpos( $html, 'Errors and warnings' ), (int) strpos( $html, 'cart.title' ) );
		self::assertStringContainsString( '1 of 1 text changed', $html );
		self::assertStringContainsString( '<strong dir="auto">🛒 Cart ({count})</strong>', $html );
		self::assertStringContainsString( 'Variables: {count}. Up to 500 characters.', $html );
		self::assertStringContainsString( 'No variables. Up to 20 characters. Shown as plain text: formatting is dropped.', $html );
		self::assertMatchesRegularExpression( '#Default:</span>\s*<div dir="ltr">🛒 <b>Cart \(\{count\}\)</b></div>#u', $html );
		self::assertStringContainsString( 'name="nyxify_texts[cart][title][fa]"', $html );
		self::assertStringContainsString( '1 text of the bot that this version of the plugin doesn’t list is kept as it is.', $html );
		self::assertStringContainsString( 'name="nyxify_texts[_kept]" value="{&quot;old.key&quot;:{&quot;en&quot;:&quot;Old&quot;}}"', $html );
		self::assertGreaterThan( strrpos( $html, '</details>' ), strpos( $html, '[_complete]' ) );
	}

	public function test_render_opens_a_section_with_an_error_and_shows_the_saved_text_after_a_conflict(): void {
		$tab                   = self::tab();
		$mine                  = $tab->values( null );
		$mine['cart.title.en'] = 'Mine';
		$saved                 = $tab->values( array( 'cart.title' => array( 'en' => 'Theirs' ) ) );

		$html = self::render( $tab, $mine, array( 'errors.generic.fa' => Fields::VARIABLES ), $saved );

		self::assertSame( 2, substr_count( $html, '<details class="nyxify-menu-button" open>' ) );
		self::assertStringContainsString( 'This text takes no variables', $html );
		self::assertStringContainsString( '<strong>Saved now:</strong> <span dir="auto">Theirs</span>', $html );
	}

	public function test_the_names_follow_a_persian_admin(): void {
		Functions\when( 'determine_locale' )->justReturn( 'fa_IR' );

		$html = self::render( self::tab(), self::tab()->values( null ), array(), null );

		self::assertStringContainsString( 'سبد خرید', $html );
		self::assertStringContainsString( '<strong dir="auto">مشکلی پیش آمد.</strong>', $html );
	}

	/**
	 * The tab with the test catalog.
	 */
	private static function tab(): TextsTab {
		return new TextsTab( fn(): TextCatalog => new TextCatalog( self::CATALOG ) );
	}

	/**
	 * The form's fields as the browser posts them: nested by key, with the hidden field that closes the form.
	 *
	 * @param array<string, string> $values The form's fields.
	 * @return array<array-key, mixed>
	 */
	private static function posted( array $values ): array {
		$posted = array( TextsTab::COMPLETE => '1' );
		foreach ( $values as $key => $value ) {
			$posted = self::put( $posted, explode( '.', $key ), $value );
		}

		return $posted;
	}

	/**
	 * A value put at a path of keys.
	 *
	 * @param array<array-key, mixed> $into  The array.
	 * @param list<string>            $path  Keys.
	 * @param string                  $value The value.
	 * @return array<array-key, mixed>
	 */
	private static function put( array $into, array $path, string $value ): array {
		$key = array_shift( $path );
		if ( null === $key ) {
			return $into;
		}
		$into[ $key ] = array() === $path ? $value : self::put( is_array( $into[ $key ] ?? null ) ? $into[ $key ] : array(), $path, $value );

		return $into;
	}

	/**
	 * The tab's fields as printed.
	 *
	 * @param TextsTab                   $tab    The tab.
	 * @param array<string, string>      $values Values.
	 * @param array<string, string>      $errors Errors.
	 * @param array<string, string>|null $saved  Saved values.
	 */
	private static function render( TextsTab $tab, array $values, array $errors, ?array $saved ): string {
		ob_start();
		$tab->render( $values, $errors, $saved );

		return (string) ob_get_clean();
	}
}
