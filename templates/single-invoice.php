<?php
/**
 * View-only invoice template (Elementor Canvas style)
 * Version: 4.9.3 (Removed Comment Column from Payment Table)
 */
if (!defined('ABSPATH')) exit;

// Init Setup
global $post;
$invoice_id = $post->ID;

// Settings & Meta
$settings = get_option('cig_settings', []);
$company_logo = $settings['company_logo'] ?? '';
$company_name = $settings['company_name'] ?? '';
$company_tax  = $settings['company_tax_id'] ?? '';
$address      = $settings['address'] ?? '';
$phone        = $settings['phone'] ?? '';
$email        = $settings['email'] ?? '';
$website      = $settings['website'] ?? '';
$bank1_logo   = $settings['bank1_logo'] ?? '';
$bank1_name   = $settings['bank1_name'] ?? '';
$bank1_account= $settings['bank1_account'] ?? '';
$bank2_logo   = $settings['bank2_logo'] ?? '';
$bank2_name   = $settings['bank2_name'] ?? '';
$bank2_account= $settings['bank2_account'] ?? '';
$bank3_logo   = $settings['bank3_logo'] ?? '';
$bank3_name   = $settings['bank3_name'] ?? '';
$bank3_account= $settings['bank3_account'] ?? '';
$director_name= $settings['director_name'] ?? '';
$signature_img= $settings['director_signature'] ?? '';

// Get invoice data from CIG_Invoice_Manager (uses custom tables with fallback to post meta)
$invoice_manager = CIG_Invoice_Manager::instance();
$invoice_data = $invoice_manager->get_invoice_by_post_id($invoice_id);
$invoice = $invoice_data['invoice'] ?? [];
$items = $invoice_data['items'] ?? [];
$payments = $invoice_data['payments'] ?? [];
$customer = $invoice_data['customer'] ?? [];

$invoice_number = $invoice['invoice_number'] ?? '';
$buyer = [
  'name'    => $customer['name'] ?? '',
  'tax_id'  => $customer['tax_id'] ?? '',
  'address' => $customer['address'] ?? '',
  'phone'   => $customer['phone'] ?? '',
  'email'   => $customer['email'] ?? '',
];

// Payment History from cig_payments table
$payment_history = $payments;

// General Note
$general_note = $invoice['general_note'] ?? '';

// Sold Date (for warranty sheet)
$sold_date = $invoice['sold_date'] ?? '';

$current_user = wp_get_current_user();
$can_edit = current_user_can('manage_woocommerce');
$can_view_payments = $can_edit || current_user_can('read');

// Get statuses
$current_status = $invoice['status'] ?? 'standard';
$is_rs_uploaded = !empty($invoice['is_rs_uploaded']);
$lifecycle      = $invoice['lifecycle_status'] ?? 'unfinished';

// Dates logic - use sale_date if available, fallback to created_at or post dates
$created_date = !empty($invoice['created_at']) ? date('Y-m-d H:i', strtotime($invoice['created_at'])) : get_the_date('Y-m-d H:i', $invoice_id);
$modified_date = get_the_modified_date('Y-m-d H:i', $invoice_id);
$is_updated = ($created_date !== $modified_date);

// Warranty Map
$warranty_map = [
    '6m' => __('6 თვე', 'cig'),
    '1y' => __('1 წელი', 'cig'),
    '2y' => __('2 წელი', 'cig'),
    '3y' => __('3 წელი', 'cig')
];

