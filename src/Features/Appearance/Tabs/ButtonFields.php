<?php
/**
 * A button's look in the bot appearance forms.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Settings\DateFormat;

/**
 * The fields of a button's look (the core's `ButtonLook`, `CategoryLook` without a title), shared by the tabs: the
 * title per language, the icon (an emoji and, for a Telegram premium emoji, its id) and the color. Anything left
 * empty keeps the bot's default. The fields' keys follow the part (`title.fa`, `icon.emoji`, `icon.custom_emoji_id`,
 * `style`) after a prefix naming the button inside it, so the core's `422` marks the right field.
 */
final class ButtonFields {

	/**
	 * The most characters of a title.
	 */
	public const TITLE_LIMIT = 40;

	/**
	 * The most characters of an icon's emoji.
	 */
	public const EMOJI_LIMIT = 16;

	/**
	 * Telegram's button colors.
	 */
	public const STYLES = array( 'primary', 'success', 'danger' );

	private const CUSTOM_EMOJI_ID = '/^\d{1,20}$/D';

	/**
	 * A premium emoji's id and its emoji, as pasted into the emoji's box (`5368324170671202286 🛒`).
	 */
	private const ID_AND_EMOJI = '/^(\d{1,20})\s+(\S+)$/uD';

	/**
	 * Symbols, skin tones, variation selectors, the keycap mark, joiners and tags, keycap digits: an emoji's
	 * characters, as the bot's editors take them.
	 */
	private const EMOJI_CHARACTERS = '/^[\p{So}\p{Sk}\p{Mn}\p{Me}\p{Cf}0-9#*]+$/uD';

	private const EMOJI_SYMBOL = '/[\p{So}\x{20E3}]/u';

	/**
	 * The form's fields for a stored look.
	 *
	 * @param mixed  $stored The look as stored.
	 * @param string $prefix The fields' key prefix ('' or ending in a dot).
	 * @param bool   $title  Whether the look has a title.
	 * @return array<string, string>
	 */
	public static function values( mixed $stored, string $prefix, bool $title ): array {
		$stored = is_array( $stored ) ? $stored : array();
		$keys   = array( 'icon.emoji', 'icon.custom_emoji_id', 'style' );
		if ( $title ) {
			$keys = array_merge( array( 'title.fa', 'title.en' ), $keys );
		}

		$values = array();
		foreach ( $keys as $key ) {
			$value                    = Fields::posted( $stored, $key );
			$values[ $prefix . $key ] = is_string( $value ) ? $value : '';
		}

		return $values;
	}

	/**
	 * Reads and checks a look's fields.
	 *
	 * @param FormReader $form   The form.
	 * @param string     $prefix The fields' key prefix ('' or ending in a dot).
	 * @param bool       $title  Whether the look has a title.
	 * @return array<string, mixed> The look; empty when everything keeps the bot's defaults.
	 */
	public static function read( FormReader $form, string $prefix, bool $title ): array {
		$look = array();

		if ( $title ) {
			$titles = $form->localized(
				$prefix . 'title',
				Fields::line( ... ),
				fn( string $text ): ?string => mb_strlen( $text ) > self::TITLE_LIMIT ? Fields::TOO_LONG : null
			);
			if ( null !== $titles ) {
				$look['title'] = $titles;
			}
		}

		$icon = self::read_icon( $form, $prefix );
		if ( null !== $icon ) {
			$look['icon'] = $icon;
		}

		$style = $form->read( $prefix . 'style', Fields::line( ... ) );
		if ( '' !== $style ) {
			if ( ! in_array( $style, self::STYLES, true ) ) {
				$form->fail( $prefix . 'style', Fields::INVALID );
			}
			$look['style'] = $style;
		}

		return $look;
	}

	/**
	 * Whether a text is one emoji (or a few: a flag, a family), as far as its characters tell: no letters or words.
	 *
	 * @param string $text The text.
	 */
	public static function is_emoji( string $text ): bool {
		$length = mb_strlen( $text );

		return $length >= 1 && $length <= self::EMOJI_LIMIT
			&& 1 === preg_match( self::EMOJI_CHARACTERS, $text )
			&& 1 === preg_match( self::EMOJI_SYMBOL, $text );
	}

