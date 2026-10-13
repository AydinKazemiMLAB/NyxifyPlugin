<?php
/**
 * The settings page mockup: runs the plugin's real classes with stubbed WordPress functions and canned core answers,
 * and writes one self-contained HTML file with every state in both languages, the real stylesheet and script, and a
 * small WordPress admin around them (bin/mockup-wp.css). Run `composer mockup` after changing the page; the sample
 * data and the clock are fixed, so only real changes show in the diff.
 *
 * Usage: php bin/mockup.php [<out file>], by default docs/mockups/admin-page.html.
 */

declare(strict_types=1);

$plugin = dirname( __DIR__ );
$out    = $argv[1] ?? $plugin . '/docs/mockups/admin-page.html';

require $plugin . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'NYXIFY_VERSION', 'preview' );

// The status is read "now": 2026-10-12 09:00 UTC (12:30 in Tehran), so days left and "checked at" stay the same, and
// the request id of a failed request is always the same one.
eval( 'namespace Nyxify\Features\Store; function time(): int { return 1791795600; }' );
eval( 'namespace Nyxify\Shared\Http; function random_bytes( int $length ): string { return substr( hex2bin( "81c0d4e2a9f3b5c7d1e3f5a7" ), 0, $length ); }' );

interface WC_Logger_Interface {
	public function log( $level, $message, $context = array() );
}

final class PreviewLogger implements WC_Logger_Interface {
	public function log( $level, $message, $context = array() ) {}
}

$GLOBALS['pv'] = array(
	'locale'     => 'en_US',
	'options'    => array(),
	'transients' => array(),
	'answers'    => array(),
	'po'         => array(),
);

/** Loads msgid => msgstr (with "ctx\4" keys and plurals as arrays) from a .po file. */
function pv_load_po( string $file ): array {
	$entries = array();
	foreach ( preg_split( '/\n\n/', (string) file_get_contents( $file ) ) as $block ) {
		$ctx = preg_match( '/^msgctxt "(.*)"$/m', $block, $m ) ? $m[1] . "\4" : '';
		if ( ! preg_match( '/^msgid "(.*)"$/m', $block, $id ) || '' === $id[1] ) {
			continue;
		}
		$key = $ctx . stripcslashes( $id[1] );
		if ( preg_match( '/^msgstr\[0\] "(.*)"$/m', $block, $one ) ) {
			preg_match( '/^msgstr\[1\] "(.*)"$/m', $block, $many );
			$entries[ $key ] = array( stripcslashes( $one[1] ), stripcslashes( $many[1] ?? $one[1] ) );
		} elseif ( preg_match( '/^msgstr "(.*)"$/m', $block, $str ) && '' !== $str[1] ) {
			$entries[ $key ] = stripcslashes( $str[1] );
		}
	}
	return $entries;
}

