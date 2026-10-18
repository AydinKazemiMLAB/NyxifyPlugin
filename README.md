<img src=".wordpress-org/icon-128x128.png" alt="" width="96" height="96" />

# Nyxify for WooCommerce

The WordPress/WooCommerce plugin of Nyxify: it connects a WooCommerce store to the Nyxify core, which sells the
store's products through Telegram, Bale and Rubika bots. The plugin activates the store with a license key, sends
catalog and order changes to the core, and gives the store owner a settings page for the bots, the admin PIN and the
bot admins.

> Status: phase 7 (release readiness) done, version **1.0.0**, ready for the WordPress.org submission (what is left
> before it: `docs/submission-kickoff.md`). On **WooCommerce > Nyxify** the owner enters a license key and the store
> is connected to the core; the page shows the store's status, checks the connection and rotates the shared secret,
> connects, replaces and removes the store's Telegram, Bale and Rubika bots, issues the bots' admin PIN and lists and
> removes the bot admins. While connected, every change to a product, a variation, its stock or a product category,
> and every change to an order placed through a bot, reaches the core in the background (seconds to about a minute,
> see "When" below), and "Sync now" starts a full catalog sync. When the core can't reach the store, the plugin
> fetches the bot admins' product edits itself every 5 minutes and applies them (pull fallback). Deactivating the
> plugin tells the core, and deleting it removes everything the plugin stored. Persian translation and RTL admin, the
> page in Vazirmatn for Persian admins, Jalali dates. Plans and progress: `docs/phase-*-plan.md`; the next phase of
> features (categories, trash and reports in the bot, plugin 1.1.0): `docs/phase-8-plan.md`.

## Requirements

- WordPress 6.5+ (tested up to 7.1), WooCommerce 9.0+ (tested up to 11.2.1), PHP 8.1+ with the `sodium` extension
  (bundled with PHP). The minimums were checked on WordPress Playground (WordPress 6.5, WooCommerce 9.0.0, PHP 8.1).
- Development: PHP 8.1+ (8.3 used) and Composer 2; Git.

## Development setup

```bash
composer install
composer check
```

