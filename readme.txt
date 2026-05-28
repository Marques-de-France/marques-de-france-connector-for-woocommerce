=== Marques de France ===
Contributors: marquesdefrance
Tags: woocommerce, sales attribution, product feed
Requires at least: 6.5
Tested up to: 6.9
Stable tag: 1.0.0
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
5. Click **Save**. The plugin will automatically connect to the MDF Hub and begin tracking attributed sales.

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

Only sales that can be attributed to a visit originating from the Marques de France directory are recorded. Orders from other sources are not sent to the Hub.

= What data is sent to Marques de France? =

Order totals, currency, order ID, and marketing attribution signals (UTM parameters, landing page, referrer). No personal customer data is transmitted.

For more details on how tracking works, see our [tracking FAQ](https://www.marques-de-france.fr/faq-category/suivi-et-tracking/).

= Can I test the connection? =

Yes. Go to **Marques de France > Dashboard** in WP Admin. The dashboard shows the current connection status with Marques de France and a summary of attributed sales and revenue.

== Screenshots ==

1. Dashboard overview: connection status, total revenue, and 12-month sales chart.
2. Product feed: full product list with name/SKU search, brand, price, tag, and availability.
3. Sales tracking: daily analytics chart with attributed orders table (order number, attribution, amount, status, date).
4. Settings: Secure Token (access code) configuration to connect the store to the Marques de France Hub.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Changelog ==

= 1.0.0 =
* Initial release.
* Sales attribution tracking via UTM parameters and referrer signals.
* Automatic order sync to the Marques de France Hub with immediate + retry (Action Scheduler) strategy.
* Order cancellation and refund status propagation to the Hub.
* Product feed REST endpoint (token-gated).
* React-powered WooCommerce admin dashboard with sales analytics chart, product feed preview, and Hub connection status.
* French (fr_FR) translation included for all admin UI strings.
