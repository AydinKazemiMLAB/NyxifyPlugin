<?php
/**
 * The upgrade notice shown when the plan's product limit keeps products from the bots.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Catalog;

use Nyxify\Features\Settings\DateFormat;
use Nyxify\Features\Settings\SettingsPage;
use Nyxify\Shared\Admin\AdminAction;

/**
 * A delivered product batch whose `skipped_plan_limit` isn't empty records its count and time in an option (not
 * autoloaded); one with nothing skipped for the limit, "Sync now", deactivation and "Dismiss" delete it. While it is
 * set, users who may manage WooCommerce see a warning on the products list, the product editor and the Nyxify page
 * (which prints it itself, as its hook name depends on WooCommerce's translated menu title). Only a count and a time
 * are kept: never a product id or name.
 */
final class PlanLimitNotice {

	/**
	 * Option holding `{count, time}`.
	 */
	public const OPTION = 'nyxify_catalog_plan_limit';

	/**
	 * Admin action name of the "Dismiss" button.
	 */
	public const DISMISS_ACTION = 'dismiss_plan_limit';

	/**
	 * Screens showing the notice through `admin_notices`: the products list and the product editor.
	 *
	 * @var list<string>
	 */
	public const SCREENS = array( 'edit-product', 'product' );

	/**
	 * Registers the notice and the "Dismiss" handler.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render_on_screen' ) );
		add_action( 'admin_post_' . AdminAction::hook_name( self::DISMISS_ACTION ), array( $this, 'dismiss' ) );
	}

	/**
	 * Records a delivered product batch's plan-limit skips: a count above 0 replaces the stored one, 0 deletes it.
	 *
	 * @param int      $count Products the core skipped for the plan's limit.
	 * @param int|null $now   Current Unix time; time() by default.
	 */
	public static function record( int $count, ?int $now = null ): void {
		if ( $count < 1 ) {
			self::clear();
			return;
		}

		update_option(
			self::OPTION,
			array(
				'count' => $count,
				'time'  => $now ?? time(),
			),
			false
		);
	}

	/**
	 * Deletes the stored skips.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The stored skips, or null when none (or the value isn't one).
	 *
	 * @return array{count: int, time: int}|null
	 */
	public static function get(): ?array {
		$value = get_option( self::OPTION );
		if ( ! is_array( $value ) ) {
			return null;
		}

		$count = $value['count'] ?? null;
		$time  = $value['time'] ?? null;
		if ( ! is_int( $count ) || $count < 1 || ! is_int( $time ) || $time < 0 ) {
			return null;
		}

		return array(
			'count' => $count,
			'time'  => $time,
		);
	}

	/**
	 * The `admin_notices` callback: the notice on the products list and the product editor.
	 */
	public function render_on_screen(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || ! in_array( $screen->id, self::SCREENS, true ) ) {
			return;
		}

		self::render( true );
	}

	/**
	 * Prints the notice, if skips are stored and the user may manage WooCommerce.
	 *
	 * @param bool $with_link Whether to link to the Nyxify page (not on the page itself).
	 */
	public static function render( bool $with_link ): void {
		if ( ! current_user_can( AdminAction::CAPABILITY ) ) {
			return;
		}

		$skips = self::get();
		if ( null === $skips ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: number of products */
			_n(
				'%s product didn’t reach your bots: your plan’s product limit is reached.',
				'%s products didn’t reach your bots: your plan’s product limit is reached.',
				$skips['count'],
				'nyxify'
			),
			DateFormat::number( $skips['count'] )
		);

		?>
		<div class="notice notice-warning nyxify-plan-limit-notice">
			<p><strong><?php echo esc_html( $message ); ?></strong></p>
			<p>
				<?php esc_html_e( 'Upgrade your plan to include them.', 'nyxify' ); ?>
				<span class="description">
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Last seen %s', 'nyxify' ), DateFormat::timestamp( $skips['time'] ) ) );
					?>
				</span>
			</p>
			<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>">
				<?php AdminAction::render_fields( self::DISMISS_ACTION ); ?>
				<p>
					<?php if ( $with_link ) : ?>
						<a href="<?php echo esc_url( SettingsPage::url() ); ?>" class="button button-small"><?php esc_html_e( 'Nyxify settings', 'nyxify' ); ?></a>
					<?php endif; ?>
					<button type="submit" class="button button-small"><?php esc_html_e( 'Dismiss', 'nyxify' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles "Dismiss" and goes back to the page it was clicked on (never returns).
	 */
	public function dismiss(): void {
		AdminAction::verify( self::DISMISS_ACTION );

		self::clear();

		$referer = wp_get_referer();
		wp_safe_redirect( is_string( $referer ) && '' !== $referer ? $referer : SettingsPage::url() );
		exit;
	}
}
