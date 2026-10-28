<?php
/**
 * The bot appearance page under WooCommerce.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance;

use Nyxify\Features\Appearance\Tabs\AppearanceTab;
use Nyxify\Features\Appearance\Tabs\WelcomeTab;
use Nyxify\Features\Settings\DateFormat;
use Nyxify\Features\Settings\ErrorNotice;
use Nyxify\Features\Settings\SettingsPage;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Admin\Notice;
use Nyxify\Shared\Admin\Notices;
use Nyxify\Shared\Http\CoreError;

/**
 * WooCommerce > Bot appearance: the shop's settings of its bots (contract §4.3), read from the core on every page load
 * (nothing is sent while the store isn't connected), with a tab per part; each tab's form saves only its part.
 *
 * The page says when a change reaches the bots: they keep their config for BOT_CACHE_MINUTES (NyxifyBot's
 * `BOT_CONFIG_TTL_SECONDS`).
 */
final class AppearancePage {

	public const PAGE_SLUG = 'nyxify-appearance';

	/**
	 * Query argument naming the open tab.
	 */
	public const QUERY_TAB = 'tab';

	/**
	 * Minutes the bots keep their config, so a change reaches every bot within this time.
	 */
	public const BOT_CACHE_MINUTES = 5;

	/**
	 * Handle of the script that picks the banner from the media library.
	 */
	public const SCRIPT_HANDLE = 'nyxify-appearance';

	/**
	 * Absolute path of the main plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * The settings on the core.
	 *
	 * @var AppearanceService
	 */
	private AppearanceService $service;

	/**
	 * The tabs; the first is the default.
	 *
	 * @var non-empty-list<AppearanceTab>
	 */
	private array $tabs;

	/**
	 * Hook suffix of the page, once the menu is registered.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param string            $plugin_file Absolute path of the main plugin file.
	 * @param AppearanceService $service     The settings on the core.
	 * @param AppearanceTab[]   $tabs        The tabs.
	 *
	 * @phpstan-param non-empty-list<AppearanceTab> $tabs
	 */
	public function __construct( string $plugin_file, AppearanceService $service, array $tabs ) {
		$this->plugin_file = $plugin_file;
		$this->service     = $service;
		$this->tabs        = $tabs;
	}