function __( $text, $domain = 'default' ) {
	$t = $GLOBALS['pv']['po'][ $text ] ?? $text;
	return is_string( $t ) ? $t : $text;
}
function _x( $text, $context, $domain = 'default' ) {
	$t = $GLOBALS['pv']['po'][ $context . "\4" . $text ] ?? $text;
	return is_string( $t ) ? $t : $text;
}
function _n( $single, $plural, $number, $domain = 'default' ) {
	$t = $GLOBALS['pv']['po'][ $single ] ?? null;
	if ( is_array( $t ) ) {
		return $number > 1 ? $t[1] : $t[0];
	}
	return 1 === $number ? $single : $plural;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function esc_html__( $text, $domain = 'default' ) {
	return esc_html( __( $text ) );
}
function esc_attr__( $text, $domain = 'default' ) {
	return esc_attr( __( $text ) );
}
function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( __( $text ) );
}
function esc_attr_e( $text, $domain = 'default' ) {
	echo esc_attr( __( $text ) );
}
function get_option( $name, $default = false ) {
	return $GLOBALS['pv']['options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['pv']['options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['pv']['options'][ $name ] );
	return true;
}
function get_transient( $name ) {
	return $GLOBALS['pv']['transients'][ $name ] ?? false;
}
function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['pv']['transients'][ $name ] = $value;
	return true;
}
function delete_transient( $name ) {
	unset( $GLOBALS['pv']['transients'][ $name ] );
	return true;
}
function current_user_can( $capability ) {
	return true;
}
function get_current_user_id() {
	return 1;
}
function admin_url( $path = '' ) {
	return 'https://shop.example.com/wp-admin/' . $path;
}
function wp_nonce_field( $action, $name ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce" />';
}
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}
function wp_timezone() {
	return new DateTimeZone( 'Asia/Tehran' );
}
function wp_date( $format, $timestamp = null ) {
	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( $format );
}
function determine_locale() {
	return $GLOBALS['pv']['locale'];
}
function apply_filters( $hook, $value, ...$args ) {
	return $value;
}
function wp_json_encode( $data, $flags = 0 ) {
	return json_encode( $data, $flags );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_get_environment_type() {
	return 'production';
}
function wp_salt( $scheme = 'auth' ) {
	return 'preview salt';
}
function is_wp_error( $thing ) {
	return $thing instanceof stdClass;
}
function wp_remote_request( $url, $args = array() ) {
	$path = (string) parse_url( $url, PHP_URL_PATH );
	return $GLOBALS['pv']['answers'][ $path ] ?? new stdClass();
}
function wp_safe_remote_request( $url, $args = array() ) {
	return wp_remote_request( $url, $args );
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
function wp_remote_retrieve_header( $response, $header ) {
	return $response['headers'][ strtolower( $header ) ] ?? '';
}

function pv_answer( int $code, $body ): array {
	return array(
		'response' => array( 'code' => $code ),
		'body'     => json_encode( $body ),
		'headers'  => array( 'x-request-id' => 'req-81c0d4e2a9f3' ),
	);
}

function pv_status( string $expires, array $platforms, array $catalog ): array {
	return array(
		'store_id'     => 'shop_8f2a0c1d3e4b5a69',
		'website_url'  => 'https://shop.nyxify.nyxon.tech',
		'status'       => 'active',
		'license'      => array(
			'key_prefix'   => 'NX-ABCDEF',
			'activated_at' => '2026-09-13T06:30:00',
		),
		'subscription' => array(
			'plan'              => 'free',
			'plan_name'         => 'Free',
			'expires_at'        => $expires,
			'allowed_platforms' => $platforms,
			'max_products'      => 50,
		),
		'catalog'      => $catalog + array(
			'status'          => 'succeeded',
			'started_at'      => '2026-10-12T07:10:00',
			'last_synced_at'  => '2026-10-12T07:11:00',
			'error'           => null,
			'limit_reached'   => false,
			'active_products' => 19,
		),
	);
}

function pv_bot( string $platform, string $status, array $changes = array() ): array {
	return $changes + array(
		'platform'       => $platform,
		'username'       => 'nyxify_' . $platform . '_bot',
		'name'           => 'Nyxify Shop',
		'status'         => $status,
		'webhook_active' => 'active' === $status,
		'last_error'     => null,
		'deployed_at'    => 'active' === $status ? '2026-10-12T07:00:00' : null,
	);
}

$admins = array(
	array(
		'id'                 => 7,
		'messenger_platform' => 'telegram',
		'messenger_user_id'  => '1001',
		'first_name'         => 'Sara',
		'last_name'          => 'Ahmadi',
		'username'           => 'sara_shop',
	),
	array(
		'id'                 => 12,
		'messenger_platform' => 'bale',
		'messenger_user_id'  => '55501',
		'first_name'         => 'Reza',
		'last_name'          => null,
		'username'           => null,
	),
);

$states = array(
	'setup'       => array( 'connected' => false ),
	'setup-error' => array(
		'connected' => false,
		'error'     => 'invalid_license_key',
	),
	'lost'      => array(
		'connected'  => false,
		'unreadable' => true,
	),
	'active'      => array(
		'connected' => true,
		'notice'    => 'activated',
		'answers'   => array(
			'/api/v1/store/status' => pv_answer( 200, pv_status( '2027-10-17T09:00:00', array( 'telegram', 'bale', 'rubika' ), array() ) ),
			'/api/v1/bots'         => pv_answer( 200, array( pv_bot( 'telegram', 'active' ), pv_bot( 'rubika', 'active' ) ) ),
			'/api/v1/bots/admins'  => pv_answer( 200, $admins ),
		),
	),
	'attention'   => array(
		'connected' => true,
		'limit'     => true,
		'answers'   => array(
			'/api/v1/store/status' => pv_answer(
				200,
				pv_status(
					'2026-10-17T09:00:00',
					array( 'telegram', 'bale' ),
					array(
						'status'          => 'failed',
						'error'           => 'WooCommerce answered with an error while Nyxify read the catalog.',
						'limit_reached'   => true,
						'active_products' => 50,
					)
				)
			),
			'/api/v1/bots'         => pv_answer(
				200,
				array(
					pv_bot( 'telegram', 'pending' ),
					pv_bot( 'bale', 'error', array( 'last_error' => 'updateBotEndpoints: InvalidUrl' ) ),
					pv_bot( 'rubika', 'active' ),
				)
			),
			'/api/v1/bots/admins'  => pv_answer( 200, array() ),
		),
	),
	'unreachable' => array(
		'connected' => true,
		'answers'   => array(),
	),
);

$store_secret = 'preview site secret';

$labels = array(
	'setup'       => 'Not connected',
	'setup-error' => 'Activation failed',
	'lost'        => 'Connection lost',
	'active'      => 'Connected, all fine',
	'attention'   => 'Connected, needs attention',
	'unreachable' => 'Server unreachable',
);

$templates = '';
foreach ( array( 'en', 'fa' ) as $lang ) {
	foreach ( $states as $name => $state ) {
		$GLOBALS['pv']['locale']     = 'fa' === $lang ? 'fa_IR' : 'en_US';
		$GLOBALS['pv']['po']         = 'fa' === $lang ? pv_load_po( $plugin . '/languages/nyxify-fa_IR.po' ) : array();
		$GLOBALS['pv']['options']    = array();
		$GLOBALS['pv']['transients'] = array();
		$GLOBALS['pv']['answers']    = $state['answers'] ?? array();

		$store = new Nyxify\Shared\Options\SecretStore( Nyxify\Shared\Security\Encryptor::from_secret( $store_secret ) );
		if ( $state['connected'] ) {
			$store->save_connection( new Nyxify\Shared\Options\Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );
			$store->save_admin_pin( '042917' );
		}
		if ( ! empty( $state['unreadable'] ) ) {
			( new Nyxify\Shared\Options\SecretStore( Nyxify\Shared\Security\Encryptor::from_secret( 'old salts' ) ) )->save_connection( new Nyxify\Shared\Options\Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'e5', 32 ) ) );
		}
		if ( isset( $state['notice'] ) ) {
			$GLOBALS['pv']['transients']['nyxify_notice_1'] = array(
				'type'       => 'success',
				'code'       => $state['notice'],
				'status'     => 0,
				'request_id' => '',
				'fields'     => array(),
			);
		}
		if ( isset( $state['error'] ) ) {
			$GLOBALS['pv']['transients']['nyxify_notice_1'] = array(
				'type'       => 'error',
				'code'       => $state['error'],
				'status'     => 400,
				'request_id' => 'req-81c0d4e2a9f3',
				'fields'     => array(),
			);
		}
		if ( ! empty( $state['limit'] ) ) {
			$GLOBALS['pv']['options']['nyxify_catalog_plan_limit'] = array(
				'count' => 3,
				'time'  => 1791789060,
			);
		}

		$logger = new Nyxify\Shared\Logging\Logger( new PreviewLogger() );
		$client = new Nyxify\Shared\Http\CoreClient( $store, 'https://api.nyxify.nyxon.tech' );
		$page   = new Nyxify\Features\Settings\SettingsPage(
			$plugin . '/nyxify.php',
			$store,
			'https://api.nyxify.nyxon.tech',
			new Nyxify\Features\Store\StatusService( $client, $logger ),
			new Nyxify\Features\Bots\BotService( $client, $logger ),
			new Nyxify\Features\Bots\AdminService( $client, $logger )
		);

		ob_start();
		$page->render();
		$body = trim( (string) ob_get_clean() );
		// Forms post nowhere in the mockup.
		$body = str_replace( 'https://shop.example.com/wp-admin/admin-post.php', '#', $body );

		$templates .= "<template id=\"{$name}-{$lang}\">\n{$body}\n</template>\n";
	}
}

