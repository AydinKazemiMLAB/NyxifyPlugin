<?php
/**
 * The outcome of one pulled change, as the ack reports it.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Sync;

/**
 * One item of `POST /sync/outbox/ack`'s `results` (`ChangeResult`, contract §5.2), always within the core's limits so
 * that one result can never make the core refuse the whole ack (422):
 *
 * - a code outside `[a-z0-9_]{1,100}` becomes `plugin_error`;
 * - a message is trimmed and cut to 1000 characters; one that isn't valid UTF-8 is dropped;
 * - a date that isn't a real `Y-m-d\TH:i:s` (UTC, the product webhook's format) is dropped, and only successes keep
 *   one;
 * - `woo_product_id` is kept only when at least 1;
 * - fields not set are left out of the ack.
 *
 * The message is WooCommerce's, already free of links and tags (the applier's job); it is sent to the core for its
 * admins, never logged.
 */
final class ChangeResult {

	/**
	 * The change can't be applied as it came: an unknown action, a missing id, a malformed payload or a field outside
	 * the whitelist.
	 */
	public const INVALID_CHANGE = 'invalid_change';

	/**
	 * The plugin's REST key or its user is gone: nothing can be written as the core's user.
	 */
	public const REST_USER_MISSING = 'rest_user_missing';

	/**
	 * The plugin failed while applying the change (an exception).
	 */
	public const PLUGIN_ERROR = 'plugin_error';

	/**
	 * WooCommerce refused the change with a code the core can't take.
	 */
	public const WOOCOMMERCE_ERROR = 'woocommerce_error';

	/**
	 * Codes the core takes (`error_code`, at most 100 characters).
	 */
	public const CODE_PATTERN = '/^[a-z0-9_]{1,100}$/D';

	/**
	 * The longest message the core takes, in characters.
	 */
	public const MAX_MESSAGE_LENGTH = 1000;

	private const DATE_FORMAT = 'Y-m-d\TH:i:s';

	/**
	 * The outbox entry's id.
	 *
	 * @var int
	 */
	public readonly int $id;

	/**
	 * Whether the change was applied.
	 *
	 * @var bool
	 */
	public readonly bool $success;

	/**
	 * The product's id (the created one for a create), or null.
	 *
	 * @var int|null
	 */
	public readonly ?int $woo_product_id;

	/**
	 * The product's `date_modified_gmt` after the run (the parent's for a variation), or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $date_modified_gmt;

	/**
	 * Why it failed, or null on success.
	 *
	 * @var string|null
	 */
	public readonly ?string $error_code;

	/**
	 * WooCommerce's message for the core's admins, or '' (never logged).
	 *
	 * @var string
	 */
	public readonly string $error_message;

	/**
	 * Constructor.
	 *
	 * @param int         $id                Outbox entry id.
	 * @param bool        $success           Whether it was applied.
	 * @param int|null    $woo_product_id    Product id, or null.
	 * @param string|null $date_modified_gmt Product date, or null.
	 * @param string|null $error_code        Error code, or null.
	 * @param string      $error_message     Error message, or ''.
	 */
	private function __construct( int $id, bool $success, ?int $woo_product_id, ?string $date_modified_gmt, ?string $error_code, string $error_message ) {
		$this->id                = $id;
		$this->success           = $success;
		$this->woo_product_id    = null !== $woo_product_id && $woo_product_id >= 1 ? $woo_product_id : null;
		$this->date_modified_gmt = $success ? self::valid_date( $date_modified_gmt ) : null;
		$this->error_code        = $error_code;
		$this->error_message     = $error_message;
	}

	/**
	 * An applied change.
	 *
	 * @param int      $id             Outbox entry id.
	 * @param int|null $woo_product_id The product's id (the created one for a create), or null.
	 */
	public static function success( int $id, ?int $woo_product_id = null ): self {
		return new self( $id, true, $woo_product_id, null, null, '' );
	}

	/**
	 * A change that wasn't applied.
	 *
	 * @param int    $id      Outbox entry id.
	 * @param string $code    Error code; one the core can't take becomes PLUGIN_ERROR.
	 * @param string $message Message for the core's admins, or ''.
	 */
	public static function failure( int $id, string $code, string $message = '' ): self {
		$code = 1 === preg_match( self::CODE_PATTERN, $code ) ? $code : self::PLUGIN_ERROR;

		return new self( $id, false, null, null, $code, self::clean_message( $message ) );
	}

	/**
	 * The same result with the product's date (ignored on a failure, dropped when malformed).
	 *
	 * @param string|null $date_modified_gmt `wc_rest_prepare_date_response()` of the product's date, or null.
	 */
	public function with_date( ?string $date_modified_gmt ): self {
		return new self( $this->id, $this->success, $this->woo_product_id, $date_modified_gmt, $this->error_code, $this->error_message );
	}

	/**
	 * The result as the ack sends it, without the fields not set.
	 *
	 * @return array<string, int|string|bool>
	 */
	public function to_ack(): array {
		$result = array(
			'id'      => $this->id,
			'success' => $this->success,
		);

		if ( null !== $this->woo_product_id ) {
			$result['woo_product_id'] = $this->woo_product_id;
		}
		if ( null !== $this->date_modified_gmt ) {
			$result['date_modified_gmt'] = $this->date_modified_gmt;
		}
		if ( null !== $this->error_code ) {
			$result['error_code'] = $this->error_code;
		}
		if ( '' !== $this->error_message ) {
			$result['error_message'] = $this->error_message;
		}

		return $result;
	}

	/**
	 * Keeps the message out of var_dump() and print_r() output.
	 *
	 * @return array<string, int|string|bool|null>
	 */
	public function __debugInfo(): array {
		return array(
			'id'         => $this->id,
			'success'    => $this->success,
			'error_code' => $this->error_code,
		);
	}

	/**
	 * A real date in DATE_FORMAT, or null.
	 *
	 * @param string|null $date Date.
	 */
	private static function valid_date( ?string $date ): ?string {
		if ( null === $date ) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!' . self::DATE_FORMAT, $date, new \DateTimeZone( 'UTC' ) );

		// A date that doesn't exist (31 Feb, 25:00) doesn't read back the same.
		return false !== $parsed && $parsed->format( self::DATE_FORMAT ) === $date ? $date : null;
	}

	/**
	 * A message trimmed and cut to MAX_MESSAGE_LENGTH characters, or '' when it isn't valid UTF-8.
	 *
	 * @param string $message Message.
	 */
	private static function clean_message( string $message ): string {
		if ( 1 !== preg_match( '/^.{0,' . self::MAX_MESSAGE_LENGTH . '}/su', trim( $message ), $matches ) ) {
			return '';
		}

		return trim( $matches[0] );
	}
}
