<?php
/**
 * The shop's bot appearance settings as the core answers them.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance;

use Nyxify\Shared\Http\CoreClient;

/**
 * `AppearanceView` (contract §4.3): the settings document's version, when it last changed and its parts (`menu`,
 * `texts`, `welcome`, `back_button`, `category_style`, `bot_profile`, `contact`; null or missing keeps the bots'
 * defaults).
 */
final class AppearanceView {

	/**
	 * Version of the document: 0 until the first change; a change is sent with it.
	 *
	 * @var int
	 */
	public readonly int $version;

	/**
	 * When it last changed (ISO date and time), or null before the first change.
	 *
	 * @var string|null
	 */
	public readonly ?string $updated_at;

	/**
	 * The parts, by name.
	 *
	 * @var array<array-key, mixed>
	 */
	public readonly array $settings;

	/**
	 * Constructor.
	 *
	 * @param int                     $version    Version.
	 * @param string|null             $updated_at Last change.
	 * @param array<array-key, mixed> $settings   Parts.
	 */
	private function __construct( int $version, ?string $updated_at, array $settings ) {
		$this->version    = $version;
		$this->updated_at = $updated_at;
		$this->settings   = $settings;
	}

	/**
	 * The view of an answer, or null when the answer isn't one.
	 *
	 * @param array<array-key, mixed> $data Decoded answer.
	 */
	public static function from_response( array $data ): ?self {
		$version    = $data['version'] ?? null;
		$updated_at = $data['updated_at'] ?? null;
		$settings   = $data['settings'] ?? null;

		if ( ! is_int( $version ) || $version < 0
			|| ( null !== $updated_at && ! is_string( $updated_at ) )
			|| ! is_array( $settings ) || ( array() !== $settings && array_is_list( $settings ) )
		) {
			return null;
		}

		return new self( $version, $updated_at, $settings );
	}

	/**
	 * A part as stored, or null when the shop keeps the bots' defaults.
	 *
	 * @param string $part Part name, e.g. `contact`.
	 */
	public function part( string $part ): mixed {
		return $this->settings[ $part ] ?? null;
	}

	/**
	 * Hash of a part as stored: a form carries it to tell, on a conflict, whether its own part changed meanwhile.
	 *
	 * @param string $part Part name.
	 */
	public function hash( string $part ): string {
		return hash( 'sha256', (string) wp_json_encode( $this->part( $part ), CoreClient::JSON_FLAGS ) );
	}
}
