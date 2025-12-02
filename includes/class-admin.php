<?php
/**
 * Admin class for Transfer Brands for WooCommerce plugin
 *
 * @package TBFW_Transfer_Brands
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Admin class for the plugin
 * 
 * Handles admin UI, settings pages, and UI-related functions
 *
 * @since 2.3.0
 */
class TBFW_Transfer_Brands_Admin {
    /**
     * Reference to core plugin instance
     *
     * @since 2.3.0
     * @var TBFW_Transfer_Brands_Core
     */
    private $core;
    
    /**
     * Current active tab
     *
     * @since 2.3.0
     * @var string
     */
    private $active_tab = 'transfer';
    
    /**
     * Constructor
     * 
     * @since 2.3.0
     * @param TBFW_Transfer_Brands_Core $core Core plugin instance
     */
    public function __construct($core) {
        $this->core = $core;
        
        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }
    
    /**
     * Enqueue admin scripts and styles
     *
     * @since 2.3.0
     * @param string $hook Current admin page
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'tbfw-transfer-brands') === false) {
            return;
        }
        
        // Enqueue JS
        wp_enqueue_script(
            'tbfw-transfer-brands-admin-js',
            TBFW_ASSETS_URL . 'js/admin.js',
            ['jquery'],
            TBFW_VERSION,
            true
        );
        
        // Pass variables to JS
        wp_localize_script('tbfw-transfer-brands-admin-js', 'tbfwTbe', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tbfw_transfer_brands_nonce'),
            'batchSize' => $this->core->get_batch_size(),
            'sourceTaxonomy' => $this->core->get_option('source_taxonomy'),
            'destTaxonomy' => $this->core->get_option('destination_taxonomy'),
            'i18n' => [
                'confirm_transfer' => __('Are you sure you want to transfer brands? This process cannot be easily undone unless you have backups enabled.', 'transfer-brands-for-woocommerce'),
                'confirm_delete' => __('This action will remove the old brand attribute from all products. Are you sure?', 'transfer-brands-for-woocommerce'),
                'confirm_delete_verification' => __('Please type YES in the box below to confirm deletion of all old brand attributes. This action cannot be undone unless you have backups enabled.', 'transfer-brands-for-woocommerce'),
                'type_yes' => __('Type YES to confirm', 'transfer-brands-for-woocommerce'),
                'delete_verification_failed' => __('Verification failed. Please type YES to confirm deletion.', 'transfer-brands-for-woocommerce'),
                'confirm_rollback' => __('Are you sure you want to rollback the transfer? This will restore the previous state before the transfer.', 'transfer-brands-for-woocommerce'),
                'confirm_restore' => __('This action will restore the deleted brand attributes to products. Are you sure?', 'transfer-brands-for-woocommerce'),
                'confirm_cleanup' => __('This action will delete ALL stored backups. After this, rollback will not be possible. Are you sure?', 'transfer-brands-for-woocommerce'),
                'processing' => __('Processing products:', 'transfer-brands-for-woocommerce'),
                'progress' => __('Progress:', 'transfer-brands-for-woocommerce'),
                'completed' => __('Completed!', 'transfer-brands-for-woocommerce'),
                'error' => __('Error:', 'transfer-brands-for-woocommerce'),
                'ajax_error' => __('AJAX Error:', 'transfer-brands-for-woocommerce'),
                'time_elapsed' => __('Time elapsed:', 'transfer-brands-for-woocommerce'),
                'estimated_time' => __('Estimated time remaining:', 'transfer-brands-for-woocommerce'),
                'minutes' => __('minutes', 'transfer-brands-for-woocommerce'),
                'seconds' => __('seconds', 'transfer-brands-for-woocommerce'),
                'autorefresh' => __('The page will automatically refresh in 3 seconds...', 'transfer-brands-for-woocommerce'),
                'warning' => __('WARNING: Do not refresh the page until the process is complete!', 'transfer-brands-for-woocommerce')
            ]
        ]);
        
        // Enqueue CSS
        wp_enqueue_style(
            'tbfw-transfer-brands-admin-css',
            TBFW_ASSETS_URL . 'css/admin.css',
            [],
            TBFW_VERSION
        );
        
        // Enqueue WordPress admin tooltips
        wp_enqueue_script('jquery-ui-tooltip');
        
        // Enqueue taxonomy refresh script
        wp_enqueue_script(
            'tbfw-taxonomy-refresh',
            TBFW_ASSETS_URL . 'js/taxonomy-refresh.js',
            ['jquery'],
            TBFW_VERSION,
            true
        );
        
        // Localize taxonomy refresh script
        wp_localize_script('tbfw-taxonomy-refresh', 'tbfwRefresh', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tbfw_transfer_brands_nonce'),
            'refreshing' => __('Refreshing...', 'transfer-brands-for-woocommerce'),
            'refreshText' => __('Refresh Destination Taxonomy', 'transfer-brands-for-woocommerce'),
            'updated' => __('Updated!', 'transfer-brands-for-woocommerce'),
            'error' => __('Error:', 'transfer-brands-for-woocommerce'),
            'networkError' => __('Network error occurred', 'transfer-brands-for-woocommerce')
        ]);
    }
    
    /**
     * Register admin pages
     *
     * @since 2.3.0
     */
    public function add_admin_pages() {
        // Add only one menu item for the main page
        add_submenu_page(
            'woocommerce',
            __('Transfer Brands', 'transfer-brands-for-woocommerce'),
            __('Transfer Brands', 'transfer-brands-for-woocommerce'),
            'manage_woocommerce',
            'tbfw-transfer-brands',
            [$this, 'admin_page']
        );
        
        // Debug page if enabled (we'll keep this as a separate page)
        if ($this->core->get_option('debug_mode')) {
            add_submenu_page(
                'woocommerce',
                __('Brand Transfer Debug', 'transfer-brands-for-woocommerce'),
                __('Brand Transfer Debug', 'transfer-brands-for-woocommerce'),
                'manage_woocommerce',
                'tbfw-transfer-brands-debug',
                [$this, 'debug_page']
            );
        }
    }
    
