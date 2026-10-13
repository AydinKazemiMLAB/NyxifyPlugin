<?php
/**
 * The plugin's log, in WooCommerce > Status > Logs.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Shared\Logging;

use Nyxify\Shared\Http\CoreError;

/**
 * Writes to the WooCommerce logger with the source `nyxify`.
 *
 * Callers log error codes, HTTP statuses and request ids, never payloads. As a safety net every message and context
 * value is redacted (shared secrets, consumer keys and secrets, license keys, bot tokens, long tokens, Iranian mobile
 * numbers) and stripped of line breaks, and context values that aren't scalars are dropped.
 */
final class Logger {

	public const SOURCE = 'nyxify';

	public const REDACTED = '[redacted]';

	/**
	 * Patterns of values that must never reach a log, in the order they are applied.
	 */
	private const SECRET_PATTERNS = array(
		// Shared secret: sec_ + 64 hex.
		'/\bsec_[A-Za-z0-9]{8,}/',
		// WooCommerce REST API consumer key and secret: ck_/cs_ + 40 hex.
		'/\bc[ks]_[A-Za-z0-9]{8,}/',
		// License key: NX- + 4 groups of 6 base32 characters; case and whitespace are ignored by the core.
		'/\bNX-[A-Z2-7]{6}(?:[\s-]*[A-Z2-7]{6}){3}\b/i',
		// Telegram and Bale bot tokens: <bot id>:<secret>.
		'/\b\d{1,20}:[A-Za-z0-9_-]{20,100}/',
		// Iranian mobile numbers (personal data), not digits inside a word such as a hex request id.
		'/(?<![A-Za-z0-9])(?:\+98|0098|98|0)?9\d{9}(?![A-Za-z0-9])/',
		// Anything else that looks like a token (e.g. Rubika's), longer than a request id or a UUID.
		'/[A-Za-z0-9_-]{40,}/',
	);

	private const CONTEXT_KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

	/**
	 * The WooCommerce logger; looked up on the first write when not given.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private ?\WC_Logger_Interface $logger;

	/**
	 * Constructor.
	 *
	 * @param \WC_Logger_Interface|null $logger WooCommerce logger; wc_get_logger() when null.
	 */
	public function __construct( ?\WC_Logger_Interface $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Logs an error.
	 *
	 * @param string               $message Message without secrets or personal data.
	 * @param array<string, mixed> $context Context; only scalar values are kept.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Logs a warning.
	 *
	 * @param string               $message Message without secrets or personal data.
	 * @param array<string, mixed> $context Context; only scalar values are kept.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Logs an informational message.
	 *
	 * @param string               $message Message without secrets or personal data.
	 * @param array<string, mixed> $context Context; only scalar values are kept.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Logs a debug message.
	 *
	 * @param string               $message Message without secrets or personal data.
	 * @param array<string, mixed> $context Context; only scalar values are kept.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Logs a failed call to the core: the operation, the error code, the HTTP status and the request id only.
	 *
	 * No response or network failure and 5xx are errors; the rest (the owner can act on them) are warnings.
	 *
	 * @param string    $operation What was being done, e.g. `store/status`.
	 * @param CoreError $error     The failure.
	 */
	public function core_error( string $operation, CoreError $error ): void {
		$this->log(
			0 === $error->status || $error->status >= 500 ? 'error' : 'warning',
			'Nyxify core call failed.',
			array(
				'operation'  => $operation,
				'error_code' => $error->code,
				'status'     => $error->status,
				'request_id' => $error->request_id,
			)
		);
	}

	/**
	 * Replaces secrets and personal data with REDACTED and escapes line breaks (no forged log lines).
	 *
	 * @param string $text Text to log.
	 */
	public static function redact( string $text ): string {
		$text = (string) preg_replace( self::SECRET_PATTERNS, self::REDACTED, $text );

		return str_replace( array( "\r", "\n" ), array( '\r', '\n' ), $text );
	}

	/**
	 * Writes one entry.
	 *
	 * @param string               $level   WooCommerce log level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context; only scalar values under simple keys are kept.
	 */
	private function log( string $level, string $message, array $context ): void {
		if ( null === $this->logger ) {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}

			$this->logger = wc_get_logger();
		}

		$this->logger->log( $level, self::redact( $message ), self::clean_context( $context ) + array( 'source' => self::SOURCE ) );
	}

	/**
	 * Redacted scalar context values; `source` is always the plugin's.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return array<string, string|int|float|bool>
	 */
	private static function clean_context( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( 'source' === $key || 1 !== preg_match( self::CONTEXT_KEY_PATTERN, (string) $key ) || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ $key ] = is_string( $value ) ? self::redact( $value ) : $value;
		}

		return $clean;
	}
}
