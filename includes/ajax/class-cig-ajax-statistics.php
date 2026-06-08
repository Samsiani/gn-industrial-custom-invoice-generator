<?php
/**
 * AJAX Handler for Statistics Operations
 * Updated: Uses raw SQL queries on custom tables (cig_invoices, cig_payments, cig_customers)
 * Separates Revenue (sale_date) from Cash Flow (payment date)
 *
 * @package CIG
 * @since 5.0.0
 */

if (!defined('ABSPATH')) exit;

class CIG_Ajax_Statistics {
    private $security;

    /** @var int Maximum number of invoices to return in drill-down queries */
    private const MAX_DRILL_DOWN_RESULTS = 200;

    /** @var string Table names */
    private $table_invoices;
    private $table_items;
    private $table_payments;
    private $table_customers;

    public function __construct($security) {
        global $wpdb;
        
        $this->security = $security;
        
        // Initialize table names
        $this->table_invoices  = $wpdb->prefix . 'cig_invoices';
        $this->table_items     = $wpdb->prefix . 'cig_invoice_items';
        $this->table_payments  = $wpdb->prefix . 'cig_payments';
        $this->table_customers = $wpdb->prefix . 'cig_customers';

        // Existing Hooks
        add_action('wp_ajax_cig_get_statistics_summary', [$this, 'get_statistics_summary']);
        add_action('wp_ajax_cig_get_users_statistics', [$this, 'get_users_statistics']);
        add_action('wp_ajax_cig_get_user_invoices', [$this, 'get_user_invoices']);
        add_action('wp_ajax_cig_export_statistics', [$this, 'export_statistics']);
        add_action('wp_ajax_cig_get_product_insight', [$this, 'get_product_insight']);
        add_action('wp_ajax_cig_get_invoices_by_filters', [$this, 'get_invoices_by_filters']);
        add_action('wp_ajax_cig_get_products_by_filters', [$this, 'get_products_by_filters']);

        // --- NEW: External Balance Logic ---
        add_action('wp_ajax_cig_get_external_balance', [$this, 'get_external_balance']);
        add_action('wp_ajax_cig_add_deposit', [$this, 'add_deposit']);
        add_action('wp_ajax_cig_delete_deposit', [$this, 'delete_deposit']);

        // --- Top Selling Products ---
        add_action('wp_ajax_cig_get_top_products', [$this, 'get_top_products']);

        // --- Product Performance Table ---
        add_action('wp_ajax_cig_get_product_performance_table', [$this, 'get_product_performance_table']);

        // --- Invoice Date Update from Statistics Dashboard ---
        add_action('wp_ajax_cig_update_invoice_date_stat', [$this, 'update_invoice_date_stat']);
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
     * Get the appropriate date column for filtering based on invoice status.
     * Fictive invoices use created_at (since sale_date is NULL), 
     * while standard invoices use sale_date.
     *
     * Note: Column names cannot be parameterized in SQL prepared statements,
     * so we use a whitelist approach for security. The returned value is
     * guaranteed to be one of the whitelisted column names.
     *
     * @param string $status The invoice status ('fictive', 'standard', 'all', etc.)
     * @return string The whitelisted date column name with table alias
     */
    private function get_date_column_for_status($status) {
        // Whitelist of allowed column names for security - only these exact values can be returned
        $allowed_columns = [
            'fictive' => 'i.created_at',
            'default' => 'i.sale_date'
        ];
        
        return ($status === 'fictive') ? $allowed_columns['fictive'] : $allowed_columns['default'];
    }

    /**
     * Get statistics summary using raw SQL on custom tables
     * 
     * Revenue Calculation: Sum total_amount from cig_invoices WHERE sale_date is within range
     * Cash Flow (Paid) Calculation: Sum amount from cig_payments WHERE date is within range
     * 
     * This separates:
     * - Revenue (Invoice Date / sale_date) - when invoice was activated/sold
     * - Cash Flow (Payment Date) - when actual payment was received
     */
    public function get_statistics_summary() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        global $wpdb;
        
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
        $status    = sanitize_text_field($_POST['status'] ?? 'standard');
        $search    = sanitize_text_field($_POST['search'] ?? '');

        // Fallback if tables don't exist
        if (!$this->tables_exist()) {
            wp_send_json_success([
                'total_invoices' => 0,
                'total_revenue' => 0,
                'total_paid' => 0,
                'total_company_transfer' => 0,
                'total_cash' => 0,
                'total_consignment' => 0,
                'total_credit' => 0,
                'total_other' => 0,
                'total_sold' => 0,
                'total_reserved' => 0,
                'total_reserved_invoices' => 0,
                'total_outstanding' => 0,
                'total_discount' => 0,
            ]);
        }

        // ============================================
        // QUERY 1: REVENUE (Based on sale_date)
        // Revenue appears on the invoice's sale_date
        // ============================================
        $where_revenue = "WHERE i.status != 'fictive'"; // Exclude fictive invoices
        $params_revenue = [];
        
        if ($status === 'fictive') {
            $where_revenue = "WHERE i.status = 'fictive'";
        } elseif ($status !== 'all') {
            $where_revenue = "WHERE (i.status = 'standard' OR i.status IS NULL)";
        }
        
        // Filter by date: use created_at for fictive invoices, sale_date for standard
        $date_column = $this->get_date_column_for_status($status);
        if ($date_from) { 
            $where_revenue .= " AND {$date_column} >= %s"; 
            $params_revenue[] = $date_from . ' 00:00:00'; 
        }
        if ($date_to) { 
            $where_revenue .= " AND {$date_column} <= %s"; 
            $params_revenue[] = $date_to . ' 23:59:59'; 
        }

        // Search filter: invoice_number, customer_name, customer_tax_id
        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_revenue .= " AND (i.invoice_number LIKE %s OR c.name LIKE %s OR c.tax_id LIKE %s OR i.buyer_name LIKE %s OR i.buyer_tax_id LIKE %s)";
            $params_revenue[] = $search_like;
            $params_revenue[] = $search_like;
            $params_revenue[] = $search_like;
            $params_revenue[] = $search_like;
            $params_revenue[] = $search_like;
        }

        // Revenue = Sum of total_amount from invoices where sale_date is in range
        $sql_revenue = "SELECT 
            COUNT(DISTINCT i.id) as invoice_count,
            COALESCE(SUM(i.total_amount), 0) as total_revenue
            FROM {$this->table_invoices} i 
            LEFT JOIN {$this->table_customers} c ON i.customer_id = c.id
            {$where_revenue}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $revenue_data = $wpdb->get_row(
            !empty($params_revenue) ? $wpdb->prepare($sql_revenue, $params_revenue) : $sql_revenue, 
            ARRAY_A
        );

        // ============================================
        // QUERY 2: CASH FLOW (Based on payment date)
        // Payments appear on the payment's date
        // ============================================
        $where_cashflow = "WHERE 1=1";
        $params_cashflow = [];
        
        // Filter by payment date
        if ($date_from) { 
            $where_cashflow .= " AND p.date >= %s"; 
            $params_cashflow[] = $date_from . ' 00:00:00'; 
        }
        if ($date_to) { 
            $where_cashflow .= " AND p.date <= %s"; 
            $params_cashflow[] = $date_to . ' 23:59:59'; 
        }
        
        // Apply status filter by joining with invoices table
        if ($status === 'fictive') {
            $where_cashflow .= " AND i.status = 'fictive'";
        } elseif ($status !== 'all') {
            $where_cashflow .= " AND (i.status = 'standard' OR i.status IS NULL)";
        }

