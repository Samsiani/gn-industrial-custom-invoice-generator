<?php
/**
 * Stock reservation and deduction management
 * Updated: Protection against 'none' status (Fictive items)
 *
 * @package CIG
 * @since 4.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG Stock Manager Class
 */
class CIG_Stock_Manager {

    /** @var CIG_Logger */
    private $logger;

    /** @var CIG_Cache */
    private $cache;

    /** @var CIG_Validator */
    private $validator;

    /**
     * Constructor
     */
    public function __construct($logger = null, $cache = null, $validator = null) {
        $this->logger    = $logger    ?: (function_exists('CIG') ? CIG()->logger    : null);
        $this->cache     = $cache     ?: (function_exists('CIG') ? CIG()->cache     : null);
        $this->validator = $validator ?: (function_exists('CIG') ? CIG()->validator : null);

        // Stock filters (Virtual deduction for reserved items)
        add_filter('woocommerce_product_is_in_stock', [$this, 'filter_stock_status'], 10, 2);
        add_filter('woocommerce_product_get_stock_quantity', [$this, 'filter_stock_quantity'], 10, 2);
        add_filter('woocommerce_product_variation_get_stock_quantity', [$this, 'filter_stock_quantity'], 10, 2);

        // Admin display
        add_action('woocommerce_product_options_stock_status', [$this, 'display_reserved_admin']);
        add_action('woocommerce_variation_options_inventory', [$this, 'display_reserved_variation'], 10, 3);

        // Expiring reservations are NO LONGER auto-cancelled (see check_expired_reservations).
        // The hourly hook is deliberately not registered, and any event still sitting in the
        // cron array from an older install is cleared here so it stops firing.
        $this->unschedule_expiry_cron();
    }

    /**
     * Remove the legacy hourly expiry cron.
     *
     * Reservations are held until a human ends them, so nothing should be listening on
     * cig_check_expired_reservations. Clearing it on load makes existing installs
     * self-heal on the next request instead of needing a reactivation.
     */
    private function unschedule_expiry_cron() {
        if (!function_exists('wp_next_scheduled')) {
            return;
        }
        while ($timestamp = wp_next_scheduled('cig_check_expired_reservations')) {
            wp_unschedule_event($timestamp, 'cig_check_expired_reservations');
        }
    }

    /**
     * Get total reserved stock for product (ignores expired)
     */
    public function get_reserved($product_id) {
        $product_id = (int) $product_id;
        if ($product_id <= 0) return 0;

        $reserved_meta = get_post_meta($product_id, '_cig_reserved_stock', true);
        if (!is_array($reserved_meta)) return 0;

        $total = 0;
        $now = current_time('mysql');

        foreach ($reserved_meta as $invoice_id => $data) {
            if (!empty($data['expires']) && $data['expires'] < $now) continue; // expired
            $total += floatval($data['qty'] ?? 0);
        }

        return $total;
    }

    /**
     * Get available stock for product
     */
    public function get_available($product_id, $exclude_invoice_id = 0) {
        $product = wc_get_product($product_id);
        if (!$product) return null;

        $stock_qty = $product->get_stock_quantity();
        if ($stock_qty === null || $stock_qty === '') return null; // Not managed

        $reserved_total = $this->get_reserved($product_id);

        // Exclude current invoice's reservation if editing
        if ($exclude_invoice_id) {
            $reserved_meta = get_post_meta($product_id, '_cig_reserved_stock', true);
            if (is_array($reserved_meta) && isset($reserved_meta[$exclude_invoice_id])) {
                $current_reserved = floatval($reserved_meta[$exclude_invoice_id]['qty'] ?? 0);
                $reserved_total  -= $current_reserved;
            }
        }

        return max(0, $stock_qty - $reserved_total);
    }

