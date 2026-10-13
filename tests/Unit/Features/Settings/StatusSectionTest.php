<?php
/**
 * Tests for the overview of the store on Nyxify.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Settings;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Settings\StatusSection;
use Nyxify\Features\Store\StoreStatus;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Tests\Unit\TestCase;

/**
 * The days left on the plan, the products bar, the catalog's badge and the error instead of the cards.
 */
final class StatusSectionTest extends TestCase {

	/**
	 * When the status was checked: 2026-10-12 09:00 UTC.
	 */
	private const CHECKED_AT = 1791795600;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_date' )->alias( fn( string $format, int $timestamp ): string => gmdate( $format, $timestamp ) );
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
	}

	/**
	 * A store status.
	 *
	 * @param array<string, mixed> $subscription Subscription fields to replace.
	 * @param array<string, mixed> $catalog      Catalog fields to replace.
	 */
	private static function store_status( array $subscription = array(), array $catalog = array() ): StoreStatus {
		$status = StoreStatus::from_response(
			array(
				'store_id'     => 'shop_8f2a0c1d3e4b5a69',
				'website_url'  => 'https://shop.example.com',
				'status'       => 'active',
				'license'      => null,
				'subscription' => $subscription + array(
					'plan_name'         => 'Pro',
					'expires_at'        => '2026-12-01T09:00:00',
					'allowed_platforms' => array( 'telegram' ),
					'max_products'      => 50,
				),
				'catalog'      => $catalog + array(
					'status'          => 'succeeded',
					'last_synced_at'  => null,
					'error'           => null,
					'limit_reached'   => false,
					'active_products' => 19,
				),
			),
			self::CHECKED_AT
		);
		self::assertNotNull( $status );

		return $status;
	}

	private static function rendered( StoreStatus|CoreError $status ): string {
		ob_start();
		( new StatusSection() )->render( $status );

		return (string) ob_get_clean();
	}

	/**
	 * Plan ends and what the plan's card says.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function plan_ends(): array {
		return array(
			'weeks away'     => array( '2026-12-01T09:00:00', '<p class="nyxify-stat__meta">50 days left</p>' ),
			'15 days'        => array( '2026-10-27T09:00:00', '<p class="nyxify-stat__meta">15 days left</p>' ),
			'14 days: renew' => array( '2026-10-26T09:00:00', '<p class="nyxify-stat__meta nyxify-stat__meta--warning">Ends in 14 days: renew to keep your bots running.</p>' ),
			'one day'        => array( '2026-10-13T10:00:00', '<p class="nyxify-stat__meta nyxify-stat__meta--warning">Ends in 1 day: renew to keep your bots running.</p>' ),
			'later today'    => array( '2026-10-12T20:00:00', '<p class="nyxify-stat__meta nyxify-stat__meta--warning">Ends today: renew to keep your bots running.</p>' ),
			'ended'          => array( '2026-10-11T09:00:00', '<p class="nyxify-stat__meta nyxify-stat__meta--warning">The plan has ended: renew it to keep your bots running.</p>' ),
		);
	}

	#[DataProvider( 'plan_ends' )]
	public function test_the_days_left_from_the_status_check( string $expires_at, string $expected ): void {
		$html = self::rendered( self::store_status( array( 'expires_at' => $expires_at ) ) );

		self::assertStringContainsString( $expected, $html );
		self::assertStringContainsString( 'Expires ' . substr( $expires_at, 0, 10 ) . '</p>', $html );
	}

	public function test_no_days_left_for_an_unreadable_end(): void {
		$html = self::rendered( self::store_status( array( 'expires_at' => 'soon' ) ) );

		self::assertStringNotContainsString( 'left', $html );
		self::assertStringNotContainsString( 'renew', $html );
		self::assertStringContainsString( 'Expires soon</p>', $html );
	}

	/**
	 * Plan names from the core and how the plan's card shows them in Persian.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function plan_names(): array {
		return array(
			'translated'       => array( 'Free', 'Free – رایگان' ),
			'any case'         => array( 'PRO', 'PRO – حرفه‌ای' ),
			'not a known name' => array( 'Gold', 'Gold' ),
		);
	}

	#[DataProvider( 'plan_names' )]
	public function test_a_known_plan_name_comes_with_its_translation( string $name, string $expected ): void {
		$persian = array(
			'Free'        => 'رایگان',
			'Pro'         => 'حرفه‌ای',
			'%1$s – %2$s' => '%1$s – %2$s',
		);
		Functions\when( '_x' )->alias( fn( string $text ): string => $persian[ $text ] ?? $text );

		$html = self::rendered( self::store_status( array( 'plan_name' => $name ) ) );

		self::assertStringContainsString( '<div class="nyxify-stat__value">' . $expected . '</div>', $html );
	}

	/**
	 * Plan names shown as they are in English.
	 *
	 * @return array<string, array{string}>
	 */
	public static function untranslated_plan_names(): array {
		return array(
			'known'     => array( 'Free' ),
			'lowercase' => array( 'free' ),
			'unknown'   => array( 'Gold' ),
		);
	}

	#[DataProvider( 'untranslated_plan_names' )]
	public function test_a_plan_name_without_a_translation_is_shown_alone( string $name ): void {
		$html = self::rendered( self::store_status( array( 'plan_name' => $name ) ) );

		self::assertStringContainsString( '<div class="nyxify-stat__value">' . $name . '</div>', $html );
	}

	public function test_the_products_bar(): void {
		$html = self::rendered( self::store_status() );

		self::assertStringContainsString( '<div class="nyxify-stat__value">19 of 50</div>', $html );
		self::assertStringContainsString( '<div class="nyxify-bar" role="progressbar" aria-labelledby="nyxify-products-status" aria-valuemin="0" aria-valuemax="50" aria-valuenow="19"><span style="inline-size: 38%"></span></div>', $html );
	}

	public function test_a_full_plan_has_a_full_bar_and_a_warning(): void {
		$html = self::rendered(
			self::store_status(
				array(),
				array(
					'active_products' => 50,
					'limit_reached'   => true,
				)
			)
		);

		self::assertStringContainsString( '<div class="nyxify-bar nyxify-bar--full" role="progressbar" aria-labelledby="nyxify-products-status" aria-valuemin="0" aria-valuemax="50" aria-valuenow="50"><span style="inline-size: 100%"></span></div>', $html );
		self::assertStringContainsString( 'nyxify-stat__meta--warning">Your plan’s product limit was reached', $html );
	}

	public function test_no_bar_for_a_limit_of_zero(): void {
		self::assertStringNotContainsString( 'role="progressbar"', self::rendered( self::store_status( array( 'max_products' => 0 ) ) ) );
	}

	/**
	 * Catalog states and their badges.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function catalog_states(): array {
		return array(
			'never'     => array( 'never', '<span class="nyxify-pill nyxify-pill--off">Not synced yet</span>' ),
			'running'   => array( 'running', '<span class="nyxify-pill nyxify-pill--info nyxify-pill--busy">Syncing…</span>' ),
			'succeeded' => array( 'succeeded', '<span class="nyxify-pill nyxify-pill--ok">Synced</span>' ),
			'failed'    => array( 'failed', '<span class="nyxify-pill nyxify-pill--error">Failed</span>' ),
		);
	}

	#[DataProvider( 'catalog_states' )]
	public function test_the_catalog_badge( string $state, string $badge ): void {
		self::assertStringContainsString( $badge, self::rendered( self::store_status( array(), array( 'status' => $state ) ) ) );
	}

	public function test_an_error_replaces_the_cards(): void {
		$html = self::rendered( new CoreError( 'internal_server_error', 500, 'req-1' ) );

		self::assertStringContainsString( '<div class="notice notice-error inline">', $html );
		self::assertStringNotContainsString( 'nyxify-overview', $html );
	}
}
