<?php
/**
 * The store's status on the core.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Store;

/**
 * The fields of `GET /store/status` (contract §1.3) the settings page shows, read defensively; nothing in it is
 * secret. Dates are the core's naive UTC ISO strings.
 */
final class StoreStatus {

	/**
	 * Store public id.
	 *
	 * @var string
	 */
	public readonly string $store_id;

	/**
	 * The site address the core knows.
	 *
	 * @var string
	 */
	public readonly string $website_url;

	/**
	 * `active`, `deactivated` or `suspended`.
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * License display prefix (`NX-ABCDEF`), or null without a license.
	 *
	 * @var string|null
	 */
	public readonly ?string $license_prefix;

	/**
	 * When the license was activated.
	 *
	 * @var string|null
	 */
	public readonly ?string $license_activated_at;

	/**
	 * Plan display name.
	 *
	 * @var string
	 */
	public readonly string $plan_name;

	/**
	 * When the subscription expires.
	 *
	 * @var string
	 */
	public readonly string $expires_at;

	/**
	 * Messengers the plan allows (`telegram`, `bale`, `rubika`).
	 *
	 * @var list<string>
	 */
	public readonly array $allowed_platforms;

	/**
	 * Product limit of the plan, or null for none.
	 *
	 * @var int|null
	 */
	public readonly ?int $max_products;

	/**
	 * Catalog sync: `never`, `running`, `succeeded` or `failed`.
	 *
	 * @var string
	 */
	public readonly string $catalog_status;

	/**
	 * When the catalog was last synced.
	 *
	 * @var string|null
	 */
	public readonly ?string $catalog_last_synced_at;

	/**
	 * The last sync's error, a message the core makes safe to show the owner.
	 *
	 * @var string|null
	 */
	public readonly ?string $catalog_error;

	/**
	 * Whether the plan's product limit left products out.
	 *
	 * @var bool
	 */
	public readonly bool $limit_reached;

	/**
	 * Products the core has.
	 *
	 * @var int
	 */
	public readonly int $active_products;

	/**
	 * When the plugin fetched this status (Unix time).
	 *
	 * @var int
	 */
	public readonly int $checked_at;

	/**
	 * Constructor.
	 *
	 * @param string      $store_id               Store public id.
	 * @param string      $website_url            Site address.
	 * @param string      $status                 Store status.
	 * @param string|null $license_prefix         License display prefix.
	 * @param string|null $license_activated_at   License activation time.
	 * @param string      $plan_name              Plan display name.
	 * @param string      $expires_at             Subscription expiry.
	 * @param string[]    $allowed_platforms      Allowed messengers.
	 * @param int|null    $max_products           Product limit.
	 * @param string      $catalog_status         Catalog sync state.
	 * @param string|null $catalog_last_synced_at Last sync time.
	 * @param string|null $catalog_error          Last sync error.
	 * @param bool        $limit_reached          Whether the limit left products out.
	 * @param int         $active_products        Products the core has.
	 * @param int         $checked_at             Fetch time.
	 *
	 * @phpstan-param list<string> $allowed_platforms
	 */
	private function __construct(
		string $store_id,
		string $website_url,
		string $status,
		?string $license_prefix,
		?string $license_activated_at,
		string $plan_name,
		string $expires_at,
		array $allowed_platforms,
		?int $max_products,
		string $catalog_status,
		?string $catalog_last_synced_at,
		?string $catalog_error,
		bool $limit_reached,
		int $active_products,
		int $checked_at
	) {
		$this->store_id               = $store_id;
		$this->website_url            = $website_url;
		$this->status                 = $status;
		$this->license_prefix         = $license_prefix;
		$this->license_activated_at   = $license_activated_at;
		$this->plan_name              = $plan_name;
		$this->expires_at             = $expires_at;
		$this->allowed_platforms      = $allowed_platforms;
		$this->max_products           = $max_products;
		$this->catalog_status         = $catalog_status;
		$this->catalog_last_synced_at = $catalog_last_synced_at;
		$this->catalog_error          = $catalog_error;
		$this->limit_reached          = $limit_reached;
		$this->active_products        = $active_products;
		$this->checked_at             = $checked_at;
	}

