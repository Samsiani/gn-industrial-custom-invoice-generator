<?php
/**
 * Statistics Dashboard Template
 * Updated: Added 'External Balance' Tab & Deposit Modal
 *
 * @package CIG
 * @since 4.9.2
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap cig-statistics-wrap">
    <h1 class="cig-stats-main-title">
        <?php esc_html_e('Invoice Statistics', 'cig'); ?>
        <span>
            <button type="button" id="cig-refresh-stats" class="button button-secondary" title="<?php esc_attr_e('Refresh statistics', 'cig'); ?>">
                <span class="dashicons dashicons-update"></span> <?php esc_html_e('Refresh', 'cig'); ?>
            </button>
            <span class="auto-refresh-indicator">
                <span class="dashicons dashicons-update"></span>
                <span><?php esc_html_e('Auto refresh every 5 min', 'cig'); ?></span>
            </span>
        </span>
    </h1>

    <h2 class="nav-tab-wrapper cig-stats-tabs">
        <a href="#tab-overview" class="nav-tab nav-tab-active" data-tab="overview"><span class="dashicons dashicons-chart-area"></span> <?php esc_html_e('General Overview', 'cig'); ?></a>
        <a href="#tab-fictive" class="nav-tab" data-tab="fictive"><span class="dashicons dashicons-hidden"></span> <?php esc_html_e('Fictive Invoices', 'cig'); ?></a>
        <a href="#tab-product" class="nav-tab" data-tab="product"><span class="dashicons dashicons-products"></span> <?php esc_html_e('Product Insight', 'cig'); ?></a>
        <a href="#tab-customer" class="nav-tab" data-tab="customer"><span class="dashicons dashicons-businessperson"></span> <?php esc_html_e('Customer Insight', 'cig'); ?></a>
        <a href="#tab-external" class="nav-tab" data-tab="external"><span class="dashicons dashicons-wallet"></span> <?php esc_html_e('External Balance', 'cig'); ?></a> </h2>

    <div id="cig-tab-overview" class="cig-tab-content active">
        <div class="cig-stats-filters-bar">
            <div class="cig-filters-row">
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Quick Filters:', 'cig'); ?></label>
                    <div class="cig-quick-filters">
                        <button type="button" class="cig-quick-filter-btn" data-filter="today"><?php esc_html_e('Today', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn" data-filter="this_week"><?php esc_html_e('This Week', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn" data-filter="this_month"><?php esc_html_e('This Month', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn" data-filter="last_30_days"><?php esc_html_e('Last 30 Days', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn active" data-filter="all_time"><?php esc_html_e('All Time', 'cig'); ?></button>
                    </div>
                </div>

                <div class="cig-filter-group">
                    <label><?php esc_html_e('Custom Range:', 'cig'); ?></label>
                    <div class="cig-date-range">
                        <input type="date" id="cig-date-from" class="cig-date-input" placeholder="<?php esc_attr_e('From', 'cig'); ?>">
                        <span>-</span>
                        <input type="date" id="cig-date-to" class="cig-date-input" placeholder="<?php esc_attr_e('To', 'cig'); ?>">
                        <button type="button" id="cig-apply-date-range" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                    </div>
                </div>

                <div class="cig-filter-group">
                    <label for="cig-payment-filter"><?php esc_html_e('Payment Method:', 'cig'); ?></label>
                    <select id="cig-payment-filter" class="cig-select-filter">
                        <option value="all"><?php esc_html_e('All Methods', 'cig'); ?></option>
                        <?php
                        $payment_types = CIG_Invoice::get_payment_types();
                        foreach ($payment_types as $key => $label) {
                            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
                        }
                        ?>
                    </select>
                </div>

                <div class="cig-filter-group">
                    <label for="cig-overview-search"><?php esc_html_e('Search:', 'cig'); ?></label>
                    <input type="text" id="cig-overview-search" class="cig-search-input" placeholder="<?php esc_attr_e('Search Invoice #, Client Name, or Tax ID', 'cig'); ?>" style="min-width:250px;">
                </div>

                <div class="cig-filter-group">
                    <button type="button" id="cig-export-stats" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> <?php esc_html_e('Export to Excel', 'cig'); ?>
                    </button>
                </div>
            </div>
        </div>

        <div class="cig-stats-summary" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
            
            <div class="cig-stat-card" id="cig-card-total-invoices" data-dropdown="invoices" data-method="">
                <div class="cig-stat-icon" style="background:#50529d;">
                    <span class="dashicons dashicons-media-spreadsheet"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ ინვოისები', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-invoices"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend" id="trend-invoices"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-total-reserved-invoices" data-dropdown="invoices" data-method="reserved_invoices">
                <div class="cig-stat-icon" style="background:#ffc107;">
                    <span class="dashicons dashicons-lock"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('დარეზერვებული ინვოისები', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-reserved-invoices"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('დააჭირეთ სანახავად', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-total-revenue" data-dropdown="invoices" data-method="">
                <div class="cig-stat-icon" style="background:#17a2b8;">
                    <span class="dashicons dashicons-chart-bar"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ ნავაჭრი', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-revenue"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend" id="trend-revenue"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-total-paid" data-dropdown="invoices" data-method="all">
                <div class="cig-stat-icon" style="background:#28a745;">
                    <span class="dashicons dashicons-money-alt"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('გადახდილი თანხა', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-paid"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-total-outstanding" data-dropdown="outstanding" data-method="all">
                <div class="cig-stat-icon" style="background:#dc3545;">
                    <span class="dashicons dashicons-warning"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('გადასახდელი თანხა', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-outstanding"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view unpaid', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-cash" data-dropdown="invoices" data-method="cash">
                <div class="cig-stat-icon" style="background:#28a745;">
                    <span class="dashicons dashicons-money"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ ქეში', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-cash"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-transfer" data-dropdown="invoices" data-method="company_transfer">
                <div class="cig-stat-icon" style="background:#17a2b8;">
                    <span class="dashicons dashicons-bank"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ ჩარიცხვა', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-company_transfer"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-credit" data-dropdown="invoices" data-method="credit">
                <div class="cig-stat-icon" style="background:#6c757d;">
                    <span class="dashicons dashicons-calendar-alt"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ განვადება', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-credit"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-consignment" data-dropdown="invoices" data-method="consignment">
                <div class="cig-stat-icon" style="background:#ffc107;">
                    <span class="dashicons dashicons-clipboard"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ კონსიგნაცია', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-consignment"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card" id="cig-card-other" data-dropdown="invoices" data-method="other">
                <div class="cig-stat-icon" style="background:#343a40;">
                    <span class="dashicons dashicons-editor-help"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('სულ სხვა', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-total-other"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>


            

        </div>

        <div class="cig-summary-dropdown" id="cig-summary-invoices" style="display:none;">
            <div class="cig-summary-header">
                <h3 id="cig-summary-title"><?php esc_html_e('Invoices', 'cig'); ?></h3>
                <button type="button" class="button cig-summary-close" data-target="#cig-summary-invoices">✕</button>
            </div>
            <div class="cig-summary-body">
                <div class="cig-table-container">
                    <table class="cig-stats-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Invoice #', 'cig'); ?></th>
                                <th><?php esc_html_e('Customer', 'cig'); ?></th>
                                <th><?php esc_html_e('Payment Method', 'cig'); ?></th>
                                <th><?php esc_html_e('Total', 'cig'); ?></th>
                                <th style="color:#28a745;"><?php esc_html_e('Paid', 'cig'); ?></th>
                                <th style="color:#dc3545;"><?php esc_html_e('Due', 'cig'); ?></th>
                                <th><?php esc_html_e('Date', 'cig'); ?></th>
                                <th><?php esc_html_e('Author', 'cig'); ?></th>
                                <th><?php esc_html_e('Actions', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-summary-invoices-tbody">
                            <tr class="loading-row">
                                <td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading...', 'cig'); ?></p></div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="cig-summary-dropdown" id="cig-summary-outstanding" style="display:none;">
            <div class="cig-summary-header">
                <h3><?php esc_html_e('Outstanding Invoices', 'cig'); ?></h3>
                <button type="button" class="button cig-summary-close" data-target="#cig-summary-outstanding">✕</button>
            </div>
            <div class="cig-summary-body">
                <div class="cig-table-container">
                    <table class="cig-stats-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Invoice #', 'cig'); ?></th>
                                <th><?php esc_html_e('Customer', 'cig'); ?></th>
                                <th><?php esc_html_e('Payment Method', 'cig'); ?></th>
                                <th><?php esc_html_e('Total', 'cig'); ?></th>
                                <th style="color:#28a745;"><?php esc_html_e('Paid', 'cig'); ?></th>
                                <th style="color:#dc3545;"><?php esc_html_e('Remaining', 'cig'); ?></th>
                                <th><?php esc_html_e('Date', 'cig'); ?></th>
                                <th><?php esc_html_e('Author', 'cig'); ?></th>
                                <th><?php esc_html_e('Actions', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-summary-outstanding-tbody">
                            <tr class="loading-row">
                                <td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading unpaid invoices...', 'cig'); ?></p></div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="cig-stats-grid" style="grid-template-columns: 100%;">
            <div class="cig-table-card" id="cig-users-panel" style="grid-column: 1 / -1;">
                <div class="cig-section-header cig-users-header-inline">
                    <h2><?php esc_html_e('Performance by User', 'cig'); ?></h2>
                    <div class="cig-section-controls">
                        <input type="text" id="cig-user-search" class="cig-search-input" placeholder="<?php esc_attr_e('Search users...', 'cig'); ?>">
                        <select id="cig-users-per-page" class="cig-per-page-select">
                            <option value="20">20 per page</option>
                            <option value="50">50 per page</option>
                            <option value="100">100 per page</option>
                        </select>
                    </div>
                </div>
                <!-- Users Section Date Filter Bar -->
                <div class="cig-stats-filters-bar" style="margin-bottom: 15px;">
                    <div class="cig-filters-row">
                        <div class="cig-filter-group">
                            <label><?php esc_html_e('Quick Filters:', 'cig'); ?></label>
                            <div class="cig-quick-filters">
                                <button type="button" class="cig-quick-filter-btn cig-users-quick-filter-btn" id="cig-users-filter-today" data-filter="today"><?php esc_html_e('Today', 'cig'); ?></button>
                                <button type="button" class="cig-quick-filter-btn cig-users-quick-filter-btn" id="cig-users-filter-this-week" data-filter="this_week"><?php esc_html_e('This Week', 'cig'); ?></button>
                                <button type="button" class="cig-quick-filter-btn cig-users-quick-filter-btn" id="cig-users-filter-this-month" data-filter="this_month"><?php esc_html_e('This Month', 'cig'); ?></button>
                                <button type="button" class="cig-quick-filter-btn cig-users-quick-filter-btn" id="cig-users-filter-last-30-days" data-filter="last_30_days"><?php esc_html_e('Last 30 Days', 'cig'); ?></button>
                                <button type="button" class="cig-quick-filter-btn cig-users-quick-filter-btn active" id="cig-users-filter-all-time" data-filter="all_time"><?php esc_html_e('All Time', 'cig'); ?></button>
                            </div>
                        </div>

                        <div class="cig-filter-group">
                            <label><?php esc_html_e('Custom Range:', 'cig'); ?></label>
                            <div class="cig-date-range">
                                <input type="date" id="cig-users-date-from" class="cig-date-input" placeholder="<?php esc_attr_e('From', 'cig'); ?>">
                                <span>-</span>
                                <input type="date" id="cig-users-date-to" class="cig-date-input" placeholder="<?php esc_attr_e('To', 'cig'); ?>">
                                <button type="button" id="cig-users-apply-date-range" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="cig-table-container">
                    <table class="cig-stats-table" id="cig-users-table">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="user_name"><?php esc_html_e('User', 'cig'); ?></th>
                                <th class="sortable" data-sort="invoice_count"><?php esc_html_e('Invoices', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_sold"><?php esc_html_e('Sold', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_reserved"><?php esc_html_e('Reserved', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_canceled"><?php esc_html_e('Canceled', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_revenue"><?php esc_html_e('Revenue', 'cig'); ?></th>
                                <th class="sortable" data-sort="last_invoice_date"><?php esc_html_e('Last Invoice', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-users-tbody">
                            <tr class="loading-row"><td colspan="7"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading users...', 'cig'); ?></p></div></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="cig-table-footer">
                    <div class="cig-pagination" id="cig-users-pagination"></div>
                </div>
            </div>

            <div class="cig-table-card cig-user-detail-panel" id="cig-user-detail-panel" style="display:none;">
                <div class="cig-section-header cig-user-detail-header-inline">
                    <div class="cig-user-detail-controls">
                        <button type="button" id="cig-back-to-users" class="button">
                            <span class="dashicons dashicons-arrow-left-alt"></span> <?php esc_html_e('Back to Users', 'cig'); ?>
                        </button>
                        <h2 id="cig-user-detail-title"><?php esc_html_e('User Invoices', 'cig'); ?></h2>
                    </div>
                    <div class="cig-user-invoices-filters-inline">
                        <input type="text" id="cig-invoice-search" class="cig-search-input" placeholder="<?php esc_attr_e('Search by invoice number...', 'cig'); ?>">
                        <select id="cig-user-payment-filter" class="cig-select-filter">
                            <option value="all"><?php esc_html_e('All Payment Methods', 'cig'); ?></option>
                            <?php foreach ($payment_types as $key => $label) { echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>'; } ?>
                        </select>
                        <select id="cig-invoices-per-page" class="cig-per-page-select">
                            <option value="30">30 per page</option>
                            <option value="50">50 per page</option>
                            <option value="100">100 per page</option>
                        </select>
                    </div>
                </div>
                <div class="cig-user-info-card" id="cig-user-info"></div>
                <div class="cig-table-container">
                    <table class="cig-stats-table" id="cig-user-invoices-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Invoice #', 'cig'); ?></th>
                                <th><?php esc_html_e('Date', 'cig'); ?></th>
                                <th><?php esc_html_e('Total Products', 'cig'); ?></th>
                                <th><?php esc_html_e('Sold', 'cig'); ?></th>
                                <th><?php esc_html_e('Reserved', 'cig'); ?></th>
                                <th><?php esc_html_e('Canceled', 'cig'); ?></th>
                                <th><?php esc_html_e('Invoice Total', 'cig'); ?></th>
                                <th><?php esc_html_e('Payment Method', 'cig'); ?></th>
                                <th><?php esc_html_e('Actions', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-user-invoices-tbody">
                            <tr class="loading-row"><td colspan="9"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading invoices...', 'cig'); ?></p></div></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="cig-table-footer">
                    <div class="cig-pagination" id="cig-invoices-pagination"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- FICTIVE INVOICES TAB -->
    <div id="cig-tab-fictive" class="cig-tab-content" style="display:none;">
        <!-- Fictive Tab Filters Bar - Date Range Only (no payment method) -->
        <div class="cig-stats-filters-bar">
            <div class="cig-filters-row">
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Quick Filters:', 'cig'); ?></label>
                    <div class="cig-quick-filters">
                        <button type="button" class="cig-quick-filter-btn cig-fictive-quick-filter-btn" data-filter="today"><?php esc_html_e('Today', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn cig-fictive-quick-filter-btn" data-filter="this_week"><?php esc_html_e('This Week', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn cig-fictive-quick-filter-btn" data-filter="this_month"><?php esc_html_e('This Month', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn cig-fictive-quick-filter-btn" data-filter="last_30_days"><?php esc_html_e('Last 30 Days', 'cig'); ?></button>
                        <button type="button" class="cig-quick-filter-btn cig-fictive-quick-filter-btn active" data-filter="all_time"><?php esc_html_e('All Time', 'cig'); ?></button>
                    </div>
                </div>

                <div class="cig-filter-group">
                    <label><?php esc_html_e('Custom Range:', 'cig'); ?></label>
                    <div class="cig-date-range">
                        <input type="date" id="cig-fictive-date-from" class="cig-date-input" placeholder="<?php esc_attr_e('From', 'cig'); ?>">
                        <span>-</span>
                        <input type="date" id="cig-fictive-date-to" class="cig-date-input" placeholder="<?php esc_attr_e('To', 'cig'); ?>">
                        <button type="button" id="cig-fictive-apply-date-range" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                    </div>
                </div>

                <div class="cig-filter-group">
                    <label for="cig-fictive-search"><?php esc_html_e('Search:', 'cig'); ?></label>
                    <input type="text" id="cig-fictive-search" class="cig-search-input" placeholder="<?php esc_attr_e('Search Invoice #, Client Name, or Tax ID', 'cig'); ?>" style="min-width:250px;">
                </div>
            </div>
        </div>

        <!-- Fictive Summary Cards -->
        <div class="cig-stats-summary" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
            
            <div class="cig-stat-card cig-fictive-card" id="cig-card-fictive-invoices" data-dropdown="fictive-invoices" data-method="all">
                <div class="cig-stat-icon" style="background:#6c757d;">
                    <span class="dashicons dashicons-media-spreadsheet"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('Total Fictive Invoices', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-fictive-invoices"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card cig-fictive-card" id="cig-card-fictive-amount" data-dropdown="fictive-invoices" data-method="all">
                <div class="cig-stat-icon" style="background:#343a40;">
                    <span class="dashicons dashicons-chart-bar"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('Total Fictive Amount', 'cig'); ?></div>
                    <div class="cig-stat-value" id="stat-fictive-amount"><span class="loading-stat">...</span></div>
                    <div class="cig-stat-trend"><?php esc_html_e('Click to view invoices', 'cig'); ?></div>
                </div>
            </div>

        </div>

        <!-- Fictive Invoices Drill-Down Table -->
        <div class="cig-summary-dropdown" id="cig-summary-fictive-invoices" style="display:none;">
            <div class="cig-summary-header">
                <h3 id="cig-summary-fictive-title"><?php esc_html_e('Fictive Invoices', 'cig'); ?></h3>
                <button type="button" class="button cig-summary-close" data-target="#cig-summary-fictive-invoices">✕</button>
            </div>
            <div class="cig-summary-body">
                <div class="cig-table-container">
                    <table class="cig-stats-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Invoice #', 'cig'); ?></th>
                                <th><?php esc_html_e('Customer', 'cig'); ?></th>
                                <th><?php esc_html_e('Total', 'cig'); ?></th>
                                <th><?php esc_html_e('Date', 'cig'); ?></th>
                                <th><?php esc_html_e('Author', 'cig'); ?></th>
                                <th><?php esc_html_e('Actions', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-summary-fictive-invoices-tbody">
                            <tr class="loading-row">
                                <td colspan="6"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading...', 'cig'); ?></p></div></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Fictive Performance by User Section -->
        <div class="cig-stats-grid" style="grid-template-columns: 100%; margin-top: 20px;">
            <div class="cig-table-card" id="cig-fictive-users-panel" style="grid-column: 1 / -1;">
                <div class="cig-section-header cig-users-header-inline">
                    <h2><?php esc_html_e('Performance by User (Fictive)', 'cig'); ?></h2>
                    <div class="cig-section-controls">
                        <input type="text" id="cig-fictive-user-search" class="cig-search-input" placeholder="<?php esc_attr_e('Search users...', 'cig'); ?>">
                        <select id="cig-fictive-users-per-page" class="cig-per-page-select">
                            <option value="20">20 per page</option>
                            <option value="50">50 per page</option>
                            <option value="100">100 per page</option>
                        </select>
                    </div>
                </div>
                <!-- Fictive Users Section Date Filter Bar -->
                <div class="cig-stats-filters-bar" style="margin-bottom: 15px;">
                    <div class="cig-filters-row">
                        <div class="cig-filter-group">
                            <label><?php esc_html_e('Quick Filters:', 'cig'); ?></label>
                            <div class="cig-quick-filters">
                                <button type="button" class="cig-quick-filter-btn cig-fictive-users-quick-filter-btn" id="cig-fictive-users-filter-today" data-filter="today"><?php esc_html_e('Today', 'cig'); ?></button>
                                <button type="button" class="cig-quick-filter-btn cig-fictive-users-quick-filter-btn" id="cig-fictive-users-filter-this-week" data-filter="this_week"><?php esc_html_e('This Week', 'cig'); ?></button>
                                <button type="button" class="cig-quick-filter-btn cig-fictive-users-quick-filter-btn" id="cig-fictive-users-filter-this-month" data-filter="this_month"><?php esc_html_e('This Month', 'cig'); ?></button>
                                <button type="button" class="cig-quick-filter-btn cig-fictive-users-quick-filter-btn" id="cig-fictive-users-filter-last-30-days" data-filter="last_30_days"><?php esc_html_e('Last 30 Days', 'cig'); ?></button>
                                <button type="button" class="cig-quick-filter-btn cig-fictive-users-quick-filter-btn active" id="cig-fictive-users-filter-all-time" data-filter="all_time"><?php esc_html_e('All Time', 'cig'); ?></button>
                            </div>
                        </div>

                        <div class="cig-filter-group">
                            <label><?php esc_html_e('Custom Range:', 'cig'); ?></label>
                            <div class="cig-date-range">
                                <input type="date" id="cig-fictive-users-date-from" class="cig-date-input" placeholder="<?php esc_attr_e('From', 'cig'); ?>">
                                <span>-</span>
                                <input type="date" id="cig-fictive-users-date-to" class="cig-date-input" placeholder="<?php esc_attr_e('To', 'cig'); ?>">
                                <button type="button" id="cig-fictive-users-apply-date-range" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="cig-table-container">
                    <table class="cig-stats-table" id="cig-fictive-users-table">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="user_name"><?php esc_html_e('User', 'cig'); ?></th>
                                <th class="sortable" data-sort="invoice_count"><?php esc_html_e('Invoices', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_sold"><?php esc_html_e('Sold', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_reserved"><?php esc_html_e('Reserved', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_canceled"><?php esc_html_e('Canceled', 'cig'); ?></th>
                                <th class="sortable" data-sort="total_revenue"><?php esc_html_e('Revenue', 'cig'); ?></th>
                                <th class="sortable" data-sort="last_invoice_date"><?php esc_html_e('Last Invoice', 'cig'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="cig-fictive-users-tbody">
                            <tr class="loading-row"><td colspan="7"><div class="cig-loading-spinner"><div class="spinner"></div><p><?php esc_html_e('Loading users...', 'cig'); ?></p></div></td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="cig-table-footer">
                    <div class="cig-pagination" id="cig-fictive-users-pagination"></div>
                </div>
            </div>
        </div>
    </div>

    <div id="cig-tab-product" class="cig-tab-content" style="display:none;">
        <!-- Product Performance Filter Bar -->
        <div class="cig-stats-filters-bar" id="cig-product-perf-filters">
            <div class="cig-filters-row">
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Date Range:', 'cig'); ?></label>
                    <div class="cig-date-range">
                        <input type="date" id="cig-pp-date-from" class="cig-date-input">
                        <span>-</span>
                        <input type="date" id="cig-pp-date-to" class="cig-date-input">
                        <button type="button" id="cig-pp-apply-date" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                    </div>
                </div>
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Search:', 'cig'); ?></label>
                    <input type="text" id="cig-pp-search" class="cig-search-input" placeholder="<?php esc_attr_e('Search Product Name or SKU...', 'cig'); ?>" style="min-width:250px;">
                </div>
            </div>
        </div>

        <!-- Product Sold Summary -->
        <div class="cig-stats-summary" id="cig-product-sold-summary" style="grid-template-columns: repeat(2, 1fr); display:none;">
            <div class="cig-stat-card">
                <div class="cig-stat-icon" style="background:#28a745;">
                    <span class="dashicons dashicons-chart-bar"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('Total Units Sold', 'cig'); ?></div>
                    <div class="cig-stat-value" id="cig-pp-total-units">0</div>
                </div>
            </div>
            <div class="cig-stat-card">
                <div class="cig-stat-icon" style="background:#50529d;">
                    <span class="dashicons dashicons-products"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('Unique Products', 'cig'); ?></div>
                    <div class="cig-stat-value" id="cig-pp-unique-products">0</div>
                </div>
            </div>
        </div>

        <!-- Product Performance Table -->
        <div class="cig-table-card" id="cig-product-perf-panel">
            <div class="cig-section-header cig-users-header-inline">
                <h2><?php esc_html_e('Product Performance', 'cig'); ?></h2>
            </div>
            <div class="cig-table-container">
                <table class="cig-stats-table" id="cig-product-perf-table">
                    <thead>
                        <tr>
                            <th style="width:60px;"><?php esc_html_e('Photo', 'cig'); ?></th>
                            <th><?php esc_html_e('Product', 'cig'); ?></th>
                            <th class="sortable" data-sort="price"><?php esc_html_e('Price', 'cig'); ?></th>
                            <th class="sortable" data-sort="stock"><?php esc_html_e('Current Stock', 'cig'); ?></th>
                            <th class="sortable" data-sort="total_reserved"><?php esc_html_e('Reserved', 'cig'); ?></th>
                            <th class="sortable" data-sort="total_fictive"><?php esc_html_e('Fictive', 'cig'); ?></th>
                            <th class="sortable" data-sort="total_sold"><?php esc_html_e('Total Sold', 'cig'); ?></th>
                            <th class="sortable" data-sort="total_revenue"><?php esc_html_e('Total Revenue', 'cig'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cig-product-perf-tbody">
                        <tr class="loading-row">
                            <td colspan="8">
                                <div class="cig-loading-spinner">
                                    <div class="spinner"></div>
                                    <p><?php esc_html_e('Loading product performance...', 'cig'); ?></p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="cig-table-footer">
                <div class="cig-pagination" id="cig-product-pagination"></div>
            </div>
        </div>
    </div>

    <div id="cig-tab-customer" class="cig-tab-content" style="display:none;">
        <div class="cig-stats-filters-bar">
            <div class="cig-filters-row">
                <div class="cig-filter-group"><label><?php esc_html_e('Search Customer:', 'cig'); ?></label><input type="text" id="cig-customer-search" class="cig-search-input" placeholder="<?php esc_attr_e('Name or Tax ID...', 'cig'); ?>" style="min-width:250px;"></div>
                <div class="cig-filter-group"><label><?php esc_html_e('Date Range:', 'cig'); ?></label><div class="cig-date-range"><input type="date" id="cig-cust-date-from" class="cig-date-input"><span>-</span><input type="date" id="cig-cust-date-to" class="cig-date-input"><button type="button" id="cig-cust-apply-date" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button></div></div>
            </div>
        </div>
        <div id="cig-customer-list-panel" class="cig-table-card">
            <div class="cig-table-container"><table class="cig-stats-table" id="cig-customers-table"><thead><tr><th>Customer Name</th><th>Tax ID</th><th>Invoices</th><th>Total Revenue</th><th style="color:#28a745;">Paid</th><th style="color:#dc3545;">Due</th></tr></thead><tbody id="cig-customers-tbody"><tr class="loading-row"><td colspan="6"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading...</p></div></td></tr></tbody></table></div>
            <div class="cig-table-footer"><div class="cig-pagination" id="cig-customers-pagination"></div></div>
        </div>
        <div id="cig-customer-detail-panel" class="cig-table-card" style="display:none; margin-top:20px;">
            <div class="cig-section-header cig-user-detail-header-inline"><div class="cig-user-detail-controls"><button type="button" id="cig-back-to-customers" class="button"><span class="dashicons dashicons-arrow-left-alt"></span> <?php esc_html_e('Back to List', 'cig'); ?></button><h2 id="cig-customer-detail-title"></h2></div></div>
            <div class="cig-table-container"><table class="cig-stats-table"><thead><tr><th>Invoice #</th><th>Date</th><th>Total</th><th style="color:#28a745;">Paid</th><th style="color:#dc3545;">Due</th><th>Status</th><th>Action</th></tr></thead><tbody id="cig-cust-invoices-tbody"></tbody></table></div>
        </div>
    </div>

    <div id="cig-tab-external" class="cig-tab-content" style="display:none;">
        
        <div class="cig-stats-filters-bar">
            <div class="cig-filters-row">
                <div class="cig-filter-group">
                    <label><?php esc_html_e('Date Range:', 'cig'); ?></label>
                    <div class="cig-date-range">
                        <input type="date" id="cig-ext-date-from" class="cig-date-input">
                        <span>-</span>
                        <input type="date" id="cig-ext-date-to" class="cig-date-input">
                        <button type="button" id="cig-ext-apply-date" class="button button-primary"><?php esc_html_e('Apply', 'cig'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="cig-stats-summary" style="grid-template-columns: repeat(3, 1fr);">
            
            <div class="cig-stat-card">
                <div class="cig-stat-icon" style="background:#343a40;">
                    <span class="dashicons dashicons-editor-help"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('დაგროვილი ("სხვა")', 'cig'); ?></div>
                    <div class="cig-stat-value" id="cig-ext-accumulated">...</div>
                    <div class="cig-stat-trend"><?php esc_html_e('Selected Period', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card">
                <div class="cig-stat-icon" style="background:#28a745;">
                    <span class="dashicons dashicons-download"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('ჩაბარებული', 'cig'); ?></div>
                    <div class="cig-stat-value" id="cig-ext-deposited">...</div>
                    <div class="cig-stat-trend"><?php esc_html_e('Selected Period', 'cig'); ?></div>
                </div>
            </div>

            <div class="cig-stat-card">
                <div class="cig-stat-icon" style="background:#dc3545;">
                    <span class="dashicons dashicons-chart-pie"></span>
                </div>
                <div class="cig-stat-content">
                    <div class="cig-stat-label"><?php esc_html_e('მიმდინარე ბალანსი', 'cig'); ?></div>
                    <div class="cig-stat-value" id="cig-ext-balance">...</div>
                    <div class="cig-stat-trend" style="color:#dc3545; font-weight:bold;"><?php esc_html_e('Total Due', 'cig'); ?></div>
                </div>
            </div>

        </div>

        <div style="margin: 20px 0; text-align: right;">
            <button type="button" id="cig-btn-add-deposit" class="button button-primary button-hero" style="background:#28a745; border-color:#28a745;">
                <span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;"></span> <?php esc_html_e('თანხის ჩაბარება', 'cig'); ?>
            </button>
        </div>

        <div class="cig-table-card">
            <div class="cig-section-header cig-users-header-inline">
                <h3><?php esc_html_e('ჩაბარების ისტორია', 'cig'); ?></h3>
            </div>
            <div class="cig-table-container">
                <table class="cig-stats-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('თარიღი', 'cig'); ?></th>
                            <th><?php esc_html_e('კომენტარი', 'cig'); ?></th>
                            <th style="text-align:right;"><?php esc_html_e('თანხა', 'cig'); ?></th>
                            <th style="width:50px; text-align:center;"><?php esc_html_e('მოქმედება', 'cig'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cig-ext-history-tbody">
                        <tr class="loading-row"><td colspan="4"><div class="cig-loading-spinner"><div class="spinner"></div><p>Loading...</p></div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<div id="cig-deposit-modal" style="display:none; position:fixed; z-index:99999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6);">
    <div style="background:#fff; width:400px; margin:100px auto; padding:30px; border-radius:8px; position:relative; box-shadow:0 5px 15px rgba(0,0,0,0.3);">
        <button type="button" id="cig-close-deposit-modal" style="position:absolute; top:10px; right:15px; border:none; background:none; font-size:20px; cursor:pointer;">&times;</button>
        
        <h2 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px; color:#333;">
            <?php esc_html_e('თანხის ჩაბარება', 'cig'); ?>
        </h2>
        
        <div style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('თარიღი', 'cig'); ?></label>
            <input type="date" id="cig-dep-date" class="regular-text" style="width:100%;" value="<?php echo current_time('Y-m-d'); ?>">
        </div>

        <div style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('თანხა (GEL)', 'cig'); ?></label>
            <input type="number" id="cig-dep-amount" class="regular-text" style="width:100%;" placeholder="0.00" step="0.01">
        </div>

        <div style="margin-bottom:20px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('კომენტარი', 'cig'); ?></label>
            <textarea id="cig-dep-note" class="regular-text" style="width:100%; height:80px;" placeholder="<?php esc_attr_e('შენიშვნა...', 'cig'); ?>"></textarea>
        </div>

        <div style="text-align:right;">
            <button type="button" id="cig-submit-deposit" class="button button-primary button-large" style="width:100%; justify-content:center;">
                <?php esc_html_e('დადასტურება', 'cig'); ?>
            </button>
        </div>
    </div>
</div>