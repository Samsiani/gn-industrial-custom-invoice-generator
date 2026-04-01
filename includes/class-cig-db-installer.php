<?php
/**
 * Database Installer Class
 * Handles creation of custom tables for the CIG plugin using dbDelta
 *
 * @package CIG
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG_DB_Installer Class
 * 
 * Creates and manages custom SQL tables for the Custom WooCommerce Invoice Generator plugin.
 * This replaces the previous wp_postmeta storage approach for better performance.
 */
class CIG_DB_Installer {

    /**
     * Install method - Creates all required custom tables
     * Runs on plugin activation using WordPress dbDelta function
     *
     * @return void
     */
    public static function install() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Create Customers Table
        self::create_customers_table($charset_collate);

        // Create Invoices Table
        self::create_invoices_table($charset_collate);

        // Create Invoice Items Table
        self::create_invoice_items_table($charset_collate);

        // Create Payments Table
        self::create_payments_table($charset_collate);

        // Run upgrade routines for existing installations
        self::maybe_upgrade();

        // Update database version
        update_option('cig_db_version', '2.1.0', false);
    }

    /**
     * Create customers table
     * Stores client/customer information
     *
     * @param string $charset_collate Database charset collation
     * @return void
     */
    private static function create_customers_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cig_customers';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            tax_id varchar(50) DEFAULT '',
            name varchar(255) NOT NULL DEFAULT '',
            phone varchar(50) DEFAULT '',
            email varchar(100) DEFAULT '',
            address text,
            PRIMARY KEY  (id),
            KEY tax_id (tax_id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Create invoices table (Master Table)
     * Stores invoice header information with critical sale_date field for reporting
     *
     * @param string $charset_collate Database charset collation
     * @return void
     */
    private static function create_invoices_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cig_invoices';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            invoice_number varchar(50) NOT NULL,
            customer_id bigint(20) DEFAULT 0,
            status varchar(20) DEFAULT 'fictive',
            lifecycle_status varchar(20) DEFAULT 'unfinished',
            total_amount decimal(10,2) DEFAULT 0.00,
            paid_amount decimal(10,2) DEFAULT 0.00,
            created_at datetime DEFAULT NULL,
            sale_date datetime DEFAULT NULL,
            sold_date date DEFAULT NULL,
            author_id bigint(20) DEFAULT 0,
            general_note text,
            is_rs_uploaded tinyint(1) DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY invoice_number (invoice_number),
            KEY status (status),
            KEY lifecycle_status (lifecycle_status),
            KEY sale_date (sale_date),
            KEY sold_date (sold_date),
            KEY customer_id (customer_id),
            KEY author_id (author_id)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Create invoice items table
     * Stores individual line items for each invoice
     *
     * @param string $charset_collate Database charset collation
     * @return void
     */
    private static function create_invoice_items_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cig_invoice_items';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            invoice_id bigint(20) NOT NULL DEFAULT 0,
            product_id bigint(20) DEFAULT 0,
            product_name varchar(255) DEFAULT '',
            sku varchar(100) DEFAULT '',
            description text,
            quantity decimal(10,2) DEFAULT 0.00,
            price decimal(10,2) DEFAULT 0.00,
            original_price decimal(10,2) DEFAULT 0.00,
            total decimal(10,2) DEFAULT 0.00,
            item_status varchar(20) DEFAULT 'none',
            warranty_duration varchar(50) DEFAULT '',
            reservation_days int(11) DEFAULT 0,
            image varchar(500) DEFAULT '',
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id),
            KEY product_id (product_id),
            KEY item_status (item_status)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Create payments table
     * Stores payment history with separate dates for cash flow tracking
     *
     * @param string $charset_collate Database charset collation
     * @return void
     */
    private static function create_payments_table($charset_collate) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cig_payments';

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            invoice_id bigint(20) NOT NULL DEFAULT 0,
            amount decimal(10,2) DEFAULT 0.00,
            date datetime DEFAULT NULL,
            method varchar(50) DEFAULT 'other',
            user_id bigint(20) DEFAULT 0,
            comment text,
            PRIMARY KEY  (id),
            KEY invoice_id (invoice_id),
            KEY date (date),
            KEY method (method)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Run upgrade routines for schema changes
     */
    private static function maybe_upgrade() {
        global $wpdb;
        $table_items = $wpdb->prefix . 'cig_invoice_items';

        // Add original_price column if it doesn't exist
        $col = $wpdb->get_results("SHOW COLUMNS FROM `{$table_items}` LIKE 'original_price'");
        if (empty($col)) {
            $wpdb->query("ALTER TABLE `{$table_items}` ADD COLUMN `original_price` decimal(10,2) DEFAULT 0.00 AFTER `price`");
        }
    }

    /**
     * Check if all required tables exist
     *
     * @return bool True if all tables exist, false otherwise
     */
    public static function tables_exist() {
        global $wpdb;

        $required_tables = [
            $wpdb->prefix . 'cig_customers',
            $wpdb->prefix . 'cig_invoices',
            $wpdb->prefix . 'cig_invoice_items',
            $wpdb->prefix . 'cig_payments'
        ];

        foreach ($required_tables as $table) {
            $result = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            ));

            if ($result !== $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get current database version
     *
     * @return string Database version or empty string if not set
     */
    public static function get_db_version() {
        return get_option('cig_db_version', '');
    }

    /**
     * Check if database needs upgrade
     *
     * @return bool True if upgrade needed
     */
    public static function needs_upgrade() {
        $current_version = self::get_db_version();
        return version_compare($current_version, '2.1.0', '<');
    }

    /**
     * Drop all custom tables (for uninstall)
     * Tables are dropped in reverse order to respect foreign key constraints
     *
     * @warning This method permanently deletes all plugin data and cannot be undone.
     *          Only call this during complete plugin uninstallation.
     *
     * @return void
     */
    public static function uninstall() {
        global $wpdb;

        // Drop tables in reverse order to handle dependencies
        $tables = [
            $wpdb->prefix . 'cig_payments',
            $wpdb->prefix . 'cig_invoice_items',
            $wpdb->prefix . 'cig_invoices',
            $wpdb->prefix . 'cig_customers'
        ];

        foreach ($tables as $table) {
            // Table names are constructed from $wpdb->prefix which is safe
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        delete_option('cig_db_version');
    }
}
