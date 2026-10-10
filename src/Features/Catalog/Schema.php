<?php
/**
 * The catalog queue's table.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Catalog;

use WooNyx\Shared\Logging\Logger;

/**
 * Creates, upgrades and drops `{prefix}woonyx_catalog_queue` (one per site of a network). The table is created on
 * plugin activation and, because an update by upload doesn't run the activation hook, whenever the stored version
 * differs from VERSION. dbDelta() needs two spaces after PRIMARY KEY and one column per line.
 */
final class Schema {

	/**
	 * Table name without the site's prefix.
	 */
	public const TABLE = 'woonyx_catalog_queue';

	/**
	 * Version of the table's definition; raise it when the definition changes.
	 */
	public const VERSION = '1';

	/**
	 * Option holding the version of the table this site has (autoloaded: read on every request).
	 */
	public const OPTION_VERSION = 'woonyx_db_version';

	/**
	 * The current site's table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Whether this site's table was created (by any version).
	 */
	public static function is_installed(): bool {
		return false !== get_option( self::OPTION_VERSION, false );
	}

	/**
	 * Creates or upgrades the table when the stored version isn't the current one.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION !== get_option( self::OPTION_VERSION, false ) ) {
			self::install();
		}
	}

	/**
	 * Creates or upgrades the table; the version is stored only once the table exists, so a failure is retried on a
	 * later request.
	 *
	 * @return bool Whether the table exists.
	 */
	public static function install(): bool {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table = self::table_name();
		dbDelta( self::definition( $table, $wpdb->get_charset_collate() ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking the plugin's own table right after creating it.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $found ) {
			( new Logger() )->warning( 'The catalog queue table could not be created.', array( 'table' => $table ) );
			return false;
		}

		update_option( self::OPTION_VERSION, self::VERSION, true );

		return true;
	}

	/**
	 * Drops the table and forgets its version (uninstall).
	 */
	public static function drop(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table_name() ) );
		delete_option( self::OPTION_VERSION );
	}

	/**
	 * The CREATE TABLE statement in dbDelta()'s format.
	 *
	 * @param string $table           Full table name.
	 * @param string $charset_collate The site's DEFAULT CHARACTER SET / COLLATE clause.
	 */
	public static function definition( string $table, string $charset_collate ): string {
		return "CREATE TABLE {$table} (
kind varchar(20) NOT NULL,
object_id bigint(20) unsigned NOT NULL,
queued_at datetime NOT NULL,
claim char(32) DEFAULT NULL,
claimed_at datetime DEFAULT NULL,
dirty tinyint(1) NOT NULL DEFAULT 0,
attempts smallint(5) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (kind,object_id),
KEY claim (claim)
) {$charset_collate};";
	}
}
