<?php
/**
 * The Jalali (Solar Hijri) calendar.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Settings;

/**
 * Converts Gregorian dates to Jalali ones with the 33-year arithmetic rule, which matches Iran's official calendar
 * (Borkowski's algorithm, checked day by day) from 1800 to March 2256, and names the Jalali months. No dependency and
 * no extension needed.
 */
final class JalaliCalendar {

	/**
	 * Days before each Gregorian month in a common year.
	 */
	private const DAYS_BEFORE_MONTH = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );

	/**
	 * The Jalali date of a Gregorian one.
	 *
	 * @param int $year  Gregorian year.
	 * @param int $month Gregorian month, 1-12.
	 * @param int $day   Gregorian day of the month.
	 * @return array{0: int, 1: int, 2: int} Jalali year, month (1-12) and day.
	 */
	public static function from_gregorian( int $year, int $month, int $day ): array {
		$leap_year = $month > 2 ? $year + 1 : $year;
		$days      = 355666 + 365 * $year + intdiv( $leap_year + 3, 4 ) - intdiv( $leap_year + 99, 100 )
			+ intdiv( $leap_year + 399, 400 ) + $day + self::DAYS_BEFORE_MONTH[ $month - 1 ];

		$jalali_year  = -1595 + 33 * intdiv( $days, 12053 );
		$days        %= 12053;
		$jalali_year += 4 * intdiv( $days, 1461 );
		$days        %= 1461;
		if ( $days > 365 ) {
			--$days;
			$jalali_year += intdiv( $days, 365 );
			$days        %= 365;
		}

		if ( $days < 186 ) {
			return array( $jalali_year, 1 + intdiv( $days, 31 ), 1 + $days % 31 );
		}

		return array( $jalali_year, 7 + intdiv( $days - 186, 30 ), 1 + ( $days - 186 ) % 30 );
	}

	/**
	 * The translated name of a Jalali month.
	 *
	 * @param int $month Jalali month, 1-12.
	 */
	public static function month_name( int $month ): string {
		$names = array(
			1  => _x( 'Farvardin', 'Jalali month', 'nyxify' ),
			2  => _x( 'Ordibehesht', 'Jalali month', 'nyxify' ),
			3  => _x( 'Khordad', 'Jalali month', 'nyxify' ),
			4  => _x( 'Tir', 'Jalali month', 'nyxify' ),
			5  => _x( 'Mordad', 'Jalali month', 'nyxify' ),
			6  => _x( 'Shahrivar', 'Jalali month', 'nyxify' ),
			7  => _x( 'Mehr', 'Jalali month', 'nyxify' ),
			8  => _x( 'Aban', 'Jalali month', 'nyxify' ),
			9  => _x( 'Azar', 'Jalali month', 'nyxify' ),
			10 => _x( 'Dey', 'Jalali month', 'nyxify' ),
			11 => _x( 'Bahman', 'Jalali month', 'nyxify' ),
			12 => _x( 'Esfand', 'Jalali month', 'nyxify' ),
		);

		return $names[ $month ] ?? (string) $month;
	}
}
