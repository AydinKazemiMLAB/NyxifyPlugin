<?php
/**
 * The "Back button" tab.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Appearance\TabInput;

/**
 * The `back_button` part (contract §4.3, a `ButtonLook`): the button that goes back a screen looks the same on every
 * screen, the bot admins' included. Its title, icon and color each keep the bot's default when left empty.
 */
final class BackButtonTab implements AppearanceTab {

	public const PART = 'back_button';

	/**
	 * The part's name.
	 */
	public function part(): string {
		return self::PART;
	}

	/**
	 * The tab's title.
	 */
	public function label(): string {
		return __( 'Back button', 'nyxify' );
	}

	/**
	 * The form's fields for the stored part.
	 *
	 * @param mixed $stored The part as stored.
	 * @return array<string, string>
	 */
	public function values( mixed $stored ): array {
		return ButtonFields::values( $stored, '', true );
	}

	/**
	 * Reads and checks the posted form.
	 *
	 * @param array<array-key, mixed> $input The tab's fields.
	 */
	public function read( array $input ): TabInput {
		$form = new FormReader( $input );

		return $form->input( ButtonFields::read( $form, '', true ) );
	}

	/**
	 * Prints the fields.
	 *
	 * @param array<string, string>      $values The fields to show.
	 * @param array<string, string>      $errors Error codes by field key.
	 * @param array<string, string>|null $saved  The saved fields after a conflict.
	 */
	public function render( array $values, array $errors, ?array $saved ): void {
		$field = function ( string $key, array $options = array() ) use ( $values, $errors, $saved ): void {
			Fields::control(
				self::PART,
				$key,
				$values[ $key ] ?? '',
				isset( $errors[ $key ] ) ? ButtonFields::error_message( $key, $errors[ $key ], '' ) : null,
				null === $saved ? null : ( $saved[ $key ] ?? '' ),
				$options
			);
		};

		?>
		<p><?php esc_html_e( 'The button that goes back a screen. It looks the same on every screen, the bot admins’ screens included. By default it is blue, with ➡️ in Persian and ⬅️ in English.', 'nyxify' ); ?></p>
		<?php Fields::form_error( $errors ); ?>
		<table class="form-table" role="presentation">
			<?php ButtonFields::render( $field, self::PART, '', true, __( 'Default (blue)', 'nyxify' ) ); ?>
		</table>
		<?php
	}
}