	/**
	 * Prints a look's rows of a form table.
	 *
	 * @param callable $field         Prints a field of the tab by key and options.
	 * @param string   $part          The tab's part.
	 * @param string   $prefix        The fields' key prefix.
	 * @param bool     $title         Whether the look has a title.
	 * @param string   $default_color The label of the bot's default color.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $field
	 */
	public static function render( callable $field, string $part, string $prefix, bool $title, string $default_color ): void {
		if ( $title ) {
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Title', 'nyxify' ); ?></th>
				<td>
					<div class="nyxify-languages">
						<?php foreach ( Fields::LANGUAGES as $language ) : ?>
							<div>
								<?php
								$field(
									$prefix . 'title.' . $language,
									array(
										'dir'       => 'fa' === $language ? 'rtl' : 'ltr',
										'maxlength' => self::TITLE_LIMIT,
										'label'     => 'fa' === $language ? __( 'Persian', 'nyxify' ) : __( 'English', 'nyxify' ),
									)
								);
								?>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Up to 40 characters, without the icon. Empty: the bot’s own title in that language.', 'nyxify' ); ?></p>
				</td>
			</tr>
			<?php
		}//end if
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Icon', 'nyxify' ); ?></th>
			<td>
				<div class="nyxify-icon-fields">
					<div>
						<?php
						$field(
							$prefix . 'icon.emoji',
							array(
								'class'     => 'nyxify-emoji-input',
								'maxlength' => 64,
								'label'     => __( 'Emoji', 'nyxify' ),
							)
						);
						?>
					</div>
					<div>
						<?php
						$field(
							$prefix . 'icon.custom_emoji_id',
							array(
								'dir'       => 'ltr',
								'maxlength' => 20,
								'label'     => __( 'Premium emoji id (Telegram, optional)', 'nyxify' ),
							)
						);
						?>
					</div>
				</div>
				<p class="description"><?php esc_html_e( 'One emoji. For a Telegram premium emoji, add its id: Bale, Rubika and Telegram apps that can’t show it show the emoji. You can also paste “id emoji” into the emoji box. Empty: the bot’s own icon.', 'nyxify' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( Fields::id( $part, $prefix . 'style' ) ); ?>"><?php esc_html_e( 'Color', 'nyxify' ); ?></label></th>
			<td>
				<?php
				$field(
					$prefix . 'style',
					array(
						'type'    => 'select',
						'choices' => array( '' => $default_color ) + self::colors(),
					)
				);
				?>
				<p class="description"><?php esc_html_e( 'Telegram only: Bale and Rubika show every button plain.', 'nyxify' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * The message of a look field's error code, or null when the key isn't one of the look's fields.
	 *
	 * @param string $key    Field key.
	 * @param string $code   Error code.
	 * @param string $prefix The fields' key prefix.
	 */
	public static function error_message( string $key, string $code, string $prefix ): ?string {
		if ( ! str_starts_with( $key, $prefix ) ) {
			return null;
		}

		$field = substr( $key, strlen( $prefix ) );
		if ( ! in_array( $field, array( 'title.fa', 'title.en', 'icon.emoji', 'icon.custom_emoji_id', 'style' ), true ) ) {
			return null;
		}
		if ( Fields::REJECTED === $code ) {
			return __( 'The Nyxify server did not accept this value.', 'nyxify' );
		}

		switch ( $field ) {
			case 'icon.emoji':
				return Fields::MISSING === $code
					? __( 'Add the emoji too: Bale, Rubika and some Telegram apps show it instead of the premium emoji.', 'nyxify' )
					: __( 'One emoji, e.g. 🛒; or a premium emoji’s id and its emoji, e.g. 5368324170671202286 🛒.', 'nyxify' );
			case 'icon.custom_emoji_id':
				return __( 'A premium emoji’s id: up to 20 digits.', 'nyxify' );
			case 'style':
				return __( 'Choose a color from the list.', 'nyxify' );
		}

		/* translators: %s: the most characters the field takes */
		return sprintf( __( 'Up to %s characters.', 'nyxify' ), DateFormat::number( self::TITLE_LIMIT ) );
	}

	/**
	 * The colors and their names.
	 *
	 * @return array<string, string>
	 */
	public static function colors(): array {
		return array(
			'primary' => __( 'Blue', 'nyxify' ),
			'success' => __( 'Green', 'nyxify' ),
			'danger'  => __( 'Red', 'nyxify' ),
		);
	}

	/**
	 * Reads and checks the icon: an emoji with an optional premium emoji id; "id emoji" in the emoji's box is split.
	 *
	 * @param FormReader $form   The form.
	 * @param string     $prefix The fields' key prefix.
	 * @return array<string, string>|null
	 */
	private static function read_icon( FormReader $form, string $prefix ): ?array {
		$emoji_key = $prefix . 'icon.emoji';
		$id_key    = $prefix . 'icon.custom_emoji_id';

		$id    = Fields::latin_digits( $form->read( $id_key, Fields::line( ... ) ) );
		$emoji = Fields::latin_digits( $form->read( $emoji_key, Fields::line( ... ) ) );
		if ( 1 === preg_match( self::ID_AND_EMOJI, $emoji, $match ) && self::is_emoji( $match[2] ) ) {
			$emoji = $match[2];
			$id    = '' === $id ? $match[1] : $id;
		}
		$form->keep( $emoji_key, $emoji );
		$form->keep( $id_key, $id );

		if ( '' === $emoji && '' === $id ) {
			return null;
		}

		if ( '' === $emoji ) {
			$form->fail( $emoji_key, Fields::MISSING );
		} elseif ( ! self::is_emoji( $emoji ) ) {
			$form->fail( $emoji_key, Fields::INVALID );
		}

		$icon = array( 'emoji' => $emoji );
		if ( '' !== $id ) {
			if ( 1 !== preg_match( self::CUSTOM_EMOJI_ID, $id ) ) {
				$form->fail( $id_key, Fields::INVALID );
			}
			$icon['custom_emoji_id'] = $id;
		}

		return $icon;
	}
}
