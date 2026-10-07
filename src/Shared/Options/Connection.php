<?php
/**
 * The store's connection to the core.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Shared\Options;

/**
 * Store id and shared secret returned by the core's activation (contract §1.1), always kept together.
 */
final class Connection {

	/**
	 * Store public id, `shop_` + 16 hex characters (sent as X-WooNyx-Shop-Id).
	 *
	 * @var string
	 */
	public readonly string $store_id;

	/**
	 * Shared secret, `sec_` + 64 hex characters (HMAC key).
	 *
	 * @var string
	 */
	public readonly string $shared_secret;

	/**
	 * Constructor.
	 *
	 * @param string $store_id      Store public id.
	 * @param string $shared_secret Shared secret.
	 * @throws \InvalidArgumentException When a value doesn't have the core's format (the values are not echoed).
	 */
	public function __construct( string $store_id, string $shared_secret ) {
		if ( 1 !== preg_match( '/^shop_[a-f0-9]{16}$/D', $store_id ) ) {
			throw new \InvalidArgumentException( 'Invalid store id.' );
		}
		if ( 1 !== preg_match( '/^sec_[a-f0-9]{64}$/D', $shared_secret ) ) {
			throw new \InvalidArgumentException( 'Invalid shared secret.' );
		}

		$this->store_id      = $store_id;
		$this->shared_secret = $shared_secret;
	}

	/**
	 * Keeps the secret out of var_dump() and print_r() output.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array(
			'store_id'      => $this->store_id,
			'shared_secret' => '***',
		);
	}
}
