<?php
/**
 * What the owner typed into a bot appearance form that wasn't saved.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance;

/**
 * When a form fails a check, the core refuses it or another change came first, the owner's input goes back into the
 * form with a message per field instead of being lost. Like the notices (Notices), it waits one minute for the next
 * page in a per-user transient, so nothing of it goes into the URL, and is read once.
 */
final class FormDraft {

	/**
	 * Name of a user's draft transient, followed by the user id (the uninstall deletes them by this prefix).
	 */
	public const TRANSIENT_PREFIX = 'nyxify_appearance_draft_';

	/**
	 * Seconds a draft waits for the next page.
	 */
	public const LIFETIME = 60;

	private const KEY_PATTERN  = '/^[a-z0-9_.]{0,80}$/D';
	private const CODE_PATTERN = '/^[a-z][a-z_]{0,31}$/D';

	/**
	 * The tab (its part) the input belongs to.
	 *
	 * @var string
	 */
	public readonly string $tab;

	/**
	 * The form's fields as typed (after normalizing), by field key.
	 *
	 * @var array<string, string>
	 */
	public readonly array $values;

	/**
	 * Why fields were refused, by field key ('' for the form as a whole): a code the tab has a message for.
	 *
	 * @var array<string, string>
	 */
	public readonly array $errors;

	/**
	 * Whether the part changed meanwhile, so the form shows the saved values next to the owner's.
	 *
	 * @var bool
	 */
	public readonly bool $conflict;

	/**
	 * Constructor.
	 *
	 * @param string                $tab      Tab.
	 * @param array<string, string> $values   Fields as typed.
	 * @param array<string, string> $errors   Refused fields.
	 * @param bool                  $conflict Whether the part changed meanwhile.
	 */
	public function __construct( string $tab, array $values, array $errors = array(), bool $conflict = false ) {
		$this->tab      = $tab;
		$this->values   = $values;
		$this->errors   = $errors;
		$this->conflict = $conflict;
	}

	/**
	 * Keeps the draft for the current user's next page (replacing any waiting one).
	 */
	public function keep(): void {
		$name = self::transient_name();
		if ( null !== $name ) {
			set_transient(
				$name,
				array(
					'tab'      => $this->tab,
					'values'   => $this->values,
					'errors'   => $this->errors,
					'conflict' => $this->conflict,
				),
				self::LIFETIME
			);
		}
	}

	/**
	 * The current user's waiting draft, removed once read; null when none or not a draft.
	 */
	public static function take(): ?self {
		$name = self::transient_name();
		if ( null === $name ) {
			return null;
		}

		$data = get_transient( $name );
		if ( false === $data ) {
			return null;
		}

		delete_transient( $name );

		if ( ! is_array( $data ) || ! is_string( $data['tab'] ?? null ) || ! is_bool( $data['conflict'] ?? null ) ) {
			return null;
		}

		$values = self::strings( $data['values'] ?? null, null );
		$errors = self::strings( $data['errors'] ?? null, self::CODE_PATTERN );
		if ( null === $values || null === $errors ) {
			return null;
		}

		return new self( $data['tab'], $values, $errors, $data['conflict'] );
	}

	/**
	 * A map of field keys to strings, or null when the value is not one.
	 *
	 * @param mixed       $data    Stored value.
	 * @param string|null $pattern What each string must match, or null for any.
	 * @return array<string, string>|null
	 */
	private static function strings( $data, ?string $pattern ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$strings = array();
		foreach ( $data as $key => $value ) {
			if ( ! is_string( $key ) || 1 !== preg_match( self::KEY_PATTERN, $key ) || ! is_string( $value )
				|| ( null !== $pattern && 1 !== preg_match( $pattern, $value ) )
			) {
				return null;
			}
			$strings[ $key ] = $value;
		}

		return $strings;
	}

	/**
	 * The transient of the current user, or null when nobody is logged in.
	 */
	private static function transient_name(): ?string {
		$user_id = get_current_user_id();

		return $user_id > 0 ? self::TRANSIENT_PREFIX . $user_id : null;
	}
}
