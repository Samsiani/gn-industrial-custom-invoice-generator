<?php
/**
 * Accountant Dashboard Handler
 * Updated: Adaptive Filters (Reset Button Hidden by Default), Full Modals, Admin Columns with Client
 *
 * @package CIG
 * @since 4.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Accountant {

    /** @var CIG_Invoice_Manager */
    private $invoice_manager;

    public function __construct() {
        $this->invoice_manager = CIG_Invoice_Manager::instance();
        add_action('admin_menu', [$this, 'register_menu']);
        add_shortcode('invoice_accountant_dashboard', [$this, 'render_shortcode']);

        // Stock export AJAX (accountant-only, separate from backup plugin)
        add_action('wp_ajax_cig_acc_stock_start', [$this, 'ajax_stock_start']);
        add_action('wp_ajax_cig_acc_stock_batch', [$this, 'ajax_stock_batch']);
        add_action('wp_ajax_cig_acc_stock_download', [$this, 'ajax_stock_download']);
    }

    public function register_menu() {
        add_submenu_page(
            'edit.php?post_type=invoice',
            __('Accountant', 'cig'),
            __('Accountant', 'cig'),
            'manage_woocommerce', 
            'cig-accountant',
            [$this, 'render_admin_page']
        );
    }

    public function render_shortcode($atts) {
        if (!current_user_can('read')) {
            return '<div class="cig-notice-error" style="padding:15px;background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:4px;">' . 
                   __('Access Denied. You do not have permission to view this page.', 'cig') . 
                   '</div>';
        }

        ob_start();
        ?>
        <div class="cig-accountant-wrapper">
            <div class="cig-accountant-header">
                <style>
                    .cig-stock-export-row {
                        display: flex;
                        align-items: center;
                        gap: 14px;
                        flex-wrap: wrap;
                        margin-bottom: 14px;
                    }
                    .cig-stock-export-row h2 { margin: 0; }

                    /* ── Export Button ── */
                    #cig-acc-stock-btn {
                        display: inline-flex;
                        align-items: center;
                        gap: 7px;
                        padding: 7px 18px;
                        background: linear-gradient(135deg, #4472C4 0%, #3a5fad 100%);
                        color: #fff;
                        border: none;
                        border-radius: 6px;
                        font-size: 13px;
                        font-weight: 600;
                        cursor: pointer;
                        box-shadow: 0 2px 6px rgba(68,114,196,.35);
                        transition: all .2s ease;
                        line-height: 1;
                    }
                    #cig-acc-stock-btn:hover:not(:disabled) {
                        background: linear-gradient(135deg, #3a5fad 0%, #2e4d8e 100%);
                        box-shadow: 0 4px 12px rgba(68,114,196,.45);
                        transform: translateY(-1px);
                    }
                    #cig-acc-stock-btn:active:not(:disabled) {
                        transform: translateY(0);
                        box-shadow: 0 1px 3px rgba(68,114,196,.3);
                    }
                    #cig-acc-stock-btn:disabled {
                        opacity: .55;
                        cursor: not-allowed;
                    }
                    #cig-acc-stock-btn .dashicons {
                        font-size: 15px;
                        width: 15px;
                        height: 15px;
                        line-height: 15px;
                    }

                    /* ── Progress Bar ── */
                    .cig-stock-progress-wrap {
                        display: none;
                        align-items: center;
                        gap: 10px;
                        padding: 6px 14px;
                        background: #f7f9fc;
                        border: 1px solid #dce3ed;
                        border-radius: 8px;
                        min-width: 220px;
                        max-width: 380px;
                        flex: 1;
                    }
                    .cig-stock-progress-wrap.active { display: flex; }
                    .cig-stock-progress-info {
                        display: flex;
                        flex-direction: column;
                        gap: 1px;
                        min-width: 0;
                        flex: 1;
                    }
                    #cig-acc-stock-text {
                        font-size: 11.5px;
                        font-weight: 600;
                        color: #3a5fad;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                    }
                    #cig-acc-stock-pct {
                        font-size: 11px;
                        color: #8a94a6;
                        font-variant-numeric: tabular-nums;
                    }
                    .cig-stock-bar-track {
                        width: 100%;
                        height: 6px;
                        background: #e2e7f0;
                        border-radius: 3px;
                        overflow: hidden;
                        margin-top: 3px;
                    }
                    #cig-acc-stock-bar {
                        height: 100%;
                        width: 0;
                        border-radius: 3px;
                        background: linear-gradient(90deg, #4472C4, #5b8de6);
                        transition: width .35s ease;
                    }
                    #cig-acc-stock-bar.done {
                        background: linear-gradient(90deg, #28a745, #34d058);
                    }
                    #cig-acc-stock-bar.error {
                        background: linear-gradient(90deg, #d63638, #e25456);
                    }

                    /* ── Spinner inside progress ── */
                    .cig-stock-spinner {
                        width: 20px;
                        height: 20px;
                        border: 2.5px solid #dce3ed;
                        border-top-color: #4472C4;
                        border-radius: 50%;
                        animation: cig-spin .7s linear infinite;
                        flex-shrink: 0;
                    }
                    .cig-stock-spinner.done {
                        border-color: #28a745;
                        border-top-color: transparent;
                        animation: none;
                    }
                    @keyframes cig-spin { to { transform: rotate(360deg); } }

                    /* ── Download Button ── */
                    #cig-acc-stock-download {
                        display: none;
                        align-items: center;
                        gap: 7px;
                        padding: 7px 18px;
                        background: linear-gradient(135deg, #28a745 0%, #22913c 100%);
                        color: #fff;
                        border: none;
                        border-radius: 6px;
                        font-size: 13px;
                        font-weight: 600;
                        text-decoration: none;
                        box-shadow: 0 2px 6px rgba(40,167,69,.35);
                        transition: all .25s ease;
                        line-height: 1;
                        animation: cig-pop-in .35s cubic-bezier(.34,1.56,.64,1);
                    }
                    #cig-acc-stock-download:hover {
                        background: linear-gradient(135deg, #22913c 0%, #1b7a32 100%);
                        box-shadow: 0 4px 14px rgba(40,167,69,.45);
                        transform: translateY(-1px);
                        color: #fff;
                    }
                    #cig-acc-stock-download .dashicons {
                        font-size: 16px;
                        width: 16px;
                        height: 16px;
                        line-height: 16px;
                    }
                    @keyframes cig-pop-in {
                        0% { opacity:0; transform: scale(.8); }
                        100% { opacity:1; transform: scale(1); }
                    }
                </style>

                <div class="cig-stock-export-row">
                    <h2><?php esc_html_e('Accountant Dashboard', 'cig'); ?></h2>

                    <button type="button" id="cig-acc-stock-btn">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Export Stock', 'cig'); ?>
                    </button>

                    <div class="cig-stock-progress-wrap" id="cig-acc-stock-progress">
                        <div class="cig-stock-spinner" id="cig-acc-stock-spinner"></div>
                        <div class="cig-stock-progress-info">
                            <span id="cig-acc-stock-text">Starting...</span>
                            <span id="cig-acc-stock-pct"></span>
                            <div class="cig-stock-bar-track">
                                <div id="cig-acc-stock-bar"></div>
                            </div>
                        </div>
                    </div>

                    <a id="cig-acc-stock-download" href="#">
                        <span class="dashicons dashicons-media-spreadsheet"></span>
                        <?php esc_html_e('Download XLSX', 'cig'); ?>
                    </a>
                </div>
                
                <div class="cig-acc-filters-section">
                    
                    <div class="cig-filter-line">
                        <div class="cig-acc-search-group">
                            <span class="dashicons dashicons-search"></span>
                            <input type="text" id="cig-acc-search" placeholder="<?php esc_attr_e('Search Invoice #, Name, Tax ID...', 'cig'); ?>">
                        </div>

                        <div class="cig-date-controls">
                            <div class="cig-quick-filters">
                                <button type="button" class="cig-qf-btn" data-range="today"><?php esc_html_e('დღეს', 'cig'); ?></button>
                                <button type="button" class="cig-qf-btn" data-range="yesterday"><?php esc_html_e('გუშინ', 'cig'); ?></button>
                                <button type="button" class="cig-qf-btn" data-range="week"><?php esc_html_e('კვირა', 'cig'); ?></button>
                                <button type="button" class="cig-qf-btn" data-range="month"><?php esc_html_e('თვე', 'cig'); ?></button>
                                <button type="button" class="cig-qf-btn active" data-range="all"><?php esc_html_e('სულ', 'cig'); ?></button>
                            </div>
                            
                            <div class="cig-date-inputs">
                                <input type="date" id="cig-acc-date-from" class="cig-acc-date">
                                <span style="color:#aaa;">—</span>
                                <input type="date" id="cig-acc-date-to" class="cig-acc-date">
                            </div>
                        </div>
                    </div>

                    <div class="cig-filter-line second-line">
                        
                        <div class="cig-toggle-group">
                            <label class="cig-toggle-btn active" title="Show Everything">
                                <input type="radio" name="cig_completion" value="all" checked> 
                                <?php esc_html_e('All', 'cig'); ?>
                            </label>
                            <label class="cig-toggle-btn" title="Show invoices with ANY status">
                                <input type="radio" name="cig_completion" value="completed"> 
                                <?php esc_html_e('დასრულებული', 'cig'); ?>
                            </label>
                            <label class="cig-toggle-btn" title="Show invoices with NO status">
                                <input type="radio" name="cig_completion" value="incomplete"> 
                                <?php esc_html_e('დაუსრულებელი', 'cig'); ?>
                            </label>
                        </div>

                        <button type="button" id="cig-reset-filters" class="button button-secondary" style="margin: 0 15px; display:none;" title="<?php esc_attr_e('Reset all filters', 'cig'); ?>">
                            <span class="dashicons dashicons-image-rotate" style="vertical-align:middle; font-size:16px;"></span> 
                            <?php esc_html_e('ფილტრის გაუქმება', 'cig'); ?>
                        </button>

                        <div class="cig-dropdown-wrap">
                            <select id="cig-acc-type-filter" class="cig-acc-select">
                                <option value="all"><?php esc_html_e('ყველა სტატუსი (All Statuses)', 'cig'); ?></option>
                                <option value="rs"><?php esc_html_e('RS ატვირთული', 'cig'); ?></option>
                                <option value="credit"><?php esc_html_e('განვადება (Credit)', 'cig'); ?></option>
                                <option value="receipt"><?php esc_html_e('მთლიანი ჩეკი (Receipt)', 'cig'); ?></option>
                                <option value="corrected"><?php esc_html_e('კორექტირებული', 'cig'); ?></option>
                            </select>
                        </div>

                    </div>
                </div>
            </div>

            <div class="cig-acc-table-container">
                <table class="cig-acc-table">
                    <thead>
                        <tr>
                            <th style="width:120px;"><?php esc_html_e('Invoice / Date', 'cig'); ?></th>
                            <th style="width:90px;"><?php esc_html_e('Sale Date', 'cig'); ?></th>
                            <th><?php esc_html_e('Client', 'cig'); ?></th>
                            <th><?php esc_html_e('Payment', 'cig'); ?></th>
                            <th><?php esc_html_e('Total', 'cig'); ?></th>
                            
                            <th style="text-align:center; width:50px; background:#f9f9f9; border-left:1px solid #eee;" title="RS Uploaded"><?php esc_html_e('RS', 'cig'); ?></th>
                            <th style="text-align:center; width:50px; background:#f9f9f9;" title="Credit / განვადება"><?php esc_html_e('Credit', 'cig'); ?></th>
                            <th style="text-align:center; width:60px; background:#f9f9f9;" title="Receipt / მთლიანი ჩეკი"><?php esc_html_e('Receipt', 'cig'); ?></th>
                            <th style="text-align:center; width:70px; background:#f9f9f9; border-right:1px solid #eee;" title="Corrected"><?php esc_html_e('Corrected', 'cig'); ?></th>
                            
                            <th style="text-align:center; width:60px;"><?php esc_html_e('Note', 'cig'); ?></th>
                            <th style="text-align:center; width:50px;"><?php esc_html_e('Act', 'cig'); ?></th>
                            <th style="text-align:center; width:50px;"><?php esc_html_e('View', 'cig'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cig-acc-tbody">
                        <tr><td colspan="12" style="text-align:center;padding:20px;">Loading...</td></tr>
                    </tbody>
                </table>
                <div class="cig-acc-pagination" id="cig-acc-pagination"></div>
            </div>
        </div>

        <div id="cig-note-modal" class="cig-modal" style="display:none;">
            <div class="cig-modal-content">
                <span class="cig-modal-close">&times;</span>
                <h3 style="margin-top:0; border-bottom:1px solid #eee; padding-bottom:10px;">
                    <?php esc_html_e('Notes / Comments', 'cig'); ?> <span id="cig-modal-invoice-num" style="color:#50529d;"></span>
                </h3>
                <div class="cig-modal-body" style="padding-top:10px;">
                    <div style="margin-bottom:20px;">
                        <label style="font-weight:bold; display:block; margin-bottom:5px; color:#555; display:flex; align-items:center; gap:5px;">
                            <span class="dashicons dashicons-admin-users"></span> <?php esc_html_e('Consultant Note:', 'cig'); ?>
                        </label>
                        <div id="cig-consultant-display" style="background:#f9f9f9; border:1px solid #eee; padding:10px; border-radius:4px; min-height:40px; color:#333; font-style:italic;"></div>
                    </div>
                    <hr style="border:0; border-top:1px dashed #ddd; margin:15px 0;">
                    <label for="cig-acc-note-input" style="font-weight:bold; display:block; margin-bottom:5px; display:flex; align-items:center; gap:5px;">
                        <span class="dashicons dashicons-format-chat"></span> <?php esc_html_e('Accountant Note:', 'cig'); ?>
                    </label>
                    <textarea id="cig-acc-note-input" rows="5" style="width:100%; border:1px solid #aaa; padding:10px; font-family:inherit;" placeholder="Add internal comment..."></textarea>
                    <div style="text-align:right; margin-top:20px;">
                        <button type="button" id="cig-save-note" class="button button-primary button-large"><?php esc_html_e('Save Note', 'cig'); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <div id="cig-confirm-modal" class="cig-modal" style="display:none; z-index:10001;">
            <div class="cig-modal-content" style="width:400px; padding:20px; text-align:center;">
                <h3 style="margin-top:0; color:#50529d;"><?php esc_html_e('Confirmation', 'cig'); ?></h3>
                <p id="cig-confirm-msg" style="font-size:15px; margin:20px 0; line-height:1.5;"></p>
                <div class="cig-confirm-btns" style="display:flex; justify-content:center; gap:15px;">
                    <button type="button" id="cig-confirm-yes" class="button button-primary button-large" style="background:#28a745; border-color:#28a745;"><?php esc_html_e('დიახ / Yes', 'cig'); ?></button>
                    <button type="button" id="cig-confirm-no" class="button button-secondary button-large"><?php esc_html_e('არა / No', 'cig'); ?></button>
                </div>
            </div>
        </div>
        
        <div id="cig-info-modal" class="cig-modal" style="display:none; z-index:10002;">
            <div class="cig-modal-content" style="width:450px; padding:25px; border-left: 5px solid #f39c12;">
                <span class="cig-modal-close cig-info-close" style="margin-top:-10px;">&times;</span>
                <h3 style="margin-top:0; color:#e67e22; display:flex; align-items:center; gap:10px;">
                    <span class="dashicons dashicons-warning" style="font-size:24px;"></span> 
                    <?php esc_html_e('Status Information', 'cig'); ?>
                </h3>
                <div id="cig-info-body" style="font-size:14px; line-height:1.6; color:#333; margin-top:15px;"></div>
                <div style="text-align:right; margin-top:20px;">
                    <button type="button" class="button button-secondary cig-info-close"><?php esc_html_e('Close', 'cig'); ?></button>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var btn      = document.getElementById('cig-acc-stock-btn');
            var progress = document.getElementById('cig-acc-stock-progress');
            var spinner  = document.getElementById('cig-acc-stock-spinner');
            var bar      = document.getElementById('cig-acc-stock-bar');
            var txt      = document.getElementById('cig-acc-stock-text');
            var pct      = document.getElementById('cig-acc-stock-pct');
            var dlLink   = document.getElementById('cig-acc-stock-download');
            var ajaxUrl  = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
            var nonce    = '<?php echo esc_js(wp_create_nonce("cig_acc_stock")); ?>';

            btn.addEventListener('click', function(){
                btn.disabled = true;
                dlLink.style.display = 'none';
                progress.classList.add('active');
                spinner.className = 'cig-stock-spinner';
                bar.className = '';
                bar.style.width = '0';
                txt.textContent = 'Counting products\u2026';
                pct.textContent = '';

                post('cig_acc_stock_start', {}, function(data){
                    if(!data.success){
                        showError(data.data || 'Failed to start.');
                        return;
                    }
                    var total = data.data.total;
                    var batch = data.data.batch;
                    var processed = 0;
                    txt.textContent = 'Exporting\u2026';
                    pct.textContent = '0 / ' + total;

                    function nextBatch(){
                        post('cig_acc_stock_batch', {offset: processed}, function(data){
                            if(!data.success){
                                showError(data.data || 'Batch failed.');
                                return;
                            }
                            processed += batch;
                            var prog = Math.min(processed, total);
                            var percent = Math.round((prog / total) * 100);
                            bar.style.width = percent + '%';
                            pct.textContent = prog + ' / ' + total + '  (' + percent + '%)';

                            if(data.data.done){
                                bar.style.width = '100%';
                                bar.classList.add('done');
                                spinner.classList.add('done');
                                txt.textContent = 'Export complete';
                                pct.textContent = total + ' products';
                                btn.disabled = false;

                                dlLink.href = data.data.download_url;
                                dlLink.style.display = 'inline-flex';

                                setTimeout(function(){ progress.classList.remove('active'); }, 2000);
                            } else {
                                nextBatch();
                            }
                        });
                    }
                    nextBatch();
                });
            });

            function showError(msg){
                bar.style.width = '100%';
                bar.classList.add('error');
                spinner.classList.add('done');
                txt.textContent = msg;
                txt.style.color = '#d63638';
                pct.textContent = '';
                btn.disabled = false;
                setTimeout(function(){
                    txt.style.color = '';
                    progress.classList.remove('active');
                    bar.classList.remove('error');
                }, 4000);
            }

            function post(action, params, cb){
                var fd = new FormData();
                fd.append('action', action);
                fd.append('nonce', nonce);
                for(var k in params) fd.append(k, params[k]);
                fetch(ajaxUrl, {method:'POST', body:fd, credentials:'same-origin'})
                    .then(function(r){ return r.json(); })
                    .then(cb)
                    .catch(function(e){ showError(e.message); });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Accountant Log & Review', 'cig'); ?></h1>
            <hr class="wp-header-end">
            <p><?php esc_html_e('List of invoices marked as "Uploaded to RS", "Corrected", or "Receipt Checked".', 'cig'); ?></p>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 100px;"><?php esc_html_e('Date', 'cig'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Sale Date', 'cig'); ?></th>
                        <th style="width: 130px;"><?php esc_html_e('Invoice #', 'cig'); ?></th>
                        <th><?php esc_html_e('Client', 'cig'); ?></th> <th><?php esc_html_e('Payment', 'cig'); ?></th>
                        <th><?php esc_html_e('Total', 'cig'); ?></th>
                        <th><?php esc_html_e('Discount', 'cig'); ?></th>
                        <th style="text-align:center;"><?php esc_html_e('RS', 'cig'); ?></th>
                        <th style="text-align:center;"><?php esc_html_e('Credit', 'cig'); ?></th>
                        <th style="text-align:center;"><?php esc_html_e('Receipt', 'cig'); ?></th>
                        <th style="text-align:center;"><?php esc_html_e('Corrected', 'cig'); ?></th>
                        <th><?php esc_html_e('Consultant Note', 'cig'); ?></th>
                        <th><?php esc_html_e('Accountant Note', 'cig'); ?></th>
                        <th style="width: 80px;"><?php esc_html_e('Actions', 'cig'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
                    $args = [
                        'post_type'      => 'invoice', 
                        'post_status'    => 'publish', 
                        'posts_per_page' => 20, 
                        'paged'          => $paged,
                        'meta_query'     => [ 
                            'relation' => 'OR', 
                            ['key' => '_cig_acc_status', 'compare' => 'EXISTS'], // New Logic
                            // Fallback for old data
                            ['key' => '_cig_rs_uploaded', 'value' => 'yes', 'compare' => '='], 
                            ['key' => '_cig_accountant_is_corrected', 'value' => 'yes', 'compare' => '='], 
                            ['key' => '_cig_acc_full_check', 'value' => 'yes', 'compare' => '='] 
                        ],
                        'orderby'  => 'date', 
                        'order'    => 'DESC'
                    ];
                    
                    $query = new WP_Query($args);
                    
                    // Payment Labels
                    $method_labels = [
                        'company_transfer' => __('კომპანია', 'cig'),
                        'cash'             => __('ქეში', 'cig'),
                        'consignment'      => __('კონსიგნაცია', 'cig'),
                        'credit'           => __('განვადება', 'cig'),
                        'other'            => __('სხვა', 'cig'),
                        'mixed'            => __('შერეული', 'cig')
                    ];
                    
                    if ($query->have_posts()) {
                        while ($query->have_posts()) {
                            $query->the_post();
                            $post_id = get_the_ID();
                            
                            // Get invoice data from CIG_Invoice_Manager (uses custom tables with fallback)
                            $invoice_data = $this->invoice_manager->get_invoice_by_post_id($post_id);
                            $invoice = $invoice_data['invoice'] ?? [];
                            $payments = $invoice_data['payments'] ?? [];
                            $customer = $invoice_data['customer'] ?? [];
                            
                            $inv_num = $invoice['invoice_number'] ?? '';
                            $total = floatval($invoice['total_amount'] ?? 0);
                            
                            // Statuses (checking is_rs_uploaded from custom table, fallback to meta for acc_status)
                            $st = get_post_meta($post_id, '_cig_acc_status', true);
                            $is_rs = ($st === 'rs') || !empty($invoice['is_rs_uploaded']);
                            $is_credit = ($st === 'credit');
                            $is_corrected = ($st === 'corrected') || (get_post_meta($post_id, '_cig_accountant_is_corrected', true) === 'yes');
                            $is_receipt = ($st === 'receipt') || (get_post_meta($post_id, '_cig_acc_full_check', true) === 'yes');
                            
                            $acc_note = get_post_meta($post_id, '_cig_accountant_note', true);
                            $cons_note = $invoice['general_note'] ?? '';

                            // Client from customer data
                            $buyer_name = $customer['name'] ?? '—';
                            $buyer_tax = $customer['tax_id'] ?? '';

                            // Payment Logic from payments array
                            $payment_str = '—';
                            if (!empty($payments)) {
                                $sums = [];
                                foreach ($payments as $h) {
                                    $m = $h['method'] ?? 'other';
                                    $lbl = $method_labels[$m] ?? $m;
                                    if (!isset($sums[$lbl])) $sums[$lbl] = 0;
                                    $sums[$lbl] += floatval($h['amount'] ?? 0);
                                }
                                if (count($sums) === 1) {
                                    $payment_str = esc_html(array_keys($sums)[0]);
                                } else {
                                    $title = implode(' + ', array_keys($sums));
                                    $desc_parts = [];
                                    foreach ($sums as $lbl => $amt) $desc_parts[] = $lbl . ' ' . number_format($amt, 0) . ' ₾';
                                    $payment_str = '<strong>' . esc_html($title) . '</strong><div style="font-size:11px; color:#666;">(' . implode(', ', $desc_parts) . ')</div>';
                                }
                            }
                            
                            // Use sale_date if available, otherwise post date
                            $display_date = !empty($invoice['sale_date']) ? $invoice['sale_date'] : get_the_date('Y-m-d H:i');
                            if (!empty($invoice['sale_date'])) {
                                $display_date = date('Y-m-d H:i', strtotime($invoice['sale_date']));
                            }
                            
                            // Get sold_date for display
                            $sold_date = $invoice['sold_date'] ?? '';
                            
                            echo '<tr>';
                            echo '<td>' . esc_html($display_date) . '</td>';
                            echo '<td>' . ($sold_date ? esc_html($sold_date) : '—') . '</td>';
                            echo '<td><a href="' . get_permalink($post_id) . '" target="_blank"><strong>' . esc_html($inv_num) . '</strong></a></td>';
                            
                            // Client
                            echo '<td><strong>' . esc_html($buyer_name) . '</strong>' . ($buyer_tax ? '<div style="font-size:11px; color:#666;">ID: ' . esc_html($buyer_tax) . '</div>' : '') . '</td>';

                            // Payment
                            echo '<td>' . $payment_str . '</td>';

                            echo '<td>' . esc_html(number_format($total, 2)) . ' ₾</td>';

                            // Discount from items
                            $inv_items = $invoice_data['items'] ?? [];
                            $inv_discount = 0;
                            foreach ($inv_items as $ii) {
                                $ii_price = floatval($ii['price'] ?? 0);
                                $ii_orig = floatval($ii['original_price'] ?? $ii_price);
                                $ii_qty = floatval($ii['quantity'] ?? $ii['qty'] ?? 0);
                                $ii_st = $ii['item_status'] ?? $ii['status'] ?? 'none';
                                if ($ii_orig > $ii_price && $ii_st !== 'canceled') {
                                    $inv_discount += ($ii_orig - $ii_price) * $ii_qty;
                                }
                            }
                            echo '<td>' . ($inv_discount > 0.01 ? '<span style="color:#856404; font-weight:bold;">' . esc_html(number_format($inv_discount, 2)) . ' ₾</span>' : '—') . '</td>';

                            // Statuses
                            echo '<td style="text-align:center;">' . ($is_rs ? '<span class="dashicons dashicons-cloud-saved" style="color:#28a745;" title="Uploaded"></span>' : '—') . '</td>';
                            echo '<td style="text-align:center;">' . ($is_credit ? '<span class="dashicons dashicons-calendar-alt" style="color:#6c757d;" title="Credit / Installment"></span>' : '—') . '</td>';
                            echo '<td style="text-align:center;">' . ($is_receipt ? '<span class="dashicons dashicons-yes-alt" style="color:#28a745;" title="Checked"></span>' : '—') . '</td>';
                            echo '<td style="text-align:center;">' . ($is_corrected ? '<span class="dashicons dashicons-warning" style="color:#f39c12;" title="Corrected"></span>' : '—') . '</td>';
                            
                            // Notes
                            echo '<td>' . ($cons_note ? '<div style="background:#f0f0f1; padding:4px; font-size:11px; border-radius:3px;">'.esc_html(mb_strimwidth($cons_note, 0, 30, '...')).'</div>' : '—') . '</td>';
                            echo '<td>' . ($acc_note ? '<div style="background:#fff8e1; padding:4px; font-size:11px; border-radius:3px; color:#856404;">'.esc_html($acc_note).'</div>' : '—') . '</td>';
                            
                            echo '<td><a href="' . get_permalink($post_id) . '" class="button button-small" target="_blank">View</a></td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="14">' . esc_html__('No relevant invoices found.', 'cig') . '</td></tr>';
                    }
                    wp_reset_postdata();
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Stock Export (Accountant) ──────────────────────────────────────────

    private function ensure_export_dir() {
        $dir = wp_upload_dir()['basedir'] . '/cig_exports/';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! file_exists( $dir . '.htaccess' ) ) {
            file_put_contents( $dir . '.htaccess', "Order deny,allow\nDeny from all\n" );
        }
        if ( ! file_exists( $dir . 'index.php' ) ) {
            file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' );
        }
        return $dir;
    }

    public function ajax_stock_start() {
        check_ajax_referer( 'cig_acc_stock', 'nonce' );
        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }
        if ( ! function_exists( 'wcis_get_product_count' ) || ! class_exists( 'WCIS_XLSX_Writer' ) ) {
            wp_send_json_error( 'Stock snapshot plugin is not active.' );
        }

        global $wpdb;
        $total = wcis_get_product_count( $wpdb );
        if ( ! $total ) {
            wp_send_json_error( 'No products found.' );
        }

        $filename = 'stock-' . wp_date( 'd-F-Y_H-i' ) . '.xlsx';
        $xlsx     = new WCIS_XLSX_Writer();
        $xlsx->add_row( array( 'ID', 'SKU', 'Name', 'Stock', 'Reserved', 'Available', 'Price', 'Upload Date' ) );

        set_transient( 'cig_acc_export_file', $filename, 600 );
        set_transient( 'cig_acc_export_xlsx', $xlsx, 600 );

        wp_send_json_success( array(
            'total' => $total,
            'batch' => defined( 'WCIS_BATCH_SIZE' ) ? WCIS_BATCH_SIZE : 200,
        ) );
    }

    public function ajax_stock_batch() {
        check_ajax_referer( 'cig_acc_stock', 'nonce' );
        if ( ! current_user_can( 'read' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $offset   = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $filename = get_transient( 'cig_acc_export_file' );
        $xlsx     = get_transient( 'cig_acc_export_xlsx' );

        if ( ! $filename || ! $xlsx || ! ( $xlsx instanceof WCIS_XLSX_Writer ) ) {
            wp_send_json_error( 'Export session expired. Please try again.' );
        }

        global $wpdb;
        $batch_size = defined( 'WCIS_BATCH_SIZE' ) ? WCIS_BATCH_SIZE : 200;
        $rows       = $this->fetch_batch_with_date( $wpdb, $offset, $batch_size );

        $written = 0;
        if ( ! empty( $rows ) ) {
            $written = $this->write_rows_with_date( $wpdb, $xlsx, $rows );
        }

        $done = ( count( $rows ) < $batch_size );

        if ( $done ) {
            $dir = $this->ensure_export_dir();

            // Remove previous accountant exports.
            $old = glob( $dir . 'stock-*.xlsx' );
            if ( $old ) {
                foreach ( $old as $f ) {
                    @unlink( $f );
                }
            }

            $xlsx->save( $dir . sanitize_file_name( $filename ) );

            delete_transient( 'cig_acc_export_file' );
            delete_transient( 'cig_acc_export_xlsx' );

            wp_send_json_success( array(
                'written'      => $written,
                'done'         => true,
                'download_url' => admin_url( 'admin-ajax.php?action=cig_acc_stock_download&file=' . urlencode( $filename ) . '&nonce=' . wp_create_nonce( 'cig_acc_dl' ) ),
            ) );
        } else {
            set_transient( 'cig_acc_export_xlsx', $xlsx, 600 );
            wp_send_json_success( array(
                'written' => $written,
                'done'    => false,
            ) );
        }
    }

    public function ajax_stock_download() {
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'cig_acc_dl' ) ) {
            wp_die( 'Invalid request.' );
        }
        if ( ! current_user_can( 'read' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $file = sanitize_file_name( $_GET['file'] ?? '' );
        $path = wp_upload_dir()['basedir'] . '/cig_exports/' . $file;

        if ( ! $file || ! file_exists( $path ) ) {
            wp_die( 'File not found.' );
        }

        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $file . '"' );
        header( 'Content-Length: ' . filesize( $path ) );
        readfile( $path );
        exit;
    }

    /**
     * Fetch batch with post_date included (accountant-only query).
     */
    private function fetch_batch_with_date( $wpdb, $offset, $limit ) {
        $lang = defined( 'WCIS_LANG' ) ? WCIS_LANG : 'ka-ge';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                p.ID,
                p.post_title AS name,
                p.post_type,
                p.post_parent,
                p.post_date,
                MAX(CASE WHEN pm.meta_key = '_sku'   THEN pm.meta_value END) AS sku,
                COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS SIGNED), 0) AS stock,
                MAX(CASE WHEN pm.meta_key = '_price'  THEN pm.meta_value END) AS price
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}icl_translations t
                ON t.element_id = p.ID
                AND t.element_type = CONCAT('post_', p.post_type)
                AND t.language_code = %s
            LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
                AND pm.meta_key IN ('_sku', '_stock', '_price')
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status = 'publish'
            GROUP BY p.ID
            ORDER BY
                CASE WHEN COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS SIGNED), 0) > 0 THEN 0 ELSE 1 END,
                COALESCE(CAST(MAX(CASE WHEN pm.meta_key = '_stock' THEN pm.meta_value END) AS SIGNED), 0) DESC
            LIMIT %d OFFSET %d",
            $lang,
            $limit,
            $offset
        ) );
    }

    /**
     * Write rows with Upload Date column (accountant-only).
     */
    private function write_rows_with_date( $wpdb, $xlsx, $rows ) {
        $all_ids       = array();
        $variation_ids = array();
        $parent_ids    = array();

        foreach ( $rows as $row ) {
            $all_ids[] = (int) $row->ID;
            if ( $row->post_type === 'product_variation' ) {
                $variation_ids[] = (int) $row->ID;
                $parent_ids[ $row->post_parent ] = true;
            }
        }

        // Batch-fetch reserved stock.
        $reserved_map = array();
        if ( $all_ids ) {
            $placeholders  = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
            $reserved_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$placeholders})
                   AND meta_key = '_cig_reserved_stock'",
                ...$all_ids
            ) );
            foreach ( $reserved_rows as $rr ) {
                $data = maybe_unserialize( $rr->meta_value );
                $qty  = 0;
                if ( is_array( $data ) ) {
                    foreach ( $data as $entry ) {
                        if ( isset( $entry['qty'] ) ) {
                            $qty += (float) $entry['qty'];
                        }
                    }
                }
                $reserved_map[ $rr->post_id ] = (int) $qty;
            }
        }

        // Batch-fetch variation attributes.
        $variation_attrs = array();
        if ( $variation_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) );
            $attr_rows    = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE post_id IN ({$placeholders})
                   AND meta_key LIKE 'attribute_%%'
                   AND meta_value != ''",
                ...$variation_ids
            ) );
            foreach ( $attr_rows as $ar ) {
                $variation_attrs[ $ar->post_id ][] = urldecode( $ar->meta_value );
            }
        }

        $count = 0;
        foreach ( $rows as $row ) {
            if ( $row->post_type === 'product' && isset( $parent_ids[ $row->ID ] ) ) {
                continue;
            }

            $sku = $row->sku ?: '';
            if ( $sku && stripos( $sku, 'GN20ST' ) === 0 ) {
                continue;
            }

            $stock    = (int) $row->stock;
            $reserved = isset( $reserved_map[ $row->ID ] ) ? $reserved_map[ $row->ID ] : 0;
            $name     = $row->name;

            if ( $row->post_type === 'product_variation' && isset( $variation_attrs[ $row->ID ] ) ) {
                $name .= ' — ' . implode( ', ', $variation_attrs[ $row->ID ] );
            }

            $upload_date = $row->post_date ? date( 'Y-m-d', strtotime( $row->post_date ) ) : '';

            $xlsx->add_row( array(
                (int) $row->ID,
                $sku,
                $name,
                $stock,
                $reserved,
                $stock - $reserved,
                ( $row->price ? $row->price . ' ₾' : '0 ₾' ),
                $upload_date,
            ) );
            $count++;
        }

        return $count;
    }
}