# How it works

What the plugin does on the shop, what it sends to the Nyxify service and when, and what never leaves the shop. The
service (`https://api.nyxify.nyxon.tech`) runs the shop's bots on Telegram, Bale and Rubika; the plugin itself never
talks to the messengers. The same list, in short, is in [readme.txt](../readme.txt) under "External services", and the
service's side is in its [Privacy Policy](https://nyxify.nyxon.tech/privacy/).

- [Connecting a shop](#connecting-a-shop)
- [Bots, admin PIN and bot admins](#bots-admin-pin-and-bot-admins)
- [Bot appearance](#bot-appearance)
- [Catalog sync](#catalog-sync)
- [Order status](#order-status)
- [Pull fallback](#pull-fallback)
- [Deactivating and deleting](#deactivating-and-deleting)
- [Configuration](#configuration)
- [Security and privacy](#security-and-privacy)

## Connecting a shop

Nothing is sent anywhere before the shop's administrator enters a license key.

1. **WooCommerce › Nyxify**: enter the license key and click *Activate*. The plugin creates a read/write WooCommerce
   REST API key for the current administrator (deleting that user or the key disconnects the shop) and sends it to
   the service with the site's address, its REST API address and the WordPress and WooCommerce versions. The service
   answers with the shop's id and a shared secret, which the plugin keeps encrypted. The license key is not kept.
2. The page then shows the shop's status (license, plan, messengers, catalog sync), cached for 5 minutes. *Check
   connection* fetches it again; *Rotate secret* replaces the shared secret.
3. On a multisite network each site is a separate shop with its own license key.

The service uses the REST API key to read the shop's products and categories, to create the orders customers place
through the bots and read their status, and to apply the product edits bot admins make.

## Bots, admin PIN and bot admins

Shown while the shop is connected and active, read from the service on every page load (not cached).

- **Bots:** one block per messenger the plan includes. Paste the token the messenger gave you (BotFather's on
  Telegram, for example): it is sent once to the service, which checks it with the messenger, and it is never stored,
  logged or shown again by the plugin. The bot shows "Setting up…" until the service has set its webhook. *Replace
  with another bot* and *Remove bot* ask for confirmation. A bot on a messenger the plan no longer includes stops
  working and can only be removed.
- **Admin PIN:** whoever sends it to one of the shop's bots becomes a bot admin and can manage the shop from the
  messenger. *New PIN* replaces it; *New PIN and log out every admin* also removes every admin. The PIN is kept
  encrypted and shown only on this page, folded under *Show PIN*.
- **Bot admins:** messenger, name and username. *Remove* takes the admin's access away; the current PIN still works for
  that person until a new one is issued.

## Bot appearance

**WooCommerce › Bot appearance** has one tab per part of the bots' look: the main menu (its buttons and their
layout), the texts the bots send (Persian and English), the welcome message and banner, the back button, an icon and
color per category, the bot's description and short description on the messenger and the shop's contact details. The page
reads the current settings from the service when it opens and sends only the tab you save. The contact details (phone,
e-mail, the shop's ids on the messengers and Instagram, a WhatsApp number, an address, working hours, an introduction)
are shown to everyone who opens the bots. Bot admins can change most of this from the bot too.

## Catalog sync

Runs only while the shop is connected; nothing is queued before.

- **What is sent:** products of every type and status (drafts, private and trashed ones too; a trashed product is sent
  with `status: "trash"`) with their complete variation list, product categories, and permanent deletes of both. Never
  `auto-draft` placeholders, revisions or autosaves. Objects are WooCommerce's REST API v3 ones, built at sending time
  and cut down to the fields the service reads: name, slug, type, status, visibility, link, SKU, prices, stock,
  descriptions, images, categories, attributes and dates. `meta_data` never leaves the shop.
- **When:** saving never waits for the service. The changes of a request (a save, a bulk edit, an import, an order's
  stock update) are collected in memory and written once at the end of the request to the table
  `{prefix}nyxify_catalog_queue` (deduplicated, safe under concurrent requests). An Action Scheduler action
  (`nyxify_catalog_flush`, group `nyxify`) then sends them in batches of up to 100 items (500 ids for deletes): right
  after an admin request, otherwise at WP-Cron's next queue run.
- **Failures:** network errors, timeouts, 408, 429 and 5xx are retried after 1, 5, 15 and 60 minutes (a longer
  `Retry-After` is honoured), then dropped and logged. A lapsed subscription, an inactive shop or a rejected signature
  empties the queue (the service's full sync catches up); other 4xx drop that batch. Every attempt is a new, freshly
  signed request with the current data.
- **Sync now:** asks the service to read the whole catalog again.
- **Plan limit:** when the service skips products because the plan's product limit is reached, a dismissible notice
  with their count is shown on the products list, the product editor and the Nyxify page.

## Order status

Only for orders the service created through a bot's checkout (marked with the `_nyxify_order` meta). Orders made any
other way (the shop's own checkout, wp-admin, other plugins) are never queued or sent.

- **What is sent:** `id`, `status`, `number`, `currency`, `total`, `date_created_gmt`, `date_modified_gmt`,
  `date_paid_gmt` and the line items' `name`, `product_id`, `variation_id`, `quantity` and `total`, read with the
  order's getters at sending time. A permanently deleted order is sent as its id with `status: "trash"`.
- **What never leaves the shop:** billing and shipping addresses, the customer, e-mail and phone, IP address and user
  agent, notes, the payment method and transaction id, the payment link, coupons, fees, shipping and tax lines, and
  `meta_data`. These fields are never even read.
- **When:** every save of such an order (status changes, payments, refunds, item or total edits), trash, restore and
  permanent delete, on both order storages (HPOS and the legacy posts). Changes go through the catalog's queue, before
  the catalog changes, with the same retries. The service keeps the newest data (`date_modified_gmt`), so repeats are
  harmless.

## Pull fallback

Bot admins' product edits normally reach WooCommerce through the service, which writes them with the shop's REST API
key. When it can't (a host that refuses its requests, a firewall, an outage), the plugin fetches them itself.

- **When:** an Action Scheduler action (`nyxify_sync_pull`, group `nyxify`) every 5 minutes while the shop is
  connected, plus one right away after *Check connection*. One run at a time per site.
- **What is pulled:** only the changes whose delivery by the service has failed, oldest first: `product.create`,
  `product.update`, `product.delete` and `variation.update`. While the service reaches the shop, nothing comes back.
- **How they are applied:** one by one, in the service's order, through WooCommerce's own REST controllers in-process
  (`rest_do_request()`: the same validation, image download and meta handling), as the user who owns the plugin's REST
  API key. Every payload is first checked against the fields the service can send (name, status, visibility,
  descriptions, SKU, prices, stock, categories, images); anything else fails that change. A create is never applied
  twice: the product carries the change's id in `_nyxify_outbox_id`.
- **Acknowledgement:** each change's result goes back to the service. Failures carry WooCommerce's error code and its
  message without tags or links (shown to the bot's admins).
- **Failures:** a lapsed subscription, an inactive shop or a rejected signature pauses pulls for an hour; other errors
  wait for the next run. Nothing is kept on the shop.

## Deactivating and deleting

- **Deactivating** tells the service (the bots stop answering) and removes the connection and the REST API key the
  plugin created. If the service can't be reached at that moment, both stay until the plugin is deleted. Connecting
  again needs the license key.
- **Deleting** removes the plugin's REST API key, options, transients, queue table, scheduled actions, the
  `_nyxify_outbox_id` meta and its WooCommerce log entries, on every site of a network. Products and orders stay.

## Configuration

Optional constants in `wp-config.php`:

| Constant | Default | Purpose |
|---|---|---|
| `NYXIFY_API_BASE_URL` | `https://api.nyxify.nyxon.tech` | The service's address, without `/api/v1`. Must be https unless `WP_ENVIRONMENT_TYPE` is `local` or `development`. |
| `NYXIFY_ENCRYPTION_KEY` | the site's `AUTH_KEY`/`AUTH_SALT` (`wp_salt('auth')`) | Key material for the encrypted options. Changing it, or the salts when it isn't set, means activating the shop again. |

Background work runs on WooCommerce's Action Scheduler, so WP-Cron has to run (a page load every few minutes, or a
system cron). The shop must be reachable over HTTPS and its REST API (`/wp-json/`) must answer without a redirect.

## Security and privacy

- Every request to the service is signed with HMAC-SHA256 over the exact bytes sent; redirects are not followed, and
  the service's raw answers are never shown: every error code has a translated message.
- Secrets (the shared secret, the admin PIN) are stored encrypted with libsodium and never autoloaded, logged or
  shown; the license key and bot tokens are not stored at all.
- Bot admins' names, usernames and messenger ids are shown on the settings page only, never logged, cached or put in
  a URL.
- Logs (WooCommerce › Status › Logs, source `nyxify`) name kinds, counts, ids, error codes and request ids; never a
  product's, a category's or an order's content, or any customer data.
- Every admin page and action needs the `manage_woocommerce` capability and a nonce. Nothing is loaded from outside
  the plugin: no CDN, no remote font or script.
- The plugin only ever deletes the REST API key it created, never one made by hand.

Found a security issue? See [SECURITY.md](../SECURITY.md).
