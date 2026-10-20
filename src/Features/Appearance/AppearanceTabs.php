<?php
/**
 * The tabs of the bot appearance page.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance;

use Nyxify\Features\Appearance\Tabs\AppearanceTab;
use Nyxify\Features\Appearance\Tabs\BackButtonTab;
use Nyxify\Features\Appearance\Tabs\BotProfileTab;
use Nyxify\Features\Appearance\Tabs\CategoryStyleTab;
use Nyxify\Features\Appearance\Tabs\ContactTab;
use Nyxify\Features\Appearance\Tabs\MenuTab;
use Nyxify\Features\Appearance\Tabs\TextsTab;
use Nyxify\Features\Appearance\Tabs\WelcomeTab;

/**
 * Every tab, in the order the page shows them; the first is the default.
 */
final class AppearanceTabs {

	/**
	 * The tabs.
	 *
	 * @return non-empty-list<AppearanceTab>
	 */
	public static function all(): array {
		return array( new MenuTab(), new TextsTab(), new WelcomeTab(), new BackButtonTab(), new CategoryStyleTab(), new BotProfileTab(), new ContactTab() );
	}
}
