<?php
/**
 * Invoice management handler
 * Updated: Security fix - Restrict invoice view to logged-in users only
 *
 * @package CIG
 * @since 4.9.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG Invoice Class
 */
class CIG_Invoice {

    /**
     * Regex pattern for matching invoice numbers (letters followed by digits)
     * Allows zero or more letters for prefix (for flexibility with pure numeric formats)
     */
    const INVOICE_NUMBER_PATTERN = '/^([A-Za-z]*)([0-9]+)$/';

    /** @var CIG_Stock_Manager */
    private $stock;

    /** @var CIG_Validator */
    private $validator;

    /** @var CIG_Logger */
    private $logger;

    /**
     * Constructor
     *
     * @param CIG_Stock_Manager|null $stock
     * @param CIG_Validator|null     $validator
     * @param CIG_Logger|null        $logger
     */
    public function __construct($stock = null, $validator = null, $logger = null) {
        $this->stock     = $stock     ?: (function_exists('CIG') ? CIG()->stock     : null);
        $this->validator = $validator ?: (function_exists('CIG') ? CIG()->validator : null);
        $this->logger    = $logger    ?: (function_exists('CIG') ? CIG()->logger    : null);

        add_action('init', [$this, 'register_post_type']);
        add_action('admin_init', [$this, 'migrate_to_canvas']);
        add_shortcode('invoice_generator', [$this, 'render_shortcode']);
        add_shortcode('products_stock_table', [$this, 'render_products_stock_table']);
        
        add_filter('template_include', [$this, 'load_invoice_template'], 99);
    }

    /**
     * Register invoice CPT & Deposit CPT
     */
    public function register_post_type() {
        // 1. Invoice CPT
        register_post_type('invoice', [
            'labels' => [
                'name'               => __('Invoices', 'cig'),
                'singular_name'      => __('Invoice', 'cig'),
                'add_new'            => __('Add New', 'cig'),
                'add_new_item'       => __('Add New Invoice', 'cig'),
                'edit_item'          => __('Edit Invoice', 'cig'),
                'new_item'           => __('New Invoice', 'cig'),
                'view_item'          => __('View Invoice', 'cig'),
                'search_items'       => __('Search Invoices', 'cig'),
                'not_found'          => __('No invoices found', 'cig'),
                'not_found_in_trash' => __('No invoices in Trash', 'cig'),
                'menu_name'          => __('Invoices', 'cig'),
            ],
            'public'             => false,
            'publicly_queryable' => true, // Must be true to view single invoice
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => ['slug' => 'invoice'],
            'supports'           => ['title'],
            'has_archive'        => false,
            'hierarchical'       => false,
            'menu_icon'          => 'dashicons-media-spreadsheet',
        ]);

        // 2. Deposit CPT (For External Balance Logic)
        register_post_type('cig_deposit', [
            'labels' => [
                'name'          => __('Deposits', 'cig'),
                'singular_name' => __('Deposit', 'cig'),
            ],
            'public'             => false,  // Internal use only
            'publicly_queryable' => false,
            'show_ui'            => false,  // Hidden from admin menu
            'supports'           => ['title', 'custom-fields', 'author'],
            'has_archive'        => false,
            'can_export'         => true,
        ]);
    }

    /**
     * Render invoice generator shortcode
     */
    public function render_shortcode($atts) {
        if (!current_user_can('manage_woocommerce')) {
            return '<div class="notice notice-warning" style="padding:12px;">' .
                   esc_html__('Only administrators or shop managers can access the Invoice Generator.', 'cig') .
                   '</div>';
        }

        ob_start();
        $settings = get_option('cig_settings', []);
        include CIG_TEMPLATES_DIR . 'shortcode-invoice.php';
        return ob_get_clean();
    }

    /**
     * Render products stock table shortcode
     */
    public function render_products_stock_table($atts) {
        ob_start();
        include CIG_TEMPLATES_DIR . 'products-stock-table.php';
        return ob_get_clean();
    }

    /**
     * Load custom template for single invoice to bypass theme layout
     */
    public function load_invoice_template($template) {
        if (is_singular('invoice')) {
            
            // --- SECURITY CHECK START ---
            // Only logged-in users can view invoices
            if (!is_user_logged_in()) {
                wp_safe_redirect(home_url()); // Redirect guests to home page
                exit;
            }
            // --- SECURITY CHECK END ---

            $can_edit = current_user_can('manage_woocommerce');

            if (isset($_GET['warranty'])) {
                return CIG_TEMPLATES_DIR . 'warranty-sheet.php';
            } 
            elseif ($can_edit && isset($_GET['edit'])) {
                return CIG_TEMPLATES_DIR . 'edit-invoice.php';
            } 
            else {
                return CIG_TEMPLATES_DIR . 'single-invoice.php';
            }
        }
        return $template;
    }

