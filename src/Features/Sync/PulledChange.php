<?php
/**
 * A change pulled from the core's outbox.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Sync;

/**
 * One item of `GET /sync/outbox` (`PulledChange`, contract §5.2), read defensively: an admin's product edit made in a
 * bot that the core couldn't push to the shop.
 *
 * - The action is any well-formed `entity.verb` name: one the plugin doesn't know is acknowledged as `invalid_change`
 *   when applied, so a newer core can't make every pull fail. The product and variation ids are checked against the
 *   action when applied too.
 * - The payload is a product's content (names, descriptions, prices, signed image links): never logged, and kept out
 *   of var_dump() and print_r() output.
 */
final class PulledChange {

	public const PRODUCT_CREATE = 'product.create';

	public const PRODUCT_UPDATE = 'product.update';

	public const PRODUCT_DELETE = 'product.delete';

	public const VARIATION_UPDATE = 'variation.update';

	/**
	 * The actions the core sends (`SyncAction`).
	 *
	 * @var list<string>
	 */
	public const ACTIONS = array( self::PRODUCT_CREATE, self::PRODUCT_UPDATE, self::PRODUCT_DELETE, self::VARIATION_UPDATE );

	private const ACTION_PATTERN = '/^[a-z][a-z0-9_]{0,31}\.[a-z][a-z0-9_]{0,31}$/D';

	/**
	 * The outbox entry's id (what the ack refers to).
	 *
	 * @var int
	 */
	public readonly int $id;

	/**
	 * The action, e.g. `product.update`.
	 *
	 * @var string
	 */
	public readonly string $action;

	/**
	 * The product's id in WooCommerce, or null (a create).
	 *
	 * @var int|null
	 */
	public readonly ?int $woo_product_id;

	/**
	 * The variation's id in WooCommerce, or null (anything but a variation edit).
	 *
	 * @var int|null
	 */
	public readonly ?int $woo_variation_id;

	/**
	 * WooCommerce REST fields to write (`{}` for a delete).
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $payload;

	/**
	 * Constructor.
	 *
	 * @param int                  $id               Outbox entry id.
	 * @param string               $action           Action.
	 * @param int|null             $woo_product_id   Product id, or null.
	 * @param int|null             $woo_variation_id Variation id, or null.
	 * @param array<string, mixed> $payload          Fields to write.
	 */
	private function __construct( int $id, string $action, ?int $woo_product_id, ?int $woo_variation_id, array $payload ) {
		$this->id               = $id;
		$this->action           = $action;
		$this->woo_product_id   = $woo_product_id;
		$this->woo_variation_id = $woo_variation_id;
		$this->payload          = $payload;
	}

	/**
	 * A change from the core's answer, or null when a field is missing or has the wrong type.
	 *
	 * @param mixed $data One decoded item of `changes`.
	 */
	public static function from_response( $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$id               = $data['id'] ?? null;
		$action           = $data['action'] ?? null;
		$woo_product_id   = $data['woo_product_id'] ?? null;
		$woo_variation_id = $data['woo_variation_id'] ?? null;
		$payload          = $data['payload'] ?? null;

		if ( ! is_int( $id ) || $id < 1
			|| ! is_string( $action ) || 1 !== preg_match( self::ACTION_PATTERN, $action )
			|| ! self::is_optional_id( $woo_product_id ) || ! self::is_optional_id( $woo_variation_id )
			|| ! self::is_object( $payload )
			|| ! is_string( $data['created_at'] ?? null )
		) {
			return null;
		}

		return new self( $id, $action, $woo_product_id, $woo_variation_id, $payload );
	}

	/**
	 * Keeps the payload out of var_dump() and print_r() output.
	 *
	 * @return array<string, int|string|null>
	 */
	public function __debugInfo(): array {
		return array(
			'id'               => $this->id,
			'action'           => $this->action,
			'woo_product_id'   => $this->woo_product_id,
			'woo_variation_id' => $this->woo_variation_id,
		);
	}

	/**
	 * Whether a value is a WooCommerce id (an integer of at least 1) or null.
	 *
	 * @param mixed $value Value.
	 * @phpstan-assert-if-true int|null $value
	 */
	private static function is_optional_id( $value ): bool {
		return null === $value || ( is_int( $value ) && $value >= 1 );
	}

	/**
	 * Whether a decoded value was a JSON object with field names (`{}` decodes to an empty array; a list, or a name
	 * PHP turns into an integer such as `"0"`, is no field of WooCommerce's).
	 *
	 * @param mixed $value Value.
	 * @phpstan-assert-if-true array<string, mixed> $value
	 */
	private static function is_object( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) ) {
				return false;
			}
		}

		return true;
	}
}
