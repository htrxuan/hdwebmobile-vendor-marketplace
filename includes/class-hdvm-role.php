<?php

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Closes CVE-2026-11987 (IDOR letting a subscriber-level account read other vendors'
 * unpublished/draft products) by construction, not by writing our own ownership-check code.
 * WooCommerce registers the 'product' post type with `capability_type => 'product'` and
 * `map_meta_cap => true` (confirmed by reading class-wc-post-types.php directly), which means
 * WordPress core's own map_meta_cap() already enforces per-author ownership on every
 * product-editing capability check -- `edit_product`, `delete_product`, etc. -- exactly the
 * same trusted mechanism that keeps a core "Author" role from touching another author's post.
 *
 * The vendor role below is deliberately built by granting only the capabilities a vendor
 * needs and NEVER granting the "others"/"private" variants -- `edit_others_products`,
 * `delete_others_products`, `read_private_products` are never added. That single omission is
 * the entire fix: it is WordPress core itself, not this plugin, that then refuses any attempt
 * by a vendor to edit, delete, or view another vendor's product, on every screen and every
 * REST/XML-RPC/CLI path a future WordPress version might add -- not just the ones this plugin
 * happens to have thought to check.
 */
class HDVM_Role
{

    const ROLE = 'hdvm_vendor';

    public static function register()
    {
        remove_role(self::ROLE); // idempotent: re-registering with fresh caps on every activation/upgrade

        add_role(self::ROLE, __('Vendor', 'hdwebmobile-vendor-marketplace'), array(
            'read'                    => true,
            'upload_files'            => true,
            'edit_products'           => true,
            'edit_published_products' => true,
            'publish_products'        => true,
            'delete_products'         => true,
            'delete_published_products' => true,
            // Deliberately absent: edit_others_products, delete_others_products,
            // edit_private_products, delete_private_products, read_private_products,
            // manage_woocommerce, edit_shop_orders, edit_others_shop_orders -- a vendor
            // never gets WooCommerce's own order-management screen at all; their only
            // window onto orders is class-hdvm-dashboard.php's explicitly ownership-checked
            // per-line-item view.
        ));
    }

    public static function deregister()
    {
        remove_role(self::ROLE);
    }

    public static function is_vendor($user_id = null)
    {
        $user = $user_id ? get_user_by('id', $user_id) : wp_get_current_user();
        return $user && $user->exists() && in_array(self::ROLE, (array) $user->roles, true);
    }
}
