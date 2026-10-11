<?php
/**
 * Scheduling the pull.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Sync;

use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;

/**
 * Schedules the Action Scheduler actions that pull the changes the core couldn't push (contract §5.2), group `nyxify`,
 * and keeps the pause after a refusal.
 *
 * - HOOK: recurring every INTERVAL_SECONDS while the site is connected. ensure() (on `admin_init` and after an
 *   activation) schedules it when it is missing and removes it when the site isn't connected.
 * - NOW_HOOK: one pull at once (after "Check connection", after a run whose answer was full), unless one is pending.
 *   Its own hook, since Action Scheduler's checks of a hook count every pending or running action: a pending immediate
 *   pull would otherwise hide a missing recurring one.
 * - OPTION_RETRY_AT: the Unix time before which no pull runs (set when the core refuses the store; cleared by "Check
 *   connection" and a new activation).
 * - Without Action Scheduler (WooCommerce inactive) nothing is scheduled.
 */
final class PullScheduler {

	public const HOOK = 'nyxify_sync_pull';

	public const NOW_HOOK = 'nyxify_sync_pull_now';

	public const GROUP = 'nyxify';

	/**
	 * Seconds between two recurring pulls (the core claims a pulled change for 300 s).
	 */
	public const INTERVAL_SECONDS = 300;

	/**
	 * Option holding the Unix time before which no pull runs; not autoloaded.
	 */
	public const OPTION_RETRY_AT = 'nyxify_sync_retry_at';

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
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_get_scheduled_actions' );
	}

	/**
	 * `admin_init`: keeps the recurring pull in step with the site's connection.
	 */
	public static function ensure_for_site(): void {
		( new self() )->ensure( null !== ( new SecretStore( Encryptor::for_site() ) )->get_connection() );
	}

	/**
	 * Schedules the recurring pull when connected and missing; removes both pulls when not connected.
	 *
	 * @param bool $connected Whether the site has a readable connection.
	 * @return bool Whether the recurring pull was scheduled now.
	 */
	public function ensure( bool $connected ): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		if ( ! $connected ) {
			if ( as_has_scheduled_action( self::HOOK, null, self::GROUP ) || as_has_scheduled_action( self::NOW_HOOK, null, self::GROUP ) ) {
				self::unschedule_all();
			}
			return false;
		}

		if ( as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
			return false;
		}

		return as_schedule_recurring_action( ( $this->clock )(), self::INTERVAL_SECONDS, self::HOOK, array(), self::GROUP, true ) > 0;
	}

	/**
	 * Schedules one pull at once unless one is pending (a running one doesn't count: it may have pulled already).
	 *
	 * @return bool Whether a pull was scheduled.
	 */
	public function schedule_now(): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => self::NOW_HOOK,
				'group'    => self::GROUP,
				'status'   => 'pending',
				'per_page' => 1,
				'orderby'  => 'none',
			),
			'ids'
		);
		if ( array() !== $pending ) {
			return false;
		}

		return as_enqueue_async_action( self::NOW_HOOK, array(), self::GROUP, false ) > 0;
	}

	/**
	 * Cancels both pulls of the current site (store or plugin deactivated, uninstall).
	 */
	public static function unschedule_all(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
			as_unschedule_all_actions( self::NOW_HOOK, array(), self::GROUP );
		}
	}

	/**
	 * The Unix time before which no pull runs; 0 when there is none.
	 */
	public function retry_at(): int {
		$value = get_option( self::OPTION_RETRY_AT, 0 );

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Pauses pulls until a time.
	 *
	 * @param int $time Unix time.
	 */
	public function set_retry_at( int $time ): void {
		update_option( self::OPTION_RETRY_AT, $time, false );
	}

	/**
	 * Ends the pause ("Check connection", a new activation).
	 */
	public static function clear_retry_at(): void {
		delete_option( self::OPTION_RETRY_AT );
	}
}
