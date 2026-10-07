# WooNyx for WooCommerce

The WordPress/WooCommerce plugin of WooNyx: it connects a WooCommerce store to the WooNyx core, which sells the
store's products through Telegram, Bale and Rubika bots. The plugin activates the store with a license key, sends
catalog and order changes to the core, and gives the store owner a settings page for the bots, the admin PIN and the
bot admins.

> Status: phase 3 (bots, admin PIN and bot admins), version 0.3.0. On **WooCommerce > WooNyx** the owner enters a
> license key and the store is connected to the core; the page shows the store's status, checks the connection and
> rotates the shared secret, connects, replaces and removes the store's Telegram, Bale and Rubika bots, issues the
> bots' admin PIN and lists and removes the bot admins; deactivating the plugin tells the core, and uninstalling
> removes everything the plugin stored. Catalog and order sync come in the next phases. Plans and progress:
> `docs/phase-*-plan.md`. "WooNyx" / `woonyx` is a temporary name:
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

## Connecting a store

1. **WooCommerce > WooNyx**: enter the license key and activate. The plugin creates a read/write WooCommerce REST key
   for the current admin (deleting that user or the key disconnects the shop) and sends it to the core with the
   site's URLs; the core answers with the store id and a shared secret, kept encrypted. The license key is not kept.
2. The page then shows the store's status (license, plan, platforms, catalog sync), cached for 5 minutes; "Check
   connection" fetches it again and "Rotate secret" replaces the shared secret.
3. Deactivating the plugin tells the core (the bots stop) and removes the connection and the REST key; if the core
   can't be reached or the subscription has expired, both stay until the plugin is deleted. Connecting again needs
   the license key. On a network, each site is activated on its own.

## Bots, admin PIN and bot admins

Shown while the store is connected and `active`, fetched from the core on every page load (not cached).

- **Bots:** one block per messenger the plan allows (plus any bot the core still has on a messenger the plan no longer
  allows: it doesn't work and can only be removed). Enter the token the messenger gave you (e.g. BotFather) to connect
  a bot; it is sent once to the core, which checks it with the messenger, and is never stored, logged or shown again.
  The core sets the webhook in the background: the bot shows "Setting up…" with a "Check again" link until it is
  active (or failed, with the core's reason). "Replace with another bot" and "Remove bot" ask for confirmation.
- **Admin PIN:** anyone who sends it to one of the store's bots becomes a bot admin. "New PIN" replaces it; "New PIN
  and log out every admin" also removes every admin. The PIN is kept encrypted and shown only on this page, folded
  under "Show PIN".
- **Bot admins:** messenger, name and username (the messenger user id only when both are missing); "Remove" takes the
  admin access away, but the current PIN still works for that person until a new one is issued.

## Layout

```
woonyx.php            plugin header, constants, Composer autoload, boot
uninstall.php         removes the plugin's REST key, options and transients (every site of a multisite)
src/Plugin.php        hooks: WooCommerce check, HPOS compatibility, translations, deactivation, features
src/Shared/           Security (Encryptor, HmacSigner), Options (SecretStore), Http (CoreClient, errors),
                      Logging (Logger), Admin (AdminAction: nonce and capability checks; Notices: one-time results)
src/Features/         Settings (the admin page and its sections: status, bots, admin PIN, bot admins; deactivation
                      warning), Store (license key, REST key, activation, status, secret rotation, deactivation),
                      Bots (platforms, token input, bots, admin PIN, bot admins: services and form handlers);
                      later Catalog, Orders, Sync
assets/css/           admin styles (logical CSS properties, RTL-safe)
assets/js/            confirmations: forms with `data-woonyx-confirm` (settings page), deactivation (plugins list)
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
- Bot admins' names, usernames and messenger ids are personal data: shown on the settings page only, never logged,
  cached or put in a notice or URL.
- The WooCommerce REST key the core uses is created by the plugin for the admin who activates; its consumer key and
  secret exist only in memory for the activation request. The plugin only ever deletes the key it created (its id is
  kept in `woonyx_rest_key_id`), never a key made by hand.

The contract with the core: `.agents/skills/woonyx-plugin-contract/SKILL.md`, `.agents/skills/woonyx-security/SKILL.md`
and `docs/core-api/openapi.json`. Background and decisions: `docs/WooNyxPlugin-HANDOFF.md`.

## License

GPL-2.0-or-later.
