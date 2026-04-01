<?php
if (!defined('ABSPATH')) exit;

class CIG_Ajax_Customers {
    private $security;

    /** @var string Table names */
    private $table_invoices;
    private $table_customers;

    public function __construct($security) {
        global $wpdb;
        
        $this->security = $security;
        
        // Initialize table names
        $this->table_invoices  = $wpdb->prefix . 'cig_invoices';
        $this->table_customers = $wpdb->prefix . 'cig_customers';

        add_action('wp_ajax_cig_get_customer_insights', [$this, 'get_customer_insights']);
        add_action('wp_ajax_cig_get_customer_invoices_details', [$this, 'get_customer_invoices_details']);
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

    public function filter_customer_search($where) {
        global $wpdb;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where .= $wpdb->prepare(" AND ({$wpdb->posts}.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id={$wpdb->posts}.ID AND meta_key='_cig_customer_tax_id' AND meta_value LIKE %s))", $like, $like);
        }
        return $where;
    }

    /**
     * Get customer insights with aggregated statistics
     * Uses custom tables for proper LEFT JOIN aggregation
     */
    public function get_customer_insights() { 
        $this->security->verify_ajax_request('cig_nonce','nonce','edit_posts');
        global $wpdb;

        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $paged = isset($_POST['paged']) ? intval($_POST['paged']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';

        // Check if custom tables exist
        if (!$this->tables_exist()) {
            // Fallback to legacy method
            $this->get_customer_insights_legacy();
            return;
        }

        $offset = ($paged - 1) * $per_page;

        // Build WHERE clause for customers
        $where_customer = "WHERE 1=1";
        $params = [];

        if ($search) {
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $where_customer .= " AND (c.name LIKE %s OR c.tax_id LIKE %s)";
            $params[] = $search_like;
            $params[] = $search_like;
        }

        // Build invoice filter for date range
        $invoice_where = "(i.status = 'standard' OR i.status IS NULL)";
        $invoice_params = [];

        if ($date_from) {
            $invoice_where .= " AND i.sale_date >= %s";
            $invoice_params[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $invoice_where .= " AND i.sale_date <= %s";
            $invoice_params[] = $date_to . ' 23:59:59';
        }

        // Get total count for pagination
        $count_sql = "SELECT COUNT(DISTINCT c.id) FROM {$this->table_customers} c {$where_customer}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $total_customers = $wpdb->get_var(
            !empty($params) ? $wpdb->prepare($count_sql, $params) : $count_sql
        );
        $total_pages = ceil($total_customers / $per_page);

        // Main query: Get customers with aggregated invoice data
        // The SQL uses CASE WHEN to conditionally aggregate invoices based on date range
        // This allows showing only invoices within the selected period while still listing all customers
        $sql = "SELECT 
            c.id,
            c.name,
            c.tax_id,
            COUNT(DISTINCT CASE WHEN {$invoice_where} THEN i.id END) as invoice_count,
            COALESCE(SUM(CASE WHEN {$invoice_where} THEN i.total_amount ELSE 0 END), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN {$invoice_where} THEN i.paid_amount ELSE 0 END), 0) as total_paid
            FROM {$this->table_customers} c
            LEFT JOIN {$this->table_invoices} i ON c.id = i.customer_id
            {$where_customer}
            GROUP BY c.id, c.name, c.tax_id
            ORDER BY total_revenue DESC
            LIMIT %d OFFSET %d";

        // Parameter order in SQL:
        // 1. $invoice_params (first CASE for COUNT) - date filters
        // 2. $invoice_params (second CASE for SUM total_amount) - date filters
        // 3. $params (WHERE clause) - search filters
        // 4. $per_page, $offset (LIMIT/OFFSET) - pagination
        $all_params = array_merge($invoice_params, $invoice_params, $params, [$per_page, $offset]);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare($sql, $all_params),
            ARRAY_A
        );

        $customers = [];
        foreach ($results as $row) {
            $revenue = floatval($row['total_revenue']);
            $paid = floatval($row['total_paid']);
            $customers[] = [
                'id' => intval($row['id']),
                'name' => $row['name'] ?: '—',
                'tax_id' => $row['tax_id'] ?: '—',
                'count' => intval($row['invoice_count']),
                'revenue' => $revenue,
                'paid' => $paid,
                'due' => max(0, $revenue - $paid)
            ];
        }

