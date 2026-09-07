<?php

/**
 * Plugin Name: HDWebmobile Vendor Marketplace
 * Plugin URI: https://hdwebmobile.com/plugins/hdwebmobile-vendor-marketplace/
 * Description: Turn a WooCommerce store into a multi-vendor marketplace. Vendors can only ever touch their own products, order line items, and earnings -- enforced by WordPress's own core capability system and explicit ownership checks, never by trusting a request.
 * Version: 1.0.0
 * Author: htrxuan - Han Tran
 * Author URI: https://hdwebmobile.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hdwebmobile-vendor-marketplace
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.9
 */

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
define('HDVM_VERSION', '1.0.0');
define('HDVM_DB_VERSION', '1.0.0');
define('HDVM_PLUGIN_FILE', __FILE__);
define('HDVM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HDVM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-activator.php';

register_activation_hook(HDVM_PLUGIN_FILE, array(HDVM_Activator::class, 'activate'));
register_deactivation_hook(HDVM_PLUGIN_FILE, array(HDVM_Activator::class, 'deactivate'));
add_action('before_woocommerce_init', array(HDVM_Activator::class, 'declare_hpos_compatibility'));

add_action('plugins_loaded', function () {
    require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-core.php';
    HDVM_Core::get_instance();
});

add_filter('plugin_action_links_' . plugin_basename(HDVM_PLUGIN_FILE), function ($links) {
    $donate_link = '<a href="https://paypal.me/htrxuan/20" target="_blank" style="color:#d54e21;font-weight:bold;">' . __('Donate', 'hdwebmobile-vendor-marketplace') . '</a>';
    array_unshift($links, $donate_link);
    return $links;
});
