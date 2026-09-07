<?php

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Closes CVE-2026-16564 (a Dokan REST endpoint that let one vendor account change the status
 * of ANY order on the marketplace, including other vendors' and the store's own, because
 * ownership was never verified) by construction: this plugin never lets a vendor touch a
 * WooCommerce order's own status at all -- there is no code path here or in
 * class-hdvm-dashboard.php that calls $order->set_status() from vendor-facing code. Instead,
 * each vendor can only set a small custom "shipped" flag on their OWN order line item
 * (set_item_status() below), and every single call site verifies the product's actual
 * post_author equals the acting vendor's own user id before touching anything.
 */
final class HDVM_Order
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('woocommerce_order_status_completed', array($this, 'record_earnings'));
    }

    /**
     * Idempotent: guarded by both an order-level meta flag (fast path) and the earnings
     * table's UNIQUE KEY on order_item_id (hard backstop, in case this ever fires twice
     * concurrently) -- a vendor is never credited twice for the same line item.
     */
    public function record_earnings($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        if ('yes' === $order->get_meta('_hdvm_earnings_recorded')) {
            return;
        }

        $commission_percent = self::get_commission_percent();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $vendor_id = (int) get_post_field('post_author', $product->get_id());
            require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-role.php';
            if (!$vendor_id || !HDVM_Role::is_vendor($vendor_id)) {
                continue; // Not a vendor-owned product -- nothing to split.
            }

            $gross      = (float) $item->get_total();
            $commission = round($gross * ($commission_percent / 100), 4);
            $vendor_amt = round($gross - $commission, 4);

            HDVM_Repository::record_earning(
                $vendor_id,
                $order_id,
                $item->get_id(),
                $product->get_id(),
                $gross,
                $commission,
                $vendor_amt
            );
        }

        $order->update_meta_data('_hdvm_earnings_recorded', 'yes');
        $order->save();
    }

    public static function get_commission_percent()
    {
        $options = get_option('hdvm_options', array('commission_percent' => 20));
        $percent = isset($options['commission_percent']) ? (float) $options['commission_percent'] : 20;
        return max(0, min(100, $percent));
    }

    /**
     * The only place a vendor's per-item shipping status is ever written. Returns a
     * WP_Error (never silently fails) if the acting user does not actually own the
     * product behind this specific order item -- the check that closes CVE-2026-16564.
     */
    public static function set_item_status($order_id, $item_id, $status, $acting_user_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('no_order', __('Order not found.', 'hdwebmobile-vendor-marketplace'));
        }

        $item = $order->get_item($item_id);
        if (!$item) {
            return new \WP_Error('no_item', __('Order item not found.', 'hdwebmobile-vendor-marketplace'));
        }

        $product = $item->get_product();
        if (!$product) {
            return new \WP_Error('no_product', __('Product no longer exists.', 'hdwebmobile-vendor-marketplace'));
        }

        $owner_id = (int) get_post_field('post_author', $product->get_id());
        if ($owner_id !== (int) $acting_user_id) {
            return new \WP_Error('not_owner', __('You do not own the product on this order line.', 'hdwebmobile-vendor-marketplace'));
        }

        $allowed = array('processing', 'shipped');
        if (!in_array($status, $allowed, true)) {
            return new \WP_Error('bad_status', __('Invalid status.', 'hdwebmobile-vendor-marketplace'));
        }

        $item->update_meta_data('_hdvm_item_status', $status);
        $item->save();

        return true;
    }

    public static function find_items_for_vendor($vendor_id, $limit = 50)
    {
        $items = array();

        $orders = wc_get_orders(array(
            'limit'   => 200, // scanned, not all returned -- see the per-item filter below.
            'orderby' => 'date',
            'order'   => 'DESC',
        ));

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) {
                    continue;
                }
                if ((int) get_post_field('post_author', $product->get_id()) !== (int) $vendor_id) {
                    continue;
                }

                $items[] = array(
                    'order'   => $order,
                    'item'    => $item,
                    'status'  => $item->get_meta('_hdvm_item_status') ?: 'processing',
                );

                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }
}