    /**
     * Update reservation map entry for product/invoice (Meta only)
     */
    public function update_reservation_meta($product_id, $invoice_id, $quantity, $reservation_days = 0, $invoice_date = '') {
        $product_id = (int) $product_id;
        $invoice_id = (int) $invoice_id;
        $quantity   = floatval($quantity);

        $reserved_meta = get_post_meta($product_id, '_cig_reserved_stock', true);
        if (!is_array($reserved_meta)) $reserved_meta = [];

        if ($quantity > 0) {
            $expiry_date = '';
            if ($reservation_days > 0) {
                $now_ts          = current_time('timestamp');
                $existing_expiry = $reserved_meta[$invoice_id]['expires'] ?? '';

                if (!empty($existing_expiry) && strtotime($existing_expiry) > $now_ts) {
                    // A valid future expiry already exists for this invoice — keep it as-is.
                    $expiry_date = $existing_expiry;
                } else {
                    // Expired or never set — start the window from now.
                    $expiry_date = date('Y-m-d H:i:s', $now_ts + (intval($reservation_days) * DAY_IN_SECONDS));
                }
            }

            $reserved_meta[$invoice_id] = [
                'qty'          => $quantity,
                'expires'      => $expiry_date,
                'invoice_date' => $invoice_date
            ];
        } else {
            unset($reserved_meta[$invoice_id]);
        }

        update_post_meta($product_id, '_cig_reserved_stock', $reserved_meta);
    }

