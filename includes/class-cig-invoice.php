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
    // ── Shared cross-plugin invoice numbering ────────────────────────────────
    // The OLD plugin (prefix "N") and the NEW plugin gn-crm-vue (prefix "GN")
    // share one WordPress DB. Each used to number from its own storage only, so
    // both reached the same numeric part (e.g. N350009908 and GN350009908).
    // These helpers make the NUMERIC part globally unique across BOTH plugins by
    // drawing every new number from one shared sequence under a shared MySQL
    // named lock. The NEW plugin implements the identical logic — keep the lock
    // name + option key below in lock-step with
    // gn-crm-vue/models/class-cig-invoice.php there.
    const GN_SEQ_LOCK   = 'gn_invoice_number';
    const GN_SEQ_OPTION = 'gn_shared_invoice_seq';

    /** Highest numeric part already used in EITHER plugin (prefix-agnostic). */
    private static function gn_global_max_seq() {
        global $wpdb;
        $vals   = [];
        $vals[] = (int) $wpdb->get_var(
            "SELECT MAX(CAST(REGEXP_REPLACE(meta_value, '^[^0-9]+', '') AS UNSIGNED))
             FROM {$wpdb->postmeta} WHERE meta_key = '_cig_invoice_number'"
        );
        $cig_t = $wpdb->prefix . 'cig_invoices';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cig_t)) === $cig_t) {
            $vals[] = (int) $wpdb->get_var(
                "SELECT MAX(CAST(REGEXP_REPLACE(invoice_number, '^[^0-9]+', '') AS UNSIGNED)) FROM {$cig_t}"
            );
        }
        $gncrm_t = $wpdb->prefix . 'gncrm_invoices';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $gncrm_t)) === $gncrm_t) {
            $vals[] = (int) $wpdb->get_var(
                "SELECT MAX(CAST(REGEXP_REPLACE(invoice_number, '^[^0-9]+', '') AS UNSIGNED)) FROM {$gncrm_t}"
            );
        }
        return max($vals ?: [0]);
    }

    /** Is this numeric part already taken by ANY invoice in EITHER plugin? */
    private static function gn_seq_taken($n) {
        global $wpdb;
        $n = (int) $n;
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key='_cig_invoice_number' AND CAST(REGEXP_REPLACE(meta_value,'^[^0-9]+','') AS UNSIGNED) = %d LIMIT 1", $n
        ))) return true;
        $cig_t = $wpdb->prefix . 'cig_invoices';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $cig_t)) === $cig_t) {
            if ($wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$cig_t} WHERE CAST(REGEXP_REPLACE(invoice_number,'^[^0-9]+','') AS UNSIGNED) = %d LIMIT 1", $n
            ))) return true;
        }
        $gncrm_t = $wpdb->prefix . 'gncrm_invoices';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $gncrm_t)) === $gncrm_t) {
            if ($wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$gncrm_t} WHERE CAST(REGEXP_REPLACE(invoice_number,'^[^0-9]+','') AS UNSIGNED) = %d LIMIT 1", $n
            ))) return true;
        }
        return false;
    }

    /** Reserve + return the next globally-unique numeric sequence (int). */
    private static function gn_reserve_seq($starting) {
        global $wpdb;
        $wpdb->get_var("SELECT GET_LOCK('" . self::GN_SEQ_LOCK . "', 10)");
        try {
            $counter = (int) get_option(self::GN_SEQ_OPTION, 0);
            $next    = max($counter, self::gn_global_max_seq(), (int) $starting - 1) + 1;
            $guard   = 0;
            while ($guard++ < 100 && self::gn_seq_taken($next)) { $next++; }
            update_option(self::GN_SEQ_OPTION, $next, false);
            update_option('cig_last_invoice_seq', $next, false); // keep legacy cache in sync
            return $next;
        } finally {
            $wpdb->query("SELECT RELEASE_LOCK('" . self::GN_SEQ_LOCK . "')");
        }
    }

    private static function gn_prefix_starting() {
        $settings = get_option('cig_settings', []);
        $prefix   = CIG_INVOICE_NUMBER_PREFIX;
        $starting = CIG_INVOICE_NUMBER_BASE;
        if (!empty($settings['starting_invoice_number'])) {
            $parsed = self::parse_invoice_number($settings['starting_invoice_number']);
            if ($parsed) { $prefix = $parsed['prefix']; $starting = $parsed['number']; }
        }
        return [$prefix, $starting];
    }

    /**
     * Preview the next number WITHOUT reserving it (form preview). Cross-plugin
     * aware so it reflects numbers created in the new plugin too.
     */
    public static function get_next_number() {
        list($prefix, $starting) = self::gn_prefix_starting();
        $counter = (int) get_option(self::GN_SEQ_OPTION, 0);
        $next    = max($counter, self::gn_global_max_seq(), (int) $starting - 1) + 1;
        return $prefix . str_pad($next, 8, '0', STR_PAD_LEFT);
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
        $maybe = strtoupper((string) $maybe);

        // Editing an existing invoice and keeping its own number: preserve it
        // exactly (never renumber an existing invoice).
        if ($skip_id && self::is_same_number($skip_id, $maybe)) {
            return $maybe;
        }

        // New invoice (or a number change): RESERVE a fresh number whose numeric
        // part is globally unique across BOTH plugins. Keep the prefix of the
        // requested number when valid, else the configured starting prefix.
        list($default_prefix, $starting) = self::gn_prefix_starting();
        $parsed = self::parse_invoice_number($maybe);
        $prefix = ($parsed && $parsed['prefix']) ? $parsed['prefix'] : $default_prefix;

        $seq = self::gn_reserve_seq($starting);
        return $prefix . str_pad($seq, 8, '0', STR_PAD_LEFT);
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
                'original_price'   => floatval($row['original_price'] ?? $row['price'] ?? 0),
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