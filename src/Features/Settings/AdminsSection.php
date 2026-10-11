<?php
/**
 * The settings page's "Bot admins" section.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Settings;

use Nyxify\Features\Bots\AdminRemovalHandler;
use Nyxify\Features\Bots\AdminService;
use Nyxify\Features\Bots\BotAdmin;
use Nyxify\Features\Bots\BotPlatform;
use Nyxify\Features\Store\StoreStatus;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Http\CoreError;

/**
 * Shown while connected: the people who became bot admins with the PIN (messenger, name, username; the messenger
 * user id only when they have neither a name nor a username), each with a "Remove" button and its confirmation.
 *
 * This page is the only place their personal data is shown: fetched on every page load, never cached or logged, and
 * escaped. Nothing is sent while the store's status can't be read or the store isn't active (the core would refuse;
 * the status section explains why).
 */
final class AdminsSection {

	/**
	 * Where the admins come from.
	 *
	 * @var AdminService
	 */
	private AdminService $service;

	/**
	 * Constructor.
	 *
	 * @param AdminService $service Where the admins come from.
	 */
	public function __construct( AdminService $service ) {
		$this->service = $service;
	}

	/**
	 * Prints the section.
	 *
	 * @param StoreStatus|CoreError $status The store's status, fetched once by the page.
	 */
	public function render( StoreStatus|CoreError $status ): void {
		?>
		<h2><?php esc_html_e( 'Bot admins', 'nyxify' ); ?></h2>
		<?php
		if ( $status instanceof CoreError ) {
			printf( '<p class="description">%s</p>', esc_html__( 'The bot admins can be managed here once the store’s status above can be read.', 'nyxify' ) );
			return;
		}

		if ( 'active' !== $status->status ) {
			printf( '<p class="description">%s</p>', esc_html__( 'The bot admins can be managed here once the store is active on Nyxify again.', 'nyxify' ) );
			return;
		}

		$admins = $this->service->list();
		if ( $admins instanceof CoreError ) {
			ErrorNotice::render( $admins );
			return;
		}

		printf( '<p class="description">%s</p>', esc_html__( 'People who sent the admin PIN to one of your bots. Removing one takes away their admin access, but the current PIN still works for them: issue a new PIN too if they shouldn’t come back.', 'nyxify' ) );

		if ( array() === $admins ) {
			printf( '<p>%s</p>', esc_html__( 'No one is a bot admin yet.', 'nyxify' ) );
			return;
		}

		?>
		<table class="widefat striped nyxify-admins">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Messenger', 'nyxify' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Name', 'nyxify' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Username', 'nyxify' ); ?></th>
					<td><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'nyxify' ); ?></span></td>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $admins as $admin ) : ?>
					<?php self::render_admin( $admin ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One admin's row.
	 *
	 * @param BotAdmin $admin The admin.
	 */
	private static function render_admin( BotAdmin $admin ): void {
		$name     = $admin->full_name();
		$username = self::username( $admin );

		/* translators: %s: the bot admin's name, username or messenger user id */
		$confirm = sprintf( __( 'Remove %s as a bot admin? The current PIN still works for them until you issue a new one.', 'nyxify' ), self::who( $admin ) );

		?>
		<tr>
			<td><?php echo esc_html( BotPlatform::label( $admin->platform ) ); ?></td>
			<td>
				<?php
				if ( '' !== $name ) {
					echo esc_html( $name );
				} elseif ( null === $username ) {
					printf( '%s <code dir="ltr">%s</code>', esc_html__( 'Messenger ID:', 'nyxify' ), esc_html( $admin->messenger_user_id ) );
				} else {
					echo '&mdash;';
				}
				?>
			</td>
			<td>
				<?php
				if ( null === $username ) {
					echo '&mdash;';
				} else {
					printf( '<span dir="ltr">%s</span>', esc_html( $username ) );
				}
				?>
			</td>
			<td>
				<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="nyxify-admin-remove-form" data-nyxify-confirm="<?php echo esc_attr( $confirm ); ?>">
					<?php AdminAction::render_fields( AdminRemovalHandler::ACTION ); ?>
					<input type="hidden" name="<?php echo esc_attr( AdminRemovalHandler::FIELD_ADMIN_ID ); ?>" value="<?php echo esc_attr( (string) $admin->id ); ?>" />
					<button type="submit" class="button button-small"><?php esc_html_e( 'Remove', 'nyxify' ); ?></button>
				</form>
			</td>
		</tr>
		<?php
	}

	/**
	 * The admin's username with one leading `@`, or null when there is none.
	 *
	 * @param BotAdmin $admin The admin.
	 */
	private static function username( BotAdmin $admin ): ?string {
		$username = ltrim( trim( (string) $admin->username ), '@' );

		return '' === $username ? null : '@' . $username;
	}

	/**
	 * How the confirmation names the admin: their name, else their username, else their messenger user id.
	 *
	 * @param BotAdmin $admin The admin.
	 */
	private static function who( BotAdmin $admin ): string {
		$name = $admin->full_name();
		if ( '' !== $name ) {
			return $name;
		}

		return self::username( $admin ) ?? $admin->messenger_user_id;
	}
}
