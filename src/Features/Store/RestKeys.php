<?php
/**
 * The plugin's WooCommerce REST key.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Store;

/**
 * Creates and deletes the read/write REST key the core uses to reach the shop (contract §1.1), the way
 * `WC_Auth::create_keys()` does, and remembers its id so activating again replaces it and uninstall deletes it.
 * Keys the plugin didn't create are never touched.
 */
final class RestKeys {

	/**
	 * Option holding the id of the key the plugin created (an integer, not secret, not autoloaded).
	 */
	public const OPTION_KEY_ID = 'woonyx_rest_key_id';

	/**
	 * WooCommerce's table, without the site prefix.
	 */
	private const TABLE = 'woocommerce_api_keys';

	/**
	 * The core reads and writes products and orders.
	 */
	private const PERMISSIONS = 'read_write';

	/**
	 * Creates a read/write key for a user.
	 *
	 * @param int $user_id Owner of the key (the admin who activates).
	 * @return RestKey|null Null when the key could not be created.
	 */
	public function create( int $user_id ): ?RestKey {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return null;
		}

		try {
			// What wc_rand_hash() returns by default, generated here so the format always matches the core's
			// `^ck_[a-f0-9]{40}$` / `^cs_[a-f0-9]{40}$`.
			$consumer_key    = 'ck_' . bin2hex( random_bytes( 20 ) );
			$consumer_secret = 'cs_' . bin2hex( random_bytes( 20 ) );
		} catch ( \Exception $e ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WooCommerce has no API to create a key; same insert as WC_Auth::create_keys().
		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'user_id'         => $user_id,
				'description'     => sprintf( 'WooNyx - API (%s)', gmdate( 'Y-m-d H:i:s' ) ),
				'permissions'     => self::PERMISSIONS,
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$key_id = (int) $wpdb->insert_id;
		if ( 1 !== $inserted || $key_id <= 0 ) {
			return null;
		}

		return new RestKey( $key_id, $consumer_key, $consumer_secret );
	}

	/**
	 * Deletes a key the plugin created.
	 *
	 * @param int $key_id Row id.
	 * @return bool False on a database error; a key that is already gone counts as deleted.
	 */
	public function delete( int $key_id ): bool {
		global $wpdb;

		if ( $key_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same delete as WC_Admin_API_Keys::remove_key(); WooCommerce doesn't cache keys.
		$deleted = $wpdb->delete( $wpdb->prefix . self::TABLE, array( 'key_id' => $key_id ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * Id of the key the plugin created last.
	 *
	 * @return int|null Null when none is remembered.
	 */
	public function current_id(): ?int {
		$value = get_option( self::OPTION_KEY_ID, null );
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$key_id = (int) $value;

		return $key_id > 0 ? $key_id : null;
	}

	/**
	 * Remembers the id of the key the plugin created.
	 *
	 * @param int $key_id Row id.
	 * @return bool Whether the option holds that id afterwards.
	 */
	public function remember( int $key_id ): bool {
		if ( $key_id <= 0 ) {
			return false;
		}
		// update_option() returns false when nothing changes.
		if ( $this->current_id() === $key_id ) {
			return true;
		}

		return update_option( self::OPTION_KEY_ID, $key_id, false );
	}

	/**
	 * Forgets the remembered key id (the key itself is deleted with delete()).
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_option( self::OPTION_KEY_ID );
	}
}
