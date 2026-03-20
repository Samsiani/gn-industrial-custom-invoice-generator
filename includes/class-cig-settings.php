<?php
/**
 * Settings page and configuration
 *
 * @package CIG
 * @since 3.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CIG Settings Class
 */
class CIG_Settings {

    /** @var CIG_Cache */
    private $cache;

    /**
     * Constructor
     *
     * @param CIG_Cache|null $cache
     */
    public function __construct($cache = null) {
        $this->cache = $cache ?: (function_exists('CIG') ? CIG()->cache : null);

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Register settings page in admin menu
     */
    public function register_menu() {
        add_options_page(
            __('Invoice Settings', 'cig'),
            __('Invoice Settings', 'cig'),
            'manage_options',
            'cig-invoice-settings',
            [$this, 'render_page']
        );
    }

    /**
     * Register settings and fields
     */
    public function register_settings() {
        register_setting('cig_settings_group', 'cig_settings', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        // Company
        add_settings_section('cig_company_section', __('Company Details', 'cig'), '__return_false', 'cig-invoice-settings');

        $company_fields = [
            'company_logo'   => ['label' => __('Company Logo', 'cig'), 'type' => 'image'],
            'company_name'   => ['label' => __('Company Name', 'cig'), 'type' => 'text'],
            'company_tax_id' => ['label' => __('Tax ID (s/k)', 'cig'), 'type' => 'text'],
            'address'        => ['label' => __('Address', 'cig'), 'type' => 'text'],
            'phone'          => ['label' => __('Phone', 'cig'), 'type' => 'text'],
            'email'          => ['label' => __('Email', 'cig'), 'type' => 'text'],
            'website'        => ['label' => __('Website', 'cig'), 'type' => 'text'],
        ];

        foreach ($company_fields as $key => $meta) {
            add_settings_field(
                "cig_$key",
                $meta['label'],
                [$this, 'render_field'],
                'cig-invoice-settings',
                'cig_company_section',
                ['key' => $key, 'type' => $meta['type']]
            );
        }

        // Bank
        add_settings_section(
            'cig_bank_section',
            __('Bank Details', 'cig'),
            function () { echo '<p>' . esc_html__('Configure up to three banks.', 'cig') . '</p>'; },
            'cig-invoice-settings'
        );

        $bank_fields = [
            'bank1_logo'   => ['label' => __('Bank 1 Logo', 'cig'), 'type' => 'image'],
            'bank1_name'   => ['label' => __('Bank 1 Name', 'cig'), 'type' => 'text'],
            'bank1_account'=> ['label' => __('Bank 1 IBAN / Account #', 'cig'), 'type' => 'text'],
            'bank2_logo'   => ['label' => __('Bank 2 Logo', 'cig'), 'type' => 'image'],
            'bank2_name'   => ['label' => __('Bank 2 Name', 'cig'), 'type' => 'text'],
            'bank2_account'=> ['label' => __('Bank 2 IBAN / Account #', 'cig'), 'type' => 'text'],
            'bank3_logo'   => ['label' => __('Bank 3 Logo', 'cig'), 'type' => 'image'],
            'bank3_name'   => ['label' => __('Bank 3 Name', 'cig'), 'type' => 'text'],
            'bank3_account'=> ['label' => __('Bank 3 IBAN / Account #', 'cig'), 'type' => 'text'],
        ];

        foreach ($bank_fields as $key => $meta) {
            add_settings_field(
                "cig_$key",
                $meta['label'],
                [$this, 'render_field'],
                'cig-invoice-settings',
                'cig_bank_section',
                ['key' => $key, 'type' => $meta['type']]
            );
        }

        // Director
        add_settings_section('cig_director_section', __('Director', 'cig'), '__return_false', 'cig-invoice-settings');

        $director_fields = [
            'director_name'      => ['label' => __('Director Name', 'cig'), 'type' => 'text'],
            'director_signature' => ['label' => __('Signature Image (PNG)', 'cig'), 'type' => 'image'],
        ];

        foreach ($director_fields as $key => $meta) {
            add_settings_field(
                "cig_$key",
                $meta['label'],
                [$this, 'render_field'],
                'cig-invoice-settings',
                'cig_director_section',
                ['key' => $key, 'type' => $meta['type']]
            );
        }

        // Attributes
        add_settings_section(
            'cig_attributes_section',
            __('Product Attribute Mapping', 'cig'),
            function () {
                echo '<p>' . esc_html__('Select Brand attribute and exclusion attributes for Specifications.', 'cig') . '</p>';
            },
            'cig-invoice-settings'
        );

        add_settings_field(
            'cig_brand_attribute',
            __('Brand Attribute', 'cig'),
            [$this, 'render_brand_field'],
            'cig-invoice-settings',
            'cig_attributes_section'
        );

        add_settings_field(
            'cig_exclude_spec_attributes',
            __('Exclude From Specifications', 'cig'),
            [$this, 'render_exclude_field'],
            'cig-invoice-settings',
            'cig_attributes_section'
        );

        // Reservation
        add_settings_section(
            'cig_reservation_section',
            __('Reservation Settings', 'cig'),
            function () {
                echo '<p>' . esc_html__('Configure default reservation period for products.', 'cig') . '</p>';
            },
            'cig-invoice-settings'
        );

        add_settings_field(
            'cig_default_reservation_days',
            __('Default Reservation Period (days)', 'cig'),
            [$this, 'render_reservation_field'],
            'cig-invoice-settings',
            'cig_reservation_section'
        );

        // Warranty Sheet Settings (NEW SECTION)
        add_settings_section(
            'cig_warranty_section',
            __('Warranty Sheet Settings', 'cig'),
            function () {
                echo '<p>' . esc_html__('Configure static text for the warranty sheet.', 'cig') . '</p>';
            },
            'cig-invoice-settings'
        );

        add_settings_field(
            'cig_warranty_text',
            __('Warranty Conditions Text', 'cig'),
            [$this, 'render_warranty_field'],
            'cig-invoice-settings',
            'cig_warranty_section'
        );

        // Invoice Number Settings (NEW SECTION)
        add_settings_section(
            'cig_invoice_number_section',
            __('Invoice Number Settings', 'cig'),
            function () {
                echo '<p>' . esc_html__('Configure the starting invoice number sequence.', 'cig') . '</p>';
            },
            'cig-invoice-settings'
        );

        add_settings_field(
            'cig_starting_invoice_number',
            __('Starting Invoice Number', 'cig'),
            [$this, 'render_starting_invoice_number_field'],
            'cig-invoice-settings',
            'cig_invoice_number_section'
        );
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $output = [];

        $keys = [
            'company_logo', 'company_name', 'company_tax_id', 'address', 'phone', 'email', 'website',
            'bank1_logo', 'bank1_name', 'bank1_account',
            'bank2_logo', 'bank2_name', 'bank2_account',
            'bank3_logo', 'bank3_name', 'bank3_account',
            'director_name', 'director_signature',
            'brand_attribute'
        ];

        foreach ($keys as $k) {
            if (!isset($input[$k])) {
                continue;
            }

            if (in_array($k, ['company_logo', 'bank1_logo', 'bank2_logo', 'bank3_logo', 'director_signature'], true)) {
                $output[$k] = esc_url_raw($input[$k]);
            } else {
                $output[$k] = sanitize_text_field($input[$k]);
            }
        }

        // Exclude attributes
        if (isset($input['exclude_spec_attributes']) && is_array($input['exclude_spec_attributes'])) {
            $output['exclude_spec_attributes'] = array_values(array_filter(
                array_map('sanitize_text_field', $input['exclude_spec_attributes'])
            ));
        } else {
            $output['exclude_spec_attributes'] = ['pa_prod-brand', 'pa_product-condition', 'prod-brand', 'product-condition'];
        }

        // Reservation days
        if (isset($input['default_reservation_days'])) {
            $days = intval($input['default_reservation_days']);
            $output['default_reservation_days'] = max(1, min(CIG_MAX_RESERVATION_DAYS, $days));
        } else {
            $output['default_reservation_days'] = CIG_DEFAULT_RESERVATION_DAYS;
        }

        // Warranty Text (NEW) - Allow HTML
        if (isset($input['warranty_text'])) {
            $output['warranty_text'] = wp_kses_post($input['warranty_text']);
        }

        // Starting Invoice Number - Allow letters and numbers (e.g., N25000088)
        if (isset($input['starting_invoice_number'])) {
            $starting_number = preg_replace('/[^A-Za-z0-9]/', '', $input['starting_invoice_number']);
            $output['starting_invoice_number'] = $starting_number;
        }

        // Bust cache if any
        if ($this->cache) {
            $this->cache->delete('settings');
        }

        return $output;
    }

    /**
     * Render standard field
     */
    public function render_field($args) {
        $opts = get_option('cig_settings', []);
        $key  = $args['key'];
        $type = $args['type'];
        $val  = $opts[$key] ?? '';

        if ($type === 'image') {
            echo '<div style="display:flex;gap:10px;align-items:center">';
            printf(
                '<input type="text" class="regular-text cig-image-url" name="cig_settings[%s]" value="%s" />',
                esc_attr($key),
                esc_attr($val)
            );
            printf(
                '<button class="button cig-upload-button" data-target="%s">%s</button>',
                esc_attr($key),
                esc_html__('Upload', 'cig')
            );
            if ($val) {
                printf('<img src="%s" style="max-height:30px;display:block;" alt="" />', esc_url($val));
            }
            echo '</div>';
        } else {
            printf(
                '<input type="text" class="regular-text" name="cig_settings[%s]" value="%s" />',
                esc_attr($key),
                esc_attr($val)
            );
        }
    }

    /**
     * Render brand attribute field
     */
    public function render_brand_field() {
        $opts    = get_option('cig_settings', []);
        $current = $opts['brand_attribute'] ?? 'pa_prod-brand';
        $options = $this->get_attribute_options();

        if (empty($options)) {
            echo '<em>' . esc_html__('No attributes found.', 'cig') . '</em>';
            printf('<input type="hidden" name="cig_settings[brand_attribute]" value="%s" />', esc_attr($current));
            return;
        }

        echo '<select name="cig_settings[brand_attribute]" class="regular-text">';
        foreach ($options as $slug => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($slug),
                selected($current, $slug, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    /**
     * Render exclude attributes field
     */
    public function render_exclude_field() {
        $opts    = get_option('cig_settings', []);
        $current = $opts['exclude_spec_attributes'] ?? ['pa_prod-brand', 'pa_product-condition'];
        $options = $this->get_attribute_options();

        echo '<select multiple size="6" name="cig_settings[exclude_spec_attributes][]" style="min-width:260px;">';

        foreach ($options as $slug => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($slug),
                selected(in_array($slug, (array) $current, true), true, false),
                esc_html($label)
            );
        }

        // Custom options
        $extra = [
            'prod-brand'        => 'prod-brand (custom)',
            'product-condition' => 'product-condition (custom)'
        ];

        foreach ($extra as $slug => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($slug),
                selected(in_array($slug, (array) $current, true), true, false),
                esc_html($label)
            );
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__('Use Ctrl/Cmd to multi-select.', 'cig') . '</p>';
    }

    /**
     * Render reservation days field
     */
    public function render_reservation_field() {
        $opts = get_option('cig_settings', []);
        $days = intval($opts['default_reservation_days'] ?? CIG_DEFAULT_RESERVATION_DAYS);

        printf(
            '<input type="number" min="1" max="%d" name="cig_settings[default_reservation_days]" value="%d" class="small-text" />',
            CIG_MAX_RESERVATION_DAYS,
            $days
        );
        echo '<p class="description">' .
             esc_html__('Number of days a product can be reserved (1-90 days). Default: 30 days', 'cig') .
             '</p>';
    }

    /**
     * Render warranty text field (NEW)
     */
    public function render_warranty_field() {
        $opts = get_option('cig_settings', []);
        $text = $opts['warranty_text'] ?? '';
        
        echo '<textarea name="cig_settings[warranty_text]" rows="10" cols="50" class="large-text code">' . esc_textarea($text) . '</textarea>';
        echo '<p class="description">' . esc_html__('Enter the terms and conditions text for the warranty sheet. HTML is allowed.', 'cig') . '</p>';
    }

    /**
     * Render starting invoice number field
     */
    public function render_starting_invoice_number_field() {
        $opts = get_option('cig_settings', []);
        $current_value = $opts['starting_invoice_number'] ?? '';
        
        // If no value set, show default from constants
        $placeholder = CIG_INVOICE_NUMBER_PREFIX . str_pad(CIG_INVOICE_NUMBER_BASE, 8, '0', STR_PAD_LEFT);
        
        printf(
            '<input type="text" name="cig_settings[starting_invoice_number]" value="%s" class="regular-text" placeholder="%s" />',
            esc_attr($current_value),
            esc_attr($placeholder)
        );
        echo '<p class="description">' . 
             esc_html__('Enter the starting invoice number (e.g., N25000088). This number will be used when the invoice table is empty. Format: prefix (letters) + numeric sequence.', 'cig') .
             '</p>';
    }

    /**
     * Get attribute taxonomy options
     *
     * @return array
     */
    private function get_attribute_options() {
        if (!function_exists('wc_get_attribute_taxonomies')) {
            return [];
        }

        $taxes = wc_get_attribute_taxonomies();
        $options = [];

        foreach ($taxes as $tax) {
            $slug  = 'pa_' . $tax->attribute_name;
            $label = $tax->attribute_label . ' (' . $slug . ')';
            $options[$slug] = $label;
        }

        return $options;
    }

    /**
     * Render settings page
     */
    public function render_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Invoice Settings', 'cig'); ?></h1>

            <p>
                <strong><?php esc_html_e('Plugin Version:', 'cig'); ?></strong>
                <?php echo esc_html(CIG_VERSION); ?> |
                <strong><?php esc_html_e('Installation Path:', 'cig'); ?></strong>
                <code><?php echo esc_html(dirname(CIG_PLUGIN_BASENAME)); ?></code>
            </p>

            <form method="post" action="options.php">
                <?php
                settings_fields('cig_settings_group');
                do_settings_sections('cig-invoice-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}