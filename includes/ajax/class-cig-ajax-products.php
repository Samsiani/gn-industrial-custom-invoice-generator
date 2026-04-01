<?php
if (!defined('ABSPATH')) exit;

class CIG_Ajax_Products {
    private $stock;
    private $security;

    public function __construct($stock, $security) {
        $this->stock = $stock;
        $this->security = $security;

        // Search & Table Hooks
        add_action('wp_ajax_cig_search_products', [$this, 'search_products']);
        add_action('wp_ajax_cig_check_stock', [$this, 'check_stock']);
        add_action('wp_ajax_cig_search_products_table', [$this, 'search_products_table']);
        add_action('wp_ajax_nopriv_cig_search_products_table', [$this, 'search_products_table']);
        add_action('wp_ajax_cig_submit_stock_request', [$this, 'submit_stock_request']);

        // DB Cart Hooks
        add_action('wp_ajax_cig_add_to_cart_db', [$this, 'add_to_cart_db']);
        add_action('wp_ajax_cig_remove_from_cart_db', [$this, 'remove_from_cart_db']);
        add_action('wp_ajax_cig_clear_cart_db', [$this, 'clear_cart_db']);
    }

    public function search_products() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        if (!class_exists('WC_Product')) wp_send_json_success([]);
        
        $term = isset($_POST['term']) ? sanitize_text_field(wp_unslash($_POST['term'])) : '';
        if ($term === '') wp_send_json_success([]);

        $args = ['post_type' => ['product', 'product_variation'], 'post_status' => 'publish', 'posts_per_page' => 20, 'fields' => 'ids'];

