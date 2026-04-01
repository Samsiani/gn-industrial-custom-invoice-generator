<?php
/**
 * Accountant Dashboard Handler
 * Updated: Adaptive Filters (Reset Button Hidden by Default), Full Modals, Admin Columns with Client
 *
 * @package CIG
 * @since 4.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Accountant {

    /** @var CIG_Invoice_Manager */
    private $invoice_manager;

    public function __construct() {
        $this->invoice_manager = CIG_Invoice_Manager::instance();
        add_action('admin_menu', [$this, 'register_menu']);
        add_shortcode('invoice_accountant_dashboard', [$this, 'render_shortcode']);
    }

    public function register_menu() {
        add_submenu_page(
            'edit.php?post_type=invoice',
            __('Accountant', 'cig'),
            __('Accountant', 'cig'),
            'manage_woocommerce', 
            'cig-accountant',
            [$this, 'render_admin_page']
        );
    }

    public function render_shortcode($atts) {
        if (!current_user_can('read')) {
            return '<div class="cig-notice-error" style="padding:15px;background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:4px;">' . 
                   __('Access Denied. You do not have permission to view this page.', 'cig') . 
                   '</div>';
        }

        ob_start();
        ?>
        <div class="cig-accountant-wrapper">
            <div class="cig-accountant-header">
                <h2><?php esc_html_e('Accountant Dashboard', 'cig'); ?></h2>
                
                <div class="cig-acc-filters-section">
                    
                    <div class="cig-filter-line">
                        <div class="cig-acc-search-group">
                            <span class="dashicons dashicons-search"></span>
                            <input type="text" id="cig-acc-search" placeholder="<?php esc_attr_e('Search Invoice #, Name, Tax ID...', 'cig'); ?>">
                        </div>

                        <div class="cig-date-controls">
                            <div class="cig-quick-filters">
                                <button type="button" class="cig-qf-btn" data-range="today"><?php esc_html_e('დღეს', 'cig'); ?></button>
                                <button type="button" class="cig-qf-btn" data-range="yesterday"><?php esc_html_e('გუშინ', 'cig'); ?></button>
                                <button type="button" class="cig-qf-btn" data-range="week"><?php esc_html_e('კვირა', 'cig'); ?></button>
                                <button type="button" class="cig-qf-btn" data-range="month"><?php esc_html_e('თვე', 'cig'); ?></button>
                                <button type="button" class="cig-qf-btn active" data-range="all"><?php esc_html_e('სულ', 'cig'); ?></button>
                            </div>
                            
                            <div class="cig-date-inputs">
                                <input type="date" id="cig-acc-date-from" class="cig-acc-date">
                                <span style="color:#aaa;">—</span>
                                <input type="date" id="cig-acc-date-to" class="cig-acc-date">
                            </div>
                        </div>
                    </div>

                    <div class="cig-filter-line second-line">
                        
                        <div class="cig-toggle-group">
                            <label class="cig-toggle-btn active" title="Show Everything">
                                <input type="radio" name="cig_completion" value="all" checked> 
                                <?php esc_html_e('All', 'cig'); ?>
                            </label>
                            <label class="cig-toggle-btn" title="Show invoices with ANY status">
                                <input type="radio" name="cig_completion" value="completed"> 
                                <?php esc_html_e('დასრულებული', 'cig'); ?>
                            </label>
                            <label class="cig-toggle-btn" title="Show invoices with NO status">
                                <input type="radio" name="cig_completion" value="incomplete"> 
                                <?php esc_html_e('დაუსრულებელი', 'cig'); ?>
                            </label>
                        </div>

                        <button type="button" id="cig-reset-filters" class="button button-secondary" style="margin: 0 15px; display:none;" title="<?php esc_attr_e('Reset all filters', 'cig'); ?>">
                            <span class="dashicons dashicons-image-rotate" style="vertical-align:middle; font-size:16px;"></span> 
                            <?php esc_html_e('ფილტრის გაუქმება', 'cig'); ?>
                        </button>

                        <div class="cig-dropdown-wrap">
                            <select id="cig-acc-type-filter" class="cig-acc-select">
                                <option value="all"><?php esc_html_e('ყველა სტატუსი (All Statuses)', 'cig'); ?></option>
                                <option value="rs"><?php esc_html_e('RS ატვირთული', 'cig'); ?></option>
                                <option value="credit"><?php esc_html_e('განვადება (Credit)', 'cig'); ?></option>
                                <option value="receipt"><?php esc_html_e('მთლიანი ჩეკი (Receipt)', 'cig'); ?></option>
                                <option value="corrected"><?php esc_html_e('კორექტირებული', 'cig'); ?></option>
                            </select>
                        </div>

                    </div>
                </div>
            </div>

            <div class="cig-acc-table-container">
                <table class="cig-acc-table">
                    <thead>
                        <tr>
                            <th style="width:120px;"><?php esc_html_e('Invoice / Date', 'cig'); ?></th>
                            <th style="width:90px;"><?php esc_html_e('Sale Date', 'cig'); ?></th>
                            <th><?php esc_html_e('Client', 'cig'); ?></th>
                            <th><?php esc_html_e('Payment', 'cig'); ?></th>
                            <th><?php esc_html_e('Total', 'cig'); ?></th>
                            
                            <th style="text-align:center; width:50px; background:#f9f9f9; border-left:1px solid #eee;" title="RS Uploaded"><?php esc_html_e('RS', 'cig'); ?></th>
                            <th style="text-align:center; width:50px; background:#f9f9f9;" title="Credit / განვადება"><?php esc_html_e('Credit', 'cig'); ?></th>
                            <th style="text-align:center; width:60px; background:#f9f9f9;" title="Receipt / მთლიანი ჩეკი"><?php esc_html_e('Receipt', 'cig'); ?></th>
                            <th style="text-align:center; width:70px; background:#f9f9f9; border-right:1px solid #eee;" title="Corrected"><?php esc_html_e('Corrected', 'cig'); ?></th>
                            
                            <th style="text-align:center; width:60px;"><?php esc_html_e('Note', 'cig'); ?></th>
                            <th style="text-align:center; width:50px;"><?php esc_html_e('Act', 'cig'); ?></th>
                            <th style="text-align:center; width:50px;"><?php esc_html_e('View', 'cig'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cig-acc-tbody">
                        <tr><td colspan="12" style="text-align:center;padding:20px;">Loading...</td></tr>
                    </tbody>
                </table>
                <div class="cig-acc-pagination" id="cig-acc-pagination"></div>
            </div>
        </div>

        <div id="cig-note-modal" class="cig-modal" style="display:none;">
            <div class="cig-modal-content">
                <span class="cig-modal-close">&times;</span>
                <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">
                    <?php esc_html_e('Notes / Comments', 'cig'); ?> <span id="cig-modal-invoice-num" style="color:#50529d;"></span>
                </h3>
                <div class="cig-modal-body" style="padding-top:10px;">
                    <div style="margin-bottom:20px;">
                        <label style="font-weight:bold; display:block; margin-bottom:5px; color:#555; display:flex; align-items:center; gap:5px;">
                            <span class="dashicons dashicons-admin-users"></span> <?php esc_html_e('Consultant Note:', 'cig'); ?>
                        </label>
                        <div id="cig-consultant-display" style="background:#f9f9f9; border:1px solid #eee; padding:10px; border-radius:4px; min-height:40px; color:#333; font-style:italic;"></div>
                    </div>
                    <hr style="border:0; border-top:1px dashed #ddd; margin:15px 0;">
                    <label for="cig-acc-note-input" style="font-weight:bold; display:block; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                        <span class="dashicons dashicons-format-chat"></span> <?php esc_html_e('Accountant Note:', 'cig'); ?>
                    </label>
                    <textarea id="cig-acc-note-input" rows="5" style="width:100%; border:1px solid #aaa; padding:10px; font-family:inherit;" placeholder="Add internal comment..."></textarea>
                    <div style="text-align:right; margin-top:20px;">
                        <button type="button" id="cig-save-note" class="button button-primary button-large"><?php esc_html_e('Save Note', 'cig'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <div id="cig-confirm-modal" class="cig-modal" style="display:none; z-index:10001;">
            <div class="cig-modal-content" style="width:400px; padding:20px; text-align:center;">
                <h3 style="margin-top:0; color:#50529d;"><?php esc_html_e('Confirmation', 'cig'); ?></h3>
                <p id="cig-confirm-msg" style="font-size:15px; margin:20px 0; line-height:1.5;"></p>
                <div class="cig-confirm-btns" style="display:flex; justify-content:center; gap:15px;">
                    <button type="button" id="cig-confirm-yes" class="button button-primary button-large" style="background:#28a745; border-color:#28a745;"><?php esc_html_e('დიახ / Yes', 'cig'); ?></button>
                    <button type="button" id="cig-confirm-no" class="button button-secondary button-large"><?php esc_html_e('არა / No', 'cig'); ?></button>
                </div>
            </div>
        </div>
        
        <div id="cig-info-modal" class="cig-modal" style="display:none; z-index:10002;">
            <div class="cig-modal-content" style="width:450px; padding:25px; border-left: 5px solid #f39c12;">
                <span class="cig-modal-close cig-info-close" style="margin-top:-10px;">&times;</span>
                <h3 style="margin-top:0; color:#e67e22; display:flex; align-items:center; gap:10px;">
                    <span class="dashicons dashicons-warning" style="font-size:24px;"></span> 
                    <?php esc_html_e('Status Information', 'cig'); ?>
                </h3>
                <div id="cig-info-body" style="font-size:14px; line-height:1.6; color:#333; margin-top:15px;"></div>
                <div style="text-align:right; margin-top:20px;">
                    <button type="button" class="button button-secondary cig-info-close"><?php esc_html_e('Close', 'cig'); ?></button>
                </div>
            </div>
        </div>

        <?php
        return ob_get_clean();
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Accountant Log & Review', 'cig'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('List of invoices marked as "Uploaded to RS", "Corrected", or "Receipt Checked".', 'cig'); ?></p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 100px;"><?php esc_html_e('Date', 'cig'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Sale Date', 'cig'); ?></th>
                        <th style="width: 130px;"><?php esc_html_e('Invoice #', 'cig'); ?></th>
                        <th><?php esc_html_e('Client', 'cig'); ?></th> <th><?php esc_html_e('Payment', 'cig'); ?></th>
                        <th><?php esc_html_e('Total', 'cig'); ?></th>
                        <th><?php esc_html_e('Discount', 'cig'); ?></th>
                        <th style="text-align:center;"><?php esc_html_e('RS', 'cig'); ?></th>
                        <th style="text-align:center;"><?php esc_html_e('Credit', 'cig'); ?></th>
                        <th style="text-align:center;"><?php esc_html_e('Receipt', 'cig'); ?></th>
                        <th style="text-align:center;"><?php esc_html_e('Corrected', 'cig'); ?></th>
                        <th><?php esc_html_e('Consultant Note', 'cig'); ?></th>
                        <th><?php esc_html_e('Accountant Note', 'cig'); ?></th>
                        <th style="width: 80px;"><?php esc_html_e('Actions', 'cig'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
                    $args = [
                        'post_type'      => 'invoice', 
                        'post_status'    => 'publish', 
                        'posts_per_page' => 20, 
                        'paged'          => $paged,
                        'meta_query'     => [ 
                            'relation' => 'OR', 
                            ['key' => '_cig_acc_status', 'compare' => 'EXISTS'], // New Logic
                            // Fallback for old data
                            ['key' => '_cig_rs_uploaded', 'value' => 'yes', 'compare' => '='], 
                            ['key' => '_cig_accountant_is_corrected', 'value' => 'yes', 'compare' => '='], 
                            ['key' => '_cig_acc_full_check', 'value' => 'yes', 'compare' => '='] 
                        ],
                        'orderby'  => 'date', 
                        'order'    => 'DESC'
                    ];
                    
                    $query = new WP_Query($args);
                    
                    // Payment Labels
                    $method_labels = [
                        'company_transfer' => __('კომპანია', 'cig'),
                        'cash'             => __('ქეში', 'cig'),
                        'consignment'      => __('კონსიგნაცია', 'cig'),
                        'credit'           => __('განვადება', 'cig'),
                        'other'            => __('სხვა', 'cig'),
                        'mixed'            => __('შერეული', 'cig')
                    ];
                    
                    if ($query->have_posts()) {
                        while ($query->have_posts()) {
                            $query->the_post();
                            $post_id = get_the_ID();
                            
                            // Get invoice data from CIG_Invoice_Manager (uses custom tables with fallback)
                            $invoice_data = $this->invoice_manager->get_invoice_by_post_id($post_id);
                            $invoice = $invoice_data['invoice'] ?? [];
                            $payments = $invoice_data['payments'] ?? [];
                            $customer = $invoice_data['customer'] ?? [];
                            
                            $inv_num = $invoice['invoice_number'] ?? '';
                            $total = floatval($invoice['total_amount'] ?? 0);
                            
                            // Statuses (checking is_rs_uploaded from custom table, fallback to meta for acc_status)
                            $st = get_post_meta($post_id, '_cig_acc_status', true);
                            $is_rs = ($st === 'rs') || !empty($invoice['is_rs_uploaded']);
                            $is_credit = ($st === 'credit');
                            $is_corrected = ($st === 'corrected') || (get_post_meta($post_id, '_cig_accountant_is_corrected', true) === 'yes');
                            $is_receipt = ($st === 'receipt') || (get_post_meta($post_id, '_cig_acc_full_check', true) === 'yes');
                            
                            $acc_note = get_post_meta($post_id, '_cig_accountant_note', true);
                            $cons_note = $invoice['general_note'] ?? '';

                            // Client from customer data
                            $buyer_name = $customer['name'] ?? '—';
                            $buyer_tax = $customer['tax_id'] ?? '';

                            // Payment Logic from payments array
                            $payment_str = '—';
                            if (!empty($payments)) {
                                $sums = [];
                                foreach ($payments as $h) {
                                    $m = $h['method'] ?? 'other';
                                    $lbl = $method_labels[$m] ?? $m;
                                    if (!isset($sums[$lbl])) $sums[$lbl] = 0;
                                    $sums[$lbl] += floatval($h['amount'] ?? 0);
                                }
                                if (count($sums) === 1) {
                                    $payment_str = esc_html(array_keys($sums)[0]);
                                } else {
                                    $title = implode(' + ', array_keys($sums));
                                    $desc_parts = [];
                                    foreach ($sums as $lbl => $amt) $desc_parts[] = $lbl . ' ' . number_format($amt, 0) . ' ₾';
                                    $payment_str = '<strong>' . esc_html($title) . '</strong><div style="font-size:11px; color:#666;">(' . implode(', ', $desc_parts) . ')</div>';
                                }
                            }
                            
                            // Use sale_date if available, otherwise post date
                            $display_date = !empty($invoice['sale_date']) ? $invoice['sale_date'] : get_the_date('Y-m-d H:i');
                            if (!empty($invoice['sale_date'])) {
                                $display_date = date('Y-m-d H:i', strtotime($invoice['sale_date']));
                            }
                            
                            // Get sold_date for display
                            $sold_date = $invoice['sold_date'] ?? '';
                            
                            echo '<tr>';
                            echo '<td>' . esc_html($display_date) . '</td>';
                            echo '<td>' . ($sold_date ? esc_html($sold_date) : '—') . '</td>';
                            echo '<td><a href="' . get_permalink($post_id) . '" target="_blank"><strong>' . esc_html($inv_num) . '</strong></a></td>';
                            
                            // Client
                            echo '<td><strong>' . esc_html($buyer_name) . '</strong>' . ($buyer_tax ? '<div style="font-size:11px; color:#666;">ID: ' . esc_html($buyer_tax) . '</div>' : '') . '</td>';

                            // Payment
                            echo '<td>' . $payment_str . '</td>';

                            echo '<td>' . esc_html(number_format($total, 2)) . ' ₾</td>';

                            // Discount from items
                            $inv_items = $invoice_data['items'] ?? [];
                            $inv_discount = 0;
                            foreach ($inv_items as $ii) {
                                $ii_price = floatval($ii['price'] ?? 0);
                                $ii_orig = floatval($ii['original_price'] ?? $ii_price);
                                $ii_qty = floatval($ii['quantity'] ?? $ii['qty'] ?? 0);
                                $ii_st = $ii['item_status'] ?? $ii['status'] ?? 'none';
                                if ($ii_orig > $ii_price && $ii_st !== 'canceled') {
                                    $inv_discount += ($ii_orig - $ii_price) * $ii_qty;
                                }
                            }
                            echo '<td>' . ($inv_discount > 0.01 ? '<span style="color:#856404; font-weight:bold;">' . esc_html(number_format($inv_discount, 2)) . ' ₾</span>' : '—') . '</td>';

                            // Statuses
                            echo '<td style="text-align:center;">' . ($is_rs ? '<span class="dashicons dashicons-cloud-saved" style="color:#28a745;" title="Uploaded"></span>' : '—') . '</td>';
                            echo '<td style="text-align:center;">' . ($is_credit ? '<span class="dashicons dashicons-calendar-alt" style="color:#6c757d;" title="Credit / Installment"></span>' : '—') . '</td>';
                            echo '<td style="text-align:center;">' . ($is_receipt ? '<span class="dashicons dashicons-yes-alt" style="color:#28a745;" title="Checked"></span>' : '—') . '</td>';
                            echo '<td style="text-align:center;">' . ($is_corrected ? '<span class="dashicons dashicons-warning" style="color:#f39c12;" title="Corrected"></span>' : '—') . '</td>';
                            
                            // Notes
                            echo '<td>' . ($cons_note ? '<div style="background:#f0f0f1; padding:4px; font-size:11px; border-radius:3px;">'.esc_html(mb_strimwidth($cons_note, 0, 30, '...')).'</div>' : '—') . '</td>';
                            echo '<td>' . ($acc_note ? '<div style="background:#fff8e1; padding:4px; font-size:11px; border-radius:3px; color:#856404;">'.esc_html($acc_note).'</div>' : '—') . '</td>';
                            
                            echo '<td><a href="' . get_permalink($post_id) . '" class="button button-small" target="_blank">View</a></td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="14">' . esc_html__('No relevant invoices found.', 'cig') . '</td></tr>';
                    }
                    wp_reset_postdata();
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}