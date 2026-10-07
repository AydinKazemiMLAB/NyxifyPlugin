<?php
/**
 * HTTP client of the WooNyx core.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Shared\Http;

use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Shared\Security\HmacSigner;

/**
 * Sends the plugin's requests to the core (`/api/v1/...`) with `wp_remote_request()`.
 *
 * - Signed calls (everything but the activation) need a stored connection; without one nothing is sent.
 * - The JSON body is encoded once, and exactly those bytes are signed and sent.
 * - Redirects are never followed, https is required outside `local`/`development` environments, and every request
 *   carries its own X-Request-ID to match the core's logs.
 *
 * `wp_safe_remote_request()` isn't used: it only allows ports 80, 443 and 8080, and the test core listens on 88. The
 * address comes from the code or from wp-config.php, never from a request.
 */
final class CoreClient {

	/**
	 * The core used when WOONYX_API_BASE_URL isn't defined (the test server until a production URL exists).
	 */
	public const DEFAULT_BASE_URL = 'https://api.woonyx.nyxon.tech:88';

	public const API_PREFIX = '/api/v1';

	public const HEADER_REQUEST_ID = 'X-Request-ID';

	/**
	 * Timeout of an ordinary call, in seconds.
	 */
	public const TIMEOUT_SECONDS = 20;

	/**
	 * Timeout of calls during which the core itself calls another service (activation reads the shop's REST API,
	 * adding a bot calls the messenger), in seconds; the core waits up to 15 s for each.
	 */
	public const LONG_TIMEOUT_SECONDS = 45;

