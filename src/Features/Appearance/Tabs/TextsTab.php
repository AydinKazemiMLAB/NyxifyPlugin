<?php
/**
 * The "Texts" tab: the shop's own wording of the bots' texts.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Appearance\TabInput;
use Nyxify\Features\Appearance\TextCatalog;
use Nyxify\Features\Settings\DateFormat;

/**
 * The `texts` part (contract §4.3, a `LocalizedMarkup` per text key): the bot's texts the shop rewrote, per language,
 * in the bots' markup. The keys, their variables and limits come from the bot's catalog (`TextCatalog`); the form
 * shows them by part of the bot with the default beside each box. A box left empty keeps the bot's default in that
 * language. Since a save replaces the whole part, the texts of keys the catalog doesn't know (a newer bot's, or one
 * the bot dropped) ride along in a hidden field and are sent back as they were.
 */
final class TextsTab implements AppearanceTab {

	public const PART = 'texts';

	/**
	 * Error codes of a text and of the form as a whole.
	 */
	public const MARKUP_TOO_LONG = 'markup_too_long';
	public const UNAVAILABLE     = 'catalog_unavailable';
	public const TRUNCATED       = 'form_truncated';

	/**
	 * The hidden fields: the texts of keys the catalog doesn't know (JSON), and the one printed last, missing when the
	 * server dropped the form's end (PHP's `max_input_vars`). A text key starts with a letter, so neither is one.
	 */
	public const KEPT     = '_kept';
	public const COMPLETE = '_complete';

	/**
	 * The tags of a default shown formatted (the bots' subset; links aren't followed from here).
	 */
	private const SHOWN_TAGS = array(
		'b'          => array(),
		'i'          => array(),
		'u'          => array(),
		's'          => array(),
		'code'       => array(),
		'blockquote' => array(),
	);

	/**
	 * The most characters of a text's default shown as its heading.
	 */
	private const HEADING_LIMIT = 90;

	/**
	 * Reads the catalog.
	 *
	 * @var \Closure(): (TextCatalog|null)
	 */
	private \Closure $load;

	/**
	 * The catalog, once read.
	 *
	 * @var TextCatalog|null
	 */
	private ?TextCatalog $catalog = null;

