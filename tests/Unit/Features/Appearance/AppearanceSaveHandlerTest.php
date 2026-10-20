<?php
/**
 * Tests for the bot appearance forms' handler.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Appearance\AppearanceSaveHandler;
use Nyxify\Features\Appearance\FormDraft;
use Nyxify\Features\Appearance\AppearanceTabs;
use Nyxify\Features\Appearance\Tabs\Fields;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Tests\Unit\TestCase;

/**
 * Capability and nonce first, then the tab, version and hash, the tab's checks, the save, the notice, the draft and the
 * redirect.
 *
 * The stubbed wp_safe_redirect() throws, so handle() stops before its exit.
 */
final class AppearanceSaveHandlerTest extends TestCase {

	private const PAGE_URL = 'https://shop.example.com/wp-admin/admin.php?page=nyxify-appearance';

	/**
	 * In-memory transients: name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Calls of the save callable: part, value, version and hash.
	 *
	 * @var list<array{string, array<string, mixed>|null, int, string}>
	 */
	private array $saves = array();

	/**
	 * What the save callable returns.
	 *
	 * @var CoreError|null
	 */
	private ?CoreError $result = null;

	/**
	 * Where wp_safe_redirect() was asked to go.
	 *
	 * @var string|null
	 */
	private ?string $redirect = null;

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

