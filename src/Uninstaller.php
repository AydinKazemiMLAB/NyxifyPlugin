<?php
/**
 * Data removal when the plugin is deleted.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify;

use Nyxify\Features\Appearance\FormDraft;
use Nyxify\Features\Catalog\FlushScheduler;
use Nyxify\Features\Catalog\PlanLimitNotice;
use Nyxify\Features\Catalog\Schema;
use Nyxify\Features\Store\RestKeys;
use Nyxify\Features\Store\StatusService;
use Nyxify\Features\Sync\PayloadFilter;
use Nyxify\Features\Sync\PullLock;
use Nyxify\Features\Sync\PullScheduler;
use Nyxify\Shared\Admin\Notices;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\SecretStore;

/**
 * Removes everything the plugin stored, on every site of a network: the WooCommerce REST key it created, its pending
 * catalog flushes and pulls (when Action Scheduler is loaded), its catalog queue table, its options and its transients,
 * the outbox marker on the products a pull created, and its entries in WooCommerce's log (when WooCommerce is loaded;
 * otherwise WooCommerce's log retention removes them). Nothing is sent to the core (the deactivation already told it,
 * or couldn't); keys the plugin didn't create are never touched.
 */
final class Uninstaller {

	/**
	 * Every option the plugin writes. Each feature adds its own options here.
	 *
	 * @var list<string>
	 */
	public const OPTIONS = array(
		SecretStore::OPTION_CONNECTION,
		SecretStore::OPTION_ADMIN_PIN,
		RestKeys::OPTION_KEY_ID,
		Schema::OPTION_VERSION,
		FlushScheduler::OPTION_RETRY_AT,
		PlanLimitNotice::OPTION,
		PullScheduler::OPTION_RETRY_AT,
		PullLock::OPTION,
	);

	/**
	 * Every transient with a fixed name. The per-user notices (Notices::TRANSIENT_PREFIX + user id) are deleted by
	 * their prefix.
	 *
	 * @var list<string>
	 */
	public const TRANSIENTS = array(
		StatusService::TRANSIENT,
	);

	/**
	 * Runs the cleanup for this site, or for every site of a multisite network.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::clean_site();
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );

			try {
				self::clean_site();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Deletes the current site's REST key, pending flushes and pulls, queue table, options, transients, outbox markers
	 * and log entries.
	 */
	private static function clean_site(): void {
		self::delete_rest_key();
		FlushScheduler::unschedule_all();
		PullScheduler::unschedule_all();
		Schema::drop();
		delete_post_meta_by_key( PayloadFilter::OUTBOX_META_KEY );
		self::delete_logs();

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}

		self::delete_notices();
	}

	/**
	 * Deletes the REST key the plugin created, if its id is remembered. WooCommerce may be inactive or already gone
	 * with its table, so a database error is not shown; the key is gone either way.
	 */
	private static function delete_rest_key(): void {
		global $wpdb;

		$rest_keys = new RestKeys();
		$key_id    = $rest_keys->current_id();
		if ( null === $key_id ) {
			return;
		}

		$suppressed = $wpdb->suppress_errors( true );
		$rest_keys->delete( $key_id );
		$wpdb->suppress_errors( $suppressed );
	}

	/**
	 * Deletes the plugin's entries in WooCommerce's log (its files and database rows, by source), when WooCommerce is
	 * loaded.
	 */
	private static function delete_logs(): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = wc_get_logger();
		if ( method_exists( $logger, 'clear' ) ) {
			$logger->clear( Logger::SOURCE );
		}
	}

	/**
	 * Deletes the per-user notices and bot appearance drafts kept in the options table. With an external object cache they live there instead
	 * and expire within Notices::LIFETIME seconds on their own.
	 */
	private static function delete_notices(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients by prefix have no API; the rows are being removed, nothing to cache.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . Notices::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . Notices::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_' . FormDraft::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . FormDraft::TRANSIENT_PREFIX ) . '%'
			)
		);
	}
}
