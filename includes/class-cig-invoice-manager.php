<?php
/**
 * Invoice Manager Class
 * Core class to handle invoice data operations using $wpdb
 *
 * @package CIG
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG_Invoice_Manager Class
 *
 * Handles CRUD operations for invoices, invoice items, and payments
 * using direct database queries with proper SQL injection prevention.
 */
class CIG_Invoice_Manager {

    /**
     * Table names
     *
     * @var string
     */
    private $table_invoices;
    private $table_items;
    private $table_payments;
    private $table_customers;

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;

        $this->table_invoices  = $wpdb->prefix . 'cig_invoices';
        $this->table_items     = $wpdb->prefix . 'cig_invoice_items';
        $this->table_payments  = $wpdb->prefix . 'cig_payments';
        $this->table_customers = $wpdb->prefix . 'cig_customers';

        // Hook into WordPress post deletion to cascade delete custom table data
        add_action('before_delete_post', [$this, 'handle_wp_post_deletion']);
    }

    /**
     * Handle WordPress post deletion - cascade delete to custom tables
     *
     * When an invoice post is deleted from WordPress, this method ensures
     * the corresponding data in custom tables (cig_invoices, cig_invoice_items,
     * cig_payments) is also deleted to prevent orphaned data.
     *
     * @since 4.0.0
     * @param int $post_id WordPress post ID being deleted
     * @return void
     */
    public function handle_wp_post_deletion($post_id) {
        // Step 1: Validation - only process 'invoice' post type
        if (get_post_type($post_id) !== 'invoice') {
            return;
        }

        // Step 2: Retrieve the internal custom table ID
        $invoice_number = get_post_meta($post_id, '_cig_invoice_number', true);
        if (empty($invoice_number)) {
            return;
        }

        $invoice_data = $this->get_invoice_by_number($invoice_number);
        if (!$invoice_data) {
            return;
        }

        $internal_id = intval($invoice_data['invoice']['id']);
        if ($internal_id <= 0) {
            return;
        }

        // Step 3: Execute deletion using existing delete_invoice method
        $this->delete_invoice($internal_id);

        // Step 4: Clear relevant cache
        if (class_exists('CIG_Cache')) {
            $cache = new CIG_Cache();
            $cache->delete('statistics_summary');
            $author_id = get_post_field('post_author', $post_id);
            if ($author_id) {
                $cache->delete('user_invoices_' . $author_id);
            }
        }
    }

    /**
     * Create a new invoice
     *
     * Inserts into cig_invoices table. If status is 'standard' (Active), sets sale_date
     * to CURRENT_TIME. If 'fictive', sets sale_date to NULL.
     * Also handles insertion of items and payments.
     *
     * @param array $data Invoice data array containing:
     *                    - invoice_number (string)
     *                    - customer_id (int)
     *                    - status (string) 'standard'|'fictive'
     *                    - lifecycle_status (string)
     *                    - total_amount (float)
     *                    - paid_amount (float)
     *                    - author_id (int)
     *                    - general_note (string)
     *                    - sold_date (string) Y-m-d format, for warranty sheet
     *                    - items (array) Invoice items
     *                    - payments (array) Payment records
     * @return int|WP_Error Invoice ID on success, WP_Error on failure
     */
    public function create_invoice($data) {
        global $wpdb;

        $invoice_number   = sanitize_text_field($data['invoice_number'] ?? '');
        $customer_id      = intval($data['customer_id'] ?? 0);
        $status           = sanitize_text_field($data['status'] ?? 'fictive');
        $lifecycle_status = sanitize_text_field($data['lifecycle_status'] ?? 'unfinished');
        $total_amount     = floatval($data['total_amount'] ?? 0);
        $paid_amount      = floatval($data['paid_amount'] ?? 0);
        $author_id        = intval($data['author_id'] ?? get_current_user_id());
        $general_note     = sanitize_textarea_field($data['general_note'] ?? '');
        $sold_date        = isset($data['sold_date']) && !empty($data['sold_date']) ? sanitize_text_field($data['sold_date']) : null;
        $items            = $data['items'] ?? [];
        $payments         = $data['payments'] ?? [];

        // Validate required fields
        if (empty($invoice_number)) {
            return new WP_Error('missing_invoice_number', __('Invoice number is required.', 'cig'));
        }

        $now = current_time('mysql');

        // Determine sale_date based on status
        // Standard (Active) = set sale_date to current time
        // Fictive = sale_date is NULL
        $sale_date = ($status === 'standard') ? $now : null;

        // Insert invoice record
        $insert_result = $wpdb->insert(
            $this->table_invoices,
            [
                'invoice_number'   => $invoice_number,
                'customer_id'      => $customer_id,
                'status'           => $status,
                'lifecycle_status' => $lifecycle_status,
                'total_amount'     => $total_amount,
                'paid_amount'      => $paid_amount,
                'created_at'       => $now,
                'sale_date'        => $sale_date,
                'sold_date'        => $sold_date,
                'author_id'        => $author_id,
                'general_note'     => $general_note,
                'is_rs_uploaded'   => 0
            ],
            ['%s', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%d']
        );

        if (false === $insert_result) {
            return new WP_Error('db_insert_error', __('Failed to create invoice.', 'cig'), $wpdb->last_error);
        }

        $invoice_id = $wpdb->insert_id;

        // Insert items
        if (!empty($items) && is_array($items)) {
            $this->insert_items($invoice_id, $items);
        }

        // Insert payments
        if (!empty($payments) && is_array($payments)) {
            $this->insert_payments($invoice_id, $payments);
        }

        // Recalculate paid_amount from payments
        $this->recalculate_paid_amount($invoice_id);

        return $invoice_id;
    }

    /**
     * Update an existing invoice
     *
     * Updates cig_invoices, cig_invoice_items, and cig_payments.
     * Handles date logic for status transitions.
     *
     * @param int   $id   Invoice ID
     * @param array $data Updated invoice data
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function update_invoice($id, $data) {
        global $wpdb;

        $id = intval($id);
        if ($id <= 0) {
            return new WP_Error('invalid_id', __('Invalid invoice ID.', 'cig'));
        }

        // Get existing invoice
        $existing = $this->get_invoice($id);
        if (!$existing) {
            return new WP_Error('invoice_not_found', __('Invoice not found.', 'cig'));
        }

        $old_status = $existing['invoice']['status'] ?? 'fictive';
        $new_status = sanitize_text_field($data['status'] ?? $old_status);

        // Prepare update data
        $update_data   = [];
        $update_format = [];

        // Standard fields
        if (isset($data['invoice_number'])) {
            $update_data['invoice_number'] = sanitize_text_field($data['invoice_number']);
            $update_format[]               = '%s';
        }

        if (isset($data['customer_id'])) {
            $update_data['customer_id'] = intval($data['customer_id']);
            $update_format[]            = '%d';
        }

        if (isset($data['status'])) {
            $update_data['status'] = $new_status;
            $update_format[]       = '%s';
        }

        if (isset($data['lifecycle_status'])) {
            $update_data['lifecycle_status'] = sanitize_text_field($data['lifecycle_status']);
            $update_format[]                 = '%s';
        }

        if (isset($data['total_amount'])) {
            $update_data['total_amount'] = floatval($data['total_amount']);
            $update_format[]             = '%f';
        }

        if (isset($data['general_note'])) {
            $update_data['general_note'] = sanitize_textarea_field($data['general_note']);
            $update_format[]             = '%s';
        }

        if (isset($data['is_rs_uploaded'])) {
            $update_data['is_rs_uploaded'] = intval($data['is_rs_uploaded']);
            $update_format[]               = '%d';
        }

        // Handle sold_date field (for warranty sheet display)
        if (isset($data['sold_date'])) {
            $sold_date_value = !empty($data['sold_date']) ? sanitize_text_field($data['sold_date']) : null;
            $update_data['sold_date'] = $sold_date_value;
            $update_format[]          = '%s';
        }

        // Date Logic for activation_date (sale_date):
        // - If transitioning to fictive: sale_date must be NULL
        // - If transitioning from fictive to standard: set sale_date to CURRENT_TIME
        if ($new_status === 'fictive' && $old_status !== 'fictive') {
            $update_data['sale_date'] = null;
            $update_format[]          = '%s';
        } elseif ($old_status === 'fictive' && $new_status === 'standard') {
            $update_data['sale_date'] = current_time('mysql');
            $update_format[]          = '%s';
        }

        // Update invoice if there's data to update
        if (!empty($update_data)) {
            $result = $wpdb->update(
                $this->table_invoices,
                $update_data,
                ['id' => $id],
                $update_format,
                ['%d']
            );

            if (false === $result) {
                return new WP_Error('db_update_error', __('Failed to update invoice.', 'cig'), $wpdb->last_error);
            }
        }

        // Sync items if provided
        if (isset($data['items']) && is_array($data['items'])) {
            $this->sync_items($id, $data['items']);
        }

        // Sync payments if provided
        if (isset($data['payments']) && is_array($data['payments'])) {
            $this->sync_payments($id, $data['payments']);
        }

        // Recalculate paid_amount based on the sum of cig_payments
        $this->recalculate_paid_amount($id);

        return true;
    }

    /**
     * Mark invoice as sold
     *
     * Updates invoice status to 'standard' and lifecycle to 'completed'.
     * Updates all item statuses from 'reserved' to 'sold'.
     * If sale_date is currently NULL (was Fictive), set it to CURRENT_TIME.
     * If it already has a date (was Reserved), keep the original date.
     * Auto-fills sold_date if empty when marking as sold.
     *
     * @param int $invoice_id Invoice ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function mark_as_sold($invoice_id) {
        global $wpdb;

        $invoice_id = intval($invoice_id);
        if ($invoice_id <= 0) {
            return new WP_Error('invalid_id', __('Invalid invoice ID.', 'cig'));
        }

        // Get existing invoice
        $existing = $this->get_invoice($invoice_id);
        if (!$existing) {
            return new WP_Error('invoice_not_found', __('Invoice not found.', 'cig'));
        }

        $current_sale_date = $existing['invoice']['sale_date'] ?? null;
        $current_sold_date = $existing['invoice']['sold_date'] ?? null;

        // Prepare update data
        $update_data = [
            'status'           => 'standard',
            'lifecycle_status' => 'completed'
        ];
        $update_format = ['%s', '%s'];

        // Date Logic: If sale_date is NULL (was Fictive), set to CURRENT_TIME
        // If already has a date (was Reserved), keep the original date
        if (empty($current_sale_date)) {
            $update_data['sale_date'] = current_time('mysql');
            $update_format[]          = '%s';
        }

        // Auto-fill sold_date if empty when marking as sold
        if (empty($current_sold_date)) {
            $update_data['sold_date'] = current_time('Y-m-d');
            $update_format[]          = '%s';
        }

        // Update invoice
        $result = $wpdb->update(
            $this->table_invoices,
            $update_data,
            ['id' => $invoice_id],
            $update_format,
            ['%d']
        );

        if (false === $result) {
            return new WP_Error('db_update_error', __('Failed to mark invoice as sold.', 'cig'), $wpdb->last_error);
        }

        // Update all item statuses from 'reserved' to 'sold'
        // Using direct query with prepare for safety
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table_items} SET item_status = %s WHERE invoice_id = %d AND item_status = %s",
                'sold',
                $invoice_id,
                'reserved'
            )
        );

        // Note: Stock sync is NOT performed here to prevent double deduction.
        // Stock adjustments are handled exclusively by CIG_Stock_Manager::update_invoice_reservations()
        // which is called from CIG_Ajax_Invoices::mark_as_sold() after this method completes.

        return true;
    }

    /**
     * Get full invoice data including items, payments, and customer info
     *
     * @param int $id Invoice ID
     * @return array|null Structured array with invoice, items, payments, customer or null if not found
     */
    public function get_invoice($id) {
        global $wpdb;

        $id = intval($id);
        if ($id <= 0) {
            return null;
        }

        // Get invoice
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $invoice = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_invoices} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        if (!$invoice) {
            return null;
        }

        // Get items
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_items} WHERE invoice_id = %d ORDER BY id ASC",
                $id
            ),
            ARRAY_A
        );

        // Get payments
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $payments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_payments} WHERE invoice_id = %d ORDER BY date ASC, id ASC",
                $id
            ),
            ARRAY_A
        );

        // Get customer info if customer_id exists
        $customer = null;
        if (!empty($invoice['customer_id'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $customer = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_customers} WHERE id = %d",
                    $invoice['customer_id']
                ),
                ARRAY_A
            );
        }

        return [
            'invoice'  => $invoice,
            'items'    => $items ?: [],
            'payments' => $payments ?: [],
            'customer' => $customer
        ];
    }

    /**
     * Sync WooCommerce product stock based on invoice items
     *
     * - If item_status is 'sold', deduct WC product stock using wc_update_product_stock
     * - If item_status is 'reserved', do NOT deduct WC stock
     * - If item_status is 'none' (fictive), do nothing
     *
     * @param int $invoice_id Invoice ID
     * @return bool True on success
     */
    public function sync_stock($invoice_id) {
        global $wpdb;

        $invoice_id = intval($invoice_id);
        if ($invoice_id <= 0) {
            return false;
        }

        // Get items for this invoice
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_items} WHERE invoice_id = %d",
                $invoice_id
            ),
            ARRAY_A
        );

        if (empty($items)) {
            return true;
        }

        foreach ($items as $item) {
            $product_id  = intval($item['product_id'] ?? 0);
            $item_status = strtolower($item['item_status'] ?? 'none');
            $quantity    = floatval($item['quantity'] ?? 0);

            // Skip if no product ID or zero quantity
            if ($product_id <= 0 || $quantity <= 0) {
                continue;
            }

            // Only deduct stock for 'sold' status
            // 'reserved' and 'none' (fictive) do not affect actual WC stock
            if ($item_status === 'sold') {
                // Check if WooCommerce function exists
                if (function_exists('wc_update_product_stock')) {
                    $product = wc_get_product($product_id);
                    if ($product && $product->managing_stock()) {
                        // Deduct stock (negative quantity to reduce)
                        wc_update_product_stock($product_id, $quantity, 'decrease');
                    }
                }
            }
            // 'reserved' - handled by separate reservation display logic (CIG_Stock_Manager)
            // 'none' (fictive) - do nothing
        }

        return true;
    }

    /**
     * Insert invoice items
     *
     * @param int   $invoice_id Invoice ID
     * @param array $items      Array of item data
     * @return bool True on success, false if any insertion failed
     */
    private function insert_items($invoice_id, $items) {
        global $wpdb;

        $success = true;

        foreach ($items as $item) {
            // Get quantity and price
            $qty   = floatval($item['quantity'] ?? $item['qty'] ?? 0);
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

            $result = $wpdb->insert(
                $this->table_items,
                [
                    'invoice_id'        => $invoice_id,
                    'product_id'        => intval($item['product_id'] ?? 0),
                    'product_name'      => sanitize_text_field($item['product_name'] ?? $item['name'] ?? ''),
                    'sku'               => sanitize_text_field($item['sku'] ?? ''),
                    'description'       => sanitize_textarea_field($item['description'] ?? $item['desc'] ?? ''),
                    'quantity'          => $qty,
                    'price'             => $price,
                    'original_price'    => $original_price,
                    'total'             => $total,
                    'item_status'       => sanitize_text_field($item['item_status'] ?? $item['status'] ?? 'none'),
                    'warranty_duration' => sanitize_text_field($item['warranty_duration'] ?? $item['warranty'] ?? ''),
                    'reservation_days'  => intval($item['reservation_days'] ?? 0),
                    'image'             => $image
                ],
                ['%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%f', '%s', '%s', '%d', '%s']
            );

            if (false === $result) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Insert payment records
     *
     * @param int   $invoice_id Invoice ID
     * @param array $payments   Array of payment data
     * @return bool True on success, false if any insertion failed
     */
    private function insert_payments($invoice_id, $payments) {
        global $wpdb;

        $success = true;

        foreach ($payments as $payment) {
            $result = $wpdb->insert(
                $this->table_payments,
                [
                    'invoice_id' => $invoice_id,
                    'amount'     => floatval($payment['amount'] ?? 0),
                    'date'       => sanitize_text_field($payment['date'] ?? current_time('mysql')),
                    'method'     => sanitize_text_field($payment['method'] ?? 'other'),
                    'user_id'    => intval($payment['user_id'] ?? get_current_user_id()),
                    'comment'    => sanitize_textarea_field($payment['comment'] ?? '')
                ],
                ['%d', '%f', '%s', '%s', '%d', '%s']
            );

            if (false === $result) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Sync invoice items - delete and re-insert
     *
     * @param int   $invoice_id Invoice ID
     * @param array $items      New items array
     * @return void
     */
    private function sync_items($invoice_id, $items) {
        global $wpdb;

        // Delete existing items
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete(
            $this->table_items,
            ['invoice_id' => $invoice_id],
            ['%d']
        );

        // Insert new items
        if (!empty($items)) {
            $this->insert_items($invoice_id, $items);
        }
    }

    /**
     * Sync payments - delete and re-insert
     *
     * @param int   $invoice_id Invoice ID
     * @param array $payments   New payments array
     * @return void
     */
    private function sync_payments($invoice_id, $payments) {
        global $wpdb;

        // Delete existing payments
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete(
            $this->table_payments,
            ['invoice_id' => $invoice_id],
            ['%d']
        );

        // Insert new payments
        if (!empty($payments)) {
            $this->insert_payments($invoice_id, $payments);
        }
    }

    /**
     * Recalculate paid_amount from payments table
     * 
     * IMPORTANT: Excludes consignment payments from the paid total.
     * Consignment payments are recorded in the payments table for history,
     * but they do not count as actual cash received until the method is changed.
     *
     * @param int $invoice_id Invoice ID
     * @return void
     */
    private function recalculate_paid_amount($invoice_id) {
        global $wpdb;

        // Sum all payments for this invoice, EXCLUDING consignment
        // Consignment payments are tracked but don't count as cash received
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total_paid = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$this->table_payments} WHERE invoice_id = %d AND method != 'consignment'",
                $invoice_id
            )
        );

        // Update the invoice's paid_amount
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->update(
            $this->table_invoices,
            ['paid_amount' => floatval($total_paid)],
            ['id' => $invoice_id],
            ['%f'],
            ['%d']
        );
    }

    /**
     * Delete an invoice and all related data
     *
     * @param int $invoice_id Invoice ID
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function delete_invoice($invoice_id) {
        global $wpdb;

        $invoice_id = intval($invoice_id);
        if ($invoice_id <= 0) {
            return new WP_Error('invalid_id', __('Invalid invoice ID.', 'cig'));
        }

        // Delete payments first (child table)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete(
            $this->table_payments,
            ['invoice_id' => $invoice_id],
            ['%d']
        );

        // Delete items (child table)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete(
            $this->table_items,
            ['invoice_id' => $invoice_id],
            ['%d']
        );

        // Delete invoice
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->delete(
            $this->table_invoices,
            ['id' => $invoice_id],
            ['%d']
        );

        if (false === $result) {
            return new WP_Error('db_delete_error', __('Failed to delete invoice.', 'cig'), $wpdb->last_error);
        }

        return true;
    }

    /**
     * Get invoices with optional filtering
     *
     * @param array $args Query arguments:
     *                    - status (string)
     *                    - lifecycle_status (string)
     *                    - customer_id (int)
     *                    - author_id (int)
     *                    - date_from (string) Y-m-d format
     *                    - date_to (string) Y-m-d format
     *                    - limit (int)
     *                    - offset (int)
     *                    - orderby (string)
     *                    - order (string) ASC|DESC
     * @return array List of invoices
     */
    public function get_invoices($args = []) {
        global $wpdb;

        $defaults = [
            'status'           => '',
            'lifecycle_status' => '',
            'customer_id'      => 0,
            'author_id'        => 0,
            'date_from'        => '',
            'date_to'          => '',
            'limit'            => 20,
            'offset'           => 0,
            'orderby'          => 'id',
            'order'            => 'DESC'
        ];

        $args = wp_parse_args($args, $defaults);

        $where_clauses = [];
        $where_values  = [];

        if (!empty($args['status'])) {
            $where_clauses[] = 'status = %s';
            $where_values[]  = sanitize_text_field($args['status']);
        }

        if (!empty($args['lifecycle_status'])) {
            $where_clauses[] = 'lifecycle_status = %s';
            $where_values[]  = sanitize_text_field($args['lifecycle_status']);
        }

        if (!empty($args['customer_id'])) {
            $where_clauses[] = 'customer_id = %d';
            $where_values[]  = intval($args['customer_id']);
        }

        if (!empty($args['author_id'])) {
            $where_clauses[] = 'author_id = %d';
            $where_values[]  = intval($args['author_id']);
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'sale_date >= %s';
            $where_values[]  = sanitize_text_field($args['date_from']) . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'sale_date <= %s';
            $where_values[]  = sanitize_text_field($args['date_to']) . ' 23:59:59';
        }

        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        // Sanitize orderby to prevent SQL injection
        $allowed_orderby = ['id', 'invoice_number', 'status', 'total_amount', 'paid_amount', 'created_at', 'sale_date'];
        $orderby         = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'id';
        $order           = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $limit  = intval($args['limit']);
        $offset = intval($args['offset']);

        // Build query
        $query = "SELECT * FROM {$this->table_invoices} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

        // Add limit and offset to values
        $where_values[] = $limit;
        $where_values[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare($query, $where_values),
            ARRAY_A
        );

        return $results ?: [];
    }

    /**
     * Count invoices with optional filtering
     *
     * @param array $args Same as get_invoices but without pagination params
     * @return int Total count
     */
    public function count_invoices($args = []) {
        global $wpdb;

        $where_clauses = [];
        $where_values  = [];

        if (!empty($args['status'])) {
            $where_clauses[] = 'status = %s';
            $where_values[]  = sanitize_text_field($args['status']);
        }

        if (!empty($args['lifecycle_status'])) {
            $where_clauses[] = 'lifecycle_status = %s';
            $where_values[]  = sanitize_text_field($args['lifecycle_status']);
        }

        if (!empty($args['customer_id'])) {
            $where_clauses[] = 'customer_id = %d';
            $where_values[]  = intval($args['customer_id']);
        }

        if (!empty($args['author_id'])) {
            $where_clauses[] = 'author_id = %d';
            $where_values[]  = intval($args['author_id']);
        }

        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        $query = "SELECT COUNT(*) FROM {$this->table_invoices} {$where_sql}";

        if (!empty($where_values)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
            return (int) $wpdb->get_var($wpdb->prepare($query, $where_values));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var($query);
    }

    /**
     * Get invoice by invoice number
     *
     * @since 4.0.0
     * @param string $invoice_number Invoice number (e.g., 'N25000001')
     * @return array|null Invoice data or null if not found
     */
    public function get_invoice_by_number($invoice_number) {
        global $wpdb;

        $invoice_number = sanitize_text_field($invoice_number);
        if (empty($invoice_number)) {
            return null;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $invoice = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_invoices} WHERE invoice_number = %s",
                $invoice_number
            ),
            ARRAY_A
        );

        if (!$invoice) {
            return null;
        }

        // Get related data
        $id = intval($invoice['id']);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_items} WHERE invoice_id = %d ORDER BY id ASC",
                $id
            ),
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $payments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_payments} WHERE invoice_id = %d ORDER BY date ASC, id ASC",
                $id
            ),
            ARRAY_A
        );

        // Get customer info if exists
        $customer = null;
        if (!empty($invoice['customer_id'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $customer = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_customers} WHERE id = %d",
                    $invoice['customer_id']
                ),
                ARRAY_A
            );
        }

        return [
            'invoice'  => $invoice,
            'items'    => $items ?: [],
            'payments' => $payments ?: [],
            'customer' => $customer
        ];
    }

    /**
     * Get invoice data for a WordPress post ID
     *
     * This method bridges the gap between WordPress posts and the custom tables.
     * It looks up the invoice_number from post meta and retrieves data from custom tables.
     * Falls back to post meta if not found in custom tables (for backward compatibility).
     *
     * @since 4.0.0
     * @param int $post_id WordPress post ID
     * @return array|null Structured array with invoice data or null if not found
     */
    public function get_invoice_by_post_id($post_id) {
        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return null;
        }

        // Get invoice number from post meta
        $invoice_number = get_post_meta($post_id, '_cig_invoice_number', true);
        if (empty($invoice_number)) {
            return null;
        }

        // Try to get from custom tables first
        $invoice_data = $this->get_invoice_by_number($invoice_number);

        // If found in custom tables, return it
        if ($invoice_data) {
            // Add post_id reference for convenience
            $invoice_data['post_id'] = $post_id;
            return $invoice_data;
        }

        // Fallback: Build data from post meta for backward compatibility
        return $this->get_invoice_from_post_meta($post_id);
    }

    /**
     * Get invoice data from post meta (fallback for non-migrated data)
     *
     * @since 4.0.0
     * @param int $post_id WordPress post ID
     * @return array Invoice data structure
     */
    private function get_invoice_from_post_meta($post_id) {
        $invoice_number = get_post_meta($post_id, '_cig_invoice_number', true);
        $items_raw = get_post_meta($post_id, '_cig_items', true);
        $payment_history = get_post_meta($post_id, '_cig_payment_history', true);

        // Build invoice data structure
        $invoice = [
            'id'               => 0, // No custom table ID
            'invoice_number'   => $invoice_number,
            'customer_id'      => intval(get_post_meta($post_id, '_cig_customer_id', true)),
            'status'           => get_post_meta($post_id, '_cig_invoice_status', true) ?: 'standard',
            'lifecycle_status' => get_post_meta($post_id, '_cig_lifecycle_status', true) ?: 'unfinished',
            'total_amount'     => floatval(get_post_meta($post_id, '_cig_invoice_total', true)),
            'paid_amount'      => floatval(get_post_meta($post_id, '_cig_payment_paid_amount', true)),
            'created_at'       => get_post_field('post_date', $post_id),
            'sale_date'        => get_post_field('post_date', $post_id),
            'author_id'        => intval(get_post_field('post_author', $post_id)),
            'general_note'     => get_post_meta($post_id, '_cig_general_note', true),
            'is_rs_uploaded'   => (get_post_meta($post_id, '_cig_rs_uploaded', true) === 'yes') ? 1 : 0
        ];

        // Build items array
        $items = [];
        if (is_array($items_raw)) {
            foreach ($items_raw as $idx => $item) {
                $items[] = [
                    'id'                => $idx + 1,
                    'invoice_id'        => 0,
                    'product_id'        => intval($item['product_id'] ?? 0),
                    'product_name'      => sanitize_text_field($item['name'] ?? ''),
                    'sku'               => sanitize_text_field($item['sku'] ?? ''),
                    'quantity'          => floatval($item['qty'] ?? 0),
                    'price'             => floatval($item['price'] ?? 0),
                    'original_price'    => floatval($item['original_price'] ?? $item['price'] ?? 0),
                    'item_status'       => sanitize_text_field($item['status'] ?? 'none'),
                    'warranty_duration' => sanitize_text_field($item['warranty'] ?? ''),
                    'reservation_days'  => intval($item['reservation_days'] ?? 0),
                    // Legacy fields for template compatibility
                    'name'              => sanitize_text_field($item['name'] ?? ''),
                    'brand'             => sanitize_text_field($item['brand'] ?? ''),
                    'desc'              => wp_kses_post($item['desc'] ?? ''),
                    'image'             => esc_url_raw($item['image'] ?? ''),
                    'qty'               => floatval($item['qty'] ?? 0),
                    'total'             => floatval($item['total'] ?? 0),
                    'status'            => sanitize_text_field($item['status'] ?? 'none'),
                    'warranty'          => sanitize_text_field($item['warranty'] ?? '')
                ];
            }
        }

        // Build payments array
        $payments = [];
        if (is_array($payment_history)) {
            foreach ($payment_history as $idx => $payment) {
                $payments[] = [
                    'id'         => $idx + 1,
                    'invoice_id' => 0,
                    'amount'     => floatval($payment['amount'] ?? 0),
                    'date'       => sanitize_text_field($payment['date'] ?? ''),
                    'method'     => sanitize_text_field($payment['method'] ?? 'other'),
                    'user_id'    => intval($payment['user_id'] ?? 0),
                    'comment'    => sanitize_text_field($payment['comment'] ?? '')
                ];
            }
        }

        // Build customer data from buyer info
        $customer = [
            'id'      => intval(get_post_meta($post_id, '_cig_customer_id', true)),
            'name'    => get_post_meta($post_id, '_cig_buyer_name', true),
            'tax_id'  => get_post_meta($post_id, '_cig_buyer_tax_id', true),
            'address' => get_post_meta($post_id, '_cig_buyer_address', true),
            'phone'   => get_post_meta($post_id, '_cig_buyer_phone', true),
            'email'   => get_post_meta($post_id, '_cig_buyer_email', true)
        ];

        return [
            'invoice'  => $invoice,
            'items'    => $items,
            'payments' => $payments,
            'customer' => $customer,
            'post_id'  => $post_id
        ];
    }

    /**
     * Get customer statistics from custom tables
     *
     * @since 4.0.0
     * @param int $customer_id Customer ID from wp_cig_customers table
     * @return array Stats array with count, revenue, paid
     */
    public function get_customer_stats($customer_id) {
        global $wpdb;

        $customer_id = intval($customer_id);
        if ($customer_id <= 0) {
            return ['count' => 0, 'revenue' => 0, 'paid' => 0];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT 
                    COUNT(*) as count,
                    COALESCE(SUM(total_amount), 0) as revenue,
                    COALESCE(SUM(paid_amount), 0) as paid
                 FROM {$this->table_invoices} 
                 WHERE customer_id = %d AND status != 'fictive'",
                $customer_id
            ),
            ARRAY_A
        );

        return [
            'count'   => intval($stats['count'] ?? 0),
            'revenue' => floatval($stats['revenue'] ?? 0),
            'paid'    => floatval($stats['paid'] ?? 0)
        ];
    }

    /**
     * Get customer statistics by WordPress post ID (cig_customer post type)
     * Looks up the customer in wp_cig_customers by tax_id stored in post meta
     *
     * @since 4.0.0
     * @param int $post_id WordPress post ID of cig_customer post type
     * @return array Stats array with count, revenue, paid
     */
    public function get_customer_stats_by_post_id($post_id) {
        global $wpdb;

        $post_id = intval($post_id);
        if ($post_id <= 0) {
            return ['count' => 0, 'revenue' => 0, 'paid' => 0];
        }

        // Get tax_id from the WordPress post meta
        $tax_id = get_post_meta($post_id, '_cig_customer_tax_id', true);
        if (empty($tax_id)) {
            return ['count' => 0, 'revenue' => 0, 'paid' => 0];
        }

        // Look up the customer_id in wp_cig_customers table by tax_id
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $customer_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_customers} WHERE tax_id = %s LIMIT 1",
                $tax_id
            )
        );

        if (!$customer_id) {
            return ['count' => 0, 'revenue' => 0, 'paid' => 0];
        }

        return $this->get_customer_stats(intval($customer_id));
    }

    /**
     * Get singleton instance
     *
     * @since 4.0.0
     * @return CIG_Invoice_Manager
     */
    public static function instance() {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }
}
