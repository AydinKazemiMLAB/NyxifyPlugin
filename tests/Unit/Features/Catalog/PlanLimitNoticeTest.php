<?php
/**
 * Tests for the plan-limit notice.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Catalog;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Catalog\PlanLimitNotice;
use WooNyx\Tests\Unit\TestCase;

/**
 * Recorded, read back, shown on the decided screens only, escaped, dismissed.
 *
 * The stubbed wp_safe_redirect() throws, so dismiss() stops before its exit.
 */
final class PlanLimitNoticeTest extends TestCase {

	private const NOW = 1_791_000_000;

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Autoload flags passed to update_option().
	 *
	 * @var array<string, mixed>
	 */
	private array $autoload = array();

	/**
	 * Whether the current user may manage WooCommerce.
	 *
	 * @var bool
	 */
	private bool $can_manage = true;

	/**
	 * Whether the nonce is valid.
	 *
	 * @var bool
	 */
	private bool $nonce_valid = true;

	/**
	 * The current screen's id, or null for none.
	 *
	 * @var string|null
	 */
	private ?string $screen = 'edit-product';

	/**
	 * What wp_get_referer() returns.
	 *
	 * @var string|false
	 */
	private $referer = 'https://shop.example.com/wp-admin/edit.php?post_type=product';

