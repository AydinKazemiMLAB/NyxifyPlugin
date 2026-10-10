<?php
/**
 * The queue of catalog changes waiting to be sent to the core.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Catalog;

/**
 * Ids of changed products and categories, one row per kind and id, in the table of Schema. Every operation is a
 * single SQL statement (or a short sequence on rows only the caller has claimed), atomic per row, so concurrent
 * requests and flushes never lose an id:
 *
 * - enqueue() never reads first: a queued row stays as it is, a row claimed by a flush is marked dirty (its object
 *   changed after the flush read it) and stays queued after that flush completes;
 * - a flush claims rows with a token before sending them; two flushes can't claim the same row; claims older than
 *   CLAIM_TIMEOUT_SECONDS (a flush that died) are released before each claim;
 * - after a send the rows are completed, released, retried (attempts + 1, dropped after MAX_ATTEMPTS failures) or
 *   dropped.
 */
final class CatalogQueue implements Queue {

	public const PRODUCT         = 'product';
	public const PRODUCT_DELETE  = 'product_delete';
	public const CATEGORY        = 'category';
	public const CATEGORY_DELETE = 'category_delete';

	/**
	 * Every kind, in the order a flush sends them.
	 *
	 * @var list<string>
	 */
	public const KINDS = array( self::CATEGORY, self::PRODUCT, self::PRODUCT_DELETE, self::CATEGORY_DELETE );

	/**
	 * Rows per INSERT statement.
	 */
	public const CHUNK = 500;

	/**
	 * After this long a claim is considered abandoned and its rows are queued again.
	 */
	public const CLAIM_TIMEOUT_SECONDS = 900;

	/**
	 * Failed sends after which a row is dropped (the first send and four retries).
	 */
	public const MAX_ATTEMPTS = 5;

	private const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param \wpdb         $wpdb  Database.
	 * @param string        $table Full table name.
	 * @param callable|null $clock Current Unix time; time() by default.
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( \wpdb $wpdb, string $table, ?callable $clock = null ) {
		$this->wpdb  = $wpdb;
		$this->table = $table;
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * The current site's queue (the table name is read when called, so it also serves the sites of a network).
	 */
	public static function for_site(): self {
		global $wpdb;

		return new self( $wpdb, Schema::table_name() );
	}

	/**
	 * A new claim token.
	 */
	public static function new_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Whether a kind is one of KINDS.
	 *
	 * @param string $kind Kind.
	 */
	public static function is_kind( string $kind ): bool {
		return in_array( $kind, self::KINDS, true );
	}

	/**
	 * Queues ids: one INSERT … ON DUPLICATE KEY UPDATE per CHUNK ids, no read.
	 *
	 * @param string       $kind One of KINDS.
	 * @param array<mixed> $ids  Object ids; anything but a positive integer is ignored, duplicates queued once.
	 * @return bool Whether every statement succeeded.
	 *
	 * @throws \InvalidArgumentException For an unknown kind (a bug in the caller).
	 */
	public function enqueue( string $kind, array $ids ): bool {
		$wpdb = $this->wpdb;

		self::check_kind( $kind );

		$ids = self::clean_ids( $ids );
		$now = $this->now();
		$ok  = true;

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$values = array( $this->table );
			foreach ( $chunk as $id ) {
				array_push( $values, $kind, $id, $now );
			}

			$rows = implode( ', ', array_fill( 0, count( $chunk ), '(%s, %d, %s)' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $rows holds only placeholders, one group per id.
			$result = $this->run( $wpdb->prepare( "INSERT INTO %i (kind, object_id, queued_at) VALUES {$rows} ON DUPLICATE KEY UPDATE dirty = IF(claim IS NULL, dirty, 1)", ...$values ) );
			$ok     = $ok && false !== $result;
		}

		return $ok;
	}

	/**
	 * Claims up to $limit unclaimed rows of a kind, oldest first, after releasing abandoned claims.
	 *
	 * @param string $kind  One of KINDS.
	 * @param int    $limit Most rows to claim.
	 * @param string $token Claim token (new_token()).
	 * @return array<int, int> Claimed object id => failed attempts so far, oldest first; empty on failure.
	 *
	 * @throws \InvalidArgumentException For an unknown kind or a malformed token (a bug in the caller).
	 */
	public function claim( string $kind, int $limit, string $token ): array {
		$wpdb = $this->wpdb;

		self::check_kind( $kind );
		self::check_token( $token );

		if ( $limit < 1 ) {
			return array();
		}

		$this->release_abandoned();

		$claimed = $this->run( $wpdb->prepare( 'UPDATE %i SET claim = %s, claimed_at = %s, dirty = 0 WHERE kind = %s AND claim IS NULL ORDER BY queued_at, object_id LIMIT %d', $this->table, $token, $this->now(), $kind, $limit ) );
		if ( ! is_int( $claimed ) || $claimed < 1 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own queue table; rows only this flush holds.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, attempts FROM %i WHERE claim = %s ORDER BY queued_at, object_id', $this->table, $token ) );

		$ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $row->object_id, $row->attempts ) ) {
				$ids[ (int) $row->object_id ] = (int) $row->attempts;
			}
		}

		return $ids;
	}

