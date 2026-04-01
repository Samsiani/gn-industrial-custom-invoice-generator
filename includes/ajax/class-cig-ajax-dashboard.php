<?php
if (!defined('ABSPATH')) {
    exit;
}

class CIG_Ajax_Dashboard {
    private $security;
    private $stock;

    public function __construct($security, $stock) {
        $this->security = $security;
        $this->stock = $stock;

        // General Dashboard Actions
        add_action('wp_ajax_cig_get_my_invoices', [$this, 'get_my_invoices']);
        add_action('wp_ajax_cig_get_expiring_reservations', [$this, 'get_expiring_reservations']);
        
        // Accountant Dashboard Actions
        add_action('wp_ajax_cig_get_accountant_invoices', [$this, 'get_accountant_invoices']);
        add_action('wp_ajax_cig_set_invoice_status', [$this, 'set_invoice_status']);
        add_action('wp_ajax_cig_update_accountant_note', [$this, 'update_accountant_note']);
    }

    private function get_status_meta_query($status) {
        if ($status === 'all') return [];
        if ($status === 'fictive') return [['key' => '_cig_invoice_status', 'value' => 'fictive', 'compare' => '=']];
        if ($status === 'reserved') return [
            ['key' => '_cig_lifecycle_status', 'value' => 'reserved', 'compare' => '='],
            ['relation' => 'OR', ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='], ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']]
        ];
        return [['relation' => 'OR', ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='], ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']]];
    }

    /**
     * Standard Consultant Dashboard Logic (Preserved)
     */
    public function get_my_invoices() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce');
        $uid = get_current_user_id();
        if (!$uid) wp_send_json_error(['message' => 'Not logged in'], 401);

        $filter = sanitize_text_field($_POST['filter'] ?? 'all');
        $search = sanitize_text_field($_POST['search'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? 'standard');
        $paged  = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;

        $args = [
            'post_type' => 'invoice',
            'post_status' => 'publish',
            'author' => $uid,
            'posts_per_page' => 20,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC'
        ];

        $meta = $this->get_status_meta_query($status);
        if ($search) {
            $meta[] = [
                'relation' => 'OR',
                ['key' => '_cig_invoice_number', 'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_cig_buyer_name',     'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_cig_buyer_tax_id',   'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_cig_buyer_phone',    'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_cig_buyer_email',    'value' => $search, 'compare' => 'LIKE'],
            ];
        }
        if ($meta) {
            $args['meta_query'] = $meta;
        }

        if ($filter === 'today') {
            $args['date_query'] = [['after' => date('Y-m-d 00:00:00'), 'inclusive' => true]];
        } elseif ($filter === 'this_week') {
            $args['date_query'] = [['after' => date('Y-m-d 00:00:00', strtotime('monday this week')), 'inclusive' => true]];
        } elseif ($filter === 'this_month') {
            $args['date_query'] = [['after' => date('Y-m-01 00:00:00'), 'inclusive' => true]];
        }
        
        $query = new WP_Query($args);
        $invoices = [];
        
        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();
            $pt = get_post_meta($id, '_cig_payment_type', true);
            $items = get_post_meta($id, '_cig_items', true) ?: [];
            $ist = get_post_meta($id, '_cig_invoice_status', true) ?: 'standard';

            $has_s = false; $has_r = false; $has_c = false;
            foreach ($items as $it) {
                $s = strtolower($it['status'] ?? 'sold');
                if ($s === 'sold') $has_s = true;
                if ($s === 'reserved') $has_r = true;
                if ($s === 'canceled') $has_c = true;
            }

            $invoices[] = [
                'id' => $id,
                'invoice_number' => get_post_meta($id, '_cig_invoice_number', true),
                'date' => get_the_date('Y-m-d H:i:s'),
                'invoice_total' => get_post_meta($id, '_cig_invoice_total', true),
                'payment_type' => $pt,
                'payment_label' => CIG_Invoice::get_payment_types()[$pt] ?? $pt,
                'has_sold' => $has_s,
                'has_reserved' => $has_r,
                'has_canceled' => $has_c,
                'status' => $ist,
                'view_url' => get_permalink($id),
                'edit_url' => add_query_arg('edit', '1', get_permalink($id))
            ];
        }
        
        // Stats Logic
        $t_args = [
            'post_type' => 'invoice',
            'post_status' => 'publish',
            'author' => $uid,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => $this->get_status_meta_query('standard')
        ];
        $tq = new WP_Query($t_args);
        $t_res = 0;
        foreach ($tq->posts as $tid) {
            $its = get_post_meta($tid, '_cig_items', true) ?: [];
            foreach ($its as $it) {
                if (strtolower($it['status'] ?? 'sold') === 'reserved') {
                    $t_res += floatval($it['qty']);
                }
            }
        }
        
        wp_send_json_success([
            'invoices' => $invoices,
            'total_pages'  => $query->max_num_pages,
            'current_page' => $paged,
            'total_items'  => $query->found_posts,
            'stats' => [
                'total_invoices' => $tq->found_posts,
                'last_invoice_date' => !empty($invoices) ? $invoices[0]['date'] : '',
                'total_reserved' => $t_res
            ]
        ]);
    }

    public function get_expiring_reservations() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce');
        $uid = get_current_user_id();
        if (!$uid) wp_send_json_error(['message' => 'Not logged in'], 401);

        $args = [
            'post_type' => 'invoice',
            'post_status' => 'publish',
            'author' => $uid,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => $this->get_status_meta_query('standard')
        ];

        $query = new WP_Query($args);
        $expiring = [];
        $now = current_time('timestamp');
        $threshold = $now + (3 * DAY_IN_SECONDS);

        foreach ($query->posts as $id) {
            $items = get_post_meta($id, '_cig_items', true) ?: [];
            $inv_date = get_post_field('post_date', $id);
            $inv_num = get_post_meta($id, '_cig_invoice_number', true);

            foreach ($items as $it) {
                if (strtolower($it['status'] ?? 'sold') !== 'reserved') continue;
                $days = intval($it['reservation_days'] ?? 0);
                if ($days <= 0) continue;

                $exp = strtotime($inv_date . ' +' . $days . ' days');
                if ($exp > $now && $exp <= $threshold) {
                    $expiring[] = [
                        'invoice_id' => $id,
                        'invoice_number' => $inv_num,
                        'product_name' => $it['name'] ?? 'Unknown',
                        'product_sku' => $it['sku'] ?? '',
                        'quantity' => floatval($it['qty'] ?? 0),
                        'expires_date' => date('Y-m-d H:i:s', $exp),
                        'days_left' => ceil(($exp - $now) / DAY_IN_SECONDS),
                        'edit_url' => add_query_arg('edit', '1', get_permalink($id))
                    ];
                }
            }
        }

        usort($expiring, function($a, $b){ return $a['days_left'] <=> $b['days_left']; });
        wp_send_json_success(['expiring' => $expiring, 'count' => count($expiring)]);
    }

    /**
     * ACCOUNTANT: Get Invoices
     * Updated logic: Only show invoices where ALL products are SOLD (_cig_lifecycle_status = completed)
     */
    public function get_accountant_invoices() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'read');
        
        $paged = isset($_POST['paged']) ? max(1, intval($_POST['paged'])) : 1;
        $search = sanitize_text_field($_POST['search'] ?? '');
        
        // New Filters
        $completion = sanitize_text_field($_POST['completion'] ?? 'all'); // 'completed', 'incomplete', 'all'
        $type_filter = sanitize_text_field($_POST['type_filter'] ?? 'all'); // 'rs', 'credit', 'receipt', 'corrected', 'all'
        
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');

        $args = [
            'post_type'      => 'invoice',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'paged'          => $paged,
            'fields'         => 'ids', 
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                ['relation' => 'OR', ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='], ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']],
                // Ensure total > 0
                ['key' => '_cig_invoice_total', 'value' => 0, 'compare' => '>', 'type' => 'DECIMAL'],
                // --- UPDATE: Show invoices where lifecycle is completed OR reserved ---
                [
                    'key'     => '_cig_lifecycle_status',
                    'value'   => ['completed', 'reserved'],
                    'compare' => 'IN'
                ]
            ]
        ];

        // 1. Completion Filter (Accountant Status)
        if ($completion === 'incomplete') {
            // Must NOT have an accountant status
            $args['meta_query'][] = [
                'key' => '_cig_acc_status',
                'compare' => 'NOT EXISTS'
            ];
        } elseif ($completion === 'completed') {
            // Must HAVE an accountant status
            $args['meta_query'][] = [
                'key' => '_cig_acc_status',
                'compare' => 'EXISTS'
            ];
        }

        // 2. Type Filter (Accountant Dropdown)
        if ($type_filter && $type_filter !== 'all') {
            $args['meta_query'][] = [
                'key' => '_cig_acc_status',
                'value' => $type_filter,
                'compare' => '='
            ];
        }

        // 3. Date & Search
        if ($date_from && $date_to) {
            $args['date_query'] = [[
                'after'     => $date_from . ' 00:00:00',
                'before'    => $date_to   . ' 23:59:59',
                'inclusive' => true
            ]];
        }
        if ($search) {
            $args['meta_query'][] = [
                'relation' => 'OR',
                ['key' => '_cig_invoice_number', 'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_cig_buyer_name',    'value' => $search, 'compare' => 'LIKE'],
                ['key' => '_cig_buyer_tax_id',  'value' => $search, 'compare' => 'LIKE']
            ];
        }

        $query = new WP_Query($args);
        
        $method_labels = [
            'company_transfer' => 'კომპანია',
            'cash'             => 'ქეში',
            'consignment'      => 'კონსიგნაცია',
            'credit'           => 'განვადება',
            'other'            => 'სხვა',
            'mixed'            => 'შერეული'
        ];

        $invoices = [];
        foreach ($query->posts as $id) {
            $status = get_post_meta($id, '_cig_acc_status', true); 

            $buyer_name  = get_post_meta($id, '_cig_buyer_name', true) ?: '—';
            $buyer_tax   = get_post_meta($id, '_cig_buyer_tax_id', true);
            $total       = get_post_meta($id, '_cig_invoice_total', true);
            $date        = get_post_field('post_date', $id);
            $is_partial  = get_post_meta($id, '_cig_payment_is_partial', true) === 'yes';
            
            // Get sold_date from post meta or custom table
            $sold_date = get_post_meta($id, '_cig_sold_date', true);
            if (empty($sold_date)) {
                // Try to get from custom tables
                global $wpdb;
                $table_invoices = $wpdb->prefix . 'cig_invoices';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $sold_date = $wpdb->get_var($wpdb->prepare(
                    "SELECT sold_date FROM {$table_invoices} WHERE id = %d",
                    $id
                ));
            }
            
            // Notes
            $acc_note = get_post_meta($id, '_cig_accountant_note', true);
            $consultant_note = get_post_meta($id, '_cig_general_note', true);

            // Payment String Logic
            $history = get_post_meta($id, '_cig_payment_history', true);
            $payment_title = '';
            $payment_desc = '';
            
            if (is_array($history) && !empty($history)) {
                $sums = [];
                foreach ($history as $h) {
                    $m = $h['method'] ?? 'other';
                    $lbl = $method_labels[$m] ?? $m;
                    if (!isset($sums[$lbl])) $sums[$lbl] = 0;
                    $sums[$lbl] += floatval($h['amount'] ?? 0);
                }

                if (count($sums) === 1) {
                    $payment_title = array_keys($sums)[0];
                } else {
                    $payment_title = implode(' + ', array_keys($sums));
                    $desc_parts = [];
                    foreach ($sums as $lbl => $amt) {
                        $desc_parts[] = $lbl . ' ' . number_format($amt, 0) . ' ₾';
                    }
                    $payment_desc = '(' . implode(', ', $desc_parts) . ')';
                }
            } else {
                $payment_title = '—';
            }

            // Get original total from items for discount display
            global $wpdb;
            $table_items = $wpdb->prefix . 'cig_invoice_items';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $orig_tot = (float)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(CASE WHEN original_price > 0 AND original_price > price THEN original_price * quantity ELSE price * quantity END), 0) FROM {$table_items} WHERE invoice_id = %d AND item_status != 'canceled'",
                $id
            ));
            $total_num = (float)$total;

            $invoices[] = [
                'id'          => $id,
                'number'      => get_post_meta($id, '_cig_invoice_number', true),
                'date'        => date('Y-m-d', strtotime($date)),
                'sold_date'   => $sold_date ?: '',
                'total'       => number_format($total_num, 2) . ' ₾',
                'original_total' => ($orig_tot > $total_num + 0.01) ? number_format($orig_tot, 2) . ' ₾' : '',
                'client_name' => $buyer_name,
                'client_tax'  => $buyer_tax,
                'view_url'    => get_permalink($id),
                'is_partial'  => $is_partial,
                'payment_title' => $payment_title,
                'payment_desc'  => $payment_desc,
                
                'status'          => $status, 
                'acc_note'        => $acc_note,
                'consultant_note' => $consultant_note
            ];
        }

        wp_send_json_success([
            'invoices'     => $invoices,
            'total_pages'  => $query->max_num_pages,
            'current_page' => $paged,
            'total_items'  => $query->found_posts
        ]);
    }

    /**
     * ACCOUNTANT: Set Invoice Status (Mutually Exclusive)
     */
    public function set_invoice_status() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'read');
        $id = intval($_POST['invoice_id']);
        $new_status = sanitize_text_field($_POST['status_type']); // rs, credit, receipt, corrected
        $state = $_POST['state'] === 'true'; // true = checking, false = unchecking

        if (!$id) wp_send_json_error();

        if ($state) {
            // Checking: Overwrite any existing status
            update_post_meta($id, '_cig_acc_status', $new_status);
            
            // Meta tracking (Optional, mostly for RS)
            if ($new_status === 'rs') {
                update_post_meta($id, '_cig_rs_uploaded_by', get_current_user_id());
                update_post_meta($id, '_cig_rs_uploaded_date', current_time('mysql'));
            }
        } else {
            // Unchecking: Remove ONLY if it matches current status
            // This prevents race conditions where you uncheck something that was already changed
            $current = get_post_meta($id, '_cig_acc_status', true);
            if ($current === $new_status) {
                delete_post_meta($id, '_cig_acc_status');
            }
        }

        wp_send_json_success();
    }

    /**
     * ACCOUNTANT: Update Note
     */
    public function update_accountant_note() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'read');
        $id = intval($_POST['invoice_id']);
        $note = sanitize_textarea_field($_POST['note']);
        
        update_post_meta($id, '_cig_accountant_note', $note);
        
        wp_send_json_success();
    }
}