	/**
	 * Where wp_safe_redirect() was asked to go.
	 *
	 * @var string|null
	 */
	private ?string $redirect = null;

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->alias( fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'get_current_screen' )->alias( fn() => null === $this->screen ? null : (object) array( 'id' => $this->screen ) );
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => number_format( (float) $number ) );
		Functions\when( 'wp_date' )->alias( fn( string $format, int $timestamp ): string => gmdate( $format, $timestamp ) );
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( string $action, string $name ): void {
				printf( '<input type="hidden" name="%s" value="nonce-for-%s" />', $name, $action ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $name ): int {
				if ( 'woonyx_dismiss_plan_limit' !== $action || '_woonyx_nonce' !== $name || ! $this->nonce_valid ) {
					throw new \RuntimeException( 'nonce' );
				}
				return 1;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'wp_get_referer' )->alias( fn() => $this->referer );
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location ): void {
				$this->redirect = $location;
				throw new \RuntimeException( 'redirect' );
			}
		);
	}

	private function store( int $count = 3 ): void {
		$this->options[ PlanLimitNotice::OPTION ] = array(
			'count' => $count,
			'time'  => self::NOW,
		);
	}

	private function on_screen(): string {
		ob_start();
		( new PlanLimitNotice() )->render_on_screen();

		return (string) ob_get_clean();
	}

	private function rendered( bool $with_link ): string {
		ob_start();
		PlanLimitNotice::render( $with_link );

		return (string) ob_get_clean();
	}

	private function dismiss(): string {
		try {
			( new PlanLimitNotice() )->dismiss();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		return 'returned';
	}

	public function test_register_hooks_the_notice_and_the_dismiss_action(): void {
		$notice = new PlanLimitNotice();

		$notice->register();

		self::assertNotFalse( has_action( 'admin_notices', array( $notice, 'render_on_screen' ) ) );
		self::assertNotFalse( has_action( 'admin_post_woonyx_dismiss_plan_limit', array( $notice, 'dismiss' ) ) );
	}

	public function test_skips_are_recorded_with_their_time_and_not_autoloaded(): void {
		PlanLimitNotice::record( 7, self::NOW );

		self::assertSame(
			array(
				'count' => 7,
				'time'  => self::NOW,
			),
			$this->options[ PlanLimitNotice::OPTION ]
		);
		self::assertFalse( $this->autoload[ PlanLimitNotice::OPTION ] );
		self::assertSame( $this->options[ PlanLimitNotice::OPTION ], PlanLimitNotice::get() );
	}

	public function test_later_skips_replace_the_stored_ones(): void {
		PlanLimitNotice::record( 7, self::NOW );
		PlanLimitNotice::record( 2, self::NOW + 60 );

		self::assertSame(
			array(
				'count' => 2,
				'time'  => self::NOW + 60,
			),
			PlanLimitNotice::get()
		);
	}

	public function test_without_a_time_the_current_time_is_kept(): void {
		$before = time();

		PlanLimitNotice::record( 1 );

		self::assertGreaterThanOrEqual( $before, PlanLimitNotice::get()['time'] ?? 0 );
	}

	public function test_a_batch_with_nothing_skipped_for_the_limit_clears_the_notice(): void {
		$this->store();

		PlanLimitNotice::record( 0, self::NOW );

		self::assertSame( array(), $this->options );
		self::assertNull( PlanLimitNotice::get() );
	}

	public function test_clear_deletes_the_option(): void {
		$this->store();

		PlanLimitNotice::clear();

		self::assertSame( array(), $this->options );
	}

	/**
	 * Stored values that aren't skips.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformed(): array {
		return array(
			'nothing'       => array( false ),
			'a string'      => array( '3' ),
			'no count'      => array( array( 'time' => self::NOW ) ),
			'count as text' => array(
				array(
					'count' => '3',
					'time'  => self::NOW,
				),
			),
			'count zero'    => array(
				array(
					'count' => 0,
					'time'  => self::NOW,
				),
			),
			'no time'       => array( array( 'count' => 3 ) ),
			'negative time' => array(
				array(
					'count' => 3,
					'time'  => -1,
				),
			),
		);
	}

	/**
	 * A malformed value reads as none and shows nothing.
	 *
	 * @param mixed $value Stored value.
	 */
	#[DataProvider( 'malformed' )]
	public function test_a_malformed_value_is_ignored( $value ): void {
		$this->options[ PlanLimitNotice::OPTION ] = $value;

		self::assertNull( PlanLimitNotice::get() );
		self::assertSame( '', $this->on_screen() );
	}

	/**
	 * Screens and whether the notice shows there through admin_notices.
	 *
	 * @return array<string, array{0: string|null, 1: bool}>
	 */
	public static function screens(): array {
		return array(
			'products list'   => array( 'edit-product', true ),
			'product editor'  => array( 'product', true ),
			'dashboard'       => array( 'dashboard', false ),
			'posts list'      => array( 'edit-post', false ),
			'categories'      => array( 'edit-product_cat', false ),
			'orders'          => array( 'woocommerce_page_wc-orders', false ),
			'the WooNyx page' => array( 'woocommerce_page_woonyx', false ),
			'no screen (yet)' => array( null, false ),
			'plugins list'    => array( 'plugins', false ),
			'a coupon'        => array( 'shop_coupon', false ),
		);
	}

	/**
	 * Shown through admin_notices on the products list and editor only (the WooNyx page prints it itself).
	 *
	 * @param string|null $screen Screen id.
	 * @param bool        $shown  Whether the notice shows.
	 */
	#[DataProvider( 'screens' )]
	public function test_screens( ?string $screen, bool $shown ): void {
		$this->store();
		$this->screen = $screen;

		$html = $this->on_screen();

		self::assertSame( $shown, str_contains( $html, 'woonyx-plan-limit-notice' ) );
	}

	public function test_the_notice_on_the_products_screens(): void {
		$this->store( 3 );

		$html = $this->on_screen();

		self::assertStringContainsString( '<div class="notice notice-warning woonyx-plan-limit-notice">', $html );
		self::assertStringContainsString( '3 products didn’t reach your bots: your plan’s product limit is reached.', $html );
		self::assertStringContainsString( 'Upgrade your plan to include them.', $html );
		self::assertStringContainsString( 'Last seen ' . gmdate( 'Y-m-d H:i', self::NOW ), $html );
		self::assertStringContainsString( '<a href="https://shop.example.com/wp-admin/admin.php?page=woonyx" class="button button-small">WooNyx settings</a>', $html );
		self::assertStringContainsString( '<form method="post" action="https://shop.example.com/wp-admin/admin-post.php">', $html );
		self::assertStringContainsString( '<input type="hidden" name="action" value="woonyx_dismiss_plan_limit" />', $html );
		self::assertStringContainsString( '<input type="hidden" name="_woonyx_nonce" value="nonce-for-woonyx_dismiss_plan_limit" />', $html );
		self::assertStringContainsString( '>Dismiss</button>', $html );
	}

	public function test_one_product_is_singular_and_large_counts_are_localized(): void {
		$this->store( 1 );
		self::assertStringContainsString( '1 product didn’t reach your bots', $this->on_screen() );

		$this->store( 12345 );
		self::assertStringContainsString( '12,345 products didn’t reach your bots', $this->on_screen() );
	}

	public function test_on_the_woonyx_page_there_is_no_link_to_it(): void {
		$this->store();

		$html = $this->rendered( false );

		self::assertStringContainsString( 'woonyx-plan-limit-notice', $html );
		self::assertStringNotContainsString( 'WooNyx settings', $html );
		self::assertStringContainsString( '>Dismiss</button>', $html );
	}

	public function test_nothing_without_the_capability(): void {
		$this->store();
		$this->can_manage = false;

		self::assertSame( '', $this->on_screen() );
		self::assertSame( '', $this->rendered( false ) );
	}

	public function test_nothing_without_stored_skips(): void {
		self::assertSame( '', $this->on_screen() );
		self::assertSame( '', $this->rendered( false ) );
	}

	public function test_the_site_date_format_is_escaped(): void {
		$this->store();
		$this->options['date_format'] = '<\s\c\r\i\p\t>Y';

		$html = $this->on_screen();

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;' . gmdate( 'Y', self::NOW ), $html );
	}

	public function test_dismiss_deletes_the_option_and_goes_back(): void {
		$this->store();

		self::assertSame( 'redirect', $this->dismiss() );

		self::assertSame( array(), $this->options );
		self::assertSame( 'https://shop.example.com/wp-admin/edit.php?post_type=product', $this->redirect );
	}

	public function test_dismiss_without_a_referer_goes_to_the_woonyx_page(): void {
		$this->store();
		$this->referer = false;

		self::assertSame( 'redirect', $this->dismiss() );

		self::assertSame( 'https://shop.example.com/wp-admin/admin.php?page=woonyx', $this->redirect );
	}

	public function test_dismiss_without_the_capability_keeps_the_option(): void {
		$this->store();
		$this->can_manage = false;

		self::assertSame( 'wp_die', $this->dismiss() );
		self::assertArrayHasKey( PlanLimitNotice::OPTION, $this->options );
	}

	public function test_dismiss_without_a_valid_nonce_keeps_the_option(): void {
		$this->store();
		$this->nonce_valid = false;

		self::assertSame( 'nonce', $this->dismiss() );
		self::assertArrayHasKey( PlanLimitNotice::OPTION, $this->options );
	}
}
