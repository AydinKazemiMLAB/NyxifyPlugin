<?php
/**
 * Owner-facing messages of the core's and the client's error codes.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Shared\Http;

/**
 * A translated message for every `error_code` the plugin can get, so the raw response is never shown.
 *
 * CORE_CODES lists what the core can answer on the plugin's endpoints: the `error_code` enums of
 * `docs/core-api/openapi.json` plus the global codes the spec leaves out (422, 413, 500 from decryption, 401). A test
 * fails when the spec has a code missing here, or when any code has no message.
 */
final class ErrorMessages {

	/**
	 * Error codes of the core on the plugin's endpoints.
	 */
	public const CORE_CODES = array(
		// Global.
		'bad_request',
		'unauthorized',
		'method_not_allowed',
		'validation_error',
		'request_too_large',
		'internal_server_error',
		'decryption_failed',
		'too_many_attempts',
		// HMAC.
		'invalid_signature_headers',
		'request_timestamp_expired',
		'invalid_request_signature',
		'replayed_request',
		// Activation, store and subscription.
		'invalid_license_key',
		'license_revoked',
		'license_already_used',
		'store_already_registered',
		'store_not_active',
		'no_active_subscription',
		'platform_not_in_plan',
		'woocommerce_authentication_failed',
		'woocommerce_unreachable',
		'woocommerce_request_rejected',
		'woocommerce_bad_response',
		// Bots.
		'bot_already_registered',
		'bot_not_found',
		'customer_not_found',
		'invalid_bot_token',
		'messenger_api_unavailable',
	);

	/**
	 * The client's own codes (CoreError constants).
	 */
	public const PLUGIN_CODES = array(
		CoreError::NOT_ACTIVATED,
		CoreError::CONNECTION_UNREADABLE,
		CoreError::INVALID_BASE_URL,
		CoreError::INVALID_PAYLOAD,
		CoreError::NETWORK_ERROR,
		CoreError::UNEXPECTED_REDIRECT,
		CoreError::INVALID_RESPONSE,
		CoreError::UNKNOWN_ERROR,
		CoreError::LICENSE_KEY_FORMAT,
		CoreError::SITE_NOT_HTTPS,
		CoreError::REST_URL_MISMATCH,
		CoreError::REST_KEY_FAILED,
		CoreError::CONNECTION_NOT_SAVED,
		CoreError::SECRET_NOT_SAVED,
		CoreError::BOT_TOKEN_FORMAT,
		CoreError::PIN_NOT_SAVED,
	);

	/**
	 * The message for a failed call.
	 *
	 * @param CoreError $error The failure.
	 */
	public static function for_error( CoreError $error ): string {
		return self::message( $error->code, $error->status );
	}

	/**
	 * The message for a code; a code without one (e.g. added to the core later) falls back on the HTTP status.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status, or 0.
	 */
	public static function message( string $code, int $status = 0 ): string {
		$messages = self::messages();

		return $messages[ $code ] ?? $messages[ self::fallback_code( $status ) ];
	}

