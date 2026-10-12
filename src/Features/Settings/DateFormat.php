<?php
/**
 * Dates on the settings page.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Settings;

/**
 * Formats the core's dates (naive UTC ISO strings) and Unix times in the site's time zone: in the site's date and time
 * formats, or, in a Persian admin (`fa_IR`), in the Jalali calendar with Persian digits ("20 Mehr 1405"; the page's
 * other numbers then get Persian digits too). The filter `nyxify_jalali_dates` turns the Jalali calendar on or off for
 * any language.
 */
final class DateFormat {

	/**
	 * Locales shown in the Jalali calendar by default.
	 */
	private const JALALI_LOCALES = array( 'fa_IR', 'fa' );

	/**
	 * Persian digits, 0 to 9.
	 */
	private const PERSIAN_DIGITS = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );

	/**
	 * A date of the core (naive UTC); anything unreadable is shown as it is.
	 *
	 * @param string $value     ISO date and time.
	 * @param bool   $with_time Whether to show the time too.
	 */
	public static function core_date( string $value, bool $with_time = true ): string {
		$timestamp = self::core_timestamp( $value );

		return null === $timestamp ? $value : self::timestamp( $timestamp, $with_time );
	}

	/**
	 * The Unix time of a date of the core (naive UTC), or null when it can't be read.
	 *
	 * @param string $value ISO date and time.
	 */
	public static function core_timestamp( string $value ): ?int {
		try {
			$time = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		return $time->getTimestamp();
	}

	/**
	 * A Unix time in the site's time zone.
	 *
	 * @param int  $timestamp Unix time.
	 * @param bool $with_time Whether to show the time too.
	 */
	public static function timestamp( int $timestamp, bool $with_time = true ): string {
		if ( self::uses_jalali() ) {
			return self::jalali( $timestamp, $with_time );
		}

		$date_format = get_option( 'date_format' );
		$date        = self::wp_date( is_string( $date_format ) && '' !== $date_format ? $date_format : 'Y-m-d', $timestamp );
		if ( ! $with_time ) {
			return $date;
		}

		$time_format = get_option( 'time_format' );

		return self::join( $date, self::wp_date( is_string( $time_format ) && '' !== $time_format ? $time_format : 'H:i', $timestamp ) );
	}

	/**
	 * A number as WordPress formats it, with Persian digits next to Jalali dates.
	 *
	 * @param int $number Number.
	 */
	public static function number( int $number ): string {
		$formatted = number_format_i18n( $number );

		return self::uses_jalali() ? self::persian_digits( $formatted ) : $formatted;
	}

	/**
	 * Whether dates are shown in the Jalali calendar.
	 */
	public static function uses_jalali(): bool {
		return (bool) apply_filters( 'nyxify_jalali_dates', in_array( determine_locale(), self::JALALI_LOCALES, true ) );
	}

	/**
	 * A Unix time in the Jalali calendar with Persian digits, the time on a 24-hour clock.
	 *
	 * @param int  $timestamp Unix time.
	 * @param bool $with_time Whether to show the time too.
	 */
	private static function jalali( int $timestamp, bool $with_time ): string {
		$local = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() );

		list( $year, $month, $day ) = JalaliCalendar::from_gregorian( (int) $local->format( 'Y' ), (int) $local->format( 'n' ), (int) $local->format( 'j' ) );

		$date = self::persian_digits( $day . ' ' . JalaliCalendar::month_name( $month ) . ' ' . $year );

		return $with_time ? self::join( $date, self::persian_digits( $local->format( 'H:i' ) ) ) : $date;
	}

	/**
	 * A date and a time, as one string.
	 *
	 * @param string $date Date.
	 * @param string $time Time.
	 */
	private static function join( string $date, string $time ): string {
		/* translators: 1: date, 2: time */
		return sprintf( __( '%1$s at %2$s', 'nyxify' ), $date, $time );
	}

	/**
	 * Persian digits in place of the ASCII ones.
	 *
	 * @param string $text Text.
	 */
	private static function persian_digits( string $text ): string {
		return str_replace( array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ), self::PERSIAN_DIGITS, $text );
	}

	/**
	 * `wp_date()`, never false.
	 *
	 * @param string $format    PHP date format.
	 * @param int    $timestamp Unix time.
	 */
	private static function wp_date( string $format, int $timestamp ): string {
		$formatted = wp_date( $format, $timestamp );

		return is_string( $formatted ) ? $formatted : '';
	}
}
