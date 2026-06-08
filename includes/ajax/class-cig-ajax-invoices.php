<?php
/**
 * AJAX Handler for Invoice Operations
 * Updated: Connects to CIG_Invoice_Manager for custom table operations
 * with sale_date logic based on latest payment date
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Ajax_Invoices {

    /** @var CIG_Invoice */
    private $invoice;

    /** @var CIG_Stock_Manager */
    private $stock;

    /** @var CIG_Validator */
    private $validator;

    /** @var CIG_Security */
    private $security;

    /** @var CIG_Cache */
    private $cache;

    /** @var CIG_Invoice_Manager */
    private $invoice_manager;

    /** @var string Table names */
    private $table_invoices;
    private $table_items;
    private $table_payments;

    /**
     * Constructor
     */
    public function __construct($invoice, $stock, $validator, $security, $cache = null) {
        global $wpdb;
        
        $this->invoice   = $invoice;
        $this->stock     = $stock;
        $this->validator = $validator;
        $this->security  = $security;
        $this->cache     = $cache;
        $this->invoice_manager = new CIG_Invoice_Manager();

        // Initialize table names
        $this->table_invoices  = $wpdb->prefix . 'cig_invoices';
        $this->table_items     = $wpdb->prefix . 'cig_invoice_items';
        $this->table_payments  = $wpdb->prefix . 'cig_payments';

        // Invoice CRUD
        add_action('wp_ajax_cig_save_invoice',           [$this, 'save_invoice']);
        add_action('wp_ajax_cig_update_invoice',         [$this, 'update_invoice']);
        add_action('wp_ajax_cig_next_invoice_number',    [$this, 'next_invoice_number']);
        add_action('wp_ajax_cig_toggle_invoice_status',  [$this, 'toggle_invoice_status']);
        add_action('wp_ajax_cig_mark_as_sold',           [$this, 'mark_as_sold']);
    }

    /**
     * Check if custom tables exist
     *
     * @return bool
     */
    private function tables_exist() {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->table_invoices));
        return $result === $this->table_invoices;
    }

    /**
     * Helper to process payment history array
     */
    private function process_payment_history($raw_history) {
        $clean_history = [];
        if (is_array($raw_history)) {
            foreach ($raw_history as $h) {
                $clean_history[] = [
                    'date'    => sanitize_text_field($h['date'] ?? ''),
                    'amount'  => floatval($h['amount'] ?? 0),
                    'method'  => sanitize_text_field($h['method'] ?? ''),
                    'comment' => sanitize_text_field($h['comment'] ?? ''),
                    'user_id' => intval($h['user_id'] ?? get_current_user_id())
                ];
            }
        }
        return $clean_history;
    }

    /**
     * Calculate the latest payment date from payment history
     *
     * @param array $payments Payment history array
     * @return string|null Latest payment date in Y-m-d format, or null if no valid dates
     */
    private function get_latest_payment_date($payments) {
        $latest_date = null;
        $latest_timestamp = 0;
        
        if (!is_array($payments) || empty($payments)) {
            return null;
        }

        foreach ($payments as $payment) {
            $payment_date = $payment['date'] ?? '';
            if (!empty($payment_date)) {
                // Use strtotime for proper date comparison
                $timestamp = strtotime($payment_date);
                if ($timestamp !== false && $timestamp > $latest_timestamp) {
                    $latest_timestamp = $timestamp;
                    $latest_date = $payment_date;
                }
            }
        }

        return $latest_date;
    }

    /**
     * Calculate sale_date based on status and payment history
     * If becoming 'standard', use the latest payment date + current time
     *
     * @param string $status     Invoice status (standard/fictive)
     * @param array  $payments   Payment history array
     * @return string|null sale_date in mysql format, or null for fictive
     */
    private function calculate_sale_date($status, $payments) {
        if ($status !== 'standard') {
            return null; // Fictive invoices have no sale_date
        }

        $latest_payment_date = $this->get_latest_payment_date($payments);
        $current_time = current_time('H:i:s');

        if (!empty($latest_payment_date)) {
            // Combine latest payment date with current time
            return $latest_payment_date . ' ' . $current_time;
        }

        // Fallback to current datetime if no payment dates
        return current_time('mysql');
    }

    /**
     * Save new invoice
     */
    public function save_invoice() {
        $this->process_invoice_save(false);
    }

    /**
     * Update existing invoice
     */
    public function update_invoice() {
        $this->process_invoice_save(true);
    }

    /**
     * Main logic for saving/updating invoice
     */
    private function process_invoice_save($update) {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $raw_payload = wp_unslash($_POST['payload'] ?? '');
        $d = json_decode($raw_payload, true);

        if (!is_array($d)) {
            wp_send_json_error(['message' => 'Invalid data format']);
        }
        
        // Security Check for Completed Invoices
        if ($update) {
            $id = intval($d['invoice_id'] ?? 0);
            $current_lifecycle = get_post_meta($id, '_cig_lifecycle_status', true);
            if ($current_lifecycle === 'completed' && !current_user_can('administrator')) {
                wp_send_json_error(['message' => 'დასრულებული ინვოისის რედაქტირება აკრძალულია.'], 403);
            }
        }

        // Validation
        $buyer = $d['buyer'] ?? [];
        if (empty($buyer['name']) || empty($buyer['tax_id']) || empty($buyer['phone'])) {
            wp_send_json_error(['message' => 'შეავსეთ მყიდველის სახელი, ს/კ და ტელეფონი.'], 400);
        }

        // Identity enforcement (backstop for the form's auto-fill + lock):
        // if this tax_id already belongs to a customer, that customer's identity
        // is authoritative — bind the buyer NAME to it (an existing ს/კ can't be
        // renamed). Contact details fall back to the stored values when present.
        if (!empty($buyer['tax_id'])) {
            global $wpdb;
            $ctable = $wpdb->prefix . 'cig_customers';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $existing_cust = $wpdb->get_row($wpdb->prepare(
                "SELECT name, phone, email, address FROM {$ctable} WHERE tax_id = %s LIMIT 1",
                sanitize_text_field($buyer['tax_id'])
            ), ARRAY_A);
            if ($existing_cust && !empty($existing_cust['name'])) {
                $buyer['name'] = $existing_cust['name'];
                if (empty($buyer['phone'])   && !empty($existing_cust['phone']))   { $buyer['phone']   = $existing_cust['phone']; }
                if (empty($buyer['address']) && !empty($existing_cust['address'])) { $buyer['address'] = $existing_cust['address']; }
                if (empty($buyer['email'])   && !empty($existing_cust['email']))   { $buyer['email']   = $existing_cust['email']; }
            }
        }

        $num = sanitize_text_field($d['invoice_number'] ?? '');
        
        // NEW: General Note
        $general_note = sanitize_textarea_field($d['general_note'] ?? '');
        
        // NEW: Sold Date (for warranty sheet)
        $sold_date = sanitize_text_field($d['sold_date'] ?? '');
        
        // 1. Determine Status based on Payment
        $hist = $this->process_payment_history($d['payment']['history'] ?? []);
        
        // Calculate two totals: real cash paid (excluding consignment) and consignment total
        $real_cash_paid = 0;
        $consignment_total = 0;
        $has_consignment = false;
        foreach ($hist as $h) {
            $amount = floatval($h['amount'] ?? 0);
            $method = strtolower($h['method'] ?? '');
            if ($method === 'consignment') {
                $consignment_total += $amount;
                $has_consignment = true;
            } else {
                $real_cash_paid += $amount;
            }
        }
        
        // Total paid for DB storage (excludes consignment - used in manager_data below)
        $paid = $real_cash_paid;

        // AUTO-STATUS LOGIC:
        // If real cash paid > 0 OR consignment exists, it's a Standard Sale (item left stock)
        // Only mark as fictive if no payments of any kind
        $st = ($real_cash_paid > 0 || $has_consignment) ? 'standard' : 'fictive';
        
        // VIOLATION FIX: Block manual fictive setting if payments exist
        // Check if user is trying to manually set invoice to fictive
        $requested_status = sanitize_text_field($d['status'] ?? '');
        if ($requested_status === 'fictive' && ($real_cash_paid > 0 || $has_consignment)) {
            wp_send_json_error([
                'message' => 'Cannot set invoice to fictive when payments exist. Remove all payments first.'
            ], 400);
        }

        // 2. Process Items & Enforce Item Statuses
        $items = array_filter((array)($d['items'] ?? []), function($r) { 
            return !empty($r['name']); 
        });

        if (empty($items)) {
            wp_send_json_error(['message' => 'დაამატეთ პროდუქტები'], 400);
        }

        $processed_items = [];
        foreach ($items as $item) {
            $current_item_status = $item['status'] ?? 'none';
            
            if ($st === 'fictive') {
                $item['status'] = 'none'; 
                $item['reservation_days'] = 0;
            } else {
                if ($current_item_status === 'none' || empty($current_item_status)) {
                    $item['status'] = 'reserved';
                }
            }

            // Preserve original price (defaults to current price for backward compat)
            $item['original_price'] = floatval($item['original_price'] ?? $item['price'] ?? 0);

            // BUG FIX: Explicitly calculate item_total (qty * price) to ensure it's not 0
            $qty   = floatval($item['qty'] ?? 0);
            $price = floatval($item['price'] ?? 0);
            $item_total = floatval($item['total'] ?? 0);

            // Calculate total if missing or zero
            if ($item_total <= 0 && $qty > 0 && $price > 0) {
                $item['total'] = $qty * $price;
            }

            $processed_items[] = $item;
        }
        $items = $processed_items; 
        
        $pid = 0;

        if ($update) {
            $id = intval($d['invoice_id']);
            if ($st === 'standard') { 
                $err = $this->stock->validate_stock($items, $id); 
                if ($err) {
                    wp_send_json_error(['message' => 'Stock error', 'errors' => $err], 400);
                }
            }
            
            $new_num = CIG_Invoice::ensure_unique_number($num, $id);
            
            wp_update_post([
                'ID'            => $id, 
                'post_title'    => 'Invoice #' . $new_num, 
                'post_modified' => current_time('mysql')
            ]);
            $pid = $id;
        } else {
            if ($st === 'standard') { 
                $err = $this->stock->validate_stock($items, 0); 
                if ($err) {
                    wp_send_json_error(['message' => 'Stock error', 'errors' => $err], 400); 
                }
            }
            $new_num = CIG_Invoice::ensure_unique_number($num);
            $pid = wp_insert_post([
                'post_type'   => 'invoice',
                'post_status' => 'publish',
                'post_title'  => 'Invoice #' . $new_num, 
                'post_author' => get_current_user_id()
            ]);
        }
        
        // Capture OLD items BEFORE saving new metadata
        $old_items = [];
        if ($update) {
            $old_items = get_post_meta($pid, '_cig_items', true);
            if (!is_array($old_items)) {
                $old_items = [];
            }
        }

        update_post_meta($pid, '_wp_page_template', 'elementor_canvas');
        update_post_meta($pid, '_cig_invoice_status', $st);
        
        // NEW: Save General Note
        update_post_meta($pid, '_cig_general_note', $general_note);
        
        // NEW: Save Sold Date (for warranty sheet)
        if (!empty($sold_date)) {
            update_post_meta($pid, '_cig_sold_date', $sold_date);
        }
        
        // Sync Customer to custom table and get customer_id
        $customer_id = 0;
        if (function_exists('CIG') && isset(CIG()->customers)) { 
            $customer_id = CIG()->customers->sync_customer($buyer); 
            if ($customer_id) {
                update_post_meta($pid, '_cig_customer_id', $customer_id); 
            }
        }
        
        // Prepare Payment Data for saving
        $payment_data = (array)($d['payment'] ?? []);
        $payment_data['history'] = $hist;

        // Save items and metadata (PostMeta - Legacy support)
        CIG_Invoice::save_meta($pid, $new_num, (array)($d['buyer'] ?? []), $items, $payment_data);
        
        // Calculate total amount and lifecycle status from items
        $total_amount = 0;
        $count_sold = 0;
        $count_reserved = 0;
        $count_active_items = 0;
        
        foreach ($items as $item) {
            $item_status = strtolower($item['status'] ?? 'none');
            if ($item_status !== 'canceled' && $item_status !== 'none') {
                $total_amount += floatval($item['total'] ?? (floatval($item['qty'] ?? 0) * floatval($item['price'] ?? 0)));
                $count_active_items++;
                if ($item_status === 'sold') {
                    $count_sold++;
                } elseif ($item_status === 'reserved') {
                    $count_reserved++;
                }
            } elseif ($item_status === 'none') {
                // For fictive invoices, still sum the total for display
                $total_amount += floatval($item['total'] ?? (floatval($item['qty'] ?? 0) * floatval($item['price'] ?? 0)));
            }
        }
        
        // Calculate Lifecycle Status based on item statuses
        // - completed: all items are 'sold'
        // - reserved: all items are 'reserved'
        // - unfinished: mixed statuses or no active items
        $lifecycle_status = 'unfinished';
        if ($st === 'fictive') {
            // Fictive invoices are always unfinished
            $lifecycle_status = 'unfinished';
        } elseif ($count_active_items > 0) {
            if ($count_sold === $count_active_items) {
                $lifecycle_status = 'completed';
            } elseif ($count_reserved === $count_active_items) {
                $lifecycle_status = 'reserved';
            }
        }
        
        // Save lifecycle_status to postmeta (ensure consistency)
        update_post_meta($pid, '_cig_lifecycle_status', $lifecycle_status);

        // --- PRIMARY STORAGE: Use CIG_Invoice_Manager for custom tables ---
        // Calculate sale_date based on latest payment date for 'standard' invoices
        // If fictive, activation_date (sale_date) must be NULL
        $sale_date = $this->calculate_sale_date($st, $hist);
        
        $manager_data = [
            'invoice_number'   => $new_num,
            'customer_id'      => $customer_id,
            'status'           => $st,
            'lifecycle_status' => $lifecycle_status,
            'total_amount'     => $total_amount,
            'paid_amount'      => $paid,
            'author_id'        => get_current_user_id(),
            'general_note'     => $general_note,
            'sold_date'        => $sold_date,
            // Immutable buyer snapshot on the invoice itself (so stats show the
            // real buyer, independent of the shared customer record).
            'buyer_name'       => $buyer['name'] ?? '',
            'buyer_tax_id'     => $buyer['tax_id'] ?? '',
            'buyer_phone'      => $buyer['phone'] ?? '',
            'buyer_address'    => $buyer['address'] ?? '',
            'buyer_email'      => $buyer['email'] ?? '',
            'items'            => $items,
            'payments'         => $hist
        ];

        if ($update) {
            // Update existing invoice in custom tables
            $this->update_invoice_in_manager($pid, $manager_data, $st, $hist);
        } else {
            $final_num = $this->create_invoice_in_manager($pid, $manager_data, $sale_date);
            if ($final_num && $final_num !== $new_num) {
                $new_num = $final_num;
            }
        }
        
        // Update Stock
        $items_for_stock = ($st === 'fictive') ? [] : $items;
        $this->stock->update_invoice_reservations($pid, $old_items, $items_for_stock); 
        
        // Clear caches (Plugin Internal)
        if ($this->cache) {
            $this->cache->delete('statistics_summary');
            $author_id = get_post_field('post_author', $pid);
            $this->cache->delete('user_invoices_' . $author_id);
        }

        // --- DATE UPDATE LOGIC for WordPress post ---
        if ($st === 'standard') {
            $this->force_update_invoice_date($pid, $this->get_latest_payment_date($hist));
        }

        // --- FULL CACHE PURGE (WP + LiteSpeed) ---
        $this->purge_cache($pid);

        wp_send_json_success([
            'post_id'        => $pid, 
            'view_url'       => get_permalink($pid), 
            'invoice_number' => $new_num,
            'status'         => $st
        ]);
    }

    /**
     * Create invoice in CIG_Invoice_Manager custom tables
     *
     * @param int    $post_id    WordPress post ID (used as invoice ID)
     * @param array  $data       Invoice data
     * @param string $sale_date  Calculated sale_date
     * @return string|null Final invoice number (may differ from input if duplicate retry occurred)
     */
    private function create_invoice_in_manager($post_id, $data, $sale_date) {
        global $wpdb;

        // Check if tables exist
        if (!$this->tables_exist()) {
            return; // Tables don't exist yet
        }

        $now = current_time('mysql');

        // Handle sold_date - use provided value or null
        $sold_date = !empty($data['sold_date']) ? sanitize_text_field($data['sold_date']) : null;

        $invoice_number = $data['invoice_number'];
        $max_retries = 3;

        for ($attempt = 0; $attempt < $max_retries; $attempt++) {
            // Insert invoice record with explicit ID matching WordPress post ID
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert(
                $this->table_invoices,
                [
                    'id'               => $post_id,
                    'invoice_number'   => $invoice_number,
                    'customer_id'      => intval($data['customer_id']),
                    'status'           => $data['status'],
                    'lifecycle_status' => $data['lifecycle_status'],
                    'total_amount'     => floatval($data['total_amount']),
                    'paid_amount'      => floatval($data['paid_amount']),
                    'created_at'       => $now,
                    'sale_date'        => $sale_date,
                    'sold_date'        => $sold_date,
                    'author_id'        => intval($data['author_id']),
                    'general_note'     => $data['general_note'],
                    'is_rs_uploaded'   => 0,
                    'buyer_name'       => $data['buyer_name'] ?? '',
                    'buyer_tax_id'     => $data['buyer_tax_id'] ?? '',
                    'buyer_phone'      => $data['buyer_phone'] ?? '',
                    'buyer_address'    => $data['buyer_address'] ?? '',
                    'buyer_email'      => $data['buyer_email'] ?? ''
                ],
                ['%d', '%s', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
            );

            if ($result !== false) {
                // Success — update post title & postmeta if number changed on retry
                if ($attempt > 0) {
                    wp_update_post(['ID' => $post_id, 'post_title' => 'Invoice #' . $invoice_number]);
                    update_post_meta($post_id, '_cig_invoice_number', $invoice_number);
                    $data['invoice_number'] = $invoice_number;
                }
                break;
            }

            // Insert failed — check if it's a duplicate key error (error code 1062)
            if (strpos($wpdb->last_error, 'Duplicate') !== false || strpos($wpdb->last_error, '1062') !== false) {
                error_log("CIG: Duplicate invoice_number '{$invoice_number}' on attempt " . ($attempt + 1) . ", regenerating...");
                $invoice_number = CIG_Invoice::get_next_number();
            } else {
                // Non-duplicate error — don't retry
                error_log("CIG: Failed to insert invoice for post {$post_id}: {$wpdb->last_error}");
                break;
            }
        }

        // Insert items
        $this->sync_items_to_manager($post_id, $data['items']);

        // Insert payments
        $this->sync_payments_to_manager($post_id, $data['payments']);

        return $invoice_number;
    }

    /**
     * Update invoice in CIG_Invoice_Manager custom tables
     *
     * @param int    $post_id   WordPress post ID
     * @param array  $data      Invoice data
     * @param string $status    New status
     * @param array  $payments  Payment history
     * @return void
     */
    private function update_invoice_in_manager($post_id, $data, $status, $payments) {
        global $wpdb;

        // Check if tables exist
        if (!$this->tables_exist()) {
            return; // Tables don't exist yet
        }

        // Get existing invoice to check old status
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT status, sale_date FROM {$this->table_invoices} WHERE id = %d", $post_id),
            ARRAY_A
        );

        $update_data = [
            'invoice_number'   => $data['invoice_number'],
            'customer_id'      => intval($data['customer_id']),
            'status'           => $status,
            'lifecycle_status' => $data['lifecycle_status'],
            'total_amount'     => floatval($data['total_amount']),
            'paid_amount'      => floatval($data['paid_amount']),
            'general_note'     => $data['general_note'],
            'buyer_name'       => $data['buyer_name'] ?? '',
            'buyer_tax_id'     => $data['buyer_tax_id'] ?? '',
            'buyer_phone'      => $data['buyer_phone'] ?? '',
            'buyer_address'    => $data['buyer_address'] ?? '',
            'buyer_email'      => $data['buyer_email'] ?? ''
        ];
        $update_format = ['%s', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s'];

        // Handle sold_date field
        if (isset($data['sold_date'])) {
            $update_data['sold_date'] = !empty($data['sold_date']) ? sanitize_text_field($data['sold_date']) : null;
            $update_format[] = '%s';
        }

        // Date Logic for activation_date (sale_date):
        // - If fictive: sale_date must be NULL
        // - If standard: set sale_date based on latest payment date
        if ($status === 'fictive') {
            // Fictive invoices have no activation_date
            $update_data['sale_date'] = null;
            $update_format[] = '%s';
        } elseif ($status === 'standard') {
            $old_status = $existing['status'] ?? 'fictive';
            $old_sale_date = $existing['sale_date'] ?? null;

            // Calculate new sale_date if:
            // 1. Transitioning from fictive to standard (no sale_date yet)
            // 2. Already standard but needs date update based on latest payment
            if ($old_status === 'fictive' || empty($old_sale_date)) {
                $sale_date = $this->calculate_sale_date($status, $payments);
                $update_data['sale_date'] = $sale_date;
                $update_format[] = '%s';
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $this->table_invoices,
            $update_data,
            ['id' => $post_id],
            $update_format,
            ['%d']
        );

        // Sync items
        $this->sync_items_to_manager($post_id, $data['items']);

        // Sync payments
        $this->sync_payments_to_manager($post_id, $data['payments']);
    }

    /**
     * Sync items to custom table
     *
     * @param int   $invoice_id Invoice ID
     * @param array $items      Items array
     * @return void
     */
    private function sync_items_to_manager($invoice_id, $items) {
        global $wpdb;

        // Delete existing items
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete($this->table_items, ['invoice_id' => $invoice_id], ['%d']);

        // Insert new items
        foreach ($items as $item) {
            // Get quantity and price
            $qty   = floatval($item['qty'] ?? 0);
            $price = floatval($item['price'] ?? 0);

            // Calculate total if missing or zero
            $total = floatval($item['total'] ?? 0);
            if ($total <= 0 && $qty > 0 && $price > 0) {
                $total = $qty * $price;
            }

            // Get image URL and sanitize - only store valid http(s) URLs
            $raw_image = $item['image'] ?? '';
            $image = '';
            if (!empty($raw_image)) {
                $sanitized = esc_url_raw($raw_image);
                // Only store if it's a valid http or https URL
                if (preg_match('/^https?:\/\//i', $sanitized)) {
                    $image = $sanitized;
                }
            }

            $original_price = floatval($item['original_price'] ?? $price);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $this->table_items,
                [
                    'invoice_id'        => $invoice_id,
                    'product_id'        => intval($item['product_id'] ?? 0),
                    'product_name'      => sanitize_text_field($item['name'] ?? ''),
                    'sku'               => sanitize_text_field($item['sku'] ?? ''),
                    'description'       => sanitize_textarea_field($item['desc'] ?? $item['description'] ?? ''),
                    'quantity'          => $qty,
                    'price'             => $price,
                    'original_price'    => $original_price,
                    'total'             => $total,
                    'item_status'       => sanitize_text_field($item['status'] ?? 'none'),
                    'warranty_duration' => sanitize_text_field($item['warranty'] ?? ''),
                    'reservation_days'  => intval($item['reservation_days'] ?? 0),
                    'image'             => $image
                ],
                ['%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%d', '%s']
            );
        }
    }

    /**
     * Sync payments to custom table
     *
     * @param int   $invoice_id Invoice ID
     * @param array $payments   Payments array
     * @return void
     */
    private function sync_payments_to_manager($invoice_id, $payments) {
        global $wpdb;

        // Delete existing payments
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete($this->table_payments, ['invoice_id' => $invoice_id], ['%d']);

        // Insert new payments
        foreach ($payments as $payment) {
            $amount = floatval($payment['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $payment_date = sanitize_text_field($payment['date'] ?? '');
            if (empty($payment_date)) {
                $payment_date = current_time('Y-m-d');
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->insert(
                $this->table_payments,
                [
                    'invoice_id' => $invoice_id,
                    'amount'     => $amount,
                    'date'       => $payment_date,
                    'method'     => sanitize_text_field($payment['method'] ?? 'other'),
                    'user_id'    => intval($payment['user_id'] ?? get_current_user_id()),
                    'comment'    => sanitize_text_field($payment['comment'] ?? '')
                ],
                ['%d', '%f', '%s', '%s', '%d', '%s']
            );
        }
    }

    /**
     * Mark reserved items as sold (Finalize Invoice)
     */
    public function mark_as_sold() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $id = intval($_POST['invoice_id']);
        if (!$id || get_post_type($id) !== 'invoice') {
            wp_send_json_error(['message' => 'Invalid invoice']);
        }

        // Get Status
        $invoice_status = get_post_meta($id, '_cig_invoice_status', true);
        $is_fictive = ($invoice_status === 'fictive');

        // 1. Get Current Items (DB state)
        $items = get_post_meta($id, '_cig_items', true);
        if (!is_array($items)) $items = [];

        $old_items = $items; 
        $updated_items = [];
        $has_change = false;

        // 2. Prepare Updated Items (Memory state)
        foreach ($items as $item) {
            $st = $item['status'] ?? 'none';
            if ($st === 'reserved') {
                $item['status'] = 'sold';
                $item['reservation_days'] = 0;
                $has_change = true;
            }
            $updated_items[] = $item;
        }

        if ($has_change) {
            // 3. Save updated items to DB
            update_post_meta($id, '_cig_items', $updated_items);
            
            // 4. Sync with Stock Manager
            if (!$is_fictive) {
                $this->stock->update_invoice_reservations($id, $old_items, $updated_items);
            } else {
                 $this->stock->update_invoice_reservations($id, [], $updated_items);
            }
            
            // 5. Mark invoice as completed & standard in postmeta
            update_post_meta($id, '_cig_lifecycle_status', 'completed');
            update_post_meta($id, '_cig_invoice_status', 'standard');
            
            // 5b. Auto-fill sold_date if empty when marking as sold
            $existing_sold_date = get_post_meta($id, '_cig_sold_date', true);
            if (empty($existing_sold_date)) {
                update_post_meta($id, '_cig_sold_date', current_time('Y-m-d'));
            }

            // 6. Update custom tables using CIG_Invoice_Manager
            $result = $this->invoice_manager->mark_as_sold($id);
            
            // Also sync items to custom table
            $this->sync_items_to_manager($id, $updated_items);

            // Clear cache
            if ($this->cache) {
                $this->cache->delete('statistics_summary');
            }

            // --- DATE UPDATE LOGIC ---
            $history = get_post_meta($id, '_cig_payment_history', true);
            $payment_date = '';
            if (is_array($history) && !empty($history)) {
                $payment_date = $this->get_latest_payment_date($history);
            }
            $this->force_update_invoice_date($id, $payment_date);

            // --- FULL CACHE PURGE (WP + LiteSpeed) ---
            $this->purge_cache($id);

            wp_send_json_success(['message' => 'Invoice marked as sold successfully.']);
        } else {
            wp_send_json_error(['message' => 'No reserved items found to mark as sold.']);
        }
    }

    /**
     * Toggle invoice status (Standard <-> Fictive)
     */
    public function toggle_invoice_status() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $id = intval($_POST['invoice_id']); 
        $nst = sanitize_text_field($_POST['status']);
        
        // Check for payments (both real cash and consignment)
        $history = get_post_meta($id, '_cig_payment_history', true) ?: [];
        $real_cash_paid = 0;
        $has_consignment = false;
        
        if (is_array($history)) {
            foreach ($history as $h) {
                $amount = floatval($h['amount'] ?? 0);
                $method = strtolower($h['method'] ?? '');
                if ($amount > 0.001) {
                    if ($method === 'consignment') {
                        $has_consignment = true;
                    } else {
                        $real_cash_paid += $amount;
                    }
                }
            }
        }
        
        // Block switching to fictive if any payments exist
        if ($nst === 'fictive' && ($real_cash_paid > 0.001 || $has_consignment)) {
            wp_send_json_error([
                'message' => 'Cannot set invoice to fictive when payments exist. Remove all payments first.'
            ]);
        }

        $items = get_post_meta($id, '_cig_items', true) ?: [];
        if ($nst === 'standard') {
            $err = $this->stock->validate_stock($items, $id);
            if ($err) {
                wp_send_json_error(['message' => 'Stock error', 'errors' => $err]);
            }
        }

        $ost = get_post_meta($id, '_cig_invoice_status', true) ?: 'standard';
        
        // 1. Update status in DB (postmeta)
        update_post_meta($id, '_cig_invoice_status', $nst);
        
        // 2. Update custom tables
        global $wpdb;
        $table_invoices = $wpdb->prefix . 'cig_invoices';
        
        $update_data = ['status' => $nst];
        $update_format = ['%s'];
        
        // Handle sale_date (activation_date) based on status
        if ($nst === 'fictive') {
            // Fictive invoices have no activation_date
            $update_data['sale_date'] = null;
            $update_format[] = '%s';
        } elseif ($ost === 'fictive' && $nst === 'standard') {
            // Activating (fictive -> standard), set sale_date
            $sale_date = $this->calculate_sale_date($nst, $history);
            $update_data['sale_date'] = $sale_date;
            $update_format[] = '%s';
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $table_invoices,
            $update_data,
            ['id' => $id],
            $update_format,
            ['%d']
        );
        
        // 3. Clear Cache
        if ($this->cache) {
            $this->cache->delete('statistics_summary');
            $author_id = get_post_field('post_author', $id);
            $this->cache->delete('user_invoices_' . $author_id);
        }

        // 4. Update Reservations / Stock
        // When transitioning to fictive, pass empty new_items to purge all reservations
        $items_old = ($ost === 'fictive') ? [] : $items;
        $items_new = ($nst === 'fictive') ? [] : $items;

        $this->stock->update_invoice_reservations($id, $items_old, $items_new);
        
        // Explicitly purge all reservations when transitioning to fictive
        // This ensures cleanup even if old_items was empty
        if ($nst === 'fictive' && $ost !== 'fictive') {
            $this->stock->purge_invoice_reservations($id);
        }
        
        // 5. Force post update timestamp and date if activating
        if ($nst === 'standard' && $ost === 'fictive') {
            $this->force_update_invoice_date($id, $this->get_latest_payment_date($history));
        } else {
            wp_update_post([
                'ID'            => $id, 
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', 1)
            ]);
        }
        
        // --- FULL CACHE PURGE (WP + LiteSpeed) ---
        $this->purge_cache($id);

        wp_send_json_success();
    }

    /**
     * Get next invoice number
     */
    public function next_invoice_number() { 
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        wp_send_json_success(['next' => CIG_Invoice::get_next_number()]); 
    }

    /**
     * FORCE UPDATE DATE: Direct SQL Update using Site Time
     */
    private function force_update_invoice_date($post_id, $payment_date_ymd = '') {
        global $wpdb;

        // საიტის დრო
        $current_time_his = current_time('H:i:s'); 
        
        if (!empty($payment_date_ymd)) {
            $final_date = $payment_date_ymd . ' ' . $current_time_his;
        } else {
            $final_date = current_time('mysql');
        }

        $final_date_gmt = get_gmt_from_date($final_date);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $wpdb->posts,
            [
                'post_date'         => $final_date,
                'post_date_gmt'     => $final_date_gmt,
                'post_modified'     => $final_date,
                'post_modified_gmt' => $final_date_gmt
            ],
            ['ID' => $post_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * ROBUST PURGE: Purge ALL caches for a specific post
     */
    private function purge_cache($post_id) {
        // 1. WordPress Object Cache (Internal WP Cache)
        clean_post_cache($post_id);

        // 2. LiteSpeed Cache (API Method - Most reliable for LSCWP)
        if (class_exists('LiteSpeed_Cache_API')) {
            // Purge by Post ID
            LiteSpeed_Cache_API::purge_post($post_id);
            
            // Purge by URL explicitly (Just in case)
            $url = get_permalink($post_id);
            if ($url) {
                LiteSpeed_Cache_API::purge_url($url);
            }
        } 
        
        // 3. HTTP Header Method (Immediate Browser/Server response purge)
        // This tells LiteSpeed Server to purge the tag associated with this Post ID immediately
        if (defined('LSCWP_V')) {
            $tag = 'PO.' . $post_id;
            @header('X-LiteSpeed-Purge: ' . $tag);
        }
    }

}