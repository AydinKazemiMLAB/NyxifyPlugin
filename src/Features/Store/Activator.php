<?php
/**
 * Store activation.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Store;

use WooNyx\Features\Sync\PullScheduler;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;

/**
 * Activates the store with a license key (contract §1.1): creates a WooCommerce REST key for the core, sends
 * `POST /store/activate` (unsigned) and saves the store id and shared secret before reporting success.
 *
 * - Nothing is created or sent when the site's addresses can't work (plain http in production, a REST API on another
 *   origin).
 * - Without a usable answer the new REST key is deleted and the previous key and connection are kept.
 * - With one, the core uses the new key from now on: it is remembered and the plugin's previous key deleted, even when
 *   the connection then can't be saved (the owner is told to activate again).
 * - The license key, the REST key and the secret are never logged; only codes, statuses and request ids.
 */
final class Activator {

	/**
	 * Operation name in the logs.
	 */
	public const OPERATION = 'store/activate';

	private const PATH = '/store/activate';

	/**
	 * The core's version format is `^[0-9][0-9A-Za-z.+-]{0,19}$`; longer development versions are cut to it.
	 */
	private const VERSION_PATTERN = '/^[0-9][0-9A-Za-z.+-]{0,19}/';

	/**
	 * Core client (only its unsigned call is used).
	 *
	 * @var CoreClient
	 */
	private CoreClient $client;

	/**
	 * Where the connection is saved.
	 *
	 * @var SecretStore
	 */
	private SecretStore $store;

	/**
	 * The plugin's REST keys.
	 *
	 * @var RestKeys
	 */
	private RestKeys $rest_keys;

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Starts the pulls once the store is connected.
	 *
	 * @var callable(): void
	 */
	private $start_pulls;

	/**
	 * Constructor.
	 *
	 * @param CoreClient    $client      Core client.
	 * @param SecretStore   $store       Where the connection is saved.
	 * @param RestKeys      $rest_keys   The plugin's REST keys.
	 * @param Logger        $logger      Safe logger.
	 * @param callable|null $start_pulls Starts the pulls; start_pulls() by default.
	 *
	 * @phpstan-param (callable(): void)|null $start_pulls
	 */
	public function __construct( CoreClient $client, SecretStore $store, RestKeys $rest_keys, Logger $logger, ?callable $start_pulls = null ) {
		$this->client      = $client;
		$this->store       = $store;
		$this->rest_keys   = $rest_keys;
		$this->logger      = $logger;
		$this->start_pulls = $start_pulls ?? array( self::class, 'start_pulls' );
	}

	/**
	 * Ends any pause left from before and schedules the recurring pull (contract §5.2).
	 */
	public static function start_pulls(): void {
		PullScheduler::clear_retry_at();
		( new PullScheduler() )->ensure( true );
	}

	/**
	 * The activator of this site.
	 */
	public static function for_site(): self {
		$store = new SecretStore( Encryptor::for_site() );

		return new self( new CoreClient( $store, CoreClient::configured_base_url() ), $store, new RestKeys(), new Logger() );
	}

	/**
	 * Activates the store.
	 *
	 * @param LicenseKey $license The owner's license key (sent once, never kept).
	 * @param int        $user_id The admin who activates; owner of the REST key.
	 * @return CoreError|null Null when the store is activated and the connection saved.
	 */
	public function activate( LicenseKey $license, int $user_id ): ?CoreError {
		$site_url = site_url();
		$rest_url = rest_url();

		$url_error = self::check_urls( $site_url, $rest_url );
		if ( null !== $url_error ) {
			return $this->fail( new CoreError( $url_error ) );
		}

		$key = $this->rest_keys->create( $user_id );
		if ( null === $key ) {
			return $this->fail( new CoreError( CoreError::REST_KEY_FAILED ) );
		}

		$response = $this->client->post_unsigned(
			self::PATH,
			array(
				'license_key'     => $license->value(),
				'site_url'        => $site_url,
				'rest_url'        => $rest_url,
				'consumer_key'    => $key->consumer_key,
				'consumer_secret' => $key->consumer_secret,
				'wp_version'      => self::version( (string) get_bloginfo( 'version' ) ),
				'woo_version'     => self::version( self::woocommerce_version() ),
			)
		);

		$connection = $response->is_success() ? self::connection( $response->data ) : null;
		if ( null === $connection ) {
			// No usable answer. If the core did activate (e.g. the answer was lost), the old secret no longer works
			// either, and activating again fixes both; keeping the new key would only leave an unused one behind.
			$this->delete_key( $key->key_id );

			return $this->fail(
				$response->error ?? new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id )
			);
		}

