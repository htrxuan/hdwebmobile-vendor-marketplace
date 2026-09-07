<?php

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Every state-changing handler here explicitly checks current_user_can('manage_woocommerce')
 * and a nonce before touching anything -- application approval is the ONLY code path that
 * grants the hdvm_vendor role (see class-hdvm-role.php), so a self-service applicant can
 * never promote themselves regardless of what they submit on the front-end form.
 */
class HDVM_Admin
{

    const NONCE_DECIDE_APPLICATION = 'hdvm_decide_application';
    const NONCE_DECIDE_WITHDRAWAL  = 'hdvm_decide_withdrawal';

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
        require_once HDVM_PLUGIN_DIR . 'includes/class-hdvm-hub.php';
        add_filter('hdwebmobile_hub_tabs', array($this, 'register_hub_tabs'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_hdvm_decide_application', array($this, 'handle_decide_application'));
        add_action('admin_post_hdvm_decide_withdrawal', array($this, 'handle_decide_withdrawal'));
    }

    public function register_hub_tabs($tabs)
    {
        $tabs['vendor-marketplace'] = array(
            'label'  => __('Vendor Marketplace', 'hdwebmobile-vendor-marketplace'),
            'order'  => 12,
            'render' => array($this, 'render_page'),
        );
        return $tabs;
    }

    public function register_settings()
    {
        register_setting('hdvm_option_group', 'hdvm_options', array($this, 'sanitize'));
    }

    public function sanitize($input)
    {
        $percent = isset($input['commission_percent']) ? (float) $input['commission_percent'] : 20;
        return array('commission_percent' => max(0, min(100, $percent)));
    }

    public function render_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-vendor-marketplace'));
        }

        $options = get_option('hdvm_options', array('commission_percent' => 20));
        ?>
        <p><?php esc_html_e('Turn your store into a multi-vendor marketplace. Approve vendor applications below; approved vendors get their own product-management and earnings dashboard, scoped entirely to their own products and orders.', 'hdwebmobile-vendor-marketplace'); ?></p>

        <h2><?php esc_html_e('Commission', 'hdwebmobile-vendor-marketplace'); ?></h2>
        <form method="post" action="options.php">
            <?php settings_fields('hdvm_option_group'); ?>
            <p>
                <label for="hdvm-commission"><?php esc_html_e('Platform commission (%)', 'hdwebmobile-vendor-marketplace'); ?></label>
                <input type="number" min="0" max="100" step="0.01" id="hdvm-commission" name="hdvm_options[commission_percent]" value="<?php echo esc_attr($options['commission_percent']); ?>" class="small-text" />
            </p>
            <?php submit_button(__('Save Commission', 'hdwebmobile-vendor-marketplace')); ?>
        </form>

        <h2><?php esc_html_e('Vendor Applications', 'hdwebmobile-vendor-marketplace'); ?></h2>
        <?php $this->render_applications_table(); ?>

        <h2><?php esc_html_e('Withdrawal Requests', 'hdwebmobile-vendor-marketplace'); ?></h2>
        <?php $this->render_withdrawals_table(); ?>
        <?php
    }

    private function render_applications_table()
    {
        $applications = HDVM_Repository::get_applications(array('status' => HDVM_Repository::APPLICATION_PENDING));

        if (empty($applications)) {
            echo '<p>' . esc_html__('No pending applications.', 'hdwebmobile-vendor-marketplace') . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:800px;"><thead><tr>';
        echo '<th>' . esc_html__('Applicant', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>' . esc_html__('Store name', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>' . esc_html__('Applied', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>&nbsp;</th>';
        echo '</tr></thead><tbody>';

        foreach ($applications as $application) {
            $user = get_user_by('id', $application->user_id);
            echo '<tr>';
            printf('<td>%s</td>', $user ? esc_html($user->display_name . ' (' . $user->user_email . ')') : esc_html__('(deleted user)', 'hdwebmobile-vendor-marketplace'));
            printf('<td>%s</td>', esc_html($application->store_name));
            printf('<td>%s</td>', esc_html($application->created_at));
            echo '<td>';
            $this->render_decision_buttons('hdvm_decide_application', $application->id, self::NONCE_DECIDE_APPLICATION);
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_withdrawals_table()
    {
        $withdrawals = HDVM_Repository::get_withdrawals(array('status' => HDVM_Repository::WITHDRAWAL_PENDING));

        if (empty($withdrawals)) {
            echo '<p>' . esc_html__('No pending withdrawal requests.', 'hdwebmobile-vendor-marketplace') . '</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:800px;"><thead><tr>';
        echo '<th>' . esc_html__('Vendor', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>' . esc_html__('Amount', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>' . esc_html__('Requested', 'hdwebmobile-vendor-marketplace') . '</th>';
        echo '<th>&nbsp;</th>';
        echo '</tr></thead><tbody>';

        foreach ($withdrawals as $withdrawal) {
            $user = get_user_by('id', $withdrawal->vendor_id);
            echo '<tr>';
            printf('<td>%s</td>', $user ? esc_html($user->display_name) : esc_html__('(deleted user)', 'hdwebmobile-vendor-marketplace'));
            printf('<td>%s</td>', wp_kses_post(wc_price($withdrawal->amount)));
            printf('<td>%s</td>', esc_html($withdrawal->requested_at));
            echo '<td>';
            $this->render_decision_buttons('hdvm_decide_withdrawal', $withdrawal->id, self::NONCE_DECIDE_WITHDRAWAL, __('Mark Paid', 'hdwebmobile-vendor-marketplace'));
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_decision_buttons($action, $id, $nonce_action, $approve_label = null)
    {
        $approve_label = $approve_label ?: __('Approve', 'hdwebmobile-vendor-marketplace');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>" />
            <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>" />
            <input type="hidden" name="decision" value="approve" />
            <?php wp_nonce_field($nonce_action . '_' . $id, 'hdvm_decide_nonce'); ?>
            <?php submit_button($approve_label, 'primary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>" />
            <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>" />
            <input type="hidden" name="decision" value="reject" />
            <?php wp_nonce_field($nonce_action . '_' . $id, 'hdvm_decide_nonce'); ?>
            <?php submit_button(__('Reject', 'hdwebmobile-vendor-marketplace'), 'delete', 'submit', false); ?>
        </form>
        <?php
    }

    public function handle_decide_application()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-vendor-marketplace'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::NONCE_DECIDE_APPLICATION . '_' . $id, 'hdvm_decide_nonce');

        $decision = isset($_POST['decision']) ? sanitize_text_field(wp_unslash($_POST['decision'])) : '';
        $approve  = 'approve' === $decision;

        $application = HDVM_Repository::find_application($id);
        if ($application && HDVM_Repository::APPLICATION_PENDING === $application->status) {
            HDVM_Repository::decide_application($id, $approve);

            if ($approve) {
                $user = get_user_by('id', $application->user_id);
                if ($user) {
                    // The ONLY place in this plugin that grants the hdvm_vendor role.
                    $user->add_role(HDVM_Role::ROLE);
                }
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=hdwebmobile&tab=vendor-marketplace'));
        exit;
    }

    public function handle_decide_withdrawal()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-vendor-marketplace'));
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        check_admin_referer(self::NONCE_DECIDE_WITHDRAWAL . '_' . $id, 'hdvm_decide_nonce');

        $decision = isset($_POST['decision']) ? sanitize_text_field(wp_unslash($_POST['decision'])) : '';
        HDVM_Repository::decide_withdrawal($id, 'approve' === $decision);

        wp_safe_redirect(admin_url('admin.php?page=hdwebmobile&tab=vendor-marketplace'));
        exit;
    }
}
