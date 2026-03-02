<?php
/**
 * Mini Dashboard Partial
 *
 * @package CIG
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

$current_user = isset($current_user) ? $current_user : wp_get_current_user();
if (!$current_user->ID) {
    return;
}

// Get user avatar
$avatar_url = get_avatar_url($current_user->ID, ['size' => 32]);

// Get shortcode URLs
$invoice_page_url = home_url('/invoice-shortcode/');
$products_page_url = home_url('/stock-table/');

// Determine current page
global $post;
$is_invoice_page = false;
$is_products_page = false;

if (is_page() && $post) {
    if (has_shortcode($post->post_content, 'invoice_generator')) {
        $is_invoice_page = true;
    }
    if (has_shortcode($post->post_content, 'products_stock_table')) {
        $is_products_page = true;
    }
}

if (is_singular('invoice')) {
    $is_invoice_page = true;
}
?>

<div class="cig-mini-dashboard no-print" id="cig-mini-dashboard">
    <div class="cig-mini-dash-container">
        
        <div class="cig-mini-dash-left">
            <div class="cig-mini-user-info">
                <img src="<?php echo esc_url($avatar_url); ?>" alt="" class="cig-mini-avatar">
                <span class="cig-mini-username"><?php echo esc_html($current_user->display_name); ?></span>
            </div>
            
            <div class="cig-mini-stats-compact">
                <div class="cig-mini-stat-item" id="cig-mini-stat-invoices">
                    <span class="cig-mini-stat-label"><?php esc_html_e('Invoices:', 'cig'); ?></span>
                    <span class="cig-mini-stat-value"><span class="cig-loading-mini">...</span></span>
                </div>
                
                <div class="cig-mini-stat-item" id="cig-mini-stat-last">
                    <span class="cig-mini-stat-label"><?php esc_html_e('Last:', 'cig'); ?></span>
                    <span class="cig-mini-stat-value"><span class="cig-loading-mini">...</span></span>
                </div>
                
                <div class="cig-mini-stat-item cig-expiring-alert" id="cig-mini-stat-reserved">
                    <span class="cig-mini-stat-label"><?php esc_html_e('Reserved:', 'cig'); ?></span>
                    <span class="cig-mini-stat-value"><span class="cig-loading-mini">...</span></span>
                    <div class="cig-expiring-badge" id="cig-expiring-badge" style="display:none;">
                        <span class="cig-expiring-count">0</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="cig-mini-dash-right">
            <button type="button" class="cig-mini-nav-btn cig-mini-btn-invoices" id="cig-mini-btn-invoices">
                <span class="dashicons dashicons-list-view"></span>
                <span><?php esc_html_e('My Invoices', 'cig'); ?></span>
            </button>

            <?php if ($is_products_page): ?>
                <a href="<?php echo esc_url($invoice_page_url); ?>" class="cig-mini-nav-btn cig-mini-btn-primary">
                    <span class="dashicons dashicons-edit-page"></span>
                    <span><?php esc_html_e('Invoice Generator', 'cig'); ?></span>
                </a>
            <?php else: ?>
                <a href="<?php echo esc_url($products_page_url); ?>" class="cig-mini-nav-btn" target="_blank" rel="noopener">
                    <span class="dashicons dashicons-search"></span>
                    <span><?php esc_html_e('All Products', 'cig'); ?></span>
                </a>

                <a href="<?php echo esc_url($invoice_page_url); ?>" class="cig-mini-nav-btn cig-mini-btn-primary">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <span><?php esc_html_e('Add New Invoice', 'cig'); ?></span>
                </a>
            <?php endif; ?>
        </div>

    </div>

    <div class="cig-mini-dropdown" id="cig-mini-invoices-dropdown" style="display:none;">
        <div class="cig-mini-dropdown-header">
            <h3><?php esc_html_e('My Invoices', 'cig'); ?></h3>
            <button type="button" class="cig-mini-close-dropdown">✕</button>
        </div>

        <div class="cig-mini-dropdown-filters compact-mode">
            <div class="cig-mini-quick-filters">
                <button type="button" class="cig-mini-filter-btn active" data-filter="all"><?php esc_html_e('All', 'cig'); ?></button>
                <button type="button" class="cig-mini-filter-btn" data-filter="today"><?php esc_html_e('Today', 'cig'); ?></button>
                <button type="button" class="cig-mini-filter-btn" data-filter="this_week"><?php esc_html_e('Week', 'cig'); ?></button>
                <button type="button" class="cig-mini-filter-btn" data-filter="this_month"><?php esc_html_e('Month', 'cig'); ?></button>
            </div>
            
            <div class="cig-mini-inputs-group">
                <select id="cig-mini-status-filter" class="cig-mini-select-filter">
                    <option value="standard" selected="selected"><?php esc_html_e('Active', 'cig'); ?></option>
                    <option value="reserved"><?php esc_html_e('Reserved', 'cig'); ?></option>
                    <option value="fictive"><?php esc_html_e('Fictive', 'cig'); ?></option>
                    <option value="all"><?php esc_html_e('All Status', 'cig'); ?></option>
                </select>

                <input type="text" id="cig-mini-invoice-search" class="cig-mini-search-input" placeholder="<?php esc_attr_e('Search...', 'cig'); ?>">
            </div>
        </div>

        <div class="cig-mini-dropdown-content">
            <table class="cig-mini-invoices-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Invoice #', 'cig'); ?></th>
                        <th><?php esc_html_e('Type', 'cig'); ?></th> <th><?php esc_html_e('Date', 'cig'); ?></th>
                        <th><?php esc_html_e('Total', 'cig'); ?></th>
                        <th><?php esc_html_e('Payment', 'cig'); ?></th>
                        <th><?php esc_html_e('Status', 'cig'); ?></th>
                        <th><?php esc_html_e('Actions', 'cig'); ?></th>
                    </tr>
                </thead>
                <tbody id="cig-mini-invoices-tbody">
                    <tr class="cig-mini-loading-row">
                        <td colspan="7">
                            <div class="cig-mini-loading">
                                <div class="cig-mini-spinner"></div>
                                <p><?php esc_html_e('Loading...', 'cig'); ?></p>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if (current_user_can('edit_posts')): ?>
        <div class="cig-mini-dropdown-footer">
            <a href="<?php echo admin_url('edit.php?post_type=invoice&page=invoice-statistics'); ?>" class="cig-mini-link-full">
                <?php esc_html_e('View Full Statistics →', 'cig'); ?>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <div class="cig-mini-dropdown cig-expiring-dropdown" id="cig-expiring-dropdown" style="display:none;">
        <div class="cig-mini-dropdown-header">
            <h3><?php esc_html_e('Expiring Reservations', 'cig'); ?></h3>
            <button type="button" class="cig-mini-close-dropdown">✕</button>
        </div>

        <div class="cig-mini-dropdown-content">
            <div id="cig-expiring-list">
                <div class="cig-mini-loading">
                    <div class="cig-mini-spinner"></div>
                    <p><?php esc_html_e('Loading...', 'cig'); ?></p>
                </div>
            </div>
        </div>
    </div>

</div>