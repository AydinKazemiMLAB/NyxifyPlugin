<?php
/**
 * The "Bot description" tab.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Appearance\TabInput;
use Nyxify\Features\Settings\DateFormat;

/**
 * The `bot_profile` part (contract §4.3): the bots' description ("What can this bot do?", shown in an empty chat) and
 * short description (on the bot's profile and in links to it) per language, in plain text. Only Telegram has them
 * (Bale's and Rubika's bot APIs don't); a bot sets them after the next message it gets.
 */
final class BotProfileTab implements AppearanceTab {

	public const PART = 'bot_profile';

	/**
	 * The texts per language and their limits, in characters (Telegram's).
	 */
	public const TEXTS = array(
		'description'       => 512,
		'short_description' => 120,
	);

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
		return __( 'Bot description', 'nyxify' );
	}

	/**
	 * The form's fields for the stored part.
	 *
	 * @param mixed $stored The part as stored.
	 * @return array<string, string>
	 */
	public function values( mixed $stored ): array {
		$stored = is_array( $stored ) ? $stored : array();
		$values = array();
		foreach ( array_keys( self::TEXTS ) as $key ) {
			foreach ( Fields::LANGUAGES as $language ) {
				$text                             = Fields::posted( $stored, $key . '.' . $language );
				$values[ $key . '.' . $language ] = is_string( $text ) ? $text : '';
			}
		}

		return $values;
	}

	/**
	 * Reads and checks the posted form.
	 *
	 * @param array<array-key, mixed> $input The tab's fields.
	 */
	public function read( array $input ): TabInput {
		$form = new FormReader( $input );
		$part = array();

		foreach ( self::TEXTS as $key => $limit ) {
			$texts = $form->localized(
				$key,
				Fields::text( ... ),
				fn( string $text ): ?string => mb_strlen( $text ) > $limit ? Fields::TOO_LONG : null
			);
			if ( null !== $texts ) {
				$part[ $key ] = $texts;
			}
		}

		return $form->input( $part );
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
				isset( $errors[ $key ] ) ? self::error_message( $key, $errors[ $key ] ) : null,
				null === $saved ? null : ( $saved[ $key ] ?? '' ),
				$options
			);
		};

		?>
		<p><?php esc_html_e( 'How your Telegram bots introduce themselves, in plain text (Bale and Rubika have no bot descriptions). A bot sets them after the next message it gets.', 'nyxify' ); ?></p>
		<?php Fields::form_error( $errors ); ?>
		<table class="form-table" role="presentation">
			<?php foreach ( self::texts() as $key => $text ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $text[0] ); ?></th>
					<td>
						<div class="nyxify-languages">
							<?php foreach ( Fields::LANGUAGES as $language ) : ?>
								<div>
									<?php
									$field(
										$key . '.' . $language,
										array(
											'type'      => 'textarea',
											'dir'       => 'fa' === $language ? 'rtl' : 'ltr',
											'rows'      => 'description' === $key ? 5 : 2,
											'maxlength' => self::TEXTS[ $key ],
											'label'     => 'fa' === $language ? __( 'Persian', 'nyxify' ) : __( 'English', 'nyxify' ),
										)
									);
									?>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php echo esc_html( $text[1] ); ?></p>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * The message of a field's error code.
	 *
	 * @param string $key  Field key.
	 * @param string $code Error code.
	 */
	public static function error_message( string $key, string $code ): string {
		$text = strstr( $key, '.', true );
		if ( Fields::TOO_LONG === $code && false !== $text && isset( self::TEXTS[ $text ] ) ) {
			/* translators: %s: the most characters the field takes */
			return sprintf( __( 'Up to %s characters.', 'nyxify' ), DateFormat::number( self::TEXTS[ $text ] ) );
		}

		return __( 'The Nyxify server did not accept this value.', 'nyxify' );
	}

	/**
	 * The texts' labels and descriptions.
	 *
	 * @return array<string, array{string, string}>
	 */
	private static function texts(): array {
		return array(
			'description'       => array(
				__( 'Description', 'nyxify' ),
				__( 'Shown in an empty chat with the bot, before the customer starts it (“What can this bot do?”). Up to 512 characters. Empty: the bot’s own, with your shop’s name.', 'nyxify' ),
			),
			'short_description' => array(
				__( 'Short description', 'nyxify' ),
				__( 'On the bot’s profile and in links to it. Up to 120 characters. Empty: the bot’s own, with your shop’s name.', 'nyxify' ),
			),
		);
	}
}
