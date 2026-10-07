<?php
/**
 * Nonce and capability checks of the plugin's admin forms.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Shared\Admin;

/**
 * The plugin's admin forms post to `admin-post.php` with `action=woonyx_<name>`; every handler starts with verify().
 *
 * A form:     `<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>">` +
 *             `AdminAction::render_fields( 'activate' )`.
 * A handler:  `add_action( 'admin_post_' . AdminAction::hook_name( 'activate' ), ... )`, first line
 *             `AdminAction::verify( 'activate' )`.
 */
final class AdminAction {

	/**
	 * Capability needed for every page and action of the plugin.
	 */
	public const CAPABILITY = 'manage_woocommerce';

	/**
	 * Name of the nonce field.
	 */
	public const NONCE_NAME = '_woonyx_nonce';

	private const PREFIX         = 'woonyx_';
	private const ACTION_PATTERN = '/^[a-z][a-z_]{0,39}$/D';

	/**
	 * The `action` value and nonce action of a form: `woonyx_<name>`.
	 *
	 * @param string $name Action name, e.g. `activate`.
	 * @throws \InvalidArgumentException When the name isn't lowercase letters and underscores.
	 */
	public static function hook_name( string $name ): string {
		if ( 1 !== preg_match( self::ACTION_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( 'Invalid admin action name.' );
		}

		return self::PREFIX . $name;
	}

	/**
	 * Where the forms post to.
	 */
	public static function form_url(): string {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Prints a form's hidden `action` field and its nonce (with the referer).
	 *
	 * @param string $name Action name.
	 */
	public static function render_fields( string $name ): void {
		$hook = self::hook_name( $name );

		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $hook ) );
		wp_nonce_field( $hook, self::NONCE_NAME );
	}

	/**
	 * Stops the request unless the user may manage WooCommerce and the nonce is valid for this action.
	 *
	 * @param string $name Action name.
	 */
	public static function verify( string $name ): void {
		$hook = self::hook_name( $name );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'woonyx' ), '', array( 'response' => 403 ) );
		}

		// Dies with "The link you followed has expired." when the nonce is missing or invalid.
		check_admin_referer( $hook, self::NONCE_NAME );
	}
}
