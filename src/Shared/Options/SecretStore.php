<?php
/**
 * Encrypted secrets in wp_options.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Shared\Options;

use WooNyx\Shared\Security\Encryptor;

/**
 * Reads and writes the plugin's secrets, encrypted, in options that are never autoloaded.
 *
 * A value that exists but can't be decrypted (the salts or WOONYX_ENCRYPTION_KEY changed, or the row was altered)
 * reads as missing; has_unreadable_connection() tells the settings page to ask for a new activation.
 */
final class SecretStore {

	/**
	 * Store id and shared secret, as one encrypted JSON object so a rotation replaces them together.
	 */
	public const OPTION_CONNECTION = 'woonyx_connection';

	/**
	 * Bot admin PIN (6 digits), encrypted.
	 */
	public const OPTION_ADMIN_PIN = 'woonyx_admin_pin';

	/**
	 * Encryptor of this site.
	 *
	 * @var Encryptor
	 */
	private Encryptor $encryptor;

	/**
	 * Constructor.
	 *
	 * @param Encryptor $encryptor Encryptor of this site.
	 */
	public function __construct( Encryptor $encryptor ) {
		$this->encryptor = $encryptor;
	}

	/**
	 * The stored connection, or null when there is none or it can't be read.
	 */
	public function get_connection(): ?Connection {
		$json = $this->read( self::OPTION_CONNECTION );
		if ( null === $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! is_string( $data['store_id'] ?? null ) || ! is_string( $data['shared_secret'] ?? null ) ) {
			return null;
		}

		try {
			return new Connection( $data['store_id'], $data['shared_secret'] );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Saves the connection (a new activation or a rotated secret).
	 *
	 * @param Connection $connection Store id and shared secret.
	 * @return bool Whether it was written; callers must not use a new secret that wasn't saved.
	 */
	public function save_connection( Connection $connection ): bool {
		return $this->write(
			self::OPTION_CONNECTION,
			(string) wp_json_encode(
				array(
					'store_id'      => $connection->store_id,
					'shared_secret' => $connection->shared_secret,
				)
			)
		);
	}

	/**
	 * Whether a connection is stored but can't be decrypted or parsed.
	 */
	public function has_unreadable_connection(): bool {
		return false !== get_option( self::OPTION_CONNECTION, false ) && null === $this->get_connection();
	}

	/**
	 * Forgets the connection.
	 */
	public function delete_connection(): void {
		delete_option( self::OPTION_CONNECTION );
	}

	/**
	 * The stored admin PIN, or null.
	 */
	public function get_admin_pin(): ?string {
		$pin = $this->read( self::OPTION_ADMIN_PIN );

		return null !== $pin && 1 === preg_match( '/^\d{6}$/D', $pin ) ? $pin : null;
	}

	/**
	 * Saves the admin PIN issued by the core.
	 *
	 * @param string $pin Six digits.
	 * @return bool Whether it was written.
	 * @throws \InvalidArgumentException When the PIN isn't six digits (the value is not echoed).
	 */
	public function save_admin_pin( string $pin ): bool {
		if ( 1 !== preg_match( '/^\d{6}$/D', $pin ) ) {
			throw new \InvalidArgumentException( 'Invalid admin PIN.' );
		}

		return $this->write( self::OPTION_ADMIN_PIN, $pin );
	}

	/**
	 * Forgets the admin PIN.
	 */
	public function delete_admin_pin(): void {
		delete_option( self::OPTION_ADMIN_PIN );
	}

	/**
	 * Decrypted value of an option, or null.
	 *
	 * @param string $option Option name.
	 */
	private function read( string $option ): ?string {
		$stored = get_option( $option, false );

		return is_string( $stored ) ? $this->encryptor->decrypt( $stored ) : null;
	}

	/**
	 * Encrypts and stores a value without autoloading it.
	 *
	 * @param string $option Option name.
	 * @param string $value  Plaintext.
	 */
	private function write( string $option, string $value ): bool {
		// A fresh nonce makes every ciphertext differ, so update_option() only returns false on a real failure.
		return update_option( $option, $this->encryptor->encrypt( $value ), false );
	}
}