        // Search filter for cashflow
        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_cashflow .= " AND (i.invoice_number LIKE %s OR c.name LIKE %s OR c.tax_id LIKE %s OR i.buyer_name LIKE %s OR i.buyer_tax_id LIKE %s)";
            $params_cashflow[] = $search_like;
            $params_cashflow[] = $search_like;
            $params_cashflow[] = $search_like;
            $params_cashflow[] = $search_like;
            $params_cashflow[] = $search_like;
        }

        $sql_cashflow = "SELECT 
            COALESCE(SUM(CASE WHEN p.method != 'consignment' THEN p.amount ELSE 0 END), 0) as total_paid,
            COALESCE(SUM(CASE WHEN p.method = 'company_transfer' THEN p.amount ELSE 0 END), 0) as total_company_transfer,
            COALESCE(SUM(CASE WHEN p.method = 'cash' THEN p.amount ELSE 0 END), 0) as total_cash,
            COALESCE(SUM(CASE WHEN p.method = 'consignment' THEN p.amount ELSE 0 END), 0) as total_consignment,
            COALESCE(SUM(CASE WHEN p.method = 'credit' THEN p.amount ELSE 0 END), 0) as total_credit,
            COALESCE(SUM(CASE WHEN p.method = 'other' OR p.method = '' OR p.method IS NULL THEN p.amount ELSE 0 END), 0) as total_other,
            COUNT(DISTINCT p.invoice_id) as paid_invoices_count
            FROM {$this->table_payments} p 
            LEFT JOIN {$this->table_invoices} i ON p.invoice_id = i.id 
            LEFT JOIN {$this->table_customers} c ON i.customer_id = c.id
            {$where_cashflow}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $cashflow_data = $wpdb->get_row(
            !empty($params_cashflow) ? $wpdb->prepare($sql_cashflow, $params_cashflow) : $sql_cashflow, 
            ARRAY_A
        );

        // ============================================
        // QUERY 3: Item quantities (sold, reserved)
        // Based on sale_date for items
        // ============================================
        $where_items = "WHERE 1=1";
        $params_items = [];
        
        if ($status === 'fictive') {
            $where_items .= " AND i.status = 'fictive'";
        } elseif ($status !== 'all') {
            $where_items .= " AND (i.status = 'standard' OR i.status IS NULL)";
        }
        
        // Filter by date: use created_at for fictive invoices, sale_date for standard
        $date_column = $this->get_date_column_for_status($status);
        if ($date_from) { 
            $where_items .= " AND {$date_column} >= %s"; 
            $params_items[] = $date_from . ' 00:00:00'; 
        }
        if ($date_to) { 
            $where_items .= " AND {$date_column} <= %s"; 
            $params_items[] = $date_to . ' 23:59:59'; 
        }

        // Search filter for items
        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_items .= " AND (i.invoice_number LIKE %s OR c.name LIKE %s OR c.tax_id LIKE %s OR i.buyer_name LIKE %s OR i.buyer_tax_id LIKE %s)";
            $params_items[] = $search_like;
            $params_items[] = $search_like;
            $params_items[] = $search_like;
            $params_items[] = $search_like;
            $params_items[] = $search_like;
        }

        $sql_items = "SELECT
            COALESCE(SUM(CASE WHEN it.item_status = 'sold' THEN it.quantity ELSE 0 END), 0) as total_sold,
            COALESCE(SUM(CASE WHEN it.item_status = 'reserved' THEN it.quantity ELSE 0 END), 0) as total_reserved,
            COUNT(DISTINCT CASE WHEN it.item_status = 'reserved' THEN it.invoice_id END) as reserved_invoices_count,
            COALESCE(SUM(CASE WHEN it.original_price > it.price AND it.item_status != 'canceled' THEN (it.original_price - it.price) * it.quantity ELSE 0 END), 0) as total_discount
            FROM {$this->table_invoices} i
            LEFT JOIN {$this->table_items} it ON i.id = it.invoice_id
            LEFT JOIN {$this->table_customers} c ON i.customer_id = c.id
            {$where_items}";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $items_data = $wpdb->get_row(
            !empty($params_items) ? $wpdb->prepare($sql_items, $params_items) : $sql_items, 
            ARRAY_A
        );

        // ============================================
        // QUERY 4: Total Outstanding (all time)
        // ============================================
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $total_outstanding = $wpdb->get_var(
            "SELECT COALESCE(SUM(total_amount - paid_amount), 0) 
             FROM {$this->table_invoices} 
             WHERE (status = 'standard' OR status IS NULL) 
             AND (total_amount - paid_amount) > 0.01"
        );

        wp_send_json_success([
            'total_invoices' => (int)($revenue_data['invoice_count'] ?? 0),
            'total_revenue' => (float)($revenue_data['total_revenue'] ?? 0),
            'total_paid' => (float)($cashflow_data['total_paid'] ?? 0),
            'total_company_transfer' => (float)($cashflow_data['total_company_transfer'] ?? 0),
            'total_cash' => (float)($cashflow_data['total_cash'] ?? 0),
            'total_consignment' => (float)($cashflow_data['total_consignment'] ?? 0),
            'total_credit' => (float)($cashflow_data['total_credit'] ?? 0),
            'total_other' => (float)($cashflow_data['total_other'] ?? 0),
            'total_sold' => (int)($items_data['total_sold'] ?? 0),
            'total_reserved' => (int)($items_data['total_reserved'] ?? 0),
            'total_reserved_invoices' => (int)($items_data['reserved_invoices_count'] ?? 0),
            'total_outstanding' => (float)$total_outstanding,
            'total_discount' => (float)($items_data['total_discount'] ?? 0),
        ]);
    }

    /**
     * Get user statistics using raw SQL
     */
    public function get_users_statistics() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        global $wpdb;

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
        $status    = sanitize_text_field($_POST['status'] ?? 'standard');
        $search    = sanitize_text_field($_POST['search'] ?? '');
        $sort_by   = sanitize_text_field($_POST['sort_by'] ?? 'invoice_count');
        $sort_order = sanitize_text_field($_POST['sort_order'] ?? 'desc');

        // Fallback if tables don't exist
        if (!$this->tables_exist()) {
            wp_send_json_success(['users' => []]);
        }

        // Build WHERE clause
        $where = "WHERE 1=1";
        $params = [];
        
        if ($status === 'fictive') {
            $where .= " AND i.status = 'fictive'";
        } elseif ($status !== 'all') {
            $where .= " AND (i.status = 'standard' OR i.status IS NULL)";
        }
        
        // Filter by date: use created_at for fictive invoices, sale_date for standard
        $date_column = $this->get_date_column_for_status($status);
        if ($date_from) { 
            $where .= " AND {$date_column} >= %s"; 
            $params[] = $date_from . ' 00:00:00'; 
        }
        if ($date_to) { 
            $where .= " AND {$date_column} <= %s"; 
            $params[] = $date_to . ' 23:59:59'; 
        }

        // Build SQL query for user statistics
        // For last_invoice_date, use the same date column as filtering
        $sql = "SELECT 
            i.author_id,
            COUNT(DISTINCT i.id) as invoice_count,
            COALESCE(SUM(i.total_amount), 0) as total_revenue,
            MAX({$date_column}) as last_invoice_date
            FROM {$this->table_invoices} i 
            {$where}
            GROUP BY i.author_id";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results(
            !empty($params) ? $wpdb->prepare($sql, $params) : $sql, 
            ARRAY_A
        );

        $users = [];
        foreach ($results as $row) {
            $uid = intval($row['author_id']);
            $u = get_userdata($uid);
            if (!$u) continue;

            // Get item counts for this user
            $items_where = "WHERE i.author_id = %d";
            $items_params = [$uid];
            
            if ($status === 'fictive') {
                $items_where .= " AND i.status = 'fictive'";
            } elseif ($status !== 'all') {
                $items_where .= " AND (i.status = 'standard' OR i.status IS NULL)";
            }
            
            if ($date_from) { 
                $items_where .= " AND {$date_column} >= %s"; 
                $items_params[] = $date_from . ' 00:00:00'; 
            }
            if ($date_to) { 
                $items_where .= " AND {$date_column} <= %s"; 
                $items_params[] = $date_to . ' 23:59:59'; 
            }

            $items_sql = "SELECT 
                COALESCE(SUM(CASE WHEN it.item_status = 'sold' THEN it.quantity ELSE 0 END), 0) as total_sold,
                COALESCE(SUM(CASE WHEN it.item_status = 'reserved' THEN it.quantity ELSE 0 END), 0) as total_reserved,
                COALESCE(SUM(CASE WHEN it.item_status = 'canceled' THEN it.quantity ELSE 0 END), 0) as total_canceled
                FROM {$this->table_invoices} i 
                LEFT JOIN {$this->table_items} it ON i.id = it.invoice_id 
                {$items_where}";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
            $items_data = $wpdb->get_row($wpdb->prepare($items_sql, $items_params), ARRAY_A);

            $user_data = [
                'user_id' => $uid,
                'user_name' => $u->display_name,
                'user_email' => $u->user_email,
                'user_avatar' => get_avatar_url($uid, ['size' => 40]),
                'invoice_count' => (int)$row['invoice_count'],
                'total_sold' => (int)($items_data['total_sold'] ?? 0),
                'total_reserved' => (int)($items_data['total_reserved'] ?? 0),
                'total_canceled' => (int)($items_data['total_canceled'] ?? 0),
                'total_revenue' => (float)$row['total_revenue'],
                'last_invoice_date' => $row['last_invoice_date'] ?? ''
            ];

            // Apply search filter
            if ($search) {
                if (stripos($user_data['user_name'], $search) === false && 
                    stripos($user_data['user_email'], $search) === false) {
                    continue;
                }
            }

            $users[] = $user_data;
        }

        // Sort results
        $sort_key = [
            'invoices' => 'invoice_count',
            'revenue' => 'total_revenue',
            'sold' => 'total_sold',
            'reserved' => 'total_reserved',
            'date' => 'last_invoice_date'
        ][$sort_by] ?? 'invoice_count';

        usort($users, function($a, $b) use ($sort_key, $sort_order) {
            return $sort_order === 'asc' ? ($a[$sort_key] <=> $b[$sort_key]) : ($b[$sort_key] <=> $a[$sort_key]);
        });

        wp_send_json_success(['users' => $users]);
    }

    /**
     * Get user invoices using raw SQL
     */
    public function get_user_invoices() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        global $wpdb;

        $user_id = intval($_POST['user_id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? 'standard');
        $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');

        // Fallback if tables don't exist
        if (!$this->tables_exist()) {
            wp_send_json_success(['invoices' => []]);
        }

        // Build WHERE clause
        $where = "WHERE i.author_id = %d";
        $params = [$user_id];
        
        if ($status === 'fictive') {
            $where .= " AND i.status = 'fictive'";
        } elseif ($status !== 'all') {
            $where .= " AND (i.status = 'standard' OR i.status IS NULL)";
        }
        
        if ($search) {
            $where .= " AND i.invoice_number LIKE %s";
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $sql = "SELECT i.* FROM {$this->table_invoices} i {$where} ORDER BY i.sale_date DESC";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $invoices = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $result = [];
        foreach ($invoices as $inv) {
            $id = intval($inv['id']);
            
            // Get items for this invoice
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $items = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT item_status, quantity FROM {$this->table_items} WHERE invoice_id = %d",
                    $id
                ),
                ARRAY_A
            );

            $tot = 0; $s = 0; $r = 0; $c = 0;
            foreach ($items as $it) {
                $q = floatval($it['quantity']);
                $st = strtolower($it['item_status'] ?? 'sold');
                $tot += $q;
                if ($st === 'sold') $s += $q;
                elseif ($st === 'reserved') $r += $q;
                elseif ($st === 'canceled') $c += $q;
            }

            // Get payment type from payments
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $payment_methods = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT method FROM {$this->table_payments} WHERE invoice_id = %d AND amount > 0",
                    $id
                )
            );

            $pt = count($payment_methods) > 1 ? 'mixed' : (reset($payment_methods) ?: '');

            // Filter by payment method if specified
            if ($payment_method && $payment_method !== 'all') {
                if ($pt !== $payment_method && !in_array($payment_method, $payment_methods, true)) {
                    continue;
                }
            }

            $payment_labels = CIG_Invoice::get_payment_types();

            // Get original total (before discounts) from items
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $orig_tot = (float)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN original_price > 0 AND original_price > price THEN original_price * quantity ELSE price * quantity END), 0) FROM {$this->table_items} WHERE invoice_id = %d AND item_status != 'canceled'",
                    $id
                )
            );
            $inv_total = (float)$inv['total_amount'];

            $result[] = [
                'id' => $id,
                'invoice_number' => $inv['invoice_number'],
                'date' => $inv['sale_date'] ?? $inv['created_at'],
                'invoice_total' => $inv_total,
                'original_total' => ($orig_tot > $inv_total + 0.01) ? $orig_tot : 0,
                'payment_type' => $pt,
                'payment_label' => $payment_labels[$pt] ?? $pt,
                'total_products' => $tot,
                'sold_items' => $s,
                'reserved_items' => $r,
                'canceled_items' => $c,
                'view_url' => get_permalink($id),
                'edit_url' => add_query_arg('edit', '1', get_permalink($id))
            ];
        }

        wp_send_json_success(['invoices' => $result]);
    }

    /**
     * Get invoices by filters using raw SQL on custom tables
     * 
     * IMPORTANT: Date filtering logic depends on context:
     * - When a specific payment_method is selected (cash flow drill-down):
     *   Date range applies to cig_payments.date (when payment was received)
     * - When no payment method is selected (general overview / reserved_invoices):
     *   Date range applies to cig_invoices.sale_date (revenue reporting)
     */
    public function get_invoices_by_filters() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        global $wpdb;

        $status = sanitize_text_field($_POST['status'] ?? 'standard');
        $mf = sanitize_text_field($_POST['payment_method'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');
        $page = max(1, intval($_POST['page'] ?? 1));
        $per_page = ($mf === 'reserved_invoices') ? 50 : self::MAX_DRILL_DOWN_RESULTS;

        // Fallback if tables don't exist - use legacy WP_Query method
        if (!$this->tables_exist()) {
            $this->get_invoices_by_filters_legacy();
            return;
        }
        
        $method_labels = [
            'company_transfer' => __('კომპანიის ჩარიცხვა', 'cig'), 
            'cash' => __('ქეში', 'cig'), 
            'consignment' => __('კონსიგნაცია', 'cig'), 
            'credit' => __('განვადება', 'cig'), 
            'other' => __('სხვა', 'cig')
        ];

        // Determine if we're filtering by payment method (cash flow drill-down)
        // Include 'all' (Total Paid) case - this should also filter by payment date
        // IF status is 'fictive', strictly disable payment method filtering logic,
        // because fictive invoices do not have payment records in the DB.
        $is_payment_method_filter = ($status !== 'fictive') && ($mf && $mf !== 'reserved_invoices');
        $is_all_paid_filter = ($mf === 'all');

        // Build WHERE clause
        $where = "WHERE 1=1";
        $params = [];
        
        if ($status === 'fictive') {
            $where .= " AND i.status = 'fictive'";
        } elseif ($status === 'outstanding') {
            $where .= " AND (i.status = 'standard' OR i.status IS NULL) AND (i.total_amount - i.paid_amount) > 0.01";
        } elseif ($status !== 'all') {
            $where .= " AND (i.status = 'standard' OR i.status IS NULL)";
        }

        // Search filter: invoice_number, customer_name, customer_tax_id
        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (i.invoice_number LIKE %s OR c.name LIKE %s OR c.tax_id LIKE %s OR i.buyer_name LIKE %s OR i.buyer_tax_id LIKE %s)";
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = $search_like;
            $params[] = $search_like;
        }

        if ($is_payment_method_filter) {
            // CASH FLOW DRILL-DOWN: Filter by payment date and method
            // Find invoices that have a matching payment within the date range
            $payment_where = "";
            $payment_params = [];
            
            // Only filter by specific method if not 'all paid'
            if (!$is_all_paid_filter) {
                $payment_where .= " AND p.method = %s";
                $payment_params[] = $mf;
            }
            $payment_where .= " AND p.amount > 0.001";
            
            if ($date_from) { 
                $payment_where .= " AND p.date >= %s"; 
                $payment_params[] = $date_from . ' 00:00:00'; 
            }
            if ($date_to) { 
                $payment_where .= " AND p.date <= %s"; 
                $payment_params[] = $date_to . ' 23:59:59'; 
            }
            
            // Use EXISTS subquery to find invoices with matching payments
            $where .= " AND EXISTS (
                SELECT 1 FROM {$this->table_payments} p 
                WHERE p.invoice_id = i.id {$payment_where}
            )";
            $params = array_merge($params, $payment_params);
            
            // Main query: Join with customers for names
            $sql = "SELECT 
                i.id,
                i.invoice_number,
                i.total_amount,
                i.paid_amount,
                i.status,
                i.sale_date,
                i.author_id,
                COALESCE(NULLIF(i.buyer_name, ''), c.name) as customer_name
                FROM {$this->table_invoices} i
                LEFT JOIN {$this->table_customers} c ON i.customer_id = c.id
                {$where}
                ORDER BY i.sale_date DESC
                LIMIT " . self::MAX_DRILL_DOWN_RESULTS;
        } else {
            // GENERAL OVERVIEW / RESERVED INVOICES / FICTIVE: Filter by appropriate date column
            // For fictive invoices, use created_at; for standard invoices, use sale_date
            $date_column = $this->get_date_column_for_status($status);
            $order_column = $this->get_date_column_for_status($status);
            
            if ($date_from) { 
                $where .= " AND {$date_column} >= %s"; 
                $params[] = $date_from . ' 00:00:00'; 
            }
            if ($date_to) { 
                $where .= " AND {$date_column} <= %s"; 
                $params[] = $date_to . ' 23:59:59'; 
            }

            // For reserved_invoices: filter at SQL level with JOIN on items table
            if ($mf === 'reserved_invoices') {
                $where .= " AND EXISTS (
                    SELECT 1 FROM {$this->table_items} ri
                    WHERE ri.invoice_id = i.id AND ri.item_status = 'reserved'
                )";
            }

            // Count total for pagination (reserved_invoices only)
            $total_count = 0;
            if ($mf === 'reserved_invoices') {
                $count_sql = "SELECT COUNT(DISTINCT i.id)
                    FROM {$this->table_invoices} i
                    LEFT JOIN {$this->table_customers} c ON i.customer_id = c.id
                    {$where}";
                $total_count = (int) $wpdb->get_var(
                    !empty($params) ? $wpdb->prepare($count_sql, $params) : $count_sql
                );
            }

            $offset = ($page - 1) * $per_page;

            // Main query: Join with customers for names
            $sql = "SELECT
                i.id,
                i.invoice_number,
                i.total_amount,
                i.paid_amount,
                i.status,
                i.sale_date,
                i.created_at,
                i.author_id,
                COALESCE(NULLIF(i.buyer_name, ''), c.name) as customer_name
                FROM {$this->table_invoices} i
                LEFT JOIN {$this->table_customers} c ON i.customer_id = c.id
                {$where}
                ORDER BY {$order_column} DESC
                LIMIT {$per_page} OFFSET {$offset}";
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $invoices = $wpdb->get_results(
            !empty($params) ? $wpdb->prepare($sql, $params) : $sql, 
            ARRAY_A
        );

        $rows = [];
        foreach ($invoices as $inv) {
            $id = intval($inv['id']);

            // Reserved filtering is now handled at SQL level — no PHP-level check needed

            // Get payment history from cig_payments
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $payments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT amount, date, method FROM {$this->table_payments} WHERE invoice_id = %d ORDER BY date ASC",
                    $id
                ),
                ARRAY_A
            );

            $inv_m = [];
            $payment_details = [];

            foreach ($payments as $p) {
                $m = $p['method'] ?? 'other';
                $amt = (float)$p['amount'];
                $pay_date = $p['date'] ?? '';

                $inv_m[] = $method_labels[$m] ?? $m;

                // Collect individual payment details for breakdown
                if ($amt > 0.001) {
                    // Check if this payment matches the filter (for highlighting)
                    $matches_filter = false;
                    // Match specific method OR any method if 'all paid' filter
                    if ($is_payment_method_filter && ($m === $mf || $is_all_paid_filter)) {
                        $pay_ts = $pay_date ? strtotime($pay_date) : 0;
                        $from_ts = $date_from ? strtotime($date_from . ' 00:00:00') : 0;
                        $to_ts = $date_to ? strtotime($date_to . ' 23:59:59') : PHP_INT_MAX;
                        if ($pay_ts >= $from_ts && $pay_ts <= $to_ts) {
                            $matches_filter = true;
                        }
                    }
                    
                    $payment_details[] = [
                        'amount' => $amt,
                        'date' => $pay_date ? substr($pay_date, 0, 10) : '',
                        'method' => $method_labels[$m] ?? $m,
                        'matches_filter' => $matches_filter
                    ];
                }
            }

            // Build detailed payment breakdown HTML
            // Highlight payments that match the filter criteria
            $bd = '';
            foreach ($payment_details as $pd_item) {
                $highlight_style = $pd_item['matches_filter'] ? 'background:#fffde7;padding:2px 4px;border-radius:3px;font-weight:600;' : '';
                $bd .= '<div style="font-size:11px;color:#333;margin-bottom:2px;' . $highlight_style . '">';
                $bd .= number_format($pd_item['amount'], 2) . ' ₾';
                $has_date = !empty($pd_item['date']);
                $has_method = !empty($pd_item['method']);
                if ($has_date || $has_method) {
                    $bd .= ' <span style="color:#666;">(';
                    if ($has_date) {
                        $bd .= esc_html($pd_item['date']);
                    }
                    if ($has_date && $has_method) {
                        $bd .= ' - ';
                    }
                    if ($has_method) {
                        $bd .= esc_html($pd_item['method']);
                    }
                    $bd .= ')</span>';
                }
                $bd .= '</div>';
            }
            if ($bd) $bd = '<div style="margin-top:4px;">' . $bd . '</div>';

            $tot = (float)$inv['total_amount'];
            $pd = (float)$inv['paid_amount'];

            // Get original total (before discounts) from items
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $original_total = (float)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN original_price > 0 AND original_price > price THEN original_price * quantity ELSE price * quantity END), 0) FROM {$this->table_items} WHERE invoice_id = %d AND item_status != 'canceled'",
                    $id
                )
            );

            // Get author name
            $author_name = '';
            if ($inv['author_id']) {
                $author = get_userdata(intval($inv['author_id']));
                $author_name = $author ? $author->display_name : '';
            }

            $rows[] = [
                'id' => $id,
                'invoice_number' => $inv['invoice_number'],
                'customer' => $inv['customer_name'] ?: '—',
                'payment_methods' => implode(', ', array_unique($inv_m)),
                'total' => $tot,
                'original_total' => ($original_total > $tot + 0.01) ? $original_total : 0,
                'paid' => $pd,
                'paid_breakdown' => $bd,
                'due' => max(0, $tot - $pd),
                'author' => $author_name,
                // For fictive invoices, use created_at; for standard, use sale_date
                'date' => ($inv['status'] === 'fictive') 
                    ? (isset($inv['created_at']) && $inv['created_at'] ? substr($inv['created_at'], 0, 16) : '')
                    : ($inv['sale_date'] ? substr($inv['sale_date'], 0, 16) : ''),
                'status' => $inv['status'],
                'view_url' => get_permalink($id),
                'edit_url' => add_query_arg('edit', '1', get_permalink($id))
            ];
        }

        $response = ['invoices' => $rows];
        if ($mf === 'reserved_invoices' && $total_count > 0) {
            $response['pagination'] = [
                'current_page' => $page,
                'total_pages'  => (int) ceil($total_count / $per_page),
                'total_count'  => $total_count,
            ];
        }
        wp_send_json_success($response);
    }

    /**
     * Legacy fallback for get_invoices_by_filters using WP_Query
     * Used when custom tables don't exist yet
     * 
     * IMPORTANT: Date filtering logic depends on context:
     * - When a specific payment_method is selected: filter by payment date
     * - When no payment method is selected: filter by post date
     */
    private function get_invoices_by_filters_legacy() {
        global $wpdb;
        $status = sanitize_text_field($_POST['status'] ?? 'standard');
        $mf = sanitize_text_field($_POST['payment_method'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');
        
        // Determine if we're filtering by payment method (cash flow drill-down)
        // Include 'all' (Total Paid) case - this should also filter by payment date
        // IF status is 'fictive', strictly disable payment method filtering logic,
        // because fictive invoices do not have payment records in the DB.
        $is_payment_method_filter = ($status !== 'fictive') && ($mf && $mf !== 'reserved_invoices');
        $is_all_paid_filter = ($mf === 'all');
        
        // Use a reasonable limit to avoid performance issues
        // When filtering by payment method, we need to scan more posts since we filter in PHP
        $query_limit = $is_payment_method_filter ? 1000 : self::MAX_DRILL_DOWN_RESULTS;
        $args = ['post_type'=>'invoice', 'post_status'=>'publish', 'posts_per_page'=>$query_limit, 'orderby'=>'date', 'order'=>'DESC'];
        
        // Only apply date_query when NOT filtering by payment method
        // When filtering by payment method, we need to check payment dates, not post dates
        if (!$is_payment_method_filter && $date_from) {
            $args['date_query'] = [['after'=>$date_from.' 00:00:00', 'before'=>$date_to.' 23:59:59', 'inclusive'=>true]];
        }
        
        $mq = $this->get_status_meta_query_legacy($status); 
        if($mq) $args['meta_query'] = $mq;
        
        $method_labels = [
            'company_transfer'=>__('კომპანიის ჩარიცხვა','cig'), 
            'cash'=>__('ქეში','cig'), 
            'consignment'=>__('კონსიგნაცია','cig'), 
            'credit'=>__('განვადება','cig'), 
            'other'=>__('სხვა','cig')
        ];
        
        // Parse date range for payment filtering
        $from_ts = $date_from ? strtotime($date_from . ' 00:00:00') : 0;
        $to_ts = $date_to ? strtotime($date_to . ' 23:59:59') : PHP_INT_MAX;
        
        $rows=[];
        $count = 0;
        foreach((new WP_Query($args))->posts as $p) {
            if ($count >= self::MAX_DRILL_DOWN_RESULTS) break;
            
            $id=$p->ID;

            // Search filter: invoice_number, buyer_name, buyer_tax_id
            if ($search) {
                $invoice_number = get_post_meta($id, '_cig_invoice_number', true) ?: '';
                $buyer_name = get_post_meta($id, '_cig_buyer_name', true) ?: '';
                $buyer_tax_id = get_post_meta($id, '_cig_buyer_tax_id', true) ?: '';
                
                // stripos is case-insensitive, so use original $search
                $match = stripos($invoice_number, $search) !== false 
                      || stripos($buyer_name, $search) !== false 
                      || stripos($buyer_tax_id, $search) !== false;
                if (!$match) continue;
            }
            
            if ($mf === 'reserved_invoices') {
                $items = get_post_meta($id, '_cig_items', true) ?: [];
                $has_res = false;
                foreach ($items as $it) {
                    if (strtolower($it['status'] ?? '') === 'reserved') { 
                        $has_res = true; 
                        break; 
                    }
                }
                if (!$has_res) continue;
            }

            $hist=get_post_meta($id,'_cig_payment_history',true);
            $inv_m=[]; 
            $has_matching_payment = false;
            $payment_details = [];
            
            if(is_array($hist)) {
                foreach($hist as $h) {
                    $m=$h['method']??'other'; 
                    $amt=(float)$h['amount'];
                    $pay_date = $h['date'] ?? '';
                    
                    $inv_m[]=$method_labels[$m]??$m;
                    
                    if ($amt > 0.001) {
                        // Check if this payment matches the filter
                        // Match specific method OR any method if 'all paid' filter
                        $matches_filter = false;
                        if ($is_payment_method_filter && ($m === $mf || $is_all_paid_filter)) {
                            $pay_ts = $pay_date ? strtotime($pay_date) : 0;
                            if ($pay_ts >= $from_ts && $pay_ts <= $to_ts) {
                                $has_matching_payment = true;
                                $matches_filter = true;
                            }
                        }
                        
                        $payment_details[] = [
                            'amount' => $amt,
                            'date' => $pay_date,
                            'method' => $method_labels[$m] ?? $m,
                            'matches_filter' => $matches_filter
                        ];
                    }
                }
            }

            // For payment method filter, only include invoices with matching payments in date range
            if ($is_payment_method_filter && !$has_matching_payment) continue;
            
            // Build detailed payment breakdown HTML with highlighting
            $bd=''; 
            foreach($payment_details as $pd_item) {
                $highlight_style = $pd_item['matches_filter'] ? 'background:#fffde7;padding:2px 4px;border-radius:3px;font-weight:600;' : '';
                $bd .= '<div style="font-size:11px;color:#333;margin-bottom:2px;' . $highlight_style . '">';
                $bd .= number_format($pd_item['amount'], 2) . ' ₾';
                $has_date = !empty($pd_item['date']);
                $has_method = !empty($pd_item['method']);
                if ($has_date || $has_method) {
                    $bd .= ' <span style="color:#666;">(';
                    if ($has_date) {
                        $bd .= esc_html($pd_item['date']);
                    }
                    if ($has_date && $has_method) {
                        $bd .= ' - ';
                    }
                    if ($has_method) {
                        $bd .= esc_html($pd_item['method']);
                    }
                    $bd .= ')</span>';
                }
                $bd .= '</div>';
            }
            if($bd) $bd='<div style="margin-top:4px;">'.$bd.'</div>';
            
            $tot=(float)get_post_meta($id,'_cig_invoice_total',true);
            $pd=(float)get_post_meta($id,'_cig_payment_paid_amount',true);
            
            $rows[]=[
                'id'=>$id, 
                'invoice_number'=>get_post_meta($id,'_cig_invoice_number',true), 
                'customer'=>get_post_meta($id,'_cig_buyer_name',true)?:'—', 
                'payment_methods'=>implode(', ',array_unique($inv_m)), 
                'total'=>$tot, 
                'paid'=>$pd, 
                'paid_breakdown'=>$bd, 
                'due'=>max(0,$tot-$pd), 
                'author'=>get_the_author_meta('display_name',$p->post_author), 
                'date'=>get_the_date('Y-m-d H:i',$p), 
                'status'=>get_post_meta($id,'_cig_invoice_status',true), 
                'view_url'=>get_permalink($id), 
                'edit_url'=>add_query_arg('edit','1',get_permalink($id))
            ];
            $count++;
        }
        wp_send_json_success(['invoices'=>$rows]);
    }

    /**
     * Legacy meta query builder for fallback
     */
    private function get_status_meta_query_legacy($status) {
        if ($status === 'all') return [];
        if ($status === 'fictive') return [['key' => '_cig_invoice_status', 'value' => 'fictive', 'compare' => '=']];
        if ($status === 'outstanding') {
             return [
                 'relation' => 'AND',
                 ['relation' => 'OR', ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='], ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']],
                 ['key' => '_cig_payment_remaining_amount', 'value' => 0.001, 'compare' => '>', 'type' => 'DECIMAL']
             ];
        }
        return [['relation' => 'OR', ['key' => '_cig_invoice_status', 'value' => 'standard', 'compare' => '='], ['key' => '_cig_invoice_status', 'compare' => 'NOT EXISTS']]];
    }

    /**
     * Get products by filters using raw SQL
     */
    public function get_products_by_filters() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        global $wpdb;

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');
        $invoice_status = sanitize_text_field($_POST['invoice_status'] ?? 'standard');
        $item_status = sanitize_text_field($_POST['status'] ?? 'sold');
        $payment_method = sanitize_text_field($_POST['payment_method'] ?? '');

        // Fallback if tables don't exist
        if (!$this->tables_exist()) {
            $this->get_products_by_filters_legacy();
            return;
        }

        // Build WHERE clause
        $where = "WHERE 1=1";
        $params = [];
        
        // Filter by invoice status
        if ($invoice_status === 'fictive') {
            $where .= " AND i.status = 'fictive'";
        } elseif ($invoice_status !== 'all') {
            $where .= " AND (i.status = 'standard' OR i.status IS NULL)";
        }
        
        // Filter by date: use created_at for fictive invoices, sale_date for standard
        $date_column = $this->get_date_column_for_status($invoice_status);
        if ($date_from) { 
            $where .= " AND {$date_column} >= %s"; 
            $params[] = $date_from . ' 00:00:00'; 
        }
        if ($date_to) { 
            $where .= " AND {$date_column} <= %s"; 
            $params[] = $date_to . ' 23:59:59'; 
        }
        
        // Filter by item status
        $where .= " AND it.item_status = %s";
        $params[] = $item_status;

        // Main query - select both sale_date and created_at, use appropriate one based on status
        $sql = "SELECT 
            it.product_name as name,
            it.sku,
            it.quantity as qty,
            i.id as invoice_id,
            i.invoice_number,
            i.sale_date,
            i.created_at,
            i.status as invoice_status,
            i.author_id
            FROM {$this->table_items} it
            INNER JOIN {$this->table_invoices} i ON it.invoice_id = i.id
            {$where}
            ORDER BY {$date_column} DESC
            LIMIT 500";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $items = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        $rows = [];
        foreach ($items as $it) {
            $id = intval($it['invoice_id']);
            
            // Get author name
            $author_name = '';
            if ($it['author_id']) {
                $author = get_userdata(intval($it['author_id']));
                $author_name = $author ? $author->display_name : '';
            }

            // Get image from post meta (still stored there for legacy support)
            $items_meta = get_post_meta($id, '_cig_items', true) ?: [];
            $image = '';
            foreach ($items_meta as $item_meta) {
                if (($item_meta['sku'] ?? '') === $it['sku'] || ($item_meta['name'] ?? '') === $it['name']) {
                    $image = $item_meta['image'] ?? '';
                    break;
                }
            }

            // Use created_at for fictive invoices, sale_date for standard
            $display_date = ($it['invoice_status'] === 'fictive') ? ($it['created_at'] ?? '') : ($it['sale_date'] ?? '');

            $rows[] = [
                'name' => $it['name'] ?? '',
                'sku' => $it['sku'] ?? '',
                'image' => $image,
                'qty' => floatval($it['qty']),
                'invoice_id' => $id,
                'invoice_number' => $it['invoice_number'],
                'author_name' => $author_name,
                'date' => $display_date,
                'view_url' => get_permalink($id),
                'edit_url' => add_query_arg('edit', '1', get_permalink($id))
            ];
        }

        wp_send_json_success(['products' => $rows]);
    }

    /**
     * Legacy fallback for get_products_by_filters
     */
    private function get_products_by_filters_legacy() {
        $args = ['post_type'=>'invoice', 'post_status'=>'publish', 'posts_per_page'=>-1, 'fields'=>'ids', 'orderby'=>'date', 'order'=>'DESC'];
        if (!empty($_POST['date_from'])) $args['date_query'] = [['after'=>$_POST['date_from'].' 00:00:00', 'before'=>$_POST['date_to'].' 23:59:59', 'inclusive'=>true]];
        $mq = $this->get_status_meta_query_legacy($_POST['invoice_status']??'standard');
        if(!empty($_POST['payment_method']) && $_POST['payment_method']!=='all') $mq[]=['key'=>'_cig_payment_type', 'value'=>$_POST['payment_method'], 'compare'=>'='];
        if($mq) $args['meta_query']=$mq;
        
        $rows=[]; $st=sanitize_text_field($_POST['status']??'sold');
        foreach((new WP_Query($args))->posts as $id) {
            foreach(get_post_meta($id,'_cig_items',true)?:[] as $it) {
                if(strtolower($it['status']??'sold')!==$st) continue;
                $rows[]=['name'=>$it['name']??'', 'sku'=>$it['sku']??'', 'image'=>$it['image']??'', 'qty'=>floatval($it['qty']), 'invoice_id'=>$id, 'invoice_number'=>get_post_meta($id,'_cig_invoice_number',true), 'author_name'=>get_the_author_meta('display_name',get_post_field('post_author',$id)), 'date'=>get_post_field('post_date',$id), 'view_url'=>get_permalink($id), 'edit_url'=>add_query_arg('edit','1',get_permalink($id))];
                if(count($rows)>=500) break 2;
            }
        }
        wp_send_json_success(['products'=>$rows]);
    }

    public function get_product_insight() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        // This is a placeholder for product insight logic, assuming similar structure to others
        // Implementation would fetch specific product stats
        wp_send_json_success(['data' => []]); // Simplified for brevity as logic wasn't fully shown in original file split
    }
    
    public function export_statistics() { wp_send_json_success(['redirect' => true]); }

    /**
     * Get top selling products for the Product Insight tab
     * Returns products sorted by quantity sold (descending)
     */
    public function get_top_products() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        global $wpdb;

        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to   = sanitize_text_field($_POST['date_to'] ?? '');
        $search    = sanitize_text_field($_POST['search'] ?? '');

        // Fallback if tables don't exist
        if (!$this->tables_exist()) {
            wp_send_json_success(['products' => []]);
        }

        // Build WHERE clause - only standard/active invoices
        // Note: status can be NULL for legacy invoices created before status field was added
        // These are treated as standard/active invoices
        $where = "WHERE (i.status = 'standard' OR i.status IS NULL)";
        $params = [];

        // Filter by sale_date
        if ($date_from) {
            $where .= " AND i.sale_date >= %s";
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $where .= " AND i.sale_date <= %s";
            $params[] = $date_to . ' 23:59:59';
        }

        // Search filter: Product Name OR SKU
        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where .= " AND (it.product_name LIKE %s OR it.sku LIKE %s)";
            $params[] = $search_like;
            $params[] = $search_like;
        }

        // Only sold items count towards sales
        $where .= " AND it.item_status = 'sold'";

        // Main query: Group by product identity (SKU or name) and aggregate
        // Use AVG for price to handle cases where same product may have different prices across invoices
        $sql = "SELECT
            COALESCE(NULLIF(it.sku, ''), it.product_name) as product_key,
            MAX(it.product_name) as product_name,
            MAX(it.sku) as sku,
            AVG(it.price) as unit_price,
            SUM(it.quantity) as sold_qty,
            SUM(it.total) as total_revenue
            FROM {$this->table_items} it
            INNER JOIN {$this->table_invoices} i ON it.invoice_id = i.id
            {$where}
            GROUP BY product_key
            ORDER BY sold_qty DESC
            LIMIT 100";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results(
            !empty($params) ? $wpdb->prepare($sql, $params) : $sql,
            ARRAY_A
        );

        $products = [];
        foreach ($results as $row) {
            $products[] = [
                'product_name' => $row['product_name'] ?? '',
                'sku'          => $row['sku'] ?? '',
                'price'        => (float)($row['unit_price'] ?? 0),
                'sold_qty'     => (float)($row['sold_qty'] ?? 0),
                'total_revenue'=> (float)($row['total_revenue'] ?? 0),
            ];
        }

        wp_send_json_success(['products' => $products]);
    }

    /**
     * Get Product Performance Table for the redesigned Product Insight tab
     * Returns aggregated product data with sold, reserved, fictive counts and revenue
     * Only shows products that have activity (sold/reserved/fictive > 0) in the selected period
     */
    public function get_product_performance_table() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        global $wpdb;

        $date_from  = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to    = sanitize_text_field($_POST['date_to'] ?? '');
        $search     = sanitize_text_field($_POST['search'] ?? '');
        $page       = max(1, intval($_POST['page'] ?? 1));
        $per_page   = 20;
        $offset     = ($page - 1) * $per_page;

        // If tables don't exist, return empty
        if (!$this->tables_exist()) {
            wp_send_json_success([
                'products' => [],
                'pagination' => ['current_page' => 1, 'total_pages' => 0]
            ]);
        }

        // Prepare date parameters
        $date_from_full = $date_from ? $date_from . ' 00:00:00' : '';
        $date_to_full = $date_to ? $date_to . ' 23:59:59' : '';
        $search_like = $search ? '%' . $wpdb->esc_like($search) . '%' : '';

        // Build SQL dynamically based on filters
        // Main aggregation query with CASE WHEN for conditional sums
        $sql = "SELECT 
            it.product_id,
            MAX(it.product_name) as product_name,
            MAX(it.sku) as sku,
            MAX(it.price) as price,
            MAX(it.image) as image,
            COALESCE(SUM(CASE 
                WHEN inv.status = 'standard' AND it.item_status = 'sold'";
        if ($date_from) $sql .= " AND inv.sale_date >= %s";
        if ($date_to) $sql .= " AND inv.sale_date <= %s";
        $sql .= " THEN it.quantity ELSE 0 END), 0) as total_sold,
            COALESCE(SUM(CASE 
                WHEN inv.status = 'standard' AND it.item_status = 'reserved'";
        if ($date_from) $sql .= " AND inv.sale_date >= %s";
        if ($date_to) $sql .= " AND inv.sale_date <= %s";
        $sql .= " THEN it.quantity ELSE 0 END), 0) as total_reserved,
            COALESCE(SUM(CASE 
                WHEN inv.status = 'fictive'";
        if ($date_from) $sql .= " AND inv.created_at >= %s";
        if ($date_to) $sql .= " AND inv.created_at <= %s";
        $sql .= " THEN it.quantity ELSE 0 END), 0) as total_fictive,
            COALESCE(SUM(CASE 
                WHEN inv.status = 'standard' AND it.item_status = 'sold'";
        if ($date_from) $sql .= " AND inv.sale_date >= %s";
        if ($date_to) $sql .= " AND inv.sale_date <= %s";
        $sql .= " THEN it.total ELSE 0 END), 0) as total_revenue
            FROM {$this->table_items} it
            INNER JOIN {$this->table_invoices} inv ON it.invoice_id = inv.id
            WHERE " . ($search ? "(it.product_name LIKE %s OR it.sku LIKE %s)" : "1=1") . "
            GROUP BY it.product_id
            HAVING (total_sold > 0 OR total_reserved > 0 OR total_fictive > 0)
            ORDER BY total_sold DESC, total_reserved DESC, total_fictive DESC
            LIMIT %d OFFSET %d";

        // Build parameters array in correct order
        $params = [];
        // total_sold date params
        if ($date_from) $params[] = $date_from_full;
        if ($date_to) $params[] = $date_to_full;
        // total_reserved date params
        if ($date_from) $params[] = $date_from_full;
        if ($date_to) $params[] = $date_to_full;
        // total_fictive date params (uses created_at)
        if ($date_from) $params[] = $date_from_full;
        if ($date_to) $params[] = $date_to_full;
        // total_revenue date params
        if ($date_from) $params[] = $date_from_full;
        if ($date_to) $params[] = $date_to_full;
        // search params
        if ($search) {
            $params[] = $search_like;
            $params[] = $search_like;
        }
        // pagination params
        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        // Build count query for pagination
        $count_sql = "SELECT COUNT(*) FROM (
            SELECT it.product_id,
            COALESCE(SUM(CASE 
                WHEN inv.status = 'standard' AND it.item_status = 'sold'";
        if ($date_from) $count_sql .= " AND inv.sale_date >= %s";
        if ($date_to) $count_sql .= " AND inv.sale_date <= %s";
        $count_sql .= " THEN it.quantity ELSE 0 END), 0) as total_sold,
            COALESCE(SUM(CASE 
                WHEN inv.status = 'standard' AND it.item_status = 'reserved'";
        if ($date_from) $count_sql .= " AND inv.sale_date >= %s";
        if ($date_to) $count_sql .= " AND inv.sale_date <= %s";
        $count_sql .= " THEN it.quantity ELSE 0 END), 0) as total_reserved,
            COALESCE(SUM(CASE 
                WHEN inv.status = 'fictive'";
        if ($date_from) $count_sql .= " AND inv.created_at >= %s";
        if ($date_to) $count_sql .= " AND inv.created_at <= %s";
        $count_sql .= " THEN it.quantity ELSE 0 END), 0) as total_fictive
            FROM {$this->table_items} it
            INNER JOIN {$this->table_invoices} inv ON it.invoice_id = inv.id
            WHERE " . ($search ? "(it.product_name LIKE %s OR it.sku LIKE %s)" : "1=1") . "
            GROUP BY it.product_id
            HAVING (total_sold > 0 OR total_reserved > 0 OR total_fictive > 0)
        ) as subquery";

        // Build count params (same as main query but without pagination)
        $count_params = [];
        if ($date_from) $count_params[] = $date_from_full;
        if ($date_to) $count_params[] = $date_to_full;
        if ($date_from) $count_params[] = $date_from_full;
        if ($date_to) $count_params[] = $date_to_full;
        if ($date_from) $count_params[] = $date_from_full;
        if ($date_to) $count_params[] = $date_to_full;
        if ($search) {
            $count_params[] = $search_like;
            $count_params[] = $search_like;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $total_count = $wpdb->get_var(
            !empty($count_params) ? $wpdb->prepare($count_sql, $count_params) : $count_sql
        );
        $total_pages = max(1, ceil(intval($total_count) / $per_page));

        // Get totals across all pages (total units sold/reserved + unique products)
        $totals_sql = "SELECT
            COUNT(DISTINCT CASE
                WHEN inv.status = 'standard' AND it.item_status IN ('sold','reserved')";
        if ($date_from) $totals_sql .= " AND inv.sale_date >= %s";
        if ($date_to) $totals_sql .= " AND inv.sale_date <= %s";
        $totals_sql .= " THEN it.product_id ELSE NULL END) as unique_products,
            COALESCE(SUM(CASE
                WHEN inv.status = 'standard' AND it.item_status IN ('sold','reserved')";
        if ($date_from) $totals_sql .= " AND inv.sale_date >= %s";
        if ($date_to) $totals_sql .= " AND inv.sale_date <= %s";
        $totals_sql .= " THEN it.quantity ELSE 0 END), 0) as total_units_sold,
            COALESCE(SUM(CASE
                WHEN inv.status = 'standard' AND it.item_status IN ('sold','reserved')";
        if ($date_from) $totals_sql .= " AND inv.sale_date >= %s";
        if ($date_to) $totals_sql .= " AND inv.sale_date <= %s";
        $totals_sql .= " THEN it.total ELSE 0 END), 0) as total_income
            FROM {$this->table_items} it
            INNER JOIN {$this->table_invoices} inv ON it.invoice_id = inv.id
            WHERE " . ($search ? "(it.product_name LIKE %s OR it.sku LIKE %s)" : "1=1");

        $totals_params = [];
        // unique_products date params
        if ($date_from) $totals_params[] = $date_from_full;
        if ($date_to) $totals_params[] = $date_to_full;
        // total_units_sold date params
        if ($date_from) $totals_params[] = $date_from_full;
        if ($date_to) $totals_params[] = $date_to_full;
        // total_income date params
        if ($date_from) $totals_params[] = $date_from_full;
        if ($date_to) $totals_params[] = $date_to_full;
        if ($search) {
            $totals_params[] = $search_like;
            $totals_params[] = $search_like;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $totals_row = $wpdb->get_row(
            !empty($totals_params) ? $wpdb->prepare($totals_sql, $totals_params) : $totals_sql,
            ARRAY_A
        );
        $total_units_sold = (int)($totals_row['total_units_sold'] ?? 0);
        $unique_products  = (int)($totals_row['unique_products'] ?? 0);
        $total_income     = (float)($totals_row['total_income'] ?? 0);

        // Process results and get current stock info
        $products = [];
        foreach ($results as $row) {
            $product_id = intval($row['product_id']);
            
            // Get current stock from WooCommerce product if available
            $stock = null;
            $image_url = $row['image'] ?: '';
            $price = (float)$row['price'];
            
            if ($product_id > 0 && function_exists('wc_get_product')) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $stock = $product->get_stock_quantity();
                    $price = (float)$product->get_price() ?: $price;
                    if (!$image_url) {
                        $image_url = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: '';
                    }
                }
            }

            $products[] = [
                'product_id'    => $product_id,
                'image'         => $image_url,
                'name'          => $row['product_name'] ?: '',
                'sku'           => $row['sku'] ?: '',
                'price'         => $price,
                'stock'         => $stock,
                'total_reserved'=> (float)$row['total_reserved'],
                'total_fictive' => (float)$row['total_fictive'],
                'total_sold'    => (float)$row['total_sold'],
                'total_revenue' => (float)$row['total_revenue'],
            ];
        }

        wp_send_json_success([
            'products' => $products,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => (int)$total_pages
            ],
            'totals' => [
                'total_units_sold' => $total_units_sold,
                'unique_products'  => $unique_products,
                'total_income'     => $total_income,
            ]
        ]);
    }

    /**
     * ----------------------------------------------------------------
     * NEW: EXTERNAL BALANCE (Wallet Logic)
     * ----------------------------------------------------------------
     */
    public function get_external_balance() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');

        $start = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '';
        $end   = isset($_POST['end_date'])   ? sanitize_text_field($_POST['end_date']) : '';

        // -- PART A: Calculate "Other" Revenue (Debit) --
        $invoice_args = [
            'post_type'      => 'invoice',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_cig_payment_type',
                    'value'   => ['other', 'mixed'],
                    'compare' => 'IN'
                ]
            ]
        ];

        $invoices = get_posts($invoice_args);
        
        $global_debit = 0; // Total accumulated over time
        $period_debit = 0; // Accumulated in selected date range

        foreach ($invoices as $inv) {
            // Check if Fictive (Skip)
            $status = get_post_meta($inv->ID, '_cig_invoice_status', true);
            if ($status === 'fictive') continue;

            $history = get_post_meta($inv->ID, '_cig_payment_history', true);
            if (!is_array($history)) continue;

            foreach ($history as $pay) {
                if (isset($pay['method']) && $pay['method'] === 'other') {
                    $amt = floatval($pay['amount']);
                    $date = isset($pay['date']) ? $pay['date'] : '';

                    // Add to Global
                    $global_debit += $amt;

                    // Add to Period if matches
                    if ($this->is_date_in_range($date, $start, $end)) {
                        $period_debit += $amt;
                    }
                }
            }
        }

        // -- PART B: Calculate Deposits (Credit) --
        $deposit_args = [
            'post_type'      => 'cig_deposit',
            'posts_per_page' => -1,
            'post_status'    => 'any' // Deposits are internal
        ];

        $deposits_query = get_posts($deposit_args);
        
        $global_credit = 0;
        $period_credit = 0;
        $deposit_history = [];

        foreach ($deposits_query as $dep) {
            $amt  = floatval(get_post_meta($dep->ID, '_cig_deposit_amount', true));
            $date = get_post_meta($dep->ID, '_cig_deposit_date', true);
            $note = get_post_meta($dep->ID, '_cig_deposit_note', true);

            // Add to Global
            $global_credit += $amt;

            // Add to Period
            if ($this->is_date_in_range($date, $start, $end)) {
                $period_credit += $amt;
                
                // Add to history list for table
                $deposit_history[] = [
                    'id'      => $dep->ID,
                    'date'    => $date,
                    'amount'  => $amt,
                    'comment' => $note
                ];
            }
        }

        // Sort history by date desc
        usort($deposit_history, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // -- PART C: Response --
        wp_send_json_success([
            'cards' => [
                // Cards follow the filter (Period data)
                'accumulated' => number_format($period_debit, 2, '.', ''),
                'deposited'   => number_format($period_credit, 2, '.', ''),
                
                // Balance is ALWAYS Global (Total Debt)
                'balance'     => number_format($global_credit - $global_debit, 2, '.', '') 
                // Note: Logic is Credit - Debit. 
                // If I gathered 1000 (Debit) and deposited 800 (Credit), Balance is -200 (I owe 200).
                // If Balance is negative, it's red (Due).
            ],
            'history' => $deposit_history
        ]);
    }

    /**
     * Add New Deposit
     */
    public function add_deposit() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');

        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $date   = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');
        $note   = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';

        if ($amount <= 0) {
            wp_send_json_error(['message' => __('Amount must be greater than 0', 'cig')]);
        }

        $post_id = wp_insert_post([
            'post_type'   => 'cig_deposit',
            'post_status' => 'publish',
            'post_title'  => 'Deposit ' . $date . ' - ' . $amount,
            'post_author' => get_current_user_id()
        ]);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
        }

        update_post_meta($post_id, '_cig_deposit_amount', $amount);
        update_post_meta($post_id, '_cig_deposit_date', $date);
        update_post_meta($post_id, '_cig_deposit_note', $note);

        wp_send_json_success(['message' => __('Deposit added successfully', 'cig')]);
    }

    /**
     * Delete Deposit
     */
    public function delete_deposit() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');

        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
        if (!$id || get_post_type($id) !== 'cig_deposit') {
            wp_send_json_error(['message' => __('Invalid ID', 'cig')]);
        }

        wp_delete_post($id, true);
        wp_send_json_success(['message' => __('Deposit deleted', 'cig')]);
    }

    /**
     * Helper: Check date range
     */
    private function is_date_in_range($date, $start, $end) {
        if (!$date) return false;
        if (!$start && !$end) return true; // No filter
        
        $ts = strtotime($date);
        $s_ts = $start ? strtotime($start . ' 00:00:00') : 0;
        $e_ts = $end ? strtotime($end . ' 23:59:59') : PHP_INT_MAX;

        return ($ts >= $s_ts && $ts <= $e_ts);
    }

    /**
     * Update Invoice Date from Statistics Dashboard
     * 
     * Updates the sale_date and activation_date in cig_invoices table
     * and syncs post_date in wp_posts table.
     * 
     * IMPORTANT: Uses 'id' as the primary key (NOT 'post_id')
     * The 'id' column in cig_invoices matches the WP Post ID.
     */
    public function update_invoice_date_stat() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'edit_posts');
        global $wpdb;

        $invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
        $new_date   = isset($_POST['new_date']) ? sanitize_text_field($_POST['new_date']) : '';

        if (!$invoice_id) {
            wp_send_json_error(['message' => __('Invalid invoice ID', 'cig')]);
        }

        if (empty($new_date)) {
            wp_send_json_error(['message' => __('Date cannot be empty', 'cig')]);
        }

        // Convert datetime-local format (YYYY-MM-DDTHH:MM) to MySQL DATETIME format
        $mysql_date = str_replace('T', ' ', $new_date);
        
        // Ensure seconds are included
        if (strlen($mysql_date) === 16) {
            $mysql_date .= ':00';
        }

        // Validate date format
        $parsed_date = strtotime($mysql_date);
        if (!$parsed_date) {
            wp_send_json_error(['message' => __('Invalid date format', 'cig')]);
        }

        // Format for MySQL - use date() for local time (not gmdate())
        $mysql_datetime = date('Y-m-d H:i:s', $parsed_date);
        $mysql_date_only = date('Y-m-d', $parsed_date);

        // Update cig_invoices table - use 'id' as primary key (NOT 'post_id')
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->update(
            $this->table_invoices,
            [
                'sale_date'       => $mysql_datetime,
                'activation_date' => $mysql_datetime
            ],
            ['id' => $invoice_id],
            ['%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            wp_send_json_error([
                'message' => __('Database update failed', 'cig'),
                'error'   => $wpdb->last_error
            ]);
        }

        // Sync WP Post date to maintain consistency
        // get_gmt_from_date() expects local time and converts to GMT
        $gmt_date = get_gmt_from_date($mysql_datetime);
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $post_result = $wpdb->update(
            $wpdb->posts,
            [
                'post_date'         => $mysql_datetime,
                'post_date_gmt'     => $gmt_date,
                'post_modified'     => $mysql_datetime,
                'post_modified_gmt' => $gmt_date
            ],
            ['ID' => $invoice_id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($post_result === false) {
            wp_send_json_error([
                'message' => __('Failed to sync post date', 'cig'),
                'error'   => $wpdb->last_error
            ]);
        }

        // Also update sold_date post meta for consistency with warranty sheet
        update_post_meta($invoice_id, '_cig_sold_date', $mysql_date_only);

        wp_send_json_success([
            'message'  => __('Date updated successfully', 'cig'),
            'new_date' => $mysql_datetime
        ]);
    }
}