	/**
	 * Whether the catalog was read.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Builds the tab.
	 *
	 * @param \Closure|null $catalog Reads the catalog (null when it can't be read); the one the plugin ships by default.
	 *
	 * @phpstan-param (\Closure(): (TextCatalog|null))|null $catalog
	 */
	public function __construct( ?\Closure $catalog = null ) {
		$this->load = $catalog ?? static fn(): ?TextCatalog => TextCatalog::load();
	}

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
		return __( 'Texts', 'nyxify' );
	}

	/**
	 * The form's fields for the stored part: each known text per language, and the unknown ones as JSON.
	 *
	 * @param mixed $stored The part as stored.
	 * @return array<string, string>
	 */
	public function values( mixed $stored ): array {
		$catalog = $this->catalog();
		if ( null === $catalog ) {
			return array();
		}

		$stored = is_array( $stored ) ? $stored : array();
		$values = array();
		foreach ( $catalog->texts as $key => $text ) {
			foreach ( Fields::LANGUAGES as $language ) {
				$value                            = is_array( $stored[ $key ] ?? null ) ? ( $stored[ $key ][ $language ] ?? null ) : null;
				$values[ $key . '.' . $language ] = is_string( $value ) ? $value : '';
			}
		}

		$kept                 = self::unknown( $stored, $catalog );
		$values[ self::KEPT ] = array() === $kept ? '' : (string) wp_json_encode( $kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return $values;
	}

	/**
	 * Reads and checks the posted form.
	 *
	 * @param array<array-key, mixed> $input The tab's fields.
	 */
	public function read( array $input ): TabInput {
		$form    = new FormReader( $input );
		$catalog = $this->catalog();
		if ( null === $catalog ) {
			$form->fail( '', self::UNAVAILABLE );
			return $form->input( array() );
		}
		if ( '1' !== Fields::posted( $input, self::COMPLETE ) ) {
			$form->fail( '', self::TRUNCATED );
		}

		$part = array();
		foreach ( $catalog->texts as $key => $text ) {
			$texts = $form->localized(
				$key,
				Fields::markup( ... ),
				static function ( string $value ) use ( $text, $catalog ): ?string {
					if ( mb_strlen( $value ) > $catalog->markup_limit ) {
						return self::MARKUP_TOO_LONG;
					}
					if ( ! Markup::variables_ok( $value, $text['variables'] ) ) {
						return Fields::VARIABLES;
					}

					return Markup::visible_length( $value ) > $text['limit'] ? Fields::TOO_LONG : null;
				}
			);
			if ( null !== $texts ) {
				$part[ $key ] = $texts;
			}
		}

		$kept = Fields::posted( $input, self::KEPT );
		$kept = is_string( $kept ) ? self::unknown( json_decode( $kept, true ), $catalog ) : array();
		$form->keep( self::KEPT, array() === $kept ? '' : (string) wp_json_encode( $kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

		return $form->input( $part + $kept );
	}

	/**
	 * Prints the fields: a box per part of the bot, a text per row with its default.
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
				isset( $errors[ $key ] ) ? $this->error_message( $key, $errors[ $key ] ) : null,
				null === $saved ? null : ( $saved[ $key ] ?? '' ),
				$options
			);
		};

		?>
		<p><?php esc_html_e( 'The texts your customers read in the bots, by part of the bot. Write your own wording in either language; a box left empty keeps the bot’s default in that language.', 'nyxify' ); ?></p>
		<?php
		$catalog = $this->catalog();
		self::form_error( null === $catalog ? array( '' => self::UNAVAILABLE ) : $errors, $this );
		if ( null === $catalog ) {
			return;
		}
		?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: the formatting tags allowed */
					__( 'Formatting: %s. A text can use only the variables listed with it, in braces; write {{ and }} for a brace. Lengths don’t count the tags.', 'nyxify' ),
					Markup::TAGS
				)
			);
			?>
		</p>
		<?php
		$language = self::language();
		foreach ( $catalog->sections as $section ) :
			$texts   = $catalog->in_section( $section['key'] );
			$changed = count( array_filter( $texts, static fn( array $text ): bool => self::filled( $values, $text['key'] ) ) );
			$open    = array_filter(
				$texts,
				static fn( array $text ): bool => self::any_error( $errors, $text['key'] ) || ( null !== $saved && self::differs( $values, $saved, $text['key'] ) )
			);
			?>
			<details class="nyxify-menu-button"<?php echo array() !== $open ? ' open' : ''; ?>>
				<summary>
					<?php echo esc_html( $section['title'][ $language ] ); ?>
					<span class="nyxify-badge">
						<?php
						echo esc_html(
							0 === $changed
								/* translators: %s: how many texts */
								? sprintf( _n( '%s text', '%s texts', count( $texts ), 'nyxify' ), DateFormat::number( count( $texts ) ) )
								/* translators: 1: how many texts were changed, 2: how many texts */
								: sprintf( _n( '%1$s of %2$s text changed', '%1$s of %2$s texts changed', count( $texts ), 'nyxify' ), DateFormat::number( $changed ), DateFormat::number( count( $texts ) ) )
						);
						?>
					</span>
				</summary>
				<?php foreach ( $texts as $text ) : ?>
					<div class="nyxify-text">
						<p class="nyxify-text__head">
							<strong dir="auto"><?php echo esc_html( self::heading( $text['default'][ $language ] ) ); ?></strong>
							<code><?php echo esc_html( $text['key'] ); ?></code>
						</p>
						<p class="description"><?php echo esc_html( self::about( $text ) ); ?></p>
						<div class="nyxify-languages">
							<?php foreach ( Fields::LANGUAGES as $text_language ) : ?>
								<div>
									<?php
									$field(
										$text['key'] . '.' . $text_language,
										array(
											'type'      => 'textarea',
											'dir'       => 'fa' === $text_language ? 'rtl' : 'ltr',
											'rows'      => $text['limit'] > 200 ? 4 : 2,
											'maxlength' => $catalog->markup_limit,
											'label'     => 'fa' === $text_language ? __( 'Persian', 'nyxify' ) : __( 'English', 'nyxify' ),
										)
									);
									?>
									<div class="description nyxify-default">
										<span class="nyxify-default__label"><?php esc_html_e( 'Default:', 'nyxify' ); ?></span>
										<div dir="<?php echo 'fa' === $text_language ? 'rtl' : 'ltr'; ?>"><?php echo wp_kses( $text['default'][ $text_language ], self::SHOWN_TAGS ); ?></div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</details>
		<?php endforeach; ?>
		<?php if ( '' !== ( $values[ self::KEPT ] ?? '' ) ) : ?>
			<p class="description">
				<?php
				$kept = json_decode( $values[ self::KEPT ], true );
				$kept = is_array( $kept ) ? count( $kept ) : 0;
				/* translators: %s: how many texts */
				echo esc_html( sprintf( _n( '%s text of the bot that this version of the plugin doesn’t list is kept as it is.', '%s texts of the bot that this version of the plugin doesn’t list are kept as they are.', $kept, 'nyxify' ), DateFormat::number( $kept ) ) );
				?>
			</p>
		<?php endif; ?>
		<input type="hidden" name="<?php echo esc_attr( Fields::name( self::PART, self::KEPT ) ); ?>" value="<?php echo esc_attr( $values[ self::KEPT ] ?? '' ); ?>" />
		<input type="hidden" name="<?php echo esc_attr( Fields::name( self::PART, self::COMPLETE ) ); ?>" value="1" />
		<?php
	}

	/**
	 * The message of a field's error code ('' for the form as a whole).
	 *
	 * @param string $key  Field key.
	 * @param string $code Error code.
	 */
	public function error_message( string $key, string $code ): string {
		if ( '' === $key ) {
			return match ( $code ) {
				self::UNAVAILABLE => __( 'The list of the bot’s texts couldn’t be read, so nothing was saved. Reinstall the plugin if this goes on.', 'nyxify' ),
				self::TRUNCATED   => __( 'Your server dropped the end of the form, so nothing was saved. Ask your host to raise PHP’s max_input_vars, then save again.', 'nyxify' ),
				default           => __( 'The Nyxify server did not accept these details. Check them and save again.', 'nyxify' ),
			};
		}

		$catalog = $this->catalog();
		$text    = $catalog?->texts[ (string) preg_replace( '/\.(fa|en)$/D', '', $key ) ] ?? null;
		if ( null === $catalog || null === $text || Fields::REJECTED === $code ) {
			/* translators: %s: the formatting tags allowed */
			return sprintf( __( 'The Nyxify server did not accept this text. Use only these tags, each one closed: %s.', 'nyxify' ), Markup::TAGS );
		}

		return match ( true ) {
			Fields::VARIABLES === $code && array() === $text['variables'] => __( 'This text takes no variables; write {{ and }} for a brace.', 'nyxify' ),
			/* translators: %s: the variables a text may use */
			Fields::VARIABLES === $code => sprintf( __( 'This text can use only %s; write {{ and }} for a brace.', 'nyxify' ), self::variable_list( $text['variables'] ) ),
			/* translators: %s: the most characters */
			self::MARKUP_TOO_LONG === $code => sprintf( __( 'Up to %s characters with the tags.', 'nyxify' ), DateFormat::number( $catalog->markup_limit ) ),
			/* translators: %s: the most characters shown */
			default => sprintf( __( 'Up to %s characters, not counting tags.', 'nyxify' ), DateFormat::number( $text['limit'] ) ),
		};
	}

	/**
	 * The catalog, read once.
	 */
	private function catalog(): ?TextCatalog {
		if ( ! $this->loaded ) {
			$this->loaded  = true;
			$this->catalog = ( $this->load )();
		}

		return $this->catalog;
	}

	/**
	 * Prints the error of the form as a whole, when there is one.
	 *
	 * @param array<string, string> $errors Error codes by field key.
	 * @param self                  $tab    The tab.
	 */
	private static function form_error( array $errors, self $tab ): void {
		if ( isset( $errors[''] ) ) {
			printf( '<div class="nyxify-callout nyxify-callout--error"><p>%s</p></div>', esc_html( $tab->error_message( '', $errors[''] ) ) );
		}
	}

	/**
	 * The stored texts of keys the catalog doesn't know, in the part's shape (a text per language, at least one).
	 *
	 * @param mixed       $stored  The part, or the hidden field's decoded JSON.
	 * @param TextCatalog $catalog The catalog.
	 * @return array<string, array<string, string>>
	 */
	private static function unknown( mixed $stored, TextCatalog $catalog ): array {
		$kept = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $texts ) {
			if ( ! is_string( $key ) || isset( $catalog->texts[ $key ] ) || 1 !== preg_match( TextCatalog::KEY_PATTERN, $key ) || ! is_array( $texts ) ) {
				continue;
			}
			$own = array();
			foreach ( Fields::LANGUAGES as $language ) {
				if ( is_string( $texts[ $language ] ?? null ) && '' !== $texts[ $language ] ) {
					$own[ $language ] = $texts[ $language ];
				}
			}
			if ( array() !== $own ) {
				$kept[ $key ] = $own;
			}
		}

		return $kept;
	}

	/**
	 * The language the admin reads the catalog's names and defaults in.
	 */
	private static function language(): string {
		return str_starts_with( determine_locale(), 'fa' ) ? 'fa' : 'en';
	}

	/**
	 * A text's default as its heading: without tags, clipped.
	 *
	 * @param string $markup The default.
	 */
	private static function heading( string $markup ): string {
		$plain = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( (string) preg_replace( '/<[^>]*>/u', '', $markup ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		return mb_strlen( $plain ) > self::HEADING_LIMIT ? mb_substr( $plain, 0, self::HEADING_LIMIT - 1 ) . '…' : $plain;
	}

	/**
	 * A text's variables, length and formatting, as the form says them.
	 *
	 * @param array{key: string, section: string, markup: bool, variables: list<string>, limit: int, default: array{fa: string, en: string}} $text The text.
	 */
	private static function about( array $text ): string {
		$parts = array(
			array() === $text['variables']
				? __( 'No variables.', 'nyxify' )
				/* translators: %s: the variables a text may use */
				: sprintf( __( 'Variables: %s.', 'nyxify' ), self::variable_list( $text['variables'] ) ),
			/* translators: %s: the most characters the field takes */
			sprintf( __( 'Up to %s characters.', 'nyxify' ), DateFormat::number( $text['limit'] ) ),
		);
		if ( ! $text['markup'] ) {
			$parts[] = __( 'Shown as plain text: formatting is dropped.', 'nyxify' );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Variables as a text names them: `{name}, {shop}`.
	 *
	 * @param string[] $variables The variables.
	 *
	 * @phpstan-param list<string> $variables
	 */
	private static function variable_list( array $variables ): string {
		return implode( ', ', array_map( static fn( string $name ): string => '{' . $name . '}', $variables ) );
	}

	/**
	 * Whether a text is written in either language.
	 *
	 * @param array<string, string> $values The form's fields.
	 * @param string                $key    The text's key.
	 */
	private static function filled( array $values, string $key ): bool {
		return '' !== ( $values[ $key . '.fa' ] ?? '' ) || '' !== ( $values[ $key . '.en' ] ?? '' );
	}

	/**
	 * Whether a text's field has an error.
	 *
	 * @param array<string, string> $errors Error codes by field key.
	 * @param string                $key    The text's key.
	 */
	private static function any_error( array $errors, string $key ): bool {
		return isset( $errors[ $key . '.fa' ] ) || isset( $errors[ $key . '.en' ] );
	}

	/**
	 * Whether a text differs from its saved value.
	 *
	 * @param array<string, string> $values The form's fields.
	 * @param array<string, string> $saved  The saved fields.
	 * @param string                $key    The text's key.
	 */
	private static function differs( array $values, array $saved, string $key ): bool {
		foreach ( Fields::LANGUAGES as $language ) {
			if ( ( $values[ $key . '.' . $language ] ?? '' ) !== ( $saved[ $key . '.' . $language ] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
