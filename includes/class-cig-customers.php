<?php
/**
 * Customer Management Handler
 *
 * @package CIG
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Customers {

    private $post_type = 'cig_customer';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        
        // AJAX Search for Autocomplete
        add_action('wp_ajax_cig_search_customers', [$this, 'ajax_search_customers']);
        // AJAX exact lookup by tax_id (drives the form's auto-fill + lock)
        add_action('wp_ajax_cig_lookup_by_tax', [$this, 'ajax_lookup_by_tax']);
    }

    /**
     * Exact-match lookup of a customer by tax_id against the authoritative
     * wp_cig_customers table. Used by the invoice form: when a consultant enters
     * an existing ს/კ ან პ/ნ, the buyer name/phone/address auto-fill and lock —
     * an existing identity can't be renamed without changing the ID.
     */
    public function ajax_lookup_by_tax() {
        check_ajax_referer( 'cig_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }
        $tax_id = sanitize_text_field( wp_unslash( $_POST['tax_id'] ?? '' ) );
        if ( $tax_id === '' ) {
            wp_send_json_success( [ 'found' => false ] );
        }
        global $wpdb;
        $table = $wpdb->prefix . 'cig_customers';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT name, phone, email, address FROM {$table} WHERE tax_id = %s LIMIT 1",
            $tax_id
        ), ARRAY_A );
        if ( ! $row ) {
            wp_send_json_success( [ 'found' => false ] );
        }
        wp_send_json_success( [
            'found'   => true,
            'name'    => $row['name'],
            'phone'   => $row['phone'],
            'email'   => $row['email'],
            'address' => $row['address'],
        ] );
    }

    /**
     * Register Customer Post Type
     */
    public function register_post_type() {
        register_post_type($this->post_type, [
            'labels' => [
                'name'               => __('Customers', 'cig'),
                'singular_name'      => __('Customer', 'cig'),
                'menu_name'          => __('Customers (CIG)', 'cig'),
                'add_new_item'       => __('Add New Customer', 'cig'),
                'edit_item'          => __('Edit Customer', 'cig'),
                'search_items'       => __('Search Customers', 'cig'),
                'not_found'          => __('No customers found', 'cig'),
            ],
            'public'             => false,
            'show_ui'            => true, // Show in Admin Menu
            'show_in_menu'       => 'edit.php?post_type=invoice', // Submenu of Invoices
            'supports'           => ['title'], // Title = Buyer Name
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'menu_icon'          => 'dashicons-businessperson',
        ]);
    }

    /**
     * Create or Update Customer from Invoice Data
     * Syncs to both WordPress post type (legacy) and custom table (wp_cig_customers)
     *
     * @param array $buyer_data [name, tax_id, address, phone, email]
     * @return int|false Customer ID from custom table or false on failure
     */
    public function sync_customer($buyer_data) {
        global $wpdb;

        $tax_id  = sanitize_text_field($buyer_data['tax_id'] ?? '');
        $name    = sanitize_text_field($buyer_data['name'] ?? '');
        $phone   = sanitize_text_field($buyer_data['phone'] ?? '');
        $email   = sanitize_email($buyer_data['email'] ?? '');
        $address = sanitize_text_field($buyer_data['address'] ?? '');

        // Validation: Tax ID and Name are mandatory
        if (empty($tax_id) || empty($name)) {
            return false;
        }

        // 1. Sync to Legacy Post Type (cig_customer)
        // Identity is keyed by tax_id and is IMMUTABLE once created: a later
        // invoice with the same tax_id must NOT rename or overwrite the existing
        // customer. We only create the record the first time a tax_id is seen.
        $existing_post_id = $this->get_customer_id_by_tax_id($tax_id);

        if ($existing_post_id) {
            $customer_post_id = $existing_post_id;
        } else {
            $customer_post_id = wp_insert_post([
                'post_type'   => $this->post_type,
                'post_title'  => $name,
                'post_status' => 'publish',
            ]);
            if (!is_wp_error($customer_post_id) && $customer_post_id) {
                update_post_meta($customer_post_id, '_cig_customer_tax_id', $tax_id);
                update_post_meta($customer_post_id, '_cig_customer_address', $address);
                update_post_meta($customer_post_id, '_cig_customer_phone', $phone);
                update_post_meta($customer_post_id, '_cig_customer_email', $email);
            }
        }

        // 2. Sync to Custom Table (wp_cig_customers) - PRIMARY storage for statistics
        $table_customers = $wpdb->prefix . 'cig_customers';
        
        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_customers));
        if ($table_exists !== $table_customers) {
            // Table doesn't exist, return post ID as fallback
            return $customer_post_id ?: false;
        }

        // Check if customer exists in custom table by tax_id
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_custom_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_customers} WHERE tax_id = %s LIMIT 1",
                $tax_id
            )
        );

        if ($existing_custom_id) {
            // Existing customer for this tax_id — IDENTITY IS IMMUTABLE here.
            // Do NOT overwrite name/phone/email/address from this invoice; the
            // stored customer record is authoritative (a different real buyer
            // must be entered under a different tax_id). This is what prevents
            // the shared-placeholder corruption (e.g. every "000" sale rewriting
            // one customer's name). The invoice keeps its own buyer snapshot.
            return intval($existing_custom_id);
        } else {
            // Insert new customer into custom table
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $result = $wpdb->insert(
                $table_customers,
                [
                    'tax_id'  => $tax_id,
                    'name'    => $name,
                    'phone'   => $phone,
                    'email'   => $email,
                    'address' => $address,
                ],
                ['%s', '%s', '%s', '%s', '%s']
            );
            
            // Check if insert was successful
            if (false === $result) {
                return $customer_post_id ?: false; // Fallback to post ID on failure
            }
            return $wpdb->insert_id ?: false;
        }
    }

    /**
     * Find customer ID by Tax ID
     */
    public function get_customer_id_by_tax_id($tax_id) {
        $query = new WP_Query([
            'post_type'      => $this->post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'     => '_cig_customer_tax_id',
                    'value'   => $tax_id,
                    'compare' => '='
                ]
            ],
            'fields' => 'ids'
        ]);

        return $query->have_posts() ? $query->posts[0] : false;
    }

    /**
     * AJAX: Search Customers (Name or Tax ID)
     */
    public function ajax_search_customers() {
        check_ajax_referer('cig_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
        if (strlen($term) < 2) wp_send_json_success([]);

        // Search by Title (Name) OR Meta (Tax ID)
        $args = [
            'post_type'      => $this->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            's'              => $term // Standard title/content search
        ];

        // Also search in Tax ID via meta query if 's' doesn't catch it well, 
        // OR better: use custom SQL filter for "Title OR TaxID" like we did for products.
        // For simplicity & performance, let's try a direct approach:
        
        add_filter('posts_where', [$this, 'filter_search_where']);
        $query = new WP_Query($args);
        remove_filter('posts_where', [$this, 'filter_search_where']);

        $results = [];
        foreach ($query->posts as $post) {
            $id = $post->ID;
            $tax_id = get_post_meta($id, '_cig_customer_tax_id', true);
            $phone  = get_post_meta($id, '_cig_customer_phone', true);
            
            $results[] = [
                'id'      => $id,
                'label'   => $post->post_title . ' (ს/კ: ' . $tax_id . ')',
                'value'   => $post->post_title, // Input gets the name
                'tax_id'  => $tax_id,
                'address' => get_post_meta($id, '_cig_customer_address', true),
                'phone'   => $phone,
                'email'   => get_post_meta($id, '_cig_customer_email', true),
            ];
        }

        wp_send_json($results);
    }

    /**
     * Custom SQL filter to search in Tax ID too
     */
    public function filter_search_where($where) {
        global $wpdb;
        // This relies on the 's' parameter being present in the query
        if (strpos($where, 'post_title LIKE') !== false) {
            $search_term = $_POST['term'] ?? ''; // Direct access as context is known
            if ($search_term) {
                $like = '%' . $wpdb->esc_like($search_term) . '%';
                // Extend WHERE to include meta_value for tax_id
                $where .= $wpdb->prepare(" OR (
                    EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} 
                        WHERE post_id = {$wpdb->posts}.ID 
                        AND meta_key = '_cig_customer_tax_id' 
                        AND meta_value LIKE %s
                    )
                )", $like);
            }
        }
        return $where;
    }
}