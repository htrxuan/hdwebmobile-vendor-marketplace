<?php

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A dedicated wp-admin page visible only to users who actually hold the hdvm_vendor role
 * (checked explicitly before the menu item is even registered, not just capability-gated) --
 * a vendor's only windows onto orders and earnings. Every action here is scoped to the
 * currently logged-in vendor's own user id; see class-hdvm-order.php and
 * class-hdvm-repository.php for where the actual ownership/amount guarantees live.
 */
final class HDVM_Dashboard
{

    const NONCE_SHIP   = 'hdvm_mark_shipped';
    const NONCE_WITHDRAW = 'hdvm_request_withdrawal';

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
        add_action('admin_menu', array($this, 'maybe_add_menu'));
        add_action('admin_post_hdvm_mark_shipped', array($this, 'handle_mark_shipped'));
        add_action('admin_post_hdvm_request_withdrawal', array($this, 'handle_request_withdrawal'));
    }

    public function maybe_add_menu()
    {
        if (!HDVM_Role::is_vendor(get_current_user_id())) {
            return;
        }

        add_menu_page(
            __('Vendor Dashboard', 'hdwebmobile-vendor-marketplace'),
            __('Vendor Dashboard', 'hdwebmobile-vendor-marketplace'),
            'edit_products',
            'hdvm-vendor-dashboard',
            array($this, 'render_page'),
            'dashicons-store',
            56
        );
    }

    public function render_page()
    {
        $vendor_id = get_current_user_id();

        if (isset($_GET['hdvm_error'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag from a post/redirect/get after the actual submission already passed nonce+ownership verification in the handlers below.
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['hdvm_error']))) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized and escaped on the line itself; a read-only display message, not used for any state change.
        }

        echo '<div class="wrap"><h1>' . esc_html__('Vendor Dashboard', 'hdwebmobile-vendor-marketplace') . '</h1>';

        $this->render_earnings_section($vendor_id);
        $this->render_orders_section($vendor_id);

        echo '<p><a href="' . esc_url(admin_url('edit.php?post_type=product')) . '" class="button">' . esc_html__('Manage My Products', 'hdwebmobile-vendor-marketplace') . '</a> ';
        echo '<a href="' . esc_url(admin_url('post-new.php?post_type=product')) . '" class="button button-primary">' . esc_html__('Add New Product', 'hdwebmobile-vendor-marketplace') . '</a></p>';

        echo '</div>';
    }

    private function render_earnings_section($vendor_id)
    {
        $balance = HDVM_Repository::get_available_balance($vendor_id);

        echo '<h2>' . esc_html__('Earnings', 'hdwebmobile-vendor-marketplace') . '</h2>';
        printf('<p><strong>%s</strong></p>', wp_kses_post(wc_price($balance)));

        if ($balance > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="hdvm_request_withdrawal" />';
            wp_nonce_field(self::NONCE_WITHDRAW, 'hdvm_withdraw_nonce');
            echo '<button type="submit" class="button button-primary">' . esc_html__('Request Withdrawal', 'hdwebmobile-vendor-marketplace') . '</button>';
            echo '</form>';
        }

        $recent = HDVM_Repository::get_earnings_for_vendor($vendor_id, 10);
        if (!empty($recent)) {
            echo '<table class="widefat striped" style="max-width:700px;margin-top:1em;"><thead><tr>';
            echo '<th>' . esc_html__('Order', 'hdwebmobile-vendor-marketplace') . '</th>';
            echo '<th>' . esc_html__('Your share', 'hdwebmobile-vendor-marketplace') . '</th>';
            echo '<th>' . esc_html__('Status', 'hdwebmobile-vendor-marketplace') . '</th>';
            echo '<th>' . esc_html__('Date', 'hdwebmobile-vendor-marketplace') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($recent as $row) {
                echo '<tr>';
                printf('<td>#%d</td>', (int) $row->order_id);
                printf('<td>%s</td>', wp_kses_post(wc_price($row->vendor_amount)));
                printf('<td>%s</td>', esc_html($row->status));
                printf('<td>%s</td>', esc_html($row->created_at));
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
    }

    private function render_orders_section($vendor_id)
    {
        $entries = HDVM_Order::find_items_for_vendor($vendor_id, 50);

        echo '<h2>' . esc_html__('My Order Items', 'hdwebmobile-vendor-marketplace') . '</h2>';

        if (empty($entries)) {
            echo '<p>' . esc_html__('No orders yet.', 'hdwebmobile-vendor-marketplace') . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
        echo '<th>' . esc_html__('Order', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>' . esc_html__('Product', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>' . esc_html__('Qty', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>' . esc_html__('Status', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>&nbsp;</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $order  = $entry['order'];
            $item   = $entry['item'];
            $status = $entry['status'];

            echo '<tr>';
            printf('<td>#%s</td>', esc_html($order->get_order_number()));
            printf('<td>%s</td>', esc_html($item->get_name()));
            printf('<td>%s</td>', esc_html($item->get_quantity()));
            printf('<td>%s</td>', esc_html($status));
            echo '<td>';
            if ('shipped' !== $status) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="hdvm_mark_shipped" />';
                printf('<input type="hidden" name="order_id" value="%d" />', esc_attr($order->get_id()));
                printf('<input type="hidden" name="item_id" value="%d" />', esc_attr($item->get_id()));
                wp_nonce_field(self::NONCE_SHIP . '_' . $item->get_id(), 'hdvm_ship_nonce');
                echo '<button type="submit" class="button button-small">' . esc_html__('Mark Shipped', 'hdwebmobile-vendor-marketplace') . '</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function handle_mark_shipped()
    {
        if (!HDVM_Role::is_vendor(get_current_user_id())) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-vendor-marketplace'));
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $item_id  = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;

        check_admin_referer(self::NONCE_SHIP . '_' . $item_id, 'hdvm_ship_nonce');

        $result = HDVM_Order::set_item_status($order_id, $item_id, 'shipped', get_current_user_id());

        $redirect = admin_url('admin.php?page=hdvm-vendor-dashboard');
        if (is_wp_error($result)) {
            $redirect = add_query_arg('hdvm_error', rawurlencode($result->get_error_message()), $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_request_withdrawal()
    {
        if (!HDVM_Role::is_vendor(get_current_user_id())) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-vendor-marketplace'));
        }

        check_admin_referer(self::NONCE_WITHDRAW, 'hdvm_withdraw_nonce');

        // Deliberately no amount read from $_POST anywhere in this handler -- see
        // class-hdvm-repository.php's request_withdrawal() docblock.
        $result = HDVM_Repository::request_withdrawal(get_current_user_id());

        $redirect = admin_url('admin.php?page=hdvm-vendor-dashboard');
        if (is_wp_error($result)) {
            $redirect = add_query_arg('hdvm_error', rawurlencode($result->get_error_message()), $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
