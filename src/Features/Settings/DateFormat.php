<?php
/**
 * Dates on the settings page.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Settings;

/**
 * Formats the core's dates (naive UTC ISO strings) and Unix times in the site's date and time format and time zone.
 */
final class DateFormat {

	/**
	 * A date of the core (naive UTC) in the site's format and time zone; anything unreadable is shown as it is.
	 *
	 * @param string $value ISO date and time.
	 */
	public static function core_date( string $value ): string {
		try {
			$time = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return $value;
		}

		return self::timestamp( $time->getTimestamp() );
	}

	/**
	 * A Unix time in the site's date and time format and time zone.
	 *
	 * @param int $timestamp Unix time.
	 */
	public static function timestamp( int $timestamp ): string {
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );
		$format      = ( is_string( $date_format ) && '' !== $date_format ? $date_format : 'Y-m-d' )
			. ' ' . ( is_string( $time_format ) && '' !== $time_format ? $time_format : 'H:i' );
		$formatted   = wp_date( $format, $timestamp );

		return is_string( $formatted ) ? $formatted : '';
	}
}
