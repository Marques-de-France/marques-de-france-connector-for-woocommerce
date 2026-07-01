=== Marques de France ===
Contributors: marquesdefrance
Tags: woocommerce, sales attribution, product feed
Requires at least: 6.5
Tested up to: 7.0
Stable tag: 1.3.1
Requires PHP: 7.4
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WooCommerce store to Marques de France. Track attributed sales, generate a product feed, and sync data to the MDF platform.

== Description ==

**Marques de France** is the official plugin for WooCommerce stores listed in the [Marques de France](https://www.marques-de-france.fr) directory, a curated platform showcasing authentic French brands.

Once activated, the plugin works silently in the background to connect your store to the Marques de France platform. No ongoing configuration needed.

= What the plugin does =

**Tracks attributed sales**
When a customer clicks on your listing in Marques de France (site et app mobile) and completes a purchase on your store, the plugin automatically records the sale and reports it to Marques de France. It works across multiple visits: if someone browses, leaves, and comes back within 30 days, the attribution is still credited to your listing.

**Keeps your revenue data accurate**
Every attributed order is synced to Marques de France in real time. If a customer cancels or gets a refund, the platform is updated automatically so your revenue figures always stay accurate.

**Generates your product feed**
The plugin gives Marques de France access to your product catalog through a secure, token-protected endpoint. This powers the product listings displayed to visitors browsing your brand on the platform.

**WooCommerce admin dashboard**
A dedicated panel in your WooCommerce admin lets you monitor your connection status, view attributed revenue over time, and preview exactly which products are shared with Marques de France.

= What is and is not tracked =

The plugin tracks which orders originated from a Marques de France visit, using the same URL parameter technology as Google Analytics (utm_source, utm_medium, etc.). Attribution is stored for 30 days using first-touch logic: the first traffic source gets the credit.

No personal data is ever shared with Marques de France. Only the order number, amount, currency, and source attribution are transmitted. Customer names, email addresses, phone numbers, and shipping addresses are never collected or sent.

= Requirements =

* A brand listed on [marques-de-france.fr](https://www.marques-de-france.fr)
* A Secure Token provided by Marques de France (configured in the plugin settings)
* WooCommerce 8.0 or later

= Source Code =

The full source code including unminified JavaScript is available on GitHub:
[https://github.com/Marques-de-France/marques-de-france-connector-for-woocommerce](https://github.com/Marques-de-France/marques-de-france-connector-for-woocommerce)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via WP Admin > Plugins > Add New > Upload Plugin or search the plugin from the store via WP Admin > Plugins > And search "Marques de France".
2. Activate the plugin through **WP Admin > Plugins**.
3. Navigate to **Marques de France > Settings** in your WP admin sidebar.
4. Enter the **Secure Token** provided by Marques de France.
5. Click **Save**. The plugin will automatically connect to Marques de France and begin tracking attributed sales.

== External Services ==

This plugin communicates with **Marques de France**, a service operated by Marques de France.

**Service endpoint**: `https://flux.marques-de-france.fr`

**When data is sent**:

* On plugin activation: the store URL is sent to register the installation.
* On each attributed order (checkout completion): order ID, total amount, currency, and attribution signals (UTM parameters, referrer URL) are sent.
* On order cancellation or refund: the order ID and new status are sent to update the revenue record.

**Data sent**: site URL, WooCommerce order ID, order number, order amount, currency, and optional marketing attribution signals (UTM source, medium, campaign, content, term, landing page, referrer). No personal customer data (names, addresses, emails) is ever transmitted.

**Service terms and privacy**:

* Terms of Use: [https://www.marques-de-france.fr/cgv/](https://www.marques-de-france.fr/cgv/)
* Privacy Policy: [https://www.marques-de-france.fr/politique-de-confidentialite-et-donnees-personnelles/](https://www.marques-de-france.fr/politique-de-confidentialite-et-donnees-personnelles/)

== Frequently Asked Questions ==

= Where do I get my Secure Token? =

Your Secure Token is provided by Marques de France when you join the partner program. Log in to your partner dashboard at [marques-de-france.fr](https://www.marques-de-france.fr) to retrieve it.

= Which sales are tracked? =

Only sales that can be attributed to a visit originating from the Marques de France directory are recorded. Orders from other sources are not sent to Marques de France.

= What data is sent to Marques de France? =

Order totals, currency, order ID, and marketing attribution signals (UTM parameters, landing page, referrer). No personal customer data is transmitted.

For more details on how tracking works, see our [tracking FAQ](https://www.marques-de-france.fr/faq-category/suivi-et-tracking/).

= Can I test the connection? =

Yes. Go to **Marques de France > Dashboard** in WP Admin. The dashboard shows the current connection status with Marques de France and a summary of attributed sales and revenue.

== Screenshots ==

1. Dashboard overview: connection status, total revenue, and 12-month sales chart.
2. Product feed: full product list with name/SKU search, brand, price, tag, and availability.
3. Sales tracking: daily analytics chart with attributed orders table (order number, attribution, amount, status, date).
4. Settings: Secure Token (access code) configuration to connect the store to Marques de France.

== Upgrade Notice ==

= 1.3.0 =
More reliable sales attribution after a store migration or reinstall. A small database update runs automatically on upgrade.

= 1.2.1 =
Bug fixes and stability improvements for feed branding, attribution persistence, and translation accuracy.

= 1.2.0 =
Improved partner-facing wording in admin notices and updated translations for clearer messaging.

= 1.0.0 =
Initial release.

== Changelog ==

= 1.3.1 =
* Fix: always enqueue the frontend attribution tracker on storefront pages, even before the secure token is saved, so first-touch signals are not lost on unconfigured stores.
* Improvement: persist `click_id` directly on local sales rows and include the schema migration on upgrade.

= 1.3.0 =
* Improvement: attributed sales are now de-duplicated using the WooCommerce order key (a stable, non-sequential identifier) instead of the order ID. This prevents genuine new sales from being skipped when a store's order numbering resets after a migration or reinstall. Older orders already synced are unaffected; a small database column is added automatically on upgrade.

= 1.2.1 =
* Fix: decode HTML entities in product brand names shown in the feed and admin UI.
* Improvement: strengthen attribution persistence with localStorage + cookie fallback for Safari/ITP resilience.
* Fix: sync and refresh translation strings for the latest admin experience.

= 1.2.0 =
* Improvement: clearer admin notice wording for sales restore flow (partner-facing language).
* Improvement: replaced end-user "Hub" wording with "Marques de France" in restore/sync notices.
* Feature: added manual product selection for the feed (in addition to the `product_tag` tag mode).
* Improvement: UI improvements across the plugin admin screens.
* Fix: synchronized translation catalogs (POT/PO/JSON) with the updated admin copy.
* Fix: security hardening in tracker input handling for plugin checks.

= 1.1.0 =
* Fix: strip HTML tags from product and variation titles in the feed to prevent malformed XML.
* Fix: expose separate `parent_image` and `variant_image` fields in the feed for simple products and variations; the main image now correctly falls back to the parent image when no variation-specific image is set.

= 1.0.2 =
* Feature: report plugin version to Marques de France on each API request via X-Plugin-Version header.
* Fix: composite products (WooCommerce Composite Products by SomewhereWarm) now appear correctly in the product feed and admin product list. WC 10.7+ auto-injects a product_type tax_query that silently excludes unregistered types; both endpoints now use WP_Query directly to bypass this restriction.
* Fix: minor French (fr_FR) translation improvements.

= 1.0.1 =
* Fix: frontend tracker script missing from deployed package (MIME type error on page load).

= 1.0.0 =
* Initial release.
* Sales attribution tracking via UTM parameters and referrer signals.
* Automatic order sync to Marques de France with immediate + retry (Action Scheduler) strategy.
* Order cancellation and refund status propagation to Marques de France.
* Product feed REST endpoint (token-gated).
* React-powered WooCommerce admin dashboard with sales analytics chart, product feed preview, and connection status.
* French (fr_FR) translation included for all admin UI strings.
