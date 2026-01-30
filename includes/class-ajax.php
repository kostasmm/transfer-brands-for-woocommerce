<?php
/**
 * Ajax class for Transfer Brands for WooCommerce plugin
 *
 * Handles AJAX requests for the plugin
 * 
 * @package TBFW_Transfer_Brands
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TBFW_Transfer_Brands_Ajax {
    /**
     * Reference to core plugin instance
     */
    private $core;
    
    /**
     * Constructor
     * 
     * @param TBFW_Transfer_Brands_Core $core Core plugin instance
     */
    public function __construct($core) {
        $this->core = $core;
        
        // AJAX handlers
        add_action('wp_ajax_tbfw_transfer_brands', [$this, 'ajax_transfer']);
        add_action('wp_ajax_tbfw_check_brands', [$this, 'ajax_check_brands']);
        add_action('wp_ajax_tbfw_rollback_transfer', [$this, 'ajax_rollback_transfer']);
        add_action('wp_ajax_tbfw_rollback_deleted_brands', [$this, 'ajax_rollback_deleted_brands']);
        add_action('wp_ajax_tbfw_delete_old_brands', [$this, 'ajax_delete_old_brands']);
        add_action('wp_ajax_tbfw_cleanup_backups', [$this, 'ajax_cleanup_backups']);
        add_action('wp_ajax_tbfw_refresh_counts', [$this, 'ajax_refresh_counts']);
        add_action('wp_ajax_tbfw_view_debug_log', [$this, 'ajax_view_debug_log']);
        add_action('wp_ajax_tbfw_init_delete', [$this, 'ajax_init_delete']);
        
        // New AJAX handler for refreshing the destination taxonomy
        add_action('wp_ajax_tbfw_refresh_destination_taxonomy', [$this, 'ajax_refresh_destination_taxonomy']);
        
        // Preview transfer handler
        add_action('wp_ajax_tbfw_preview_transfer', [$this, 'ajax_preview_transfer']);

        // Quick source switch handler
        add_action('wp_ajax_tbfw_switch_source', [$this, 'ajax_switch_source']);

        // Review notice dismiss handler
        add_action('wp_ajax_tbfw_dismiss_review_notice', [$this, 'ajax_dismiss_review_notice']);

        // Verify transfer handler
        add_action('wp_ajax_tbfw_verify_transfer', [$this, 'ajax_verify_transfer']);
    }
    /**
     * Get user-friendly error message
     *
     * @since 2.9.0
     * @param string $technical_message Technical error message
     * @return array Array with 'message' and optional 'hint'
     */
    private function get_friendly_error($technical_message) {
        $friendly_errors = [
            'taxonomy_not_found' => [
                'message' => __('The brand taxonomy could not be found.', 'transfer-brands-for-woocommerce'),
                'hint' => __('Please check that WooCommerce Brands is activated.', 'transfer-brands-for-woocommerce')
            ],
            'invalid_taxonomy' => [
                'message' => __('The selected taxonomy is not valid.', 'transfer-brands-for-woocommerce'),
                'hint' => __('Go to Settings tab and verify your source/destination taxonomy settings.', 'transfer-brands-for-woocommerce')
            ],
            'term_exists' => [
                'message' => __('Some brands already exist in the destination.', 'transfer-brands-for-woocommerce'),
                'hint' => __('Existing brands will be reused automatically.', 'transfer-brands-for-woocommerce')
            ],
            'permission_denied' => [
                'message' => __('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'),
                'hint' => __('Please contact your site administrator.', 'transfer-brands-for-woocommerce')
            ],
            'no_products' => [
                'message' => __('No products found with the source brand attribute.', 'transfer-brands-for-woocommerce'),
                'hint' => __('Verify your source taxonomy setting matches your product attributes.', 'transfer-brands-for-woocommerce')
            ],
            'backup_failed' => [
                'message' => __('Could not create backup before transfer.', 'transfer-brands-for-woocommerce'),
                'hint' => __('Check your database permissions or try disabling backup in Settings.', 'transfer-brands-for-woocommerce')
            ],
        ];

        // Check for matches in technical message
        foreach ($friendly_errors as $key => $error) {
            if (stripos($technical_message, str_replace('_', ' ', $key)) !== false ||
                stripos($technical_message, $key) !== false) {
                return $error;
            }
        }

        // Return original message if no match found
        return [
            'message' => $technical_message,
            'hint' => ''
        ];
    }

    /**
     * Format error response with optional debug info
     *
     * @since 2.9.0
     * @param string $technical_message Technical error message
     * @return string Formatted error message
     */
    private function format_error_message($technical_message) {
        $friendly = $this->get_friendly_error($technical_message);
        $message = $friendly['message'];

        if (!empty($friendly['hint'])) {
            $message .= ' ' . $friendly['hint'];
        }

        // Add technical details only in debug mode
        if ($this->core->get_option('debug_mode') && $message !== $technical_message) {
            $message .= ' [' . esc_html($technical_message) . ']';
        }

        return $message;
    }

    
    /**
     * AJAX handler for processing the transfer in steps
     */
    public function ajax_transfer() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }

        $step = isset($_POST['step']) ? sanitize_text_field(wp_unslash($_POST['step'])) : 'backup';
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

        // Pre-transfer validation: Check if WooCommerce Brands is enabled
        if ($step === 'backup' && $offset === 0) {
            $brands_status = $this->core->get_utils()->check_woocommerce_brands_status();
            if (!$brands_status['enabled']) {
                $error_message = $brands_status['message'];
                if (!empty($brands_status['instructions'])) {
                    $error_message .= ' ' . wp_strip_all_tags($brands_status['instructions']);
                }
                wp_send_json_error([
                    'message' => $error_message,
                    'brands_not_enabled' => true,
                    'details' => $brands_status['details']
                ]);
                return;
            }
        }

        // Step 0: Create backup of the current terms and assignments
        if ($step === 'backup') {
            if ($offset === 0) {
                delete_option('tbfw_transfer_processed_products');
            }
            
            if (!$this->core->get_option('backup_enabled')) {
                // Skip backup if disabled
                wp_send_json_success([
                    'step' => 'terms',
                    'offset' => 0,
                    'percent' => 0,
                    'message' => 'Backup skipped, starting transfer...',
                    'log' => 'Backup skipped (disabled in settings)'
                ]);
                return;
            }
            
            // Create backup if it's the first run
            if ($offset === 0) {
                $backup_result = $this->core->get_backup()->create_backup();
                if (!$backup_result) {
                    wp_send_json_error([
                        'message' => __('Failed to create backup. Please check your database connection and try again.', 'transfer-brands-for-woocommerce')
                    ]);
                    return;
                }
            }

            // Backup completed, move to next step
            wp_send_json_success([
                'step' => 'terms',
                'offset' => 0,
                'percent' => 5,
                'message' => __('Backup created, starting transfer...', 'transfer-brands-for-woocommerce'),
                'log' => __('Backup completed successfully', 'transfer-brands-for-woocommerce')
            ]);
        }

        // Step 1: Transfer attribute terms to product_brand taxonomy
        if ($step === 'terms') {
            $result = $this->core->get_transfer()->process_terms_batch($offset);
            
            if (!$result['success']) {
                wp_send_json_error(['message' => $result['message']]);
                return;
            }
            
            wp_send_json_success($result);
        }

        // Step 2: Assign new brand terms to products
        if ($step === 'products') {
            $result = $this->core->get_transfer()->process_products_batch();
            
            if (!$result['success']) {
                wp_send_json_error(['message' => $result['message']]);
                return;
            }
            
            wp_send_json_success($result);
        }

        wp_send_json_error(['message' => 'Invalid step']);
    }
    
    /**
     * AJAX handler for checking brands
     *
     * @since 2.8.5 Added support for brand plugin taxonomies (pwb-brand, etc.)
     */
    public function ajax_check_brands() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }

        $source_taxonomy = $this->core->get_option('source_taxonomy');
        $is_brand_plugin = $this->core->get_utils()->is_brand_plugin_taxonomy($source_taxonomy);

        $source_terms = get_terms([
            'taxonomy' => $source_taxonomy,
            'hide_empty' => false
        ]);

        if (is_wp_error($source_terms)) {
            wp_send_json_error(['message' => $this->format_error_message($source_terms->get_error_message())]);
            return;
        }

        global $wpdb;

        $custom_attribute_count = 0;
        $custom_samples = [];
        $sample_products = [];

        // For brand plugin taxonomies, skip custom attribute checks (they don't use _product_attributes)
        if (!$is_brand_plugin) {
            // Get info about custom attributes (only for WooCommerce attributes)
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
            $custom_attribute_count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
                    WHERE meta_key = '_product_attributes'
                    AND meta_value LIKE %s
                    AND meta_value LIKE %s
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')",
                    '%' . $wpdb->esc_like($source_taxonomy) . '%',
                    '%"is_taxonomy";i:0;%'
                )
            );

            // Sample of products with custom attributes
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
            $custom_products = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                    WHERE meta_key = '_product_attributes'
                    AND meta_value LIKE %s
                    AND meta_value LIKE %s
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')
                    LIMIT 10",
                    '%' . $wpdb->esc_like($source_taxonomy) . '%',
                    '%"is_taxonomy";i:0;%'
                )
            );

            foreach ($custom_products as $item) {
                $attributes = maybe_unserialize($item->meta_value);
                if (is_array($attributes) && isset($attributes[$source_taxonomy])) {
                    $product = wc_get_product($item->post_id);
                    if ($product) {
                        $custom_samples[] = [
                            'id' => $item->post_id,
                            'name' => $product->get_name(),
                            'value' => $attributes[$source_taxonomy]['value'] ?? 'N/A'
                        ];
                    }
                }
            }
        }

        // Check if any terms already exist in destination
        $conflicting_terms = [];
        $terms_with_images = 0;

        foreach ($source_terms as $term) {
            $exists = term_exists($term->name, $this->core->get_option('destination_taxonomy'));
            if ($exists) {
                $conflicting_terms[] = $term->name;
            }

            // Check if term has image using the comprehensive detection method
            $transfer_instance = $this->core->get_transfer();
            // Use reflection to access the private method
            $reflection = new ReflectionClass($transfer_instance);
            $method = $reflection->getMethod('find_brand_image');
            $method->setAccessible(true);
            $image_id = $method->invoke($transfer_instance, $term->term_id);

            if ($image_id) {
                $terms_with_images++;
            }
        }

        // Get sample products - different logic for brand plugins vs WooCommerce attributes
        if ($is_brand_plugin) {
            // For brand plugins, query products via taxonomy relationship
            $products_query = new WP_Query([
                'post_type' => 'product',
                'posts_per_page' => 5,
                'post_status' => 'publish',
                'tax_query' => [
                    [
                        'taxonomy' => $source_taxonomy,
                        'operator' => 'EXISTS',
                    ]
                ]
            ]);

            if ($products_query->have_posts()) {
                foreach ($products_query->posts as $post) {
                    $product = wc_get_product($post->ID);
                    if (!$product) continue;

                    // Get terms directly from taxonomy
                    $product_terms = get_the_terms($post->ID, $source_taxonomy);
                    $term_names = [];

                    if ($product_terms && !is_wp_error($product_terms)) {
                        $term_names = wp_list_pluck($product_terms, 'name');
                    }

                    $sample_products[] = [
                        'id' => $post->ID,
                        'name' => $product->get_name(),
                        'brands' => $term_names
                    ];
                }
            }
        } else {
            // For WooCommerce attributes, use the original query
            $products_query = new WP_Query([
                'post_type' => 'product',
                'posts_per_page' => 5,
                'tax_query' => [
                    [
                        'taxonomy' => $source_taxonomy,
                        'operator' => 'EXISTS',
                    ]
                ]
            ]);

            if ($products_query->have_posts()) {
                foreach ($products_query->posts as $post) {
                    $product = wc_get_product($post->ID);
                    if (!$product) {
                        continue;
                    }
                    $attrs = $product->get_attributes();

                    if (isset($attrs[$source_taxonomy])) {
                        $terms = [];
                        foreach ($attrs[$source_taxonomy]->get_options() as $term_id) {
                            $term = get_term($term_id, $source_taxonomy);
                            if ($term && !is_wp_error($term)) {
                                $terms[] = $term->name;
                            }
                        }

                        $sample_products[] = [
                            'id' => $post->ID,
                            'name' => $product->get_name(),
                            'brands' => $terms
                        ];
                    }
                }
            }
        }

        // Add debug info
        $this->core->add_debug("Brand analysis performed", [
            'source_terms' => count($source_terms),
            'is_brand_plugin' => $is_brand_plugin,
            'custom_attribute_count' => $custom_attribute_count,
            'taxonomy_samples' => count($sample_products),
            'custom_samples' => count($custom_samples)
        ]);
        
        // Check WooCommerce Brands status
        $brands_status = $this->core->get_utils()->check_woocommerce_brands_status();

        // Build HTML response
        $html = '<div class="analysis-results">';

        // WooCommerce Brands Status Section
        $html .= '<h4>' . esc_html__('WooCommerce Brands Status', 'transfer-brands-for-woocommerce') . '</h4>';
        if ($brands_status['enabled']) {
            $html .= '<div class="notice notice-success inline" style="margin: 0 0 15px 0; padding: 10px 12px;">';
            $html .= '<p style="margin: 0;"><span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> <strong>' . esc_html($brands_status['message']) . '</strong></p>';
            $html .= '</div>';
        } else {
            $html .= '<div class="notice notice-error inline" style="margin: 0 0 15px 0; padding: 10px 12px;">';
            $html .= '<p style="margin: 0 0 5px 0;"><span class="dashicons dashicons-warning" style="color: #d63638;"></span> <strong>' . esc_html($brands_status['message']) . '</strong></p>';
            if (!empty($brands_status['instructions'])) {
                $html .= '<p style="margin: 0;">' . wp_kses_post($brands_status['instructions']) . '</p>';
            }
            $html .= '</div>';
        }

        if (!empty($brands_status['details'])) {
            $html .= '<details style="margin-bottom: 15px;">';
            $html .= '<summary style="cursor: pointer; font-weight: 600;">' . esc_html__('Technical Details', 'transfer-brands-for-woocommerce') . '</summary>';
            $html .= '<ul style="margin: 10px 0 0 20px; list-style-type: disc;">';
            foreach ($brands_status['details'] as $detail) {
                $html .= '<li>' . esc_html($detail) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</details>';
        }

        $html .= '<h4>' . esc_html__('Source Brands Summary', 'transfer-brands-for-woocommerce') . '</h4>';

        // Show different info for brand plugins vs WooCommerce attributes
        if ($is_brand_plugin) {
            $html .= '<div class="notice notice-info inline" style="margin: 0 0 15px 0; padding: 10px 12px;">';
            $html .= '<p style="margin: 0;"><span class="dashicons dashicons-info" style="color: #2271b1;"></span> ';
            $html .= '<strong>' . esc_html__('Brand Plugin Detected:', 'transfer-brands-for-woocommerce') . '</strong> ';
            $html .= esc_html__('Transferring from a third-party brand plugin taxonomy.', 'transfer-brands-for-woocommerce');
            $html .= '</p></div>';

            // Get product count for brand plugin
            $brand_plugin_product_count = $this->core->get_utils()->count_products_with_source();

            $html .= '<ul>';
            $html .= '<li><strong>' . count($source_terms) . '</strong> ' . esc_html__('brands found in taxonomy', 'transfer-brands-for-woocommerce') . ' <code>' . esc_html($source_taxonomy) . '</code></li>';
            $html .= '<li><strong>' . $brand_plugin_product_count . '</strong> ' . esc_html__('products have brands assigned', 'transfer-brands-for-woocommerce') . '</li>';
            $html .= '<li><strong>' . $terms_with_images . '</strong> ' . esc_html__('brands have images that will be transferred', 'transfer-brands-for-woocommerce') . '</li>';
            $html .= '</ul>';
        } else {
            $html .= '<ul>';
            $html .= '<li><strong>' . count($source_terms) . '</strong> ' . esc_html__('brands found in taxonomy', 'transfer-brands-for-woocommerce') . ' <code>' . esc_html($source_taxonomy) . '</code></li>';
            $html .= '<li><strong>' . $custom_attribute_count . '</strong> ' . esc_html__('products have custom (non-taxonomy) attributes with name', 'transfer-brands-for-woocommerce') . ' <code>' . esc_html($source_taxonomy) . '</code></li>';
            $html .= '<li><strong>' . $terms_with_images . '</strong> ' . esc_html__('brands have images that will be transferred', 'transfer-brands-for-woocommerce') . '</li>';
            $html .= '</ul>';
        }

        // Warning about custom attributes (only for WooCommerce attributes)
        if (!$is_brand_plugin && $custom_attribute_count > 0) {
            $html .= '<div class="notice notice-warning inline" style="margin-top: 15px;">';
            $html .= '<p><strong>Custom Attributes Detected:</strong> Some of your products use custom (non-taxonomy) attributes for brands.</p>';
            $html .= '<p>The plugin will attempt to convert these to taxonomy terms based on their values.</p>';
            
            if (!empty($custom_samples)) {
                $html .= '<p><strong>Examples:</strong></p>';
                $html .= '<table class="widefat" style="margin-top: 10px;">';
                $html .= '<thead><tr><th>ID</th><th>Product</th><th>Value</th></tr></thead>';
                $html .= '<tbody>';
                
                foreach ($custom_samples as $product) {
                    $html .= '<tr>';
                    $html .= '<td>' . $product['id'] . '</td>';
                    $html .= '<td>' . esc_html($product['name']) . '</td>';
                    $html .= '<td>' . esc_html($product['value']) . '</td>';
                    $html .= '</tr>';
                }
                
                $html .= '</tbody></table>';
            }
            
            $html .= '</div>';
        }
        
        if (!empty($conflicting_terms)) {
            $html .= '<div class="notice notice-warning inline" style="margin-top: 15px;">';
            $html .= '<p><strong>Warning:</strong> The following brand names already exist in the destination taxonomy:</p>';
            $html .= '<ul style="margin-left: 20px; list-style-type: disc;">';

            $displayed_terms = array_slice($conflicting_terms, 0, 10);
            foreach ($displayed_terms as $term) {
                $html .= '<li>' . esc_html($term) . '</li>';
            }

            if (count($conflicting_terms) > 10) {
                $html .= '<li>...and ' . (count($conflicting_terms) - 10) . ' more</li>';
            }

            $html .= '</ul>';
            $html .= '<p>Existing brands will be reused and not duplicated.</p>';
            $html .= '</div>';
        }

        // Check for products with multiple brands (important for brand plugins)
        if ($is_brand_plugin) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
            $multi_brand_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT object_id, COUNT(*) as brand_count
                    FROM {$wpdb->term_relationships} tr
                    JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE tt.taxonomy = %s
                    GROUP BY object_id
                    HAVING brand_count > 1
                ) AS multi",
                $source_taxonomy
            ));

            if ($multi_brand_count > 0) {
                // Get the actual products with multiple brands (up to 20 for display in analysis)
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
                $multi_brand_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT object_id FROM (
                        SELECT object_id, COUNT(*) as brand_count
                        FROM {$wpdb->term_relationships} tr
                        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                        WHERE tt.taxonomy = %s
                        GROUP BY object_id
                        HAVING brand_count > 1
                    ) AS multi
                    LIMIT 20",
                    $source_taxonomy
                ));

                $html .= '<div class="notice notice-warning inline" style="margin-top: 15px;">';
                $html .= '<p><strong>' . esc_html__('Multiple Brands Detected:', 'transfer-brands-for-woocommerce') . '</strong> ';
                $html .= sprintf(
                    /* translators: %d: Number of products with multiple brands */
                    esc_html__('%d products have multiple brands assigned. These will all be transferred, but you may want to review them.', 'transfer-brands-for-woocommerce'),
                    $multi_brand_count
                );
                $html .= '</p>';

                $html .= '<details style="margin-top: 10px;">';
                $html .= '<summary style="cursor: pointer; color: #2271b1; font-weight: 600;">' . esc_html__('View products with multiple brands', 'transfer-brands-for-woocommerce') . '</summary>';
                $html .= '<table class="widefat striped" style="margin-top: 10px;">';
                $html .= '<thead><tr>';
                $html .= '<th>' . esc_html__('ID', 'transfer-brands-for-woocommerce') . '</th>';
                $html .= '<th>' . esc_html__('Product', 'transfer-brands-for-woocommerce') . '</th>';
                $html .= '<th>' . esc_html__('Brands Assigned', 'transfer-brands-for-woocommerce') . '</th>';
                $html .= '<th>' . esc_html__('Action', 'transfer-brands-for-woocommerce') . '</th>';
                $html .= '</tr></thead>';
                $html .= '<tbody>';

                foreach ($multi_brand_ids as $product_id) {
                    $product = wc_get_product($product_id);
                    if (!$product) continue;

                    $product_terms = get_the_terms($product_id, $source_taxonomy);
                    $brand_names = [];
                    if ($product_terms && !is_wp_error($product_terms)) {
                        $brand_names = wp_list_pluck($product_terms, 'name');
                    }

                    $html .= '<tr>';
                    $html .= '<td>' . esc_html($product_id) . '</td>';
                    $html .= '<td>' . esc_html($product->get_name()) . '</td>';
                    $html .= '<td>' . esc_html(implode(', ', $brand_names)) . '</td>';
                    $html .= '<td><a href="' . esc_url(get_edit_post_link($product_id, 'raw')) . '" target="_blank" class="button button-small">' . esc_html__('Edit', 'transfer-brands-for-woocommerce') . '</a></td>';
                    $html .= '</tr>';
                }

                $html .= '</tbody></table>';

                if ($multi_brand_count > 20) {
                    $html .= '<p style="margin-top: 10px;"><em>' . sprintf(
                        /* translators: %d: number of additional products not shown */
                        esc_html__('...and %d more products. Use "Preview Transfer" for a complete list.', 'transfer-brands-for-woocommerce'),
                        $multi_brand_count - 20
                    ) . '</em></p>';
                }

                $html .= '</details>';
                $html .= '</div>';
            }
        }

        if (!empty($sample_products)) {
            if ($is_brand_plugin) {
                $html .= '<h4>' . esc_html__('Sample Products with Brand Plugin Brands', 'transfer-brands-for-woocommerce') . '</h4>';
                $html .= '<p>' . esc_html__('Here are some products with brands from the brand plugin:', 'transfer-brands-for-woocommerce') . '</p>';
            } else {
                $html .= '<h4>' . esc_html__('Sample Products with Brand Attributes', 'transfer-brands-for-woocommerce') . '</h4>';
                $html .= '<p>' . esc_html__('Here are some products with taxonomy brand attributes:', 'transfer-brands-for-woocommerce') . '</p>';
            }
            $html .= '<table class="widefat" style="margin-top: 10px;">';
            $html .= '<thead><tr><th>ID</th><th>' . esc_html__('Product', 'transfer-brands-for-woocommerce') . '</th><th>' . esc_html__('Current Brands', 'transfer-brands-for-woocommerce') . '</th></tr></thead>';
            $html .= '<tbody>';

            foreach ($sample_products as $product) {
                $html .= '<tr>';
                $html .= '<td>' . esc_html($product['id']) . '</td>';
                $html .= '<td>' . esc_html($product['name']) . '</td>';
                $html .= '<td>' . esc_html(implode(', ', $product['brands'])) . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }
        
        $html .= '</div>';
        
        wp_send_json_success(['html' => $html]);
    }
    
    /**
     * AJAX handler for rolling back a transfer
     */
    public function ajax_rollback_transfer() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        $result = $this->core->get_backup()->rollback_transfer();
        
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
            return;
        }
        
        wp_send_json_success(['message' => $result['message']]);
    }
    
    /**
     * AJAX handler for restoring deleted brands
     */
    public function ajax_rollback_deleted_brands() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        $result = $this->core->get_backup()->rollback_deleted_brands();
        
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
            return;
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * Initialize the deletion process by clearing processed products data
     * 
     * @since 2.5.0 Improved to ensure clean state before deletion
     */
    public function ajax_init_delete() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        // Make sure we completely remove previous data
        delete_option('tbfw_brands_processed_ids');
        
        // Add debug log
        $this->core->add_debug("Initialized delete old brands process", [
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        ]);
        
        wp_send_json_success(['message' => 'Delete process initialized, all previous progress has been reset.']);
    }
    
    /**
     * AJAX handler for deleting old brands from products
     *
     * This method processes products in batches, removing the old brand attributes
     * while tracking successfully processed products to avoid duplication.
     *
     * @since 2.5.0 Improved to track processed products by ID and ensure complete processing
     * @since 2.6.0 Fixed SQL security issues
     * @since 2.8.8 Added support for brand plugin taxonomies (pwb-brand, yith_product_brand)
     */
    public function ajax_delete_old_brands() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = $this->core->get_batch_size();
        $source_taxonomy = $this->core->get_option('source_taxonomy');

        // Check if this is a brand plugin taxonomy (pwb-brand, yith_product_brand) vs WooCommerce attribute
        $is_brand_plugin = $this->core->get_utils()->is_brand_plugin_taxonomy($source_taxonomy);

        global $wpdb;

        // Get previously processed product IDs
        $processed_ids = get_option('tbfw_brands_processed_ids', []);

        // Different query logic for brand plugin taxonomies vs WooCommerce attributes
        if ($is_brand_plugin) {
            // For brand plugin taxonomies, query products via taxonomy relationship
            $product_ids = $this->get_brand_plugin_products_for_delete($source_taxonomy, $processed_ids, $batch_size);
            $total = $this->count_brand_plugin_products_for_delete($source_taxonomy);
            $remaining = $total - count($processed_ids);
        } else {
            // For WooCommerce attributes, use the _product_attributes meta query
            $query_args = [
                '%' . $wpdb->esc_like($source_taxonomy) . '%',
                $batch_size
            ];

            $query = "SELECT DISTINCT post_id
                     FROM {$wpdb->postmeta}
                     WHERE meta_key = '_product_attributes'
                     AND meta_value LIKE %s
                     AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')";

            // Add exclusion for already processed products
            if (!empty($processed_ids)) {
                $placeholders = implode(',', array_fill(0, count($processed_ids), '%d'));
                $query .= " AND post_id NOT IN ($placeholders)";
                $query_args = array_merge([$query_args[0]], $processed_ids, [$query_args[1]]);
            }

            $query .= " ORDER BY post_id ASC LIMIT %d";

            // Get products to process
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built dynamically with proper placeholders and $wpdb->prepare() handles escaping
            $product_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));

            // Check for database errors
            if ($wpdb->last_error) {
                $this->core->add_debug("Database error retrieving products for deletion", [
                    'error' => $wpdb->last_error
                ]);
                wp_send_json_error([
                    'message' => __('Database error retrieving products. Please try again.', 'transfer-brands-for-woocommerce')
                ]);
                return;
            }

            // Count remaining products for progress
            $remaining_query = "SELECT COUNT(DISTINCT post_id)
                               FROM {$wpdb->postmeta}
                               WHERE meta_key = '_product_attributes'
                               AND meta_value LIKE %s
                               AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')";

            $remaining_args = ['%' . $wpdb->esc_like($source_taxonomy) . '%'];

            if (!empty($processed_ids)) {
                $placeholders = implode(',', array_fill(0, count($processed_ids), '%d'));
                $remaining_query .= " AND post_id NOT IN ($placeholders)";
                $remaining_args = array_merge($remaining_args, $processed_ids);
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is built dynamically, migration tool requires direct query
            $remaining = $wpdb->get_var($wpdb->prepare($remaining_query, $remaining_args));

            // Check for database errors and ensure $remaining is numeric
            if ($wpdb->last_error) {
                $this->core->add_debug("Database error counting remaining products", [
                    'error' => $wpdb->last_error
                ]);
                wp_send_json_error([
                    'message' => __('Database error counting products. Please try again.', 'transfer-brands-for-woocommerce')
                ]);
                return;
            }

            $remaining = (int) ($remaining ?? 0);

            // Total is remaining plus already processed
            $total = $remaining + count($processed_ids);
        }

        $this->core->add_debug("Deleting old brands batch", [
            'batch_size' => $batch_size,
            'found_products' => count($product_ids),
            'total_remaining' => $remaining,
            'total_processed' => count($processed_ids),
            'total_products' => $total,
            'is_brand_plugin' => $is_brand_plugin,
            'source_taxonomy' => $source_taxonomy
        ]);

        if (empty($product_ids)) {
            wp_send_json_success([
                'complete' => true,
                'percent' => 100,
                'message' => 'All products have been processed. No more products with old brands found.',
                'total' => $total,
                'processed' => count($processed_ids)
            ]);
            return;
        }

        $log_message = '';
        $processed = 0;
        $actual_modified = 0;

        // List of successfully processed IDs in this batch
        $newly_processed_ids = [];

        // Check if backup is enabled
        $backup_enabled = $this->core->get_option('backup_enabled');

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            $processed++;

            if ($is_brand_plugin) {
                // For brand plugin taxonomies, get terms and remove via wp_remove_object_terms
                $source_terms = get_the_terms($product_id, $source_taxonomy);

                if ($source_terms && !is_wp_error($source_terms)) {
                    // Create backup if enabled
                    if ($backup_enabled) {
                        $this->core->get_backup()->backup_brand_plugin_terms($product_id, $source_terms, $source_taxonomy);
                    }

                    // Remove all terms of this taxonomy from the product
                    $term_ids = wp_list_pluck($source_terms, 'term_id');
                    wp_remove_object_terms($product_id, $term_ids, $source_taxonomy);

                    $actual_modified++;

                    $this->core->add_debug("Deleted brand plugin terms from product", [
                        'product_id' => $product_id,
                        'product_name' => $product->get_name(),
                        'taxonomy' => $source_taxonomy,
                        'removed_terms' => wp_list_pluck($source_terms, 'name'),
                        'backup_created' => $backup_enabled
                    ]);
                }
            } else {
                // For WooCommerce attributes, use the original logic
                $attributes = $product->get_attributes();

                // Check if the product has the old brand attribute
                if (isset($attributes[$source_taxonomy])) {
                    // Create backup if enabled
                    if ($backup_enabled) {
                        $this->core->get_backup()->backup_product_attribute($product_id, $attributes[$source_taxonomy]);
                    }

                    // Remove the attribute
                    unset($attributes[$source_taxonomy]);

                    // Update the product
                    $product->set_attributes($attributes);
                    $product->save();

                    $actual_modified++;

                    $this->core->add_debug("Deleted old brand attribute from product", [
                        'product_id' => $product_id,
                        'product_name' => $product->get_name(),
                        'backup_created' => $backup_enabled
                    ]);
                }
            }

            // Add to processed IDs
            $newly_processed_ids[] = $product_id;
        }

        // Update processed IDs
        $processed_ids = array_merge($processed_ids, $newly_processed_ids);
        update_option('tbfw_brands_processed_ids', $processed_ids);

        // Calculate progress percentage based on total and processed
        $processed_count = count($processed_ids);
        $percent = min(100, round(($processed_count / max(1, $total)) * 100));

        // Detailed log message
        $type_label = $is_brand_plugin ? 'brand terms' : 'brand attributes';
        $log_message = "Removed old {$type_label} from {$actual_modified} products in this batch (examined {$processed})";
        if ($backup_enabled) {
            $log_message .= " - Backups created";
        } else {
            $log_message .= " - No backups created";
        }

        // Check if we're done
        $complete = ($remaining <= count($product_ids));

        wp_send_json_success([
            'complete' => $complete,
            'offset' => 0, // No need for offset anymore since we exclude by ID
            'total' => $total,
            'processed' => $processed_count,
            'percent' => $percent,
            'message' => "Processing products: {$processed_count} of {$total}",
            'log' => $log_message,
            'batch_processed' => $actual_modified,
            'batch_examined' => $processed,
            'backup_created' => $backup_enabled
        ]);
    }

    /**
     * Get products from a brand plugin taxonomy for deletion
     *
     * @since 2.8.8
     * @param string $taxonomy The brand plugin taxonomy (e.g., pwb-brand)
     * @param array $exclude_ids Product IDs to exclude (already processed)
     * @param int $batch_size Number of products to return
     * @return array Array of product IDs
     */
    private function get_brand_plugin_products_for_delete($taxonomy, $exclude_ids = [], $batch_size = 50) {
        global $wpdb;

        // Build query to get products with this taxonomy
        $query = "SELECT DISTINCT tr.object_id
                  FROM {$wpdb->term_relationships} tr
                  INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                  INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                  WHERE tt.taxonomy = %s
                  AND p.post_type = 'product'
                  AND p.post_status = 'publish'";

        $query_args = [$taxonomy];

        // Exclude already processed products
        if (!empty($exclude_ids)) {
            $placeholders = implode(',', array_fill(0, count($exclude_ids), '%d'));
            $query .= " AND tr.object_id NOT IN ($placeholders)";
            $query_args = array_merge($query_args, $exclude_ids);
        }

        $query .= " ORDER BY tr.object_id ASC LIMIT %d";
        $query_args[] = $batch_size;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built dynamically with proper placeholders and $wpdb->prepare() handles escaping
        return $wpdb->get_col($wpdb->prepare($query, $query_args));
    }

    /**
     * Count total products with a brand plugin taxonomy for deletion
     *
     * @since 2.8.8
     * @param string $taxonomy The brand plugin taxonomy
     * @return int Total number of products
     */
    private function count_brand_plugin_products_for_delete($taxonomy) {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT tr.object_id)
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                WHERE tt.taxonomy = %s
                AND p.post_type = 'product'
                AND p.post_status = 'publish'",
                $taxonomy
            )
        );
    }
    
    /**
     * AJAX handler for cleaning up all backups
     */
    public function ajax_cleanup_backups() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        $result = $this->core->get_backup()->cleanup_backups();
        
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
            return;
        }
        
        wp_send_json_success(['message' => $result['message']]);
    }
    
    /**
     * AJAX handler for refreshing counts
     */
    public function ajax_refresh_counts() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        // Delete transients that might affect the counts
        delete_transient('tbfw_count_comments');
        delete_transient('wc_product_count');
        delete_transient('wc_term_counts');
        
        // Clear WooCommerce-related cache keys where possible without global flush
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('product-transient-version', 'transient');
        }
        
        // Clear WooCommerce-related counts
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }
        
        // Update the counts
        $source_count = $this->core->get_utils()->count_source_terms();
        $destination_count = $this->core->get_utils()->count_destination_terms();
        $products_with_source = $this->core->get_utils()->count_products_with_source();
        
        $this->core->add_debug("Refreshed counts", [
            'source_count' => $source_count,
            'destination_count' => $destination_count,
            'products_with_source' => $products_with_source
        ]);
        
        // Return the updated counts
        wp_send_json_success([
            'source_count' => $source_count,
            'destination_count' => $destination_count,
            'products_with_source' => $products_with_source,
            'message' => 'Counts refreshed successfully.'
        ]);
    }
    
    /**
     * AJAX handler for viewing/clearing debug log
     */
    public function ajax_view_debug_log() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        if (isset($_POST['clear']) && sanitize_text_field(wp_unslash($_POST['clear']))) {
            delete_option('tbfw_brands_debug_log');
            wp_send_json_success(['message' => 'Debug log cleared']);
            return;
        }
        
        $debug_log = get_option('tbfw_brands_debug_log', []);
        wp_send_json_success(['log' => $debug_log]);
    }
    
    /**
     * AJAX handler for refreshing destination taxonomy
     */
    public function ajax_refresh_destination_taxonomy() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        // Refresh the destination taxonomy
        $taxonomy = $this->core->reload_destination_taxonomy();
        
        if ($taxonomy) {
            wp_send_json_success([
                'taxonomy' => $taxonomy,
                /* translators: %s: taxonomy name */
                'message' => sprintf(__('Destination taxonomy updated to: %s', 'transfer-brands-for-woocommerce'), $taxonomy)
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to update destination taxonomy', 'transfer-brands-for-woocommerce')
            ]);
        }
    }

    /**
     * AJAX handler for previewing transfer (dry run)
     *
     * Shows what would happen without making any changes
     *
     * @since 2.9.0
     */
    public function ajax_preview_transfer() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }

        $source_taxonomy = $this->core->get_option('source_taxonomy');
        $destination_taxonomy = $this->core->get_option('destination_taxonomy');
        $is_brand_plugin = $this->core->get_utils()->is_brand_plugin_taxonomy($source_taxonomy);

        // Get source terms
        $source_terms = get_terms([
            'taxonomy' => $source_taxonomy,
            'hide_empty' => false
        ]);

        if (is_wp_error($source_terms)) {
            wp_send_json_error(['message' => $this->format_error_message($source_terms->get_error_message())]);
            return;
        }

        // Analyze what would happen
        $brands_to_create = 0;
        $brands_existing = 0;
        $brands_with_images = 0;
        $existing_brand_names = [];
        $new_brand_names = [];

        foreach ($source_terms as $term) {
            $exists = term_exists($term->name, $destination_taxonomy);
            if ($exists) {
                $brands_existing++;
                $existing_brand_names[] = $term->name;
            } else {
                $brands_to_create++;
                $new_brand_names[] = $term->name;
            }

            // Check for image
            $transfer_instance = $this->core->get_transfer();
            $reflection = new ReflectionClass($transfer_instance);
            $method = $reflection->getMethod('find_brand_image');
            $method->setAccessible(true);
            $image_id = $method->invoke($transfer_instance, $term->term_id);
            if ($image_id) {
                $brands_with_images++;
            }
        }

        // Count products that would be affected
        $products_to_update = $this->core->get_utils()->count_products_with_source();

        // Check for potential issues
        $issues = [];
        $multi_brand_products = [];

        // Check for products with multiple brands
        global $wpdb;
        if ($is_brand_plugin) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
            $multi_brand_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT object_id, COUNT(*) as brand_count
                    FROM {$wpdb->term_relationships} tr
                    JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE tt.taxonomy = %s
                    GROUP BY object_id
                    HAVING brand_count > 1
                ) AS multi",
                $source_taxonomy
            ));

            // Get the actual products with multiple brands (up to 50 for display)
            if ($multi_brand_count > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
                $multi_brand_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT object_id FROM (
                        SELECT object_id, COUNT(*) as brand_count
                        FROM {$wpdb->term_relationships} tr
                        JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                        WHERE tt.taxonomy = %s
                        GROUP BY object_id
                        HAVING brand_count > 1
                    ) AS multi
                    LIMIT 50",
                    $source_taxonomy
                ));

                foreach ($multi_brand_ids as $product_id) {
                    $product = wc_get_product($product_id);
                    if (!$product) continue;

                    $product_terms = get_the_terms($product_id, $source_taxonomy);
                    $brand_names = [];
                    if ($product_terms && !is_wp_error($product_terms)) {
                        $brand_names = wp_list_pluck($product_terms, 'name');
                    }

                    $multi_brand_products[] = [
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'edit_url' => get_edit_post_link($product_id, 'raw'),
                        'brands' => $brand_names
                    ];
                }
            }
        } else {
            $multi_brand_count = 0; // For attributes, this is handled differently
        }

        if ($multi_brand_count > 0) {
            $issues[] = [
                'type' => 'warning',
                'message' => sprintf(
                    /* translators: %d: Number of products with multiple brands */
                    __('%d products have multiple brands assigned', 'transfer-brands-for-woocommerce'),
                    $multi_brand_count
                ),
                'products' => $multi_brand_products,
                'total_count' => $multi_brand_count
            ];
        }

        // Check WooCommerce Brands status
        $brands_status = $this->core->get_utils()->check_woocommerce_brands_status();
        if (!$brands_status['enabled']) {
            $issues[] = [
                'type' => 'error',
                'message' => $brands_status['message']
            ];
        }

        // Build HTML response
        $html = '<div class="tbfw-preview-summary">';

        // Brands to create
        $html .= '<div class="tbfw-preview-item success">';
        $html .= '<div class="tbfw-preview-item-value">' . esc_html($brands_to_create) . '</div>';
        $html .= '<div class="tbfw-preview-item-label">' . esc_html__('Brands to Create', 'transfer-brands-for-woocommerce') . '</div>';
        $html .= '</div>';

        // Brands existing
        $html .= '<div class="tbfw-preview-item info">';
        $html .= '<div class="tbfw-preview-item-value">' . esc_html($brands_existing) . '</div>';
        $html .= '<div class="tbfw-preview-item-label">' . esc_html__('Will Be Reused', 'transfer-brands-for-woocommerce') . '</div>';
        $html .= '</div>';

        // Products to update
        $html .= '<div class="tbfw-preview-item success">';
        $html .= '<div class="tbfw-preview-item-value">' . esc_html($products_to_update) . '</div>';
        $html .= '<div class="tbfw-preview-item-label">' . esc_html__('Products to Update', 'transfer-brands-for-woocommerce') . '</div>';
        $html .= '</div>';

        // Images to transfer
        $html .= '<div class="tbfw-preview-item info">';
        $html .= '<div class="tbfw-preview-item-value">' . esc_html($brands_with_images) . '</div>';
        $html .= '<div class="tbfw-preview-item-label">' . esc_html__('Brand Images', 'transfer-brands-for-woocommerce') . '</div>';
        $html .= '</div>';

        $html .= '</div>'; // .tbfw-preview-summary

        // Show issues if any
        if (!empty($issues)) {
            $html .= '<div class="notice notice-warning inline" style="margin: 15px 0;">';
            $html .= '<p><strong>' . esc_html__('Potential Issues:', 'transfer-brands-for-woocommerce') . '</strong></p>';
            $html .= '<ul style="margin-left: 20px; list-style-type: disc;">';
            foreach ($issues as $issue) {
                $html .= '<li>' . esc_html($issue['message']);

                // If this issue has product details, show them in an expandable section
                if (!empty($issue['products'])) {
                    $html .= '<details style="margin-top: 10px;">';
                    $html .= '<summary style="cursor: pointer; color: #2271b1;">' . esc_html__('View affected products', 'transfer-brands-for-woocommerce') . '</summary>';
                    $html .= '<table class="widefat striped" style="margin-top: 10px;">';
                    $html .= '<thead><tr>';
                    $html .= '<th>' . esc_html__('ID', 'transfer-brands-for-woocommerce') . '</th>';
                    $html .= '<th>' . esc_html__('Product', 'transfer-brands-for-woocommerce') . '</th>';
                    $html .= '<th>' . esc_html__('Brands Assigned', 'transfer-brands-for-woocommerce') . '</th>';
                    $html .= '<th>' . esc_html__('Action', 'transfer-brands-for-woocommerce') . '</th>';
                    $html .= '</tr></thead>';
                    $html .= '<tbody>';

                    foreach ($issue['products'] as $product) {
                        $html .= '<tr>';
                        $html .= '<td>' . esc_html($product['id']) . '</td>';
                        $html .= '<td>' . esc_html($product['name']) . '</td>';
                        $html .= '<td>' . esc_html(implode(', ', $product['brands'])) . '</td>';
                        $html .= '<td><a href="' . esc_url($product['edit_url']) . '" target="_blank" class="button button-small">' . esc_html__('Edit', 'transfer-brands-for-woocommerce') . '</a></td>';
                        $html .= '</tr>';
                    }

                    $html .= '</tbody></table>';

                    if (isset($issue['total_count']) && $issue['total_count'] > count($issue['products'])) {
                        $html .= '<p style="margin-top: 10px;"><em>' . sprintf(
                            /* translators: %d: number of additional products not shown */
                            esc_html__('...and %d more products not shown. Fix these first, then run preview again.', 'transfer-brands-for-woocommerce'),
                            $issue['total_count'] - count($issue['products'])
                        ) . '</em></p>';
                    }

                    $html .= '</details>';
                }

                $html .= '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        // Details section
        $html .= '<details class="tbfw-preview-details">';
        $html .= '<summary>' . esc_html__('View Brand Details', 'transfer-brands-for-woocommerce') . '</summary>';

        if (!empty($new_brand_names)) {
            $html .= '<p><strong>' . esc_html__('Brands to be created:', 'transfer-brands-for-woocommerce') . '</strong></p>';
            $html .= '<ul class="tbfw-preview-list">';
            $display_brands = array_slice($new_brand_names, 0, 10);
            foreach ($display_brands as $name) {
                $html .= '<li>' . esc_html($name) . '</li>';
            }
            if (count($new_brand_names) > 10) {
                $html .= '<li><em>' . sprintf(
                    /* translators: %d: Number of additional items not shown */
                    esc_html__('...and %d more', 'transfer-brands-for-woocommerce'),
                    count($new_brand_names) - 10
                ) . '</em></li>';
            }
            $html .= '</ul>';
        }

        if (!empty($existing_brand_names)) {
            $html .= '<p><strong>' . esc_html__('Existing brands (will be reused):', 'transfer-brands-for-woocommerce') . '</strong></p>';
            $html .= '<ul class="tbfw-preview-list">';
            $display_existing = array_slice($existing_brand_names, 0, 10);
            foreach ($display_existing as $name) {
                $html .= '<li>' . esc_html($name) . '</li>';
            }
            if (count($existing_brand_names) > 10) {
                $html .= '<li><em>' . sprintf(
                    /* translators: %d: Number of additional items not shown */
                    esc_html__('...and %d more', 'transfer-brands-for-woocommerce'),
                    count($existing_brand_names) - 10
                ) . '</em></li>';
            }
            $html .= '</ul>';
        }

        $html .= '</details>';

        wp_send_json_success([
            'html' => $html,
            'summary' => [
                'brands_to_create' => $brands_to_create,
                'brands_existing' => $brands_existing,
                'products_to_update' => $products_to_update,
                'brands_with_images' => $brands_with_images,
                'has_issues' => !empty($issues)
            ]
        ]);
    }


    /**
     * Switch source taxonomy via AJAX
     * 
     * @since 2.9.0
     */
    public function ajax_switch_source() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce')]);
            return;
        }

        $new_taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field(wp_unslash($_POST['taxonomy'])) : '';

        if (empty($new_taxonomy)) {
            wp_send_json_error(['message' => __('Invalid taxonomy specified.', 'transfer-brands-for-woocommerce')]);
            return;
        }

        // Validate the taxonomy exists
        if (!taxonomy_exists($new_taxonomy)) {
            wp_send_json_error(['message' => __('The specified taxonomy does not exist.', 'transfer-brands-for-woocommerce')]);
            return;
        }

        // Get current options and update source_taxonomy
        $options = get_option('tbfw_transfer_brands_options', []);
        $options['source_taxonomy'] = $new_taxonomy;
        update_option('tbfw_transfer_brands_options', $options);

        // Log the change
        $this->core->add_debug('Source taxonomy switched', [
            'new_taxonomy' => $new_taxonomy
        ]);

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %s: Taxonomy name */
                __('Source changed to %s. Page will reload.', 'transfer-brands-for-woocommerce'),
                $new_taxonomy
            ),
            'taxonomy' => $new_taxonomy
        ]);
    }

    /**
     * AJAX handler for dismissing the review notice
     *
     * @since 3.0.0
     * @since 3.0.4 Added capability check for security
     */
    public function ajax_dismiss_review_notice() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'tbfw_dismiss_review')) {
            wp_send_json_error(['message' => __('Security check failed.', 'transfer-brands-for-woocommerce')]);
            return;
        }

        // Verify capability
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce')]);
            return;
        }

        $action = isset($_POST['dismiss_action']) ? sanitize_text_field(wp_unslash($_POST['dismiss_action'])) : 'later';
        $user_id = get_current_user_id();

        if ($action === 'never') {
            // Permanently dismiss
            update_user_meta($user_id, 'tbfw_review_notice_dismissed', 'permanent');
        } else {
            // Dismiss for 7 days
            update_user_meta($user_id, 'tbfw_review_notice_dismissed', time() + (7 * DAY_IN_SECONDS));
        }

        wp_send_json_success(['message' => __('Notice dismissed.', 'transfer-brands-for-woocommerce')]);
    }

    /**
     * AJAX handler for verifying transfer results
     *
     * Shows what was actually transferred to help diagnose issues
     *
     * @since 3.0.1
     */
    public function ajax_verify_transfer() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce')]);
            return;
        }

        $destination_taxonomy = $this->core->get_option('destination_taxonomy');

        // Check if destination taxonomy exists
        if (!taxonomy_exists($destination_taxonomy)) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: %s: taxonomy name */
                    __('Destination taxonomy "%s" does not exist. WooCommerce Brands may not be enabled.', 'transfer-brands-for-woocommerce'),
                    $destination_taxonomy
                )
            ]);
            return;
        }

        // Get all brands in destination taxonomy
        $destination_terms = get_terms([
            'taxonomy' => $destination_taxonomy,
            'hide_empty' => false
        ]);

        if (is_wp_error($destination_terms)) {
            wp_send_json_error([
                'message' => __('Error retrieving brands: ', 'transfer-brands-for-woocommerce') . $destination_terms->get_error_message()
            ]);
            return;
        }

        $brands_count = count($destination_terms);

        // Count products with destination brands
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
        $products_with_brands = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT tr.object_id)
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
            WHERE tt.taxonomy = %s
            AND p.post_type = 'product'
            AND p.post_status = 'publish'",
            $destination_taxonomy
        ));

        // Get sample products with brands
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
        $sample_product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tr.object_id
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
            WHERE tt.taxonomy = %s
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
            LIMIT 10",
            $destination_taxonomy
        ));

        $sample_products = [];
        foreach ($sample_product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) continue;

            $product_terms = get_the_terms($product_id, $destination_taxonomy);
            $brand_names = [];
            if ($product_terms && !is_wp_error($product_terms)) {
                $brand_names = wp_list_pluck($product_terms, 'name');
            }

            $sample_products[] = [
                'id' => $product_id,
                'name' => $product->get_name(),
                'brands' => $brand_names
            ];
        }

        // Build HTML response
        $html = '<div class="tbfw-verify-results">';

        // Summary section
        $html .= '<h4>' . esc_html__('Transfer Verification Results', 'transfer-brands-for-woocommerce') . '</h4>';

        if ($brands_count > 0 && $products_with_brands > 0) {
            $html .= '<div class="notice notice-success inline" style="margin: 0 0 15px 0; padding: 10px 12px;">';
            $html .= '<p style="margin: 0;"><span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> ';
            $html .= '<strong>' . esc_html__('Transfer appears successful!', 'transfer-brands-for-woocommerce') . '</strong></p>';
            $html .= '</div>';
        } elseif ($brands_count > 0 && $products_with_brands == 0) {
            $html .= '<div class="notice notice-warning inline" style="margin: 0 0 15px 0; padding: 10px 12px;">';
            $html .= '<p style="margin: 0;"><span class="dashicons dashicons-warning" style="color: #dba617;"></span> ';
            $html .= '<strong>' . esc_html__('Brands exist but no products are assigned!', 'transfer-brands-for-woocommerce') . '</strong></p>';
            $html .= '<p style="margin: 5px 0 0 0;">' . esc_html__('The brand terms were created, but products were not assigned. Try running the transfer again.', 'transfer-brands-for-woocommerce') . '</p>';
            $html .= '</div>';
        } else {
            $html .= '<div class="notice notice-error inline" style="margin: 0 0 15px 0; padding: 10px 12px;">';
            $html .= '<p style="margin: 0;"><span class="dashicons dashicons-dismiss" style="color: #d63638;"></span> ';
            $html .= '<strong>' . esc_html__('No brands found in destination taxonomy!', 'transfer-brands-for-woocommerce') . '</strong></p>';
            $html .= '<p style="margin: 5px 0 0 0;">' . esc_html__('The transfer may have failed. Check that WooCommerce Brands is enabled and try again.', 'transfer-brands-for-woocommerce') . '</p>';
            $html .= '</div>';
        }

        // Statistics
        $html .= '<ul class="tbfw-list-disc" style="margin: 15px 0;">';
        $html .= '<li>' . sprintf(
            /* translators: %1$d: number of brands, %2$s: taxonomy name */
            esc_html__('%1$d brands in destination taxonomy (%2$s)', 'transfer-brands-for-woocommerce'),
            $brands_count,
            '<code>' . esc_html($destination_taxonomy) . '</code>'
        ) . '</li>';
        $html .= '<li>' . sprintf(
            /* translators: %d: number of products */
            esc_html__('%d products have brands assigned', 'transfer-brands-for-woocommerce'),
            $products_with_brands
        ) . '</li>';
        $html .= '</ul>';

        // Brand list
        if ($brands_count > 0) {
            $html .= '<details style="margin: 15px 0;">';
            $html .= '<summary style="cursor: pointer; font-weight: 600;">' . esc_html__('View destination brands', 'transfer-brands-for-woocommerce') . '</summary>';
            $html .= '<ul class="tbfw-preview-list" style="margin: 10px 0 0 20px;">';
            $display_terms = array_slice($destination_terms, 0, 20);
            foreach ($display_terms as $term) {
                $html .= '<li>' . esc_html($term->name) . ' <span class="tbfw-text-muted">(' . $term->count . ' products)</span></li>';
            }
            if ($brands_count > 20) {
                $html .= '<li><em>' . sprintf(
                    /* translators: %d: number of additional brands not shown */
                    esc_html__('...and %d more brands', 'transfer-brands-for-woocommerce'),
                    $brands_count - 20
                ) . '</em></li>';
            }
            $html .= '</ul>';
            $html .= '</details>';
        }

        // Sample products
        if (!empty($sample_products)) {
            $html .= '<h4 style="margin-top: 20px;">' . esc_html__('Sample Products with Brands', 'transfer-brands-for-woocommerce') . '</h4>';
            $html .= '<table class="widefat striped" style="margin-top: 10px;">';
            $html .= '<thead><tr>';
            $html .= '<th>' . esc_html__('ID', 'transfer-brands-for-woocommerce') . '</th>';
            $html .= '<th>' . esc_html__('Product', 'transfer-brands-for-woocommerce') . '</th>';
            $html .= '<th>' . esc_html__('Assigned Brands', 'transfer-brands-for-woocommerce') . '</th>';
            $html .= '</tr></thead>';
            $html .= '<tbody>';

            foreach ($sample_products as $product) {
                $html .= '<tr>';
                $html .= '<td>' . esc_html($product['id']) . '</td>';
                $html .= '<td>' . esc_html($product['name']) . '</td>';
                $html .= '<td>' . esc_html(implode(', ', $product['brands'])) . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
        }

        $html .= '</div>';

        wp_send_json_success([
            'html' => $html,
            'summary' => [
                'brands_count' => $brands_count,
                'products_with_brands' => (int)$products_with_brands,
                'success' => ($brands_count > 0 && $products_with_brands > 0)
            ]
        ]);
    }

}