		// The core uses the new key from now on, whether or not the connection can be saved below.
		$this->replace_key( $key->key_id );
		StatusService::clear();

		if ( ! $this->store->save_connection( $connection ) ) {
			return $this->fail( new CoreError( CoreError::CONNECTION_NOT_SAVED, $response->status, $response->request_id ) );
		}

		$this->logger->info(
			'Store activated.',
			array(
				'store_id'   => $connection->store_id,
				'request_id' => $response->request_id,
			)
		);
		( $this->start_pulls )();

		return null;
	}

	/**
	 * Why the site's addresses can't be used, or null when they can.
	 *
	 * @param string $site_url `site_url()`.
	 * @param string $rest_url `rest_url()`.
	 * @return string|null A CoreError code, or null.
	 */
	private static function check_urls( string $site_url, string $rest_url ): ?string {
		$site = self::origin( $site_url );
		if ( null === $site || ( 'https' !== $site['scheme'] && ! CoreClient::allows_plain_http() ) ) {
			return CoreError::SITE_NOT_HTTPS;
		}

		return self::origin( $rest_url ) === $site ? null : CoreError::REST_URL_MISMATCH;
	}

	/**
	 * Scheme, host and port of an http(s) URL, with the default port filled in.
	 *
	 * @param string $url URL.
	 * @return array{scheme: string, host: string, port: int}|null Null when it is not an http(s) URL with a host.
	 */
	private static function origin( string $url ): ?array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || '' === $parts['host'] ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );
		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return null;
		}

		return array(
			'scheme' => $scheme,
			'host'   => strtolower( $parts['host'] ),
			'port'   => $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ),
		);
	}

	/**
	 * The connection in the core's answer, or null when it is missing or malformed.
	 *
	 * @param array<array-key, mixed> $data Decoded answer.
	 */
	private static function connection( array $data ): ?Connection {
		$store_id      = $data['store_id'] ?? null;
		$shared_secret = $data['shared_secret'] ?? null;
		if ( ! is_string( $store_id ) || ! is_string( $shared_secret ) ) {
			return null;
		}

		try {
			return new Connection( $store_id, $shared_secret );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * WooCommerce's version.
	 */
	private static function woocommerce_version(): string {
		if ( defined( 'WC_VERSION' ) ) {
			return (string) constant( 'WC_VERSION' );
		}

		return function_exists( 'WC' ) ? (string) WC()->version : '';
	}

	/**
	 * A version in the core's format; anything that doesn't start like a version is sent as is (the core rejects it
	 * with a 422 naming the field).
	 *
	 * @param string $version Version.
	 */
	private static function version( string $version ): string {
		return 1 === preg_match( self::VERSION_PATTERN, $version, $matches ) ? $matches[0] : $version;
	}

	/**
	 * Remembers the new key and deletes the plugin's previous one, which the core no longer uses.
	 *
	 * @param int $key_id The new key.
	 */
	private function replace_key( int $key_id ): void {
		$previous = $this->rest_keys->current_id();

		if ( ! $this->rest_keys->remember( $key_id ) ) {
			$this->logger->warning( 'The id of the new REST key could not be saved.', array( 'key_id' => $key_id ) );
		}

		if ( null !== $previous && $previous !== $key_id ) {
			$this->delete_key( $previous );
		}
	}

	/**
	 * Deletes a key the plugin created; a failure is only logged.
	 *
	 * @param int $key_id Row id.
	 */
	private function delete_key( int $key_id ): void {
		if ( ! $this->rest_keys->delete( $key_id ) ) {
			$this->logger->warning( 'A WooNyx REST key could not be deleted.', array( 'key_id' => $key_id ) );
		}
	}

	/**
	 * Logs a failure and returns it.
	 *
	 * @param CoreError $error The failure.
	 */
	private function fail( CoreError $error ): CoreError {
		$this->logger->core_error( self::OPERATION, $error );

		return $error;
	}
}
