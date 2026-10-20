<?php
/**
 * The bot appearance forms' handler.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance;

use Nyxify\Features\Appearance\Tabs\AppearanceTab;
use Nyxify\Features\Appearance\Tabs\Fields;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Admin\Notice;
use Nyxify\Shared\Admin\Notices;
use Nyxify\Shared\Http\CoreError;

/**
 * Handles `admin-post.php?action=nyxify_save_appearance`: checks the capability and nonce, reads the tab's form, saves
 * its part when every field passes the tab's checks, and redirects back to the tab with a one-time notice.
 *
 * Whenever nothing was saved (a field failed a check, the core refused the part or another change came first), the
 * owner's input goes back into the form (FormDraft), with the core's `422` mapped to the refused fields.
 */
final class AppearanceSaveHandler {

	/**
	 * Admin action name of the forms.
	 */
	public const ACTION = 'save_appearance';

	/**
	 * Name of the hidden field holding the tab (its part).
	 */
	public const FIELD_TAB = 'nyxify_tab';

	/**
	 * Name of the hidden field holding the version the form was loaded on.
	 */
	public const FIELD_VERSION = 'nyxify_version';

	/**
	 * Name of the hidden field holding the part's hash as the form loaded it.
	 */
	public const FIELD_HASH = 'nyxify_hash';

	/**
	 * Success code of the notice.
	 */
	public const SAVED = 'appearance_saved';

	private const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

	/**
	 * Saves a part: `( string $part, ?array $value, int $version, string $hash ) => ?CoreError`, e.g.
	 * AppearanceService::save().
	 *
	 * @var callable(string, array<string, mixed>|null, int, string): ?CoreError
	 */
	private $save;

	/**
	 * The page's tabs.
	 *
	 * @var list<AppearanceTab>
	 */
	private array $tabs;

	/**
	 * Constructor.
	 *
	 * @param callable        $save Saves a part; null on success, the error otherwise.
	 * @param AppearanceTab[] $tabs The page's tabs.
	 *
	 * @phpstan-param callable(string, array<string, mixed>|null, int, string): ?CoreError $save
	 * @phpstan-param list<AppearanceTab> $tabs
	 */
	public function __construct( callable $save, array $tabs ) {
		$this->save = $save;
		$this->tabs = $tabs;
	}

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . AdminAction::hook_name( self::ACTION ), array( $this, 'handle' ) );
	}

	/**
	 * Handles a form and redirects (never returns).
	 */
	public function handle(): void {
		AdminAction::verify( self::ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is checked by verify(); the tab, version and hash are matched against known values and patterns, and the tab's read() sanitizes its own fields.
		$part    = is_string( $_POST[ self::FIELD_TAB ] ?? null ) ? sanitize_key( wp_unslash( $_POST[ self::FIELD_TAB ] ) ) : '';
		$version = is_string( $_POST[ self::FIELD_VERSION ] ?? null ) ? wp_unslash( $_POST[ self::FIELD_VERSION ] ) : '';
		$hash    = is_string( $_POST[ self::FIELD_HASH ] ?? null ) ? wp_unslash( $_POST[ self::FIELD_HASH ] ) : '';
		$tab     = $this->tab( $part );
		$input   = null !== $tab && is_array( $_POST[ Fields::input_name( $tab->part() ) ] ?? null ) ? wp_unslash( $_POST[ Fields::input_name( $tab->part() ) ] ) : array();
		// phpcs:enable

		if ( null === $tab || 1 !== preg_match( '/^\d{1,9}$/D', $version ) || 1 !== preg_match( self::HASH_PATTERN, $hash ) ) {
			Notices::push( Notice::error( new CoreError( CoreError::INVALID_PAYLOAD ) ) );
			$this->redirect( '' );
		}

		$read = $tab->read( $input );
		if ( array() !== $read->errors ) {
			( new FormDraft( $tab->part(), $read->values, $read->errors ) )->keep();
			Notices::push( Notice::error( new CoreError( CoreError::APPEARANCE_FORM_INVALID ) ) );
			$this->redirect( $tab->part() );
		}

		$error = ( $this->save )( $tab->part(), $read->part, (int) $version, $hash );
		if ( null === $error ) {
			Notices::push( Notice::success( self::SAVED ) );
		} else {
			$refused = 'validation_error' === $error->code ? self::refused_fields( $tab->part(), $error->fields ) : array();
			( new FormDraft( $tab->part(), $read->values, $refused, AppearanceService::VERSION_CONFLICT === $error->code ) )->keep();
			Notices::push( Notice::error( $error ) );
		}

		$this->redirect( $tab->part() );
	}

	/**
	 * The fields of the tab the core's `422` names (`body.settings.contact.address.fa` is `address.fa`); anything else
	 * it names, the part itself included, marks the form as a whole ('').
	 *
	 * @param string   $part   The tab's part.
	 * @param string[] $fields The `422`'s fields.
	 * @return array<string, string>
	 *
	 * @phpstan-param list<string> $fields
	 */
	public static function refused_fields( string $part, array $fields ): array {
		$prefix  = 'body.settings.' . $part . '.';
		$refused = array();
		foreach ( $fields as $field ) {
			$key             = str_starts_with( $field, $prefix ) ? substr( $field, strlen( $prefix ) ) : '';
			$key             = 1 === preg_match( '/^[a-z0-9_.]{1,80}$/D', $key ) ? $key : '';
			$refused[ $key ] = Fields::REJECTED;
		}

		return array() === $refused ? array( '' => Fields::REJECTED ) : $refused;
	}

	/**
	 * The tab of a part, or null when there is none.
	 *
	 * @param string $part Part name.
	 */
	private function tab( string $part ): ?AppearanceTab {
		foreach ( $this->tabs as $tab ) {
			if ( $tab->part() === $part ) {
				return $tab;
			}
		}

		return null;
	}

	/**
	 * Redirects to a tab of the page (never returns).
	 *
	 * @param string $tab The tab, or '' for the first one.
	 */
	private function redirect( string $tab ): never {
		wp_safe_redirect( AppearancePage::url( $tab ) );
		exit;
	}
}
