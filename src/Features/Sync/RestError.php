<?php
/**
 * What a refused WooCommerce REST request tells the core.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Sync;

/**
 * Reads the error answer of an in-process REST request the way the core's push reports one: WooCommerce's code when
 * the core can take it (else `woocommerce_error`) and its message with tags stripped and every link replaced (an image
 * download error carries the signed link).
 */
final class RestError {

	private const LINK_PATTERN = '#\b[a-z][a-z0-9+.-]*://[^\s<>"\']+#i';

	private const LINK = '[link]';

	/**
	 * WooCommerce's error code when the core can take it, else `woocommerce_error`.
	 *
	 * @param mixed $data The error answer.
	 */
	public static function code( $data ): string {
		$code = is_array( $data ) ? ( $data['code'] ?? null ) : null;

		return is_string( $code ) && 1 === preg_match( ChangeResult::CODE_PATTERN, $code ) ? $code : ChangeResult::WOOCOMMERCE_ERROR;
	}

	/**
	 * WooCommerce's message without tags and links, or one naming the status when it has none.
	 *
	 * @param mixed $data   The error answer.
	 * @param int   $status Its HTTP status.
	 */
	public static function message( $data, int $status ): string {
		$message = is_array( $data ) && is_string( $data['message'] ?? null ) ? $data['message'] : '';
		$message = (string) preg_replace( self::LINK_PATTERN, self::LINK, wp_strip_all_tags( $message ) );
		$message = trim( (string) preg_replace( '/\s+/u', ' ', $message ) );

		return '' !== $message ? $message : sprintf( 'WooCommerce answered with HTTP %d.', $status );
	}

	/**
	 * A failed result carrying the answer's code and message.
	 *
	 * @param int    $change_id Outbox entry id.
	 * @param mixed  $data      The error answer.
	 * @param int    $status    Its HTTP status.
	 * @param string $code      A code of our own instead of WooCommerce's, or '' to use WooCommerce's.
	 */
	public static function failure( int $change_id, $data, int $status, string $code = '' ): ChangeResult {
		return ChangeResult::failure( $change_id, '' !== $code ? $code : self::code( $data ), self::message( $data, $status ) );
	}
}
