<?php
/**
 * Reading and printing the bot appearance forms' fields.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

/**
 * Helpers the tabs share: a field's key is a dotted path inside its part (`phone`, `address.fa`), its name is
 * `nyxify_<part>[address][fa]` and its id `nyxify-<part>-address-fa`.
 */
final class Fields {

	/**
	 * Error code: longer than the field's limit.
	 */
	public const TOO_LONG = 'too_long';

	/**
	 * Error code: not in the field's format.
	 */
	public const INVALID = 'invalid';

	/**
	 * Error code: refused by the core (`422 validation_error`).
	 */
	public const REJECTED = 'rejected';

	/**
	 * Error code: names a variable the text can't use, or has a stray brace.
	 */
	public const VARIABLES = 'variables';

	/**
	 * Error code: needed by another field that is set.
	 */
	public const MISSING = 'missing';

	/**
	 * The most characters of an address (the core's limit).
	 */
	public const URL_LIMIT = 1000;

	/**
	 * The languages of the bots' texts, in the order the forms show them.
	 */
	public const LANGUAGES = array( 'fa', 'en' );

	/**
	 * Name of the request field holding a tab's fields.
	 *
	 * @param string $part The tab's part.
	 */
	public static function input_name( string $part ): string {
		return 'nyxify_' . $part;
	}

	/**
	 * A field's value in the posted fields, by its dotted key; null when missing.
	 *
	 * @param array<array-key, mixed> $input Posted fields.
	 * @param string                  $key   Dotted key.
	 */
	public static function posted( array $input, string $key ): mixed {
		$value = $input;
		foreach ( explode( '.', $key ) as $step ) {
			if ( ! is_array( $value ) || ! array_key_exists( $step, $value ) ) {
				return null;
			}
			$value = $value[ $step ];
		}

		return $value;
	}

