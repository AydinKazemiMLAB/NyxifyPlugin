<?php
/**
 * The bot's catalog of the texts a shop can replace.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance;

/**
 * `text-catalog.json`, exported by the bot service (`scripts/export_text_catalog.py` there) and refreshed with each
 * release: the parts of the bot (sections) and every text key with its section, variables, limit and default in
 * Persian and English. Text keys and their variables belong to the bot; the core stores whatever keys it is given.
 * Entries that aren't in the expected shape are skipped.
 */
final class TextCatalog {

	/**
	 * The file the plugin ships.
	 */
	public const FILE = __DIR__ . '/text-catalog.json';

	/**
	 * A text key (the core's pattern).
	 */
	public const KEY_PATTERN = '/^[a-z][a-z0-9_.]{1,59}$/D';

	private const VARIABLE = '/^[a-z][a-z0-9_]{0,29}$/D';

	/**
	 * The most characters of any text as written, tags included (the core's limit).
	 *
	 * @var int
	 */
	public readonly int $markup_limit;

	/**
	 * The sections with texts, in the bot's order.
	 *
	 * @var list<array{key: string, title: array{fa: string, en: string}}>
	 */
	public readonly array $sections;

	/**
	 * The texts by key, in the bot's order.
	 *
	 * @var array<string, array{key: string, section: string, markup: bool, variables: list<string>, limit: int, default: array{fa: string, en: string}}>
	 */
	public readonly array $texts;

	/**
	 * Builds the catalog from its decoded JSON.
	 *
	 * @param array<array-key, mixed> $data The decoded file.
	 */
	public function __construct( array $data ) {
		$this->markup_limit = is_int( $data['markup_limit'] ?? null ) && $data['markup_limit'] > 0 ? $data['markup_limit'] : 2000;

		$sections = array();
		foreach ( is_array( $data['sections'] ?? null ) ? $data['sections'] : array() as $section ) {
			$title = is_array( $section ) ? self::localized( $section['title'] ?? null ) : null;
			if ( null !== $title && is_string( $section['key'] ?? null ) ) {
				$sections[ $section['key'] ] = array(
					'key'   => $section['key'],
					'title' => $title,
				);
			}
		}

		$texts = array();
		foreach ( is_array( $data['texts'] ?? null ) ? $data['texts'] : array() as $text ) {
			$entry = is_array( $text ) ? self::text( $text ) : null;
			if ( null !== $entry && isset( $sections[ $entry['section'] ] ) && ! self::nests( $entry['key'], array_keys( $texts ) ) ) {
				$texts[ $entry['key'] ] = $entry;
			}
		}

		$used           = array_column( $texts, 'section' );
		$this->sections = array_values( array_filter( $sections, static fn( array $section ): bool => in_array( $section['key'], $used, true ) ) );
		$this->texts    = $texts;
	}

	/**
	 * The catalog the plugin ships; null when it can't be read.
	 *
	 * @param string $file The file.
	 */
	public static function load( string $file = self::FILE ): ?self {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin's own.
		$json = is_readable( $file ) ? file_get_contents( $file ) : false;
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$catalog = new self( $data );

		return array() === $catalog->texts ? null : $catalog;
	}

	/**
	 * The texts of a section, in the bot's order.
	 *
	 * @param string $section The section's key.
	 * @return list<array{key: string, section: string, markup: bool, variables: list<string>, limit: int, default: array{fa: string, en: string}}>
	 */
	public function in_section( string $section ): array {
		return array_values( array_filter( $this->texts, static fn( array $text ): bool => $section === $text['section'] ) );
	}

	/**
	 * A text entry, or null when it isn't in the expected shape.
	 *
	 * @param array<array-key, mixed> $text The decoded entry.
	 * @return array{key: string, section: string, markup: bool, variables: list<string>, limit: int, default: array{fa: string, en: string}}|null
	 */
	private static function text( array $text ): ?array {
		$default   = self::localized( $text['default'] ?? null );
		$variables = is_array( $text['variables'] ?? null ) ? $text['variables'] : null;
		if (
			null === $default || null === $variables
			|| ! is_string( $text['key'] ?? null ) || 1 !== preg_match( self::KEY_PATTERN, $text['key'] )
			|| ! is_string( $text['section'] ?? null ) || ! is_bool( $text['markup'] ?? null )
			|| ! is_int( $text['limit'] ?? null ) || $text['limit'] < 1
		) {
			return null;
		}

		$names = array();
		foreach ( $variables as $variable ) {
			if ( ! is_string( $variable ) || 1 !== preg_match( self::VARIABLE, $variable ) ) {
				return null;
			}
			$names[] = $variable;
		}

		return array(
			'key'       => $text['key'],
			'section'   => $text['section'],
			'markup'    => $text['markup'],
			'variables' => $names,
			'limit'     => $text['limit'],
			'default'   => $default,
		);
	}

	/**
	 * A text per language, or null when either is missing.
	 *
	 * @param mixed $value The decoded value.
	 * @return array{fa: string, en: string}|null
	 */
	private static function localized( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! is_string( $value['fa'] ?? null ) || ! is_string( $value['en'] ?? null ) ) {
			return null;
		}

		return array(
			'fa' => $value['fa'],
			'en' => $value['en'],
		);
	}

	/**
	 * Whether a key and one taken would share form fields: one is the other and a dot plus more.
	 *
	 * @param string   $key   The key.
	 * @param string[] $taken Keys taken.
	 */
	private static function nests( string $key, array $taken ): bool {
		foreach ( $taken as $other ) {
			if ( str_starts_with( $key, $other . '.' ) || str_starts_with( $other, $key . '.' ) ) {
				return true;
			}
		}

		return false;
	}
}