    /**
     * Migrate existing invoices to Elementor Canvas template
     */
    public function migrate_to_canvas() {
        if (!current_user_can('manage_woocommerce')) return;
        if (get_option('cig_canvas_migrated')) return;

        $query = new WP_Query(['post_type'=>'invoice', 'post_status'=>'any', 'posts_per_page'=>-1, 'fields'=>'ids']);
        foreach ($query->posts as $post_id) {
            if (get_post_meta($post_id, '_wp_page_template', true) !== 'elementor_canvas') {
                update_post_meta($post_id, '_wp_page_template', 'elementor_canvas');
            }
        }
        update_option('cig_canvas_migrated', 1, false);
    }

    /**
     * Get next invoice number
     * 
     * Logic:
     * 1. If wp_cig_invoices table is empty, return the starting number from settings
     * 2. If invoices exist, return max(existing_max + 1, starting_number)
     */
    public static function get_next_number() {
        global $wpdb;

        // Acquire MySQL advisory lock to prevent race conditions
        $lock_acquired = $wpdb->get_var("SELECT GET_LOCK('cig_invoice_number', 10)");
        if ($lock_acquired != 1) {
            // Lock not acquired (timeout) — fall through without lock, best-effort
            error_log('CIG: Could not acquire invoice number lock in get_next_number()');
        }

        try {
            // Get the starting invoice number from settings
            $settings = get_option('cig_settings', []);
            $starting_invoice = $settings['starting_invoice_number'] ?? '';

            // Parse starting invoice number or use defaults
            $starting_prefix = CIG_INVOICE_NUMBER_PREFIX;
            $starting_seq = CIG_INVOICE_NUMBER_BASE;

            if (!empty($starting_invoice)) {
                $parsed = self::parse_invoice_number($starting_invoice);
                if ($parsed) {
                    $starting_prefix = $parsed['prefix'];
                    $starting_seq = $parsed['number'];
                }
            }

            // Check if custom table exists and has invoices
            $table_invoices = $wpdb->prefix . 'cig_invoices';
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_invoices)) === $table_invoices;

            $max_seq_from_db = 0;
            $has_invoices = false;

            if ($table_exists) {
                // Get count and max invoice number from custom table
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_invoices}");
                $has_invoices = ($count > 0);