$buttons = '';
foreach ( $labels as $name => $label ) {
	$buttons .= "\t\t<button type=\"button\" data-state=\"{$name}\">{$label}</button>\n";
}

$css = rtrim( (string) file_get_contents( $plugin . '/assets/css/admin.css' ) );
// The stylesheet's images and font inlined, so the mockup stays one file.
$css = (string) preg_replace_callback(
	'#url\(\.\./images/([a-z0-9-]+\.png)\)#',
	static fn( array $m ): string => 'url(data:image/png;base64,' . base64_encode( (string) file_get_contents( $plugin . '/assets/images/' . $m[1] ) ) . ')',
	$css
);
$css = (string) preg_replace_callback(
	'#url\(\.\./fonts/([a-z0-9/-]+\.woff2)\)#i',
	static fn( array $m ): string => 'url(data:font/woff2;base64,' . base64_encode( (string) file_get_contents( $plugin . '/assets/fonts/' . $m[1] ) ) . ')',
	$css
);
$js =rtrim( (string) file_get_contents( $plugin . '/assets/js/admin.js' ) );
$wp  = rtrim( (string) file_get_contents( __DIR__ . '/mockup-wp.css' ) );

$html = <<<HTML
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Nyxify admin page mockup</title>
<!--
	The settings page as the plugin renders it: the plugin's own classes ran with sample data (no real store, key or
	PIN), and its stylesheet and script are copied in below. Only the WordPress admin around the page is imitated.
	Regenerate it after changing the page instead of editing it by hand.
