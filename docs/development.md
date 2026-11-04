# Development

You need PHP 8.1 or later (8.3 is used), Composer 2 and Git. The tests are unit tests with Brain Monkey: no WordPress
install is needed to run them.

```bash
composer install
composer check
```

On Windows run Composer from PowerShell or cmd (Git Bash may not find `php`).

## Commands

| Command | What it does |
|---|---|
| `composer lint` / `composer lint:fix` | PHPCS with the WordPress Coding Standards and PHPCompatibilityWP (PHP 8.1+, WordPress 6.5+, prefixes `nyxify`/`Nyxify`, text domain `nyxify`); `lint:fix` runs PHPCBF |
| `composer analyse` | PHPStan level 8 with the WordPress and WooCommerce stubs |
| `composer test` | PHPUnit 10 with Brain Monkey |
| `composer check` | the three above; must be green before every commit |
| `composer i18n:pot` | regenerates `languages/nyxify.pot` |
| `composer i18n:compile` | merges the `.pot` into every `.po`, then builds the `.mo` and `.l10n.php` files |
| `composer build` | builds `dist/nyxify-<version>.zip`, the zip WordPress.org gets (see below) |
| `composer mockup` | regenerates `docs/mockups/admin-page.html`: the settings page rendered by its own classes with sample data, every state in English and Persian, in one self-contained file |

A few tests compare the plugin with the Nyxify service's OpenAPI spec, which isn't part of this repository; they are
skipped when it isn't there.

## The release zip

`composer build` ships what `git archive` would: tracked and untracked files that aren't ignored, minus the
`export-ignore` paths of [.gitattributes](../.gitattributes) (tests, docs, tooling, these READMEs), plus Composer's
production autoloader. Nothing has to be committed first.

Translations are not in the zip: WordPress loads them from translate.wordpress.org. The Persian `.po` in `languages/`
is the source imported there.

## Layout

```
nyxify.php            plugin header, constants, Composer autoload, boot
uninstall.php         runs src/Uninstaller.php on every site of a network
src/Plugin.php        hooks: the WooCommerce check, HPOS compatibility, deactivation, features
src/Shared/           Security (Encryptor, HmacSigner), Options (SecretStore), Http (CoreClient, errors and their
                      messages), Logging (Logger), Admin (nonce and capability checks, one-time notices)
src/Features/
  Store/              license key, REST API key, activation, status, secret rotation, deactivation
  Settings/           the WooCommerce › Nyxify page and its sections: status, bots, admin PIN, bot admins
  Bots/               messengers, bot tokens, bots, admin PIN, bot admins: services and form handlers
  Appearance/         the WooCommerce › Bot appearance page, one class per tab, the bots' text catalog
  Catalog/            queue table, a request's pending changes, change collector, flush action, payloads, sender,
                      retry policy, "Sync now", plan-limit notice
  Orders/             order collector, whitelisted order payloads, status sender
  Sync/               pull fallback: outbox client, payload whitelist, change applier, lock, scheduler, run
assets/               admin styles (logical CSS properties, RTL-safe), scripts, the logo, Vazirmatn and its licence
languages/            nyxify.pot and the Persian translation
tests/Unit/           PHPUnit + Brain Monkey, mirroring src/
bin/                  the zip builder, the mockup, the logo's images (brand-assets.sh), the directory's screenshots
                      from the mockup (screenshots.sh), the service's public pages (site.sh)
.wordpress-org/       the directory's icon, banners and screenshots (for SVN's assets/, not the zip)
site/                 the service's terms of service and privacy policy, English and Persian (nyxify.nyxon.tech)
docs/                 how it works, this file, the admin page's mockup, the logo's original
```

Each feature is a folder of small classes; a feature talks to the service only through `Shared/Http/CoreClient`.

## Rules the code follows

- No request to the service before the shop's administrator enters a license key (WordPress.org's rule).
- Nothing is loaded from outside the plugin: no CDN, no remote font or script.
- Every admin page and action checks `manage_woocommerce` and a nonce; input is sanitized, output escaped, everything
  prefixed.
- Requests to the service are signed with HMAC-SHA256 over the exact bytes sent, redirects are not followed, and the
  service's raw answers are never shown: every `error_code` has a translated message (a test checks the list).
- Secrets are stored encrypted with libsodium and never autoloaded, logged or displayed; the license key and bot
  tokens are not stored at all. The logger redacts secret-looking values as a safety net.
- Personal data (bot admins, orders) is shown only where it's needed and never logged; orders are read through a
  short whitelist of getters.
- Catalog objects carry only the fields the service reads; logs name kinds, counts and ids, never an object's content.
- Pulled changes are applied only through WooCommerce's REST controllers, after a whitelist check.

What this means for data on the shop: [How it works](how-it-works.md).
