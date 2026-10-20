<?php
/**
 * The "Welcome" tab: what customers see when they start a bot.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Appearance\TabInput;
use Nyxify\Features\Settings\DateFormat;

/**
 * The `welcome` part (contract §4.3): the banner (an https image address; without one the bots show the site's logo)
 * and the text per language, in the bots' markup with `{name}` (the customer's first name) and `{shop}` (the shop's
 * name). The text goes under the banner, so it is held to a photo caption's room, as the bot's own editor does.
 */
final class WelcomeTab implements AppearanceTab {

	public const PART = 'welcome';

	/**
	 * The variables the text may use.
	 */
	public const VARIABLES = array( 'name', 'shop' );

	/**
	 * The most characters the bots show under the banner (a caption's 1,024, less room for direction marks).
	 */
	public const TEXT_LIMIT = 960;

	/**
	 * The most characters of the text as written, tags included (the core's limit).
	 */
	public const MARKUP_LIMIT = 2000;

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
		return __( 'Welcome', 'nyxify' );
	}

	/**
	 * The form's fields for the stored part.
	 *
	 * @param mixed $stored The part as stored.
	 * @return array<string, string>
	 */
	public function values( mixed $stored ): array {
		$stored = is_array( $stored ) ? $stored : array();
		$values = array( 'banner_url' => is_string( $stored['banner_url'] ?? null ) ? $stored['banner_url'] : '' );
		foreach ( Fields::LANGUAGES as $language ) {
			$text                          = Fields::posted( $stored, 'text.' . $language );
			$values[ 'text.' . $language ] = is_string( $text ) ? $text : '';
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

		$banner = $form->read( 'banner_url', Fields::markup( ... ) );
		if ( '' !== $banner ) {
			if ( ! Fields::is_https_url( $banner ) ) {
				$form->fail( 'banner_url', Fields::INVALID );
			}
			$part['banner_url'] = $banner;
		}

		$text = $form->localized( 'text', Fields::markup( ... ), self::text_problem( ... ) );
		if ( null !== $text ) {
			$part['text'] = $text;
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
		<p><?php esc_html_e( 'What customers see when they start one of your bots: the banner, then this text, with the main menu under it.', 'nyxify' ); ?></p>
		<?php Fields::form_error( $errors ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( Fields::id( self::PART, 'banner_url' ) ); ?>"><?php esc_html_e( 'Banner', 'nyxify' ); ?></label></th>
				<td>
					<?php
					$field(
						'banner_url',
						array(
							'type'        => 'url',
							'dir'         => 'ltr',
							'maxlength'   => Fields::URL_LIMIT,
							'placeholder' => 'https://',
							'class'       => 'large-text',
						)
					);
					?>
					<p class="description"><?php esc_html_e( 'The address of an image, starting with https://. For an image in your media library, open it and copy its file URL. Empty: your site’s logo.', 'nyxify' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Text', 'nyxify' ); ?></th>
				<td>
					<div class="nyxify-languages">
						<?php foreach ( Fields::LANGUAGES as $language ) : ?>
							<div>
								<?php
								$field(
									'text.' . $language,
									array(
										'type'      => 'textarea',
										'dir'       => 'fa' === $language ? 'rtl' : 'ltr',
										'rows'      => 6,
										'maxlength' => self::MARKUP_LIMIT,
										'label'     => 'fa' === $language ? __( 'Persian', 'nyxify' ) : __( 'English', 'nyxify' ),
									)
								);
								?>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: the most characters shown, 2: the formatting tags allowed */
								__( '{name} is the customer’s first name and {shop} your shop’s name. Up to %1$s characters, not counting tags. Formatting: %2$s. Empty: the bot’s own welcome; a customer using the other language sees the one you wrote.', 'nyxify' ),
								DateFormat::number( self::TEXT_LIMIT ),
								Markup::TAGS
							)
						);
						?>
					</p>
				</td>
			</tr>
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
			return 'banner_url' === $key
				? __( 'The Nyxify server did not accept this value.', 'nyxify' )
				/* translators: %s: the formatting tags allowed */
				: sprintf( __( 'The Nyxify server did not accept this text. Use only these tags, each one closed: %s.', 'nyxify' ), Markup::TAGS );
		}

		if ( 'banner_url' === $key ) {
			return __( 'An image address starting with https://, without spaces.', 'nyxify' );
		}

		if ( Fields::VARIABLES === $code ) {
			return __( 'Only {name} and {shop} can be used here; write {{ and }} for a brace.', 'nyxify' );
		}

		/* translators: %s: the most characters shown */
		return sprintf( __( 'Up to %s characters, not counting tags.', 'nyxify' ), DateFormat::number( self::TEXT_LIMIT ) );
	}

	/**
	 * Why a text can't be shown, or null when it can.
	 *
	 * @param string $text The text.
	 */
	private static function text_problem( string $text ): ?string {
		if ( mb_strlen( $text ) > self::MARKUP_LIMIT ) {
			return Fields::TOO_LONG;
		}
		if ( ! Markup::variables_ok( $text, self::VARIABLES ) ) {
			return Fields::VARIABLES;
		}

		return Markup::visible_length( $text ) > self::TEXT_LIMIT ? Fields::TOO_LONG : null;
	}
}
