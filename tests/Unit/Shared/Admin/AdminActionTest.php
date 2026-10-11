<?php
/**
 * Tests for the admin form helpers.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Shared\Admin;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Tests\Unit\TestCase;

/**
 * Action names, form fields, and the capability and nonce checks.
 */
final class AdminActionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	public function test_hook_name(): void {
		self::assertSame( 'nyxify_activate', AdminAction::hook_name( 'activate' ) );
		self::assertSame( 'nyxify_issue_admin_pin', AdminAction::hook_name( 'issue_admin_pin' ) );
	}

	/**
	 * Names that can't become a hook.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_names(): array {
		return array(
			'empty'     => array( '' ),
			'uppercase' => array( 'Activate' ),
			'dash'      => array( 'issue-pin' ),
			'markup'    => array( 'a"><script>' ),
			'too long'  => array( str_repeat( 'a', 41 ) ),
		);
	}

	/**
	 * Only lowercase letters and underscores.
	 *
	 * @param string $name Action name.
	 */
	#[DataProvider( 'invalid_names' )]
	public function test_invalid_names_throw( string $name ): void {
		$this->expectException( \InvalidArgumentException::class );

		AdminAction::hook_name( $name );
	}

	public function test_form_url(): void {
		Functions\expect( 'admin_url' )->once()->with( 'admin-post.php' )->andReturn( 'https://shop.example.com/wp-admin/admin-post.php' );

		self::assertSame( 'https://shop.example.com/wp-admin/admin-post.php', AdminAction::form_url() );
	}

	public function test_render_fields(): void {
		Functions\expect( 'wp_nonce_field' )->once()->with( 'nyxify_activate', '_nyxify_nonce' );

		ob_start();
		AdminAction::render_fields( 'activate' );

		self::assertSame( '<input type="hidden" name="action" value="nyxify_activate" />', ob_get_clean() );
	}

	public function test_verify_needs_the_capability_before_the_nonce(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );
		Functions\expect( 'check_admin_referer' )->never();
		$this->expectExceptionMessage( 'wp_die' );

		AdminAction::verify( 'activate' );
	}

	public function test_verify_checks_the_nonce_of_this_action(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( true );
		Functions\expect( 'check_admin_referer' )->once()->with( 'nyxify_activate', '_nyxify_nonce' )->andReturn( 1 );

		AdminAction::verify( 'activate' );
	}
}
