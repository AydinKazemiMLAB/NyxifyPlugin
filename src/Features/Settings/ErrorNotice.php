<?php
/**
 * An inline notice for a failed call on the settings page.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Settings;

use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Http\ErrorMessages;

/**
 * The translated message of a failure (never the core's raw text) and its request id; a lapsed subscription is a
 * warning, the rest errors.
 */
final class ErrorNotice {

	/**
	 * Prints the notice.
	 *
	 * @param CoreError $error The failure.
	 */
	public static function render( CoreError $error ): void {
		printf(
			'<div class="notice %s inline"><p>%s</p>',
			esc_attr( 'no_active_subscription' === $error->code ? 'notice-warning' : 'notice-error' ),
			esc_html( ErrorMessages::for_error( $error ) )
		);
		if ( '' !== $error->request_id ) {
			printf(
				'<p class="description">%s <code dir="ltr">%s</code></p>',
				esc_html__( 'Request ID (for WooNyx support):', 'woonyx' ),
				esc_html( $error->request_id )
			);
		}
		echo '</div>';
	}
}
