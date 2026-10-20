<?php
/**
 * The "Menu" tab: the bots' main menu.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Appearance\TabInput;
use Nyxify\Features\Settings\DateFormat;

/**
 * The `menu` part (contract §4.3, `MenuSettings`): the bot's own buttons (shown or hidden, title, icon, color), up to
 * five of the shop's own (a link, or a text the bot sends) and the layout, written as one row per line with the
 * buttons' keys separated by spaces (decision 22). Anything left empty keeps the bot's default; without a layout the
 * bots show their default rows with the shop's own buttons a row each under them.
 */
final class MenuTab implements AppearanceTab {

	public const PART = 'menu';

	/**
	 * The bot's own buttons, in the order the form shows them.
	 */
	public const BUILT_IN = array( 'products', 'search', 'cart', 'orders', 'account', 'language', 'website', 'support' );

	/**
	 * The bots' rows without a layout of the shop's.
	 */
	public const DEFAULT_LAYOUT = array(
		array( 'products', 'search' ),
		array( 'cart', 'orders' ),
		array( 'account', 'language' ),
		array( 'website', 'support' ),
	);

	/**
	 * The most rows of the menu, and of buttons in a row (the core's limits).
	 */
	public const MAX_ROWS = 8;
	public const MAX_ROW  = 3;

	/**
	 * The most buttons of the shop's own (the core's limit).
	 */
	public const MAX_CUSTOM = 5;

	/**
	 * The most characters of a button's text (the core's limit).
	 */
	public const TEXT_LIMIT = 2000;

	/**
	 * The variables a button's text may use.
	 */
	public const VARIABLES = array( 'name', 'shop' );

	/**
	 * Error codes of the layout and of a button of the shop's.
	 */
	public const LAYOUT_UNKNOWN = 'layout_unknown';
	public const LAYOUT_TWICE   = 'layout_twice';
	public const LAYOUT_ROWS    = 'layout_rows';
	public const LAYOUT_ROW     = 'layout_row';
	public const LINK_OR_TEXT   = 'link_or_text';

	/**
	 * A key of the shop's own button (the core's pattern).
	 */
	private const KEY_PATTERN = '/^[a-z0-9_]{2,20}$/D';

	/**
	 * The bot's default icon of each button.
	 */
	private const ICONS = array(
		'products' => '🛍',
		'search'   => '🔍',
		'cart'     => '🛒',
		'orders'   => '📦',
		'account'  => '👤',
		'language' => '🌐',
		'website'  => '🌐',
		'support'  => '💬',
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
		return __( 'Menu', 'nyxify' );
	}

