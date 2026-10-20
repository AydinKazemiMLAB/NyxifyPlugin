<?php
/**
 * Tests for the "Contact" tab.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Appearance\Tabs\ContactTab;
use Nyxify\Features\Appearance\Tabs\Fields;
use Nyxify\Tests\Unit\TestCase;

/**
 * What the owner types is normalized, checked like the core checks it, and turned into the `contact` part; the stored
 * part goes back into the form; the fields print with their errors and, after a conflict, the saved values.
 */
final class ContactTabTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'sanitize_text_field' )->alias( fn( string $value ): string => trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
		Functions\when( 'sanitize_textarea_field' )->alias( fn( string $value ): string => trim( strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
		Functions\when( 'is_email' )->alias( fn( string $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
	}

	/**
	 * Every field empty.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_form(): array {
		return array(
			'phone'        => '',
			'email'        => '',
			'telegram'     => '',
			'bale'         => '',
			'rubika'       => '',
			'whatsapp'     => '',
			'instagram'    => '',
			'introduction' => array(
				'fa' => '',
				'en' => '',
			),
			'address'      => array(
				'fa' => '',
				'en' => '',
			),
			'hours'        => array(
				'fa' => '',
				'en' => '',
			),
		);
	}

	public function test_the_tab_is_the_contact_part(): void {
		self::assertSame( 'contact', ( new ContactTab() )->part() );
		self::assertSame( 'Contact', ( new ContactTab() )->label() );
	}

	public function test_every_field_becomes_the_part(): void {
		$input = array(
			'phone'        => ' ۰۲۱ ۱۲۳۴   ۵۶۷۸ ',
			'email'        => 'info@sarashop.ir',
			'telegram'     => '@sara_shop',
			'bale'         => 'https://ble.ir/sarashop',
			'rubika'       => 'rubika.ir/sara.shop/',
			'whatsapp'     => '+98 912 000 0000',
			'instagram'    => 'https://www.instagram.com/sara.shop/?hl=fa',
			'introduction' => array(
				'fa' => "از ۹ تا ۱۷\r\nپاسخ می‌دهیم.",
				'en' => '',
			),
			'address'      => array( 'fa' => 'تهران' ),
			'hours'        => array(
				'fa' => 'شنبه تا چهارشنبه',
				'en' => 'Sat to Wed',
			),
		);

		$read = ( new ContactTab() )->read( $input );

		self::assertSame( array(), $read->errors );
		self::assertSame(
			array(
				'phone'        => '021 1234 5678',
				'email'        => 'info@sarashop.ir',
				'telegram'     => 'sara_shop',
				'bale'         => 'sarashop',
				'rubika'       => 'sara.shop',
				'whatsapp'     => '989120000000',
				'instagram'    => 'sara.shop',
				'introduction' => array( 'fa' => "از ۹ تا ۱۷\nپاسخ می‌دهیم." ),
				'address'      => array( 'fa' => 'تهران' ),
				'hours'        => array(
					'fa' => 'شنبه تا چهارشنبه',
					'en' => 'Sat to Wed',
				),
			),
			$read->part
		);
		self::assertSame( '', $read->values['introduction.en'] );
		self::assertSame( '989120000000', $read->values['whatsapp'] );
	}

	public function test_an_empty_form_removes_the_part(): void {
		$read = ( new ContactTab() )->read( self::empty_form() );

		self::assertNull( $read->part );
		self::assertSame( array(), $read->errors );
	}

	public function test_missing_and_non_string_fields_are_empty(): void {
		$read = ( new ContactTab() )->read(
			array(
				'phone'   => array( '021 1234 5678' ),
				'address' => 'not per language',
			)
		);

		self::assertNull( $read->part );
		self::assertSame( '', $read->values['phone'] );
		self::assertSame( '', $read->values['address.fa'] );
	}

	public function test_a_whatsapp_number_loses_its_00_brackets_and_dashes(): void {
		$read = ( new ContactTab() )->read( array( 'whatsapp' => '0098 (912) 000-0000' ) );

		self::assertSame( array( 'whatsapp' => '989120000000' ), $read->part );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function invalid_fields(): array {
		return array(
			'phone with letters'    => array( 'phone', '021 CALL NOW' ),
			'phone too short'       => array( 'phone', '123' ),
			'email'                 => array( 'email', 'info at sarashop' ),
			'telegram too short'    => array( 'telegram', 'sara' ),
			'telegram with a dot'   => array( 'telegram', 'sara.shop' ),
			'telegram with a digit' => array( 'telegram', '1sara_shop' ),
			'bale in Persian'       => array( 'bale', 'سارا' ),
			'rubika too short'      => array( 'rubika', 'ab' ),
			'instagram with a dash' => array( 'instagram', 'sara-shop' ),
			'another link'          => array( 'telegram', 'https://example.com/sara_shop' ),
			'whatsapp local'        => array( 'whatsapp', '09120000000' ),
			'whatsapp too short'    => array( 'whatsapp', '9891200' ),
			'whatsapp with letters' => array( 'whatsapp', '98912abc000' ),
		);
	}

	#[DataProvider( 'invalid_fields' )]
	public function test_an_invalid_field_is_refused( string $field, string $value ): void {
		$read = ( new ContactTab() )->read( array( $field => $value ) );

		self::assertSame( array( $field => Fields::INVALID ), $read->errors );
		self::assertNotSame( '', ContactTab::error_message( $field, Fields::INVALID ) );
	}

	public function test_texts_over_their_limit_are_refused(): void {
		$read = ( new ContactTab() )->read(
			array(
				'introduction' => array( 'fa' => str_repeat( 'س', 501 ) ),
				'address'      => array( 'en' => str_repeat( 'a', 300 ) ),
				'hours'        => array( 'en' => str_repeat( 'a', 201 ) ),
			)
		);

		self::assertSame(
			array(
				'introduction.fa' => Fields::TOO_LONG,
				'hours.en'        => Fields::TOO_LONG,
			),
			$read->errors
		);
		self::assertSame( 'Up to 500 characters.', ContactTab::error_message( 'introduction.fa', Fields::TOO_LONG ) );
		self::assertSame( 'Up to 200 characters.', ContactTab::error_message( 'hours.en', Fields::TOO_LONG ) );
	}

	public function test_the_stored_part_fills_the_form(): void {
		$values = ( new ContactTab() )->values(
			array(
				'phone'   => '021 1234 5678',
				'email'   => null,
				'address' => array(
					'fa' => 'تهران',
					'en' => null,
				),
				'hours'   => null,
			)
		);

		self::assertSame( '021 1234 5678', $values['phone'] );
		self::assertSame( '', $values['email'] );
		self::assertSame( 'تهران', $values['address.fa'] );
		self::assertSame( '', $values['address.en'] );
		self::assertSame( '', $values['hours.fa'] );
		self::assertCount( 13, $values );
		self::assertSame( $values, ( new ContactTab() )->read( $this->as_input( $values ) )->values );
		self::assertCount( 13, ( new ContactTab() )->values( null ) );
	}

	public function test_render_prints_the_values_escaped(): void {
		$tab    = new ContactTab();
		$values = $tab->values(
			array(
				'telegram' => 'sara_shop',
				'address'  => array( 'fa' => 'خیابان "آزادی" <۱>' ),
			)
		);

		$html = $this->render( $values, array(), null );

		self::assertStringContainsString( 'name="nyxify_contact[telegram]"', $html );
		self::assertStringContainsString( 'value="sara_shop"', $html );
		self::assertStringContainsString( 't.me/', $html );
		self::assertStringContainsString( 'name="nyxify_contact[address][fa]"', $html );
		self::assertStringContainsString( 'خیابان &quot;آزادی&quot; &lt;۱&gt;</textarea>', $html );
		self::assertStringNotContainsString( 'aria-invalid', $html );
		self::assertStringNotContainsString( 'Saved now:', $html );
	}

	public function test_render_marks_refused_fields(): void {
		$tab    = new ContactTab();
		$values = $tab->values( null );

		$html = $this->render(
			$values,
			array(
				'phone'      => Fields::INVALID,
				'address.en' => Fields::REJECTED,
				''           => Fields::REJECTED,
			),
			null
		);

		self::assertStringContainsString( 'id="nyxify-contact-phone" name="nyxify_contact[phone]" dir="ltr" maxlength="40" placeholder="021 1234 5678" aria-describedby="nyxify-contact-phone-error" aria-invalid="true"', $html );
		self::assertStringContainsString( '<p class="nyxify-field-error" id="nyxify-contact-phone-error">', $html );
		self::assertStringContainsString( '<p class="nyxify-field-error" id="nyxify-contact-address-en-error">The Nyxify server did not accept this value.</p>', $html );
		self::assertStringContainsString( 'The Nyxify server did not accept these details.', $html );
	}

	public function test_after_a_conflict_the_saved_values_show_where_they_differ(): void {
		$tab   = new ContactTab();
		$saved = $tab->values(
			array(
				'phone'    => '021 9999 0000',
				'telegram' => 'sara_shop',
			)
		);
		$mine  = $tab->values(
			array(
				'phone'    => '021 1234 5678',
				'telegram' => 'sara_shop',
				'email'    => 'info@sarashop.ir',
			)
		);

		$html = $this->render( $mine, array(), $saved );

		self::assertSame( 2, substr_count( $html, 'Saved now:' ) );
		self::assertStringContainsString( '<span dir="auto">021 9999 0000</span>', $html );
		self::assertStringContainsString( '<span dir="auto">(empty)</span>', $html );
		self::assertStringContainsString( 'value="021 1234 5678"', $html );
	}

	/**
	 * Flat form values as a posted form.
	 *
	 * @param array<string, string> $values Flat values.
	 * @return array<string, mixed>
	 */
	private function as_input( array $values ): array {
		$lines = array();
		$texts = array();
		foreach ( $values as $key => $value ) {
			$steps = explode( '.', $key );
			if ( 2 === count( $steps ) ) {
				$texts[ $steps[0] ][ $steps[1] ] = $value;
			} else {
				$lines[ $key ] = $value;
			}
		}

		return $lines + $texts;
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
		( new ContactTab() )->render( $values, $errors, $saved );

		return (string) ob_get_clean();
	}
}
