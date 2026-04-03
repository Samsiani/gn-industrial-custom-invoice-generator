<?php
/**
 * Plugin Name: Custom WooCommerce Invoice Generator
 * Plugin URI: https://example.com/invoice-generator
 * Description: Professional invoice generator with advanced stock reservation, real-time validation, and comprehensive analytics.
 * Version: 4.9.13
 * Author: Samsiani
 * Author URI: https://example.com
 * Text Domain: cig
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package CIG
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prevent direct access
if (!function_exists('add_action')) {
    header('Status: 403 Forbidden', true, 403);
    exit;
}

// --- HPOS COMPATIBILITY DECLARATION (NEW) ---
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Plugin constants
 */
define('CIG_VERSION', '4.9.13');
define('CIG_PLUGIN_FILE', __FILE__);
define('CIG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CIG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CIG_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('CIG_INCLUDES_DIR', CIG_PLUGIN_DIR . 'includes/');
define('CIG_TEMPLATES_DIR', CIG_PLUGIN_DIR . 'templates/');
define('CIG_ASSETS_URL', CIG_PLUGIN_URL . 'assets/');

// Performance & cache constants
define('CIG_CACHE_GROUP', 'cig_cache');
define('CIG_CACHE_EXPIRY', 900); // 15 minutes
define('CIG_STOCK_CHECK_INTERVAL', 3600); // 1 hour
define('CIG_MAX_RESERVATION_DAYS', 90);
define('CIG_DEFAULT_RESERVATION_DAYS', 30);
define('CIG_PRODUCTS_PER_PAGE', 50);
define('CIG_INVOICE_NUMBER_PREFIX', 'N');
define('CIG_INVOICE_NUMBER_BASE', 25000000);

/**
 * Main plugin class (Singleton)
 */
final class CIG_Invoice_Generator {

    private static $instance = null;

    // Utilities
    public $logger;
    public $cache;
    public $validator;
    public $security;

    // Components
    public $core;
    public $invoice;
    public $stock;
    public $settings;
    public $admin_columns;
    public $statistics;
    public $stock_requests;
    public $accountant;
    public $customers;
    
    // New Component
    public $user_restrictions;
    
    // Admin Portal
    public $admin_portal;
    
    // Services (4.0.0)
    public $invoice_service;
    
    // Invoice Manager (4.0.0)
    public $invoice_manager;
    
    // Migrator (4.0.0)
    public $migrator;

    /**
     * Get singleton instance
     *
     * @return CIG_Invoice_Generator
     */
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        if (!$this->check_requirements()) {
            return;
        }

        $this->load_dependencies();
        $this->init_hooks();
        $this->init_components();
    }

    /**
     * Verify minimal requirements
     *
     * @return bool
     */
    private function check_requirements() {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__('Custom WooCommerce Invoice Generator requires PHP 7.4 or higher.', 'cig');
                echo '</p></div>';
            });
            return false;
        }
        return true;
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        // Utilities
        require_once CIG_INCLUDES_DIR . 'class-cig-logger.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-cache.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-validator.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-security.php';

        // Core classes
        require_once CIG_INCLUDES_DIR . 'class-cig-core.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-invoice.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-stock-manager.php';
        
        // Database & Services (4.0.0)
        require_once CIG_INCLUDES_DIR . 'class-cig-db-installer.php';
        require_once CIG_INCLUDES_DIR . 'database/class-cig-database.php';
        require_once CIG_INCLUDES_DIR . 'dto/class-cig-invoice-item-dto.php';
        require_once CIG_INCLUDES_DIR . 'services/class-cig-invoice-service.php';
        require_once CIG_INCLUDES_DIR . 'migration/class-cig-migrator.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-invoice-manager.php';
        
        // AJAX Handlers
        require_once CIG_INCLUDES_DIR . 'ajax/class-cig-ajax-invoices.php';
        require_once CIG_INCLUDES_DIR . 'ajax/class-cig-ajax-products.php';
        require_once CIG_INCLUDES_DIR . 'ajax/class-cig-ajax-statistics.php';
        require_once CIG_INCLUDES_DIR . 'ajax/class-cig-ajax-customers.php';
        require_once CIG_INCLUDES_DIR . 'ajax/class-cig-ajax-dashboard.php';

        require_once CIG_INCLUDES_DIR . 'class-cig-settings.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-admin-columns.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-statistics.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-stock-requests.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-accountant.php';
        require_once CIG_INCLUDES_DIR . 'class-cig-customers.php';
        
        // Load User Restrictions
        require_once CIG_INCLUDES_DIR . 'class-cig-user-restrictions.php';
        
        // Load Admin Portal
        require_once CIG_INCLUDES_DIR . 'class-cig-admin-portal.php';

        // GitHub Auto-Updater
        require_once CIG_INCLUDES_DIR . 'class-cig-updater.php';
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook(CIG_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(CIG_PLUGIN_FILE, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'on_plugins_loaded'], 10);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }

    /**
     * Enqueue frontend assets
     *
     * Ensures Dashicons are available on the frontend for non-admin users
     * who don't have the Admin Bar (which normally loads dashicons).
     */
    public function enqueue_frontend_assets() {
        wp_enqueue_style('dashicons');
    }

    /**
     * Initialize components with explicit dependency injection
     */
    private function init_components() {
        // Utilities
        $this->logger    = new CIG_Logger();
        $this->cache     = new CIG_Cache();
        $this->validator = new CIG_Validator();
        $this->security  = new CIG_Security();

        // Core components
        $this->core      = new CIG_Core($this->logger, $this->cache);
        $this->stock     = new CIG_Stock_Manager($this->logger, $this->cache, $this->validator);
        $this->invoice   = new CIG_Invoice($this->stock, $this->validator, $this->logger);
        
        // Services (4.0.0)
        $this->invoice_service = new CIG_Invoice_Service();
        
        // Invoice Manager (4.0.0)
        $this->invoice_manager = CIG_Invoice_Manager::instance();
        
        // Initialize AJAX Handlers
        new CIG_Ajax_Invoices($this->invoice, $this->stock, $this->validator, $this->security, $this->cache);
        new CIG_Ajax_Products($this->stock, $this->security);
        new CIG_Ajax_Statistics($this->security);
        new CIG_Ajax_Customers($this->security);
        new CIG_Ajax_Dashboard($this->security, $this->stock);

        // Other components
        $this->settings       = new CIG_Settings($this->cache);
        $this->admin_columns  = new CIG_Admin_Columns($this->stock);
        $this->statistics     = new CIG_Statistics($this->cache);
        $this->stock_requests = new CIG_Stock_Requests();
        $this->accountant     = new CIG_Accountant();
        $this->customers      = new CIG_Customers();
        
        // Init User Restrictions
        $this->user_restrictions = new CIG_User_Restrictions();
        
        // Init Admin Portal with database dependency
        global $wpdb;
        $this->admin_portal = new CIG_Admin_Portal( $wpdb );
        
        // Init Migrator (4.0.0) - handles admin notices for migration
        $this->migrator = new CIG_Migrator();

        // GitHub auto-updater
        new CIG_Updater( CIG_PLUGIN_FILE, 'Samsiani', 'gn-industrial-custom-invoice-generator', CIG_VERSION );
    }

    /**
     * Activation routine
     */
    public function activate() {
        if (!wp_next_scheduled('cig_check_expired_reservations')) {
            wp_schedule_event(time(), 'hourly', 'cig_check_expired_reservations');
        }
        
        // Create custom tables using DB Installer (2.0.0)
        CIG_DB_Installer::install();
        
        // Run legacy database creation only if tables don't exist (backward compatibility)
        if (!CIG_Database::tables_exist()) {
            CIG_Database::create_tables();
        }
        
        // Run data migration from v1 (postmeta) to v2 (custom tables)
        // Use existing migrator instance if available, otherwise create new one
        $migrator = isset($this->migrator) ? $this->migrator : new CIG_Migrator();
        $migrator->migrate_v1_to_v2();
        
        update_option('cig_version', CIG_VERSION, false);
        flush_rewrite_rules();
    }

    /**
     * Deactivation routine
     */
    public function deactivate() {
        $timestamp = wp_next_scheduled('cig_check_expired_reservations');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'cig_check_expired_reservations');
        }
        flush_rewrite_rules();
    }

    /**
     * After plugins are loaded
     */
    public function on_plugins_loaded() {
        load_plugin_textdomain('cig', false, dirname(CIG_PLUGIN_BASENAME) . '/languages');

        // Auto-upgrade DB schema when plugin version changes (no reactivation needed)
        $stored_version = get_option('cig_version', '');
        if (version_compare($stored_version, CIG_VERSION, '<')) {
            CIG_DB_Installer::install();
            update_option('cig_version', CIG_VERSION, false);
        }

        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>';
                echo esc_html__('Custom WooCommerce Invoice Generator', 'cig');
                echo '</strong> ';
                echo esc_html__('requires WooCommerce to be installed and active.', 'cig');
                echo '</p></div>';
            });
        }
    }

    private function __clone() {}
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}

/**
 * Global accessor
 *
 * @return CIG_Invoice_Generator
 */
function CIG() {
    return CIG_Invoice_Generator::instance();
}

// Bootstrap
CIG();