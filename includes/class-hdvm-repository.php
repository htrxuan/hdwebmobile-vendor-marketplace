<?php

namespace htrxuan\hdvm;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * request_withdrawal() below closes CVE-2026-16577 (a vendor manipulating their
 * reverse-withdrawal ledger because the competing plugin never validated the client-supplied
 * payment amount against the vendor's real balance) by construction: this method takes NO
 * amount parameter from any caller at all. The payable amount is always computed here, fresh,
 * as SUM(vendor_amount) over that vendor's own 'available' ledger rows -- there is no
 * withdrawal code path anywhere in this plugin that accepts a number typed or submitted by
 * the vendor.
 *
 * Direct queries against custom tables are unavoidable here -- there is no WP API for this
 * data -- so DirectDatabaseQuery/NoCaching advisories are expected and accepted for this
 * class, matching standard practice for custom-table plugins.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class HDVM_Repository
{

    const EARNING_AVAILABLE  = 'available';
    const EARNING_WITHDRAWN  = 'withdrawn';

    const WITHDRAWAL_PENDING = 'pending';
    const WITHDRAWAL_PAID    = 'paid';
    const WITHDRAWAL_REJECTED = 'rejected';

    const APPLICATION_PENDING  = 'pending';
    const APPLICATION_APPROVED = 'approved';
    const APPLICATION_REJECTED = 'rejected';

    public static function earnings_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'hdvm_earnings';
    }

    public static function withdrawals_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'hdvm_withdrawals';
    }

    public static function applications_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'hdvm_vendor_applications';
    }

    public static function get_schema_sql()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $earnings        = self::earnings_table();
        $withdrawals     = self::withdrawals_table();
        $applications    = self::applications_table();

        return "CREATE TABLE {$earnings} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            order_item_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            gross_amount DECIMAL(19,4) NOT NULL,
            commission_amount DECIMAL(19,4) NOT NULL,
            vendor_amount DECIMAL(19,4) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'available',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_item_id (order_item_id),
            KEY vendor_id_status (vendor_id, status)
        ) {$charset_collate};
        CREATE TABLE {$withdrawals} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vendor_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(19,4) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            requested_at DATETIME NOT NULL,
            decided_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY vendor_id (vendor_id),
            KEY status (status)
        ) {$charset_collate};
        CREATE TABLE {$applications} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            store_name VARCHAR(200) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            decided_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status)
        ) {$charset_collate};";
    }

    /**
     * Idempotent by construction: order_item_id carries a UNIQUE key, so if an order-status
     * transition hook ever fires twice for the same order (already guarded upstream too, see
     * class-hdvm-order.php) this simply fails the second insert rather than double-crediting
     * a vendor.
     */
    public static function record_earning($vendor_id, $order_id, $order_item_id, $product_id, $gross_amount, $commission_amount, $vendor_amount)
    {
        global $wpdb;
        return false !== $wpdb->insert(
            self::earnings_table(),
            array(
                'vendor_id'          => $vendor_id,
                'order_id'           => $order_id,
                'order_item_id'      => $order_item_id,
                'product_id'         => $product_id,
                'gross_amount'       => $gross_amount,
                'commission_amount'  => $commission_amount,
                'vendor_amount'      => $vendor_amount,
                'status'             => self::EARNING_AVAILABLE,
                'created_at'         => current_time('mysql'),
            ),
            array('%d', '%d', '%d', '%d', '%f', '%f', '%f', '%s', '%s')
        );
    }

    public static function get_available_balance($vendor_id)
    {
        global $wpdb;
        $sum = $wpdb->get_var($wpdb->prepare(
            'SELECT SUM(vendor_amount) FROM %i WHERE vendor_id = %d AND status = %s',
            self::earnings_table(),
            $vendor_id,
            self::EARNING_AVAILABLE
        ));
        return $sum ? (float) $sum : 0.0;
    }

    public static function get_earnings_for_vendor($vendor_id, $limit = 50)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE vendor_id = %d ORDER BY created_at DESC LIMIT %d',
            self::earnings_table(),
            $vendor_id,
            $limit
        ));
    }

    /**
     * The only way a withdrawal request is ever created. No $amount parameter -- see the
     * class docblock above.
     */
    public static function request_withdrawal($vendor_id)
    {
        global $wpdb;

        $balance = self::get_available_balance($vendor_id);
        if ($balance <= 0) {
            return new \WP_Error('no_balance', __('No available balance to withdraw.', 'hdwebmobile-vendor-marketplace'));
        }

        $wpdb->insert(
            self::withdrawals_table(),
            array(
                'vendor_id'     => $vendor_id,
                'amount'        => $balance,
                'status'        => self::WITHDRAWAL_PENDING,
                'requested_at'  => current_time('mysql'),
            ),
            array('%d', '%f', '%s', '%s')
        );
        $withdrawal_id = (int) $wpdb->insert_id;

        // Reserve the earnings this withdrawal covers immediately, so a second request
        // submitted before this one is decided can never draw on the same balance twice.
        $wpdb->update(
            self::earnings_table(),
            array('status' => self::EARNING_WITHDRAWN),
            array('vendor_id' => $vendor_id, 'status' => self::EARNING_AVAILABLE),
            array('%s'),
            array('%d', '%s')
        );

        return $withdrawal_id;
    }

    /**
     * Admin-only: callers must have already checked current_user_can('manage_woocommerce')
     * and verified a nonce (see class-hdvm-admin.php) -- this method itself does not
     * re-check capability, matching every other admin-only repository method in this suite.
     */
    public static function decide_withdrawal($id, $approve)
    {
        global $wpdb;
        $withdrawal = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::withdrawals_table(), $id));
        if (!$withdrawal || self::WITHDRAWAL_PENDING !== $withdrawal->status) {
            return false;
        }

        $wpdb->update(
            self::withdrawals_table(),
            array('status' => $approve ? self::WITHDRAWAL_PAID : self::WITHDRAWAL_REJECTED, 'decided_at' => current_time('mysql')),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );

        if (!$approve) {
            // Release the reserved earnings back to available so the vendor can request again.
            $wpdb->update(
                self::earnings_table(),
                array('status' => self::EARNING_AVAILABLE),
                array('vendor_id' => $withdrawal->vendor_id, 'status' => self::EARNING_WITHDRAWN),
                array('%s'),
                array('%d', '%s')
            );
        }

        return true;
    }

    public static function get_withdrawals(array $args = array())
    {
        global $wpdb;
        $where  = array('1=1');
        $params = array();

        if (!empty($args['vendor_id'])) {
            $where[]  = 'vendor_id = %d';
            $params[] = (int) $args['vendor_id'];
        }
        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        $where_sql = implode(' AND ', $where);
        $limit     = (int) ($args['limit'] ?? 100);

        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            "SELECT * FROM %i WHERE {$where_sql} ORDER BY requested_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge(array(self::withdrawals_table()), $params, array($limit))
        ));
    }

    public static function create_application($user_id, $store_name)
    {
        global $wpdb;
        $wpdb->insert(
            self::applications_table(),
            array(
                'user_id'    => $user_id,
                'store_name' => $store_name,
                'status'     => self::APPLICATION_PENDING,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s')
        );
        return (int) $wpdb->insert_id;
    }

    public static function has_pending_or_approved_application($user_id)
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE user_id = %d AND status IN (%s, %s) LIMIT 1',
            self::applications_table(),
            $user_id,
            self::APPLICATION_PENDING,
            self::APPLICATION_APPROVED
        ));
    }

    public static function get_applications(array $args = array())
    {
        global $wpdb;
        $where  = array('1=1');
        $params = array();

        if (!empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        $where_sql = implode(' AND ', $where);
        $limit     = (int) ($args['limit'] ?? 100);

        return $wpdb->get_results($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            "SELECT * FROM %i WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge(array(self::applications_table()), $params, array($limit))
        ));
    }

    public static function find_application($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::applications_table(), $id));
    }

    public static function decide_application($id, $approve)
    {
        global $wpdb;
        return false !== $wpdb->update(
            self::applications_table(),
            array('status' => $approve ? self::APPLICATION_APPROVED : self::APPLICATION_REJECTED, 'decided_at' => current_time('mysql')),
            array('id' => (int) $id),
            array('%s', '%s'),
            array('%d')
        );
    }

    public static function get_store_name($vendor_id)
    {
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare(
            'SELECT store_name FROM %i WHERE user_id = %d AND status = %s ORDER BY decided_at DESC LIMIT 1',
            self::applications_table(),
            $vendor_id,
            self::APPLICATION_APPROVED
        ));
        return $name ?: get_the_author_meta('display_name', $vendor_id);
    }
}
