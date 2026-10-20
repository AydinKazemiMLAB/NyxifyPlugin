<?php
/**
 * A tab of the bot appearance page.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Appearance\TabInput;

/**
 * One part of the settings document (its name is the tab's slug), edited by one form that saves only that part.
 *
 * A tab turns the stored part into form fields (a flat map of field keys such as `address.fa` to strings), reads a
 * posted form back into a part, and prints its fields. The page prints the form around them.
 */
interface AppearanceTab {

	/**
	 * The part's name in the settings document, also the tab's slug, e.g. `contact`.
	 */
	public function part(): string;

	/**
	 * The tab's title.
	 */
	public function label(): string;

	/**
	 * The form's fields for a stored part (null when the shop keeps the bots' defaults).
	 *
	 * @param mixed $stored The part as stored.
	 * @return array<string, string>
	 */
	public function values( mixed $stored ): array;

	/**
	 * Reads the posted form (unslashed): normalizes and checks every field.
	 *
	 * @param array<array-key, mixed> $input The tab's fields from the request.
	 */
	public function read( array $input ): TabInput;

	/**
	 * Prints the fields.
	 *
	 * @param array<string, string>      $values The fields to show.
	 * @param array<string, string>      $errors Error codes by field key ('' for the form as a whole).
	 * @param array<string, string>|null $saved  The saved fields, after a conflict, to show next to the owner's.
	 */
	public function render( array $values, array $errors, ?array $saved ): void;
}
