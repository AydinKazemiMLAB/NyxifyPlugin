<?php
/**
 * HMAC signatures of the requests between the plugin and the core.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Shared\Security;

/**
 * The Nyxify signing protocol (nyxify-security §1), a port of the core's `HMACSigner`.
 *
 * `signature = "sha256=" + hex(HMAC-SHA256(shared_secret, timestamp + "." + nonce + "." + raw_body))`. The body is
 * the exact string sent on the wire: encode JSON once, sign that string, send that string.
 */
final class HmacSigner {

	public const HEADER_SHOP_ID   = 'X-Nyxify-Shop-Id';
	public const HEADER_TIMESTAMP = 'X-Nyxify-Timestamp';
	public const HEADER_NONCE     = 'X-Nyxify-Nonce';
	public const HEADER_SIGNATURE = 'X-Nyxify-Signature';

	/**
	 * Accepted clock difference, in seconds, in both directions (the core's HMAC_TIMESTAMP_TOLERANCE_SECONDS).
	 */
	public const TIMESTAMP_TOLERANCE_SECONDS = 300;

	private const SIGNATURE_PREFIX = 'sha256=';

	private const SHOP_ID_PATTERN   = '/^shop_[a-f0-9]{16}$/D';
	private const TIMESTAMP_PATTERN = '/^\d{1,12}$/D';
	private const NONCE_PATTERN     = '/^[A-Za-z0-9_-]{16,128}$/D';
	private const SIGNATURE_PATTERN = '/^sha256=[a-f0-9]{64}$/D';

	/**
	 * Shared secret (HMAC key).
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Constructor.
	 *
	 * @param string $secret Shared secret.
	 * @throws \InvalidArgumentException When the secret is empty.
	 */
	public function __construct( string $secret ) {
		if ( '' === $secret ) {
			throw new \InvalidArgumentException( 'The shared secret must not be empty.' );
		}

		$this->secret = $secret;
	}

	/**
	 * The signed message: `timestamp.nonce.body`.
	 *
	 * @param string $timestamp Unix seconds.
	 * @param string $nonce     Request nonce.
	 * @param string $body      Raw body ('' for none).
	 */
	public static function build_message( string $timestamp, string $nonce, string $body ): string {
		return $timestamp . '.' . $nonce . '.' . $body;
	}

	/**
	 * Signature of one request.
	 *
	 * @param string $timestamp Unix seconds.
	 * @param string $nonce     Request nonce.
	 * @param string $body      Raw body ('' for none).
	 * @return string `sha256=` + 64 lowercase hex characters.
	 */
	public function sign( string $timestamp, string $nonce, string $body ): string {
		return self::SIGNATURE_PREFIX . hash_hmac( 'sha256', self::build_message( $timestamp, $nonce, $body ), $this->secret );
	}

	/**
	 * Headers of an outgoing signed request. Send exactly the `$body` that was signed.
	 *
	 * @param string      $shop_id   Store public id.
	 * @param string      $body      Raw body ('' for none).
	 * @param int|null    $timestamp Unix seconds; now when null.
	 * @param string|null $nonce     Nonce; 32 random hex characters when null.
	 * @return array<string, string>
	 */
	public function build_headers( string $shop_id, string $body, ?int $timestamp = null, ?string $nonce = null ): array {
		$request_timestamp = (string) ( $timestamp ?? time() );
		$request_nonce     = $nonce ?? self::new_nonce();

		return array(
			self::HEADER_SHOP_ID   => $shop_id,
			self::HEADER_TIMESTAMP => $request_timestamp,
			self::HEADER_NONCE     => $request_nonce,
			self::HEADER_SIGNATURE => $this->sign( $request_timestamp, $request_nonce, $body ),
		);
	}

	/**
	 * Checks an incoming signed request (core -> plugin; unused until the core calls the plugin).
	 *
	 * Checks the header formats, the shop id, the timestamp (±TIMESTAMP_TOLERANCE_SECONDS) and the signature in
	 * constant time. Replays are NOT detected here: the endpoint must also claim the nonce (e.g. in a transient kept
	 * 2 × the tolerance) after this returns true.
	 *
	 * @param array<string, string> $headers          Request headers; names are matched case-insensitively.
	 * @param string                $body             Raw body exactly as received.
	 * @param string                $expected_shop_id This store's public id.
	 * @param int|null              $now              Unix seconds; now when null.
	 */
	public function verify( array $headers, string $body, string $expected_shop_id, ?int $now = null ): bool {
		$headers   = array_change_key_case( $headers, CASE_LOWER );
		$shop_id   = $headers[ strtolower( self::HEADER_SHOP_ID ) ] ?? '';
		$timestamp = $headers[ strtolower( self::HEADER_TIMESTAMP ) ] ?? '';
		$nonce     = $headers[ strtolower( self::HEADER_NONCE ) ] ?? '';
		$signature = $headers[ strtolower( self::HEADER_SIGNATURE ) ] ?? '';

		if ( 1 !== preg_match( self::SHOP_ID_PATTERN, $shop_id )
			|| 1 !== preg_match( self::TIMESTAMP_PATTERN, $timestamp )
			|| 1 !== preg_match( self::NONCE_PATTERN, $nonce )
			|| 1 !== preg_match( self::SIGNATURE_PATTERN, $signature )
		) {
			return false;
		}

		if ( abs( ( $now ?? time() ) - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE_SECONDS ) {
			return false;
		}

		$expected_signature = $this->sign( $timestamp, $nonce, $body );

		// Both comparisons always run, so the time taken doesn't tell which one failed.
		$shop_matches      = hash_equals( $expected_shop_id, $shop_id );
		$signature_matches = hash_equals( $expected_signature, $signature );

		return $shop_matches && $signature_matches;
	}

	/**
	 * A new request nonce: 32 hex characters from a CSPRNG, like the core's.
	 */
	public static function new_nonce(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Keeps the secret out of var_dump() and print_r() output.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array( 'secret' => '***' );
	}
}