        $filter_handler = function($clauses) use ($term) {
            global $wpdb;
            $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} as sku_meta_search ON ({$wpdb->posts}.ID = sku_meta_search.post_id AND sku_meta_search.meta_key = '_sku') ";
            $clauses['where'] .= " AND (sku_meta_search.meta_value IS NULL OR sku_meta_search.meta_value NOT REGEXP '^GN20ST') ";
            if (!empty($term)) {
                $like = '%' . $wpdb->esc_like($term) . '%';
                $clauses['where'] .= $wpdb->prepare(" AND (({$wpdb->posts}.post_title LIKE %s) OR (sku_meta_search.meta_value LIKE %s))", $like, $like);
            }
            $clauses['groupby'] = "{$wpdb->posts}.ID";
            return $clauses;
        };

        add_filter('posts_clauses', $filter_handler);
        $query = new WP_Query($args);
        remove_filter('posts_clauses', $filter_handler);

        $results = [];
        $seen = [];
        foreach ($query->posts as $pid) {
            if (!$pid || isset($seen[$pid])) continue;
            
            $p_check = wc_get_product($pid);
            if ($p_check && $p_check->is_type('variable')) continue; 

            $payload = $this->build_product_payload($pid);
            if ($payload) { $results[] = $payload; $seen[$pid] = true; }
        }
        wp_send_json($results);
    }

    /**
     * Helper to build product data payload
     */
    private function build_product_payload($pid) {
        try {
            $p = wc_get_product($pid); if (!$p) return null;
            $sets = get_option('cig_settings', []); 
            $br = $sets['brand_attribute'] ?? 'pa_prod-brand';
            $excludes = $sets['exclude_spec_attributes'] ?? ['pa_prod-brand', 'pa_product-condition'];
            
            $brand = ''; 
            if ($br) { 
                $brand_val = $p->get_attribute($br);
                if ($brand_val) {
                    $brand = $brand_val;
                } else {
                    $ts = wp_get_post_terms($p->get_parent_id() ?: $pid, $br, ['fields' => 'names']); 
                    if (!is_wp_error($ts) && !empty($ts)) $brand = $ts[0]; 
                }
            }

            // --- DESCRIPTION LOGIC ---
            $lines = [];
            $attributes = $p->get_attributes();
            
            if (!empty($attributes)) {
                foreach ($attributes as $attr) {
                    if (!is_a($attr, 'WC_Product_Attribute')) continue;
                    $name = $attr->get_name();
                    $tax_slug = taxonomy_exists($name) ? $name : sanitize_title($name);
                    if (in_array($tax_slug, (array)$excludes, true)) continue;
                    $label = wc_attribute_label($name);
                    $value = $p->get_attribute($name);
                    if (!empty($value)) {
                        $lines[] = '• ' . $label . ': ' . $value;
                    }
                }
            }

            if (!empty($lines)) {
                $desc = implode("\n", $lines);
            } else {
                $post_obj = get_post($p->get_parent_id() ?: $pid);
                $desc = $post_obj ? wp_strip_all_tags($post_obj->post_content) : '';
            }

            // --- DIMENSIONS ---
            $dimensions = '';
            if ($p->has_dimensions()) {
                $raw_dims = wc_format_dimensions($p->get_dimensions(false));
                $clean_dims = html_entity_decode($raw_dims); 
                $clean_dims = str_replace(['×', '&times;', ' '], ['x', 'x', ''], $clean_dims);
                $clean_dims = str_replace(['cm', 'mm', 'm', 'in'], ['სმ', 'მმ', 'მ', 'ინჩი'], $clean_dims);

                if ($clean_dims !== 'N/A' && !empty($clean_dims)) {
                    $dimensions = $clean_dims;
                }
            }

            // --- TITLE FORMATTING ---
            $final_name = $p->get_name(); 
            
            if ($p->is_type('variation')) {
                 $parent = wc_get_product($p->get_parent_id());
                 $parent_name = $parent ? $parent->get_name() : $final_name;

                 $attrs_list = [];
                 foreach ($p->get_variation_attributes() as $attr_key => $attr_val) {
                     if ($attr_val) {
                         $slug_decoded = urldecode($attr_val);
                         $taxonomy = str_replace('attribute_', '', $attr_key);
                         $term_name = $slug_decoded;

                         if (taxonomy_exists($taxonomy)) {
                             $term = get_term_by('slug', $attr_val, $taxonomy);
                             if ($term && !is_wp_error($term)) {
                                 $term_name = $term->name;
                             }
                         }
                         if ($term_name === $slug_decoded) {
                             $term_name = ucfirst($term_name);
                         }
                         $attrs_list[] = $term_name;
                     }
                 }

                 if (!empty($attrs_list)) {
                     $final_name = $parent_name . ' - ' . implode(', ', $attrs_list);
                 } else {
                     $final_name = $parent_name;
                 }
            }

            $sq = $p->get_stock_quantity(); 
            $res = $this->stock ? $this->stock->get_reserved($pid) : 0; 
            $av = ($sq !== null && $sq !== '') ? max(0, $sq - $res) : null;
            
            $iid = $p->get_image_id(); $img = ''; 
            if ($iid) { $s = wp_get_attachment_image_src($iid, 'medium'); if ($s) $img = $s[0]; }

            return [
                'id'        => $pid, 
                'label'     => $final_name . ' (SKU: ' . ($p->get_sku() ?: 'N/A') . ')', 
                'value'     => $final_name, 
                'sku'       => $p->get_sku(), 
                'brand'     => $brand, 
                'desc'      => $desc,
                'price'     => floatval($p->get_price() ?: 0),
                'original_price' => floatval($p->get_regular_price() ?: $p->get_price() ?: 0),
                'image'     => $img,
                'stock'     => $sq, 
                'reserved'  => $res, 
                'available' => $av,
                'dimensions' => $dimensions
            ];
        } catch (Exception $e) {
            return null; // Skip problematic product
        }
    }

    public function check_stock() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        $pid=intval($_POST['product_id']); $q=floatval($_POST['quantity']); $inv=intval($_POST['invoice_id']);
        $av=$this->stock->get_available($pid, $inv);
        $prod = wc_get_product($pid);
        if($prod->get_stock_quantity() === null) wp_send_json_success(['available'=>9999, 'can_add'=>true]);
        $can=$q<=$av;
        wp_send_json_success(['available'=>max(0,$av), 'can_add'=>$can, 'message'=>$can?sprintf(__('%s available','cig'),$av):sprintf(__('Only %s available','cig'),$av)]);
    }

    public function search_products_table() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'cig_nonce')) wp_send_json_error(['message' => 'Invalid nonce'], 400);
        if (!class_exists('WC_Product')) wp_send_json_error(['message' => 'WooCommerce not active'], 400);

        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $per_page = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $sort_col = isset($_POST['sort']) ? sanitize_text_field($_POST['sort']) : 'title';
        $sort_dir = isset($_POST['order']) && strtolower($_POST['order']) === 'desc' ? 'DESC' : 'ASC';

        $args = ['post_type' => ['product', 'product_variation'], 'post_status' => 'publish', 'posts_per_page' => $per_page, 'paged' => $page, 'fields' => 'ids'];

        $filter_handler = function($clauses) use ($search) {
            global $wpdb;
            $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} as sku_meta_filter ON ({$wpdb->posts}.ID = sku_meta_filter.post_id AND sku_meta_filter.meta_key = '_sku') ";
            $clauses['where'] .= " AND (sku_meta_filter.meta_value IS NULL OR sku_meta_filter.meta_value NOT REGEXP '^GN20ST') ";
            if (!empty($search)) {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $clauses['where'] .= $wpdb->prepare(" AND (({$wpdb->posts}.post_title LIKE %s) OR ({$wpdb->posts}.post_content LIKE %s) OR (sku_meta_filter.meta_value LIKE %s))", $like, $like, $like);
            }
            $clauses['groupby'] = "{$wpdb->posts}.ID";
            return $clauses;
        };

        add_filter('posts_clauses', $filter_handler);
        switch ($sort_col) {
            case 'price_num': case 'price': $args['meta_key'] = '_price'; $args['orderby'] = 'meta_value_num'; break;
            case 'stock_num': $args['meta_key'] = '_stock'; $args['orderby'] = 'meta_value_num'; break;
            case 'sku': $args['meta_key'] = '_sku'; $args['orderby'] = 'meta_value'; break;
            default: $args['orderby'] = 'title'; break;
        }
        $args['order'] = $sort_dir;
        $query = new WP_Query($args);
        remove_filter('posts_clauses', $filter_handler);

        $products = [];
        $page_product_ids = $query->posts;
        $pending_map = [];
        
        if (!empty($page_product_ids)) {
            $pending_reqs = get_posts(['post_type' => 'cig_req', 'post_status' => 'publish', 'numberposts' => -1, 'meta_query' => ['relation' => 'AND', ['key' => '_cig_req_product_id', 'value' => $page_product_ids, 'compare' => 'IN'], ['key' => '_cig_req_status', 'value' => 'pending']], 'fields' => 'ids']);
            foreach ($pending_reqs as $req_id) {
                $pid = get_post_meta($req_id, '_cig_req_product_id', true);
                $changes = get_post_meta($req_id, '_cig_req_changes', true);
                if ($pid && is_array($changes)) {
                    if (!isset($pending_map[$pid])) $pending_map[$pid] = [];
                    if (isset($changes['price'])) $pending_map[$pid]['price'] = $changes['price']['new'];
                    if (isset($changes['stock'])) $pending_map[$pid]['stock'] = $changes['stock']['new'];
                }
            }
        }

        foreach ($query->posts as $product_id) {
            $p_check = wc_get_product($product_id);
            if ($p_check && $p_check->is_type('variable')) continue; 
            
            $payload = $this->build_product_payload($product_id);
            if ($payload) {
                $payload['title'] = $payload['value']; 
                $payload['price_num'] = $payload['price']; 
                $payload['stock_num'] = ($payload['stock'] !== null && $payload['stock'] !== '') ? floatval($payload['stock']) : -1;
                $payload['stock'] = ($payload['stock'] !== null && $payload['stock'] !== '') ? floatval($payload['stock']) : 'Not managed';
                $payload['available_num'] = ($payload['available'] !== null) ? floatval($payload['available']) : -1;
                $payload['available'] = ($payload['available'] !== null) ? floatval($payload['available']) : 'Not managed';
                $payload['full_image'] = ''; 
                
                $img_id = get_post_thumbnail_id($product_id);
                if($img_id) {
                    $f = wp_get_attachment_image_src($img_id, 'full');
                    if($f) $payload['full_image'] = $f[0];
                }
                
                $payload['product_url'] = get_permalink($product_id);
                $payload['pending_data'] = $pending_map[$product_id] ?? [];
                $payload['price'] = wc_price($payload['price']);

                $products[] = $payload;
            }
        }
        
        wp_send_json_success(['products' => $products, 'total_items' => $query->found_posts, 'total_pages' => $query->max_num_pages, 'current_page'=> $page]);
    }

    public function submit_stock_request() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        if (!class_exists('CIG_Stock_Requests')) wp_send_json_error(['message' => 'Module missing']);
        
        $pid = intval($_POST['product_id']);
        $product = wc_get_product($pid);
        if (!$product) wp_send_json_error(['message' => 'Invalid product']);

        $changes = [];
        if (isset($_POST['price'])) $changes['price'] = ['old' => $product->get_price(), 'new' => floatval($_POST['price'])];
        if (isset($_POST['stock'])) $changes['stock'] = ['old' => $product->get_stock_quantity(), 'new' => intval($_POST['stock'])];

        if (empty($changes)) wp_send_json_error(['message' => 'No changes detected']);

        $req = CIG()->stock_requests->create_request($pid, get_current_user_id(), $changes);
        if (is_wp_error($req)) wp_send_json_error(['message' => $req->get_error_message()]);

        wp_send_json_success(['message' => 'Request submitted successfully.']);
    }

    // --- DB Cart Logic ---
    public function add_to_cart_db() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $item = isset($_POST['item']) ? $_POST['item'] : [];
        if (empty($item) || !isset($item['id'])) wp_send_json_error(['message' => 'Invalid data']);

        $user_id = get_current_user_id();
        $cart = get_user_meta($user_id, '_cig_temp_cart', true);
        if (!is_array($cart)) $cart = [];

        $found = false;
        foreach ($cart as &$c_item) {
            if ($c_item['id'] == $item['id']) {
                $c_item['qty'] = isset($c_item['qty']) ? intval($c_item['qty']) + 1 : 2;
                $found = true;
                break;
            }
        }

        if (!$found) {
            $new_item = [
                'id'    => intval($item['id']),
                'sku'   => sanitize_text_field($item['sku'] ?? ''),
                'name'  => sanitize_text_field($item['name'] ?? ''),
                'price' => floatval($item['price'] ?? 0),
                'image' => esc_url_raw($item['image'] ?? ''),
                'brand' => sanitize_text_field($item['brand'] ?? ''),
                'desc'  => wp_kses_post($item['desc'] ?? ''),
                'qty'   => 1
            ];
            $cart[] = $new_item;
        }

        update_user_meta($user_id, '_cig_temp_cart', $cart);
        wp_send_json_success(['cart' => $cart, 'count' => count($cart)]);
    }

    public function remove_from_cart_db() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id) wp_send_json_error(['message' => 'Invalid ID']);

        $user_id = get_current_user_id();
        $cart = get_user_meta($user_id, '_cig_temp_cart', true);
        if (!is_array($cart)) $cart = [];

        $new_cart = [];
        foreach ($cart as $item) {
            if ($item['id'] != $id) {
                $new_cart[] = $item;
            }
        }

        update_user_meta($user_id, '_cig_temp_cart', $new_cart);
        wp_send_json_success(['cart' => $new_cart, 'count' => count($new_cart)]);
    }

    public function clear_cart_db() {
        $this->security->verify_ajax_request('cig_nonce', 'nonce', 'manage_woocommerce');
        $user_id = get_current_user_id();
        delete_user_meta($user_id, '_cig_temp_cart');
        wp_send_json_success();
    }
}