| Command | What it does |
|---|---|
| `composer lint` / `composer lint:fix` | PHPCS with the WordPress Coding Standards and PHPCompatibilityWP (PHP 8.1+, WordPress 6.5+, prefixes `nyxify`/`Nyxify`, text domain `nyxify`); `lint:fix` runs PHPCBF |
| `composer analyse` | PHPStan level 8 with the WordPress and WooCommerce stubs |
| `composer test` | PHPUnit 10 with Brain Monkey (unit tests, no WordPress needed) |
| `composer check` | the three above; must be green before every commit |
| `composer i18n:pot` | regenerates `languages/nyxify.pot` |
| `composer i18n:compile` | merges the `.pot` into every `.po`, then builds the `.mo` and `.l10n.php` files (kept here, not in the zip: WordPress.org's review asked for translations through translate.wordpress.org, which WordPress loads from `wp-content/languages/plugins/` on its own; the Persian `.po` is the source to import there) |
| `composer build` | builds `dist/nyxify-<version>.zip`: the files `git archive` would ship (dev files are `export-ignore`d in `.gitattributes`) plus Composer's production autoloader; nothing has to be committed first |
| `composer mockup` | regenerates `docs/mockups/admin-page.html`: the settings page rendered by its own classes with sample data, every state in English and Persian, one self-contained file |

On Windows run Composer from PowerShell or cmd (Git Bash may not find `php`).

## Configuration (`wp-config.php`)

| Constant | Default | Purpose |
|---|---|---|
| `NYXIFY_API_BASE_URL` | `https://api.nyxify.nyxon.tech` (the production core) | The core's address, without `/api/v1`. Must be https unless `WP_ENVIRONMENT_TYPE` is `local` or `development`. |
| `NYXIFY_ENCRYPTION_KEY` | the site's `AUTH_KEY`/`AUTH_SALT` (`wp_salt('auth')`) | Key material for the encrypted options. Changing it, or the salts when it isn't set, means activating the store again. |

## Connecting a store

1. **WooCommerce > Nyxify**: enter the license key and activate. The plugin creates a read/write WooCommerce REST key
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

## Catalog sync

Runs only while the store is connected; nothing is queued before.

- **What is sent:** products of every type and status (drafts, private and trashed ones too; a trashed product goes
  with `status: "trash"`) with their complete variation list, product categories, and permanent deletes of both.
  Never `auto-draft` placeholders, revisions or autosaves. Objects are the WooCommerce REST v3 ones, built with the
  REST controllers at sending time and cut down to the fields the core reads: `meta_data` never leaves the shop.
- **When:** saving never waits on the core. The changes of a request (a save, a bulk edit, an import, an order's stock
  update) are collected in memory and written once at the end of the request to the table
  `{prefix}nyxify_catalog_queue` (deduplicated, safe under concurrent requests). An Action Scheduler action
  (`nyxify_catalog_flush`, group `nyxify`) then sends them in batches of up to 100 items (500 ids for deletes): right
  after an admin request, otherwise at WP-Cron's next queue run (every minute, needs a page load or a system cron).
- **Failures:** network errors, timeouts, 408, 429 and 5xx are retried after 1, 5, 15 and 60 minutes (a longer
  `Retry-After` is honoured), then dropped and logged; a lapsed subscription, an inactive store or a rejected
  signature empties the queue (the core's full sync catches up); other 4xx drop that batch. Each attempt is a new,
  freshly signed request with the current data.
- **Sync now:** next to the catalog line of the status section while the store is `active`: asks the core to read the
  whole catalog again (`POST /catalog/sync`); a second click while one runs says so.
- **Plan limit:** when the core skips products because the plan's product limit is reached, a dismissible notice
  with their count is shown on the products list, the product editor and the Nyxify page. The next product batch
  with nothing skipped, "Sync now" and deactivation clear it.
- **Logs** (WooCommerce > Status > Logs, source `nyxify`): kinds, counts, ids, error codes and request ids; never a
  product's or category's content.

## Order status

Runs only while the store is connected, and only for orders the core created through a bot's checkout (marked with
the `_nyxify_order` meta). Orders made any other way (the shop's own checkout, wp-admin, other plugins) are never
queued or sent.

- **What is sent** (`POST /orders/webhooks/status`): `id`, `status`, `number`, `currency`, `total`,
  `date_created_gmt`, `date_modified_gmt`, `date_paid_gmt` and the line items' `name`, `product_id`, `variation_id`,
  `quantity` and `total`, read with the order's getters at sending time in the REST v3 formats. A permanently deleted
  order is sent as `{"id", "status": "trash"}`.
- **What never leaves the shop:** billing and shipping addresses, the customer, e-mail and phone, IP address and user
  agent, notes, the payment method and transaction id, the payment link, coupons, fees, shipping and tax lines, and
  `meta_data`. These fields are never even read.
- **When:** every save of such an order (status changes from wp-admin, bulk actions, payments, the core's own REST
  calls, item or total edits), trash and restore, refunds (the parent order is sent) and permanent deletes. Both order
  storages are covered (HPOS and the legacy posts). Changes go through the catalog's queue and flush action, sent
  before the catalog changes, up to 100 orders a request, with the same retries and the same stop on a lapsed
  subscription, an inactive store or a rejected signature. The core keeps the newest data (`date_modified_gmt`), so
  repeats and echoes of its own writes are harmless.
- **Not sent:** orders changed before 0.5.0 or while the store wasn't connected (no backfill; the core reads unpaid
  orders itself and the bot refreshes an order when it is opened).
- **Logs:** kinds, counts, order ids, request ids, error codes and the core's `affected` count; never an order's
  status, number, total, items or any customer data.

## Pull fallback

Bot admins' product edits normally reach WooCommerce through the core, which writes them with the store's REST key.
When it can't (a host that refuses the core's requests, a firewall, an outage), the plugin fetches them itself. Runs
only while the store is connected.

- **When:** an Action Scheduler action (`nyxify_sync_pull`, group `nyxify`) every 5 minutes, scheduled when the store
  is activated and kept in place on every admin page load (removed when not connected), plus one immediate run
  (`nyxify_sync_pull_now`) after "Check connection" and after a run that got a full answer. Like every Action
  Scheduler action it needs WP-Cron (a page load or a system cron). One run at a time per site (a lock in
  `nyxify_sync_lock`, expiring after 330 s).
- **What is pulled** (`GET /sync/outbox?limit=20`): only the changes whose push by the core has failed at least once,
  oldest first, each claimed by the plugin for 5 minutes: `product.create`, `product.update`, `product.delete` and
  `variation.update`. While the core reaches the store, nothing comes back and the call is answered empty.
