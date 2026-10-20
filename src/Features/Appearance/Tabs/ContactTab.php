<?php
/**
 * The "Contact" tab: how customers reach the shop.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Appearance\TabInput;
use Nyxify\Features\Settings\DateFormat;

/**
 * The `contact` part (contract §4.3), set from WordPress only: the bots show it on their "Support" screen, and the bot
 * admins can't change it. Every field is optional; with all of them empty the part is removed and the bots show no
 * "Support" button.
 *
 * What the owner types is normalized before the checks, which follow the core's: Persian digits become Latin ones,
 * a pasted link (`https://t.me/my_shop`) or a leading `@` leaves the id, and the WhatsApp number loses its `+`, `00`,
 * spaces, dashes and brackets.
 */
final class ContactTab implements AppearanceTab {

	public const PART = 'contact';

	/**
	 * The texts per language and their limits, in characters.
	 */
	public const TEXTS = array(
		'introduction' => 500,
		'address'      => 300,
		'hours'        => 200,
	);

	/**
	 * The one-line fields, in the order the form shows them.
	 */
	public const LINES = array( 'phone', 'email', 'telegram', 'bale', 'rubika', 'whatsapp', 'instagram' );

	private const PHONE_PATTERN     = '/^\+?[0-9][0-9 -]{3,23}[0-9]$/D';
	private const TELEGRAM_PATTERN  = '/^[A-Za-z][A-Za-z0-9_]{4,31}$/D';
	private const MESSENGER_PATTERN = '/^[A-Za-z][A-Za-z0-9_.]{2,31}$/D';
	private const INSTAGRAM_PATTERN = '/^[A-Za-z0-9_.]{1,30}$/D';
	private const WHATSAPP_PATTERN  = '/^[1-9][0-9]{7,14}$/D';
	private const EMAIL_LIMIT       = 254;

	/**
	 * The link each messenger's id is shown in, also stripped from a pasted link.
	 */
	private const HOSTS = array(
		'telegram'  => 't.me',
		'bale'      => 'ble.ir',
		'rubika'    => 'rubika.ir',
		'whatsapp'  => 'wa.me',
		'instagram' => 'instagram.com',
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
		return __( 'Contact', 'nyxify' );
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

		foreach ( self::LINES as $key ) {
			$values[ $key ] = is_string( $stored[ $key ] ?? null ) ? $stored[ $key ] : '';
		}
		foreach ( array_keys( self::TEXTS ) as $key ) {
			foreach ( Fields::LANGUAGES as $language ) {
				$text                             = Fields::posted( $stored, $key . '.' . $language );
				$values[ $key . '.' . $language ] = is_string( $text ) ? $text : '';
			}
		}

		return $values;
	}

