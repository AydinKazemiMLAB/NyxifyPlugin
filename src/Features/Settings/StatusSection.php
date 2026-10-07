<?php
/**
 * The settings page's "Store on WooNyx" section.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Settings;

use WooNyx\Features\Store\StatusRefreshHandler;
use WooNyx\Features\Store\StoreStatus;
use WooNyx\Shared\Admin\AdminAction;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Http\ErrorMessages;

/**
 * Shown while a connection is stored: the store's status on the core (license, plan, messengers, products, catalog
 * sync), or why it couldn't be read, and the "Check connection" button. Every value from the core is escaped.
 */
final class StatusSection {

	/**
	 * Prints the section.
	 *
	 * @param StoreStatus|CoreError $result The status (StatusService::get()), fetched once by the page.
	 */
	public function render( StoreStatus|CoreError $result ): void {
		?>
		<h2><?php esc_html_e( 'Store on WooNyx', 'woonyx' ); ?></h2>
		<?php
		if ( $result instanceof CoreError ) {
			ErrorNotice::render( $result );
		} else {
			self::render_status( $result );
		}
		?>
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="woonyx-refresh-form">
			<?php AdminAction::render_fields( StatusRefreshHandler::ACTION ); ?>
			<p>
				<button type="submit" class="button"><?php esc_html_e( 'Check connection', 'woonyx' ); ?></button>
				<?php if ( $result instanceof StoreStatus ) : ?>
					<span class="description">
						<?php
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'Checked %s', 'woonyx' ), DateFormat::timestamp( $result->checked_at ) ) );
						?>
					</span>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * The status table.
	 *
	 * @param StoreStatus $status The status.
	 */
	private static function render_status( StoreStatus $status ): void {
		?>
		<table class="form-table woonyx-store-status" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Store', 'woonyx' ); ?></th>
					<td>
						<?php echo esc_html( self::label( self::store_labels(), $status->status ) ); ?>
						<p class="description"><code dir="ltr"><?php echo esc_html( $status->website_url ); ?></code></p>
						<?php if ( 'active' !== $status->status ) : ?>
							<div class="notice notice-error inline"><p><?php echo esc_html( ErrorMessages::message( 'store_not_active' ) ); ?></p></div>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'License', 'woonyx' ); ?></th>
					<td>
						<?php if ( null === $status->license_prefix ) : ?>
							&mdash;
						<?php else : ?>
							<code dir="ltr"><?php echo esc_html( $status->license_prefix ); ?></code>
							<?php if ( null !== $status->license_activated_at ) : ?>
								<p class="description">
									<?php
									/* translators: %s: date and time */
									echo esc_html( sprintf( __( 'Activated %s', 'woonyx' ), DateFormat::core_date( $status->license_activated_at ) ) );
									?>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plan', 'woonyx' ); ?></th>
					<td>
						<?php echo esc_html( $status->plan_name ); ?>
						<p class="description">
							<?php
							/* translators: %s: date and time */
							echo esc_html( sprintf( __( 'Expires %s', 'woonyx' ), DateFormat::core_date( $status->expires_at ) ) );
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Messengers', 'woonyx' ); ?></th>
					<td><?php echo esc_html( self::platforms( $status->allowed_platforms ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Products', 'woonyx' ); ?></th>
					<td><?php echo esc_html( self::products( $status ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Catalog sync', 'woonyx' ); ?></th>
					<td>
						<?php echo esc_html( self::label( self::catalog_labels(), $status->catalog_status ) ); ?>
						<?php if ( null !== $status->catalog_last_synced_at ) : ?>
							<p class="description">
								<?php
								/* translators: %s: date and time */
								echo esc_html( sprintf( __( 'Last synced %s', 'woonyx' ), DateFormat::core_date( $status->catalog_last_synced_at ) ) );
								?>
							</p>
						<?php endif; ?>
						<?php if ( 'failed' === $status->catalog_status && null !== $status->catalog_error && '' !== $status->catalog_error ) : ?>
							<div class="notice notice-error inline"><p><?php echo esc_html( $status->catalog_error ); ?></p></div>
						<?php endif; ?>
						<?php if ( $status->limit_reached ) : ?>
							<div class="notice notice-warning inline"><p><?php esc_html_e( 'Your plan’s product limit was reached, so some products are not in your bots. Upgrade your plan to include them.', 'woonyx' ); ?></p></div>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Labels of the store's states.
	 *
	 * @return array<string, string>
	 */
	private static function store_labels(): array {
		return array(
			'active'      => __( 'Active', 'woonyx' ),
			'deactivated' => __( 'Deactivated', 'woonyx' ),
			'suspended'   => __( 'Suspended', 'woonyx' ),
		);
	}

	/**
	 * Labels of the catalog sync's states.
	 *
	 * @return array<string, string>
	 */
	private static function catalog_labels(): array {
		return array(
			'never'     => __( 'Not synced yet', 'woonyx' ),
			'running'   => __( 'Syncing…', 'woonyx' ),
			'succeeded' => __( 'Synced', 'woonyx' ),
			'failed'    => __( 'Failed', 'woonyx' ),
		);
	}

	/**
	 * The label of a value; an unknown value (added to the core later) is shown as it is.
	 *
	 * @param array<string, string> $labels Labels by value.
	 * @param string                $value  Value.
	 */
	private static function label( array $labels, string $value ): string {
		return $labels[ $value ] ?? $value;
	}

	/**
	 * The plan's messengers, by name.
	 *
	 * @param string[] $platforms Platform codes.
	 */
	private static function platforms( array $platforms ): string {
		if ( array() === $platforms ) {
			return __( 'None', 'woonyx' );
		}

		$labels = array(
			'telegram' => __( 'Telegram', 'woonyx' ),
			'bale'     => __( 'Bale', 'woonyx' ),
			'rubika'   => __( 'Rubika', 'woonyx' ),
		);

		return implode(
			/* translators: separator between the items of a list */
			_x( ', ', 'list separator', 'woonyx' ),
			array_map( fn( string $platform ): string => self::label( $labels, $platform ), $platforms )
		);
	}

	/**
	 * Products on the core against the plan's limit.
	 *
	 * @param StoreStatus $status The status.
	 */
	private static function products( StoreStatus $status ): string {
		if ( null === $status->max_products ) {
			/* translators: %s: number of products */
			return sprintf( __( '%s (no limit)', 'woonyx' ), number_format_i18n( $status->active_products ) );
		}

		return sprintf(
			/* translators: 1: number of products, 2: the plan's limit */
			__( '%1$s of %2$s', 'woonyx' ),
			number_format_i18n( $status->active_products ),
			number_format_i18n( $status->max_products )
		);
	}
}