- **How they are applied:** one by one, in the core's order, through WooCommerce's own REST controllers in-process
  (`rest_do_request()`: the same validation, image download and meta handling as the core's push), as the user who
  owns the plugin's REST key. A create becomes `POST /wc/v3/products` (after looking for a product that already
  carries the change's `_nyxify_outbox_id`, so a create is never applied twice), an edit `PUT /wc/v3/products/{id}`,
  a delete `DELETE /wc/v3/products/{id}` (to the trash), a variation edit `PUT
  /wc/v3/products/{id}/variations/{id}`. Before that every payload is checked against the fields the core can send
  (name, status, visibility, descriptions, SKU, prices, stock fields, categories by id, images by https link or
  attachment id): anything else fails that change as `invalid_change`. Image downloads are capped at 15 seconds.
  No change is started after 20 seconds of a run; the rest come back later.
- **Acknowledgement** (`POST /sync/outbox/ack`): each change's result, with the product's `date_modified_gmt` after
  the run (the parent's for a variation), so the core recognises the product webhook that follows as the echo of
  its own change. Failures carry WooCommerce's error code and its message without tags or links, cut to 1000
  characters (shown to the bot's admins); `invalid_change`, `rest_user_missing` and `plugin_error` are the plugin's
  own. Sent once more after a network error or a 5xx; a change left unacknowledged is handed out again once its
  claim expires and found by its `_nyxify_outbox_id` instead of being created again.
- **Failures:** a lapsed subscription, an inactive store or a rejected signature pauses pulls for an hour
  (`nyxify_sync_retry_at`; "Check connection" ends the pause); other errors wait for the next run. Nothing is kept
  locally.
- **Logs:** counts (pulled, applied, failed, not started), the core's `affected`, request ids, and for each failed
  change its id, action and error code; never a payload, a product's content, an image link or a signed link.

## Layout

```
nyxify.php            plugin header, constants, Composer autoload, boot
uninstall.php         removes the plugin's REST key, options, transients, queue table, scheduled actions, the
                      `_nyxify_outbox_id` meta and its WooCommerce log entries (every site of a multisite)
src/Plugin.php        hooks: WooCommerce check (its notice on the Plugins screen only), HPOS compatibility, deactivation,
                      features
src/Shared/           Security (Encryptor, HmacSigner), Options (SecretStore), Http (CoreClient, errors),
                      Logging (Logger), Admin (AdminAction: nonce and capability checks; Notices: one-time results)
src/Features/         Settings (the admin page and its sections: status, bots, admin PIN, bot admins; deactivation
                      warning), Store (license key, REST key, activation, status, secret rotation, deactivation),
                      Bots (platforms, token input, bots, admin PIN, bot admins: services and form handlers),
                      Catalog (queue table, the request's pending changes, change collector, flush action for
                      catalog and orders, payload builder, sender, retry policy, "Sync now", plan-limit notice),
                      Orders (order collector, whitelisted order payloads, status sender),
                      Sync (pull fallback: outbox client, payload whitelist, change applier, lock, scheduler, run)
assets/css/           admin styles (logical CSS properties, RTL-safe; Vazirmatn for Persian admins)
assets/fonts/         Vazirmatn v33.003 (variable woff2) and its SIL OFL 1.1, unchanged
assets/images/        the logo on the settings page
assets/js/            confirmations: forms with `data-nyxify-confirm` (settings page), deactivation (plugins list)
languages/            nyxify.pot (in the zip) and the Persian translation (not in the zip, see i18n:compile)
tests/Unit/           PHPUnit + Brain Monkey
bin/                  the zip builder (`composer build`), the mockup (`composer mockup`), the logo's assets
                      (`brand-assets.sh`), the directory's screenshots from the mockup (`screenshots.sh`), the
                      service's public pages (`site.sh`)
.wordpress-org/       the directory's icon, banner and screenshots (for SVN's `assets/`, not the zip)
site/                 the service's terms of service and privacy policy, English and Persian, served at
                      https://nyxify.nyxon.tech/ (not in the zip)
docs/                 handoff, phase plans and kickoffs, the core's OpenAPI spec, the admin page's mockup
```

## Rules the code follows

- No request to the core before the owner enters a license key (WordPress.org).
- Nothing is loaded from outside the plugin: no CDN, no remote font or script (Vazirmatn is bundled).
- Every admin page and action: `manage_woocommerce` and a nonce; input sanitized, output escaped; everything
  prefixed.
- Requests to the core are signed with HMAC-SHA256 over the exact bytes sent, redirects are not followed, and the
  core's raw responses are never shown: every `error_code` has a translated message.
- Secrets (shared secret, admin PIN) are stored encrypted with libsodium and never autoloaded, logged or displayed;
  the license key and bot tokens are not stored at all. The logger redacts secret-looking values as a safety net.
- Bot admins' names, usernames and messenger ids are personal data: shown on the settings page only, never logged,
  cached or put in a notice or URL.
- Catalog objects carry only the fields the core reads (no `meta_data`); logs name kinds, counts and ids, never an
  object's content.
- Orders are personal data: only orders the core created are sent, built from a short whitelist of getters (no
  customer, address, contact, note, payment or meta field is read), and logged by id only.
- Pulled changes are applied only through WooCommerce's REST controllers, after a whitelist check, as the REST key's
  user; their payloads, images and links are never logged.
- The WooCommerce REST key the core uses is created by the plugin for the admin who activates; its consumer key and
  secret exist only in memory for the activation request. The plugin only ever deletes the key it created (its id is
  kept in `nyxify_rest_key_id`), never a key made by hand.

The contract with the core: `.agents/skills/nyxify-plugin-contract/SKILL.md`, `.agents/skills/nyxify-security/SKILL.md`
and `docs/core-api/openapi.json`. Background and decisions: `docs/NyxifyPlugin-HANDOFF.md`.

## License

GPL-2.0-or-later.