	/**
	 * A one-line field: tags, line breaks and extra spaces removed; '' when it isn't a string.
	 *
	 * @param mixed $value Posted value.
	 */
	public static function line( mixed $value ): string {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/**
	 * A field of several lines: tags removed, `\n` line breaks, trimmed; '' when it isn't a string.
	 *
	 * @param mixed $value Posted value.
	 */
	public static function text( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( $value ) ) );
	}

	/**
	 * A text the bots read as markup (or an address): kept as typed, tags and all, with `
` line breaks and trimmed;
	 * '' when it isn't a string or isn't valid UTF-8. Never printed unescaped.
	 *
	 * @param mixed $value Posted value.
	 */
	public static function markup( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		return trim( str_replace( array( "\r\n", "\r" ), "\n", wp_check_invalid_utf8( $value ) ) );
	}

	/**
	 * Whether an address is one the core takes as a link or an image: https, without spaces, quotes or angle brackets,
	 * at most 1,000 characters.
	 *
	 * @param string $url The address.
	 */
	public static function is_https_url( string $url ): bool {
		return mb_strlen( $url ) <= self::URL_LIMIT && 1 === preg_match( '#^https://[^\s<>"\']+$#uD', $url );
	}

	/**
	 * Persian and Arabic-Indic digits as Latin ones.
	 *
	 * @param string $text Text.
	 */
	public static function latin_digits( string $text ): string {
		return strtr(
			$text,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
	}

	/**
	 * The id of a field's element.
	 *
	 * @param string $part The tab's part.
	 * @param string $key  Dotted key.
	 */
	public static function id( string $part, string $key ): string {
		return str_replace( array( '.', '_' ), '-', 'nyxify-' . $part . '-' . $key );
	}

	/**
	 * The name of a field's element.
	 *
	 * @param string $part The tab's part.
	 * @param string $key  Dotted key.
	 */
	public static function name( string $part, string $key ): string {
		return self::input_name( $part ) . '[' . str_replace( '.', '][', $key ) . ']';
	}

	/**
	 * Prints a field's input (or text area), then its error and, after a conflict, its saved value when it differs.
	 *
	 * @param string               $part    The tab's part.
	 * @param string               $key     Dotted key.
	 * @param string               $value   The value to show.
	 * @param string|null          $error   The error message, or null.
	 * @param string|null          $saved   The saved value after a conflict, or null.
	 * @param array<string, mixed> $options `type` (text, email, tel, url, textarea, select or checkbox), `choices` (a
	 *                                      select's values and labels; a checkbox's, `1` and '', to show a saved
	 *                                      value), `text` (a checkbox's label), `class` (an input's, `regular-text` by
	 *                                      default), `dir`, `maxlength`, `rows`, `prefix` (shown before the input, e.g.
	 *                                      `t.me/`), `describedby` (a description's id), `placeholder`, `label` (a
	 *                                      visible label above the input), `aria-label` (a label read out only, for
	 *                                      a control in a table).
	 */
	public static function control( string $part, string $key, string $value, ?string $error, ?string $saved, array $options = array() ): void {
		$id   = self::id( $part, $key );
		$type = is_string( $options['type'] ?? null ) ? $options['type'] : 'text';

		$described = array();
		if ( is_string( $options['describedby'] ?? null ) ) {
			$described[] = $options['describedby'];
		}
		if ( null !== $error ) {
			$described[] = $id . '-error';
		}

		$attributes = array(
			'id'   => $id,
			'name' => self::name( $part, $key ),
			'dir'  => is_string( $options['dir'] ?? null ) ? $options['dir'] : 'auto',
		);
		if ( is_int( $options['maxlength'] ?? null ) ) {
			$attributes['maxlength'] = (string) $options['maxlength'];
		}
		if ( is_string( $options['placeholder'] ?? null ) ) {
			$attributes['placeholder'] = $options['placeholder'];
		}
		if ( is_string( $options['aria-label'] ?? null ) ) {
			$attributes['aria-label'] = $options['aria-label'];
		}
		if ( array() !== $described ) {
			$attributes['aria-describedby'] = implode( ' ', $described );
		}
		if ( null !== $error ) {
			$attributes['aria-invalid'] = 'true';
		}

		if ( is_string( $options['label'] ?? null ) ) {
			printf( '<label for="%s" class="nyxify-control-label">%s</label>', esc_attr( $id ), esc_html( $options['label'] ) );
		}

		$choices = self::choices( $options['choices'] ?? null );
		if ( 'textarea' === $type ) {
			echo '<textarea class="large-text"';
			self::print_attributes( $attributes + array( 'rows' => (string) ( is_int( $options['rows'] ?? null ) ? $options['rows'] : 3 ) ) );
			printf( '>%s</textarea>', esc_textarea( $value ) );
		} elseif ( 'select' === $type ) {
			unset( $attributes['maxlength'], $attributes['placeholder'] );
			self::print_select( $attributes, $value, $choices );
		} elseif ( 'checkbox' === $type ) {
			unset( $attributes['maxlength'], $attributes['placeholder'], $attributes['dir'] );
			echo '<label class="nyxify-checkbox"><input type="checkbox" value="1"';
			self::print_attributes( $attributes );
			printf( '%s /> %s</label>', checked( '1', $value, false ), esc_html( is_string( $options['text'] ?? null ) ? $options['text'] : '' ) );
		} else {
			$attributes['class'] = is_string( $options['class'] ?? null ) ? $options['class'] : 'regular-text';
			self::print_input( $attributes + array( 'type' => $type ), $value, is_string( $options['prefix'] ?? null ) ? $options['prefix'] : null );
		}

		if ( null !== $error ) {
			printf( '<p class="nyxify-field-error" id="%s">%s</p>', esc_attr( $id . '-error' ), esc_html( $error ) );
		}

		if ( null !== $saved && $saved !== $value ) {
			printf(
				'<p class="nyxify-saved-value"><strong>%s</strong> <span dir="auto">%s</span></p>',
				esc_html__( 'Saved now:', 'nyxify' ),
				isset( $choices[ $saved ] ) ? esc_html( $choices[ $saved ] ) : ( '' === $saved ? esc_html__( '(empty)', 'nyxify' ) : nl2br( esc_html( $saved ) ) )
			);
		}
	}

	/**
	 * Prints the error of the form as a whole (key ''), when there is one: what the core refused without naming one
	 * of the tab's fields.
	 *
	 * @param array<string, string> $errors Error codes by field key.
	 */
	public static function form_error( array $errors ): void {
		if ( ! isset( $errors[''] ) ) {
			return;
		}

		printf(
			'<div class="nyxify-callout nyxify-callout--error"><p>%s</p></div>',
			esc_html__( 'The Nyxify server did not accept these details. Check them and save again.', 'nyxify' )
		);
	}

	/**
	 * A select's values and labels.
	 *
	 * @param mixed $choices The `choices` option.
	 * @return array<string, string>
	 */
	private static function choices( mixed $choices ): array {
		$valid = array();
		foreach ( is_array( $choices ) ? $choices : array() as $value => $label ) {
			if ( is_string( $label ) ) {
				$valid[ (string) $value ] = $label;
			}
		}

		return $valid;
	}

	/**
	 * Prints a select.
	 *
	 * @param array<string, string> $attributes Attributes.
	 * @param string                $value      The chosen value.
	 * @param array<string, string> $choices    Values and labels.
	 */
	private static function print_select( array $attributes, string $value, array $choices ): void {
		echo '<select';
		self::print_attributes( $attributes );
		echo '>';
		foreach ( $choices as $choice => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $choice ), selected( $value, (string) $choice, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Prints a one-line input, after its prefix when it has one.
	 *
	 * @param array<string, string> $attributes Attributes.
	 * @param string                $value      The value to show.
	 * @param string|null           $prefix     Shown before the input, or null.
	 */
	private static function print_input( array $attributes, string $value, ?string $prefix ): void {
		if ( null !== $prefix ) {
			printf( '<span class="nyxify-prefixed" dir="ltr"><span class="nyxify-prefix" aria-hidden="true">%s</span>', esc_html( $prefix ) );
		}

		echo '<input autocomplete="off" spellcheck="false"';
		self::print_attributes( $attributes + array( 'value' => $value ) );
		echo ' />';

		if ( null !== $prefix ) {
			echo '</span>';
		}
	}

	/**
	 * Prints ` name="value"` per attribute.
	 *
	 * @param array<string, string> $attributes Attributes.
	 */
	private static function print_attributes( array $attributes ): void {
		foreach ( $attributes as $name => $value ) {
			printf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}
	}
}