	/**
	 * The status in the core's answer, or null when a required field is missing or has the wrong type.
	 *
	 * @param array<array-key, mixed> $data       Decoded `GET /store/status` answer.
	 * @param int                     $checked_at When it was fetched (Unix time).
	 */
	public static function from_response( array $data, int $checked_at ): ?self {
		$license      = is_array( $data['license'] ?? null ) ? $data['license'] : array();
		$subscription = is_array( $data['subscription'] ?? null ) ? $data['subscription'] : null;
		$catalog      = is_array( $data['catalog'] ?? null ) ? $data['catalog'] : null;
		if ( null === $subscription || null === $catalog ) {
			return null;
		}

		return self::from_array(
			array(
				'store_id'               => $data['store_id'] ?? null,
				'website_url'            => $data['website_url'] ?? null,
				'status'                 => $data['status'] ?? null,
				'license_prefix'         => $license['key_prefix'] ?? null,
				'license_activated_at'   => $license['activated_at'] ?? null,
				'plan_name'              => $subscription['plan_name'] ?? null,
				'expires_at'             => $subscription['expires_at'] ?? null,
				'allowed_platforms'      => $subscription['allowed_platforms'] ?? null,
				'max_products'           => $subscription['max_products'] ?? null,
				'catalog_status'         => $catalog['status'] ?? null,
				'catalog_last_synced_at' => $catalog['last_synced_at'] ?? null,
				'catalog_error'          => $catalog['error'] ?? null,
				'limit_reached'          => $catalog['limit_reached'] ?? null,
				'active_products'        => $catalog['active_products'] ?? null,
				'checked_at'             => $checked_at,
			)
		);
	}

	/**
	 * A status from its stored fields, or null when they are not valid.
	 *
	 * @param mixed $data Stored value (to_array()).
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$store_id               = $data['store_id'] ?? null;
		$website_url            = $data['website_url'] ?? null;
		$status                 = $data['status'] ?? null;
		$license_prefix         = $data['license_prefix'] ?? null;
		$license_activated_at   = $data['license_activated_at'] ?? null;
		$plan_name              = $data['plan_name'] ?? null;
		$expires_at             = $data['expires_at'] ?? null;
		$allowed_platforms      = $data['allowed_platforms'] ?? null;
		$max_products           = $data['max_products'] ?? null;
		$catalog_status         = $data['catalog_status'] ?? null;
		$catalog_last_synced_at = $data['catalog_last_synced_at'] ?? null;
		$catalog_error          = $data['catalog_error'] ?? null;
		$limit_reached          = $data['limit_reached'] ?? null;
		$active_products        = $data['active_products'] ?? null;
		$checked_at             = $data['checked_at'] ?? null;

		if ( ! is_string( $store_id ) || ! is_string( $website_url ) || ! is_string( $status )
			|| ! is_string( $plan_name ) || ! is_string( $expires_at ) || ! is_string( $catalog_status )
			|| ! self::is_optional_string( $license_prefix ) || ! self::is_optional_string( $license_activated_at )
			|| ! self::is_optional_string( $catalog_last_synced_at ) || ! self::is_optional_string( $catalog_error )
			|| ! is_array( $allowed_platforms ) || ! ( null === $max_products || is_int( $max_products ) )
			|| ! is_bool( $limit_reached ) || ! is_int( $active_products ) || ! is_int( $checked_at )
		) {
			return null;
		}

		return new self(
			$store_id,
			$website_url,
			$status,
			$license_prefix,
			$license_activated_at,
			$plan_name,
			$expires_at,
			array_values( array_filter( $allowed_platforms, 'is_string' ) ),
			$max_products,
			$catalog_status,
			$catalog_last_synced_at,
			$catalog_error,
			$limit_reached,
			$active_products,
			$checked_at
		);
	}

	/**
	 * Whether a value is a string or null.
	 *
	 * @param mixed $value Value.
	 * @phpstan-assert-if-true string|null $value
	 */
	private static function is_optional_string( $value ): bool {
		return null === $value || is_string( $value );
	}

	/**
	 * The fields to cache.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'store_id'               => $this->store_id,
			'website_url'            => $this->website_url,
			'status'                 => $this->status,
			'license_prefix'         => $this->license_prefix,
			'license_activated_at'   => $this->license_activated_at,
			'plan_name'              => $this->plan_name,
			'expires_at'             => $this->expires_at,
			'allowed_platforms'      => $this->allowed_platforms,
			'max_products'           => $this->max_products,
			'catalog_status'         => $this->catalog_status,
			'catalog_last_synced_at' => $this->catalog_last_synced_at,
			'catalog_error'          => $this->catalog_error,
			'limit_reached'          => $this->limit_reached,
			'active_products'        => $this->active_products,
			'checked_at'             => $this->checked_at,
		);
	}
}
