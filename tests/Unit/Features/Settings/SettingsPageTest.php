<?php
/**
 * Tests for the settings page.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Settings;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Bots\AdminService;
use Nyxify\Features\Bots\BotService;
use Nyxify\Features\Settings\SettingsPage;
use Nyxify\Features\Store\StatusService;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * Menu, plugins-list link, stylesheet and the page's three connection states.
 */
final class SettingsPageTest extends TestCase {

	private const PLUGIN_FILE = '/plugins/nyxify/nyxify.php';
	private const CORE_URL    = 'https://core.example.com';
	private const HOOK_SUFFIX = 'woocommerce_page_nyxify';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * In-memory transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Whether the current user may manage WooCommerce.
	 *
	 * @var bool
	 */
	private bool $can_manage = true;

	/**
	 * Calls to wp_remote_request(): url and args.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * What the next wp_remote_request() returns.
	 *
	 * @var array<string, mixed>|\stdClass
	 */
	private $next_response;

	/**
	 * What `GET /bots` returns.
	 *
	 * @var array<string, mixed>
	 */
	private $bots_response;

	/**
	 * What `GET /bots/admins` returns.
	 *
	 * @var array<string, mixed>
	 */
	private $admins_response;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'plugin_basename' )->justReturn( 'nyxify/nyxify.php' );
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_transient' )->alias( fn( string $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action, string $name ): void {
				printf( '<input type="hidden" name="%s" value="nonce-for-%s" />', $name, $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'wp_date' )->alias( fn( string $format, int $timestamp ): string => gmdate( $format, $timestamp ) );

		// The core: only reached for the status, and only while connected.
		$this->next_response   = self::response( 200, self::json( self::status_answer() ) );
		$this->bots_response   = self::response( 200, '[]' );
		$this->admins_response = self::response( 200, '[]' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				if ( str_ends_with( $url, '/api/v1/bots' ) ) {
					return $this->bots_response;
				}
				return str_ends_with( $url, '/api/v1/bots/admins' ) ? $this->admins_response : $this->next_response;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $thing ): bool => $thing instanceof \stdClass );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);
	}

	/**
	 * JSON of a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * A fake HTTP response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw body.
	 * @return array<string, mixed>
	 */
	private static function response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => array( 'x-request-id' => 'req-core-0002' ),
		);
	}

	/**
	 * The core's `GET /store/status` answer (contract §1.3), with changes.
	 *
	 * @param array<string, mixed> $changes Top-level fields to replace.
	 * @return array<string, mixed>
	 */
	private static function status_answer( array $changes = array() ): array {
		return $changes + array(
			'store_id'     => 'shop_8f2a0c1d3e4b5a69',
			'website_url'  => 'https://shop.example.com',
			'status'       => 'active',
			'wp_version'   => '7.1.3',
			'woo_version'  => '11.1.2',
			'license'      => array(
				'key_prefix'   => 'NX-ABCDEF',
				'activated_at' => '2026-09-13T09:00:00',
			),
			'subscription' => array(
				'plan'              => 'free',
				'plan_name'         => 'Free',
				'expires_at'        => '2026-10-13T09:00:00',
				'allowed_platforms' => array( 'telegram', 'bale' ),
				'max_products'      => 50,
			),
			'catalog'      => array(
				'status'          => 'succeeded',
				'started_at'      => '2026-09-13T09:00:01',
				'last_synced_at'  => '2026-09-13T09:00:09',
				'error'           => null,
				'limit_reached'   => false,
				'active_products' => 42,
			),
		);
	}

	private function store( string $site_secret = 'site secret' ): SecretStore {
		return new SecretStore( Encryptor::from_secret( $site_secret ) );
	}

	private function connect(): void {
		$this->store()->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );
	}

	private function page( string $core_url = self::CORE_URL ): SettingsPage {
		$store     = $this->store();
		$wc_logger = Mockery::mock( 'WC_Logger_Interface' );
		$wc_logger->shouldReceive( 'log' );

		$client = new CoreClient( $store, $core_url );
		$logger = new Logger( $wc_logger );

		return new SettingsPage( self::PLUGIN_FILE, $store, $core_url, new StatusService( $client, $logger ), new BotService( $client, $logger ), new AdminService( $client, $logger ) );
	}

	/**
	 * The paths requested from the core, in order.
	 *
	 * @return list<string>
	 */
	private function requested_paths(): array {
		return array_map( fn( array $request ): string => substr( $request['url'], strlen( self::CORE_URL ) ), $this->requests );
	}

	private function rendered( SettingsPage $page ): string {
		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	public function test_register_hooks(): void {
		$page = $this->page();

		$page->register();

		self::assertNotFalse( has_action( 'admin_menu', array( $page, 'add_menu' ) ) );
		self::assertNotFalse( has_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) ) );
		self::assertNotFalse( has_filter( 'plugin_action_links_nyxify/nyxify.php', array( $page, 'add_settings_link' ) ) );
	}

	public function test_menu_is_a_woocommerce_submenu_for_shop_managers(): void {
		$page = $this->page();
		Functions\expect( 'add_submenu_page' )
			->once()
			->with( 'woocommerce', 'Nyxify', 'Nyxify', 'manage_woocommerce', 'nyxify', array( $page, 'render' ) )
			->andReturn( self::HOOK_SUFFIX );

		$page->add_menu();
	}

	public function test_settings_link_comes_first(): void {
		$links = $this->page()->add_settings_link( array( 'deactivate' => '<a>Deactivate</a>' ) );

		self::assertSame( array( 'nyxify-settings', 'deactivate' ), array_keys( $links ) );
		self::assertSame( '<a href="https://shop.example.com/wp-admin/admin.php?page=nyxify">Settings</a>', $links['nyxify-settings'] );
	}

	public function test_no_settings_link_without_the_capability(): void {
		$this->can_manage = false;

		self::assertSame( array( 'deactivate' => 'x' ), $this->page()->add_settings_link( array( 'deactivate' => 'x' ) ) );
	}

	public function test_stylesheet_and_script_only_on_this_page(): void {
		$page = $this->page();
		Functions\when( 'add_submenu_page' )->justReturn( self::HOOK_SUFFIX );
		Functions\when( 'plugins_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-content/plugins/nyxify/' . $path );
		Functions\expect( 'wp_enqueue_style' )
			->once()
			->with( 'nyxify-admin', 'https://shop.example.com/wp-content/plugins/nyxify/assets/css/admin.css', array(), NYXIFY_VERSION );
		Functions\expect( 'wp_enqueue_script' )
			->once()
			->with(
				'nyxify-admin',
				'https://shop.example.com/wp-content/plugins/nyxify/assets/js/admin.js',
				array(),
				NYXIFY_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);

		$page->enqueue_assets( self::HOOK_SUFFIX );
		$page->add_menu();
		$page->enqueue_assets( 'edit.php' );
		$page->enqueue_assets( self::HOOK_SUFFIX );
	}

	public function test_page_needs_the_capability(): void {
		$this->can_manage = false;
		$this->expectExceptionMessage( 'wp_die' );

		$this->page()->render();
	}

	public function test_not_connected(): void {
		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'nyxify-status--disconnected', $html );
		self::assertStringContainsString( 'Not connected', $html );
		self::assertStringContainsString( 'Enter your license key to activate it.', $html );
		self::assertStringContainsString( '<code dir="ltr">https://core.example.com</code>', $html );
		self::assertStringContainsString( 'Nothing is sent to the Nyxify server until you enter a license key.', $html );
		self::assertStringNotContainsString( 'NYXIFY_API_BASE_URL', $html );
		self::assertStringNotContainsString( 'Store on Nyxify', $html );
		self::assertSame( array(), $this->requests, 'Nothing may be sent before a license key is entered.' );
	}

	public function test_connected_shows_the_store_id_but_not_the_secret(): void {
		$secret = 'sec_' . str_repeat( 'e5', 32 );
		$this->store()->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', $secret ) );

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'nyxify-status--connected', $html );
		self::assertStringContainsString( 'shop_8f2a0c1d3e4b5a69', $html );
		self::assertStringNotContainsString( $secret, $html );
	}

	public function test_unreadable_connection_asks_to_activate_again(): void {
		$this->store( 'old salts' )->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'nyxify-status--error', $html );
		self::assertStringContainsString( 'Connection lost', $html );
		self::assertStringContainsString( 'Enter your license key again to reconnect.', $html );
		self::assertStringNotContainsString( 'shop_8f2a0c1d3e4b5a69', $html );
		self::assertSame( array(), $this->requests );
	}

	public function test_core_url_is_escaped(): void {
		$html = $this->rendered( $this->page( 'https://x.example.com/<script>' ) );

		self::assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * The license form's markup, or '' when the page has none.
	 *
	 * @param string $html Page.
	 */
	private static function license_form( string $html ): string {
		return 1 === preg_match( '#<form[^>]*class="nyxify-license-form".*?</form>#s', $html, $matches ) ? $matches[0] : '';
	}

	public function test_not_connected_shows_the_license_form_open(): void {
		$html = $this->rendered( $this->page() );
		$form = self::license_form( $html );

		self::assertStringContainsString( 'action="https://shop.example.com/wp-admin/admin-post.php"', $form );
		self::assertStringContainsString( '<input type="hidden" name="action" value="nyxify_activate" />', $form );
		self::assertStringContainsString( 'name="_nyxify_nonce" value="nonce-for-nyxify_activate"', $form );
		self::assertMatchesRegularExpression( '#<input type="text" id="nyxify-license-key" name="nyxify_license_key" [^>]*autocomplete="off"#', $form );
		self::assertStringContainsString( '>Activate</button>', $form );
		self::assertStringContainsString( 'is not stored on this site', $form );
		self::assertStringContainsString( 'If this user or the key is deleted, the store is disconnected.', $form );
		self::assertStringNotContainsString( '<details', $html );
	}

	public function test_the_license_field_is_never_filled(): void {
		$_POST['nyxify_license_key'] = 'NX-ABCDEF-GHIJKL-MNOPQR-ST2345';

		try {
			$form = self::license_form( $this->rendered( $this->page() ) );
		} finally {
			unset( $_POST['nyxify_license_key'] );
		}

		self::assertDoesNotMatchRegularExpression( '#id="nyxify-license-key"[^>]*\bvalue=#', $form );
		self::assertStringNotContainsString( 'GHIJKL', $form );
	}

	public function test_connected_folds_the_form_into_activate_again(): void {
		$this->connect();

		$html = $this->rendered( $this->page() );

		self::assertMatchesRegularExpression( '#<details class="nyxify-reactivate">\s*<summary>Activate again</summary>.*<form[^>]*class="nyxify-license-form".*</form>\s*</details>#s', $html );
		self::assertStringContainsString( '>Activate again</button>', $html );
		self::assertStringNotContainsString( 'Nothing is sent to the Nyxify server', $html );
	}

	public function test_connected_shows_the_rotate_button_with_a_confirmation(): void {
		$this->connect();

		$html = $this->rendered( $this->page() );

		$form = 1 === preg_match( '#<form[^>]*class="nyxify-rotate-form".*?</form>#s', $html, $matches ) ? $matches[0] : '';
		self::assertStringContainsString( 'action="https://shop.example.com/wp-admin/admin-post.php"', $form );
		self::assertStringContainsString( 'data-nyxify-confirm="Replace the shared secret with a new one now? The store stays connected."', $form );
		self::assertStringContainsString( '<input type="hidden" name="action" value="nyxify_rotate_secret" />', $form );
		self::assertStringContainsString( 'name="_nyxify_nonce" value="nonce-for-nyxify_rotate_secret"', $form );
		self::assertStringContainsString( '>Rotate secret</button>', $form );
		self::assertStringContainsString( '<h2>Shared secret</h2>', $html );
		self::assertLessThan( (int) strpos( $html, '<details' ), (int) strpos( $html, 'nyxify-rotate-form' ) );
		self::assertStringNotContainsString( str_repeat( 'e5', 32 ), $html );
	}

	public function test_connected_warns_about_deactivating(): void {
		$this->connect();

		$html = $this->rendered( $this->page() );

		self::assertMatchesRegularExpression(
			'#</details>\s*<div class="notice notice-warning inline nyxify-deactivation-note">\s*<p><strong>Deactivating Nyxify stops your shop’s bots\. To connect again you will need your license key\.</strong></p>#',
			$html
		);
		self::assertStringContainsString( 'or your subscription has expired, Nyxify keeps the store connected and both stay on this site until you delete the plugin.', $html );
	}

	public function test_no_deactivation_warning_while_not_connected(): void {
		self::assertStringNotContainsString( 'nyxify-deactivation-note', $this->rendered( $this->page() ) );
	}

	public function test_no_rotate_button_without_a_readable_connection(): void {
		self::assertStringNotContainsString( 'nyxify_rotate_secret', $this->rendered( $this->page() ) );

		$this->store( 'old salts' )->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );
		self::assertStringNotContainsString( 'nyxify_rotate_secret', $this->rendered( $this->page() ) );
	}

	public function test_lost_connection_shows_the_form_open(): void {
		$this->store( 'old salts' )->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );

		$html = $this->rendered( $this->page() );

		self::assertNotSame( '', self::license_form( $html ) );
		self::assertStringNotContainsString( '<details', $html );
	}

	public function test_a_success_notice_is_shown_once(): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'success',
			'code'       => 'activated',
			'status'     => 0,
			'request_id' => '',
			'fields'     => array(),
		);

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( '<div class="notice notice-success"><p>The store is connected to Nyxify.</p></div>', $html );
		self::assertSame( array(), $this->transients );
		self::assertStringNotContainsString( 'notice-success', $this->rendered( $this->page() ) );
	}

	public function test_a_rotation_notice(): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'success',
			'code'       => 'secret_rotated',
			'status'     => 0,
			'request_id' => '',
			'fields'     => array(),
		);

		self::assertStringContainsString(
			'<div class="notice notice-success"><p>The shared secret was replaced with a new one. The store stays connected.</p></div>',
			$this->rendered( $this->page() )
		);
	}

	public function test_a_failed_rotation_notice_asks_to_rotate_again(): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'error',
			'code'       => 'secret_not_saved',
			'status'     => 200,
			'request_id' => 'req-0001-abcd',
			'fields'     => array(),
		);

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'The current secret still works: rotate it again now.', $html );
		self::assertStringContainsString( '<code dir="ltr">req-0001-abcd</code>', $html );
	}

	public function test_an_error_notice_shows_the_message_fields_and_request_id(): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'error',
			'code'       => 'validation_error',
			'status'     => 422,
			'request_id' => 'req-0001-abcd',
			'fields'     => array( 'body.site_url', 'body.<b>' ),
		);

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( '<div class="notice notice-error">', $html );
		self::assertStringContainsString( 'Some of the data sent to Nyxify is invalid.', $html );
		self::assertStringContainsString( 'Invalid fields: <code dir="ltr">body.site_url, body.&lt;b&gt;</code>', $html );
		self::assertStringContainsString( 'Request ID (for Nyxify support): <code dir="ltr">req-0001-abcd</code>', $html );
	}

	public function test_an_unknown_error_code_falls_back_on_its_status(): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'error',
			'code'       => 'something_new',
			'status'     => 503,
			'request_id' => '',
			'fields'     => array(),
		);

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'The Nyxify server had an internal error.', $html );
		self::assertStringNotContainsString( 'something_new', $html );
		self::assertStringNotContainsString( 'Request ID', $html );
	}

	public function test_connected_shows_the_store_status_from_the_core(): void {
		$this->connect();

		$html = $this->rendered( $this->page() );

		self::assertSame( array( '/api/v1/store/status', '/api/v1/bots', '/api/v1/bots/admins' ), $this->requested_paths() );
		self::assertSame( 'GET', $this->requests[0]['args']['method'] );
		self::assertArrayHasKey( 'X-Nyxify-Signature', $this->requests[0]['args']['headers'] );

		self::assertStringContainsString( 'Store on Nyxify', $html );
		self::assertMatchesRegularExpression( '#<th scope="row">Store</th>\s*<td>\s*Active#', $html );
		self::assertStringContainsString( '<code dir="ltr">https://shop.example.com</code>', $html );
		self::assertStringContainsString( '<code dir="ltr">NX-ABCDEF</code>', $html );
		self::assertStringContainsString( 'Activated 2026-09-13 09:00', $html );
		self::assertStringContainsString( 'Expires 2026-10-13 09:00', $html );
		self::assertStringContainsString( '<td>Telegram, Bale</td>', $html );
		self::assertStringContainsString( '<td>42 of 50</td>', $html );
		self::assertStringContainsString( 'Synced', $html );
		self::assertStringContainsString( 'Last synced 2026-09-13 09:00', $html );
		// The deactivation note at the end is a warning too; the status itself has none.
		$before_note = (string) strstr( $html, '<div class="notice notice-warning inline nyxify-deactivation-note">', true );
		self::assertNotSame( '', $before_note );
		self::assertStringNotContainsString( 'notice-warning', $before_note );
		self::assertStringNotContainsString( 'notice-error', $html );
		self::assertStringContainsString( '<input type="hidden" name="action" value="nyxify_refresh_status" />', $html );
		self::assertStringContainsString( '>Check connection</button>', $html );
		self::assertStringContainsString( 'Checked ', $html );
	}

	public function test_the_status_is_cached_for_five_minutes(): void {
		$this->connect();

		$this->rendered( $this->page() );
		$html = $this->rendered( $this->page() );

		// The status once; the bots and the admins on every page load.
		self::assertSame(
			array( '/api/v1/store/status', '/api/v1/bots', '/api/v1/bots/admins', '/api/v1/bots', '/api/v1/bots/admins' ),
			$this->requested_paths()
		);
		self::assertStringContainsString( '<td>42 of 50</td>', $html );
	}

	public function test_dates_follow_the_site_formats(): void {
		$this->connect();
		$this->options['date_format'] = 'd/m/Y';
		$this->options['time_format'] = 'g:i a';

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'Expires 13/10/2026 9:00 am', $html );
	}

	public function test_core_strings_are_escaped(): void {
		$this->connect();
		$answer                               = self::status_answer( array( 'website_url' => 'https://shop.example.com/<i>' ) );
		$answer['subscription']['plan_name']  = '<script>alert(1)</script>';
		$answer['catalog']['status']          = 'failed';
		$answer['catalog']['error']           = 'Could not read <b>products</b>';
		$answer['subscription']['expires_at'] = 'not a date <u>';
		$this->next_response                  = self::response( 200, self::json( $answer ) );

		$html = $this->rendered( $this->page() );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>products', $html );
		self::assertStringNotContainsString( '<i>', $html );
		self::assertStringNotContainsString( '<u>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
		self::assertStringContainsString( 'Could not read &lt;b&gt;products&lt;/b&gt;', $html );
		self::assertStringContainsString( 'Failed', $html );
	}

	public function test_no_limit_unknown_values_and_the_limit_notice(): void {
		$this->connect();
		$answer                                      = self::status_answer( array( 'license' => null ) );
		$answer['subscription']['max_products']      = null;
		$answer['subscription']['allowed_platforms'] = array( 'rubika', 'whatsapp' );
		$answer['catalog']['status']                 = 'paused';
		$answer['catalog']['limit_reached']          = true;
		$answer['catalog']['last_synced_at']         = null;
		$this->next_response                         = self::response( 200, self::json( $answer ) );

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( '<td>42 (no limit)</td>', $html );
		self::assertStringContainsString( '<td>Rubika, whatsapp</td>', $html );
		self::assertMatchesRegularExpression( '#<th scope="row">License</th>\s*<td>\s*&mdash;#', $html );
		self::assertStringContainsString( 'paused', $html );
		self::assertStringNotContainsString( 'Last synced', $html );
		self::assertStringContainsString( 'product limit was reached', $html );
	}

	public function test_a_deactivated_store_asks_to_activate_again(): void {
		$this->connect();
		$this->next_response = self::response( 200, self::json( self::status_answer( array( 'status' => 'deactivated' ) ) ) );

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'Deactivated', $html );
		self::assertStringContainsString( 'This store is deactivated or suspended on Nyxify.', $html );
	}

	/**
	 * @return array<string, array{int, string, string, string}>
	 */
	public static function status_errors(): array {
		return array(
			'subscription lapsed' => array( 403, 'no_active_subscription', 'notice-warning', 'Renew it to continue.' ),
			'store not active'    => array( 403, 'store_not_active', 'notice-error', 'Activate it again with your license key' ),
			'secret rejected'     => array( 401, 'invalid_request_signature', 'notice-error', 'Activate the store again with your license key.' ),
			'clock off'           => array( 401, 'request_timestamp_expired', 'notice-error', 'Correct the server time' ),
			'server error'        => array( 500, 'internal_server_error', 'notice-error', 'internal error' ),
		);
	}

	#[DataProvider( 'status_errors' )]
	public function test_status_errors_are_explained_and_not_cached( int $status, string $code, string $notice_class, string $message ): void {
		$this->connect();
		$this->next_response = self::response(
			$status,
			self::json(
				array(
					'message'    => 'raw core message',
					'error_code' => $code,
				)
			)
		);

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( '<div class="notice ' . $notice_class . ' inline">', $html );
		self::assertStringContainsString( $message, $html );
		self::assertStringNotContainsString( 'raw core message', $html );
		self::assertStringContainsString( 'Request ID (for Nyxify support): <code dir="ltr">req-core-0002</code>', $html );
		self::assertStringContainsString( '>Check connection</button>', $html );
		self::assertStringNotContainsString( 'Checked ', $html );
		self::assertArrayNotHasKey( StatusService::TRANSIENT, $this->transients );
	}

	public function test_a_network_failure_is_explained(): void {
		$this->connect();
		$this->next_response = new \stdClass();

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( 'Could not reach the Nyxify server.', $html );
	}

	public function test_the_bots_come_after_the_status_and_before_the_shared_secret(): void {
		$this->connect();

		$html = $this->rendered( $this->page() );

		$status = (int) strpos( $html, '<h2>Store on Nyxify</h2>' );
		$bots   = (int) strpos( $html, '<h2>Bots</h2>' );
		$pin    = (int) strpos( $html, '<h2>Admin PIN</h2>' );
		$admins = (int) strpos( $html, '<h2>Bot admins</h2>' );
		$secret = (int) strpos( $html, '<h2>Shared secret</h2>' );
		self::assertGreaterThan( 0, $status );
		self::assertGreaterThan( $status, $bots );
		self::assertGreaterThan( $bots, $pin );
		self::assertGreaterThan( $pin, $admins );
		self::assertGreaterThan( $admins, $secret );
	}

	public function test_no_pin_section_while_not_connected(): void {
		$this->store()->save_admin_pin( '042917' );

		$html = $this->rendered( $this->page() );

		self::assertStringNotContainsString( 'Admin PIN', $html );
		self::assertStringNotContainsString( '042917', $html );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function pin_notices(): array {
		return array(
			'issued'  => array( 'admin_pin_issued', 'A new admin PIN was issued; the previous one no longer works. Bot admins stay admins.' ),
			'revoked' => array( 'admin_pin_issued_revoked', 'A new admin PIN was issued and every bot admin was logged out.' ),
		);
	}

	#[DataProvider( 'pin_notices' )]
	public function test_pin_success_notices( string $code, string $message ): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'success',
			'code'       => $code,
			'status'     => 0,
			'request_id' => '',
			'fields'     => array(),
		);

		self::assertStringContainsString( '<div class="notice notice-success"><p>' . $message . '</p></div>', $this->rendered( $this->page() ) );
	}

	public function test_no_bots_or_admins_section_while_not_connected(): void {
		$html = $this->rendered( $this->page() );

		self::assertStringNotContainsString( '<h2>Bots</h2>', $html );
		self::assertStringNotContainsString( '<h2>Bot admins</h2>', $html );
		self::assertSame( array(), $this->requests );
	}

	public function test_a_status_error_sends_no_bots_or_admins_request(): void {
		$this->connect();
		$this->next_response = self::response(
			403,
			self::json(
				array(
					'message'    => 'raw core message',
					'error_code' => 'no_active_subscription',
				)
			)
		);

		$html = $this->rendered( $this->page() );

		self::assertSame( array( '/api/v1/store/status' ), $this->requested_paths() );
		self::assertStringContainsString( 'The bots can be managed here once the store’s status above can be read.', $html );
		self::assertStringContainsString( 'The bot admins can be managed here once the store’s status above can be read.', $html );
	}

	public function test_an_admin_removed_notice(): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'success',
			'code'       => 'admin_revoked',
			'status'     => 0,
			'request_id' => '',
			'fields'     => array(),
		);

		self::assertStringContainsString(
			'<div class="notice notice-success"><p>The bot admin was removed. The current PIN still works for them until you issue a new one.</p></div>',
			$this->rendered( $this->page() )
		);
	}

	public function test_an_admin_already_removed_notice(): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'error',
			'code'       => 'customer_not_found',
			'status'     => 404,
			'request_id' => 'req-core-0009',
			'fields'     => array(),
		);

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( '<p>This person is no longer a bot admin of this store.</p>', $html );
		self::assertStringContainsString( '<code dir="ltr">req-core-0009</code>', $html );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function bot_notices(): array {
		return array(
			'registered' => array( 'bot_registered', 'The bot token was accepted. Nyxify is setting the bot up; check its status below.' ),
			'removed'    => array( 'bot_removed', 'The bot was removed. It no longer answers your customers.' ),
		);
	}

	#[DataProvider( 'bot_notices' )]
	public function test_bot_success_notices( string $code, string $message ): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'success',
			'code'       => $code,
			'status'     => 0,
			'request_id' => '',
			'fields'     => array(),
		);

		self::assertStringContainsString( '<div class="notice notice-success"><p>' . $message . '</p></div>', $this->rendered( $this->page() ) );
	}

	public function test_a_malformed_token_notice(): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'error',
			'code'       => 'bot_token_format',
			'status'     => 0,
			'request_id' => '',
			'fields'     => array(),
		);

		self::assertStringContainsString( 'This does not look like a bot token.', $this->rendered( $this->page() ) );
	}

	public function test_an_unknown_success_code_shows_nothing(): void {
		$this->transients['nyxify_notice_5'] = array(
			'type' => 'success',
			'code' => 'something_new',
		);

		self::assertStringNotContainsString( 'notice', $this->rendered( $this->page() ) );
	}

	public function test_an_active_store_has_the_sync_button_in_the_catalog_row(): void {
		$this->connect();

		$html = $this->rendered( $this->page() );

		$row = (string) strstr( (string) strstr( $html, '<th scope="row">Catalog sync</th>' ), '</tr>', true );
		self::assertStringContainsString( '<form method="post" action="https://shop.example.com/wp-admin/admin-post.php" class="nyxify-sync-form">', $row );
		self::assertStringContainsString( '<input type="hidden" name="action" value="nyxify_catalog_sync" />', $row );
		self::assertStringContainsString( '<input type="hidden" name="_nyxify_nonce" value="nonce-for-nyxify_catalog_sync" />', $row );
		self::assertStringContainsString( '>Sync now</button>', $row );
		self::assertStringContainsString( 'Nyxify reads every product and category of this store again.', $row );
		// Only the status was read: the button itself sends nothing.
		self::assertNotContains( '/api/v1/catalog/sync', $this->requested_paths() );
	}

	/**
	 * Store states other than active.
	 *
	 * @return array<string, array{string}>
	 */
	public static function inactive_states(): array {
		return array(
			'deactivated'   => array( 'deactivated' ),
			'suspended'     => array( 'suspended' ),
			'unknown state' => array( 'archived' ),
		);
	}

	#[DataProvider( 'inactive_states' )]
	public function test_no_sync_button_unless_the_store_is_active( string $state ): void {
		$this->connect();
		$this->next_response = self::response( 200, self::json( self::status_answer( array( 'status' => $state ) ) ) );

		$html = $this->rendered( $this->page() );

		self::assertStringContainsString( '<th scope="row">Catalog sync</th>', $html );
		self::assertStringNotContainsString( 'nyxify_catalog_sync', $html );
		self::assertStringNotContainsString( 'Sync now', $html );
	}

	public function test_no_sync_button_while_the_status_cannot_be_read(): void {
		$this->connect();
		$this->next_response = self::response(
			403,
			self::json(
				array(
					'message'    => 'raw core message',
					'error_code' => 'no_active_subscription',
				)
			)
		);

		$html = $this->rendered( $this->page() );

		self::assertStringNotContainsString( 'nyxify_catalog_sync', $html );
		self::assertStringNotContainsString( 'Sync now', $html );
	}

	public function test_no_sync_button_while_not_connected(): void {
		self::assertStringNotContainsString( 'nyxify_catalog_sync', $this->rendered( $this->page() ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function sync_notices(): array {
		return array(
			'started' => array( 'catalog_sync_started', 'Nyxify is reading your catalog again. Check the catalog sync below in a few minutes.' ),
			'running' => array( 'catalog_sync_running', 'A catalog sync is already running. Check the catalog sync below in a few minutes.' ),
		);
	}

	#[DataProvider( 'sync_notices' )]
	public function test_sync_success_notices( string $code, string $message ): void {
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'success',
			'code'       => $code,
			'status'     => 0,
			'request_id' => '',
			'fields'     => array(),
		);

		self::assertStringContainsString( '<div class="notice notice-success"><p>' . $message . '</p></div>', $this->rendered( $this->page() ) );
	}

	public function test_the_plan_limit_notice_is_shown_on_the_page_without_a_link_to_it(): void {
		$this->connect();
		$this->options['nyxify_catalog_plan_limit'] = array(
			'count' => 2,
			'time'  => 1_791_000_000,
		);

		$html = $this->rendered( $this->page() );

		$notice = (int) strpos( $html, '<div class="notice notice-warning nyxify-plan-limit-notice">' );
		self::assertGreaterThan( (int) strpos( $html, '<hr class="wp-header-end" />' ), $notice );
		self::assertLessThan( (int) strpos( $html, '<h2>Connection</h2>' ), $notice );
		self::assertStringContainsString( '2 products didn’t reach your bots: your plan’s product limit is reached.', $html );
		self::assertStringContainsString( '<input type="hidden" name="action" value="nyxify_dismiss_plan_limit" />', $html );
		self::assertStringNotContainsString( 'Nyxify settings', $html );
	}

	public function test_no_plan_limit_notice_without_stored_skips(): void {
		$this->connect();

		self::assertStringNotContainsString( 'nyxify-plan-limit-notice', $this->rendered( $this->page() ) );
	}
}
