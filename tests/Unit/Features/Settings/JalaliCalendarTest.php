<?php
/**
 * Tests for the Jalali calendar.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Settings;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Settings\JalaliCalendar;
use Nyxify\Tests\Unit\TestCase;

/**
 * Known dates (Nowruz, leap years, the ends of months) and the month names.
 */
final class JalaliCalendarTest extends TestCase {

	/**
	 * Gregorian dates and their Jalali ones.
	 *
	 * @return array<string, array{array{int, int, int}, array{int, int, int}}>
	 */
	public static function dates(): array {
		return array(
			'today'                    => array( array( 2026, 10, 12 ), array( 1405, 7, 20 ) ),
			'Nowruz 1405'              => array( array( 2026, 3, 21 ), array( 1405, 1, 1 ) ),
			'last day of 1404'         => array( array( 2026, 3, 20 ), array( 1404, 12, 29 ) ),
			'Esfand 30 of leap 1403'   => array( array( 2025, 3, 20 ), array( 1403, 12, 30 ) ),
			'Nowruz 1404'              => array( array( 2025, 3, 21 ), array( 1404, 1, 1 ) ),
			'Esfand 30 of leap 1399'   => array( array( 2021, 3, 20 ), array( 1399, 12, 30 ) ),
			'Nowruz 1403, a day early' => array( array( 2024, 3, 20 ), array( 1403, 1, 1 ) ),
			'end of Shahrivar'         => array( array( 2026, 9, 22 ), array( 1405, 6, 31 ) ),
			'first of Mehr'            => array( array( 2026, 9, 23 ), array( 1405, 7, 1 ) ),
			'end of Gregorian year'    => array( array( 2024, 12, 31 ), array( 1403, 10, 11 ) ),
			'February 29'              => array( array( 2028, 2, 29 ), array( 1406, 12, 10 ) ),
			'22 Bahman 1357'           => array( array( 1979, 2, 11 ), array( 1357, 11, 22 ) ),
			'year 2000'                => array( array( 2000, 1, 1 ), array( 1378, 10, 11 ) ),
			'Esfand 30 of leap 1408'   => array( array( 2030, 3, 20 ), array( 1408, 12, 30 ) ),
		);
	}

	/**
	 * Converts a known date.
	 *
	 * @param array{int, int, int} $gregorian Year, month, day.
	 * @param array{int, int, int} $jalali    Year, month, day.
	 */
	#[DataProvider( 'dates' )]
	public function test_from_gregorian( array $gregorian, array $jalali ): void {
		self::assertSame( $jalali, JalaliCalendar::from_gregorian( ...$gregorian ) );
	}

	public function test_every_day_of_a_year_follows_the_previous_one(): void {
		$day      = new \DateTimeImmutable( '2026-03-21', new \DateTimeZone( 'UTC' ) );
		$previous = JalaliCalendar::from_gregorian( 2026, 3, 20 );

		for ( $i = 0; $i < 366; $i++ ) {
			$current = JalaliCalendar::from_gregorian( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), (int) $day->format( 'j' ) );

			$next_in_month = array( $previous[0], $previous[1], $previous[2] + 1 );
			$next_month    = array( $previous[0], $previous[1] + 1, 1 );
			$next_year     = array( $previous[0] + 1, 1, 1 );
			self::assertContains( $current, array( $next_in_month, $next_month, $next_year ), $day->format( 'Y-m-d' ) );
			self::assertLessThanOrEqual( $current[1] <= 6 ? 31 : 30, $current[2] );

			$previous = $current;
			$day      = $day->modify( '+1 day' );
		}
	}

	public function test_month_names_are_translatable(): void {
		Functions\expect( '_x' )->times( 12 )->with( \Mockery::type( 'string' ), 'Jalali month', 'nyxify' )->andReturnUsing( fn( string $text ): string => 'fa:' . $text );

		self::assertSame( 'fa:Mehr', JalaliCalendar::month_name( 7 ) );
	}

	public function test_month_names(): void {
		Functions\stubTranslationFunctions();

		self::assertSame( 'Farvardin', JalaliCalendar::month_name( 1 ) );
		self::assertSame( 'Esfand', JalaliCalendar::month_name( 12 ) );
		self::assertSame( '13', JalaliCalendar::month_name( 13 ) );
	}
}