    /**
     * Main Sync Function: Updates Reservations AND Actual Stock
     */
    public function update_invoice_reservations($invoice_id, $old_items, $new_items) {
        $invoice_id  = (int) $invoice_id;
        $invoice_date = get_post_field('post_date', $invoice_id) ?: current_time('mysql');

        // 1. Map Old State
        $old_reserved_map = [];
        $old_sold_map     = [];

        foreach ((array) $old_items as $item) {
            $pid    = intval($item['product_id'] ?? 0);
            $status = strtolower($item['status'] ?? ''); 
            $qty    = floatval($item['qty'] ?? 0);

            if (!$pid) continue;
            if ($status === 'none') continue; // PROTECTION: Ignore 'none' status (Fictive)

            if ($status === 'reserved') {
                $old_reserved_map[$pid] = ($old_reserved_map[$pid] ?? 0) + $qty;
            } elseif ($status === 'sold') {
                $old_sold_map[$pid] = ($old_sold_map[$pid] ?? 0) + $qty;
            }
        }

        // 2. Map New State
        $new_reserved_map = [];
        $new_sold_map     = [];
        $new_days_map     = [];

        foreach ((array) $new_items as $item) {
            $pid    = intval($item['product_id'] ?? 0);
            $status = strtolower($item['status'] ?? ''); 
            $qty    = floatval($item['qty'] ?? 0);
            $days   = intval($item['reservation_days'] ?? 0);

            if (!$pid) continue;
            if ($status === 'none') continue; // PROTECTION: Ignore 'none' status (Fictive)

            if ($status === 'reserved') {
                $new_reserved_map[$pid] = ($new_reserved_map[$pid] ?? 0) + $qty;
                $new_days_map[$pid] = $days;
            } elseif ($status === 'sold') {
                $new_sold_map[$pid] = ($new_sold_map[$pid] ?? 0) + $qty;
            }
        }

        // 3. Process Reservations (Meta Updates)
        $all_reserved_products = array_unique(array_merge(array_keys($old_reserved_map), array_keys($new_reserved_map)));
        foreach ($all_reserved_products as $pid) {
            $old_qty = $old_reserved_map[$pid] ?? 0;
            $new_qty = $new_reserved_map[$pid] ?? 0;
            $days    = $new_days_map[$pid] ?? 0;

            if ($old_qty != $new_qty || $new_qty > 0) {
                $this->update_reservation_meta($pid, $invoice_id, $new_qty, $days, $invoice_date);
            }
        }

        // 4. Process Sold Items (Real Stock Deduction/Refund)
        $all_sold_products = array_unique(array_merge(array_keys($old_sold_map), array_keys($new_sold_map)));
        foreach ($all_sold_products as $pid) {
            $old_qty = $old_sold_map[$pid] ?? 0;
            $new_qty = $new_sold_map[$pid] ?? 0;
            $diff    = $new_qty - $old_qty; // positive = decrease stock, negative = increase stock

            if ($diff !== 0) {
                $product = wc_get_product($pid);
                if ($product && $product->managing_stock()) {
                    $current_stock = $product->get_stock_quantity();
                    $new_stock     = $current_stock - $diff;
                    
                    $product->set_stock_quantity($new_stock);
                    $product->save();
                    
                    if ($this->logger) {
                        $this->logger->info("Stock adjusted for Product #{$pid}", [
                            'invoice' => $invoice_id,
                            'old_sold' => $old_qty,
                            'new_sold' => $new_qty,
                            'diff' => $diff
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Validate stock availability
     */
    public function validate_stock($items, $exclude_invoice_id = 0) {
        $errors = [];

        foreach ((array) $items as $item) {
            // Skip items with 'none' status (fictive or ignored)
            if (($item['status'] ?? '') === 'none') continue;

            $product_id = intval($item['product_id'] ?? 0);
            $qty        = floatval($item['qty'] ?? 0);

            if (!$product_id || $qty <= 0) continue;

            $product = wc_get_product($product_id);
            if (!$product) continue;

            $stock_qty = $product->get_stock_quantity();
            if ($stock_qty === null || $stock_qty === '') continue; // Not managed

            $available = $this->get_available($product_id, $exclude_invoice_id);

            // Handle case where user is increasing Sold quantity on existing invoice
            if ($exclude_invoice_id) {
                $old_items = get_post_meta($exclude_invoice_id, '_cig_items', true);
                if (is_array($old_items)) {
                    foreach ($old_items as $old_item) {
                        if (intval($old_item['product_id'] ?? 0) === $product_id && ($old_item['status'] ?? 'sold') === 'sold') {
                            $available += floatval($old_item['qty'] ?? 0);
                        }
                    }
                }
            }

            if ($qty > $available) {
                $errors[] = sprintf(
                    __('Product "%s" (SKU: %s): Requested %s, but only %s available', 'cig'),
                    sanitize_text_field($item['name'] ?? ''),
                    sanitize_text_field($item['sku'] ?? ''),
                    $qty,
                    $available
                );
            }
        }

        return $errors;
    }

    public function filter_stock_quantity($stock_qty, $product) { return $stock_qty; }

    public function filter_stock_status($in_stock, $product) {
        $stock_qty = $product->get_stock_quantity();
        if ($stock_qty !== null && $stock_qty !== '') {
            $product_id = $product->get_id();
            $reserved   = $this->get_reserved($product_id);
            $available  = $stock_qty - $reserved;
            if ($available <= 0) return false;
        }
        return $in_stock;
    }

    public function display_reserved_admin() {
        global $post; if (!$post) return;
        $product_id = (int) $post->ID;
        $reserved   = $this->get_reserved($product_id);
        if ($reserved <= 0) return;

        echo '<div class="options_group"><p class="form-field"><label>Reserved Stock</label><span style="display:block;padding:5px 0;color:#d63638;font-weight:bold;">' . sprintf('%s units reserved', number_format($reserved, 0)) . '</span></p></div>';
    }

    public function display_reserved_variation($loop, $variation_data, $variation) {
        $product_id = (int) $variation->ID;
        $reserved   = $this->get_reserved($product_id);
        if ($reserved <= 0) return;
        echo '<div style="padding:10px;background:#fff3cd;border:1px solid #ffc107;margin:10px 0;"><strong>Reserved:</strong> ' . sprintf('%s units', number_format($reserved, 0)) . '</div>';
    }

    /**
     * Purge all stock reservations for a specific invoice
     * Used when transitioning an invoice to fictive status
     *
     * @param int $invoice_id Invoice ID to purge reservations for
     * @return void
     */
    public function purge_invoice_reservations($invoice_id) {
        global $wpdb;
        
        $invoice_id = (int) $invoice_id;
        if ($invoice_id <= 0) {
            return;
        }

        // Find all products that have reservations for this invoice
        // Search in _cig_reserved_stock meta for this invoice_id key
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
                '_cig_reserved_stock'
            )
        );

        if (empty($product_ids)) {
            return;
        }

        foreach ($product_ids as $product_id) {
            $reserved_meta = get_post_meta($product_id, '_cig_reserved_stock', true);
            
            if (!is_array($reserved_meta) || !isset($reserved_meta[$invoice_id])) {
                continue;
            }

            // Remove this invoice's reservation from the product
            unset($reserved_meta[$invoice_id]);

            // Update or delete the meta based on remaining reservations
            if (empty($reserved_meta)) {
                delete_post_meta($product_id, '_cig_reserved_stock');
            } else {
                update_post_meta($product_id, '_cig_reserved_stock', $reserved_meta);
            }

            if ($this->logger) {
                $this->logger->info("Purged reservation for Product #{$product_id}", [
                    'invoice' => $invoice_id,
                    'reason' => 'Invoice transitioned to fictive'
                ]);
            }
        }
    }

    /**
     * DISABLED — reservations are never auto-cancelled any more.
     *
     * This used to run hourly and flip every 'reserved' line whose stored expiry had
     * passed to 'canceled', in _cig_items postmeta AND wp_cig_invoice_items, then drop
     * the invoice's entry from the product's _cig_reserved_stock map.
     *
     * It cancelled real orders silently. N250004668 (GEL 14,050, GEL 3,640 already paid)
     * was created 2026-05-07 with a 90-day window and was auto-cancelled on 2026-08-05;
     * the sync bridge then mirrored the cancellation into the Vue app, so the invoice
     * showed zero revenue with the customer's payment still attached and no audit entry
     * explaining it.
     *
     * Raising the reservation-days setting could not prevent this: update_reservation_meta()
     * deliberately preserves an existing future expiry, so every live reservation kept the
     * date stamped at creation time.
     *
     * Ending a reservation is now a human decision. The stored 'expires' value is kept
     * because the dashboard's "expiring reservations" widget reads it, but nothing acts
     * on it. The method is retained as a no-op so any stray caller or a cron event left
     * in the database from an older install cannot cancel anything.
     *
     * @return void
     */
    public function check_expired_reservations() {
        return;
    }

    /**
     * The original expiry sweep, kept for reference only. Never invoked.
     *
     * @codeCoverageIgnore
     */
    private function legacy_check_expired_reservations_DISABLED() {
        global $wpdb;
        $product_ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cig_reserved_stock'");
        if (empty($product_ids)) return;

        $now = current_time('mysql');
        foreach ($product_ids as $product_id) {
            $reserved_meta = get_post_meta($product_id, '_cig_reserved_stock', true);
            if (!is_array($reserved_meta)) continue;
            $changed = false;
            foreach ($reserved_meta as $invoice_id => $data) {
                if (!empty($data['expires']) && $data['expires'] < $now) {
                    // 1. Update legacy postmeta (_cig_items).
                    $invoice_items = get_post_meta($invoice_id, '_cig_items', true);
                    if (is_array($invoice_items)) {
                        foreach ($invoice_items as &$item) {
                            if ((int) ($item['product_id'] ?? 0) === (int)$product_id && ($item['status'] ?? '') === 'reserved') {
                                $item['status'] = 'canceled';
                            }
                        }
                        update_post_meta($invoice_id, '_cig_items', $invoice_items);
                    }

                    // 2. Mirror the cancellation in the custom DB table.
                    $invoice_number = get_post_meta((int) $invoice_id, '_cig_invoice_number', true);
                    if (!empty($invoice_number)) {
                        $internal_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$wpdb->prefix}cig_invoices WHERE invoice_number = %s",
                            $invoice_number
                        ));
                        if ($internal_id) {
                            $wpdb->update(
                                $wpdb->prefix . 'cig_invoice_items',
                                ['item_status' => 'canceled'],
                                [
                                    'invoice_id'  => (int) $internal_id,
                                    'product_id'  => (int) $product_id,
                                    'item_status' => 'reserved',
                                ],
                                ['%s'],
                                ['%d', '%d', '%s']
                            );
                        }
                    }

                    unset($reserved_meta[$invoice_id]);
                    $changed = true;
                }
            }
            if ($changed) update_post_meta($product_id, '_cig_reserved_stock', $reserved_meta);
        }
    }
}