// Payment Methods Map
$payment_methods_map = [
    'company_transfer' => __('კომპანიის გადარიცხვა', 'cig'),
    'cash'             => __('ქეში', 'cig'),
    'consignment'      => __('კონსიგნაცია', 'cig'),
    'credit'           => __('განვადება', 'cig'),
    'other'            => __('სხვა', 'cig'),
    'mixed'            => __('შერეული', 'cig')
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html(get_the_title()); ?></title>
    <?php wp_head(); ?>
    <style>
        /* Force reset for canvas mode */
        body { background: #f0f0f0; margin: 0; padding: 20px; font-family: 'FiraGO', sans-serif; }
        @media print { body { background: #fff; padding: 0; } }
    </style>
</head>
<body <?php body_class(); ?>>

<?php if ($can_view_payments): // Mini Dashboard visible for authenticated users ?>
    <?php include CIG_TEMPLATES_DIR . 'partials/mini-dashboard.php'; ?>
<?php endif; ?>

<div class="invoice-wrapper" style="position: relative;">
  
  <?php if ($current_status === 'fictive'): ?>
    <div class="cig-fictive-stamp no-print" style="
        position: absolute; top: 20px; right: 20px; border: 4px solid #dc3545; color: #dc3545;
        padding: 10px 30px; font-weight: 900; font-size: 32px; text-transform: uppercase;
        transform: rotate(-15deg); opacity: 0.4; pointer-events: none; z-index: 0; letter-spacing: 2px;
    ">FICTIVE</div>
  <?php endif; ?>

  <div class="invoice-top-bar">
    <div class="company-logo">
      <?php if ($company_logo): ?><img src="<?php echo esc_url($company_logo); ?>" alt="Company Logo"><?php endif; ?>
    </div>
    <div style="text-align:right;">
        <h2 class="invoice-title"><?php esc_html_e('Invoice #','cig'); ?>
          <input type="text" value="<?php echo esc_attr($invoice_number); ?>" readonly>
        </h2>
        
        <div style="font-size:11px; color:#666; margin-top:5px;">
            <span style="font-weight:bold;">
                <?php esc_html_e('თარიღი:', 'cig'); ?> <?php echo esc_html($created_date); ?>
            </span>
            <?php if (!empty($sold_date)): ?>
            <span style="font-weight:bold; margin-left:15px;">
                <?php esc_html_e('Sold Date:', 'cig'); ?> <?php echo esc_html($sold_date); ?>
            </span>
            <?php endif; ?>
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
      <?php if (!empty($buyer['name'])): ?><strong class="editable-field"><?php echo esc_html($buyer['name']); ?></strong><br><?php endif; ?>
      <?php if (!empty($buyer['tax_id'])): ?><strong class="editable-field"><?php echo esc_html($buyer['tax_id']); ?></strong><?php endif; ?>
      <ul>
        <?php if (!empty($buyer['address'])): ?><li><svg class="icon-svg" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg><span class="editable-value"><?php echo esc_html($buyer['address']); ?></span></li><?php endif; ?>
        <?php if (!empty($buyer['phone'])): ?><li><svg class="icon-svg" viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.24.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg><span class="editable-value"><?php echo esc_html($buyer['phone']); ?></span></li><?php endif; ?>
        <?php if (!empty($buyer['email'])): ?><li><svg class="icon-svg" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg><span class="editable-value"><?php echo esc_html($buyer['email']); ?></span></li><?php endif; ?>
      </ul>
    </div>
  </header>

  <div class="color-bar"></div>

  <table id="invoice-table">
    <thead>
      <tr>
        <th class="col-n"><?php esc_html_e('N','cig'); ?></th>
        <th class="col-name"><?php esc_html_e('Name','cig'); ?></th>
        <th class="col-image"><?php esc_html_e('Image','cig'); ?></th>
        <th class="col-brand"><?php esc_html_e('Brand','cig'); ?></th>
        <th class="col-desc"><?php esc_html_e('Specifications','cig'); ?></th>
        <th class="col-qty"><?php esc_html_e('Qt.','cig'); ?></th>
        <th class="col-price"><?php esc_html_e('Price','cig'); ?></th>
        <th class="col-total"><?php esc_html_e('Total','cig'); ?></th>
        <th class="col-status no-print"><?php esc_html_e('Status','cig'); ?></th>
      </tr>
    </thead>
    <tbody id="invoice-items">
      <?php 
      $grand = 0;
      $total_discount = 0;
      foreach ($items as $idx=>$row):
        $n=$idx+1;
        // Support both custom table field names and legacy field names
        $name=$row['product_name'] ?? $row['name'] ?? '';
        $brand=$row['brand'] ?? '';
        $sku=$row['sku'] ?? '';
        $desc=$row['description'] ?? $row['desc'] ?? '';
        $image=$row['image'] ?? '';
        $qty=floatval($row['quantity'] ?? $row['qty'] ?? 0);
        $price=floatval($row['price'] ?? 0);
        $original_price=floatval($row['original_price'] ?? $price);
        $has_discount=($original_price > $price && $original_price > 0);
        $total=floatval($row['total'] ?? ($qty*$price));
        
        // --- STATUS LOGIC FOR VIEW ---
        $status=$row['item_status'] ?? $row['status'] ?? 'none';
        $reservation_days=$row['reservation_days'] ?? 0;
        $warranty_key=$row['warranty_duration'] ?? $row['warranty'] ?? '';
        
        if ($status !== 'canceled') {
            $grand += $total;
            if ($has_discount) {
                $total_discount += ($original_price - $price) * $qty;
            }
        }

        // Status Badge Logic
        $status_color = '#6c757d'; // Default Gray for 'none'
        $status_label = '---';
        
        if ($status === 'sold') {
            $status_color = '#28a745';
            $status_label = 'Sold';
        } elseif ($status === 'reserved') {
            $status_color = '#ffc107';
            $status_label = sprintf(__('Reserved (%s days)', 'cig'), $reservation_days);
        } elseif ($status === 'canceled') {
            $status_color = '#dc3545';
            $status_label = 'Canceled';
        }
        ?>
        <tr>
          <td class="col-n"><?php echo esc_html($n); ?></td>
          <td class="col-name">
            <div><?php echo esc_html($name); ?></div>
            <div class="name-sub">
              <span class="name-sku-label"><?php esc_html_e('Code:', 'cig'); ?></span>
              <span class="name-sku-value"><?php echo $sku !== '' ? esc_html($sku) : '—'; ?></span>
            </div>
          </td>
          <td class="col-image">
              <?php if ($image): ?><img src="<?php echo esc_url($image); ?>" alt=""><?php endif; ?>
              <div style="font-size:10px; margin-top:5px; color:#666; text-align:center;">
                  <?php echo isset($warranty_map[$warranty_key]) ? 'Warranty: ' . esc_html($warranty_map[$warranty_key]) : '---'; ?>
              </div>
          </td>
          <td class="col-brand"><?php echo esc_html($brand); ?></td>
          <td class="col-desc"><div style="white-space:pre-wrap;"><?php echo esc_html($desc); ?></div></td>
          <td class="col-qty"><?php echo esc_html($qty); ?></td>
          <td class="col-price">
            <?php if ($has_discount): ?>
              <del class="cig-original-price"><?php echo number_format($original_price,2,'.',''); ?></del>
              <span class="cig-discounted-price"><?php echo number_format($price,2,'.',''); ?></span>
            <?php else: ?>
              <?php echo number_format($price,2,'.',''); ?>
            <?php endif; ?>
          </td>
          <td class="col-total">
            <?php if ($has_discount): ?>
              <del class="cig-original-price"><?php echo number_format($original_price * $qty,2,'.',''); ?></del>
              <span class="cig-discounted-price"><?php echo number_format($total,2,'.',''); ?></span>
            <?php else: ?>
              <?php echo number_format($total,2,'.',''); ?>
            <?php endif; ?>
          </td>
          <td class="col-status no-print"><span class="status-badge" style="background:<?php echo esc_attr($status_color); ?>;color:#fff;padding:3px 8px;border-radius:3px;font-size:11px;font-weight:bold;white-space:nowrap;"><?php echo esc_html($status_label); ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php
  // Calculate Payment Totals from History - separate cash vs consignment
  $total_paid = 0;
  $cash_total = 0;
  $consignment_total = 0;
  if (!empty($payment_history)) {
      foreach ($payment_history as $pay) {
          $amount = floatval($pay['amount'] ?? 0);
          $method = strtolower($pay['method'] ?? '');
          $total_paid += $amount;
          if ($method === 'consignment') {
              $consignment_total += $amount;
          } else {
              $cash_total += $amount;
          }
      }
  }
  $remaining = $grand - $total_paid;
  if (abs($remaining) < 0.01) $remaining = 0;
  
  // Check if consignment payment exists
  $has_consignment = $consignment_total > 0;
  ?>

  <div class="invoice-summary">
    <table class="totals-table">
      <tbody>
        <tr>
            <td><?php esc_html_e('Total','cig'); ?></td>
            <td id="grand-total">
              <?php if ($total_discount > 0.01): ?>
                <del style="color:#999; font-size:13px; font-weight:normal;"><?php echo number_format($grand + $total_discount, 2, '.', '') . '&nbsp;&#8382;'; ?></del>
                <span style="font-weight:bold; font-size:16px; color:#333;"><?php echo number_format($grand, 2, '.', '') . '&nbsp;&#8382;'; ?></span>
                <span style="display:inline-block; margin-left:6px; background:#fff3cd; color:#856404; font-size:11px; font-weight:600; padding:2px 6px; border-radius:3px;">-<?php echo number_format($total_discount, 2, '.', ''); ?> &#8382;</span>
              <?php else: ?>
                <?php echo number_format($grand, 2, '.', '') . '&nbsp;&#8382;'; ?>
              <?php endif; ?>
            </td>
        </tr>

        <?php // Row 2: Show "Cash Paid" IF $cash_total > 0 ?>
        <?php if ($cash_total > 0): ?>
            <tr>
                <td style="font-size:13px; color:#28a745;"><?php esc_html_e('გადახდილია', 'cig'); ?></td>
                <td style="font-size:13px; font-weight:bold; color:#28a745;"><?php echo number_format($cash_total, 2, '.', '') . '&nbsp;&#8382;'; ?></td>
            </tr>
        <?php endif; ?>

        <?php // Row 3: Show "Consignment" IF $consignment_total > 0 ?>
        <?php if ($consignment_total > 0): ?>
            <tr>
                <td style="font-size:13px; color:#6c757d;"><?php esc_html_e('კონსიგნაცია', 'cig'); ?></td>
                <td style="font-size:13px; font-weight:bold; color:#6c757d;"><?php echo number_format($consignment_total, 2, '.', '') . '&nbsp;&#8382;'; ?></td>
            </tr>
        <?php endif; ?>

        <?php // Row 4: Show "Remaining/Due" ONLY IF $remaining > 0.01 ?>
        <?php if ($remaining > 0.01): ?>
            <tr>
                <td style="font-size:13px; color:#dc3545;"><?php esc_html_e('დარჩენილია', 'cig'); ?></td>
                <td style="font-size:13px; font-weight:bold; color:#dc3545;"><?php echo number_format($remaining, 2, '.', '') . '&nbsp;&#8382;'; ?></td>
            </tr>
        <?php elseif ($remaining < -0.01): ?>
            <tr>
                <td style="font-size:13px; color:#dc3545;"><?php esc_html_e('დარჩენილია', 'cig'); ?></td>
                <td style="font-size:13px; font-weight:bold; color:#dc3545;"><?php echo number_format($remaining, 2, '.', '') . '&nbsp;&#8382; (ზედმეტი)'; ?></td>
            </tr>
        <?php endif; ?>
        <tr><td colspan="2" style="text-align:right;font-size:12px;"><?php esc_html_e('Price includes VAT','cig'); ?></td></tr>
      </tbody>
    </table>
  </div>

  <?php if ($can_view_payments && !empty($payment_history)): ?>
  <div class="invoice-payment-info no-print" style="margin-top: 30px; background:#fff; border:1px solid #ddd; border-radius:6px; padding:20px; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
    <h3 style="margin:0 0 15px 0; font-size:15px; color:#333; font-weight:600; border-bottom:1px solid #eee; padding-bottom:10px;">
        <span class="dashicons dashicons-money-alt" style="vertical-align:middle; color:#50529d;"></span> 
        <?php esc_html_e('გადახდები', 'cig'); ?>
    </h3>

    <table class="cig-history-table" style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:15px;">
        <thead style="background:#f1f2f6; color:#555;">
            <tr>
                <th style="padding:10px; text-align:left; border-bottom:2px solid #ddd;"><?php esc_html_e('თარიღი', 'cig'); ?></th>
                <th style="padding:10px; text-align:left; border-bottom:2px solid #ddd;"><?php esc_html_e('მეთოდი', 'cig'); ?></th>
                <th style="padding:10px; text-align:right; border-bottom:2px solid #ddd;"><?php esc_html_e('თანხა', 'cig'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($payment_history as $pay): 
                $amt = floatval($pay['amount'] ?? 0);
                $m_key = $pay['method'] ?? 'other';
                $m_label = $payment_methods_map[$m_key] ?? $m_key;
            ?>
            <tr>
                <td style="padding:10px; border-bottom:1px solid #eee; color:#555;"><?php echo esc_html($pay['date'] ?? '-'); ?></td>
                <td style="padding:10px; border-bottom:1px solid #eee; color:#555;"><?php echo esc_html($m_label); ?></td>
                <td style="padding:10px; border-bottom:1px solid #eee; text-align:right; font-weight:bold; color:#333;">
                    <?php echo number_format($amt, 2); ?> ₾
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
  </div>
  <?php endif; ?>
  
  <?php if ($general_note): ?>
    <div class="invoice-general-note no-print" style="margin-top:20px; padding:15px; background:#fff8e1; border:1px solid #ffeeba; border-radius:6px; color:#856404;">
        <h4 style="margin:0 0 5px 0; font-size:13px; text-transform:uppercase;"><?php esc_html_e('Note:', 'cig'); ?></h4>
        <div style="white-space: pre-wrap; line-height: 1.5; font-size: 13px;"><?php echo esc_html($general_note); ?></div>
    </div>
  <?php endif; ?>

  <?php if ($can_edit): ?>
    <div class="cig-rs-action-panel no-print" style="margin-top: 20px; margin-bottom: 10px; padding: 12px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; display: inline-flex; align-items: center; gap: 15px;">
        <span style="font-weight:600; color:#333; font-size:13px;"><?php esc_html_e('RS.ge Status:', 'cig'); ?></span>
        
        <div class="cig-rs-toggle-wrap <?php echo $is_rs_uploaded ? 'uploaded' : ''; ?>" data-id="<?php echo esc_attr($invoice_id); ?>">
            <label style="cursor: default; display:flex; align-items:center; gap:6px;">
                <input type="checkbox" class="cig-rs-checkbox" <?php checked($is_rs_uploaded); ?> disabled="disabled" style="opacity: 0.7; cursor: not-allowed;">
                <span style="font-size:13px; color:#333; font-weight:500;"><?php esc_html_e('Uploaded', 'cig'); ?></span>
                <span class="dashicons dashicons-lock" style="font-size:14px; color:#999;" title="Manage in Accountant Dashboard"></span>
            </label>
        </div>
    </div>
  <?php endif; ?>

  <footer class="invoice-footer-bank">
    <div class="bank-details">
      <strong><?php esc_html_e('Bank Details:','cig'); ?></strong>
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
      <?php if ($director_name): ?><strong><?php echo esc_html(sprintf(__('Director: %s','cig'),$director_name)); ?></strong><?php endif; ?>
      <?php if ($signature_img): ?><img src="<?php echo esc_url($signature_img); ?>" alt="Signature" class="signature-image"><?php endif; ?>
    </div>
  </footer>

  <div class="invoice-final-actions no-print" style="margin-top:25px; padding-top:20px; border-top:1px solid #eee;">
      
      <?php if ($can_edit): ?>
          <a class="button button-secondary" href="<?php echo esc_url(add_query_arg('edit','1')); ?>" style="margin-right:10px;"><?php esc_html_e('Edit Invoice Content','cig'); ?></a>
      <?php endif; ?>

      <a class="button button-secondary" href="<?php echo esc_url(add_query_arg('warranty','1')); ?>" target="_blank" style="margin-right:10px; background:#6c757d; color:#fff; border-color:#6c757d;"><?php esc_html_e('Print Warranty Sheet','cig'); ?></a>

      <button type="button" class="btn-print-invoice" id="btn-print-invoice"><?php esc_html_e('Print Invoice','cig'); ?></button>

      <?php if ($can_edit && $lifecycle !== 'completed'): ?>
         <button type="button" id="cig-mark-sold" class="button" style="background:#28a745; color:#fff; border:none; padding:10px 20px; font-size:14px; cursor:pointer; margin-left:10px;">
             <span class="dashicons dashicons-yes-alt" style="vertical-align:middle;"></span> <?php esc_html_e('Mark as Sold', 'cig'); ?>
         </button>
      <?php endif; ?>
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>