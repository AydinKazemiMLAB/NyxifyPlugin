<?php
/**
 * Data removal when the plugin is deleted.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx;

use WooNyx\Features\Store\RestKeys;
use WooNyx\Features\Store\StatusService;
use WooNyx\Shared\Admin\Notices;
use WooNyx\Shared\Options\SecretStore;

/**
 * Removes everything the plugin stored, on every site of a network: the WooCommerce REST key it created, its options
 * and its transients. Nothing is sent to the core (the deactivation already told it, or couldn't); keys the plugin
 * didn't create are never touched.
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
	 * Deletes the current site's REST key, options and transients.
	 */
	private static function clean_site(): void {
		self::delete_rest_key();

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
	 * Deletes the per-user notices kept in the options table. With an external object cache they live there instead
	 * and expire within Notices::LIFETIME seconds on their own.
	 */
	private static function delete_notices(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients by prefix have no API; the rows are being removed, nothing to cache.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . Notices::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . Notices::TRANSIENT_PREFIX ) . '%'
			)
		);
	}
}