	private const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._-]{8,128}$/D';
	private const PATH_PATTERN       = '#^(?:/[A-Za-z0-9._~-]+)+$#D';
	private const JSON_FLAGS         = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Where the connection (store id and shared secret) is kept.
	 *
	 * @var SecretStore
	 */
	private SecretStore $store;

	/**
	 * Core address without the API prefix, e.g. `https://api.example.com`.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param SecretStore $store    Where the connection is kept.
	 * @param string      $base_url Core address without the API prefix.
	 */
	public function __construct( SecretStore $store, string $base_url ) {
		$this->store    = $store;
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * The client of this site: its secret store and the configured core address.
	 */
	public static function for_site(): self {
		return new self( new SecretStore( Encryptor::for_site() ), self::configured_base_url() );
	}

	/**
	 * WOONYX_API_BASE_URL when defined and not empty, otherwise DEFAULT_BASE_URL.
	 */
	public static function configured_base_url(): string {
		$url = defined( 'WOONYX_API_BASE_URL' ) ? (string) constant( 'WOONYX_API_BASE_URL' ) : '';

		return '' !== $url ? $url : self::DEFAULT_BASE_URL;
	}

	/**
	 * The core address this client talks to (not a secret; the settings page shows it).
	 */
	public function base_url(): string {
		return $this->base_url;
	}

	/**
	 * A signed GET.
	 *
	 * @param string                    $path    Path after `/api/v1`, e.g. `/store/status`.
	 * @param array<string, int|string> $query   Query parameters.
	 * @param int                       $timeout Seconds.
	 */
	public function get( string $path, array $query = array(), int $timeout = self::TIMEOUT_SECONDS ): CoreResponse {
		return $this->send( 'GET', $path, $query, null, true, $timeout );
	}

	/**
	 * A signed POST.
	 *
	 * @param string                    $path    Path after `/api/v1`.
	 * @param array<string, mixed>|null $payload JSON object fields, or null for an empty body.
	 * @param int                       $timeout Seconds.
	 */
	public function post( string $path, ?array $payload = null, int $timeout = self::TIMEOUT_SECONDS ): CoreResponse {
		return $this->send( 'POST', $path, array(), $payload, true, $timeout );
	}

	/**
	 * A signed DELETE (empty body).
	 *
	 * @param string $path    Path after `/api/v1`.
	 * @param int    $timeout Seconds.
	 */
	public function delete( string $path, int $timeout = self::TIMEOUT_SECONDS ): CoreResponse {
		return $this->send( 'DELETE', $path, array(), null, true, $timeout );
	}

	/**
	 * An unsigned POST: only the activation, sent after the owner entered a license key.
	 *
	 * @param string               $path    Path after `/api/v1`.
	 * @param array<string, mixed> $payload JSON object fields.
	 * @param int                  $timeout Seconds.
	 */
	public function post_unsigned( string $path, array $payload, int $timeout = self::LONG_TIMEOUT_SECONDS ): CoreResponse {
		return $this->send( 'POST', $path, array(), $payload, false, $timeout );
	}

	/**
	 * A new request id: `wn-` + 24 random hex characters.
	 */
	public static function new_request_id(): string {
		return 'wn-' . bin2hex( random_bytes( 12 ) );
	}

	/**
	 * Builds, signs, sends and reads one request.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path after `/api/v1`.
	 * @param array<string, int|string> $query   Query parameters.
	 * @param array<string, mixed>|null $payload JSON object fields, or null for an empty body.
	 * @param bool                      $signed  Whether to sign with the stored connection.
	 * @param int                       $timeout Seconds.
	 * @throws \InvalidArgumentException When the path isn't a plain API path (a bug in the caller).
	 */
	private function send( string $method, string $path, array $query, ?array $payload, bool $signed, int $timeout ): CoreResponse {
		if ( 1 !== preg_match( self::PATH_PATTERN, $path ) ) {
			throw new \InvalidArgumentException( 'Invalid core API path.' );
		}

		$request_id = self::new_request_id();

		$connection = null;
		if ( $signed ) {
			$connection = $this->store->get_connection();
			if ( null === $connection ) {
				$code = $this->store->has_unreadable_connection() ? CoreError::CONNECTION_UNREADABLE : CoreError::NOT_ACTIVATED;

				return CoreResponse::failure( new CoreError( $code ) );
			}
		}

		$url = $this->endpoint_url( $path, $query );
		if ( null === $url ) {
			return CoreResponse::failure( new CoreError( CoreError::INVALID_BASE_URL ) );
		}

		$body = self::encode( $payload );
		if ( null === $body ) {
			return CoreResponse::failure( new CoreError( CoreError::INVALID_PAYLOAD ) );
		}

		$headers = array(
			'Accept'                => 'application/json',
			self::HEADER_REQUEST_ID => $request_id,
		);
		if ( '' !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( null !== $connection ) {
			$headers += ( new HmacSigner( $connection->shared_secret ) )->build_headers( $connection->store_id, $body );
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => $timeout,
			'redirection' => 0,
			'httpversion' => '1.1',
			'user-agent'  => 'WooNyx/' . WOONYX_VERSION,
		);
		if ( '' !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return CoreResponse::failure( new CoreError( CoreError::NETWORK_ERROR, 0, $request_id ) );
		}

		return self::read_response(
			(int) wp_remote_retrieve_response_code( $response ),
			wp_remote_retrieve_body( $response ),
			self::response_request_id( wp_remote_retrieve_header( $response, self::HEADER_REQUEST_ID ), $request_id )
		);
	}

	/**
	 * The full URL of a path, or null when the core address can't be used.
	 *
	 * @param string                    $path  Path after `/api/v1`.
	 * @param array<string, int|string> $query Query parameters.
	 */
	private function endpoint_url( string $path, array $query ): ?string {
		$parts = wp_parse_url( $this->base_url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] )
		) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && self::allows_plain_http() ) ) {
			return null;
		}

		$url = $this->base_url . self::API_PREFIX . $path;

		return array() === $query ? $url : $url . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Plain http only on a developer's machine (WP_ENVIRONMENT_TYPE `local` or `development`).
	 */
	private static function allows_plain_http(): bool {
		return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
	}

	/**
	 * The body to sign and send: '' for none, `{}` for an empty object, null when it can't be encoded.
	 *
	 * @param array<string, mixed>|null $payload JSON object fields.
	 */
	private static function encode( ?array $payload ): ?string {
		if ( null === $payload ) {
			return '';
		}

		if ( array() === $payload ) {
			return '{}';
		}

		$json = wp_json_encode( $payload, self::JSON_FLAGS );

		return is_string( $json ) ? $json : null;
	}

	/**
	 * The core's request id when well-formed, otherwise the one that was sent.
	 *
	 * @param string|array<string> $header   X-Request-ID of the response.
	 * @param string               $fallback The request id that was sent.
	 */
	private static function response_request_id( $header, string $fallback ): string {
		return is_string( $header ) && 1 === preg_match( self::REQUEST_ID_PATTERN, $header ) ? $header : $fallback;
	}

	/**
	 * Turns a status and a body into a CoreResponse; the body is never kept on failure.
	 *
	 * @param int    $status     HTTP status.
	 * @param string $body       Raw body.
	 * @param string $request_id Request id.
	 */
	private static function read_response( int $status, string $body, string $request_id ): CoreResponse {
		if ( $status >= 200 && $status < 300 ) {
			if ( 204 === $status && '' === trim( $body ) ) {
				return CoreResponse::success( $status, array(), $request_id );
			}

			$data = json_decode( $body, true );

			return is_array( $data )
				? CoreResponse::success( $status, $data, $request_id )
				: CoreResponse::failure( new CoreError( CoreError::INVALID_RESPONSE, $status, $request_id ) );
		}

		if ( $status >= 300 && $status < 400 ) {
			return CoreResponse::failure( new CoreError( CoreError::UNEXPECTED_REDIRECT, $status, $request_id ) );
		}

		$data = json_decode( $body, true );
		$code = is_array( $data ) && is_string( $data['error_code'] ?? null ) ? $data['error_code'] : CoreError::UNKNOWN_ERROR;

		return CoreResponse::failure( new CoreError( $code, $status, $request_id, self::invalid_fields( $data ) ) );
	}

	/**
	 * Field names of a validation error's `details` (their messages may echo input, so they are dropped).
	 *
	 * @param mixed $data Decoded error body.
	 * @return list<string>
	 */
	private static function invalid_fields( $data ): array {
		if ( ! is_array( $data ) || ! is_array( $data['details'] ?? null ) ) {
			return array();
		}

		$fields = array();
		foreach ( $data['details'] as $detail ) {
			if ( is_array( $detail ) && is_string( $detail['field'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_.\[\]-]{1,128}$/D', $detail['field'] ) ) {
				$fields[] = $detail['field'];
			}
		}

		return $fields;
	}
}
