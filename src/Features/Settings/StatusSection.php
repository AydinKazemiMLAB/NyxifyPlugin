<?php
/**
 * The settings page's overview of the store on Nyxify.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Settings;

use Nyxify\Features\Bots\BotPlatform;
use Nyxify\Features\Catalog\CatalogSyncHandler;
use Nyxify\Features\Store\StoreStatus;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\ErrorMessages;

/**
 * Shown while a connection is stored: four cards with the store's status on the core (the store, the plan with the
 * days left and its messengers, the products against the plan's limit, the catalog sync with its "Sync now" button
 * while the store is active), or why the status couldn't be read. Every value from the core is escaped.
 */
final class StatusSection {

	/**
	 * Days before the plan's end from which the page asks to renew.
	 */
	public const RENEW_WARNING_DAYS = 14;

	private const DAY = 86400;

	/**
	 * Prints the section.
	 *
	 * @param StoreStatus|CoreError $result The status (StatusService::get()), fetched once by the page.
	 */
	public function render( StoreStatus|CoreError $result ): void {
		if ( $result instanceof CoreError ) {
			ErrorNotice::render( $result );
			return;
		}

		?>
		<div class="nyxify-overview">
			<?php
			self::render_store( $result );
			self::render_plan( $result );
			self::render_products( $result );
			self::render_catalog( $result );
			?>
		</div>
		<?php
	}

	/**
	 * The store's card.
	 *
	 * @param StoreStatus $status The status.
	 */
	private static function render_store( StoreStatus $status ): void {
		$labels = array(
			'active'      => __( 'Active', 'nyxify' ),
			'deactivated' => __( 'Deactivated', 'nyxify' ),
			'suspended'   => __( 'Suspended', 'nyxify' ),
		);

		self::open_card( 'store', __( 'Store', 'nyxify' ), 'nyxify-store-status' );
		echo '<div class="nyxify-stat__value">';
		Markup::pill( 'active' === $status->status ? Markup::OK : Markup::ERROR, self::label( $labels, $status->status ) );
		echo '</div>';
		printf( '<p class="nyxify-stat__meta"><code dir="ltr">%s</code></p>', esc_html( $status->website_url ) );
		if ( 'active' !== $status->status ) {
			printf( '<p class="nyxify-stat__meta nyxify-stat__meta--error">%s</p>', esc_html( ErrorMessages::message( 'store_not_active' ) ) );
		}
		echo '</section>';
	}

	/**
	 * The plan's card: its name, the days left (a warning near the end), the end date and its messengers.
	 *
	 * @param StoreStatus $status The status.
	 */
	private static function render_plan( StoreStatus $status ): void {
		self::open_card( 'plan', __( 'Plan', 'nyxify' ), 'nyxify-plan-status' );
		printf( '<div class="nyxify-stat__value">%s</div>', esc_html( $status->plan_name ) );

		$days = self::days_left( $status );
		if ( null !== $days ) {
			printf(
				'<p class="%s">%s</p>',
				esc_attr( $days <= self::RENEW_WARNING_DAYS ? 'nyxify-stat__meta nyxify-stat__meta--warning' : 'nyxify-stat__meta' ),
				esc_html( self::days_left_message( $days ) )
			);
		}

		printf(
			'<p class="nyxify-stat__meta">%s</p>',
			/* translators: %s: date */
			esc_html( sprintf( __( 'Expires %s', 'nyxify' ), DateFormat::core_date( $status->expires_at, false ) ) )
		);
		printf(
			'<p class="nyxify-stat__meta">%s</p>',
			/* translators: %s: list of messengers, e.g. "Telegram, Bale" */
			esc_html( sprintf( __( 'Messengers: %s', 'nyxify' ), self::platforms( $status->allowed_platforms ) ) )
		);
		echo '</section>';
	}

	/**
	 * Whole days from the status check to the plan's end (negative once it ended), or null when the date can't be read.
	 *
	 * @param StoreStatus $status The status.
	 */
	private static function days_left( StoreStatus $status ): ?int {
		$expires = DateFormat::core_timestamp( $status->expires_at );

		return null === $expires ? null : (int) floor( ( $expires - $status->checked_at ) / self::DAY );
	}

	/**
	 * The days left, as a sentence; a call to renew near the end.
	 *
	 * @param int $days Whole days left.
	 */
	private static function days_left_message( int $days ): string {
		if ( $days < 0 ) {
			return __( 'The plan has ended: renew it to keep your bots running.', 'nyxify' );
		}

		if ( 0 === $days ) {
			return __( 'Ends today: renew to keep your bots running.', 'nyxify' );
		}

		if ( $days <= self::RENEW_WARNING_DAYS ) {
			return sprintf(
				/* translators: %s: number of days */
				_n( 'Ends in %s day: renew to keep your bots running.', 'Ends in %s days: renew to keep your bots running.', $days, 'nyxify' ),
				DateFormat::number( $days )
			);
		}

		/* translators: %s: number of days */
		return sprintf( _n( '%s day left', '%s days left', $days, 'nyxify' ), DateFormat::number( $days ) );
	}

