<?php
/**
 * Tests for the settings page's "Admin PIN" section.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Settings;

use Brain\Monkey\Functions;
use WooNyx\Features\Settings\AdminPinSection;
use WooNyx\Features\Store\StoreStatus;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Tests\Unit\TestCase;

/**
 * The masked PIN, "no PIN yet", the two buttons and when they are offered. No request is ever sent.
 */
final class AdminPinSectionTest extends TestCase {

	private const PIN = '042917';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

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
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action, string $name ): void {
				printf( '<input type="hidden" name="%s" value="nonce-for-%s" />', $name, $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\expect( 'wp_remote_request' )->never();
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	/**
	 * A store status.
	 *
	 * @param string $state The store's state.
	 */
	private static function store_status( string $state = 'active' ): StoreStatus {
		$status = StoreStatus::from_response(
			array(
				'store_id'     => 'shop_8f2a0c1d3e4b5a69',
				'website_url'  => 'https://shop.example.com',
				'status'       => $state,
				'subscription' => array(
					'plan_name'         => 'Pro',
					'expires_at'        => '2026-12-01T00:00:00',
					'allowed_platforms' => array( 'telegram' ),
					'max_products'      => null,
				),
				'catalog'      => array(
					'status'          => 'succeeded',
					'last_synced_at'  => null,
					'error'           => null,
					'limit_reached'   => false,
					'active_products' => 3,
				),
			),
			1759832100
		);
		self::assertNotNull( $status );

		return $status;
	}

	private function rendered( StoreStatus|CoreError $status ): string {
		ob_start();
		( new AdminPinSection( $this->store() ) )->render( $status );

		return (string) ob_get_clean();
	}

	/**
	 * The forms of the section.
	 *
	 * @param string $html The section.
	 * @return list<string>
	 */
	private static function forms( string $html ): array {
		preg_match_all( '#<form[^>]*class="woonyx-admin-pin-form".*?</form>#s', $html, $matches );

		return $matches[0];
	}

	public function test_without_a_pin(): void {
		$html = $this->rendered( self::store_status() );

		self::assertStringContainsString( '<h2>Admin PIN</h2>', $html );
		self::assertStringContainsString( 'No PIN has been issued from this site yet.', $html );
		self::assertStringNotContainsString( '<details', $html );

		$forms = self::forms( $html );
		self::assertCount( 2, $forms );
		self::assertStringContainsString( 'class="button button-primary">New PIN</button>', $forms[0] );
	}

	public function test_the_pin_is_masked_in_details(): void {
		$this->store()->save_admin_pin( self::PIN );

		$html = $this->rendered( self::store_status() );

		self::assertMatchesRegularExpression(
			'#<details class="woonyx-admin-pin">\s*<summary>Show PIN</summary>\s*<p><code dir="ltr" class="woonyx-pin">042917</code></p>#',
			$html
		);
		self::assertSame( 1, substr_count( $html, self::PIN ) );
		self::assertStringNotContainsString( '<details class="woonyx-admin-pin" open', $html );
		self::assertStringNotContainsString( 'No PIN has been issued', $html );
		self::assertStringContainsString( 'class="button">New PIN</button>', self::forms( $html )[0] );
	}

	public function test_the_two_buttons_and_their_confirmations(): void {
		$forms = self::forms( $this->rendered( self::store_status() ) );

		self::assertCount( 2, $forms );
		foreach ( $forms as $form ) {
			self::assertStringContainsString( 'action="https://shop.example.com/wp-admin/admin-post.php"', $form );
			self::assertStringContainsString( '<input type="hidden" name="action" value="woonyx_issue_admin_pin" />', $form );
			self::assertStringContainsString( 'name="_woonyx_nonce" value="nonce-for-woonyx_issue_admin_pin"', $form );
		}

		self::assertStringContainsString( 'data-woonyx-confirm="Issue a new PIN? The current PIN stops working; current bot admins stay admins."', $forms[0] );
		self::assertStringNotContainsString( 'woonyx_revoke_admins', $forms[0] );

		self::assertStringContainsString( 'data-woonyx-confirm="Issue a new PIN and log out every bot admin?', $forms[1] );
		self::assertStringContainsString( '<input type="hidden" name="woonyx_revoke_admins" value="1" />', $forms[1] );
		self::assertStringContainsString( '>New PIN and log out every admin</button>', $forms[1] );
	}

	public function test_no_buttons_while_the_status_is_unreadable_but_the_pin_stays(): void {
		$this->store()->save_admin_pin( self::PIN );

		$html = $this->rendered( new CoreError( 'no_active_subscription', 403 ) );

		self::assertSame( array(), self::forms( $html ) );
		self::assertStringContainsString( 'A new PIN can be issued here once the store is active and its status above can be read.', $html );
		self::assertStringContainsString( self::PIN, $html );
	}

	public function test_no_buttons_while_the_store_is_not_active(): void {
		$html = $this->rendered( self::store_status( 'deactivated' ) );

		self::assertSame( array(), self::forms( $html ) );
		self::assertStringContainsString( 'A new PIN can be issued here once the store is active', $html );
	}

	public function test_an_unreadable_pin_reads_as_none(): void {
		$this->options[ SecretStore::OPTION_ADMIN_PIN ] = 'v1:garbage';

		$html = $this->rendered( self::store_status() );

		self::assertStringContainsString( 'No PIN has been issued from this site yet.', $html );
		self::assertStringNotContainsString( 'garbage', $html );
	}
}