        wp_send_json_success([
            'customers' => $customers,
            'total_pages' => (int)$total_pages
        ]);
    }

    /**
     * Legacy fallback for get_customer_insights
     */
    private function get_customer_insights_legacy() {
        $s = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $paged = isset($_POST['paged']) ? intval($_POST['paged']) : 1;
        
        $args = ['post_type'=>'cig_customer','post_status'=>'publish','posts_per_page'=>20,'paged'=>$paged,'fields'=>'ids'];
        if($s) add_filter('posts_where', [$this,'filter_customer_search']);
        $q = new WP_Query($args);
        if($s) remove_filter('posts_where', [$this,'filter_customer_search']);
        
        $custs = []; 
        foreach($q->posts as $cid) {
            $invs = get_posts([
                'post_type'=>'invoice',
                'post_status'=>'publish',
                'posts_per_page'=>-1,
                'fields'=>'ids',
                'meta_query'=>[
                    ['key'=>'_cig_customer_id','value'=>$cid],
                    ['relation'=>'OR',
                        ['key'=>'_cig_invoice_status','value'=>'standard'],
                        ['key'=>'_cig_invoice_status','compare'=>'NOT EXISTS']
                    ]
                ]
            ]);
            $rev = 0; $pd = 0; 
            foreach($invs as $iid) { 
                $rev += floatval(get_post_meta($iid,'_cig_invoice_total',true)); 
                $pd += floatval(get_post_meta($iid,'_cig_payment_paid_amount',true)); 
            }
            $custs[] = [
                'id' => $cid, 
                'name' => get_the_title($cid), 
                'tax_id' => get_post_meta($cid,'_cig_customer_tax_id',true) ?: '—', 
                'count' => count($invs), 
                'revenue' => $rev, 
                'paid' => $pd, 
                'due' => max(0, $rev - $pd)
            ];
        }
        wp_send_json_success(['customers'=>$custs, 'total_pages'=>$q->max_num_pages]);
    }

    /**
     * Get customer invoice details for drill-down
     * Returns list of invoices for a specific customer
     */
    public function get_customer_invoices_details() {
        $this->security->verify_ajax_request('cig_nonce','nonce','edit_posts');
        global $wpdb;

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';

        if (!$customer_id) {
            wp_send_json_error(['message' => 'Invalid customer ID']);
            return;
        }

        // Check if custom tables exist
        if (!$this->tables_exist()) {
            // Fallback to legacy method
            $this->get_customer_invoices_details_legacy($customer_id);
            return;
        }

        // Get customer name
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $customer_name = $wpdb->get_var(
            $wpdb->prepare("SELECT name FROM {$this->table_customers} WHERE id = %d", $customer_id)
        );

        // Build WHERE clause
        $where = "WHERE i.customer_id = %d AND (i.status = 'standard' OR i.status IS NULL)";
        $params = [$customer_id];

        if ($date_from) {
            $where .= " AND i.sale_date >= %s";
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $where .= " AND i.sale_date <= %s";
            $params[] = $date_to . ' 23:59:59';
        }

        // Query invoices for this customer
        $sql = "SELECT 
            i.id,
            i.invoice_number,
            i.sale_date,
            i.total_amount,
            i.paid_amount
            FROM {$this->table_invoices} i
            {$where}
            ORDER BY i.sale_date DESC
            LIMIT 200";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare($sql, $params),
            ARRAY_A
        );

        $invoices = [];
        foreach ($results as $row) {
            $total = floatval($row['total_amount']);
            $paid = floatval($row['paid_amount']);
            $due = max(0, $total - $paid);
            $inv_id = intval($row['id']);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $orig_tot = (float)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(CASE WHEN original_price > 0 AND original_price > price THEN original_price * quantity ELSE price * quantity END), 0) FROM {$this->table_items} WHERE invoice_id = %d AND item_status != 'canceled'",
                    $inv_id
                )
            );

            $invoices[] = [
                'number' => $row['invoice_number'] ?: '—',
                'date' => $row['sale_date'] ? substr($row['sale_date'], 0, 10) : '—',
                'total' => $total,
                'original_total' => ($orig_tot > $total + 0.01) ? $orig_tot : 0,
                'paid' => $paid,
                'due' => $due,
                'status' => ($due < 0.01) ? 'Paid' : 'Unpaid',
                'view_url' => get_permalink($inv_id)
            ];
        }

        wp_send_json_success([
            'customer_name' => $customer_name ?: '—',
            'invoices' => $invoices
        ]);
    }

    /**
     * Legacy fallback for get_customer_invoices_details
     */
    private function get_customer_invoices_details_legacy($cid) {
        $args = [
            'post_type'=>'invoice',
            'post_status'=>'publish',
            'posts_per_page'=>200,
            'orderby'=>'date',
            'order'=>'DESC',
            'meta_query'=>[
                ['key'=>'_cig_customer_id','value'=>$cid],
                ['relation'=>'OR',
                    ['key'=>'_cig_invoice_status','value'=>'standard'],
                    ['key'=>'_cig_invoice_status','compare'=>'NOT EXISTS']
                ]
            ]
        ];
        
        $invs = []; 
        $q = new WP_Query($args);
        foreach($q->posts as $p) { 
            $id = $p->ID; 
            $t = floatval(get_post_meta($id,'_cig_invoice_total',true)); 
            $pd = floatval(get_post_meta($id,'_cig_payment_paid_amount',true)); 
            $due = max(0, $t - $pd);
            $invs[] = [
                'number' => get_post_meta($id,'_cig_invoice_number',true) ?: '—', 
                'date' => get_the_date('Y-m-d',$id), 
                'total' => $t, 
                'paid' => $pd, 
                'due' => $due, 
                'status' => ($due < 0.01) ? 'Paid' : 'Unpaid', 
                'view_url' => get_permalink($id)
            ]; 
        }
        wp_send_json_success(['customer_name'=>get_the_title($cid), 'invoices'=>$invs]);
    }
}