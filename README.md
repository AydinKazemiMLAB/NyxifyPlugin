# WooNyx for WooCommerce

The WordPress/WooCommerce plugin of WooNyx: it connects a WooCommerce store to the WooNyx core, which sells the
store's products through Telegram, Bale and Rubika bots. The plugin activates the store with a license key, sends
catalog and order changes to the core, and gives the store owner a settings page for the bots, the admin PIN and the
bot admins.

> Status: phase 1 (skeleton). The plugin installs, shows an empty settings page under **WooCommerce > WooNyx** and
> sends nothing to the core. Plans and progress: `docs/phase-*-plan.md`. "WooNyx" / `woonyx` is a temporary name:
> WordPress.org doesn't accept slugs starting with `woo`, so the public name, slug and text domain change before the
> first release.

## Requirements

- WordPress 6.5+, WooCommerce 9.0+ (tested up to 11.1.2), PHP 8.1+ with the `sodium` extension (bundled with PHP).
- Development: PHP 8.1+ (8.3 used) and Composer 2; Git.

## Development setup

```bash
composer install
composer check
```

| Command | What it does |
|---|---|
| `composer lint` / `composer lint:fix` | PHPCS with the WordPress Coding Standards and PHPCompatibilityWP (PHP 8.1+, WordPress 6.5+, prefixes `woonyx`/`WooNyx`, text domain `woonyx`); `lint:fix` runs PHPCBF |
| `composer analyse` | PHPStan level 8 with the WordPress and WooCommerce stubs |
| `composer test` | PHPUnit 10 with Brain Monkey (unit tests, no WordPress needed) |
| `composer check` | the three above; must be green before every commit |
| `composer i18n:pot` | regenerates `languages/woonyx.pot` |
| `composer i18n:compile` | merges the `.pot` into every `.po`, then builds the `.mo` and `.l10n.php` files |
| `composer build` | builds `dist/woonyx-<version>.zip`: the files `git archive` would ship (dev files are `export-ignore`d in `.gitattributes`) plus Composer's production autoloader; nothing has to be committed first |

On Windows run Composer from PowerShell or cmd (Git Bash may not find `php`).

## Configuration (`wp-config.php`)

| Constant | Default | Purpose |
|---|---|---|
| `WOONYX_API_BASE_URL` | `https://api.woonyx.nyxon.tech:88` (the test core, until a production URL exists) | The core's address, without `/api/v1`. Must be https unless `WP_ENVIRONMENT_TYPE` is `local` or `development`. |
| `WOONYX_ENCRYPTION_KEY` | the site's `AUTH_KEY`/`AUTH_SALT` (`wp_salt('auth')`) | Key material for the encrypted options. Changing it, or the salts when it isn't set, means activating the store again. |

## Layout

```
woonyx.php            plugin header, constants, Composer autoload, boot
uninstall.php         removes the plugin's options (every site of a multisite)
src/Plugin.php        hooks: WooCommerce check, HPOS compatibility, translations, features
src/Shared/           Security (Encryptor, HmacSigner), Options (SecretStore), Http (CoreClient, errors),
                      Logging (Logger), Admin (AdminAction: nonce and capability checks)
src/Features/         Settings (the admin page); later Store, Bots, Catalog, Orders, Sync
assets/css/           admin styles (logical CSS properties, RTL-safe)
languages/            woonyx.pot and the Persian translation
tests/Unit/           PHPUnit + Brain Monkey
bin/build-zip.php     the zip builder behind `composer build`
docs/                 handoff, phase plans, the core's OpenAPI spec
```

## Rules the code follows

- No request to the core before the owner enters a license key (WordPress.org).
- Every admin page and action: `manage_woocommerce` and a nonce; input sanitized, output escaped; everything
  prefixed.
- Requests to the core are signed with HMAC-SHA256 over the exact bytes sent, redirects are not followed, and the
  core's raw responses are never shown: every `error_code` has a translated message.
- Secrets (shared secret, admin PIN) are stored encrypted with libsodium and never autoloaded, logged or displayed;
  the license key and bot tokens are not stored at all. The logger redacts secret-looking values as a safety net.

The contract with the core: `.agents/skills/woonyx-plugin-contract/SKILL.md`, `.agents/skills/woonyx-security/SKILL.md`
and `docs/core-api/openapi.json`. Background and decisions: `docs/WooNyxPlugin-HANDOFF.md`.

## License

GPL-2.0-or-later.
