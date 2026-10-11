<?php
/**
 * Scheduling the catalog flush.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Catalog;

/**
 * Schedules the Action Scheduler action that sends the queued catalog changes (`nyxify_catalog_flush`, group
 * `nyxify`) and keeps the backoff's not-before time.
 *
 * - A flush is scheduled only when no **pending** one exists. Action Scheduler's `unique` option and
 *   as_has_scheduled_action() also count the running action, so ids queued while a flush is sending could be left
 *   without one; `unique` is therefore off. Two racing requests may schedule two pending flushes: harmless, the second
 *   claims what the first left, or nothing.
 * - It is due at the later of now and the backoff's not-before time: an action already due at the end of an admin
 *   request is started by Action Scheduler's async runner right away; a later one waits for WP-Cron.
 * - Without Action Scheduler (WooCommerce inactive) nothing is scheduled.
 */
final class FlushScheduler {

	/**
	 * The action's hook.
	 */
	public const HOOK = 'nyxify_catalog_flush';

	/**
	 * The action's group.
	 */
	public const GROUP = 'nyxify';

	/**
	 * Option holding the Unix time before which no flush sends (backoff after a retryable failure); not autoloaded.
	 */
	public const OPTION_RETRY_AT = 'nyxify_catalog_retry_at';

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
	 * Whether Action Scheduler's functions are loaded.
	 */
	public static function is_available(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' );
	}

	/**
	 * Schedules a flush unless one is pending.
	 *
	 * @return bool Whether a new flush was scheduled.
	 */
	public function schedule(): bool {
		if ( ! self::is_available() || $this->has_pending() ) {
			return false;
		}

		$at = max( ( $this->clock )(), $this->retry_at() );

		return as_schedule_single_action( $at, self::HOOK, array(), self::GROUP, false ) > 0;
	}

	/**
	 * Whether a flush is waiting to run (a running one doesn't count).
	 */
	public function has_pending(): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);

		return array() !== $ids;
	}

	/**
	 * Cancels every pending flush of the current site (store or plugin deactivated, uninstall).
	 */
	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * The Unix time before which no flush sends; 0 when there is none.
	 */
	public function retry_at(): int {
		$value = get_option( self::OPTION_RETRY_AT, 0 );

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Sets the time before which no flush sends.
	 *
	 * @param int $time Unix time.
	 */
	public function set_retry_at( int $time ): void {
		update_option( self::OPTION_RETRY_AT, $time, false );
	}

	/**
	 * Forgets the backoff (a send succeeded, or the queue was dropped).
	 */
	public function clear_retry_at(): void {
		delete_option( self::OPTION_RETRY_AT );
	}
}
