<?php

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

class HDVM_Activator
{

    public static function activate()
    {
        if (!self::is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(HDVM_PLUGIN_FILE));
            set_transient('hdvm_wc_missing_notice', true, 30);
            return;
        }

        self::maybe_upgrade_db();

        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-role.php';
        HDVM_Role::register();

        if (false === get_option('hdvm_options')) {
            add_option('hdvm_options', array('commission_percent' => 20));
        }
    }

    public static function deactivate()
    {
        // Deliberately does NOT deregister the hdvm_vendor role or touch any vendor's
        // existing products/earnings on deactivation -- a merchant temporarily disabling
        // the plugin should not silently strip vendor accounts of their role or lose ledger
        // history. Role/data cleanup, if ever wanted, belongs in an explicit uninstall.php,
        // not here.
        flush_rewrite_rules();
    }

    public static function is_woocommerce_active()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('woocommerce/woocommerce.php') || class_exists('WooCommerce');
    }

    public static function maybe_upgrade_db()
    {
        if (get_option('hdvm_db_version') === HDVM_DB_VERSION) {
            return;
        }

        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-repository.php';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(HDVM_Repository::get_schema_sql());

        update_option('hdvm_db_version', HDVM_DB_VERSION);
    }

    public static function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', HDVM_PLUGIN_FILE, true);
        }
    }
}