    /**
     * Register settings
     *
     * @since 2.3.0
     */
    public function register_settings() {
        register_setting(
            'tbfw_transfer_brands_settings', 
            'tbfw_transfer_brands_options',
            [
                'sanitize_callback' => [$this, 'sanitize_settings']
            ]
        );
        
        add_settings_section(
            'tbfw_transfer_brands_main',
            __('Main Settings', 'transfer-brands-for-woocommerce'),
            [$this, 'settings_section_callback'],
            'tbfw_transfer_brands_settings'
        );
        
        add_settings_field(
            'source_taxonomy',
            __('Source Attribute Taxonomy', 'transfer-brands-for-woocommerce'),
            [$this, 'source_taxonomy_callback'],
            'tbfw_transfer_brands_settings',
            'tbfw_transfer_brands_main'
        );
        
        // The destination_taxonomy will no longer be displayed as a configurable field
        // Instead, we'll display an informational note
        add_settings_field(
            'destination_taxonomy_info',
            __('Destination Brand Taxonomy', 'transfer-brands-for-woocommerce'),
            [$this, 'destination_taxonomy_info_callback'],
            'tbfw_transfer_brands_settings',
            'tbfw_transfer_brands_main'
        );
        
        add_settings_field(
            'batch_size',
            __('Batch Size', 'transfer-brands-for-woocommerce'),
            [$this, 'batch_size_callback'],
            'tbfw_transfer_brands_settings',
            'tbfw_transfer_brands_main'
        );
        
        add_settings_field(
            'backup_enabled',
            __('Create Backup', 'transfer-brands-for-woocommerce'),
            [$this, 'backup_enabled_callback'],
            'tbfw_transfer_brands_settings',
            'tbfw_transfer_brands_main'
        );
        
        add_settings_field(
            'debug_mode',
            __('Debug Mode', 'transfer-brands-for-woocommerce'),
            [$this, 'debug_mode_callback'],
            'tbfw_transfer_brands_settings',
            'tbfw_transfer_brands_main'
        );
    }
    
    /**
     * Sanitize settings
     *
     * @since 2.3.0
     * @param array $input Settings input
     * @return array Sanitized settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        
        // Source taxonomy
        if (isset($input['source_taxonomy'])) {
            $sanitized['source_taxonomy'] = sanitize_text_field($input['source_taxonomy']);
        } else {
            $sanitized['source_taxonomy'] = 'pa_brand';
        }
        
        // The destination_taxonomy is removed from editing
        // We make sure to keep the existing value
        $sanitized['destination_taxonomy'] = $this->core->get_option('destination_taxonomy', 'product_brand');
        
        // Batch size
        if (isset($input['batch_size'])) {
            $sanitized['batch_size'] = absint($input['batch_size']);
            if ($sanitized['batch_size'] < 5) $sanitized['batch_size'] = 5;
            if ($sanitized['batch_size'] > 100) $sanitized['batch_size'] = 100;
        } else {
            $sanitized['batch_size'] = 20;
        }
        
        // Backup enabled - checkboxes don't send a value when unchecked
        $sanitized['backup_enabled'] = isset($input['backup_enabled']) ? true : false;
        
        // Debug mode
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? true : false;
        
        return $sanitized;
    }
    
    /**
     * Add the new method for displaying information about the destination taxonomy:
     */
    public function destination_taxonomy_info_callback() {
        $destination = $this->core->get_option('destination_taxonomy', 'product_brand');
        
        echo '<div class="tbfw-tb-permalink-info">';
        echo '<strong>' . esc_html($destination) . '</strong>';
        echo '<p class="description">' . wp_kses_post(sprintf(
            /* translators: %s: Link to the WordPress permalinks settings page */
            __('This value is automatically pulled from WordPress permalinks settings. To change it, go to %s and modify the "Product brand base" field.', 'transfer-brands-for-woocommerce'),
            '<a href="' . esc_url(admin_url('options-permalink.php')) . '" target="_blank">' . 
            esc_html__('Settings > Permalinks', 'transfer-brands-for-woocommerce') . '</a>'
        )) . '</p>';
        echo '</div>';
        
        // Add a refresh button for immediate update
        echo '<div class="tbfw-tb-permalink-refresh">';
        echo '<button type="button" id="tbfw-tb-refresh-taxonomy" class="button button-small">' . 
             esc_html__('Refresh Destination Taxonomy', 'transfer-brands-for-woocommerce') . 
             '</button>';
        echo '<span id="tbfw-tb-refresh-taxonomy-status" style="margin-left: 10px; display: none;"></span>';
        echo '</div>';
    }
    
