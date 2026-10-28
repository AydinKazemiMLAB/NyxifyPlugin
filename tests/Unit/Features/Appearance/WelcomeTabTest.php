<?php
/**
 * Tests for the "Welcome" tab.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Appearance\Tabs\Fields;
use Nyxify\Features\Appearance\Tabs\WelcomeTab;
use Nyxify\Tests\Unit\TestCase;

/**
 * The banner's address and the text per language: the text is kept as typed (its tags are the bots' markup), with
 * only `{name}` and `{shop}`, and must fit under the banner.
 */
final class WelcomeTabTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_check_invalid_utf8' )->alias( fn( string $value ): string => mb_check_encoding( $value, 'UTF-8' ) ? $value : '' );
	}

	public function test_the_tab_is_the_welcome_part(): void {
		self::assertSame( 'welcome', ( new WelcomeTab() )->part() );
		self::assertSame( 'Welcome', ( new WelcomeTab() )->label() );
	}

	public function test_the_banner_and_texts_become_the_part_as_typed(): void {
		$read = ( new WelcomeTab() )->read(
			array(
				'banner_url' => ' https://shop.example.com/wp-content/uploads/2026/10/%D8%A8%D9%86%D8%B1.jpg ',
				'text'       => array(
					'fa' => "سلام <b>{name}</b>!\r\nبه {shop} خوش آمدید. {{کد}}",
					'en' => '',
				),
			)
		);

		self::assertSame( array(), $read->errors );
		self::assertSame(
			array(
				'banner_url' => 'https://shop.example.com/wp-content/uploads/2026/10/%D8%A8%D9%86%D8%B1.jpg',
				'text'       => array( 'fa' => "سلام <b>{name}</b>!\nبه {shop} خوش آمدید. {{کد}}" ),
			),
			$read->part
		);
		self::assertSame( '', $read->values['text.en'] );
	}

	public function test_an_empty_form_keeps_the_defaults(): void {
		$read = ( new WelcomeTab() )->read(
			array(
				'banner_url' => '',
				'text'       => array(
					'fa' => ' ',
					'en' => '',
				),
			)
		);

		self::assertNull( $read->part );
		self::assertCount( 3, $read->values );
	}

	public function test_a_text_without_a_banner_keeps_the_sites_logo(): void {
		$read = ( new WelcomeTab() )->read( array( 'text' => array( 'en' => 'Hello {name}' ) ) );

		self::assertSame( array( 'text' => array( 'en' => 'Hello {name}' ) ), $read->part );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalid_banners(): array {
		return array(
			'http'       => array( 'http://shop.example.com/banner.jpg' ),
			'no address' => array( 'https://' ),
			'a space'    => array( 'https://shop.example.com/my banner.jpg' ),
			'a quote'    => array( 'https://shop.example.com/a"b.jpg' ),
			'a path'     => array( '/wp-content/uploads/banner.jpg' ),
			'too long'   => array( 'https://shop.example.com/' . str_repeat( 'a', 1000 ) ),
		);
	}

	#[DataProvider( 'invalid_banners' )]
	public function test_an_invalid_banner_is_refused( string $banner ): void {
		$read = ( new WelcomeTab() )->read( array( 'banner_url' => $banner ) );

		self::assertSame( array( 'banner_url' => Fields::INVALID ), $read->errors );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function invalid_texts(): array {
		return array(
			'another variable' => array( 'Hello {customer}', Fields::VARIABLES ),
			'a stray brace'    => array( 'Hello {name', Fields::VARIABLES ),
			'an empty brace'   => array( 'Hello {}', Fields::VARIABLES ),
			'too long shown'   => array( str_repeat( 'س', 961 ), Fields::TOO_LONG ),
			'too long written' => array( str_repeat( '<b>a</b>', 251 ), Fields::TOO_LONG ),
		);
	}

	#[DataProvider( 'invalid_texts' )]
	public function test_an_invalid_text_is_refused( string $text, string $code ): void {
		$read = ( new WelcomeTab() )->read( array( 'text' => array( 'fa' => $text ) ) );

		self::assertSame( array( 'text.fa' => $code ), $read->errors );
		self::assertNotSame( '', WelcomeTab::error_message( 'text.fa', $code ) );
	}

	public function test_tags_dont_count_toward_the_limit(): void {
		$read = ( new WelcomeTab() )->read( array( 'text' => array( 'en' => '<b>' . str_repeat( 'a', 960 ) . '</b>' ) ) );

		self::assertSame( array(), $read->errors );
	}

	public function test_messages(): void {
		self::assertSame( 'Up to 960 characters, not counting tags.', WelcomeTab::error_message( 'text.en', Fields::TOO_LONG ) );
		self::assertStringContainsString( 'https://', WelcomeTab::error_message( 'banner_url', Fields::INVALID ) );
		self::assertStringContainsString( '<blockquote>', WelcomeTab::error_message( 'text.fa', Fields::REJECTED ) );
		self::assertSame( 'The Nyxify server did not accept this value.', WelcomeTab::error_message( 'banner_url', Fields::REJECTED ) );
	}

	public function test_the_stored_part_fills_the_form_and_reads_back_the_same(): void {
		$tab    = new WelcomeTab();
		$values = $tab->values(
			array(
				'banner_url' => 'https://shop.example.com/banner.jpg',
				'text'       => array(
					'fa' => '<i>سلام</i> {name}',
					'en' => null,
				),
			)
		);

		self::assertSame(
			array(
				'banner_url' => 'https://shop.example.com/banner.jpg',
				'text.fa'    => '<i>سلام</i> {name}',
				'text.en'    => '',
			),
			$values
		);
		self::assertSame(
			$values,
			$tab->read(
				array(
					'banner_url' => $values['banner_url'],
					'text'       => array(
						'fa' => $values['text.fa'],
						'en' => '',
					),
				)
			)->values
		);
		self::assertCount( 3, $tab->values( null ) );
	}

	public function test_render_prints_the_markup_escaped(): void {
		$tab  = new WelcomeTab();
		$html = $this->render( $tab->values( array( 'text' => array( 'fa' => '<b>سلام</b> & {name}' ) ) ), array(), null );

		self::assertStringContainsString( 'name="nyxify_welcome[banner_url]" dir="ltr" maxlength="1000" placeholder="https://" class="large-text" type="url" value=""', $html );
		self::assertStringContainsString( 'data-nyxify-media="nyxify-welcome-banner-url"', $html );
		self::assertStringContainsString( 'id="nyxify-welcome-banner-url-preview" class="nyxify-banner-preview" alt="" hidden', $html );
		self::assertStringContainsString( '&lt;b&gt;سلام&lt;/b&gt; &amp; {name}</textarea>', $html );
		self::assertStringContainsString( 'Up to 960 characters, not counting tags.', $html );
		self::assertStringNotContainsString( '<b>سلام</b>', $html );
	}

	public function test_render_shows_the_banners_preview(): void {
		$tab  = new WelcomeTab();
		$html = $this->render( $tab->values( array( 'banner_url' => 'https://shop.example.com/banner.png' ) ), array(), null );

		self::assertStringContainsString( 'id="nyxify-welcome-banner-url-preview" class="nyxify-banner-preview" src="https://shop.example.com/banner.png" alt="" />', $html );
	}

	public function test_render_marks_a_refused_text(): void {
		$tab  = new WelcomeTab();
		$html = $this->render( $tab->values( null ), array( 'text.en' => Fields::REJECTED ), null );

		self::assertStringContainsString( 'id="nyxify-welcome-text-en-error">The Nyxify server did not accept this text.', $html );
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
		( new WelcomeTab() )->render( $values, $errors, $saved );

		return (string) ob_get_clean();
	}
}
