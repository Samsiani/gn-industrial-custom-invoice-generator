<?php
if (!defined('ABSPATH')) { exit; }

// Settings & Globals
$settings = isset($settings) ? $settings : get_option('cig_settings', []);
$company_logo = isset($settings['company_logo']) ? $settings['company_logo'] : '';
$company_name = isset($settings['company_name']) ? $settings['company_name'] : '';
$company_tax  = isset($settings['company_tax_id']) ? $settings['company_tax_id'] : '';
$address      = isset($settings['address']) ? $settings['address'] : '';
$phone        = isset($settings['phone']) ? $settings['phone'] : '';
$email        = isset($settings['email']) ? $settings['email'] : '';
$website      = isset($settings['website']) ? $settings['website'] : '';

$bank1_logo    = isset($settings['bank1_logo']) ? $settings['bank1_logo'] : '';
$bank1_name    = isset($settings['bank1_name']) ? $settings['bank1_name'] : '';
$bank1_account = isset($settings['bank1_account']) ? $settings['bank1_account'] : '';

$bank2_logo    = isset($settings['bank2_logo']) ? $settings['bank2_logo'] : '';
$bank2_name    = isset($settings['bank2_name']) ? $settings['bank2_name'] : '';
$bank2_account = isset($settings['bank2_account']) ? $settings['bank2_account'] : '';

$bank3_logo    = isset($settings['bank3_logo']) ? $settings['bank3_logo'] : '';
$bank3_name    = isset($settings['bank3_name']) ? $settings['bank3_name'] : '';
$bank3_account = isset($settings['bank3_account']) ? $settings['bank3_account'] : '';

$director_name = isset($settings['director_name']) ? $settings['director_name'] : '';
$signature_img = isset($settings['director_signature']) ? $settings['director_signature'] : '';
$default_reservation_days = isset($settings['default_reservation_days']) ? intval($settings['default_reservation_days']) : 30;

