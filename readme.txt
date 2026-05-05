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

**Marques de France** is the official plugin for partner stores listed in the [Marques de France](https://www.marques-de-france.fr) directory — a curated platform showcasing authentic French brands.

Once installed and configured, this plugin:

* **Tracks attributed sales** — detects when a customer arrives via the Marques de France directory and records the resulting sale in your WooCommerce store.
* **Syncs revenue to the MDF Hub** — automatically sends attributed order data (amounts, UTM parameters, attribution signals) to the Marques de France platform so your partner dashboard stays up to date.
* **Generates a product feed** — exposes a product feed endpoint compatible with the Marques de France platform, giving Marques de France the data it needs to showcase your products.
* **WooCommerce admin dashboard** — adds a dedicated admin panel with sales analytics, a product feed preview, and Hub connection status.

**Requirements**

* An active partner account on [marques-de-france.fr](https://www.marques-de-france.fr)
* A Secure Token provided by Marques de France (entered in the plugin settings)
* WooCommerce 8.0 or later

**Source Code**

The full source code including unminified JavaScript is available on GitHub:
[https://github.com/Marques-de-France/marques-de-france-connector-for-woocommerce](https://github.com/Marques-de-France/marques-de-france-connector-for-woocommerce)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via **WP Admin → Plugins → Add New → Upload Plugin**.
2. Activate the plugin through **WP Admin → Plugins**.
3. Navigate to **Marques de France → Settings** in your WP admin sidebar.
4. Enter the **Secure Token** provided by Marques de France.
5. Click **Save**. The plugin will automatically connect to the MDF Hub and begin tracking attributed sales.

== External Services ==

This plugin communicates with the **Marques de France Hub** — a service operated by Marques de France.

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

= Can I test the connection? =

Yes. Go to **Marques de France → Dashboard** in WP Admin. The dashboard shows the current Hub connection status and a summary of attributed sales and revenue.

= Where is the product feed URL? =

The feed is available at `/wp-json/mdfcforwc/v1/feed?token=YOUR_SECURE_TOKEN`. The Settings page in WP Admin displays the exact URL for your store.

= Can I override the Hub URL for local development? =

Yes. Add the following to your `wp-config.php`:

`define( 'MDF_CFORWC_HUB_URL', 'https://your-dev-tunnel.example.com' );`

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
