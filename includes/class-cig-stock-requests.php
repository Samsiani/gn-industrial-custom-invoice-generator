<?php
/**
 * Stock & Price Update Requests Handler
 *
 * @package CIG
 * @since 3.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CIG_Stock_Requests {

    private $post_type = 'cig_req';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('admin_menu', [$this, 'register_menu_page']);
        add_action('admin_init', [$this, 'handle_actions']);
        
        // Filter to save the "per_page" option
        add_filter('set-screen-option', [$this, 'set_screen_option'], 10, 3);
    }

    /**
     * Register CPT for storing requests (Hidden from UI)
     */
    public function register_post_type() {
        register_post_type($this->post_type, [
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => false,
            'supports'           => ['title', 'author'],
            'label'              => 'Stock Requests',
            'capability_type'    => 'post',
            'capabilities'       => [
                'create_posts' => 'do_not_allow', 
            ],
            'map_meta_cap'       => true,
        ]);
    }

    /**
     * Add Admin Menu & Register Screen Options
     */
    public function register_menu_page() {
        $hook = add_submenu_page(
            'edit.php?post_type=invoice',
            __('Stock Requests', 'cig'),
            __('Stock Requests', 'cig'),
            'manage_woocommerce',
            'cig-stock-requests',
            [$this, 'render_page']
        );

        // Add Screen Options when this page loads
        add_action("load-$hook", [$this, 'add_screen_options']);
    }

    /**
     * Add Screen Option (Per Page)
     */
    public function add_screen_options() {
        add_screen_option('per_page', [
            'label'   => __('Requests per page', 'cig'),
            'default' => 20,
            'option'  => 'cig_requests_per_page'
        ]);
    }

    /**
     * Save Screen Option
     */
    public function set_screen_option($status, $option, $value) {
        if ('cig_requests_per_page' === $option) {
            return $value;
        }
        return $status;
    }

    /**
     * Create a new request (Called via AJAX)
     */
    public function create_request($product_id, $user_id, $changes) {
        $product = wc_get_product($product_id);
        if (!$product) return new WP_Error('invalid_product', 'Invalid Product');

        $title = sprintf('Update Request for %s (ID: %d)', $product->get_name(), $product_id);

        $post_id = wp_insert_post([
            'post_type'   => $this->post_type,
            'post_title'  => $title,
            'post_status' => 'publish',
            'post_author' => $user_id,
        ]);

        if (is_wp_error($post_id)) return $post_id;

        update_post_meta($post_id, '_cig_req_product_id', $product_id);
        update_post_meta($post_id, '_cig_req_status', 'pending');
        update_post_meta($post_id, '_cig_req_date', current_time('mysql'));
        update_post_meta($post_id, '_cig_req_changes', $changes);

        return $post_id;
    }

    /**
     * Handle Approve/Reject Actions
     */
    public function handle_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'cig-stock-requests') return;

        // Bulk approve all pending requests
        if (isset($_GET['action']) && $_GET['action'] === 'approve_all') {
            $nonce = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
            if (!wp_verify_nonce($nonce, 'cig_req_approve_all')) {
                wp_die('Security check failed');
            }
            if (!current_user_can('manage_woocommerce')) {
                wp_die('Permission denied');
            }

            $pending = get_posts([
                'post_type'      => $this->post_type,
                'posts_per_page' => -1,
                'meta_query'     => [['key' => '_cig_req_status', 'value' => 'pending']],
                'fields'         => 'ids',
            ]);

            foreach ($pending as $req_id) {
                $this->approve_request($req_id);
            }

            wp_redirect(remove_query_arg(['action', '_wpnonce']));
            exit;
        }

        if (!isset($_GET['action']) || !isset($_GET['req_id'])) return;

        $action = sanitize_text_field($_GET['action']);
        $req_id = intval($_GET['req_id']);
        $nonce  = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';

        if (!wp_verify_nonce($nonce, 'cig_req_action_' . $req_id)) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permission denied');
        }

        if ($action === 'approve') {
            $this->approve_request($req_id);
        } elseif ($action === 'reject') {
            $this->reject_request($req_id);
        }

        wp_redirect(remove_query_arg(['action', 'req_id', '_wpnonce']));
        exit;
    }

    /**
     * Approve Logic
     */
    private function approve_request($req_id) {
        $product_id = get_post_meta($req_id, '_cig_req_product_id', true);
        $changes    = get_post_meta($req_id, '_cig_req_changes', true);
        $product    = wc_get_product($product_id);

        if ($product && is_array($changes)) {
            foreach ($changes as $type => $data) {
                $new_val = $data['new'];
                
                if ($type === 'price') {
                    $product->set_regular_price($new_val);
                    $product->set_price($new_val);
                } elseif ($type === 'stock') {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($new_val);
                }
            }
            $product->save();
        }

        update_post_meta($req_id, '_cig_req_status', 'approved');
        update_post_meta($req_id, '_cig_req_approver', get_current_user_id());
        update_post_meta($req_id, '_cig_req_processed_date', current_time('mysql'));
    }

    /**
     * Get the base URL for this admin page
     */
    private function get_page_url() {
        return admin_url('edit.php?post_type=invoice&page=cig-stock-requests&tab=pending');
    }

    /**
     * Reject Logic
     */
    private function reject_request($req_id) {
        update_post_meta($req_id, '_cig_req_status', 'rejected');
        update_post_meta($req_id, '_cig_req_approver', get_current_user_id());
        update_post_meta($req_id, '_cig_req_processed_date', current_time('mysql'));
    }

    /**
     * Render Admin Page
     */
    public function render_page() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'pending';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Stock & Price Update Requests', 'cig'); ?></h1>
            <hr class="wp-header-end">
            
            <nav class="nav-tab-wrapper">
                <a href="?post_type=invoice&page=cig-stock-requests&tab=pending" class="nav-tab <?php echo $tab === 'pending' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Pending Requests', 'cig'); ?>
                </a>
                <a href="?post_type=invoice&page=cig-stock-requests&tab=history" class="nav-tab <?php echo $tab === 'history' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('History / Logs', 'cig'); ?>
                </a>
            </nav>
            <br>
            <?php
            if ($tab === 'pending') {
                $approve_all_url = wp_nonce_url(
                    add_query_arg(['action' => 'approve_all'], $this->get_page_url()),
                    'cig_req_approve_all'
                );
                echo '<div class="tablenav top" style="margin-bottom:10px;">';
                echo '<a href="' . esc_url($approve_all_url) . '" class="button button-primary" onclick="return confirm(\'Approve all pending requests?\');">';
                echo '✓ ' . esc_html__('Approve All', 'cig') . '</a>';
                echo '</div>';
                $this->render_table('pending');
            } else {
                $this->render_table(['approved', 'rejected']);
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render Table
     */
    private function render_table($status) {
        // 1. Sorting Parameters
        $order      = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';
        $next_order = ($order === 'ASC') ? 'desc' : 'asc';
        $sort_icon  = ($order === 'ASC') ? ' &#9650;' : ' &#9660;';
        
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'pending';
        $base_url    = admin_url('edit.php?post_type=invoice&page=cig-stock-requests&tab=' . $current_tab);
        $sort_link   = add_query_arg('order', $next_order, $base_url);

        // 2. Pagination Parameters
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        
        // 3. Get Screen Option (Items Per Page)
        $user   = get_current_user_id();
        $per_page = 20; // fallback
        
        // Safety check: get_current_screen() returns null on frontend
        $screen = get_current_screen();
        if ( $screen ) {
            $option = $screen->get_option( 'per_page', 'option' );
            if ( $option ) {
                $user_per_page = (int) get_user_meta( $user, $option, true );
                if ( $user_per_page > 0 ) {
                    $per_page = $user_per_page;
                } else {
                    $default = $screen->get_option( 'per_page', 'default' );
                    if ( $default ) {
                        $per_page = (int) $default;
                    }
                }
            }
        }

        // 4. Query
        $args = [
            'post_type'      => $this->post_type,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'meta_query'     => [
                [
                    'key'     => '_cig_req_status',
                    'value'   => $status,
                    'compare' => is_array($status) ? 'IN' : '='
                ]
            ],
            'orderby' => 'date',
            'order'   => $order
        ];

        $query = new WP_Query($args);

        if (!$query->have_posts()) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('No records found.', 'cig') . '</p></div>';
            return;
        }

        // Table Header
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:150px;"><a href="' . esc_url($sort_link) . '">Date' . $sort_icon . '</a></th>';
        echo '<th style="width:150px;">User</th>';
        echo '<th>Product</th>';
        echo '<th>Changes Requested</th>';
        if ($status === 'pending') {
            echo '<th style="width:200px;">Actions</th>';
        } else {
            echo '<th style="width:100px;">Status</th>';
            echo '<th style="width:150px;">Processed By</th>';
        }
        echo '</tr></thead><tbody>';

        // Table Body
        while ($query->have_posts()) {
            $query->the_post();
            $req_id     = get_the_ID();
            $product_id = get_post_meta($req_id, '_cig_req_product_id', true);
            $changes    = get_post_meta($req_id, '_cig_req_changes', true);
            $req_date   = get_post_meta($req_id, '_cig_req_date', true);
            $author_id  = get_the_author_meta('ID');
            $author_name= get_the_author();
            
            $product   = wc_get_product($product_id);
            $prod_name = $product ? '<a href="'.get_edit_post_link($product_id).'" target="_blank"><strong>'.$product->get_name().'</strong></a>' : '<em style="color:#999;">(Deleted Product)</em>';
            if ($product && $product->get_sku()) {
                $prod_name .= '<br><small>SKU: ' . $product->get_sku() . '</small>';
            }

            echo '<tr>';
            echo '<td>' . esc_html($req_date) . '</td>';
            
            $avatar = get_avatar($author_id, 24);
            echo '<td><div style="display:flex;align-items:center;gap:5px;">' . $avatar . ' ' . esc_html($author_name) . '</div></td>';
            
            echo '<td>' . $prod_name . '</td>';
            
            echo '<td>';
            if (is_array($changes)) {
                foreach ($changes as $type => $vals) {
                    echo '<div style="margin-bottom:4px;">';
                    echo '<strong>' . ucfirst($type) . ':</strong> ';
                    echo '<span style="color:#dc3545;text-decoration:line-through;margin-right:5px;">' . $vals['old'] . '</span>';
                    echo '<span class="dashicons dashicons-arrow-right-alt" style="font-size:14px;line-height:1.5;color:#999;"></span> ';
                    echo '<span style="color:#28a745;font-weight:bold;margin-left:5px;">' . $vals['new'] . '</span>';
                    echo '</div>';
                }
            }
            echo '</td>';

            if ($status === 'pending') {
                $nonce_url_approve = wp_nonce_url(add_query_arg(['action' => 'approve', 'req_id' => $req_id]), 'cig_req_action_' . $req_id);
                $nonce_url_reject  = wp_nonce_url(add_query_arg(['action' => 'reject', 'req_id' => $req_id]), 'cig_req_action_' . $req_id);
                
                echo '<td>';
                echo '<a href="' . esc_url($nonce_url_approve) . '" class="button button-primary" style="margin-right:5px;">Approve</a>';
                echo '<a href="' . esc_url($nonce_url_reject) . '" class="button button-secondary">Reject</a>';
                echo '</td>';
            } else {
                $curr_status = get_post_meta($req_id, '_cig_req_status', true);
                $approver_id = get_post_meta($req_id, '_cig_req_approver', true);
                $approver    = get_userdata($approver_id);
                $app_name    = $approver ? $approver->display_name : '—';
                $proc_date   = get_post_meta($req_id, '_cig_req_processed_date', true);
                
                $color = ($curr_status === 'approved') ? '#28a745' : '#dc3545';
                echo '<td><strong style="color:'.esc_attr($color).';text-transform:uppercase;">' . esc_html($curr_status) . '</strong></td>';
                echo '<td>' . esc_html($app_name) . '<br><small style="color:#999;">' . esc_html($proc_date) . '</small></td>';
            }

            echo '</tr>';
        }

        echo '</tbody></table>';
        
        // 5. Pagination Rendering (Updated Logic)
        $total_pages = $query->max_num_pages;
        if ($total_pages > 1) {
            $current_url = remove_query_arg('paged');
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links([
                'base'      => add_query_arg('paged', '%#%', $current_url),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => __('&laquo;', 'cig'),
                'next_text' => __('&raquo;', 'cig'),
                'add_args'  => [
                    'tab'   => $current_tab,
                    'order' => $order
                ]
            ]);
            echo '</div></div>';
        }
        
        wp_reset_postdata();
    }
}