	/**
	 * The claimed rows were delivered: deleted, except those queued again meanwhile (dirty), which are released with
	 * their attempts reset.
	 *
	 * @param string $token Claim token.
	 * @return bool Whether both statements succeeded.
	 */
	public function complete( string $token ): bool {
		$wpdb = $this->wpdb;

		self::check_token( $token );

		$deleted  = $this->run( $wpdb->prepare( 'DELETE FROM %i WHERE claim = %s AND dirty = 0', $this->table, $token ) );
		$released = $this->run( $wpdb->prepare( 'UPDATE %i SET claim = NULL, claimed_at = NULL, dirty = 0, attempts = 0 WHERE claim = %s', $this->table, $token ) );

		return false !== $deleted && false !== $released;
	}

	/**
	 * Claimed rows that weren't sent (e.g. over a batch's size limit) go back to the queue as they were.
	 *
	 * @param string       $token Claim token.
	 * @param array<mixed> $ids   Object ids to release; anything but a positive integer is ignored.
	 * @return bool Whether the statement succeeded (true when there is nothing to release).
	 */
	public function release( string $token, array $ids ): bool {
		$wpdb = $this->wpdb;

		self::check_token( $token );

		$ids = self::clean_ids( $ids );
		if ( array() === $ids ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One %d per id, the ids spread after the table and token.
		$result = $this->run( $wpdb->prepare( 'UPDATE %i SET claim = NULL, claimed_at = NULL, dirty = 0 WHERE claim = %s AND object_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', $this->table, $token, ...$ids ) );

		return false !== $result;
	}

	/**
	 * The send failed and will be retried: every claimed row gets one more attempt; rows that reached MAX_ATTEMPTS are
	 * deleted, the others released.
	 *
	 * @param string $token Claim token.
	 * @return int|false Rows dropped for too many attempts, or false when a statement failed.
	 */
	public function retry( string $token ) {
		$wpdb = $this->wpdb;

		self::check_token( $token );

		$counted  = $this->run( $wpdb->prepare( 'UPDATE %i SET attempts = attempts + 1 WHERE claim = %s', $this->table, $token ) );
		$dropped  = $this->run( $wpdb->prepare( 'DELETE FROM %i WHERE claim = %s AND attempts >= %d', $this->table, $token, self::MAX_ATTEMPTS ) );
		$released = $this->run( $wpdb->prepare( 'UPDATE %i SET claim = NULL, claimed_at = NULL, dirty = 0 WHERE claim = %s', $this->table, $token ) );

		if ( false === $counted || false === $dropped || false === $released ) {
			return false;
		}

		return (int) $dropped;
	}

	/**
	 * The claimed rows are given up (the core refused them for good).
	 *
	 * @param string $token Claim token.
	 * @return bool Whether the statement succeeded.
	 */
	public function drop( string $token ): bool {
		$wpdb = $this->wpdb;

		self::check_token( $token );

		return false !== $this->run( $wpdb->prepare( 'DELETE FROM %i WHERE claim = %s', $this->table, $token ) );
	}

	/**
	 * Empties the queue (the store or the plugin was deactivated, or the core refuses the store).
	 *
	 * @return bool Whether the statement succeeded.
	 */
	public function clear(): bool {
		$wpdb = $this->wpdb;

		return false !== $this->run( $wpdb->prepare( 'DELETE FROM %i', $this->table ) );
	}

	/**
	 * Whether any row waits unclaimed (a flush schedules the next one when so).
	 */
	public function has_unclaimed(): bool {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own queue table; must see other requests' rows now.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE claim IS NULL LIMIT 1', $this->table ) );
	}

	/**
	 * Runs a query $wpdb->prepare() built; a query prepare() couldn't build (null) fails without reaching the database.
	 *
	 * @param string|null $sql Prepared query.
	 * @return int|bool Rows affected, or false on failure.
	 */
	private function run( ?string $sql ) {
		if ( null === $sql ) {
			return false;
		}

		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own queue table; every caller passes $wpdb->prepare()'s result.
		return $wpdb->query( $sql );
	}

	/**
	 * Releases claims older than CLAIM_TIMEOUT_SECONDS.
	 */
	private function release_abandoned(): void {
		$wpdb = $this->wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', ( $this->clock )() - self::CLAIM_TIMEOUT_SECONDS );

		$this->run( $wpdb->prepare( 'UPDATE %i SET claim = NULL, claimed_at = NULL, dirty = 0 WHERE claim IS NOT NULL AND claimed_at < %s', $this->table, $cutoff ) );
	}

	/**
	 * The current time as a GMT DATETIME.
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s', ( $this->clock )() );
	}

	/**
	 * Positive integer ids, each once, in their first order.
	 *
	 * @param array<mixed> $ids Ids.
	 * @return list<int>
	 */
	private static function clean_ids( array $ids ): array {
		$clean = array();
		foreach ( $ids as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Refuses an unknown kind.
	 *
	 * @param string $kind Kind.
	 *
	 * @throws \InvalidArgumentException For an unknown kind.
	 */
	private static function check_kind( string $kind ): void {
		if ( ! self::is_kind( $kind ) ) {
			throw new \InvalidArgumentException( 'Unknown catalog queue kind.' );
		}
	}

	/**
	 * Refuses a malformed token (it is part of every claim's WHERE clause).
	 *
	 * @param string $token Claim token.
	 *
	 * @throws \InvalidArgumentException For a malformed token.
	 */
	private static function check_token( string $token ): void {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			throw new \InvalidArgumentException( 'Invalid catalog queue claim token.' );
		}
	}
}