    /**
     * Settings section callback
     *
     * @since 2.3.0
     */
    public function settings_section_callback() {
        echo '<p>' . esc_html__('Configure the brand transfer settings. These settings determine how brands are transferred from attribute to taxonomy.', 'transfer-brands-for-woocommerce') . '</p>';
    }
    
    /**
     * Source taxonomy field callback
     *
     * @since 2.3.0
     * @since 2.8.5 Added support for Perfect Brands for WooCommerce (pwb-brand)
     */
    public function source_taxonomy_callback() {
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        $source = $this->core->get_option('source_taxonomy', 'pa_brand');

        echo '<select name="tbfw_transfer_brands_options[source_taxonomy]">';

        // WooCommerce Product Attributes section
        if (!empty($attribute_taxonomies)) {
            echo '<optgroup label="' . esc_attr__('WooCommerce Attributes', 'transfer-brands-for-woocommerce') . '">';
            foreach ($attribute_taxonomies as $tax) {
                $tax_name = 'pa_' . $tax->attribute_name;
                echo '<option value="' . esc_attr($tax_name) . '" ' . selected($tax_name, $source, false) . '>';
                echo esc_html($tax->attribute_label) . ' (' . esc_html($tax_name) . ')';
                echo '</option>';
            }
            echo '</optgroup>';
        }

        // Brand Plugins section - check for supported brand plugin taxonomies
        $brand_plugins = $this->get_supported_brand_plugins();
        if (!empty($brand_plugins)) {
            echo '<optgroup label="' . esc_attr__('Brand Plugins', 'transfer-brands-for-woocommerce') . '">';
            foreach ($brand_plugins as $plugin) {
                echo '<option value="' . esc_attr($plugin['taxonomy']) . '" ' . selected($plugin['taxonomy'], $source, false) . '>';
                echo esc_html($plugin['label']);
                echo '</option>';
            }
            echo '</optgroup>';
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__('Select the source attribute or taxonomy that contains your brands.', 'transfer-brands-for-woocommerce') . '</p>';

        // Show info about detected brand plugins
        if (!empty($brand_plugins)) {
            echo '<p class="description" style="color: #2271b1;"><span class="dashicons dashicons-info" style="font-size: 14px; width: 14px; height: 14px;"></span> ';
            echo esc_html__('Detected brand plugin(s):', 'transfer-brands-for-woocommerce') . ' ';
            $plugin_names = array_column($brand_plugins, 'name');
            echo '<strong>' . esc_html(implode(', ', $plugin_names)) . '</strong>';
            echo '</p>';
        }
    }

    /**
     * Get list of supported brand plugins that are active
     *
     * @since 2.8.5
     * @return array Array of supported brand plugins with their taxonomies
     */
    private function get_supported_brand_plugins() {
        $plugins = [];

        // Perfect Brands for WooCommerce
        if (taxonomy_exists('pwb-brand')) {
            $plugins[] = [
                'taxonomy' => 'pwb-brand',
                'name' => 'Perfect Brands for WooCommerce',
                'label' => __('Perfect Brands (pwb-brand)', 'transfer-brands-for-woocommerce'),
                'image_meta_key' => 'pwb_brand_image'
            ];
        }

        // YITH WooCommerce Brands (uses yith_product_brand taxonomy)
        if (taxonomy_exists('yith_product_brand')) {
            $plugins[] = [
                'taxonomy' => 'yith_product_brand',
                'name' => 'YITH WooCommerce Brands',
                'label' => __('YITH Brands (yith_product_brand)', 'transfer-brands-for-woocommerce'),
                'image_meta_key' => 'yith_woocommerce_brand_thumbnail_id'
            ];
        }

        return $plugins;
    }
    
    /**
     * Batch size field callback
     *
     * @since 2.3.0
     */
    public function batch_size_callback() {
        $batch_size = $this->core->get_option('batch_size', 20);
        echo '<input type="number" name="tbfw_transfer_brands_options[batch_size]" value="' . esc_attr($batch_size) . '" min="5" max="100" />';
        echo '<p class="description">' . esc_html__('Number of products to process per batch. Higher values may be faster but could time out.', 'transfer-brands-for-woocommerce') . '</p>';
    }
    
    /**
     * Backup enabled field callback
     *
     * @since 2.3.0
     */
    public function backup_enabled_callback() {
        $backup_enabled = $this->core->get_option('backup_enabled', true);
        echo '<input type="checkbox" id="tbfw_backup_enabled" name="tbfw_transfer_brands_options[backup_enabled]" value="1" ' . checked($backup_enabled, true, false) . ' />';
        echo '<label for="tbfw_backup_enabled"> ' . esc_html__('Enable backups (recommended)', 'transfer-brands-for-woocommerce') . '</label>';
        echo '<p class="description">' . esc_html__('This feature creates a backup before transferring brands. This allows you to undo the transfer if any issues occur.', 'transfer-brands-for-woocommerce') . '</p>';
        
        echo '<div class="notice notice-info inline" style="margin: 10px 0; padding: 8px 12px;">';
        echo '<p><strong>' . esc_html__('What the backup does:', 'transfer-brands-for-woocommerce') . '</strong></p>';
        echo '<ul style="margin-left: 20px; list-style-type: disc;">';
        echo '<li>' . esc_html__('Stores all existing brands in the destination and their metadata (images, etc.)', 'transfer-brands-for-woocommerce') . '</li>';
        echo '<li>' . esc_html__('For each modified product, stores the original brand assignments', 'transfer-brands-for-woocommerce') . '</li>';
        echo '<li>' . esc_html__('Maintains a mapping between old and new terms', 'transfer-brands-for-woocommerce') . '</li>';
        echo '<li>' . esc_html__('Allows full restoration in case of error via the "Rollback Transfer" button', 'transfer-brands-for-woocommerce') . '</li>';
        echo '</ul>';
        echo '</div>';
    }
    
    /**
     * Debug mode field callback
     *
     * @since 2.3.0
     */
    public function debug_mode_callback() {
        $debug_mode = $this->core->get_option('debug_mode', false);
        echo '<input type="checkbox" id="tbfw_debug_mode" name="tbfw_transfer_brands_options[debug_mode]" value="1" ' . checked($debug_mode, true, false) . ' />';
        echo '<label for="tbfw_debug_mode"> ' . esc_html__('Enable debugging mode', 'transfer-brands-for-woocommerce') . '</label>';
        echo '<p class="description">' . esc_html__('When enabled, the plugin will collect detailed logs about the brand transfer process. Use this if you encounter issues.', 'transfer-brands-for-woocommerce') . '</p>';
    }
    
    /**
     * Get active tab
     *
     * @since 2.3.0
     * @return string Active tab
     */
    private function get_active_tab() {
        return isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'transfer';
    }
    
    /**
     * Render tab navigation
     *
     * @since 2.3.0
     */
    private function render_tabs() {
        $active_tab = $this->get_active_tab();
        ?>
        <h2 class="nav-tab-wrapper">
            <a href="?page=tbfw-transfer-brands&tab=transfer" class="nav-tab <?php echo $active_tab == 'transfer' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Transfer Brands', 'transfer-brands-for-woocommerce'); ?>
            </a>
            <a href="?page=tbfw-transfer-brands&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Settings', 'transfer-brands-for-woocommerce'); ?>
            </a>
        </h2>
        <?php
    }
    
    /**
     * Render settings form
     *
     * @since 2.3.0
     */
    private function render_settings_form() {
        ?>
        <div class="tbfw-tb-settings-container">
            <form method="post" action="options.php">
                <?php
                settings_fields('tbfw_transfer_brands_settings');
                do_settings_sections('tbfw_transfer_brands_settings');
                submit_button(__('Save Settings', 'transfer-brands-for-woocommerce'));
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Debug page
     *
     * @since 2.3.0
     */
    public function debug_page() {
        global $wpdb;
        
        // Get the current debug log
        $debug_log = get_option('tbfw_brands_debug_log', []);
        
        // Get products with brand attribute
        $source_taxonomy = $this->core->get_option('source_taxonomy');
        
        // Properly prepare query with placeholders
        $products_data = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
                WHERE meta_key = '_product_attributes' 
                AND meta_value LIKE %s 
                LIMIT 20",
                '%' . $wpdb->esc_like($source_taxonomy) . '%'
            )
        );
        
        // Format the sample products data
        $sample_products = [];
        foreach ($products_data as $item) {
            $attributes = maybe_unserialize($item->meta_value);
            if (is_array($attributes) && isset($attributes[$source_taxonomy])) {
                $product = wc_get_product($item->post_id);
                if ($product) {
                    $sample_products[] = [
                        'id' => $item->post_id,
                        'name' => $product->get_name(),
                        'attribute' => $attributes[$source_taxonomy]
                    ];
                }
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Brand Transfer Debug Information', 'transfer-brands-for-woocommerce'); ?></h1>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h2><?php esc_html_e('Configuration', 'transfer-brands-for-woocommerce'); ?></h2>
                <table class="widefat">
                    <tr>
                        <td><strong><?php esc_html_e('Source Taxonomy:', 'transfer-brands-for-woocommerce'); ?></strong></td>
                        <td><?php echo esc_html($this->core->get_option('source_taxonomy')); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Destination Taxonomy:', 'transfer-brands-for-woocommerce'); ?></strong></td>
                        <td><?php echo esc_html($this->core->get_option('destination_taxonomy')); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Batch Size:', 'transfer-brands-for-woocommerce'); ?></strong></td>
                        <td><?php echo esc_html($this->core->get_option('batch_size')); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Backup Enabled:', 'transfer-brands-for-woocommerce'); ?></strong></td>
                        <td><?php echo $this->core->get_option('backup_enabled') ? esc_html__('Yes', 'transfer-brands-for-woocommerce') : esc_html__('No', 'transfer-brands-for-woocommerce'); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h2><?php esc_html_e('Sample Products with Brand Attribute', 'transfer-brands-for-woocommerce'); ?></h2>
                <p><?php 
                /* translators: %s: Source taxonomy name */
                printf(esc_html__('These are products that have the %s attribute:', 'transfer-brands-for-woocommerce'), '<code>' . esc_html($source_taxonomy) . '</code>'); 
                ?></p>
                
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'transfer-brands-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Product', 'transfer-brands-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Attribute Type', 'transfer-brands-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Value', 'transfer-brands-for-woocommerce'); ?></th>
                            <th><?php esc_html_e('Details', 'transfer-brands-for-woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sample_products as $product): ?>
                        <tr>
                            <td><?php echo esc_html($product['id']); ?></td>
                            <td><?php echo esc_html($product['name']); ?></td>
                            <td>
                                <?php 
                                if (isset($product['attribute']['is_taxonomy']) && $product['attribute']['is_taxonomy']) {
                                    esc_html_e('Taxonomy', 'transfer-brands-for-woocommerce');
                                } else {
                                    esc_html_e('Custom', 'transfer-brands-for-woocommerce');
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                if (isset($product['attribute']['value'])) {
                                    echo esc_html($product['attribute']['value']);
                                } elseif (isset($product['attribute']['options']) && is_array($product['attribute']['options'])) {
                                    echo esc_html(implode(', ', $product['attribute']['options']));
                                }
                                ?>
                            </td>
                            <td>
                                <button class="button" onclick="jQuery('#product-<?php echo esc_attr($product['id']); ?>').toggle();"><?php esc_html_e('Show Details', 'transfer-brands-for-woocommerce'); ?></button>
                                <div id="product-<?php echo esc_attr($product['id']); ?>" style="display: none; margin-top: 10px;">
                                    <?php $attr_dump = print_r($product['attribute'], true); ?>
                                    <pre><?php echo esc_html($attr_dump); ?></pre>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if (empty($sample_products)): ?>
                <p><?php esc_html_e('No products found with this attribute.', 'transfer-brands-for-woocommerce'); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h2><?php esc_html_e('Debug Log', 'transfer-brands-for-woocommerce'); ?></h2>
                <p><?php esc_html_e('This log shows detailed information about the brand transfer process:', 'transfer-brands-for-woocommerce'); ?></p>
                
                <?php if (empty($debug_log)): ?>
                <p><?php esc_html_e('No debug log entries found. Enable debug mode and perform operations to generate log entries.', 'transfer-brands-for-woocommerce'); ?></p>
                <?php else: ?>
                <div style="max-height: 400px; overflow-y: scroll; background: #f5f5f5; padding: 10px; margin-bottom: 10px;">
                    <?php foreach (array_reverse($debug_log) as $index => $entry): ?>
                    <div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #ddd;">
                        <strong>[<?php echo esc_html($entry['time']); ?>]</strong> <?php echo esc_html($entry['message']); ?>
                        <?php if (!empty($entry['data'])): ?>
                        <button class="button button-small" onclick="jQuery('#log-data-<?php echo esc_attr($index); ?>').toggle();"><?php esc_html_e('Show Data', 'transfer-brands-for-woocommerce'); ?></button>
                        <div id="log-data-<?php echo esc_attr($index); ?>" style="display: none; margin-top: 5px; padding: 5px; background: #fff;">
                            <?php $data_dump = print_r($entry['data'], true); ?>
                            <pre><?php echo esc_html($data_dump); ?></pre>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <button id="clear-debug-log" class="button"><?php esc_html_e('Clear Debug Log', 'transfer-brands-for-woocommerce'); ?></button>
                <?php endif; ?>
            </div>
        </div>
        
        <?php
        // Enqueue clear log script
        wp_enqueue_script(
            'tbfw-clear-debug-log',
            TBFW_ASSETS_URL . 'js/clear-debug-log.js',
            ['jquery'],
            TBFW_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('tbfw-clear-debug-log', 'tbfwDebug', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tbfw_transfer_brands_nonce'),
            'confirmClear' => __('Are you sure you want to clear the debug log?', 'transfer-brands-for-woocommerce')
        ]);
    }
    
    /**
     * Render the main admin page with progress bar and controls
     *
     * @since 2.3.0
     */
    public function admin_page() {
        // Avoid global cache flush here to prevent heavy performance impact
        
        // Get current tab
        $active_tab = $this->get_active_tab();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Transfer Brands for WooCommerce', 'transfer-brands-for-woocommerce'); ?></h1>
            
            <?php $this->render_tabs(); ?>
            
            <?php if ($active_tab === 'settings'): ?>
                <?php $this->render_settings_form(); ?>
            <?php else: ?>
                <?php $this->render_transfer_page(); ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render the transfer tab content
     *
     * @since 2.3.0
     */
    private function render_transfer_page() {
        // Get counts for display
        $source_count = $this->core->get_utils()->count_source_terms();
        $destination_count = $this->core->get_utils()->count_destination_terms();
        $products_with_source = $this->core->get_utils()->count_products_with_source();

        // Get backup information
        $transfer_backup = get_option('tbfw_transfer_brands_backup', false);
        $deleted_backup = get_option('tbfw_deleted_brands_backup', false);

        // Count products in deletion backup (for more accurate information)
        $deleted_products_count = $deleted_backup ? count($deleted_backup) : 0;

        // Check WooCommerce Brands status
        $brands_status = $this->core->get_utils()->check_woocommerce_brands_status();
        $can_transfer = $brands_status['enabled'];
        ?>

        <?php if (!$can_transfer): ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e('WooCommerce Brands Not Ready', 'transfer-brands-for-woocommerce'); ?></strong></p>
            <p><?php echo esc_html($brands_status['message']); ?></p>
            <?php if (!empty($brands_status['instructions'])): ?>
            <p><?php echo wp_kses_post($brands_status['instructions']); ?></p>
            <?php endif; ?>
            <?php if (!empty($brands_status['details'])): ?>
            <details style="margin-top: 10px;">
                <summary style="cursor: pointer; font-weight: 600;"><?php esc_html_e('Technical Details', 'transfer-brands-for-woocommerce'); ?></summary>
                <ul style="margin: 10px 0 0 20px; list-style-type: disc;">
                    <?php foreach ($brands_status['details'] as $detail): ?>
                    <li><?php echo esc_html($detail); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>
        </div>
        <?php elseif (!empty($brands_status['details']) && strpos(implode(' ', $brands_status['details']), 'Could not verify') !== false): ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e('Note', 'transfer-brands-for-woocommerce'); ?>:</strong> <?php echo esc_html($brands_status['message']); ?></p>
            <details>
                <summary style="cursor: pointer;"><?php esc_html_e('Details', 'transfer-brands-for-woocommerce'); ?></summary>
                <ul style="margin: 10px 0 0 20px; list-style-type: disc;">
                    <?php foreach ($brands_status['details'] as $detail): ?>
                    <li><?php echo esc_html($detail); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </div>
        <?php endif; ?>

        <div class="notice notice-info">
            <p><?php printf(
                /* translators: %1$s: Source taxonomy name, %2$s: Destination taxonomy name */
                esc_html__('This tool will transfer product brands from %1$s attribute to %2$s taxonomy.', 'transfer-brands-for-woocommerce'),
                '<strong>' . esc_html($this->core->get_option('source_taxonomy')) . '</strong>',
                '<strong>' . esc_html($this->core->get_option('destination_taxonomy')) . '</strong>'
            ); ?></p>
            <p><?php esc_html_e('You can change these settings in the Settings tab.', 'transfer-brands-for-woocommerce'); ?></p>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
            <h2><?php esc_html_e('Current Status', 'transfer-brands-for-woocommerce'); ?></h2>
            
            <?php 
                // Get debug info for counts
                $count_debug = get_option('tbfw_brands_count_debug', []); 
                
                // Get custom attribute details
                global $wpdb;
                $custom_attribute_count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} 
                        WHERE meta_key = '_product_attributes' 
                        AND meta_value LIKE %s
                        AND meta_value LIKE %s
                        AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')",
                        '%' . $wpdb->esc_like($this->core->get_option('source_taxonomy')) . '%',
                        '%"is_taxonomy";i:0;%'
                    )
                );
                
                $taxonomy_attribute_count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} 
                        WHERE meta_key = '_product_attributes' 
                        AND meta_value LIKE %s
                        AND meta_value LIKE %s
                        AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')",
                        '%' . $wpdb->esc_like($this->core->get_option('source_taxonomy')) . '%',
                        '%"is_taxonomy";i:1;%'
                    )
                );
            ?>
            
            <table class="widefat" style="margin-bottom: 20px;">
                <tr>
                    <td>
                        <strong><?php esc_html_e('Source terms:', 'transfer-brands-for-woocommerce'); ?></strong>
                        <span class="tbfw-tb-taxonomy-badge source"><?php echo esc_html($this->core->get_option('source_taxonomy')); ?></span>
                    </td>
                    <td><?php echo esc_html($source_count) . ' ' . esc_html__('brands', 'transfer-brands-for-woocommerce'); ?></td>
                </tr>
                <tr>
                    <td>
                        <strong><?php esc_html_e('Destination terms:', 'transfer-brands-for-woocommerce'); ?></strong>
                        <span class="tbfw-tb-taxonomy-badge destination"><?php echo esc_html($this->core->get_option('destination_taxonomy')); ?></span>
                    </td>
                    <td><?php echo esc_html($destination_count) . ' ' . esc_html__('brands', 'transfer-brands-for-woocommerce'); ?></td>
                </tr>
                <tr>
                    <td>
                        <strong><?php esc_html_e('Products with source brand:', 'transfer-brands-for-woocommerce'); ?></strong>
                        <a href="#" id="tbfw-tb-show-count-details" style="margin-left: 10px; font-size: 0.8em;">[<?php esc_html_e('Show details', 'transfer-brands-for-woocommerce'); ?>]</a>
                    </td>
                    <td><?php echo esc_html($products_with_source) . ' ' . esc_html__('products', 'transfer-brands-for-woocommerce'); ?></td>
                </tr>
                
                <tr id="tbfw-tb-count-details" style="display: none; background-color: #f8f8f8;">
                    <td colspan="2">
                        <div style="padding: 10px; border-left: 4px solid #2271b1;">
                            <p><strong><?php esc_html_e('Count details:', 'transfer-brands-for-woocommerce'); ?></strong></p>
                            <ul style="margin-left: 20px; list-style-type: disc;">
                                <li><?php esc_html_e('Products with custom (non-taxonomy) brand:', 'transfer-brands-for-woocommerce'); ?> <strong><?php echo esc_html($custom_attribute_count); ?></strong></li>
                                <li><?php esc_html_e('Products with taxonomy brand:', 'transfer-brands-for-woocommerce'); ?> <strong><?php echo esc_html($taxonomy_attribute_count); ?></strong></li>
                                <li><?php esc_html_e('Total products with any brand:', 'transfer-brands-for-woocommerce'); ?> <strong><?php echo esc_html($products_with_source); ?></strong></li>
                            </ul>
                            <p><em><?php esc_html_e('Note: The plugin will transfer both taxonomy and custom attributes.', 'transfer-brands-for-woocommerce'); ?></em></p>
                            <?php if ($this->core->get_option('debug_mode')): ?>
                            <p><a href="<?php echo esc_url(admin_url('admin.php?page=tbfw-transfer-brands-debug')); ?>" class="button"><?php esc_html_e('View Detailed Debug Info', 'transfer-brands-for-woocommerce'); ?></a></p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                
                <?php if ($transfer_backup || $deleted_backup): ?>
                <tr>
                    <td><strong><?php esc_html_e('Backups:', 'transfer-brands-for-woocommerce'); ?></strong></td>
                    <td>
                        <?php if ($transfer_backup): ?>
                            <?php esc_html_e('Transfer backup:', 'transfer-brands-for-woocommerce'); ?> <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transfer_backup['timestamp']))); ?>
                            <?php if (isset($transfer_backup['completed'])): ?>
                                (<?php esc_html_e('completed', 'transfer-brands-for-woocommerce'); ?>)
                            <?php endif; ?>
                            <br>
                        <?php endif; ?>
                        
                        <?php if ($deleted_backup): ?>
                            <?php esc_html_e('Deletion backup:', 'transfer-brands-for-woocommerce'); ?> <?php printf(
                                /* translators: %s: Number of products */
                                esc_html(_n('%s product', '%s products', count($deleted_backup), 'transfer-brands-for-woocommerce')),
                                esc_html(count($deleted_backup))
                            ); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            
            <div class="actions">
                <div class="action-container">
                    <button id="tbfw-tb-check" class="button action-button"
                            data-tooltip="<?php esc_attr_e('Scan your products and brands to identify potential issues before transferring', 'transfer-brands-for-woocommerce'); ?>">
                        <?php esc_html_e('Analyze Brands', 'transfer-brands-for-woocommerce'); ?>
                    </button>
                    <span class="action-description"><?php esc_html_e('Analyze your brands before transfer', 'transfer-brands-for-woocommerce'); ?></span>
                </div>
                
                <div class="action-container">
                    <button id="tbfw-tb-refresh-counts" class="button action-button"
                            data-tooltip="<?php esc_attr_e('Update the count statistics to reflect current database state', 'transfer-brands-for-woocommerce'); ?>">
                        <?php esc_html_e('Refresh Counts', 'transfer-brands-for-woocommerce'); ?>
                    </button>
                    <span class="action-description"><?php esc_html_e('Update statistics', 'transfer-brands-for-woocommerce'); ?></span>
                </div>
                
                <div class="action-container">
                    <button id="tbfw-tb-start" class="button button-primary action-button"
                            data-tooltip="<?php echo $can_transfer ? esc_attr__('Begin transferring brands from attribute to taxonomy', 'transfer-brands-for-woocommerce') : esc_attr__('WooCommerce Brands must be enabled first', 'transfer-brands-for-woocommerce'); ?>"
                            <?php echo !$can_transfer ? 'disabled' : ''; ?>>
                        <?php esc_html_e('Start Transfer', 'transfer-brands-for-woocommerce'); ?>
                    </button>
                    <span class="action-description">
                        <?php if ($can_transfer): ?>
                            <?php esc_html_e('Transfer brands to taxonomy', 'transfer-brands-for-woocommerce'); ?>
                        <?php else: ?>
                            <span style="color: #d63638;"><?php esc_html_e('Enable WooCommerce Brands first', 'transfer-brands-for-woocommerce'); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                
                <?php if ($products_with_source > 0): ?>
                <div class="action-container">
                    <button id="tbfw-tb-delete-old" class="button button-warning action-button"
                            data-tooltip="<?php esc_attr_e('Remove original brand attributes after successful transfer (requires confirmation)', 'transfer-brands-for-woocommerce'); ?>">
                        <?php esc_html_e('Delete Old Brands', 'transfer-brands-for-woocommerce'); ?>
                    </button>
                    <span class="action-description"><?php esc_html_e('Remove old brand attributes', 'transfer-brands-for-woocommerce'); ?></span>
                </div>
                <?php endif; ?>
                
                <?php 
                if ($transfer_backup && isset($transfer_backup['timestamp'])): 
                ?>
                <div class="action-container">
                    <button id="tbfw-tb-rollback" class="button button-secondary action-button"
                            data-tooltip="<?php esc_attr_e('Revert transfer to previous state', 'transfer-brands-for-woocommerce'); ?>">
                        <?php esc_html_e('Rollback Transfer', 'transfer-brands-for-woocommerce'); ?>
                    </button>
                    <span class="action-description"><?php esc_html_e('Undo transfer process', 'transfer-brands-for-woocommerce'); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($deleted_backup && !empty($deleted_backup)): ?>
                <div class="action-container">
                    <button id="tbfw-tb-rollback-delete" class="button button-secondary action-button"
                            data-tooltip="<?php esc_attr_e('Restore previously deleted brand attributes to products', 'transfer-brands-for-woocommerce'); ?>">
                        <?php esc_html_e('Restore Deleted Brands', 'transfer-brands-for-woocommerce'); ?>
                    </button>
                    <span class="action-description"><?php esc_html_e('Restore deleted attributes', 'transfer-brands-for-woocommerce'); ?></span>
                </div>
                <?php endif; ?>
                
                <?php 
                // Check if valid backups with timestamp exist
                if (($transfer_backup && isset($transfer_backup['timestamp'])) || 
                    ($deleted_backup && !empty($deleted_backup))): 
                ?>
                <div class="action-container">
                    <button id="tbfw-tb-cleanup" class="button action-button" style="border-color: #ccc;"
                            data-tooltip="<?php esc_attr_e('Remove all backup data (prevents rollback)', 'transfer-brands-for-woocommerce'); ?>">
                        <?php esc_html_e('Clean Up Backups', 'transfer-brands-for-woocommerce'); ?>
                    </button>
                    <span class="action-description"><?php esc_html_e('Delete all backup data', 'transfer-brands-for-woocommerce'); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="tbfw-tb-analysis" style="margin-top:20px; display:none;">
            <h3><?php esc_html_e('Analysis Results', 'transfer-brands-for-woocommerce'); ?></h3>
            <div id="tbfw-tb-analysis-content" class="card" style="padding: 15px;"></div>
        </div>
        
        <div id="tbfw-tb-progress" style="margin-top:20px; display:none;">
            <h3 id="tbfw-tb-progress-title"><?php esc_html_e('Transfer Progress', 'transfer-brands-for-woocommerce'); ?></h3>
            <div class="card" style="padding: 15px;">
                <div class="progress-info" style="margin-bottom: 10px;">
                    <div id="tbfw-tb-progress-stats" style="font-weight: bold; margin-bottom: 5px;"></div>
                    <div id="tbfw-tb-progress-warning" style="color: #d63638; margin-bottom: 5px; display: none;">
                        <strong><?php esc_html_e('WARNING:', 'transfer-brands-for-woocommerce'); ?></strong> <?php esc_html_e('Do not refresh the page until the process is complete!', 'transfer-brands-for-woocommerce'); ?>
                    </div>
                    <div id="tbfw-tb-timer" style="font-size: 0.9em; color: #555;"></div>
                </div>
                <progress id="tbfw-tb-progress-bar" value="0" max="100" style="width:100%; height: 20px;"></progress>
                <p id="tbfw-tb-progress-text"></p>
                <div id="tbfw-tb-log" style="margin-top: 15px; max-height: 200px; overflow-y: scroll; background: #f5f5f5; padding: 10px; display: none; font-family: monospace; font-size: 12px;"></div>
            </div>
        </div>
        
        <!-- Modal for delete confirmation -->
        <div id="tbfw-tb-delete-confirm-modal" class="tbfw-tb-modal">
            <div class="tbfw-tb-modal-content">
                <div class="tbfw-tb-modal-header">
                    <span class="tbfw-tb-modal-close">&times;</span>
                    <h2><?php esc_html_e('Confirm Deletion', 'transfer-brands-for-woocommerce'); ?></h2>
                </div>
                <div class="tbfw-tb-modal-body">
                    <p class="tbfw-tb-warning-text"><?php esc_html_e('Warning: This will permanently remove the original brand attributes from all products.', 'transfer-brands-for-woocommerce'); ?></p>
                    
                    <p><?php esc_html_e('This action cannot be undone unless you have backups enabled.', 'transfer-brands-for-woocommerce'); ?></p>
                    
                    <p><?php esc_html_e('To confirm, please type YES in the box below:', 'transfer-brands-for-woocommerce'); ?></p>
                    
                    <input type="text" id="tbfw-tb-delete-confirm-input" class="tbfw-tb-confirm-input" placeholder="<?php esc_attr_e('Type YES to confirm', 'transfer-brands-for-woocommerce'); ?>" />
                    
                    <div class="tbfw-tb-modal-buttons">
                        <button id="tbfw-tb-cancel-delete" class="button"><?php esc_html_e('Cancel', 'transfer-brands-for-woocommerce'); ?></button>
                        <button id="tbfw-tb-confirm-delete" class="button button-warning"><?php esc_html_e('Confirm Delete', 'transfer-brands-for-woocommerce'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}