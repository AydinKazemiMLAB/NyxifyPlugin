<?php
/**
 * One pull at a time per site.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Sync;

/**
 * A per-site lock around a whole pull run (pull, apply, ack), so two runs (the recurring action, an immediate one, two
 * cron runners) never apply the same changes twice.
 *
 * - The lock is the option row OPTION, written with SQL only (no option cache can hide it): `INSERT IGNORE` takes it
 *   atomically, its value is `<token>:<expiry Unix time>`.
 * - A lock past its expiry (a run that died) is taken over with an `UPDATE` that matches the old value, so only one
 *   of two racing runs wins it.
 * - Released by its token only: a run whose lock expired and was taken over can't release the newer one.
 * - The expiry (TTL_SECONDS) is longer than a run (a 20 s budget, the core's 300 s claim) and short enough that a dead
 *   run doesn't stop pulls for long.
 */
final class PullLock {

	/**
	 * The lock's option row.
	 */
	public const OPTION = 'woonyx_sync_lock';

	/**
	 * How long a lock holds before another run may take it over, in seconds.
	 */
	public const TTL_SECONDS = 330;

	/**
	 * Current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Current Unix time; time() by default.
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * Takes the lock.
	 *
	 * @return string|null The lock's value (pass it to release()), or null while another run holds it.
	 */
	public function acquire(): ?string {
		global $wpdb;

		$now   = ( $this->clock )();
		$value = bin2hex( random_bytes( 16 ) ) . ':' . ( $now + self::TTL_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic lock; the option cache must not answer.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION, $value ) );
		if ( 1 === $inserted ) {
			return $value;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
		if ( ! is_string( $current ) || self::expiry( $current ) > $now ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::OPTION, $current ) );

		return 1 === $taken ? $value : null;
	}

	/**
	 * Releases the lock if it is still the one taken.
	 *
	 * @param string $value What acquire() returned.
	 */
	public function release( string $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As in acquire().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION, $value ) );
	}

	/**
	 * The expiry of a lock's value; 0 (expired) when malformed.
	 *
	 * @param string $value The lock's value.
	 */
	private static function expiry( string $value ): int {
		return 1 === preg_match( '/^[0-9a-f]{32}:(\d{1,12})$/D', $value, $matches ) ? (int) $matches[1] : 0;
	}
}
