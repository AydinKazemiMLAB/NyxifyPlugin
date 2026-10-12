<?php
/**
 * Small pieces of the settings page's markup.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Settings;

/**
 * Status badges, icons and card headings, shared by the page's sections. The icons are drawn by the stylesheet (CSS
 * masks of our own SVGs, in the text's colour), so the markup is only an empty, hidden `<span>`.
 */
final class Markup {

	public const OK = 'ok';

	public const WARNING = 'warning';

	public const ERROR = 'error';

	public const INFO = 'info';

	public const OFF = 'off';

	/**
	 * Icons the stylesheet knows.
	 */
	private const ICONS = array( 'store', 'plan', 'products', 'sync', 'bots', 'pin', 'admins', 'connection', 'lock', 'alert' );

	/**
	 * A status badge.
	 *
	 * @param string $tone  One of the tone constants.
	 * @param string $label The text.
	 * @param bool   $busy  Whether its dot pulses (something is in progress).
	 */
	public static function pill( string $tone, string $label, bool $busy = false ): void {
		printf(
			'<span class="%s">%s</span>',
			esc_attr( 'nyxify-pill nyxify-pill--' . $tone . ( $busy ? ' nyxify-pill--busy' : '' ) ),
			esc_html( $label )
		);
	}

	/**
	 * An icon, hidden from screen readers.
	 *
	 * @param string $name One of the known icons.
	 */
	public static function icon( string $name ): void {
		if ( in_array( $name, self::ICONS, true ) ) {
			printf( '<span class="%s" aria-hidden="true"></span>', esc_attr( 'nyxify-icon nyxify-icon--' . $name ) );
		}
	}

	/**
	 * A card's heading: an icon and an `<h2>` with the given id.
	 *
	 * @param string $icon  One of the known icons.
	 * @param string $title The heading.
	 * @param string $id    The heading's id, for the card's `aria-labelledby`.
	 */
	public static function card_heading( string $icon, string $title, string $id ): void {
		echo '<div class="nyxify-card__head">';
		self::icon( $icon );
		printf( '<h2 id="%s">%s</h2>', esc_attr( $id ), esc_html( $title ) );
		echo '</div>';
	}
}