	protected function setUp(): void {
		parent::setUp();

		$_POST = array(
			'nyxify_tab'     => 'contact',
			'nyxify_version' => '3',
			'nyxify_hash'    => self::hash(),
			'nyxify_contact' => array(
				'phone'    => '021 1234 5678',
				'telegram' => "@sara\\'s",
				'address'  => array( 'fa' => 'تهران' ),
			),
		);

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->alias( fn( $value ) => self::unslash( $value ) );
		Functions\when( 'sanitize_key' )->alias( fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
		Functions\when( 'sanitize_text_field' )->alias( fn( string $value ): string => trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
		Functions\when( 'sanitize_textarea_field' )->alias( fn( string $value ): string => trim( strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
		Functions\when( 'is_email' )->alias( fn( string $email ) => false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false );
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $name ): int {
				if ( 'nyxify_save_appearance' !== $action || '_nyxify_nonce' !== $name || ! $this->nonce_valid ) {
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
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location ): void {
				$this->redirect = $location;
				throw new \RuntimeException( 'redirect' );
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * A well-formed hash.
	 */
	private static function hash(): string {
		return str_repeat( 'ab', 32 );
	}

	/**
	 * Like wp_unslash(): stripslashes() deep.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( fn( $item ) => self::unslash( $item ), $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}

	private function handler(): AppearanceSaveHandler {
		return new AppearanceSaveHandler(
			function ( string $part, ?array $value, int $version, string $hash ): ?CoreError {
				$this->saves[] = array( $part, $value, $version, $hash );
				return $this->result;
			},
			AppearanceTabs::all()
		);
	}

	/**
	 * Runs the handler up to its redirect.
	 */
	private function submit(): void {
		try {
			$this->handler()->handle();
			self::fail( 'handle() returned instead of redirecting.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirect', $e->getMessage() );
		}
	}

	/**
	 * The notice waiting for user 5.
	 *
	 * @return array<string, mixed>
	 */
	private function notice(): array {
		$notice = $this->transients['nyxify_notice_5'] ?? null;
		self::assertIsArray( $notice );

		return $notice;
	}

	/**
	 * The draft waiting for user 5.
	 *
	 * @return array<string, mixed>
	 */
	private function draft(): array {
		$draft = $this->transients[ FormDraft::TRANSIENT_PREFIX . '5' ] ?? null;
		self::assertIsArray( $draft );

		return $draft;
	}

	public function test_register_hooks_the_admin_post_action(): void {
		$handler = $this->handler();

		$handler->register();

		self::assertNotFalse( has_action( 'admin_post_nyxify_save_appearance', array( $handler, 'handle' ) ) );
	}

	public function test_without_the_capability_nothing_happens(): void {
		$this->can_manage = false;

		$this->expectExceptionMessage( 'wp_die' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->saves );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_without_a_valid_nonce_nothing_happens(): void {
		$this->nonce_valid = false;

		$this->expectExceptionMessage( 'nonce' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->saves );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_a_valid_form_saves_its_part_and_redirects_to_its_tab(): void {
		$_POST['nyxify_contact']['telegram'] = '@sara_shop';

		$this->submit();

		self::assertSame(
			array(
				array(
					'contact',
					array(
						'phone'    => '021 1234 5678',
						'telegram' => 'sara_shop',
						'address'  => array( 'fa' => 'تهران' ),
					),
					3,
					self::hash(),
				),
			),
			$this->saves
		);
		self::assertSame( 'success', $this->notice()['type'] );
		self::assertSame( AppearanceSaveHandler::SAVED, $this->notice()['code'] );
		self::assertArrayNotHasKey( FormDraft::TRANSIENT_PREFIX . '5', $this->transients );
		self::assertSame( self::PAGE_URL . '&tab=contact', $this->redirect );
	}

	public function test_a_field_failing_its_check_sends_nothing_and_keeps_the_input(): void {
		$this->submit();

		self::assertSame( array(), $this->saves );
		self::assertSame( CoreError::APPEARANCE_FORM_INVALID, $this->notice()['code'] );
		self::assertSame( 'contact', $this->draft()['tab'] );
		self::assertSame( array( 'telegram' => Fields::INVALID ), $this->draft()['errors'] );
		self::assertSame( "sara's", $this->draft()['values']['telegram'] );
		self::assertSame( '021 1234 5678', $this->draft()['values']['phone'] );
		self::assertFalse( $this->draft()['conflict'] );
		self::assertSame( self::PAGE_URL . '&tab=contact', $this->redirect );
	}

	public function test_a_conflict_keeps_the_input_and_marks_it(): void {
		$_POST['nyxify_contact']['telegram'] = 'sara_shop';
		$this->result                        = new CoreError( 'appearance_version_conflict', 409, 'req-0001-abcd' );

		$this->submit();

		self::assertCount( 1, $this->saves );
		self::assertSame( 'appearance_version_conflict', $this->notice()['code'] );
		self::assertSame( 'req-0001-abcd', $this->notice()['request_id'] );
		self::assertTrue( $this->draft()['conflict'] );
		self::assertSame( array(), $this->draft()['errors'] );
		self::assertSame( 'sara_shop', $this->draft()['values']['telegram'] );
	}

	public function test_the_cores_422_marks_the_fields_it_names(): void {
		$_POST['nyxify_contact']['telegram'] = 'sara_shop';
		$this->result                        = new CoreError( 'validation_error', 422, 'req-0001-abcd', array( 'body.settings.contact.email', 'body.settings.contact.address.fa' ) );

		$this->submit();

		self::assertSame( 'validation_error', $this->notice()['code'] );
		self::assertSame(
			array(
				'email'      => Fields::REJECTED,
				'address.fa' => Fields::REJECTED,
			),
			$this->draft()['errors']
		);
		self::assertFalse( $this->draft()['conflict'] );
	}

	public function test_other_failures_keep_the_input_without_marks(): void {
		$_POST['nyxify_contact']['telegram'] = 'sara_shop';
		$this->result                        = new CoreError( CoreError::NETWORK_ERROR, 0, 'nx-0001' );

		$this->submit();

		self::assertSame( CoreError::NETWORK_ERROR, $this->notice()['code'] );
		self::assertSame( array(), $this->draft()['errors'] );
		self::assertSame( 'sara_shop', $this->draft()['values']['telegram'] );
	}

	/**
	 * @return array<string, array{string, mixed}>
	 */
	public static function bad_forms(): array {
		return array(
			'unknown tab'       => array( 'nyxify_tab', 'nope' ),
			'missing tab'       => array( 'nyxify_tab', null ),
			'tab as an array'   => array( 'nyxify_tab', array( 'contact' ) ),
			'negative version'  => array( 'nyxify_version', '-1' ),
			'version with text' => array( 'nyxify_version', '3abc' ),
			'missing version'   => array( 'nyxify_version', null ),
			'short hash'        => array( 'nyxify_hash', 'abcd' ),
			'missing hash'      => array( 'nyxify_hash', null ),
		);
	}

	/**
	 * @param string $field The field changed.
	 * @param mixed  $value Its value, or null to leave it out.
	 */
	#[DataProvider( 'bad_forms' )]
	public function test_a_bad_tab_version_or_hash_sends_nothing( string $field, $value ): void {
		if ( null === $value ) {
			unset( $_POST[ $field ] );
		} else {
			$_POST[ $field ] = $value;
		}

		$this->submit();

		self::assertSame( array(), $this->saves );
		self::assertSame( CoreError::INVALID_PAYLOAD, $this->notice()['code'] );
		self::assertSame( self::PAGE_URL, $this->redirect );
	}

	public function test_refused_fields_outside_the_part_mark_the_form(): void {
		self::assertSame( array( '' => Fields::REJECTED ), AppearanceSaveHandler::refused_fields( 'contact', array() ) );
		self::assertSame(
			array(
				''      => Fields::REJECTED,
				'phone' => Fields::REJECTED,
			),
			AppearanceSaveHandler::refused_fields( 'contact', array( 'body.settings.contact', 'body.settings.contact.phone', 'body.version' ) )
		);
		self::assertSame( array( '' => Fields::REJECTED ), AppearanceSaveHandler::refused_fields( 'contact', array( 'body.settings.contact.Phone[0]' ) ) );
	}
}