                if ($has_invoices) {
                    // Get max invoice number from custom table
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $max_invoice = $wpdb->get_var("SELECT MAX(invoice_number) FROM {$table_invoices}");
                    if ($max_invoice) {
                        $parsed_max = self::parse_invoice_number($max_invoice);
                        if ($parsed_max) {
                            $max_seq_from_db = $parsed_max['number'];
                        }
                    }
                }
            }

            // Also check postmeta for legacy support (in case custom table is not fully migrated)
            $opt = get_option('cig_last_invoice_seq');
            if ($opt !== false) {
                $max_seq_from_db = max($max_seq_from_db, intval($opt));
                $has_invoices = true;
            } elseif (!$has_invoices) {
                // Fallback: Check postmeta if no invoices in custom table
                // Use a broader pattern that matches both formats with and without prefix
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value REGEXP %s",
                    '_cig_invoice_number', '^[A-Za-z]*[0-9]+$'
                ));
                if ($rows && count($rows) > 0) {
                    $has_invoices = true;
                    foreach ($rows as $val) {
                        $parsed_row = self::parse_invoice_number($val);
                        if ($parsed_row && $parsed_row['number'] > $max_seq_from_db) {
                            $max_seq_from_db = $parsed_row['number'];
                        }
                    }
                }
            }

            // Determine next sequence number
            if (!$has_invoices) {
                // No invoices exist - return the starting number
                $next_seq = $starting_seq;
            } else {
                // Invoices exist - increment from max, but never go below starting number
                $next_seq = max($max_seq_from_db + 1, $starting_seq);
            }

            // Update the last invoice seq option for caching
            $current_opt = intval(get_option('cig_last_invoice_seq', 0));
            if ($next_seq - 1 > $current_opt) {
                update_option('cig_last_invoice_seq', $next_seq - 1, false);
            }

            // Use a consistent padding length of 8 digits (default)
            // This ensures consistent formatting regardless of the starting number
            $pad_length = 8;

            return $starting_prefix . str_pad($next_seq, $pad_length, '0', STR_PAD_LEFT);
        } finally {
            $wpdb->query("SELECT RELEASE_LOCK('cig_invoice_number')");
        }
    }

    /**
     * Parse invoice number into prefix and numeric parts
     * 
     * @param string $invoice_number Invoice number like "N25000088" or "INV000123"
     * @return array|false Array with 'prefix' and 'number' keys, or false if invalid
     */
    public static function parse_invoice_number($invoice_number) {
        if (empty($invoice_number)) {
            return false;
        }
        
        // Match prefix (letters) followed by numbers using class constant
        if (preg_match(self::INVOICE_NUMBER_PATTERN, $invoice_number, $matches)) {
            return [
                'prefix' => strtoupper($matches[1] ?: CIG_INVOICE_NUMBER_PREFIX),
                'number' => intval($matches[2])
            ];
        }
        
        return false;
    }

    /**
     * Check if invoice number exists
     */
    public static function number_exists($invoice_no) {
        $query = new WP_Query(['post_type'=>'invoice', 'post_status'=>'any', 'meta_key'=>'_cig_invoice_number', 'meta_value'=>$invoice_no, 'posts_per_page'=>1, 'fields'=>'ids']);
        return $query->have_posts();
    }

    /**
     * Ensure unique invoice number
     */
    public static function ensure_unique_number($maybe, $skip_id = 0) {
        global $wpdb;

        // Acquire MySQL advisory lock to prevent race conditions
        $lock_acquired = $wpdb->get_var("SELECT GET_LOCK('cig_invoice_number', 10)");
        if ($lock_acquired != 1) {
            error_log('CIG: Could not acquire invoice number lock in ensure_unique_number()');
        }

        try {
            // Validate format using class constant pattern (without anchors for simple validation)
            if (empty($maybe) || !preg_match(self::INVOICE_NUMBER_PATTERN, $maybe)) {
                $maybe = self::get_next_number();
            }
            $maybe = strtoupper($maybe);

            // Parse the invoice number to get prefix and numeric part
            $parsed = self::parse_invoice_number($maybe);
            if (!$parsed) {
                $maybe = self::get_next_number();
                $parsed = self::parse_invoice_number($maybe);
            }

            $prefix = $parsed['prefix'];
            // Use consistent padding of 8 digits
            $pad_length = 8;

            $tries = 0;
            while ($tries < 15) {
                $exists = self::number_exists($maybe);
                if (!$exists || ($skip_id && self::is_same_number($skip_id, $maybe))) {
                    $current_parsed = self::parse_invoice_number($maybe);
                    if ($current_parsed) {
                        $seq = $current_parsed['number'];
                        $current = intval(get_option('cig_last_invoice_seq', 0));
                        if ($seq > $current) update_option('cig_last_invoice_seq', $seq, false);
                    }
                    return $maybe;
                }
                $current_parsed = self::parse_invoice_number($maybe);
                $seq = ($current_parsed ? $current_parsed['number'] : 0) + 1;
                $maybe = $prefix . str_pad($seq, $pad_length, '0', STR_PAD_LEFT);
                $tries++;
            }
            return $maybe;
        } finally {
            $wpdb->query("SELECT RELEASE_LOCK('cig_invoice_number')");
        }
    }

    private static function is_same_number($invoice_id, $invoice_no) {
        $stored = get_post_meta($invoice_id, '_cig_invoice_number', true);
        return strtoupper($stored) === strtoupper($invoice_no);
    }

    /**
     * Save invoice metadata (Unified Payment History System)
     */
    public static function save_meta($post_id, $invoice_number, $buyer, $items, $payment_data = []) {
        // 1. Save Basic Info
        update_post_meta($post_id, '_cig_invoice_number', $invoice_number);
        update_post_meta($post_id, '_cig_buyer_name', sanitize_text_field($buyer['name'] ?? ''));
        update_post_meta($post_id, '_cig_buyer_tax_id', sanitize_text_field($buyer['tax_id'] ?? ''));
        update_post_meta($post_id, '_cig_buyer_address', sanitize_text_field($buyer['address'] ?? ''));
        update_post_meta($post_id, '_cig_buyer_phone', sanitize_text_field($buyer['phone'] ?? ''));
        update_post_meta($post_id, '_cig_buyer_email', sanitize_email($buyer['email'] ?? ''));

        // 2. Save Items
        $clean_items = [];
        $total = 0;
        
        $count_sold = 0;
        $count_reserved = 0;
        $count_active_items = 0;

        foreach ($items as $idx => $row) {
            $item_total = floatval($row['total'] ?? 0);
            
            // Allow 'none' status to persist
            $status = sanitize_text_field($row['status'] ?? 'sold');
            $status = in_array($status, ['sold', 'reserved', 'canceled', 'none'], true) ? $status : 'sold';

            if ($status !== 'canceled' && $status !== 'none') {
                $total += $item_total;
                $count_active_items++;
                if ($status === 'sold') $count_sold++;
                elseif ($status === 'reserved') $count_reserved++;
            }
            // For fictive invoices (none), we usually still sum the total for display
            if ($status === 'none') {
                $total += $item_total;
            }

            $reservation_days = intval($row['reservation_days'] ?? 0);
            if ($status !== 'reserved') $reservation_days = 0;
            else $reservation_days = max(1, min(CIG_MAX_RESERVATION_DAYS, $reservation_days));

            $clean_items[] = [
                'n'                => $idx + 1,
                'product_id'       => intval($row['product_id'] ?? 0),
                'name'             => sanitize_text_field($row['name'] ?? ''),
                'brand'            => sanitize_text_field($row['brand'] ?? ''),
                'sku'              => sanitize_text_field($row['sku'] ?? ''),
                'desc'             => wp_kses_post($row['desc'] ?? ''),
                'image'            => esc_url_raw($row['image'] ?? ''),
                'qty'              => floatval($row['qty'] ?? 0),
                'price'            => floatval($row['price'] ?? 0),
                'total'            => $item_total,
                'status'           => $status,
                'reservation_days' => $reservation_days,
                'warranty'         => sanitize_text_field($row['warranty'] ?? ''),
            ];
        }

        update_post_meta($post_id, '_cig_items', $clean_items);
        update_post_meta($post_id, '_cig_invoice_total', $total);

        // --- Calculate Lifecycle Status ---
        $lifecycle_status = 'unfinished'; 
        if ($count_active_items > 0) {
            if ($count_sold === $count_active_items) $lifecycle_status = 'completed';
            elseif ($count_reserved === $count_active_items) $lifecycle_status = 'reserved';
        }
        update_post_meta($post_id, '_cig_lifecycle_status', $lifecycle_status);

        // 3. Process Payment History
        $history = [];
        $total_paid = 0;
        $unique_methods = [];

        if (isset($payment_data['history']) && is_array($payment_data['history'])) {
            foreach ($payment_data['history'] as $entry) {
                $amount = floatval($entry['amount'] ?? 0);
                if ($amount <= 0) continue;

                $method = sanitize_text_field($entry['method'] ?? 'company_transfer');
                
                $history[] = [
                    'date'    => sanitize_text_field($entry['date'] ?? current_time('Y-m-d')),
                    'amount'  => $amount,
                    'method'  => $method,
                    'comment' => sanitize_text_field($entry['comment'] ?? ''),
                    'user_id' => intval($entry['user_id'] ?? get_current_user_id())
                ];
                
                $total_paid += $amount;
                $unique_methods[] = $method;
            }
        }

        update_post_meta($post_id, '_cig_payment_history', $history);

        // 4. Calculate Derived Fields
        $remaining = max(0, $total - $total_paid);
        $unique_methods = array_unique($unique_methods);
        if (empty($unique_methods)) $main_type = '';
        elseif (count($unique_methods) > 1) $main_type = 'mixed';
        else $main_type = reset($unique_methods);
        
        update_post_meta($post_id, '_cig_payment_type', $main_type);
        $is_partial = ($total_paid > 0.01 && $remaining > 0.01) ? 'yes' : 'no';
        update_post_meta($post_id, '_cig_payment_is_partial', $is_partial);
        update_post_meta($post_id, '_cig_payment_paid_amount', $total_paid);
        update_post_meta($post_id, '_cig_payment_remaining_amount', $remaining);

        // Cleanup legacy
        delete_post_meta($post_id, '_cig_payment_company');
        delete_post_meta($post_id, '_cig_payment_cash');
        delete_post_meta($post_id, '_cig_payment_comment');
    }

    public static function get_payment_types() {
        return [
            'company_transfer' => __('Company Transfer', 'cig'),
            'cash'             => __('Cash (Personal Transfer)', 'cig'),
            'mixed'            => __('Mixed (Company + Cash)', 'cig'),
            'consignment'      => __('Consignment', 'cig'),
            'credit'           => __('Credit Installment', 'cig'),
        ];
    }
}