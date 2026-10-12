<?php
/**
 * The settings page's "Bots" section.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Settings;

use Nyxify\Features\Bots\Bot;
use Nyxify\Features\Bots\BotPlatform;
use Nyxify\Features\Bots\BotRegistrationHandler;
use Nyxify\Features\Bots\BotRemovalHandler;
use Nyxify\Features\Bots\BotService;
use Nyxify\Features\Store\StoreStatus;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Http\CoreError;

/**
 * Shown while connected, in a card: one block per messenger the plan allows, plus any bot the core still has on a
 * messenger the plan no longer allows (it doesn't work; it can only be removed). Each block has a status badge and
 * shows the bot (username, name, webhook, set-up date, why setting up failed) with a folded "Replace" token form and
 * "Remove", or an open token form when there is no bot.
 *
 * The bots are fetched on every page load, not cached. Nothing is sent while the store isn't active (the core would
 * refuse; the overview explains why); the page doesn't show this section while the store's status can't be read.
 * Every value from the core is escaped; the token field is never filled.
 */
final class BotsSection {

	/**
	 * Where the bots come from.
	 *
	 * @var BotService
	 */
	private BotService $service;

	/**
	 * Constructor.
	 *
	 * @param BotService $service Where the bots come from.
	 */
	public function __construct( BotService $service ) {
		$this->service = $service;
	}

	/**
	 * Prints the section.
	 *
	 * @param StoreStatus $status The store's status, fetched once by the page.
	 */
	public function render( StoreStatus $status ): void {
		?>
		<section class="nyxify-card nyxify-bots-card" aria-labelledby="nyxify-bots-heading">
			<?php Markup::card_heading( 'bots', __( 'Bots', 'nyxify' ), 'nyxify-bots-heading' ); ?>
			<?php $this->render_body( $status ); ?>
		</section>
		<?php
	}

	/**
	 * The card's content.
	 *
	 * @param StoreStatus $status The store's status.
	 */
	private function render_body( StoreStatus $status ): void {
		if ( 'active' !== $status->status ) {
			printf( '<p class="nyxify-empty">%s</p>', esc_html__( 'The bots can be managed here once the store is active on Nyxify again.', 'nyxify' ) );
			return;
		}

		$bots = $this->service->list();
		if ( $bots instanceof CoreError ) {
			ErrorNotice::render( $bots );
			return;
		}

		$by_platform = array();
		foreach ( $bots as $bot ) {
			$by_platform[ $bot->platform ] = $bot;
		}

		$platforms = self::platforms( $status->allowed_platforms, array_keys( $by_platform ) );
		if ( array() === $platforms ) {
			printf( '<p class="nyxify-empty">%s</p>', esc_html__( 'Your plan doesn’t include any messenger. Upgrade your plan to connect a bot.', 'nyxify' ) );
			return;
		}

		printf( '<p class="description">%s</p>', esc_html__( 'One bot per messenger. A bot token is sent once to Nyxify and is not stored on this site.', 'nyxify' ) );

		echo '<div class="nyxify-bots">';
		foreach ( $platforms as $platform ) {
			self::render_platform( $platform, $by_platform[ $platform ] ?? null, in_array( $platform, $status->allowed_platforms, true ) );
		}
		echo '</div>';
	}

	/**
	 * The messengers to show: the plan's known ones in the usual order, then those that only have a bot.
	 *
	 * @param string[] $allowed       The plan's messengers.
	 * @param string[] $with_bots     Messengers with a bot.
	 * @return list<string>
	 */
	private static function platforms( array $allowed, array $with_bots ): array {
		$platforms = array_values( array_filter( BotPlatform::ALL, fn( string $platform ): bool => in_array( $platform, $allowed, true ) ) );

		foreach ( $with_bots as $platform ) {
			if ( ! in_array( $platform, $platforms, true ) ) {
				$platforms[] = $platform;
			}
		}

		return $platforms;
	}

