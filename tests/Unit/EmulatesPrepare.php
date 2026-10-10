<?php
/**
 * A stand-in for $wpdb->prepare() in tests.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit;

/**
 * Fills %i (identifier, backticked), %s (quoted) and %d (integer) placeholders in order, with the arguments given
 * one by one or as a single array, as $wpdb->prepare() accepts them. Enough to compare the SQL a class sends.
 */
trait EmulatesPrepare {

	/**
	 * The prepared query.
	 *
	 * @param string $query   Query with placeholders.
	 * @param mixed  ...$args Values, or one array of values.
	 */
	protected static function prepare_query( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}

		$used = 0;
		$sql  = (string) preg_replace_callback(
			'/%([isd])/',
			static function ( array $placeholder ) use ( $args, &$used ): string {
				if ( ! array_key_exists( $used, $args ) ) {
					throw new \LogicException( 'Too few arguments for prepare().' );
				}

				$value = $args[ $used++ ];

				switch ( $placeholder[1] ) {
					case 'i':
						return '`' . (string) $value . '`';
					case 'd':
						return (string) (int) $value;
					default:
						return "'" . addslashes( (string) $value ) . "'";
				}
			},
			$query
		);

		if ( count( $args ) !== $used ) {
			throw new \LogicException( 'Too many arguments for prepare().' );
		}

		return $sql;
	}
}
