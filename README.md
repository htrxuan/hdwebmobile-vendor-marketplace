# HDWebmobile Vendor Marketplace

Turn your store into a multi-vendor marketplace. Vendors can only ever touch their own products, orders, and earnings.

- **WordPress.org:** https://wordpress.org/plugins/hdwebmobile-vendor-marketplace/
- **Requires:** WordPress 6.9+, WooCommerce, PHP 7.4+
- **License:** GPLv2 or later

## Description

HDWebmobile Vendor Marketplace lets independent sellers apply, get approved, and sell their own products through your WooCommerce store. Each vendor gets a dashboard to manage their products, see their own order items, track their earnings, and request a withdrawal -- all scoped strictly to their own account.

## Why this plugin exists

A competing multi-vendor marketplace plugin (Dokan) disclosed a cluster of related authorization vulnerabilities in 2026: CVE-2026-11987 let a subscriber-level account read other vendors' unpublished product data via IDOR; CVE-2026-16564 let a vendor account change the status of *any* order on the marketplace, including other vendors' and the store's own, because a bulk order-status REST endpoint never verified ownership; and CVE-2026-16577 let a vendor manipulate their own withdrawal amount because the client-supplied payment figure was never validated against their real balance. This plugin closes all three vulnerability classes by construction, not by bolted-on checks:

* Product ownership is never enforced by this plugin's own code at all -- WooCommerce registers the "product" post type with `capability_type => 'product'` and `map_meta_cap => true`, so WordPress core's own decades-tested authorization system already refuses to let one vendor edit, delete, or view another vendor's product. The Vendor role this plugin creates simply never receives `edit_others_products`, `delete_others_products`, or `read_private_products` -- that single omission is the entire fix.
* A vendor can never change a WooCommerce order's own status. There is no code path anywhere in this plugin that lets vendor-facing code call `$order->set_status()`. Vendors can only mark their own order line item as shipped, and every such request explicitly verifies the product behind that specific line item is actually authored by the vendor making the request.
* A withdrawal request never accepts an amount from the vendor. The payable amount is always computed server-side, fresh, as the sum of that vendor's own available earnings ledger rows -- there is no withdrawal code path anywhere in this plugin that reads a number typed or submitted by the vendor.

## Features

* Front-end "Become a Vendor" application form; admin approval is the only way to actually grant vendor access
* Approved vendors get their own product-management screens (using WooCommerce's own Products screen, automatically scoped to their own listings)
* A per-vendor commission rate, set once by the store admin
* Automatic per-line-item earnings split the moment an order is marked Completed
* A vendor dashboard: earnings balance, recent earnings, order items, and a "Request Withdrawal" button
* A public storefront view per vendor, reusing your theme's own Shop page layout
* A "Sold by" line on product pages linking to that vendor's storefront

## Development

Standard WordPress plugin structure:

```
hdwebmobile-vendor-marketplace.php    Bootstrap
includes/class-hdvm-activator.php
includes/class-hdvm-admin.php
includes/class-hdvm-core.php
includes/class-hdvm-dashboard.php
includes/class-hdvm-hub.php
includes/class-hdvm-order.php
includes/class-hdvm-repository.php
includes/class-hdvm-role.php
includes/class-hdvm-storefront.php
includes/class-hdvm-vendor-application.php
```

Part of the [HDWebmobile](https://hdwebmobile.com/plugins/) suite of focused, single-purpose WooCommerce plugins.

## License

GPLv2 or later. See [LICENSE](LICENSE).