	/**
	 * One messenger's block.
	 *
	 * @param string   $platform Messenger.
	 * @param Bot|null $bot      Its bot, if any.
	 * @param bool     $in_plan  Whether the plan allows the messenger.
	 */
	private static function render_platform( string $platform, ?Bot $bot, bool $in_plan ): void {
		$known = BotPlatform::is_known( $platform );
		$label = BotPlatform::label( $platform );

		?>
		<div class="<?php echo esc_attr( null === $bot ? 'nyxify-bot nyxify-bot--empty' : 'nyxify-bot' ); ?>">
			<div class="nyxify-bot__head">
				<span class="nyxify-bot__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $label, 0, 1 ) ); ?></span>
				<h3><?php echo esc_html( $label ); ?></h3>
				<?php self::render_badge( $bot, $in_plan ); ?>
			</div>
			<?php if ( null === $bot ) : ?>
				<?php
				if ( $in_plan && $known ) {
					self::render_token_form( $platform, false );
				} else {
					printf( '<p>%s</p>', esc_html__( 'No bot is connected.', 'nyxify' ) );
				}
				?>
			<?php else : ?>
				<?php self::render_bot( $bot ); ?>
				<?php if ( ! $in_plan ) : ?>
					<p class="nyxify-callout nyxify-callout--warning"><?php esc_html_e( 'This messenger isn’t in your plan, so the bot doesn’t work. Remove it, or upgrade your plan.', 'nyxify' ); ?></p>
				<?php endif; ?>
				<?php if ( $known ) : ?>
					<div class="nyxify-actions">
						<?php if ( $in_plan ) : ?>
							<details class="nyxify-replace-bot">
								<summary><?php esc_html_e( 'Replace with another bot', 'nyxify' ); ?></summary>
								<?php self::render_token_form( $platform, true ); ?>
							</details>
						<?php endif; ?>
						<?php self::render_remove_form( $platform ); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The messenger's status badge.
	 *
	 * @param Bot|null $bot     Its bot, if any.
	 * @param bool     $in_plan Whether the plan allows the messenger.
	 */
	private static function render_badge( ?Bot $bot, bool $in_plan ): void {
		if ( null === $bot ) {
			Markup::pill( Markup::OFF, __( 'Not connected', 'nyxify' ) );
			return;
		}

		if ( ! $in_plan ) {
			Markup::pill( Markup::WARNING, __( 'Not in your plan', 'nyxify' ) );
			return;
		}

		$tones = array(
			Bot::PENDING => Markup::INFO,
			Bot::ACTIVE  => Markup::OK,
			Bot::ERROR   => Markup::ERROR,
		);

		Markup::pill( $tones[ $bot->status ] ?? Markup::OFF, self::status_label( $bot->status ), Bot::PENDING === $bot->status );
	}

	/**
	 * The bot's details, and what its status means.
	 *
	 * @param Bot $bot The bot.
	 */
	private static function render_bot( Bot $bot ): void {
		?>
		<dl class="nyxify-details">
			<dt><?php esc_html_e( 'Bot', 'nyxify' ); ?></dt>
			<dd><?php self::render_identity( $bot ); ?></dd>
			<dt><?php esc_html_e( 'Webhook', 'nyxify' ); ?></dt>
			<dd><?php echo esc_html( $bot->webhook_active ? __( 'Set', 'nyxify' ) : __( 'Not set', 'nyxify' ) ); ?></dd>
			<?php if ( null !== $bot->deployed_at ) : ?>
				<dt><?php esc_html_e( 'Set up', 'nyxify' ); ?></dt>
				<dd><?php echo esc_html( DateFormat::core_date( $bot->deployed_at, false ) ); ?></dd>
			<?php endif; ?>
		</dl>
		<?php if ( Bot::PENDING === $bot->status ) : ?>
			<p class="nyxify-callout nyxify-callout--info">
				<?php esc_html_e( 'Nyxify is setting the bot up; this usually takes a few seconds.', 'nyxify' ); ?>
				<a href="<?php echo esc_url( SettingsPage::url() ); ?>"><?php esc_html_e( 'Check again', 'nyxify' ); ?></a>
			</p>
		<?php endif; ?>
		<?php if ( Bot::ERROR === $bot->status ) : ?>
			<div class="nyxify-callout nyxify-callout--error">
				<p><?php esc_html_e( 'Setting up the bot failed. Nyxify tries again every hour, up to five times; you can also enter its token again.', 'nyxify' ); ?></p>
				<?php if ( null !== $bot->last_error && '' !== $bot->last_error ) : ?>
					<p><?php esc_html_e( 'Details:', 'nyxify' ); ?> <code dir="ltr"><?php echo esc_html( $bot->last_error ); ?></code></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * The bot's username (a link where the messenger has public bot pages) and name.
	 *
	 * @param Bot $bot The bot.
	 */
	private static function render_identity( Bot $bot ): void {
		$username = null !== $bot->username && '' !== $bot->username ? '@' . ltrim( $bot->username, '@' ) : null;
		$name     = null !== $bot->name && '' !== $bot->name ? $bot->name : null;

		if ( null === $username && null === $name ) {
			echo '&mdash;';
			return;
		}

		if ( null !== $username ) {
			$url = BotPlatform::bot_url( $bot->platform, $username );
			if ( null === $url ) {
				printf( '<code dir="ltr">%s</code>', esc_html( $username ) );
			} else {
				printf( '<a href="%s" target="_blank" rel="noopener noreferrer" dir="ltr">%s</a>', esc_url( $url ), esc_html( $username ) );
			}
		}

		if ( null !== $name ) {
			printf( null === $username ? '%s' : '<br /><span class="description">%s</span>', esc_html( $name ) );
		}
	}

	/**
	 * A token form: open to add a bot, or inside "Replace" with a confirmation.
	 *
	 * @param string $platform A known messenger.
	 * @param bool   $replace  Whether the messenger already has a bot.
	 */
	private static function render_token_form( string $platform, bool $replace ): void {
		$label    = BotPlatform::label( $platform );
		$field_id = 'nyxify-bot-token-' . $platform;
		$confirm  = $replace
			/* translators: %s: messenger name, e.g. Telegram */
			? sprintf( __( 'Replace the %s bot with the bot of this token? The current bot stops answering your customers.', 'nyxify' ), $label )
			: '';

		?>
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="nyxify-bot-token-form nyxify-field"<?php echo '' === $confirm ? '' : ' data-nyxify-confirm="' . esc_attr( $confirm ) . '"'; ?>>
			<?php AdminAction::render_fields( BotRegistrationHandler::ACTION ); ?>
			<input type="hidden" name="<?php echo esc_attr( BotRegistrationHandler::FIELD_PLATFORM ); ?>" value="<?php echo esc_attr( $platform ); ?>" />
			<label for="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Bot token', 'nyxify' ); ?></label>
			<input type="password" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( BotRegistrationHandler::FIELD_TOKEN ); ?>" class="code" dir="ltr" autocomplete="off" spellcheck="false" required aria-describedby="<?php echo esc_attr( $field_id . '-description' ); ?>" />
			<p class="description" id="<?php echo esc_attr( $field_id . '-description' ); ?>"><?php esc_html_e( 'The token the messenger gave you when you created the bot (for example with BotFather). It is sent once to Nyxify and is not stored on this site.', 'nyxify' ); ?></p>
			<p><button type="submit" class="<?php echo esc_attr( $replace ? 'button button-primary button-small' : 'button button-primary' ); ?>"><?php echo esc_html( $replace ? __( 'Replace bot', 'nyxify' ) : __( 'Connect bot', 'nyxify' ) ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * The "Remove" button, with a confirmation.
	 *
	 * @param string $platform A known messenger.
	 */
	private static function render_remove_form( string $platform ): void {
		/* translators: %s: messenger name, e.g. Telegram */
		$confirm = sprintf( __( 'Remove the %s bot? It stops answering your customers.', 'nyxify' ), BotPlatform::label( $platform ) );

		?>
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="nyxify-bot-remove-form" data-nyxify-confirm="<?php echo esc_attr( $confirm ); ?>">
			<?php AdminAction::render_fields( BotRemovalHandler::ACTION ); ?>
			<input type="hidden" name="<?php echo esc_attr( BotRemovalHandler::FIELD_PLATFORM ); ?>" value="<?php echo esc_attr( $platform ); ?>" />
			<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Remove bot', 'nyxify' ); ?></button>
		</form>
		<?php
	}

	/**
	 * The label of a bot status; an unknown value (added to the core later) is shown as it is.
	 *
	 * @param string $status Status.
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			Bot::PENDING => __( 'Setting up…', 'nyxify' ),
			Bot::ACTIVE  => __( 'Active', 'nyxify' ),
			Bot::ERROR   => __( 'Failed', 'nyxify' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
