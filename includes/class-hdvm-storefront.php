<?php

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reuses WooCommerce's own Shop page and product-archive template entirely -- no custom
 * template of this plugin's own -- by filtering the main product query when a
 * ?hdvm_vendor={user_id} query var is present. This means every existing theme/plugin
 * customization of the Shop page (grid layout, filters, pagination) keeps working for a
 * vendor's storefront view for free.
 */
final class HDVM_Storefront
{

    const QUERY_VAR = 'hdvm_vendor';

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
        add_filter('query_vars', array($this, 'add_query_var'));
        add_action('woocommerce_product_query', array($this, 'filter_shop_by_vendor'));
        add_action('woocommerce_single_product_summary', array($this, 'render_sold_by'), 6);
    }

    public function add_query_var($vars)
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function filter_shop_by_vendor($query)
    {
        $vendor_id = absint(get_query_var(self::QUERY_VAR));
        if (!$vendor_id || !HDVM_Role::is_vendor($vendor_id)) {
            return;
        }

        $query->set('author', $vendor_id);
    }

    public static function get_storefront_url($vendor_id)
    {
        return add_query_arg(self::QUERY_VAR, $vendor_id, wc_get_page_permalink('shop'));
    }

    public function render_sold_by()
    {
        global $product;
        if (!$product) {
            return;
        }

        $vendor_id = (int) get_post_field('post_author', $product->get_id());
        if (!HDVM_Role::is_vendor($vendor_id)) {
            return;
        }

        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-repository.php';
        $store_name = HDVM_Repository::get_store_name($vendor_id);

        printf(
            '<p class="hdvm-sold-by">%s <a href="%s">%s</a></p>',
            esc_html__('Sold by', 'hdwebmobile-vendor-marketplace'),
            esc_url(self::get_storefront_url($vendor_id)),
            esc_html($store_name)
        );
    }
}
