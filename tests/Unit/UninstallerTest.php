<?php
/**
 * Tests for the uninstall cleanup.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit;

use Brain\Monkey\Functions;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Uninstaller;

/**
 * Every option is deleted, on one site or on each site of a network.
 */
final class UninstallerTest extends TestCase {

	public function test_single_site_deletes_every_option(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'delete_option' )->once()->with( SecretStore::OPTION_CONNECTION );
		Functions\expect( 'delete_option' )->once()->with( SecretStore::OPTION_ADMIN_PIN );

		Uninstaller::run();
	}

	public function test_multisite_cleans_each_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );
		Functions\expect( 'switch_to_blog' )->twice();
		Functions\expect( 'restore_current_blog' )->twice();
		Functions\expect( 'delete_option' )->times( 2 * count( Uninstaller::OPTIONS ) );

		Uninstaller::run();
	}
}
