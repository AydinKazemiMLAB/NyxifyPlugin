<?php
/**
 * Tests for the bot appearance page.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use Mockery;
use Nyxify\Features\Appearance\AppearancePage;
use Nyxify\Features\Appearance\AppearanceService;
use Nyxify\Features\Appearance\AppearanceTabs;
use Nyxify\Features\Appearance\AppearanceView;
use Nyxify\Features\Appearance\FormDraft;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * The page over a mocked core: nothing is sent while not connected; while connected the settings are read once, the
 * open tab's form carries the version and its part's hash, and a waiting draft goes back into the form.
 */
final class AppearancePageTest extends TestCase {

	private const PLUGIN_FILE = '/plugins/nyxify/nyxify.php';
	private const HOOK_SUFFIX = 'woocommerce_page_nyxify-appearance';

	private const CONTACT = array(
		'phone'    => '021 1234 5678',
		'telegram' => 'sara_shop',
	);

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
	 * The core's settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $view;

	protected function setUp(): void {
		parent::setUp();

		$this->view = array(
			'version'    => 4,
			'updated_at' => '2026-10-19T10:15:00',
			'settings'   => array(
				'menu'    => null,
				'contact' => self::CONTACT,
			),
		);

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
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'sanitize_key' )->alias( fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
		Functions\when( 'wp_date' )->alias( fn( string $format, int $timestamp ): string => gmdate( $format, $timestamp ) );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'checked' )->alias( fn( $checked, $current ): string => (string) $checked === (string) $current ? " checked='checked'" : '' );
		Functions\when( 'sanitize_text_field' )->alias( 'trim' );
		Functions\when( 'selected' )->alias( fn( $selected, $current ): string => (string) $selected === (string) $current ? " selected='selected'" : '' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => json_encode( $this->view ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
					'headers'  => array( 'x-request-id' => 'req-core-0003' ),
				);
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	private function connect(): void {
		$this->store()->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'ab', 32 ) ) );
	}

	private function page(): AppearancePage {
		$wc_logger = Mockery::mock( 'WC_Logger_Interface' );
		$wc_logger->shouldReceive( 'log' );

		return new AppearancePage(
			self::PLUGIN_FILE,
			new AppearanceService( new CoreClient( $this->store(), 'https://core.example.com' ), new Logger( $wc_logger ) ),
			AppearanceTabs::all()
		);
	}

	private function render(): string {
		ob_start();
		$this->page()->render();

		return (string) ob_get_clean();
	}

	public function test_the_menu_is_under_woocommerce_and_the_style_loads_on_this_page_only(): void {
		$page = $this->page();
		Functions\expect( 'add_submenu_page' )->once()->with( 'woocommerce', 'Bot appearance', 'Bot appearance', 'manage_woocommerce', 'nyxify-appearance', array( $page, 'render' ) )->andReturn( self::HOOK_SUFFIX );
		Functions\when( 'plugins_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-content/plugins/nyxify/' . $path );
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'nyxify-admin', 'https://shop.example.com/wp-content/plugins/nyxify/assets/css/admin.css', array(), NYXIFY_VERSION );

		$page->register();
		$page->add_menu();
		$page->enqueue_assets( 'woocommerce_page_nyxify' );
		$page->enqueue_assets( self::HOOK_SUFFIX );

		self::assertNotFalse( has_action( 'admin_menu', array( $page, 'add_menu' ) ) );
	}

	public function test_the_url_names_the_tab(): void {
		self::assertSame( 'https://shop.example.com/wp-admin/admin.php?page=nyxify-appearance', AppearancePage::url() );
		self::assertSame( 'https://shop.example.com/wp-admin/admin.php?page=nyxify-appearance&tab=contact', AppearancePage::url( 'contact' ) );
	}

	public function test_without_the_capability_the_page_is_refused(): void {
		$this->can_manage = false;

		$this->expectExceptionMessage( 'wp_die' );

		try {
			$this->render();
		} finally {
			ob_end_clean();
			self::assertSame( array(), $this->requests );
		}
	}

	public function test_while_not_connected_nothing_is_sent(): void {
		$html = $this->render();

		self::assertSame( array(), $this->requests );
		self::assertStringContainsString( 'This store is not connected to Nyxify yet.', $html );
		self::assertStringContainsString( 'Go to the Nyxify settings', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_the_contact_tab_shows_the_stored_part_with_its_version_and_hash(): void {
		$this->connect();
		$_GET['tab'] = 'contact';
		$view        = AppearanceView::from_response( $this->view );
		self::assertNotNull( $view );

		$html = $this->render();

		self::assertCount( 1, $this->requests );
		self::assertStringContainsString( 'Your bots show a change within 5 minutes', $html );
		self::assertStringContainsString( 'class="nav-tab nav-tab-active" aria-current="page">Contact</a>', $html );
		self::assertStringContainsString( '<input type="hidden" name="action" value="nyxify_save_appearance" />', $html );
		self::assertStringContainsString( '<input type="hidden" name="nyxify_tab" value="contact" />', $html );
		self::assertStringContainsString( '<input type="hidden" name="nyxify_version" value="4" />', $html );
		self::assertStringContainsString( '<input type="hidden" name="nyxify_hash" value="' . $view->hash( 'contact' ) . '" />', $html );
		self::assertStringContainsString( 'value="021 1234 5678"', $html );
		self::assertStringContainsString( 'value="sara_shop"', $html );
		self::assertStringContainsString( 'Bot appearance last changed', $html );
	}

	public function test_an_unknown_tab_opens_the_first(): void {
		$this->connect();
		$_GET['tab'] = 'nope';

		$html = $this->render();

		self::assertStringContainsString( 'name="nyxify_tab" value="menu"', $html );
		self::assertStringContainsString( 'class="nav-tab nav-tab-active" aria-current="page">Menu</a>', $html );
		self::assertStringContainsString( '>Welcome</a>', $html );
		self::assertStringContainsString( '>Back button</a>', $html );
		self::assertStringContainsString( '>Bot description</a>', $html );
		self::assertStringContainsString( '>Contact</a>', $html );
	}

	public function test_a_refusal_of_a_field_the_form_lacks_marks_the_form(): void {
		$this->connect();
		$_GET['tab'] = 'back_button';
		$this->transients[ FormDraft::TRANSIENT_PREFIX . '5' ] = array(
			'tab'      => 'back_button',
			'values'   => array( 'icon.emoji' => '🔙' ),
			'errors'   => array(
				'icon'       => 'rejected',
				'icon.emoji' => 'rejected',
			),
			'conflict' => false,
		);

		$html = $this->render();

		self::assertStringContainsString( 'The Nyxify server did not accept these details.', $html );
		self::assertStringContainsString( 'id="nyxify-back-button-icon-emoji-error"', $html );
	}

	public function test_a_waiting_draft_goes_back_into_the_form_with_the_saved_values(): void {
		$this->connect();
		$_GET['tab'] = 'contact';
		$this->transients[ FormDraft::TRANSIENT_PREFIX . '5' ] = array(
			'tab'      => 'contact',
			'values'   => array(
				'phone'    => '021 9999 0000',
				'telegram' => 'sara_shop',
			),
			'errors'   => array( 'phone' => 'rejected' ),
			'conflict' => true,
		);
		$this->transients['nyxify_notice_5']                   = array(
			'type'       => 'error',
			'code'       => 'appearance_version_conflict',
			'status'     => 409,
			'request_id' => 'req-0001-abcd',
			'fields'     => array(),
		);

		$html = $this->render();

		self::assertStringContainsString( 'These settings were changed meanwhile', $html );
		self::assertStringContainsString( 'req-0001-abcd', $html );
		self::assertStringContainsString( 'value="021 9999 0000"', $html );
		self::assertStringContainsString( 'The Nyxify server did not accept this value.', $html );
		self::assertStringContainsString( '<span dir="auto">021 1234 5678</span>', $html );
		self::assertSame( 1, substr_count( $html, 'Saved now:' ) );
		self::assertSame( array(), $this->transients );
	}

	public function test_a_draft_of_another_tab_is_dropped(): void {
		$this->connect();
		$_GET['tab'] = 'contact';
		$this->transients[ FormDraft::TRANSIENT_PREFIX . '5' ] = array(
			'tab'      => 'menu',
			'values'   => array( 'phone' => '021 9999 0000' ),
			'errors'   => array(),
			'conflict' => false,
		);

		$html = $this->render();

		self::assertStringContainsString( 'value="021 1234 5678"', $html );
		self::assertStringNotContainsString( '021 9999 0000', $html );
	}

	public function test_a_malformed_draft_is_dropped(): void {
		$this->connect();
		$_GET['tab'] = 'contact';
		$this->transients[ FormDraft::TRANSIENT_PREFIX . '5' ] = array(
			'tab'      => 'contact',
			'values'   => array( 'phone' => array( 'x' ) ),
			'errors'   => array(),
			'conflict' => false,
		);

		self::assertStringContainsString( 'value="021 1234 5678"', $this->render() );
	}

	public function test_a_save_shows_when_the_bots_get_it(): void {
		$this->connect();
		$this->transients['nyxify_notice_5'] = array(
			'type'       => 'success',
			'code'       => 'appearance_saved',
			'status'     => 0,
			'request_id' => '',
			'fields'     => array(),
		);

		self::assertStringContainsString( '<div class="notice notice-success"><p>Saved. Your bots show the change within 5 minutes.</p></div>', $this->render() );
	}
}
