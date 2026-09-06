=== Sync Mautic ===
Contributors: dogbytemarketing, polyplugins
Tags: sync, mautic, marketing, email, email marketing
Tested up to: 7.1
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Bridge Mautic with WordPress, WooCommerce, and OptinMonster, automatically tagging contacts with their purchases, categories, and brands.


== Description ==

Mautic is an excellent Open Source Email and SMS marketing tool, but it lacks the ability to see what your contacts have purchased or if they came from your website. Sync Mautic works with WordPress to tag those subscribed via your website and also with WooCommerce so that when a customer completes an order and they are subscribed as a contact, it will automatically tag them with the products, categories, and brands they have purchased. Our plugin also provides an endpoint you can hook into for OptinMonster to collect leads, but if you don't use OptinMonster we provided a [mautic_form] shortcode to use which will inject a form that links to your Mautic instance.

Features:

* Supports WordPress
* Supports WooCommerce
* Supports tracking eCommerce revenue using our [eCommerce Connector for Mautic](https://www.dogbytemarketing.com/shop/ecommerce-connector-for-mautic/)
* [NEW] Optional order line items (product ID, name, quantity, category) for eCommerce Connector 1.3.0
* [NEW] Optional signed webhook order tracking as an alternative to page hits
* [NEW] Optional clickthrough collection for signed webhook so orders can be attributed to the Mautic email that was clicked
* Supports OptinMonster using /wp-json/sync-mautic/v1/optinmonster
* Supports adding leads using /wp-json/sync-mautic/v1/add-lead
* Adds [mautic_form] shortcode to give an email signup. You can use the tag attribute to add tags in Mautic [mautic_form tag="Tag 1, Tag 2"]
* Adds a checkbox to WooCommerce checkout to subscribe to newsletter
* Tag users of your website in Mautic
* Tag customers of your store in Mautic
* Tag subscribed contacts with products they purchase
* Tag subscribed contacts with categories they have purchased from
* Tag subscribed contacts with brands they have purchased from


== Installation ==

1. Take a backup of your WordPress database and files before you install. A backup lets you restore WordPress if something goes wrong.
1. Upload the plugin files to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugins screen directly.
1. Activate the plugin through the 'Plugins' screen in WordPress
1. Use the Mautic>Sync Mautic page to configure the plugin
1. In Mautic, open Ecommerce Connector → Validation and set Allowed order sources to WooCommerce. Sync Mautic sends WooCommerce as the order source.


== Upgrade ==

1. Take a backup of your WordPress database and files before you upgrade. A backup lets you restore WordPress if something goes wrong.
1. Update Sync Mautic from the WordPress plugins screen, or replace the plugin files in `/wp-content/plugins/sync-mautic/`.
1. Open Settings > Sync Mautic after the upgrade and confirm your tracking settings.
1. In Mautic, open Ecommerce Connector → Validation and set Allowed order sources to WooCommerce. Sync Mautic sends WooCommerce as the order source.


== Screenshots ==

1. Plugin Settings


== GDPR and privacy ==

This plugin may send contact, order, and revenue data to Mautic. That data may be personal data under the GDPR and similar privacy laws.

Cookies are not the threshold. The signed webhook is server-to-server and does not set cookies, but it still processes personal data. Page-hit tracking uses WP Mautic visitor identification, which typically involves cookies. Page hits also send billing email on the mtc.js pageview so the order can be linked to an existing Mautic contact. Page hits do not set an additional cookie. Optional clickthrough collection stores a first-party cookie so signed webhook orders can be attributed to a Mautic email.

**Before you enable tracking, update your privacy and cookie policies along with cookie acceptance banners** so they disclose that order, purchase, and revenue information may be sent to and stored in Mautic for marketing analytics, email attribution, segmentation, and contact profiling. Do not rely on this plugin's settings alone; your public-facing privacy policy (and any other required notices) must reflect this processing.

You are also responsible for:

* Having a lawful basis for processing (for example consent or legitimate interests, as applicable in your jurisdiction)
* Obtaining consent where required before enabling order tracking, clickthrough collection, or checkout opt-in
* Handling data subject requests (access, erasure, and related rights) for data stored in WordPress and in Mautic
* Configuring retention and security in line with your policies and legal obligations

= Data this plugin may send or store =

When order tracking is enabled, this plugin may send to Mautic (via Ecommerce Connector):

* Order identifiers, totals, currency, source, and order date
* Line items (product/item ID, name, quantity, category), when enabled
* Billing email on page-hit (mtc.js) pageviews and signed webhook requests, used only to link an existing Mautic contact (contacts are not created from page hits or the webhook)
* Optional clickthrough token (`ct`) for email attribution, when Collect clickthrough is enabled
* Links between orders and contacts, and optional attribution to Mautic emails

Mautic may then store and display contact commerce fields, timeline entries, and report data derived from those orders.

This plugin may also store in WordPress:

* Cached Mautic lead IDs on WordPress user profiles when order tagging is enabled
* A first-party clickthrough cookie and matching order meta when Collect clickthrough is enabled
* Checkout newsletter signup meta when checkout opt-in is used

Order tagging and checkout opt-in may create or update Mautic contacts and tags. Past order tagging does not send historical orders to Ecommerce Connector.

= Access and erasure =

* **Contact export** of ecommerce order history (order ID, totals, currency, source, location, date, attributed email, and line items) is handled in Mautic by Ecommerce Connector, so you can fulfill access requests there.
* **Deleting a Mautic contact** also deletes that contact's ecommerce orders and line items in Mautic. Commerce fields on the contact are removed with the contact. Email revenue totals for those orders will drop after deletion.
* Merging Mautic contacts moves ecommerce orders to the remaining contact.
* Deleting a WordPress user does not automatically delete the matching Mautic contact or that contact's ecommerce orders.

You are solely responsible for any data protection measures required for your use of this integration.


== Disclaimer ==

This software is provided **as is**, without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose, and noninfringement. In no event shall the authors or copyright holders be liable for any claim, damages, or other liability arising from use of this software.

You are solely responsible for how you deploy and use this plugin, including compliance with applicable laws and data protection obligations.


== Changelog ==

= 1.1.0 =
* Added: Optional line items on WooCommerce order tracking (product ID, name, quantity, first category). Requires eCommerce Connector 1.3.0. Defaults to off.
* Added: Signed webhook tracking method as an exclusive alternative to page hits. Requires eCommerce Connector 1.3.0 and the Ecommerce Connector webhook secret.
* Added: Page-hit line item cap of 20, with a note to use the signed webhook for larger carts.
* Added: Settings copy that tracking does not backfill past orders.
* Added: Optional collect clickthrough setting for signed webhook only (default off). Stores the Mautic ct token in a first-party cookie and sends it with the webhook for email attribution.

= 1.0.8 =
* Added: eCommerce tracking support to Mautic 7 using our [eCommerce Connector for Mautic](https://www.dogbytemarketing.com/shop/ecommerce-connector-for-mautic/)
* Updated: Logging to use filesystem instead of depending on WooCommerce

= 1.0.7 =
* Updated: Documentation in the settings panel for configuring the Mautic API

= 1.0.6 =
* Updated: Shortcode to be able to use tag or tags.

= 1.0.5 =
* Added: Ability to add multiple tags using [mautic_form tag="Tag 1, Tag 2"] shortcode.
* Added: API connection test when saving Sync Mautic settings, which will trigger an error if it fails to connect.
* Added: Order meta to track if the customer signed up for the newsletter.
* Added: Additional error logging.
* Bugfix: After updating settings, it will pass the old token until cache expires. Saving settings will now clear the cache.
* Bugfix: After the token expires, it fails to clear cache the first time it attempts to connect.

= 1.0.4 =
* Added: Additional debugging to checkout lead
* Removed: WooCommerce init method

= 1.0.3 =
* Added: Old rest routes for those previously on the beta
* Updated: Handling for beta compatibility

= 1.0.2 =
* Bugfix: Empty tag added into Mautic when the shortcode's tag attribute was empty.
* Added: Beta compatibility check for shortcode so previous beta users do not need to update their shortcode.

= 1.0.1 =
* Bugfix: Saving Settings

= 1.0.0 =
* Initial Release
