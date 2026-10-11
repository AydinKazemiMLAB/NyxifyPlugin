<?php
/**
 * A WooCommerce REST key just created by the plugin.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Store;

/**
 * The plain consumer key and secret exist only in this value, for the activation request; WooCommerce keeps a hash
 * of the key, and the plugin keeps only the key id.
 */
final class RestKey {

	/**
	 * Row id in `woocommerce_api_keys` (not secret).
	 *
	 * @var int
	 */
	public readonly int $key_id;

	/**
	 * Consumer key, `ck_` + 40 hex characters.
	 *
	 * @var string
	 */
	public readonly string $consumer_key;

	/**
	 * Consumer secret, `cs_` + 40 hex characters.
	 *
	 * @var string
	 */
	public readonly string $consumer_secret;

	/**
	 * Constructor.
	 *
	 * @param int    $key_id          Row id.
	 * @param string $consumer_key    Plain consumer key.
	 * @param string $consumer_secret Consumer secret.
	 */
	public function __construct( int $key_id, string $consumer_key, string $consumer_secret ) {
		$this->key_id          = $key_id;
		$this->consumer_key    = $consumer_key;
		$this->consumer_secret = $consumer_secret;
	}

	/**
	 * Keeps the key and secret out of var_dump() and print_r() output.
	 *
	 * @return array<string, int|string>
	 */
	public function __debugInfo(): array {
		return array(
			'key_id'          => $this->key_id,
			'consumer_key'    => '***',
			'consumer_secret' => '***',
		);
	}
}
