<?php
/**
 * The fields a pulled change may write.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Sync;

use Nyxify\Shared\Http\CoreClient;

/**
 * Checks a pulled change against what the core can send (contract §5.1: `ProductFields`, `UpdateVariationRequest`,
 * images resolved to `src` links) and returns the body of its WooCommerce REST request.
 *
 * - Every field is checked by name, type and allowed values, nested ones included (categories and images); a field
 *   outside the whitelist, or of the wrong type, fails the change with `invalid_change`. The message names the
 *   fields, never their values.
 * - Image links must be https (http only where CoreClient allows plain http to the core), without whitespace.
 * - A create's `meta_data` is the plugin's own: `_nyxify_outbox_id` = the change's id, whatever the core sent (only
 *   that key is accepted from it).
 * - A delete carries no fields; an update or a variation edit at least one.
 */
final class PayloadFilter {

	/**
	 * The meta a created product carries, to find it again instead of creating it twice.
	 */
	public const OUTBOX_META_KEY = '_nyxify_outbox_id';

	/**
	 * The most images of a product (the core's `MAX_IMAGES`).
	 */
	public const MAX_IMAGES = 20;

	/**
	 * The most categories of a product (the core's limit).
	 */
	public const MAX_CATEGORIES = 100;

	/**
	 * The longest image link (the core's limit).
	 */
	public const MAX_SRC_LENGTH = 2000;

	private const PRICE_PATTERN = '/^\d{1,16}(?:\.\d{1,4})?$/D';

	private const FIELD_NAME_PATTERN = '/^[A-Za-z0-9_]{1,64}$/D';

	private const PRODUCT_STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	private const VARIATION_STATUSES = array( 'publish', 'private' );

	private const VISIBILITIES = array( 'visible', 'catalog', 'search', 'hidden' );

