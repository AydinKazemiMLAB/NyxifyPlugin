<?php
/**
 * The settings page's "Admin PIN" section.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Settings;

use Nyxify\Features\Bots\AdminPinHandler;
use Nyxify\Features\Store\StoreStatus;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Options\SecretStore;

/**
 * Shown while connected: the last PIN issued from this site, masked in a `<details>` (no script, no request), and the
 * two buttons that issue a new one. This page is the only place the PIN is ever shown. The buttons need an active
 * store with a readable status (the core would refuse otherwise); the stored PIN is shown either way.
 */
final class AdminPinSection {

	/**
	 * Where the PIN is kept.
	 *
	 * @var SecretStore
	 */
	private SecretStore $store;

	/**
	 * Constructor.
	 *
	 * @param SecretStore $store Where the PIN is kept.
	 */
	public function __construct( SecretStore $store ) {
		$this->store = $store;
	}

	/**
	 * Prints the section.
	 *
	 * @param StoreStatus|CoreError $status The store's status, fetched once by the page.
	 */
	public function render( StoreStatus|CoreError $status ): void {
		$pin = $this->store->get_admin_pin();

		?>
		<h2><?php esc_html_e( 'Admin PIN', 'nyxify' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Anyone who sends this PIN to one of your bots becomes a bot admin and can manage your shop from the bot. Share it only with people you trust.', 'nyxify' ); ?></p>
		<?php if ( null === $pin ) : ?>
			<p><?php esc_html_e( 'No PIN has been issued from this site yet.', 'nyxify' ); ?></p>
		<?php else : ?>
			<details class="nyxify-admin-pin">
				<summary><?php esc_html_e( 'Show PIN', 'nyxify' ); ?></summary>
				<p><code dir="ltr" class="nyxify-pin"><?php echo esc_html( $pin ); ?></code></p>
				<p class="description"><?php esc_html_e( 'The last PIN issued from this site. Issuing a new one makes it stop working.', 'nyxify' ); ?></p>
			</details>
		<?php endif; ?>
		<?php
		if ( $status instanceof CoreError || 'active' !== $status->status ) {
			printf( '<p class="description">%s</p>', esc_html__( 'A new PIN can be issued here once the store is active and its status above can be read.', 'nyxify' ) );
			return;
		}

		self::render_form( false, null === $pin );
		self::render_form( true, false );
	}

	/**
	 * One of the two buttons, with its confirmation.
	 *
	 * @param bool $revoke  Whether it also logs every admin out.
	 * @param bool $primary Whether it is the main button.
	 */
	private static function render_form( bool $revoke, bool $primary ): void {
		$confirm = $revoke
			? __( 'Issue a new PIN and log out every bot admin? Everyone will need the new PIN to become an admin again.', 'nyxify' )
			: __( 'Issue a new PIN? The current PIN stops working; current bot admins stay admins.', 'nyxify' );

		?>
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="nyxify-admin-pin-form" data-nyxify-confirm="<?php echo esc_attr( $confirm ); ?>">
			<?php AdminAction::render_fields( AdminPinHandler::ACTION ); ?>
			<?php if ( $revoke ) : ?>
				<input type="hidden" name="<?php echo esc_attr( AdminPinHandler::FIELD_REVOKE ); ?>" value="1" />
			<?php endif; ?>
			<button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $revoke ? __( 'New PIN and log out every admin', 'nyxify' ) : __( 'New PIN', 'nyxify' ) ); ?></button>
		</form>
		<?php
	}
}