	/**
	 * Reads, normalizes and checks the posted form.
	 *
	 * @param array<array-key, mixed> $input The tab's fields.
	 */
	public function read( array $input ): TabInput {
		$values = array(
			'phone'     => self::phone( Fields::line( Fields::posted( $input, 'phone' ) ) ),
			'email'     => Fields::line( Fields::posted( $input, 'email' ) ),
			'telegram'  => self::username( Fields::line( Fields::posted( $input, 'telegram' ) ), self::HOSTS['telegram'] ),
			'bale'      => self::username( Fields::line( Fields::posted( $input, 'bale' ) ), self::HOSTS['bale'] ),
			'rubika'    => self::username( Fields::line( Fields::posted( $input, 'rubika' ) ), self::HOSTS['rubika'] ),
			'whatsapp'  => self::whatsapp( Fields::line( Fields::posted( $input, 'whatsapp' ) ) ),
			'instagram' => self::username( Fields::line( Fields::posted( $input, 'instagram' ) ), self::HOSTS['instagram'] ),
		);

		$errors = array();
		$checks = array(
			'phone'     => fn( string $value ): bool => 1 === preg_match( self::PHONE_PATTERN, $value ),
			'email'     => fn( string $value ): bool => mb_strlen( $value ) <= self::EMAIL_LIMIT && false !== is_email( $value ),
			'telegram'  => fn( string $value ): bool => 1 === preg_match( self::TELEGRAM_PATTERN, $value ),
			'bale'      => fn( string $value ): bool => 1 === preg_match( self::MESSENGER_PATTERN, $value ),
			'rubika'    => fn( string $value ): bool => 1 === preg_match( self::MESSENGER_PATTERN, $value ),
			'whatsapp'  => fn( string $value ): bool => 1 === preg_match( self::WHATSAPP_PATTERN, $value ),
			'instagram' => fn( string $value ): bool => 1 === preg_match( self::INSTAGRAM_PATTERN, $value ),
		);
		$part   = array();
		foreach ( $checks as $key => $valid ) {
			if ( '' === $values[ $key ] ) {
				continue;
			}
			if ( ! $valid( $values[ $key ] ) ) {
				$errors[ $key ] = Fields::INVALID;
			}
			$part[ $key ] = $values[ $key ];
		}

		foreach ( self::TEXTS as $key => $limit ) {
			$texts = array();
			foreach ( Fields::LANGUAGES as $language ) {
				$field            = $key . '.' . $language;
				$values[ $field ] = Fields::text( Fields::posted( $input, $field ) );
				if ( '' === $values[ $field ] ) {
					continue;
				}
				if ( mb_strlen( $values[ $field ] ) > $limit ) {
					$errors[ $field ] = Fields::TOO_LONG;
				}
				$texts[ $language ] = $values[ $field ];
			}
			if ( array() !== $texts ) {
				$part[ $key ] = $texts;
			}
		}

		return new TabInput( $values, array() === $part ? null : $part, $errors );
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
		<p><?php esc_html_e( 'How customers reach you. Your bots show these details on their “Support” screen, with a button for each messenger; with every field empty they show no “Support” button. Only this page sets them: bot admins can’t change them from the bot.', 'nyxify' ); ?></p>
		<p class="nyxify-privacy"><?php esc_html_e( 'Everyone who opens your bots sees these details.', 'nyxify' ); ?></p>
		<?php Fields::form_error( $errors ); ?>

		<h3><?php esc_html_e( 'Phone and email', 'nyxify' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( Fields::id( self::PART, 'phone' ) ); ?>"><?php esc_html_e( 'Phone', 'nyxify' ); ?></label></th>
				<td>
					<?php
					$field(
						'phone',
						array(
							'type'        => 'tel',
							'dir'         => 'ltr',
							'maxlength'   => 40,
							'placeholder' => '021 1234 5678',
						)
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( Fields::id( self::PART, 'email' ) ); ?>"><?php esc_html_e( 'Email', 'nyxify' ); ?></label></th>
				<td>
					<?php
					$field(
						'email',
						array(
							'type'      => 'email',
							'dir'       => 'ltr',
							'maxlength' => self::EMAIL_LIMIT,
						)
					);
					?>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Messengers', 'nyxify' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Your id on each messenger, without @; you can also paste the link to your page. The bots show their own messenger first.', 'nyxify' ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( self::messengers() as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( Fields::id( self::PART, $key ) ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<?php
						$field(
							$key,
							array(
								'dir'         => 'ltr',
								'maxlength'   => 100,
								'prefix'      => self::HOSTS[ $key ] . '/',
								'placeholder' => 'whatsapp' === $key ? '989121234567' : null,
							)
						);
						?>
						<?php if ( 'whatsapp' === $key ) : ?>
							<p class="description"><?php esc_html_e( 'The number with the country code, e.g. 989121234567 for 0912 123 4567.', 'nyxify' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h3><?php esc_html_e( 'Introduction, address and working hours', 'nyxify' ); ?></h3>
		<p class="description"><?php esc_html_e( 'In Persian, English or both: a customer using the other language sees the one you wrote.', 'nyxify' ); ?></p>
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
											'rows'      => 'introduction' === $key ? 4 : 2,
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
		if ( Fields::REJECTED === $code ) {
			return __( 'The Nyxify server did not accept this value.', 'nyxify' );
		}

		$text = strstr( $key, '.', true );
		if ( Fields::TOO_LONG === $code && false !== $text && isset( self::TEXTS[ $text ] ) ) {
			/* translators: %s: the most characters the field takes */
			return sprintf( __( 'Up to %s characters.', 'nyxify' ), DateFormat::number( self::TEXTS[ $text ] ) );
		}

		$messages = array(
			'phone'     => __( 'A phone number of 5 to 25 characters: digits, with a leading + , spaces and dashes if you like.', 'nyxify' ),
			'email'     => __( 'This is not an email address.', 'nyxify' ),
			'telegram'  => __( 'A Telegram username: 5 to 32 English letters, digits and _, starting with a letter.', 'nyxify' ),
			'bale'      => __( 'An id of 3 to 32 English letters, digits, _ and dots, starting with a letter.', 'nyxify' ),
			'rubika'    => __( 'An id of 3 to 32 English letters, digits, _ and dots, starting with a letter.', 'nyxify' ),
			'whatsapp'  => __( 'The number with the country code, 8 to 15 digits, e.g. 989121234567.', 'nyxify' ),
			'instagram' => __( 'An Instagram username: up to 30 English letters, digits, _ and dots.', 'nyxify' ),
		);

		return $messages[ $key ] ?? __( 'This value is not valid.', 'nyxify' );
	}

	/**
	 * The messengers' fields and labels.
	 *
	 * @return array<string, string>
	 */
	private static function messengers(): array {
		return array(
			'telegram'  => __( 'Telegram', 'nyxify' ),
			'bale'      => __( 'Bale', 'nyxify' ),
			'rubika'    => __( 'Rubika', 'nyxify' ),
			'whatsapp'  => __( 'WhatsApp', 'nyxify' ),
			'instagram' => __( 'Instagram', 'nyxify' ),
		);
	}

	/**
	 * The texts' labels and descriptions.
	 *
	 * @return array<string, array{string, string}>
	 */
	private static function texts(): array {
		return array(
			'introduction' => array( __( 'Introduction', 'nyxify' ), __( 'A few lines at the top of the screen, e.g. when you answer. Up to 500 characters.', 'nyxify' ) ),
			'address'      => array( __( 'Address', 'nyxify' ), __( 'Up to 300 characters.', 'nyxify' ) ),
			'hours'        => array( __( 'Working hours', 'nyxify' ), __( 'Up to 200 characters.', 'nyxify' ) ),
		);
	}

	/**
	 * A phone number with Latin digits and single spaces.
	 *
	 * @param string $value One line.
	 */
	private static function phone( string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', Fields::latin_digits( $value ) ) );
	}

	/**
	 * An id without a leading `@` or the messenger's link around it.
	 *
	 * @param string $value One line.
	 * @param string $host  The messenger's link host, e.g. `t.me`.
	 */
	private static function username( string $value, string $host ): string {
		$value = Fields::latin_digits( $value );
		$link  = '#^(?:https?://)?(?:www\.)?' . preg_quote( $host, '#' ) . '/([^/?\#\s]+)/?(?:[?\#].*)?$#iD';
		if ( 1 === preg_match( $link, $value, $match ) ) {
			$value = $match[1];
		}

		return ltrim( $value, '@' );
	}

	/**
	 * A WhatsApp number as digits only, without `+` or `00` before the country code.
	 *
	 * @param string $value One line.
	 */
	private static function whatsapp( string $value ): string {
		$value = self::username( $value, self::HOSTS['whatsapp'] );
		$value = (string) preg_replace( '/[\s()+-]+/u', '', $value );

		return str_starts_with( $value, '00' ) ? substr( $value, 2 ) : $value;
	}
}
