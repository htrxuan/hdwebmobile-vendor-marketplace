=== HDWebmobile Vendor Marketplace ===
Contributors: htrxuan
Donate link: https://paypal.me/htrxuan/20
Tags: woocommerce, multivendor, marketplace, vendor, commission
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your store into a multi-vendor marketplace. Vendors can only ever touch their own products, orders, and earnings.

== Description ==

HDWebmobile Vendor Marketplace lets independent sellers apply, get approved, and sell their own products through your WooCommerce store. Each vendor gets a dashboard to manage their products, see their own order items, track their earnings, and request a withdrawal -- all scoped strictly to their own account.

= Why this plugin exists =
A competing multi-vendor marketplace plugin (Dokan) disclosed a cluster of related authorization vulnerabilities in 2026: CVE-2026-11987 let a subscriber-level account read other vendors' unpublished product data via IDOR; CVE-2026-16564 let a vendor account change the status of *any* order on the marketplace, including other vendors' and the store's own, because a bulk order-status REST endpoint never verified ownership; and CVE-2026-16577 let a vendor manipulate their own withdrawal amount because the client-supplied payment figure was never validated against their real balance. This plugin closes all three vulnerability classes by construction, not by bolted-on checks:

* Product ownership is never enforced by this plugin's own code at all -- WooCommerce registers the "product" post type with `capability_type => 'product'` and `map_meta_cap => true`, so WordPress core's own decades-tested authorization system already refuses to let one vendor edit, delete, or view another vendor's product. The Vendor role this plugin creates simply never receives `edit_others_products`, `delete_others_products`, or `read_private_products` -- that single omission is the entire fix.
* A vendor can never change a WooCommerce order's own status. There is no code path anywhere in this plugin that lets vendor-facing code call `$order->set_status()`. Vendors can only mark their own order line item as shipped, and every such request explicitly verifies the product behind that specific line item is actually authored by the vendor making the request.
* A withdrawal request never accepts an amount from the vendor. The payable amount is always computed server-side, fresh, as the sum of that vendor's own available earnings ledger rows -- there is no withdrawal code path anywhere in this plugin that reads a number typed or submitted by the vendor.

= Key Features =
* Front-end "Become a Vendor" application form; admin approval is the only way to actually grant vendor access
* Approved vendors get their own product-management screens (using WooCommerce's own Products screen, automatically scoped to their own listings)
* A per-vendor commission rate, set once by the store admin
* Automatic per-line-item earnings split the moment an order is marked Completed
* A vendor dashboard: earnings balance, recent earnings, order items, and a "Request Withdrawal" button
* A public storefront view per vendor, reusing your theme's own Shop page layout
* A "Sold by" line on product pages linking to that vendor's storefront

= Limitations (please read before installing) =
* No built-in payment gateway integration for payouts -- withdrawal requests are approved and marked paid manually by the store admin; actually transferring funds is outside this plugin's scope
* One flat commission rate for the whole marketplace -- no per-vendor or per-category rates in this version
* No vendor-to-vendor messaging or vendor-branded email templates in this version

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/hdwebmobile-vendor-marketplace` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress. WooCommerce must already be installed and active.
3. Set your commission rate under WooCommerce > HDWebmobile > Vendor Marketplace.

== How to Use ==

= 1. A customer applies to become a vendor =
Logged-in customers see a "Sell on our marketplace" form on their My Account dashboard.

= 2. You approve the application =
Under WooCommerce > HDWebmobile > Vendor Marketplace, approve or reject pending applications. Approving grants the Vendor role.

= 3. The vendor manages their store =
Approved vendors get a "Vendor Dashboard" menu in wp-admin: add products, see their own order items, mark items shipped, and track earnings.

= 4. Earnings and withdrawals =
When an order is completed, each vendor's share of their line items is calculated automatically. Vendors can request a withdrawal for their full available balance; you approve and mark it paid from the same hub tab.

== Screenshots ==

1. The "Become a Vendor" application form on My Account.
2. A vendor's dashboard: earnings, order items, and product management links.
3. The Vendor Applications and Withdrawal Requests queues under WooCommerce > HDWebmobile.

== Changelog ==

= 1.0.0 =
* Initial release: vendor applications, product ownership enforced by WordPress core's own capability system, per-line-item order status, automatic earnings splitting, server-computed withdrawal requests, per-vendor storefront pages.