	private const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );

	private const BACKORDERS = array( 'no', 'notify', 'yes' );

	/**
	 * The body of a change's request, or an `invalid_change` result.
	 *
	 * @param PulledChange $change The change.
	 * @return array<string, mixed>|ChangeResult
	 */
	public function check( PulledChange $change ): array|ChangeResult {
		switch ( $change->action ) {
			case PulledChange::PRODUCT_CREATE:
				return $this->create( $change );
			case PulledChange::PRODUCT_UPDATE:
				if ( null === $change->woo_product_id ) {
					return self::invalid( $change, 'Missing product id.' );
				}
				return $this->fields( $change, $this->product_checks(), true );
			case PulledChange::PRODUCT_DELETE:
				if ( null === $change->woo_product_id ) {
					return self::invalid( $change, 'Missing product id.' );
				}
				return array() === $change->payload ? array() : self::not_allowed( $change, array_keys( $change->payload ) );
			case PulledChange::VARIATION_UPDATE:
				if ( null === $change->woo_product_id || null === $change->woo_variation_id ) {
					return self::invalid( $change, 'Missing product or variation id.' );
				}
				return $this->fields( $change, $this->variation_checks(), true );
			default:
				return self::invalid( $change, 'Unknown action.' );
		}//end switch
	}

	/**
	 * A create's body: the product fields, `type` (only `simple`) and the plugin's own `meta_data`.
	 *
	 * @param PulledChange $change The change.
	 * @return array<string, mixed>|ChangeResult
	 */
	private function create( PulledChange $change ): array|ChangeResult {
		$checks              = $this->product_checks();
		$checks['type']      = static fn( $value ): bool => 'simple' === $value;
		$checks['meta_data'] = static fn( $value ): bool => self::is_outbox_meta( $value );

		$body = $this->fields( $change, $checks, false );
		if ( $body instanceof ChangeResult ) {
			return $body;
		}

		$body['meta_data'] = array(
			array(
				'key'   => self::OUTBOX_META_KEY,
				'value' => (string) $change->id,
			),
		);

		return $body;
	}

	/**
	 * The payload when every field passes its check, or an `invalid_change` result naming the ones that don't.
	 *
	 * @param PulledChange            $change         The change.
	 * @param array<string, callable> $checks         A check per allowed field.
	 * @param bool                    $needs_a_field  Whether an empty payload is refused.
	 * @return array<string, mixed>|ChangeResult
	 *
	 * @phpstan-param array<string, callable(mixed): bool> $checks
	 */
	private function fields( PulledChange $change, array $checks, bool $needs_a_field ): array|ChangeResult {
		if ( $needs_a_field && array() === $change->payload ) {
			return self::invalid( $change, 'Nothing to change.' );
		}

		$refused = array();
		foreach ( $change->payload as $name => $value ) {
			if ( ! isset( $checks[ $name ] ) || ! $checks[ $name ]( $value ) ) {
				$refused[] = $name;
			}
		}

		return array() === $refused ? $change->payload : self::not_allowed( $change, $refused );
	}

	/**
	 * Checks of the product fields (`ProductFields::to_woo()`).
	 *
	 * @return array<string, callable(mixed): bool>
	 */
	private function product_checks(): array {
		return array(
			'name'               => static fn( $value ): bool => is_string( $value ) && '' !== trim( $value ),
			'status'             => static fn( $value ): bool => in_array( $value, self::PRODUCT_STATUSES, true ),
			'catalog_visibility' => static fn( $value ): bool => in_array( $value, self::VISIBILITIES, true ),
			'description'        => static fn( $value ): bool => is_string( $value ),
			'short_description'  => static fn( $value ): bool => is_string( $value ),
			'sku'                => static fn( $value ): bool => is_string( $value ),
			'regular_price'      => static fn( $value ): bool => self::is_price( $value ),
			'sale_price'         => static fn( $value ): bool => '' === $value || self::is_price( $value ),
			'manage_stock'       => static fn( $value ): bool => is_bool( $value ),
			'stock_quantity'     => static fn( $value ): bool => is_int( $value ),
			'stock_status'       => static fn( $value ): bool => in_array( $value, self::STOCK_STATUSES, true ),
			'backorders'         => static fn( $value ): bool => in_array( $value, self::BACKORDERS, true ),
			'categories'         => static fn( $value ): bool => self::is_categories( $value ),
			'images'             => fn( $value ): bool => $this->is_images( $value ),
		);
	}

	/**
	 * Checks of the variation fields (`UpdateVariationRequest::to_woo()`).
	 *
	 * @return array<string, callable(mixed): bool>
	 */
	private function variation_checks(): array {
		return array(
			'status'         => static fn( $value ): bool => in_array( $value, self::VARIATION_STATUSES, true ),
			'description'    => static fn( $value ): bool => is_string( $value ),
			'sku'            => static fn( $value ): bool => is_string( $value ),
			'regular_price'  => static fn( $value ): bool => self::is_price( $value ),
			'sale_price'     => static fn( $value ): bool => '' === $value || self::is_price( $value ),
			'manage_stock'   => static fn( $value ): bool => is_bool( $value ),
			'stock_quantity' => static fn( $value ): bool => is_int( $value ),
			'stock_status'   => static fn( $value ): bool => in_array( $value, self::STOCK_STATUSES, true ),
			'backorders'     => static fn( $value ): bool => in_array( $value, self::BACKORDERS, true ),
			'image'          => fn( $value ): bool => $this->is_image( $value ),
		);
	}

	/**
	 * Whether a value is a price as the core sends it (a decimal string, at most 4 decimals).
	 *
	 * @param mixed $value Value.
	 */
	private static function is_price( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::PRICE_PATTERN, $value );
	}

	/**
	 * Whether a value is `[{"id": n}, ...]`, at most MAX_CATEGORIES.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_categories( $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_CATEGORIES ) {
			return false;
		}

		foreach ( $value as $category ) {
			if ( ! is_array( $category ) || array( 'id' ) !== array_keys( $category ) || ! is_int( $category['id'] ) || $category['id'] < 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value is a list of at most MAX_IMAGES images (an empty list removes them all).
	 *
	 * @param mixed $value Value.
	 */
	private function is_images( $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_IMAGES ) {
			return false;
		}

		foreach ( $value as $image ) {
			if ( ! $this->is_image( $image ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value is one image: exactly one of `src` (a link) and `id` (a media library image), plus optional
	 * `name` and `alt`.
	 *
	 * @param mixed $value Value.
	 */
	private function is_image( $value ): bool {
		if ( ! is_array( $value ) || array() !== array_diff( array_keys( $value ), array( 'src', 'id', 'name', 'alt' ) ) ) {
			return false;
		}

		$has_src = array_key_exists( 'src', $value );
		if ( array_key_exists( 'id', $value ) === $has_src ) {
			return false;
		}

		$valid = $has_src ? self::is_link( $value['src'] ) : is_int( $value['id'] ) && $value['id'] >= 1;
		if ( ! $valid ) {
			return false;
		}

		foreach ( array( 'name', 'alt' ) as $text ) {
			if ( array_key_exists( $text, $value ) && ! is_string( $value[ $text ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value is an image link WooCommerce may download: https (http only where CoreClient allows plain
	 * http), a host, no whitespace, at most MAX_SRC_LENGTH characters.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_link( $value ): bool {
		if ( ! is_string( $value ) || strlen( $value ) > self::MAX_SRC_LENGTH || 1 === preg_match( '/\s/u', $value ) ) {
			return false;
		}

		$scheme = strtolower( (string) strstr( $value, '://', true ) );
		$host   = wp_parse_url( $value, PHP_URL_HOST );

		return ( 'https' === $scheme || ( 'http' === $scheme && CoreClient::allows_plain_http() ) )
			&& is_string( $host ) && '' !== $host;
	}

	/**
	 * Whether a create's `meta_data` holds only the outbox id (the plugin sets its value itself).
	 *
	 * @param mixed $value Value.
	 */
	private static function is_outbox_meta( $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return false;
		}

		foreach ( $value as $meta ) {
			if ( ! is_array( $meta ) || self::OUTBOX_META_KEY !== ( $meta['key'] ?? null ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * An `invalid_change` result naming the refused fields (their names only; one that isn't a plain name is counted).
	 *
	 * @param PulledChange $change The change.
	 * @param array<mixed> $names  Refused field names.
	 */
	private static function not_allowed( PulledChange $change, array $names ): ChangeResult {
		$plain = array();
		$other = 0;
		foreach ( $names as $name ) {
			if ( is_string( $name ) && 1 === preg_match( self::FIELD_NAME_PATTERN, $name ) ) {
				$plain[] = $name;
			} else {
				++$other;
			}
		}

		if ( $other > 0 ) {
			$plain[] = sprintf( '%d other', $other );
		}

		return self::invalid( $change, 'Fields not allowed or invalid: ' . implode( ', ', $plain ) . '.' );
	}

	/**
	 * An `invalid_change` result.
	 *
	 * @param PulledChange $change  The change.
	 * @param string       $message Message for the core's admins (no value of the payload).
	 */
	private static function invalid( PulledChange $change, string $message ): ChangeResult {
		return ChangeResult::failure( $change->id, ChangeResult::INVALID_CHANGE, $message );
	}
}
