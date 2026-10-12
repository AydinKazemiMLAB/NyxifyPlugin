<?php
/**
 * Tests for the settings page's "Bots" section.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Settings;

use Brain\Monkey\Functions;
use Mockery;
use Nyxify\Features\Bots\BotService;
use Nyxify\Features\Settings\BotsSection;
use Nyxify\Features\Store\StoreStatus;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * Blocks per plan, bots outside the plan, the forms, the escaping of the core's strings and when nothing is sent.
 */
final class BotsSectionTest extends TestCase {

	private const CORE_URL = 'https://core.example.com';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Calls to wp_remote_request(): url and args.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * What `GET /bots` returns.
	 *
	 * @var array<string, mixed>|\stdClass
	 */
	private $bots_response;

	protected function setUp(): void {
		parent::setUp();

		$this->bots_response = self::response( 200, '[]' );

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
		Functions\when( 'wp_date' )->alias( fn( string $format, int $timestamp ): string => gmdate( $format, $timestamp ) );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->bots_response;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $thing ): bool => $thing instanceof \stdClass );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);

		$store = new SecretStore( Encryptor::from_secret( 'site secret' ) );
		$store->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );
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
			'headers'  => array( 'x-request-id' => 'req-core-0003' ),
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
	 * The core answers GET /bots with these bots.
	 *
	 * @param array<array<string, mixed>> $bots `BotView`s.
	 */
	private function bots( array $bots ): void {
		$this->bots_response = self::response( 200, self::json( $bots ) );
	}

	/**
	 * A `BotView`.
	 *
	 * @param array<string, mixed> $changes Fields to replace.
	 * @return array<string, mixed>
	 */
	private static function bot( array $changes = array() ): array {
		return $changes + array(
			'platform'       => 'telegram',
			'username'       => 'nyxify_shop_bot',
			'name'           => 'Nyxify Shop',
			'status'         => 'active',
			'webhook_active' => true,
			'last_error'     => null,
			'deployed_at'    => '2026-10-07T10:15:00',
		);
	}

	/**
	 * A store status.
	 *
	 * @param string[] $platforms The plan's messengers.
	 * @param string   $state     The store's state.
	 */
	private static function store_status( array $platforms = array( 'telegram', 'bale', 'rubika' ), string $state = 'active' ): StoreStatus {
		$status = StoreStatus::from_response(
			array(
				'store_id'     => 'shop_8f2a0c1d3e4b5a69',
				'website_url'  => 'https://shop.example.com',
				'status'       => $state,
				'license'      => null,
				'subscription' => array(
					'plan_name'         => 'Pro',
					'expires_at'        => '2026-12-01T00:00:00',
					'allowed_platforms' => $platforms,
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

	private function section(): BotsSection {
		$store     = new SecretStore( Encryptor::from_secret( 'site secret' ) );
		$wc_logger = Mockery::mock( 'WC_Logger_Interface' );
		$wc_logger->shouldReceive( 'log' );

		return new BotsSection( new BotService( new CoreClient( $store, self::CORE_URL ), new Logger( $wc_logger ) ) );
	}

	private function rendered( StoreStatus $status ): string {
		ob_start();
		$this->section()->render( $status );

		return (string) ob_get_clean();
	}

	/**
	 * The block of one messenger.
	 *
	 * @param string $html  The section.
	 * @param string $label The messenger's heading.
	 */
	private static function block( string $html, string $label ): string {
		foreach ( (array) preg_split( '#(?=<div class="nyxify-bot(?: nyxify-bot--empty)?">)#', $html ) as $part ) {
			if ( is_string( $part ) && str_contains( $part, '<h3>' . $label . '</h3>' ) ) {
				return $part;
			}
		}

		return '';
	}

	public function test_one_block_per_platform_of_the_plan_with_open_forms(): void {
		$html = $this->rendered( self::store_status() );

		self::assertSame( array( self::CORE_URL . '/api/v1/bots' ), array_column( $this->requests, 'url' ) );
		self::assertStringContainsString( '<section class="nyxify-card nyxify-bots-card" aria-labelledby="nyxify-bots-heading">', $html );
		self::assertStringContainsString( '<h2 id="nyxify-bots-heading">Bots</h2>', $html );
		self::assertStringContainsString( 'A bot token is sent once to Nyxify and is not stored on this site.', $html );
		self::assertLessThan( (int) strpos( $html, '<h3>Bale</h3>' ), (int) strpos( $html, '<h3>Telegram</h3>' ) );
		self::assertLessThan( (int) strpos( $html, '<h3>Rubika</h3>' ), (int) strpos( $html, '<h3>Bale</h3>' ) );

		$block = self::block( $html, 'Bale' );
		self::assertStringStartsWith( '<div class="nyxify-bot nyxify-bot--empty">', $block );
		self::assertStringContainsString( '<span class="nyxify-bot__avatar" aria-hidden="true">B</span>', $block );
		self::assertStringContainsString( '<span class="nyxify-pill nyxify-pill--off">Not connected</span>', $block );
		self::assertStringNotContainsString( 'No bot is connected.', $block );
		self::assertStringContainsString( '<input type="hidden" name="action" value="nyxify_register_bot" />', $block );
		self::assertStringContainsString( 'name="_nyxify_nonce" value="nonce-for-nyxify_register_bot"', $block );
		self::assertStringContainsString( '<input type="hidden" name="nyxify_platform" value="bale" />', $block );
		self::assertMatchesRegularExpression( '#<input type="password" id="nyxify-bot-token-bale" name="nyxify_bot_token" [^>]*autocomplete="off"#', $block );
		self::assertDoesNotMatchRegularExpression( '#name="nyxify_bot_token"[^>]*\bvalue=#', $block );
		self::assertStringContainsString( '>Connect bot</button>', $block );
		self::assertStringNotContainsString( 'data-nyxify-confirm', $block );
		self::assertStringNotContainsString( '<details', $block );
	}

	public function test_the_avatar_is_the_first_letter_of_the_translated_name(): void {
		Functions\when( '__' )->alias( fn( string $text ): string => 'Telegram' === $text ? 'تلگرام' : $text );

		$block = self::block( $this->rendered( self::store_status( array( 'telegram' ) ) ), 'تلگرام' );

		self::assertStringContainsString( '<span class="nyxify-bot__avatar" aria-hidden="true">ت</span>', $block );
	}

	public function test_only_the_plans_platforms(): void {
		$html = $this->rendered( self::store_status( array( 'bale' ) ) );

		self::assertStringContainsString( '<h3>Bale</h3>', $html );
		self::assertStringNotContainsString( '<h3>Telegram</h3>', $html );
		self::assertStringNotContainsString( '<h3>Rubika</h3>', $html );
	}

	public function test_an_active_bot_with_its_link_remove_and_folded_replace(): void {
		$this->bots( array( self::bot() ) );

		$block = self::block( $this->rendered( self::store_status() ), 'Telegram' );

		self::assertStringContainsString( '<a href="https://t.me/nyxify_shop_bot" target="_blank" rel="noopener noreferrer" dir="ltr">@nyxify_shop_bot</a>', $block );
		self::assertStringStartsWith( '<div class="nyxify-bot">', $block );
		self::assertStringContainsString( '<br /><span class="description">Nyxify Shop</span>', $block );
		self::assertStringContainsString( '<span class="nyxify-pill nyxify-pill--ok">Active</span>', $block );
		self::assertMatchesRegularExpression( '#<dt>Webhook</dt>\s*<dd>Set</dd>#', $block );
		self::assertMatchesRegularExpression( '#<dt>Set up</dt>\s*<dd>2026-10-07</dd>#', $block );
		self::assertStringNotContainsString( 'Check again', $block );

		self::assertMatchesRegularExpression( '#<form[^>]*class="nyxify-bot-remove-form" data-nyxify-confirm="Remove the Telegram bot\? It stops answering your customers\.">#', $block );
		self::assertStringContainsString( '<input type="hidden" name="action" value="nyxify_remove_bot" />', $block );
		self::assertStringContainsString( '<button type="submit" class="button-link button-link-delete">Remove bot</button>', $block );

		self::assertMatchesRegularExpression( '#<details class="nyxify-replace-bot">\s*<summary>Replace with another bot</summary>\s*<form[^>]*class="nyxify-bot-token-form nyxify-field" data-nyxify-confirm="Replace the Telegram bot with the bot of this token\?#', $block );
		self::assertStringContainsString( '>Replace bot</button>', $block );
	}

	public function test_bale_links_and_rubika_does_not(): void {
		$this->bots(
			array(
				self::bot(
					array(
						'platform' => 'bale',
						'username' => 'ShopBaleBot',
					)
				),
				self::bot(
					array(
						'platform' => 'rubika',
						'username' => 'shop_rubika_bot',
					)
				),
			)
		);

		$html = $this->rendered( self::store_status() );

		self::assertStringContainsString( 'href="https://ble.ir/ShopBaleBot"', self::block( $html, 'Bale' ) );
		$rubika = self::block( $html, 'Rubika' );
		self::assertStringContainsString( '<code dir="ltr">@shop_rubika_bot</code>', $rubika );
		self::assertStringNotContainsString( '<a href', $rubika );
	}

	public function test_a_pending_bot_offers_check_again(): void {
		$this->bots(
			array(
				self::bot(
					array(
						'status'         => 'pending',
						'webhook_active' => false,
						'deployed_at'    => null,
					)
				),
			)
		);

		$block = self::block( $this->rendered( self::store_status() ), 'Telegram' );

		self::assertStringContainsString( '<span class="nyxify-pill nyxify-pill--info nyxify-pill--busy">Setting up…</span>', $block );
		self::assertStringContainsString( '<p class="nyxify-callout nyxify-callout--info">', $block );
		self::assertStringContainsString( '<a href="https://shop.example.com/wp-admin/admin.php?page=nyxify">Check again</a>', $block );
		self::assertMatchesRegularExpression( '#<dt>Webhook</dt>\s*<dd>Not set</dd>#', $block );
		self::assertStringNotContainsString( 'Set up</dt>', $block );
	}

	public function test_a_failed_bot_shows_the_translated_message_and_the_escaped_details(): void {
		$this->bots(
			array(
				self::bot(
					array(
						'status'         => 'error',
						'webhook_active' => false,
						'last_error'     => 'Bad Request: <b>bad webhook</b> & more',
					)
				),
			)
		);

		$block = self::block( $this->rendered( self::store_status() ), 'Telegram' );

		self::assertStringContainsString( '<span class="nyxify-pill nyxify-pill--error">Failed</span>', $block );
		self::assertStringContainsString( '<div class="nyxify-callout nyxify-callout--error">', $block );
		self::assertStringContainsString( 'Setting up the bot failed. Nyxify tries again every hour, up to five times', $block );
		self::assertStringContainsString( 'Details: <code dir="ltr">Bad Request: &lt;b&gt;bad webhook&lt;/b&gt; &amp; more</code>', $block );
		self::assertStringNotContainsString( '<b>', $block );
	}

	public function test_core_strings_are_escaped(): void {
		$this->bots(
			array(
				self::bot(
					array(
						'username'    => '<script>x</script>',
						'name'        => '<img src=x onerror=alert(1)>',
						'status'      => 'paused',
						'deployed_at' => 'not a date <u>',
					)
				),
			)
		);

		$block = self::block( $this->rendered( self::store_status() ), 'Telegram' );

		self::assertStringNotContainsString( '<script>', $block );
		self::assertStringNotContainsString( '<img', $block );
		self::assertStringNotContainsString( '<u>', $block );
		self::assertStringContainsString( '<code dir="ltr">@&lt;script&gt;x&lt;/script&gt;</code>', $block );
		self::assertStringContainsString( '<span class="nyxify-pill nyxify-pill--off">paused</span>', $block );
		self::assertMatchesRegularExpression( '#<dt>Set up</dt>\s*<dd>not a date &lt;u&gt;</dd>#', $block );
	}

	public function test_a_bot_without_names(): void {
		$this->bots(
			array(
				self::bot(
					array(
						'username' => null,
						'name'     => null,
					)
				),
			)
		);

		self::assertMatchesRegularExpression( '#<dt>Bot</dt>\s*<dd>&mdash;</dd>#', self::block( $this->rendered( self::store_status() ), 'Telegram' ) );
	}

	public function test_a_bot_outside_the_plan_can_only_be_removed(): void {
		$this->bots( array( self::bot( array( 'platform' => 'rubika' ) ) ) );

		$html  = $this->rendered( self::store_status( array( 'telegram' ) ) );
		$block = self::block( $html, 'Rubika' );

		self::assertLessThan( (int) strpos( $html, '<h3>Rubika</h3>' ), (int) strpos( $html, '<h3>Telegram</h3>' ) );
		self::assertStringContainsString( '<span class="nyxify-pill nyxify-pill--warning">Not in your plan</span>', $block );
		self::assertStringContainsString( '<p class="nyxify-callout nyxify-callout--warning">This messenger isn’t in your plan, so the bot doesn’t work.', $block );
		self::assertStringContainsString( 'nyxify-bot-remove-form', $block );
		self::assertStringNotContainsString( 'nyxify-bot-token-form', $block );
		self::assertStringNotContainsString( '<details', $block );
	}

	public function test_a_bot_of_an_unknown_platform_is_shown_without_forms(): void {
		$this->bots( array( self::bot( array( 'platform' => 'eitaa' ) ) ) );

		$block = self::block( $this->rendered( self::store_status( array( 'telegram', 'eitaa' ) ) ), 'eitaa' );

		self::assertStringContainsString( '@nyxify_shop_bot', $block );
		self::assertStringContainsString( '<span class="nyxify-bot__avatar" aria-hidden="true">e</span>', $block );
		self::assertStringNotContainsString( '<form', $block );
		self::assertStringNotContainsString( '<a href', $block );
	}

	public function test_a_plan_without_messengers(): void {
		$html = $this->rendered( self::store_status( array() ) );

		self::assertStringContainsString( 'Your plan doesn’t include any messenger.', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_an_inactive_store_sends_nothing(): void {
		$html = $this->rendered( self::store_status( array( 'telegram' ), 'suspended' ) );

		self::assertSame( array(), $this->requests );
		self::assertStringContainsString( 'The bots can be managed here once the store is active on Nyxify again.', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_a_network_failure_is_explained(): void {
		$this->bots_response = new \stdClass();

		$html = $this->rendered( self::store_status() );

		self::assertStringContainsString( 'Could not reach the Nyxify server.', $html );
		self::assertStringNotContainsString( '<form', $html );
	}

	public function test_a_list_error_is_explained(): void {
		$this->bots_response = self::response(
			500,
			self::json(
				array(
					'message'    => 'raw core message',
					'error_code' => 'internal_server_error',
				)
			)
		);

		$html = $this->rendered( self::store_status() );

		self::assertStringContainsString( '<div class="notice notice-error inline">', $html );
		self::assertStringContainsString( 'The Nyxify server had an internal error.', $html );
		self::assertStringContainsString( '<code dir="ltr">req-core-0003</code>', $html );
		self::assertStringNotContainsString( 'raw core message', $html );
		self::assertStringNotContainsString( '<form', $html );
	}
}