	/**
	 * The form's fields for the stored part: every built-in button, five slots for the shop's own (the stored ones
	 * first, each with its key; the empty ones with a free key) and the layout.
	 *
	 * @param mixed $stored The part as stored.
	 * @return array<string, string>
	 */
	public function values( mixed $stored ): array {
		$stored  = is_array( $stored ) ? $stored : array();
		$buttons = is_array( $stored['buttons'] ?? null ) ? $stored['buttons'] : array();
		$values  = array();

		foreach ( self::BUILT_IN as $key ) {
			$look                                     = is_array( $buttons[ $key ] ?? null ) ? $buttons[ $key ] : array();
			$values[ 'buttons.' . $key . '.enabled' ] = false === ( $look['enabled'] ?? null ) ? '' : '1';
			$values                                  += ButtonFields::values( $look, 'buttons.' . $key . '.', true );
		}

		$custom = array_values( array_filter( is_array( $stored['custom'] ?? null ) ? $stored['custom'] : array(), 'is_array' ) );
		$taken  = array();
		foreach ( $custom as $button ) {
			if ( is_string( $button['key'] ?? null ) ) {
				$taken[] = $button['key'];
			}
		}
		for ( $slot = 0; $slot < self::MAX_CUSTOM; $slot++ ) {
			$button  = $custom[ $slot ] ?? array();
			$prefix  = 'custom.' . $slot . '.';
			$key     = is_string( $button['key'] ?? null ) ? $button['key'] : self::free_key( $taken );
			$taken[] = $key;

			$values[ $prefix . 'key' ] = $key;
			$values                   += ButtonFields::values( $button, $prefix, true );
			$values[ $prefix . 'url' ] = is_string( $button['url'] ?? null ) ? $button['url'] : '';
			foreach ( Fields::LANGUAGES as $language ) {
				$text                                    = Fields::posted( $button, 'text.' . $language );
				$values[ $prefix . 'text.' . $language ] = is_string( $text ) ? $text : '';
			}
		}

		$values['layout'] = self::layout_text( $stored['layout'] ?? null );

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

		$buttons = array();
		foreach ( self::BUILT_IN as $key ) {
			$prefix = 'buttons.' . $key . '.';
			$look   = ButtonFields::read( $form, $prefix, true );
			if ( '1' !== $form->read( $prefix . 'enabled', Fields::line( ... ) ) ) {
				$form->keep( $prefix . 'enabled', '' );
				$look['enabled'] = false;
			}
			if ( array() !== $look ) {
				$buttons[ $key ] = $look;
			}
		}

		$custom = array();
		$keys   = array();
		for ( $slot = 0; $slot < self::MAX_CUSTOM; $slot++ ) {
			$button = self::read_custom( $form, $slot, $keys );
			if ( null !== $button ) {
				$custom[] = $button;
				$keys[]   = $button['key'];
			}
		}

		$layout = self::read_layout( $form, $keys );
		if ( null !== $layout ) {
			$part['layout'] = $layout;
		}
		if ( array() !== $buttons ) {
			$part['buttons'] = $buttons;
		}
		if ( array() !== $custom ) {
			$part['custom'] = $custom;
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
		$open  = static fn( string $prefix ): bool => self::any_under( $errors, $prefix )
			|| ( null !== $saved && self::differs_under( $values, $saved, $prefix ) );

		?>
		<p><?php esc_html_e( 'Your customers’ main menu: the bot’s own buttons, up to five of your own, and how they are laid out. Anything you leave empty keeps the bot’s default.', 'nyxify' ); ?></p>
		<?php Fields::form_error( $errors ); ?>

		<h3><?php esc_html_e( 'The bot’s buttons', 'nyxify' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Open a button to hide it or change its title, icon or color.', 'nyxify' ); ?></p>
		<?php foreach ( self::BUILT_IN as $key ) : ?>
			<?php
			$prefix  = 'buttons.' . $key . '.';
			$changed = self::changed_look( $values, $prefix );
			?>
			<details class="nyxify-menu-button"<?php echo $open( $prefix ) ? ' open' : ''; ?>>
				<summary>
					<span aria-hidden="true"><?php echo esc_html( self::ICONS[ $key ] ); ?></span>
					<?php echo esc_html( self::default_title( $key ) ); ?>
					<code><?php echo esc_html( $key ); ?></code>
					<?php if ( '1' !== ( $values[ $prefix . 'enabled' ] ?? '1' ) ) : ?>
						<span class="nyxify-badge"><?php esc_html_e( 'Hidden', 'nyxify' ); ?></span>
					<?php elseif ( $changed ) : ?>
						<span class="nyxify-badge"><?php esc_html_e( 'Changed', 'nyxify' ); ?></span>
					<?php endif; ?>
				</summary>
				<p class="description"><?php echo esc_html( self::about( $key ) ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'In the menu', 'nyxify' ); ?></th>
						<td>
							<?php
							$field(
								$prefix . 'enabled',
								array(
									'type'    => 'checkbox',
									'text'    => __( 'Show this button', 'nyxify' ),
									'choices' => array(
										'1' => __( 'Shown', 'nyxify' ),
										''  => __( 'Hidden', 'nyxify' ),
									),
								)
							);
							?>
						</td>
					</tr>
					<?php ButtonFields::render( $field, self::PART, $prefix, true, self::default_color( $key ) ); ?>
				</table>
			</details>
		<?php endforeach; ?>

		<h3><?php esc_html_e( 'Your own buttons', 'nyxify' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Up to five. Each one opens a link or shows a text, and needs a title in at least one language. A button with every field empty doesn’t exist.', 'nyxify' ); ?></p>
		<?php for ( $slot = 0; $slot < self::MAX_CUSTOM; $slot++ ) : ?>
			<?php
			$prefix = 'custom.' . $slot . '.';
			$key    = $values[ $prefix . 'key' ] ?? '';
			$title  = self::slot_title( $values, $prefix );
			?>
			<details class="nyxify-menu-button"<?php echo ( $open( $prefix ) || self::slot_used( $values, $prefix ) ) ? ' open' : ''; ?>>
				<summary>
					<?php
					/* translators: %s: the button's number, 1 to 5 */
					echo esc_html( sprintf( __( 'Your button %s', 'nyxify' ), DateFormat::number( $slot + 1 ) ) );
					?>
					<code><?php echo esc_html( $key ); ?></code>
					<?php if ( '' !== $title ) : ?>
						<span dir="auto"><?php echo esc_html( $title ); ?></span>
					<?php endif; ?>
				</summary>
				<input type="hidden" name="<?php echo esc_attr( Fields::name( self::PART, $prefix . 'key' ) ); ?>" value="<?php echo esc_attr( $key ); ?>" />
				<table class="form-table" role="presentation">
					<?php ButtonFields::render( $field, self::PART, $prefix, true, __( 'Default (blue)', 'nyxify' ) ); ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Fields::id( self::PART, $prefix . 'url' ) ); ?>"><?php esc_html_e( 'Link', 'nyxify' ); ?></label></th>
						<td>
							<?php
							$field(
								$prefix . 'url',
								array(
									'type'        => 'url',
									'dir'         => 'ltr',
									'maxlength'   => Fields::URL_LIMIT,
									'placeholder' => 'https://',
									'class'       => 'large-text',
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'The page the button opens, starting with https://. Leave it empty for a button that shows a text.', 'nyxify' ); ?></p>
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
											$prefix . 'text.' . $language,
											array(
												'type'  => 'textarea',
												'dir'   => 'fa' === $language ? 'rtl' : 'ltr',
												'rows'  => 4,
												'maxlength' => self::TEXT_LIMIT,
												'label' => 'fa' === $language ? __( 'Persian', 'nyxify' ) : __( 'English', 'nyxify' ),
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
										/* translators: 1: the most characters, 2: the formatting tags allowed */
										__( 'What the bot sends when the button is pressed, instead of a link. {name} is the customer’s first name and {shop} your shop’s name. Up to %1$s characters. Formatting: %2$s.', 'nyxify' ),
										DateFormat::number( self::TEXT_LIMIT ),
										Markup::TAGS
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</details>
		<?php endfor; ?>

		<h3><?php esc_html_e( 'Layout', 'nyxify' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="<?php echo esc_attr( Fields::id( self::PART, 'layout' ) ); ?>"><?php esc_html_e( 'Rows', 'nyxify' ); ?></label></th>
				<td>
					<?php
					$field(
						'layout',
						array(
							'type'        => 'textarea',
							'dir'         => 'ltr',
							'rows'        => self::MAX_ROWS,
							'placeholder' => self::layout_text( self::DEFAULT_LAYOUT ),
						)
					);
					?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: the most rows, 2: the most buttons in a row, 3: the built-in buttons' keys */
								__( 'One row per line, top first, with the buttons’ keys separated by spaces: up to %1$s rows of up to %2$s buttons. The bot’s buttons are %3$s; your own buttons’ keys are shown next to their names. A button left out isn’t shown. Empty: the default rows, with your own buttons a row each under them.', 'nyxify' ),
								DateFormat::number( self::MAX_ROWS ),
								DateFormat::number( self::MAX_ROW ),
								implode( ' ', self::BUILT_IN )
							)
						);
						?>
					</p>
					<?php $missing = self::left_out( $values ); ?>
					<?php if ( array() !== $missing ) : ?>
						<p class="nyxify-saved-value">
							<?php
							/* translators: %s: buttons' keys */
							echo esc_html( sprintf( __( 'Your buttons not in the layout, so not shown: %s', 'nyxify' ), implode( ' ', $missing ) ) );
							?>
						</p>
					<?php endif; ?>
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
		if ( 'layout' === $key ) {
			return match ( $code ) {
				self::LAYOUT_UNKNOWN => __( 'A key in the layout names no button. Use the keys listed below, and your own buttons’ keys only for buttons you filled in.', 'nyxify' ),
				self::LAYOUT_TWICE   => __( 'A button can be in the layout only once.', 'nyxify' ),
				/* translators: %s: the most rows */
				self::LAYOUT_ROWS    => sprintf( __( 'Up to %s rows.', 'nyxify' ), DateFormat::number( self::MAX_ROWS ) ),
				/* translators: %s: the most buttons in a row */
				self::LAYOUT_ROW     => sprintf( __( 'Up to %s buttons in a row.', 'nyxify' ), DateFormat::number( self::MAX_ROW ) ),
				default              => __( 'The Nyxify server did not accept this value.', 'nyxify' ),
			};
		}

		$prefix = (string) preg_replace( '/^((?:buttons\.[a-z]+|custom\.\d)\.).*$/D', '$1', $key );
		$look   = $prefix !== $key ? ButtonFields::error_message( $key, $code, $prefix ) : null;
		if ( null !== $look && ! ( Fields::MISSING === $code && str_ends_with( $key, '.title.fa' ) ) ) {
			return $look;
		}

		if ( Fields::REJECTED === $code ) {
			return str_contains( $key, '.text.' )
				/* translators: %s: the formatting tags allowed */
				? sprintf( __( 'The Nyxify server did not accept this text. Use only these tags, each one closed: %s.', 'nyxify' ), Markup::TAGS )
				: __( 'The Nyxify server did not accept this value.', 'nyxify' );
		}

		return match ( true ) {
			str_ends_with( $key, '.title.fa' ) => __( 'Give the button a title in at least one language.', 'nyxify' ),
			str_ends_with( $key, '.url' ) && self::LINK_OR_TEXT === $code => __( 'Give the button a link or a text: one of the two.', 'nyxify' ),
			str_ends_with( $key, '.url' ) => __( 'A link starting with https://, without spaces.', 'nyxify' ),
			Fields::VARIABLES === $code => __( 'Only {name} and {shop} can be used here; write {{ and }} for a brace.', 'nyxify' ),
			/* translators: %s: the most characters the field takes */
			default => sprintf( __( 'Up to %s characters.', 'nyxify' ), DateFormat::number( self::TEXT_LIMIT ) ),
		};
	}

	/**
	 * A slot of the shop's own buttons as the part takes it; null when the slot is empty.
	 *
	 * @param FormReader $form The form.
	 * @param int        $slot The slot, 0 to 4.
	 * @param string[]   $keys The keys of the buttons read before it.
	 * @return array<string, mixed>|null
	 *
	 * @phpstan-param list<string> $keys
	 */
	private static function read_custom( FormReader $form, int $slot, array $keys ): ?array {
		$prefix = 'custom.' . $slot . '.';
		$key    = $form->read( $prefix . 'key', Fields::line( ... ) );
		$look   = ButtonFields::read( $form, $prefix, true );
		$url    = $form->read( $prefix . 'url', Fields::markup( ... ) );
		$text   = $form->localized(
			$prefix . 'text',
			Fields::markup( ... ),
			static function ( string $text ): ?string {
				if ( mb_strlen( $text ) > self::TEXT_LIMIT ) {
					return Fields::TOO_LONG;
				}

				return Markup::variables_ok( $text, self::VARIABLES ) ? null : Fields::VARIABLES;
			}
		);

		if ( array() === $look && '' === $url && null === $text ) {
			return null;
		}

		if ( 1 !== preg_match( self::KEY_PATTERN, $key ) || in_array( $key, self::BUILT_IN, true ) || in_array( $key, $keys, true ) ) {
			$form->fail( '', Fields::INVALID );
		}
		if ( ! isset( $look['title'] ) ) {
			$form->fail( $prefix . 'title.fa', Fields::MISSING );
		}
		if ( ( '' === $url ) === ( null === $text ) ) {
			$form->fail( $prefix . 'url', self::LINK_OR_TEXT );
		} elseif ( '' !== $url && ! Fields::is_https_url( $url ) ) {
			$form->fail( $prefix . 'url', Fields::INVALID );
		}

		$button = array(
			'key'   => $key,
			'title' => $look['title'] ?? array(),
		) + $look;
		if ( '' !== $url ) {
			$button['url'] = $url;
		}
		if ( null !== $text ) {
			$button['text'] = $text;
		}

		return $button;
	}

	/**
	 * The layout as the part takes it (null for the bots' default), checked against the buttons there are.
	 *
	 * @param FormReader $form The form.
	 * @param string[]   $keys The keys of the shop's own buttons.
	 * @return list<list<string>>|null
	 *
	 * @phpstan-param list<string> $keys
	 */
	private static function read_layout( FormReader $form, array $keys ): ?array {
		$rows = array();
		foreach ( explode( "\n", strtolower( $form->read( 'layout', Fields::text( ... ) ) ) ) as $line ) {
			$row = preg_split( '/[\s,،]+/u', trim( $line ), -1, PREG_SPLIT_NO_EMPTY );
			if ( is_array( $row ) && array() !== $row ) {
				$rows[] = $row;
			}
		}
		$form->keep( 'layout', self::layout_text( $rows ) );
		if ( array() === $rows ) {
			return null;
		}

		$all   = array_merge( ...$rows );
		$known = array_merge( self::BUILT_IN, $keys );
		if ( array() !== array_diff( $all, $known ) ) {
			$form->fail( 'layout', self::LAYOUT_UNKNOWN );
		} elseif ( count( array_unique( $all ) ) !== count( $all ) ) {
			$form->fail( 'layout', self::LAYOUT_TWICE );
		} elseif ( count( $rows ) > self::MAX_ROWS ) {
			$form->fail( 'layout', self::LAYOUT_ROWS );
		} elseif ( max( array_map( 'count', $rows ) ) > self::MAX_ROW ) {
			$form->fail( 'layout', self::LAYOUT_ROW );
		}

		return $rows;
	}

	/**
	 * A layout as the form shows it: a line per row, keys separated by spaces.
	 *
	 * @param mixed $layout The layout as stored.
	 */
	private static function layout_text( mixed $layout ): string {
		$lines = array();
		foreach ( is_array( $layout ) ? $layout : array() as $row ) {
			if ( is_array( $row ) ) {
				$lines[] = implode( ' ', array_filter( $row, 'is_string' ) );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * The first key `btn1`…`btn6` not taken.
	 *
	 * @param string[] $taken Keys taken.
	 *
	 * @phpstan-param list<string> $taken
	 */
	private static function free_key( array $taken ): string {
		for ( $number = 1; ; $number++ ) {
			if ( ! in_array( 'btn' . $number, $taken, true ) ) {
				return 'btn' . $number;
			}
		}
	}

	/**
	 * The keys of the shop's filled-in buttons a layout of the shop's leaves out.
	 *
	 * @param array<string, string> $values The form's fields.
	 * @return string[]
	 */
	private static function left_out( array $values ): array {
		$layout = $values['layout'] ?? '';
		if ( '' === trim( $layout ) ) {
			return array();
		}

		$in      = preg_split( '/\s+/u', $layout, -1, PREG_SPLIT_NO_EMPTY );
		$missing = array();
		for ( $slot = 0; $slot < self::MAX_CUSTOM; $slot++ ) {
			$prefix = 'custom.' . $slot . '.';
			$key    = $values[ $prefix . 'key' ] ?? '';
			if ( '' !== $key && self::slot_used( $values, $prefix ) && ! in_array( $key, is_array( $in ) ? $in : array(), true ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Whether any field of a slot of the shop's own buttons is filled in (its key aside).
	 *
	 * @param array<string, string> $values The form's fields.
	 * @param string                $prefix The slot's prefix.
	 */
	private static function slot_used( array $values, string $prefix ): bool {
		foreach ( $values as $key => $value ) {
			if ( str_starts_with( $key, $prefix ) && $prefix . 'key' !== $key && '' !== $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a built-in button's title, icon or color is set.
	 *
	 * @param array<string, string> $values The form's fields.
	 * @param string                $prefix The button's prefix.
	 */
	private static function changed_look( array $values, string $prefix ): bool {
		foreach ( $values as $key => $value ) {
			if ( str_starts_with( $key, $prefix ) && $prefix . 'enabled' !== $key && '' !== $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A slot's title, in Persian or else English, to show in its summary.
	 *
	 * @param array<string, string> $values The form's fields.
	 * @param string                $prefix The slot's prefix.
	 */
	private static function slot_title( array $values, string $prefix ): string {
		$title = $values[ $prefix . 'title.fa' ] ?? '';

		return '' !== $title ? $title : ( $values[ $prefix . 'title.en' ] ?? '' );
	}

	/**
	 * Whether any key under a prefix is in a map.
	 *
	 * @param array<string, string> $map    Map by field key.
	 * @param string                $prefix The prefix.
	 */
	private static function any_under( array $map, string $prefix ): bool {
		foreach ( array_keys( $map ) as $key ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a field under a prefix differs from its saved value.
	 *
	 * @param array<string, string> $values The form's fields.
	 * @param array<string, string> $saved  The saved fields.
	 * @param string                $prefix The prefix.
	 */
	private static function differs_under( array $values, array $saved, string $prefix ): bool {
		foreach ( $values as $key => $value ) {
			if ( str_starts_with( $key, $prefix ) && ( $saved[ $key ] ?? '' ) !== $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A built-in button's default title, as the bots show it in this language.
	 *
	 * @param string $key The button's key.
	 */
	private static function default_title( string $key ): string {
		return match ( $key ) {
			'products' => __( 'Products', 'nyxify' ),
			'search'   => __( 'Search', 'nyxify' ),
			'cart'     => __( 'Cart', 'nyxify' ),
			'orders'   => __( 'My orders', 'nyxify' ),
			'account'  => __( 'My account', 'nyxify' ),
			'language' => __( 'Language', 'nyxify' ),
			'website'  => __( 'Shop website', 'nyxify' ),
			default    => __( 'Support', 'nyxify' ),
		};
	}

	/**
	 * What a built-in button does, and when the bots leave it out by themselves.
	 *
	 * @param string $key The button's key.
	 */
	private static function about( string $key ): string {
		return match ( $key ) {
			'products' => __( 'Opens your categories and products.', 'nyxify' ),
			'search'   => __( 'Searches your products by name.', 'nyxify' ),
			'cart'     => __( 'Opens the customer’s cart.', 'nyxify' ),
			'orders'   => __( 'Lists the customer’s orders.', 'nyxify' ),
			'account'  => __( 'The customer’s name, phone and addresses.', 'nyxify' ),
			'language' => __( 'Switches between Persian and English. Its default title is the other language’s name (English, فارسی).', 'nyxify' ),
			'website'  => __( 'Opens your site.', 'nyxify' ),
			default    => __( 'Opens your contact details; shown only once the Contact tab has some.', 'nyxify' ),
		};
	}

	/**
	 * The label of a built-in button's default color.
	 *
	 * @param string $key The button's key.
	 */
	private static function default_color( string $key ): string {
		return in_array( $key, array( 'language', 'website' ), true ) ? __( 'Default (no color)', 'nyxify' ) : __( 'Default (blue)', 'nyxify' );
	}
}
