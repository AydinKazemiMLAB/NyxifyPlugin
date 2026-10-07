<?php
/**
 * Data removal when the plugin is deleted.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx;

use WooNyx\Shared\Options\SecretStore;

/**
 * Removes everything the plugin stored, on every site of a network.
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
			self::clean_site();
			restore_current_blog();
		}
	}

	/**
	 * Deletes the current site's options.
	 */
	private static function clean_site(): void {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}
