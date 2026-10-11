<?php
/**
 * Encryption of the secrets the plugin keeps in wp_options.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Shared\Security;

/**
 * Authenticated symmetric encryption with libsodium's secretbox (XSalsa20-Poly1305).
 *
 * Values are stored as `v1:` + base64(nonce . ciphertext). The key comes from the `NYXIFY_ENCRYPTION_KEY` constant
 * when it is defined, otherwise from the site's salts; either is stretched with HKDF-SHA256. Changing the salts (or the
 * constant) makes stored values unreadable: decrypt() then returns null and the owner activates the plugin again.
 */
final class Encryptor {

	/**
	 * Prefix of the current storage format.
	 */
	private const VERSION_PREFIX = 'v1:';

	/**
	 * HKDF "info": separates this key from any other use of the same secret.
	 */
	private const HKDF_INFO = 'nyxify-options-v1';

	/**
	 * Raw 32-byte secretbox key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $key Raw key of SODIUM_CRYPTO_SECRETBOX_KEYBYTES bytes.
	 * @throws \InvalidArgumentException When the key has the wrong length.
	 */
	public function __construct( string $key ) {
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'The encryption key must be 32 bytes long.' );
		}

		$this->key = $key;
	}

	/**
	 * The encryptor of this site: `NYXIFY_ENCRYPTION_KEY` when defined and not empty, otherwise the site's salts.
	 */
	public static function for_site(): self {
		$secret = defined( 'NYXIFY_ENCRYPTION_KEY' ) ? (string) constant( 'NYXIFY_ENCRYPTION_KEY' ) : '';

		if ( '' === $secret ) {
			$secret = wp_salt( 'auth' );
		}

		return self::from_secret( $secret );
	}

	/**
	 * Derives the key from a secret of any length with HKDF-SHA256.
	 *
	 * @param string $secret Input key material (a constant's value or the site's salts).
	 * @throws \InvalidArgumentException When the secret is empty.
	 */
	public static function from_secret( string $secret ): self {
		if ( '' === $secret ) {
			throw new \InvalidArgumentException( 'The encryption secret must not be empty.' );
		}

		return new self( hash_hkdf( 'sha256', $secret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::HKDF_INFO ) );
	}

	/**
	 * Encrypts a value; every call uses a fresh random nonce.
	 *
	 * @param string $plaintext Value to protect.
	 */
	public function encrypt( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::VERSION_PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $this->key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text, not obfuscation.
	}

	/**
	 * Decrypts a value written by encrypt().
	 *
	 * @param string $payload Stored value.
	 * @return string|null The plaintext, or null when the value is malformed, was altered or was encrypted with
	 *                     another key.
	 */
	public function decrypt( string $payload ): ?string {
		if ( ! str_starts_with( $payload, self::VERSION_PREFIX ) ) {
			return null;
		}

		$raw = base64_decode( substr( $payload, strlen( self::VERSION_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}

		$plaintext = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key
		);

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Keeps the key out of var_dump() and print_r() output.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array( 'key' => '***' );
	}
}