$current_user = wp_get_current_user();
?>
<div class="cig-invoice-generator">
  <?php include CIG_TEMPLATES_DIR . 'partials/mini-dashboard.php'; ?>

  <div class="invoice-wrapper">
    <div class="invoice-top-bar">
      <div class="company-logo">
        <?php if ($company_logo): ?>
          <img src="<?php echo esc_url($company_logo); ?>" alt="Company Logo">
        <?php endif; ?>
      </div>
      <div style="text-align:right;">
        <h2 class="invoice-title">
          <?php esc_html_e('Invoice #', 'cig'); ?>
          <input type="text" value="" id="invoice-number" placeholder="N25000000">
        </h2>
        <div class="invoice-sold-date-wrapper no-print" style="margin-top:10px; font-size:13px;">
          <label for="invoice-sold-date" style="font-weight:600; color:#555; margin-right:5px;"><?php esc_html_e('Sold Date:', 'cig'); ?></label>
          <input type="date" id="invoice-sold-date" value="" style="padding:4px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px;">
        </div>
      </div>
    </div>

    <div class="color-bar"></div>

    <header class="invoice-header">
      <div class="company-details">
        <?php if ($company_name): ?><strong><?php echo esc_html($company_name); ?></strong><br><?php endif; ?>
        <?php if ($company_tax): ?><strong><?php echo esc_html($company_tax); ?></strong><?php endif; ?>
        <ul>
          <?php if ($address): ?><li class="address"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg><?php echo esc_html($address); ?></li><?php endif; ?>
          <?php if ($phone): ?><li class="phone"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.24.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg><?php echo esc_html($phone); ?></li><?php endif; ?>
          <?php if ($email): ?><li class="email"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg><?php echo esc_html($email); ?></li><?php endif; ?>
          <?php if ($website): ?><li class="web"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1h-2v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 3.88-2.67 7.19-6.35 7.93z"/></svg><?php echo esc_html($website); ?></li><?php endif; ?>
        </ul>
      </div>

      <div class="buyer-details">
        <strong class="editable-field is-empty" data-placeholder="<?php esc_attr_e('Buyer Name...', 'cig'); ?>" id="buyer-name-display"><?php esc_html_e('Buyer Name...', 'cig'); ?></strong><br>
        <strong class="editable-field is-empty" data-placeholder="<?php esc_attr_e('Tax ID...', 'cig'); ?>" id="buyer-tax-display"><?php esc_html_e('Tax ID...', 'cig'); ?></strong>
        <ul>
          <li class="is-empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg><span class="editable-value" data-placeholder="<?php esc_attr_e('Enter address...', 'cig'); ?>" id="buyer-address-display"><?php esc_html_e('Enter address...', 'cig'); ?></span></li>
          <li class="is-empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.24.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg><span class="editable-value" data-placeholder="<?php esc_attr_e('Enter phone...', 'cig'); ?>" id="buyer-phone-display"><?php esc_html_e('Enter phone...', 'cig'); ?></span></li>
          <li class="is-empty"><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg><span class="editable-value" data-placeholder="<?php esc_attr_e('Enter email...', 'cig'); ?>" id="buyer-email-display"><?php esc_html_e('Enter email...', 'cig'); ?></span></li>
        </ul>
      </div>
    </header>

    <div class="color-bar"></div>

    <table id="invoice-table">
      <thead>
        <tr>
          <th class="col-n"><?php esc_html_e('N', 'cig'); ?></th>
          <th class="col-name"><?php esc_html_e('Name', 'cig'); ?></th>
          <th class="col-image"><?php esc_html_e('Image', 'cig'); ?></th>
          <th class="col-brand"><?php esc_html_e('Brand', 'cig'); ?></th>
          <th class="col-desc"><?php esc_html_e('Specifications', 'cig'); ?></th>
          <th class="col-qty"><?php esc_html_e('Qt.', 'cig'); ?></th>
          <th class="col-price"><?php esc_html_e('Price', 'cig'); ?></th>
          <th class="col-total"><?php esc_html_e('Total', 'cig'); ?></th>
          <th class="col-status no-print"><?php esc_html_e('Status', 'cig'); ?></th>
          <th class="col-actions no-print">*</th>
        </tr>
      </thead>
      <tbody id="invoice-items">
        <tr>
          <td class="col-n">1</td>
          <td class="col-name">
            <input type="text" class="product-search" placeholder="<?php esc_attr_e('Search SKU or Name', 'cig'); ?>" data-product-id="0" data-sku="">
            <div class="name-sub">
              <span class="name-sku-label"><?php esc_html_e('Code:', 'cig'); ?></span>
              <span class="name-sku-value">—</span>
            </div>
          </td>
          <td class="col-image">
            <img src="<?php echo esc_url(CIG_ASSETS_URL . 'img/placeholder-80x70.png'); ?>" alt="Product" class="product-image cig-placeholder-img">
            <select class="warranty-period" style="width:100%; margin-top:5px; font-size:10px; border:1px solid #ccc; border-radius:3px;">
                <option value="">---</option>
                <option value="6m"><?php esc_html_e('6 Months', 'cig'); ?></option>
                <option value="1y"><?php esc_html_e('1 Year', 'cig'); ?></option>
                <option value="2y"><?php esc_html_e('2 Years', 'cig'); ?></option>
                <option value="3y"><?php esc_html_e('3 Years', 'cig'); ?></option>
            </select>
          </td>
          <td class="col-brand"><input type="text" class="product-brand" readonly></td>
          <td class="col-desc"><textarea class="product-desc" rows="1"></textarea></td>
          <td class="col-qty">
            <div class="quantity-wrapper">
              <input type="number" class="quantity" value="1" min="1">
              <div class="qty-btn-group">
                <button type="button" class="qty-btn qty-increase">▲</button>
                <button type="button" class="qty-btn qty-decrease">▼</button>
              </div>
            </div>
          </td>
          <td class="col-price"><input type="number" class="price" value="0.00" step="0.01"><span class="cig-original-price" style="display:none;"></span></td>
          <td class="col-total"><input type="text" class="row-total" value="0.00" readonly></td>
          <td class="col-status no-print">
            <select class="product-status">
              <option value="none">---</option> <option value="sold"><?php esc_html_e('Sold', 'cig'); ?></option>
              <option value="reserved"><?php esc_html_e('Reserved', 'cig'); ?></option>
              <option value="canceled"><?php esc_html_e('Canceled', 'cig'); ?></option>
            </select>
            <input type="number" class="reservation-days" min="1" max="90" value="<?php echo esc_attr($default_reservation_days); ?>" placeholder="Days" style="width:60px;margin-top:3px;display:none;">
          </td>
          <td class="col-actions no-print"><button type="button" class="btn-remove-row">X</button></td>
        </tr>
      </tbody>
    </table>

    <div class="invoice-actions">
      <button type="button" class="btn-add-row" id="btn-add-row">+ <?php esc_html_e('Add Row', 'cig'); ?></button>
    </div>

    <div class="invoice-summary">
      <table class="totals-table">
        <tbody>
          <tr>
            <td><?php esc_html_e('Total', 'cig'); ?></td>
            <td id="grand-total">0.00</td>
          </tr>
          <tr>
            <td colspan="2" style="font-size: 12px; text-align: right;"><?php esc_html_e('Price includes VAT', 'cig'); ?></td>
          </tr>
        </tbody>
      </table>
      <div id="cig-discount-summary" class="cig-discount-summary" style="display:none;"></div>
    </div>

    <div class="invoice-payment-section no-print" style="margin-top:20px; padding:0; border:none; background:transparent;">
      
      <div class="cig-payment-module" style="background:#fff; border:1px solid #ddd; border-radius:6px; padding:20px; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
          
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
              <h3 style="margin:0; font-size:15px; color:#333; font-weight:600;">
                  <span class="dashicons dashicons-money-alt" style="vertical-align:middle; color:#50529d;"></span> 
                  <?php esc_html_e('გადახდები (Payment History)', 'cig'); ?>
              </h3>
              <div style="display:flex; gap:15px; font-size:13px;">
                  <div style="background:#f8f9fa; padding:5px 10px; border-radius:4px; border:1px solid #ddd;">
                      <span style="color:#666;"><?php esc_html_e('სულ:', 'cig'); ?></span> 
                      <strong id="disp-grand-total">0.00</strong>
                  </div>
                  <div id="disp-paid-row" style="background:#e8f5e9; padding:5px 10px; border-radius:4px; border:1px solid #c3e6cb;">
                      <span id="disp-paid-label" style="color:#155724;"><?php esc_html_e('გადახდილი:', 'cig'); ?></span> 
                      <strong id="disp-paid-total" style="color:#155724;">0.00</strong>
                  </div>
                  <div id="disp-consignment-row" style="background:#f0f0f0; padding:5px 10px; border-radius:4px; border:1px solid #ccc; display:none;">
                      <span style="color:#6c757d;"><?php esc_html_e('კონსიგნაცია:', 'cig'); ?></span> 
                      <strong id="disp-consignment-total" style="color:#6c757d;">0.00</strong>
                  </div>
                  <div id="disp-remaining-row" style="background:#fff5f5; padding:5px 10px; border-radius:4px; border:1px solid #f5c6cb;">
                      <span style="color:#721c24;"><?php esc_html_e('დარჩენილი:', 'cig'); ?></span> 
                      <strong id="disp-remaining" style="color:#721c24;">0.00</strong>
                  </div>
              </div>
          </div>

          <table class="cig-history-table" style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:20px;">
              <thead style="background:#f1f2f6; color:#555;">
                  <tr>
                      <th style="padding:10px; text-align:left; border-bottom:2px solid #ddd;"><?php esc_html_e('თარიღი', 'cig'); ?></th>
                      <th style="padding:10px; text-align:left; border-bottom:2px solid #ddd;"><?php esc_html_e('მეთოდი', 'cig'); ?></th>
                      <th style="padding:10px; text-align:right; border-bottom:2px solid #ddd;"><?php esc_html_e('თანხა', 'cig'); ?></th>
                      <th style="padding:10px; width:40px; border-bottom:2px solid #ddd;"></th>
                  </tr>
              </thead>
              <tbody id="cig-payment-history-tbody">
                  </tbody>
          </table>

          <div style="background:#f0f7ff; padding:15px; border-radius:6px; border:1px dashed #50529d;">
              <div style="font-size:12px; font-weight:bold; color:#50529d; margin-bottom:10px; text-transform:uppercase;">
                  <?php esc_html_e('+ ახალი გადახდის დამატება', 'cig'); ?>
              </div>
              
              <div class="cig-add-payment-row" style="display:flex; gap:15px; align-items:flex-end; flex-wrap:wrap;">
                  <div style="flex:1; min-width:140px;">
                      <label style="font-size:11px; display:block; margin-bottom:4px; color:#555;"><?php esc_html_e('თარიღი', 'cig'); ?></label>
                      <input type="date" id="new-pay-date" value="<?php echo current_time('Y-m-d'); ?>" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px;">
                  </div>
                  <div style="flex:1; min-width:140px;">
                      <label style="font-size:11px; display:block; margin-bottom:4px; color:#555;"><?php esc_html_e('გადახდის მეთოდი', 'cig'); ?></label>
                      <select id="new-pay-method" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; background:#fff;">
                          <option value="company_transfer"><?php esc_html_e('კომპანიის გადარიცხვა (Company)', 'cig'); ?></option>
                          <option value="cash"><?php esc_html_e('ქეში (Cash)', 'cig'); ?></option>
                          <option value="consignment"><?php esc_html_e('კონსიგნაცია (Consignment)', 'cig'); ?></option>
                          <option value="credit"><?php esc_html_e('განვადება (Credit)', 'cig'); ?></option>
                          <option value="other"><?php esc_html_e('სხვა (Other)', 'cig'); ?></option>
                      </select>
                  </div>
                  <div style="flex:1; min-width:140px;">
                      <label style="font-size:11px; display:block; margin-bottom:4px; color:#555;"><?php esc_html_e('თანხა', 'cig'); ?></label>
                      <input type="number" id="new-pay-amount" placeholder="0.00" step="0.01" min="0" style="width:100%; padding:6px; border:1px solid #ccc; border-radius:4px; font-weight:bold;">
                  </div>
                  <div style="display:flex; gap:5px;">
                      <button type="button" id="btn-pay-full" class="button" style="height:32px; font-size:12px; color:#28a745; border-color:#28a745; background:#fff;" title="<?php esc_attr_e('დარჩენილი თანხის სრულად დაფარვა', 'cig'); ?>">
                          <?php esc_html_e('სრულად', 'cig'); ?>
                      </button>
                      <button type="button" id="btn-add-payment" class="button button-primary" style="height:32px; font-size:12px;">
                          <?php esc_html_e('დამატება', 'cig'); ?>
                      </button>
                  </div>
              </div>
          </div>

      </div>
    </div>
    
    <div class="invoice-general-note-section no-print" style="margin-top:20px; padding:15px; background:#fff8e1; border:1px solid #ffeeba; border-radius:6px;">
        <label for="invoice-general-note" style="font-weight:600; color:#856404; display:block; margin-bottom:5px;">
            <span class="dashicons dashicons-edit" style="font-size:16px; vertical-align:middle;"></span> <?php esc_html_e('შენიშვნა / კომენტარი (Note):', 'cig'); ?>
        </label>
        <textarea id="invoice-general-note" style="width:100%; height:60px; padding:8px; border:1px solid #ffeeba; border-radius:4px; font-family:inherit;" placeholder="<?php esc_attr_e('ჩაწერეთ კომენტარი...', 'cig'); ?>"></textarea>
    </div>

    <footer class="invoice-footer-bank">
      <div class="bank-details">
        <strong><?php esc_html_e('Bank Details:', 'cig'); ?></strong>
        <div class="bank-cards-row">
        <?php if ($bank1_name || $bank1_account || $bank1_logo): ?>
          <div class="bank-card">
            <div class="bank-card-header">
              <?php if ($bank1_logo): ?><img class="bank-logo" src="<?php echo esc_url($bank1_logo); ?>" alt="<?php echo esc_attr($bank1_name); ?>"><?php endif; ?>
              <?php if ($bank1_name): ?><span class="bank-name"><?php echo esc_html($bank1_name); ?></span><?php endif; ?>
            </div>
            <?php if ($bank1_account): ?><div class="bank-iban"><?php echo esc_html($bank1_account); ?></div><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($bank2_name || $bank2_account || $bank2_logo): ?>
          <div class="bank-card">
            <div class="bank-card-header">
              <?php if ($bank2_logo): ?><img class="bank-logo" src="<?php echo esc_url($bank2_logo); ?>" alt="<?php echo esc_attr($bank2_name); ?>"><?php endif; ?>
              <?php if ($bank2_name): ?><span class="bank-name"><?php echo esc_html($bank2_name); ?></span><?php endif; ?>
            </div>
            <?php if ($bank2_account): ?><div class="bank-iban"><?php echo esc_html($bank2_account); ?></div><?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($bank3_name || $bank3_account || $bank3_logo): ?>
          <div class="bank-card">
            <div class="bank-card-header">
              <?php if ($bank3_logo): ?><img class="bank-logo" src="<?php echo esc_url($bank3_logo); ?>" alt="<?php echo esc_attr($bank3_name); ?>"><?php endif; ?>
              <?php if ($bank3_name): ?><span class="bank-name"><?php echo esc_html($bank3_name); ?></span><?php endif; ?>
            </div>
            <?php if ($bank3_account): ?><div class="bank-iban"><?php echo esc_html($bank3_account); ?></div><?php endif; ?>
          </div>
        <?php endif; ?>
        </div>
      </div>

      <div class="director-signature">
        <?php if ($director_name): ?><strong><?php echo esc_html(sprintf(__('Director: %s', 'cig'), $director_name)); ?></strong><?php endif; ?>
        <?php if ($signature_img): ?><img src="<?php echo esc_url($signature_img); ?>" alt="Signature" class="signature-image"><?php endif; ?>
      </div>
    </footer>

    <div class="invoice-final-actions">
      <button type="button" class="btn-save-invoice" id="btn-save-invoice"><?php esc_html_e('Save Invoice', 'cig'); ?></button>
      <button type="button" class="btn-print-invoice" id="btn-print-invoice"><?php esc_html_e('Print Invoice', 'cig'); ?></button>
    </div>
  </div>
</div>