	/**
	 * The products' card: active products against the plan's limit, with a bar.
	 *
	 * @param StoreStatus $status The status.
	 */
	private static function render_products( StoreStatus $status ): void {
		self::open_card( 'products', __( 'Products', 'nyxify' ), 'nyxify-products-status' );
		printf( '<div class="nyxify-stat__value">%s</div>', esc_html( self::products( $status ) ) );

		if ( null !== $status->max_products && $status->max_products > 0 ) {
			$percent = (int) min( 100, round( $status->active_products / $status->max_products * 100 ) );
			printf(
				'<div class="%s" role="progressbar" aria-labelledby="nyxify-products-status" aria-valuemin="0" aria-valuemax="%s" aria-valuenow="%s"><span style="%s"></span></div>',
				esc_attr( $status->limit_reached || $percent >= 100 ? 'nyxify-bar nyxify-bar--full' : 'nyxify-bar' ),
				esc_attr( (string) $status->max_products ),
				esc_attr( (string) min( $status->active_products, $status->max_products ) ),
				esc_attr( 'inline-size: ' . $percent . '%' )
			);
		}

		if ( $status->limit_reached ) {
			printf( '<p class="nyxify-stat__meta nyxify-stat__meta--warning">%s</p>', esc_html__( 'Your plan’s product limit was reached, so some products are not in your bots. Upgrade your plan to include them.', 'nyxify' ) );
		}
		echo '</section>';
	}

	/**
	 * The catalog sync's card, with "Sync now" while the store is active.
	 *
	 * @param StoreStatus $status The status.
	 */
	private static function render_catalog( StoreStatus $status ): void {
		$labels = array(
			'never'     => __( 'Not synced yet', 'nyxify' ),
			'running'   => __( 'Syncing…', 'nyxify' ),
			'succeeded' => __( 'Synced', 'nyxify' ),
			'failed'    => __( 'Failed', 'nyxify' ),
		);
		$tones  = array(
			'running'   => Markup::INFO,
			'succeeded' => Markup::OK,
			'failed'    => Markup::ERROR,
		);

		self::open_card( 'sync', __( 'Catalog sync', 'nyxify' ), 'nyxify-catalog-status' );
		echo '<div class="nyxify-stat__value">';
		Markup::pill( $tones[ $status->catalog_status ] ?? Markup::OFF, self::label( $labels, $status->catalog_status ), 'running' === $status->catalog_status );
		echo '</div>';
		if ( null !== $status->catalog_last_synced_at ) {
			printf(
				'<p class="nyxify-stat__meta">%s</p>',
				/* translators: %s: date and time */
				esc_html( sprintf( __( 'Last synced %s', 'nyxify' ), DateFormat::core_date( $status->catalog_last_synced_at ) ) )
			);
		}
		if ( 'failed' === $status->catalog_status && null !== $status->catalog_error && '' !== $status->catalog_error ) {
			printf( '<p class="nyxify-stat__meta nyxify-stat__meta--error">%s</p>', esc_html( $status->catalog_error ) );
		}
		if ( 'active' === $status->status ) {
			self::render_sync_form();
		}
		echo '</section>';
	}

	/**
	 * The "Sync now" button: Nyxify reads the whole catalog again.
	 */
	private static function render_sync_form(): void {
		?>
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="nyxify-sync-form">
			<?php AdminAction::render_fields( CatalogSyncHandler::ACTION ); ?>
			<button type="submit" class="button button-small" aria-describedby="nyxify-sync-description"><?php Markup::icon( 'sync' ); ?><?php esc_html_e( 'Sync now', 'nyxify' ); ?></button>
			<p class="nyxify-stat__meta" id="nyxify-sync-description"><?php esc_html_e( 'Nyxify reads every product and category of this store again.', 'nyxify' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Opens a card: its icon and label; the caller closes the `<section>`.
	 *
	 * @param string $icon  Icon.
	 * @param string $label Label.
	 * @param string $id    The label's id.
	 */
	private static function open_card( string $icon, string $label, string $id ): void {
		printf( '<section class="nyxify-card nyxify-stat" aria-labelledby="%s"><div class="nyxify-stat__label">', esc_attr( $id ) );
		Markup::icon( $icon );
		printf( '<h2 id="%s">%s</h2></div>', esc_attr( $id ), esc_html( $label ) );
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
			return __( 'None', 'nyxify' );
		}

		return implode(
			/* translators: separator between the items of a list */
			_x( ', ', 'list separator', 'nyxify' ),
			array_map( array( BotPlatform::class, 'label' ), $platforms )
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
			return sprintf( __( '%s (no limit)', 'nyxify' ), DateFormat::number( $status->active_products ) );
		}

		return sprintf(
			/* translators: 1: number of products, 2: the plan's limit */
			__( '%1$s of %2$s', 'nyxify' ),
			DateFormat::number( $status->active_products ),
			DateFormat::number( $status->max_products )
		);
	}
}
