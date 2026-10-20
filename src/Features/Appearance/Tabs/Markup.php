<?php
/**
 * Checks of the texts the bots show as markup.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

/**
 * The bots' texts are in an HTML subset (`b i u s code a blockquote tg-emoji`) with the variables their key offers as
 * `{name}` (`{{` and `}}` for literal braces). The tags are left to the core, which refuses a text outside the subset;
 * the plugin checks the variables and the length the bots can show, as the bot's own editors do.
 */
final class Markup {

	/**
	 * The tags people may write, as the forms list them.
	 */
	public const TAGS = '<b> <i> <u> <s> <code> <a href="https://…"> <blockquote>';

	/**
	 * Every brace pair (a variable or not), a doubled brace, or a stray brace.
	 */
	private const BRACES = '/\{\{|\}\}|\{([^{}]*)\}|[{}]/u';

	private const VARIABLE = '/^[a-z][a-z0-9_]{0,29}$/D';

	/**
	 * Whether a text names only the variables it may use and has no stray brace.
	 *
	 * @param string   $text      The text.
	 * @param string[] $variables The variables it may use.
	 *
	 * @phpstan-param list<string> $variables
	 */
	public static function variables_ok( string $text, array $variables ): bool {
		if ( false === preg_match_all( self::BRACES, $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL ) ) {
			return false;
		}

		foreach ( $matches as $match ) {
			if ( '{{' === $match[0] || '}}' === $match[0] ) {
				continue;
			}
			$name = $match[1] ?? null;
			if ( null === $name || 1 !== preg_match( self::VARIABLE, $name ) || ! in_array( $name, $variables, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * How long a text is once its tags are read, counted as Telegram counts (UTF-16 code units).
	 *
	 * @param string $text The text.
	 */
	public static function visible_length( string $text ): int {
		$shown = html_entity_decode( (string) preg_replace( '/<[^>]*>/u', '', $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return intdiv( strlen( (string) mb_convert_encoding( $shown, 'UTF-16LE', 'UTF-8' ) ), 2 );
	}
}