-->
<style>
{$wp}
</style>
<style>
/* ---- assets/css/admin.css ---- */
{$css}
</style>
</head>
<body class="wp-admin wp-core-ui js">
<div class="mock-switcher" role="toolbar" aria-label="Mockup controls">
	<strong>Mockup:</strong>
	<span><button type="button" data-lang="en">English</button> <button type="button" data-lang="fa">فارسی</button></span>
	<span>
{$buttons}	</span>
</div>
<div class="wp-adminbar"></div>
<div class="wp-shell">
	<nav class="wp-menu" aria-label="WordPress menu (mockup)">
		<div data-en="Dashboard" data-fa="پیشخوان"></div>
		<div class="open" data-en="WooCommerce" data-fa="ووکامرس"></div>
		<div class="sub" data-en="Orders" data-fa="سفارش‌ها"></div>
		<div class="sub" data-en="Settings" data-fa="پیکربندی"></div>
		<div class="sub current" data-en="Nyxify" data-fa="Nyxify"></div>
		<div data-en="Products" data-fa="محصولات"></div>
		<div data-en="Plugins" data-fa="افزونه‌ها"></div>
	</nav>
	<main class="wp-content" id="page"></main>
</div>
{$templates}
<script type="text/plain" id="nyxify-admin-js">
/* ---- assets/js/admin.js ---- */
{$js}
</script>
<script>
/*
 * Shows one state in one language (#active-fa in the address opens that one). admin.js runs again for each state,
 * so the PIN's buttons work; only its first run adds the page-wide confirmation on submit. Forms post nowhere.
 */
( function () {
	var adminJs = document.getElementById( 'nyxify-admin-js' ).textContent;
	var addListener = document.addEventListener;
	var started = false;
	var parts = location.hash.slice( 1 ).split( /-(?=en$|fa$)/ );
	var state = document.getElementById( parts[0] + '-en' ) ? parts[0] : 'active';
	var lang = 'fa' === parts[1] ? 'fa' : 'en';

	function runAdminJs() {
		if ( started ) {
			document.addEventListener = function ( type ) {
				if ( 'submit' !== type ) {
					addListener.apply( document, arguments );
				}
			};
		}
		new Function( adminJs )();
		document.addEventListener = addListener;
		started = true;
	}

	function show() {
		var page = document.getElementById( 'page' );
		var rtl = 'fa' === lang;
		document.documentElement.lang = lang;
		document.documentElement.dir = rtl ? 'rtl' : 'ltr';
		document.body.classList.toggle( 'rtl', rtl );
		document.body.classList.toggle( 'locale-fa-ir', rtl );
		document.body.classList.toggle( 'locale-en-us', ! rtl );
		page.replaceChildren( document.getElementById( state + '-' + lang ).content.cloneNode( true ) );
		document.querySelectorAll( '.wp-menu [data-en]' ).forEach( function ( item ) {
			item.textContent = item.getAttribute( 'data-' + lang );
		} );
		document.querySelectorAll( '[data-lang]' ).forEach( function ( button ) {
			button.setAttribute( 'aria-pressed', String( button.dataset.lang === lang ) );
		} );
		document.querySelectorAll( '[data-state]' ).forEach( function ( button ) {
			button.setAttribute( 'aria-pressed', String( button.dataset.state === state ) );
		} );
		try {
			history.replaceState( null, '', '#' + state + '-' + lang );
		} catch ( e ) {}
		runAdminJs();
	}

	document.querySelectorAll( '[data-lang]' ).forEach( function ( button ) {
		button.onclick = function () {
			lang = button.dataset.lang;
			show();
		};
	} );
	document.querySelectorAll( '[data-state]' ).forEach( function ( button ) {
		button.onclick = function () {
			state = button.dataset.state;
			show();
		};
	} );
	document.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
	} );
	show();
}() );
</script>
</body>
</html>

HTML;

file_put_contents( $out, $html );

echo "ok\n";