	/**
	 * Every message, by code.
	 *
	 * @return array<string, string>
	 */
	public static function messages(): array {
		return array(
			// The client's own codes.
			CoreError::NOT_ACTIVATED            => __( 'This store is not connected to Nyxify yet. Enter your license key to activate it.', 'nyxify' ),
			CoreError::CONNECTION_UNREADABLE    => __( 'The saved connection to Nyxify can no longer be read, probably because the site’s security keys (salts) changed. Enter your license key again to reconnect.', 'nyxify' ),
			CoreError::INVALID_BASE_URL         => __( 'The Nyxify server address is invalid. Check NYXIFY_API_BASE_URL in wp-config.php: it must be an https address.', 'nyxify' ),
			CoreError::INVALID_PAYLOAD          => __( 'The data could not be prepared for sending to Nyxify.', 'nyxify' ),
			CoreError::NETWORK_ERROR            => __( 'Could not reach the Nyxify server. Check this site’s internet connection and try again in a few minutes.', 'nyxify' ),
			CoreError::UNEXPECTED_REDIRECT      => __( 'The Nyxify server answered with a redirect, which is not followed. Check the server address.', 'nyxify' ),
			CoreError::INVALID_RESPONSE         => __( 'The Nyxify server sent an unexpected answer. Try again later.', 'nyxify' ),
			CoreError::UNKNOWN_ERROR            => __( 'Something went wrong while talking to the Nyxify server. Try again later.', 'nyxify' ),
			CoreError::LICENSE_KEY_FORMAT       => __( 'This is not a Nyxify license key. A license key looks like NX-XXXXXX-XXXXXX-XXXXXX-XXXXXX.', 'nyxify' ),
			CoreError::SITE_NOT_HTTPS           => __( 'Nyxify needs this site to use https. Change the WordPress Address and Site Address in Settings → General to https, then try again.', 'nyxify' ),
			CoreError::REST_URL_MISMATCH        => __( 'This site’s REST API is not on the same address as the site, so Nyxify cannot use it. Make the WordPress Address and Site Address in Settings → General use the same scheme, host and port.', 'nyxify' ),
			CoreError::REST_KEY_FAILED          => __( 'The WooCommerce REST API key Nyxify needs could not be created. Try again; if it keeps failing, check the site’s database error log.', 'nyxify' ),
			CoreError::CONNECTION_NOT_SAVED     => __( 'The store was activated on Nyxify, but the connection could not be saved on this site. Enter your license key again to activate it once more.', 'nyxify' ),
			CoreError::SECRET_NOT_SAVED         => __( 'Nyxify issued a new shared secret, but it could not be saved on this site. The current secret still works: rotate it again now.', 'nyxify' ),
			CoreError::BOT_TOKEN_FORMAT         => __( 'This does not look like a bot token. Paste the token the messenger gave you when you created the bot, without spaces.', 'nyxify' ),
			CoreError::PIN_NOT_SAVED            => __( 'Nyxify issued a new admin PIN, but it could not be saved on this site, and the previous PIN no longer works. Issue a new PIN now.', 'nyxify' ),

			// Global.
			'bad_request'                       => __( 'The Nyxify server rejected the request as invalid. Update the plugin and try again.', 'nyxify' ),
			'unauthorized'                      => __( 'The Nyxify server did not accept this store’s credentials. Activate the store again with your license key.', 'nyxify' ),
			'method_not_allowed'                => __( 'The Nyxify server does not accept this request. Update the plugin.', 'nyxify' ),
			'validation_error'                  => __( 'Some of the data sent to Nyxify is invalid. Check it and try again.', 'nyxify' ),
			'request_too_large'                 => __( 'The data is too large for the Nyxify server.', 'nyxify' ),
			'internal_server_error'             => __( 'The Nyxify server had an internal error. Try again later.', 'nyxify' ),
			'decryption_failed'                 => __( 'The Nyxify server could not read this store’s saved credentials. Activate the store again with your license key.', 'nyxify' ),
			'too_many_attempts'                 => __( 'Too many attempts. Wait a few minutes and try again.', 'nyxify' ),

			// HMAC.
			'invalid_signature_headers'         => __( 'The request’s signature was missing or malformed. Activate the store again with your license key.', 'nyxify' ),
			'request_timestamp_expired'         => __( 'The request was rejected because this server’s clock is off by more than five minutes. Correct the server time and try again.', 'nyxify' ),
			'invalid_request_signature'         => __( 'The request’s signature did not match; the shared secret may be out of date. Activate the store again with your license key.', 'nyxify' ),
			'replayed_request'                  => __( 'The same request reached the Nyxify server twice and was rejected. Try again.', 'nyxify' ),

			// Activation, store and subscription.
			'invalid_license_key'               => __( 'This license key is not valid. Check it and try again.', 'nyxify' ),
			'license_revoked'                   => __( 'This license key has been revoked. Contact Nyxify support.', 'nyxify' ),
			'license_already_used'              => __( 'This license key is already used by another website.', 'nyxify' ),
			'store_already_registered'          => __( 'This website is already activated with another license key.', 'nyxify' ),
			'store_not_active'                  => __( 'This store is deactivated or suspended on Nyxify. Activate it again with your license key; if the license was revoked, contact Nyxify support.', 'nyxify' ),
			'no_active_subscription'            => __( 'Your Nyxify subscription is not active or has expired. Renew it to continue.', 'nyxify' ),
			'platform_not_in_plan'              => __( 'Your Nyxify plan does not include this messenger. Upgrade your plan to use it.', 'nyxify' ),
			'woocommerce_authentication_failed' => __( 'The Nyxify server could not sign in to this store’s WooCommerce REST API with the new key. Make sure no security plugin blocks the REST API, then try again.', 'nyxify' ),
			'woocommerce_unreachable'           => __( 'The Nyxify server could not reach this store. The site must be public, use https, and its REST API must not redirect.', 'nyxify' ),
			'woocommerce_request_rejected'      => __( 'This store’s WooCommerce REST API rejected the Nyxify server’s request. Check the firewall and security plugins.', 'nyxify' ),
			'woocommerce_bad_response'          => __( 'This store’s WooCommerce REST API sent the Nyxify server an unexpected answer. Check for plugins that change REST API responses.', 'nyxify' ),

			// Bots.
			'bot_already_registered'            => __( 'This bot is already connected to another Nyxify store. Create a new bot for this shop, or remove this one from the other store first.', 'nyxify' ),
			'bot_not_found'                     => __( 'No bot is connected for this messenger.', 'nyxify' ),
			'customer_not_found'                => __( 'This person is no longer a bot admin of this store.', 'nyxify' ),
			'invalid_bot_token'                 => __( 'The messenger rejected this bot token. Check it and try again.', 'nyxify' ),
			'messenger_api_unavailable'         => __( 'The messenger’s servers did not respond. Try again in a few minutes.', 'nyxify' ),
		);
	}

	/**
	 * The code whose message stands in for an unknown code with this HTTP status.
	 *
	 * @param int $status HTTP status, or 0.
	 */
	private static function fallback_code( int $status ): string {
		if ( 0 === $status ) {
			return CoreError::NETWORK_ERROR;
		}

		if ( 413 === $status ) {
			return 'request_too_large';
		}

		if ( 429 === $status ) {
			return 'too_many_attempts';
		}

		return $status >= 500 ? 'internal_server_error' : CoreError::UNKNOWN_ERROR;
	}
}
