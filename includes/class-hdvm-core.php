<?php

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

final class HDVM_Core
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
        $this->includes();
        $this->init_hooks();
    }

    private function __clone()
    {
    }

    private function includes()
    {
        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-repository.php';
        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-role.php';
        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-vendor-application.php';
        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-order.php';
        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-dashboard.php';
        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-storefront.php';
        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-admin.php';
    }

    private function init_hooks()
    {
        add_action('admin_notices', array($this, 'render_missing_woocommerce_notice'));
        add_action('admin_init', array(HDVM_Activator::class, 'maybe_upgrade_db'));

        if (!class_exists('WooCommerce')) {
            return;
        }

        HDVM_Vendor_Application::get_instance();
        HDVM_Order::get_instance();
        HDVM_Dashboard::get_instance();
        HDVM_Storefront::get_instance();

        // HDVM_Admin registers admin-menu/settings hooks itself, but this must load
        // unconditionally (not only when is_admin()) since it also owns the
        // hdwebmobile_hub_tabs registration used by the shared hub page.
        HDVM_Admin::get_instance();
    }

    public function render_missing_woocommerce_notice()
    {
        $screen = get_current_screen();
        if (!$screen || 'plugins' !== $screen->id) {
            return;
        }

        if (!get_transient('hdvm_wc_missing_notice')) {
            return;
        }
        delete_transient('hdvm_wc_missing_notice');
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php esc_html_e('HDWebmobile Vendor Marketplace requires WooCommerce to be installed and active. The plugin has been deactivated.', 'hdwebmobile-vendor-marketplace'); ?>
            </p>
        </div>
        <?php
    }
}