	/**
	 * Registers the menu and the stylesheet.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds WooCommerce > Bot appearance (after WooCommerce > Nyxify, which registers first).
	 */
	public function add_menu(): void {
		$hook_suffix = add_submenu_page(
			'woocommerce',
			__( 'Bot appearance', 'nyxify' ),
			__( 'Bot appearance', 'nyxify' ),
			AdminAction::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * The address of the page, or of one of its tabs.
	 *
	 * @param string $tab The tab's part, or '' for the default tab.
	 */
	public static function url( string $tab = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return '' === $tab ? $url : $url . '&' . self::QUERY_TAB . '=' . rawurlencode( $tab );
	}

	/**
	 * Loads the settings page's stylesheet on this page only, and on the welcome tab the media library with the script
	 * that picks (or uploads) the banner in it.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( SettingsPage::STYLE_HANDLE, plugins_url( 'assets/css/admin.css', $this->plugin_file ), array(), NYXIFY_VERSION );
		if ( WelcomeTab::PART !== $this->current_tab()->part() ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/appearance.js', $this->plugin_file ),
			array( 'media-views' ),
			NYXIFY_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Prints the page.
	 */
	public function render(): void {
		if ( ! current_user_can( AdminAction::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'nyxify' ), '', array( 'response' => 403 ) );
		}

		$tab   = $this->current_tab();
		$view  = $this->service->get();
		$draft = FormDraft::take();

		?>
		<div class="wrap nyxify-settings nyxify-appearance">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bot appearance', 'nyxify' ); ?></h1>
			<a href="<?php echo esc_url( SettingsPage::url() ); ?>" class="page-title-action"><?php esc_html_e( 'Nyxify settings', 'nyxify' ); ?></a>
			<hr class="wp-header-end" />
			<?php self::render_notice(); ?>

			<?php if ( $view instanceof CoreError ) : ?>
				<?php ErrorNotice::render( $view ); ?>
				<?php if ( in_array( $view->code, array( CoreError::NOT_ACTIVATED, CoreError::CONNECTION_UNREADABLE ), true ) ) : ?>
					<p><a href="<?php echo esc_url( SettingsPage::url() ); ?>" class="button button-primary"><?php esc_html_e( 'Go to the Nyxify settings', 'nyxify' ); ?></a></p>
				<?php endif; ?>
			<?php else : ?>
				<div class="nyxify-callout nyxify-callout--info nyxify-appearance__note">
					<p>
						<?php
						/* translators: %s: minutes */
						echo esc_html( sprintf( __( 'Your bots show a change within %s minutes: they keep their settings that long.', 'nyxify' ), DateFormat::number( self::BOT_CACHE_MINUTES ) ) );
						?>
					</p>
					<p><?php esc_html_e( 'Bot admins can change the same settings in the bot’s “🎨 Bot appearance” section, apart from the contact details.', 'nyxify' ); ?></p>
				</div>
				<?php $this->render_tabs( $tab ); ?>
				<?php $this->render_form( $tab, $view, null !== $draft && $draft->tab === $tab->part() ? $draft : null ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The open tab: the one in the URL when known, otherwise the first.
	 */
	private function current_tab(): AppearanceTab {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which tab to show.
		$requested = isset( $_GET[ self::QUERY_TAB ] ) && is_string( $_GET[ self::QUERY_TAB ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY_TAB ] ) ) : '';
		foreach ( $this->tabs as $tab ) {
			if ( $tab->part() === $requested ) {
				return $tab;
			}
		}

		return $this->tabs[0];
	}

	/**
	 * The tabs' links.
	 *
	 * @param AppearanceTab $current The open tab.
	 */
	private function render_tabs( AppearanceTab $current ): void {
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Bot appearance', 'nyxify' ) . '">';
		foreach ( $this->tabs as $tab ) {
			$open = $tab === $current;
			printf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( self::url( $tab->part() ) ),
				esc_attr( 'nav-tab' . ( $open ? ' nav-tab-active' : '' ) ),
				$open ? ' aria-current="page"' : '',
				esc_html( $tab->label() )
			);
		}
		echo '</nav>';
	}

	/**
	 * A tab's form: the owner's unsaved input when a draft waits for it, otherwise the stored part.
	 *
	 * @param AppearanceTab  $tab   The tab.
	 * @param AppearanceView $view  The settings.
	 * @param FormDraft|null $draft The tab's waiting draft.
	 */
	private function render_form( AppearanceTab $tab, AppearanceView $view, ?FormDraft $draft ): void {
		$stored = $tab->values( $view->part( $tab->part() ) );

		?>
		<form method="post" action="<?php echo esc_url( AdminAction::form_url() ); ?>" class="nyxify-card nyxify-appearance-form">
			<?php AdminAction::render_fields( AppearanceSaveHandler::ACTION ); ?>
			<input type="hidden" name="<?php echo esc_attr( AppearanceSaveHandler::FIELD_TAB ); ?>" value="<?php echo esc_attr( $tab->part() ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( AppearanceSaveHandler::FIELD_VERSION ); ?>" value="<?php echo esc_attr( (string) $view->version ); ?>" />
			<input type="hidden" name="<?php echo esc_attr( AppearanceSaveHandler::FIELD_HASH ); ?>" value="<?php echo esc_attr( $view->hash( $tab->part() ) ); ?>" />
			<?php
			if ( null === $draft ) {
				$tab->render( $stored, array(), null );
			} else {
				$tab->render( $draft->values, self::shown_errors( $draft->errors, $stored ), $draft->conflict ? $stored : null );
			}
			?>
			<p class="submit nyxify-actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'nyxify' ); ?></button>
				<?php if ( null !== $view->updated_at ) : ?>
					<span class="description">
						<?php
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'Bot appearance last changed %s', 'nyxify' ), DateFormat::core_date( $view->updated_at ) ) );
						?>
					</span>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/**
	 * A draft's errors as the tab shows them: an error of a key the form has no field for (the core may refuse a part
	 * of the part, e.g. an icon as a whole) belongs to the form as a whole ('').
	 *
	 * @param array<string, string> $errors Error codes by field key.
	 * @param array<string, string> $fields The form's fields.
	 * @return array<string, string>
	 */
	private static function shown_errors( array $errors, array $fields ): array {
		$shown = array();
		foreach ( $errors as $key => $code ) {
			$shown[ array_key_exists( $key, $fields ) ? $key : '' ] ??= $code;
		}

		return $shown;
	}

	/**
	 * The result of the last save, once.
	 */
	private static function render_notice(): void {
		$notice = Notices::take();
		if ( null === $notice ) {
			return;
		}

		if ( Notice::SUCCESS === $notice->type ) {
			if ( AppearanceSaveHandler::SAVED === $notice->code ) {
				printf(
					'<div class="notice notice-success"><p>%s</p></div>',
					/* translators: %s: minutes */
					esc_html( sprintf( __( 'Saved. Your bots show the change within %s minutes.', 'nyxify' ), DateFormat::number( self::BOT_CACHE_MINUTES ) ) )
				);
			}
			return;
		}

		ErrorNotice::render( new CoreError( $notice->code, $notice->status, $notice->request_id ) );
	}
}
