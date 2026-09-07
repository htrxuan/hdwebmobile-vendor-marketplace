<?php

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The application form only ever creates a pending record -- it cannot itself grant the
 * hdvm_vendor role. Only class-hdvm-admin.php's approve handler (explicit
 * current_user_can('manage_woocommerce') + nonce) can promote a user, so there is no
 * self-service path to becoming a vendor.
 */
final class HDVM_Vendor_Application
{

    const NONCE_ACTION = 'hdvm_apply';

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
        add_action('woocommerce_account_dashboard', array($this, 'render_application_prompt'));
        add_action('template_redirect', array($this, 'handle_submission'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
    }

    public function maybe_enqueue_assets()
    {
        if (is_account_page() || is_product() || is_shop()) {
            wp_enqueue_style('hdvm-frontend', HDVM_PLUGIN_URL . 'assets/css/hdvm-frontend.css', array(), HDVM_VERSION);
        }
    }

    public function render_application_prompt()
    {
        $user_id = get_current_user_id();
        if (!$user_id || HDVM_Role::is_vendor($user_id)) {
            return;
        }

        if (isset($_GET['hdvm_applied'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a post/redirect/get after the actual submission already passed nonce verification in handle_submission().
            echo '<div class="hdvm-notice hdvm-success">' . esc_html__('Thanks! Your vendor application has been submitted for review.', 'hdwebmobile-vendor-marketplace') . '</div>';
            return;
        }

        if (HDVM_Repository::has_pending_or_approved_application($user_id)) {
            echo '<p class="hdvm-pending-notice">' . esc_html__('Your vendor application is awaiting review.', 'hdwebmobile-vendor-marketplace') . '</p>';
            return;
        }

        echo '<div class="hdvm-apply-box">';
        echo '<h3>' . esc_html__('Sell on our marketplace', 'hdwebmobile-vendor-marketplace') . '</h3>';
        echo '<form method="post" class="hdvm-apply-form">';
        wp_nonce_field(self::NONCE_ACTION, 'hdvm_nonce');
        printf(
            '<p><label for="hdvm_store_name">%s</label><input type="text" id="hdvm_store_name" name="hdvm_store_name" required /></p>',
            esc_html__('Store name', 'hdwebmobile-vendor-marketplace')
        );
        echo '<button type="submit" class="woocommerce-button button wp-element-button">' . esc_html__('Apply to become a vendor', 'hdwebmobile-vendor-marketplace') . '</button>';
        echo '</form>';
        echo '</div>';
    }

    public function handle_submission()
    {
        if (!is_account_page() || !is_user_logged_in()) {
            return;
        }

        if (!isset($_POST['hdvm_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdvm_nonce'])), self::NONCE_ACTION)) {
            return;
        }

        $user_id = get_current_user_id();
        if (HDVM_Role::is_vendor($user_id) || HDVM_Repository::has_pending_or_approved_application($user_id)) {
            return;
        }

        $store_name = isset($_POST['hdvm_store_name']) ? sanitize_text_field(wp_unslash($_POST['hdvm_store_name'])) : '';
        if ('' === $store_name) {
            return;
        }

        HDVM_Repository::create_application($user_id, $store_name);

        wp_safe_redirect(add_query_arg('hdvm_applied', 1, wc_get_page_permalink('myaccount')));
        exit;
    }
}
