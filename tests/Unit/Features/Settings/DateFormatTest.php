<?php
/**
 * Tests for the settings page's dates.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Settings;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Nyxify\Features\Settings\DateFormat;
use Nyxify\Tests\Unit\TestCase;

/**
 * The site's formats in Gregorian, the Jalali calendar with Persian digits in a Persian admin, and the filter.
 */
final class DateFormatTest extends TestCase {

	/**
	 * 2026-10-12 07:11:00 UTC: 10:41 in Tehran (UTC+3:30), 20 Mehr 1405.
	 */
	private const TIME = 1791789060;

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * The admin's locale.
	 *
	 * @var string
	 */
	private string $locale = 'en_US';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\when( 'get_option' )->alias( fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value );
		Functions\when( 'determine_locale' )->alias( fn(): string => $this->locale );
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'Asia/Tehran' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( string $format, int $timestamp ): string => ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( new \DateTimeZone( 'Asia/Tehran' ) )->format( $format )
		);
		Functions\when( 'number_format_i18n' )->alias( static fn( $number ): string => number_format( (float) $number ) );
	}

	public function test_the_site_formats_with_and_without_the_time(): void {
		$this->options['date_format'] = 'F j, Y';
		$this->options['time_format'] = 'g:i a';

		self::assertSame( 'October 12, 2026 at 10:41 am', DateFormat::timestamp( self::TIME ) );
		self::assertSame( 'October 12, 2026', DateFormat::timestamp( self::TIME, false ) );
	}

	public function test_default_formats_when_the_site_has_none(): void {
		$this->options['date_format'] = '';

		self::assertSame( '2026-10-12 at 10:41', DateFormat::timestamp( self::TIME ) );
	}

	public function test_core_dates_are_naive_utc(): void {
		self::assertSame( '2026-10-12 at 10:41', DateFormat::core_date( '2026-10-12T07:11:00' ) );
		self::assertSame( '2026-10-12', DateFormat::core_date( '2026-10-12T07:11:00', false ) );
		self::assertSame( self::TIME, DateFormat::core_timestamp( '2026-10-12T07:11:00' ) );
	}

	public function test_an_unreadable_core_date_is_shown_as_it_is(): void {
		self::assertSame( 'not a date', DateFormat::core_date( 'not a date' ) );
		self::assertNull( DateFormat::core_timestamp( 'not a date' ) );
	}

	public function test_a_persian_admin_gets_jalali_dates_with_persian_digits(): void {
		$this->locale = 'fa_IR';

		self::assertTrue( DateFormat::uses_jalali() );
		self::assertSame( '۲۰ Mehr ۱۴۰۵ at ۱۰:۴۱', DateFormat::timestamp( self::TIME ) );
		self::assertSame( '۲۰ Mehr ۱۴۰۵', DateFormat::core_date( '2026-10-12T07:11:00', false ) );
	}

	public function test_the_jalali_date_is_the_one_in_the_site_time_zone(): void {
		$this->locale = 'fa_IR';

		// 2026-03-20 21:00 UTC is already Nowruz (March 21, 00:30) in Tehran.
		self::assertSame( '۱ Farvardin ۱۴۰۵', DateFormat::core_date( '2026-03-20T21:00:00', false ) );
	}

	public function test_the_translated_month_and_join(): void {
		$this->locale = 'fa_IR';
		Functions\when( '_x' )->alias( fn( string $text ): string => 'Mehr' === $text ? 'مهر' : $text );
		Functions\when( '__' )->alias( fn( string $text ): string => '%1$s at %2$s' === $text ? '%1$s، ساعت %2$s' : $text );

		self::assertSame( '۲۰ مهر ۱۴۰۵، ساعت ۱۰:۴۱', DateFormat::timestamp( self::TIME ) );
	}

	public function test_other_languages_stay_gregorian(): void {
		foreach ( array( 'en_US', 'de_DE', 'fa_AF', 'ar' ) as $locale ) {
			$this->locale = $locale;
			self::assertFalse( DateFormat::uses_jalali(), $locale );
		}
	}

	public function test_the_filter_turns_the_jalali_calendar_off_or_on(): void {
		$this->locale = 'fa_IR';
		Filters\expectApplied( 'nyxify_jalali_dates' )->once()->with( true )->andReturn( false );
		self::assertSame( '2026-10-12', DateFormat::timestamp( self::TIME, false ) );

		$this->locale = 'en_US';
		Filters\expectApplied( 'nyxify_jalali_dates' )->once()->with( false )->andReturn( true );
		self::assertSame( '۲۰ Mehr ۱۴۰۵', DateFormat::timestamp( self::TIME, false ) );
	}

	public function test_numbers_get_persian_digits_next_to_jalali_dates(): void {
		self::assertSame( '1,234', DateFormat::number( 1234 ) );

		$this->locale = 'fa_IR';
		self::assertSame( '۱,۲۳۴', DateFormat::number( 1234 ) );
	}
}
