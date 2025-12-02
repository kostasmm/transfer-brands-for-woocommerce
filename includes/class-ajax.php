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
    }
    
    /**
     * AJAX handler for processing the transfer in steps
     */
    public function ajax_transfer() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }

        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'backup';
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
                $this->core->get_backup()->create_backup();
            }
            
            // Backup completed, move to next step
            wp_send_json_success([
                'step' => 'terms',
                'offset' => 0,
                'percent' => 5,
                'message' => 'Backup created, starting transfer...',
                'log' => 'Backup completed successfully'
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
     */
    public function ajax_check_brands() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        $source_terms = get_terms([
            'taxonomy' => $this->core->get_option('source_taxonomy'), 
            'hide_empty' => false
        ]);
        
        if (is_wp_error($source_terms)) {
            wp_send_json_error(['message' => 'Error: ' . $source_terms->get_error_message()]);
            return;
        }
        
        global $wpdb;
        
        // Get info about custom attributes
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
        
        // Sample of products with custom attributes
        $custom_products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
                WHERE meta_key = '_product_attributes' 
                AND meta_value LIKE %s
                AND meta_value LIKE %s
                AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')
                LIMIT 10",
                '%' . $wpdb->esc_like($this->core->get_option('source_taxonomy')) . '%',
                '%"is_taxonomy";i:0;%'
            )
        );
        
        $custom_samples = [];
        foreach ($custom_products as $item) {
            $attributes = maybe_unserialize($item->meta_value);
            if (is_array($attributes) && isset($attributes[$this->core->get_option('source_taxonomy')])) {
                $product = wc_get_product($item->post_id);
                if ($product) {
                    $custom_samples[] = [
                        'id' => $item->post_id,
                        'name' => $product->get_name(),
                        'value' => $attributes[$this->core->get_option('source_taxonomy')]['value'] ?? 'N/A'
                    ];
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
        
        // Get some sample products with taxonomy attributes
        $products_query = new WP_Query([
            'post_type' => 'product',
            'posts_per_page' => 5,
            'tax_query' => [
                [
                    'taxonomy' => $this->core->get_option('source_taxonomy'),
                    'operator' => 'EXISTS',
                ]
            ]
        ]);
        
        $sample_products = [];
        
        if ($products_query->have_posts()) {
            foreach ($products_query->posts as $post) {
                $product = wc_get_product($post->ID);
                $attrs = $product->get_attributes();
                
                if (isset($attrs[$this->core->get_option('source_taxonomy')])) {
                    $terms = [];
                    foreach ($attrs[$this->core->get_option('source_taxonomy')]->get_options() as $term_id) {
                        $term = get_term($term_id, $this->core->get_option('source_taxonomy'));
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
        
        // Add debug info
        $this->core->add_debug("Brand analysis performed", [
            'source_terms' => count($source_terms),
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
        $html .= '<ul>';
        $html .= '<li><strong>' . count($source_terms) . '</strong> ' . esc_html__('brands found in taxonomy', 'transfer-brands-for-woocommerce') . ' ' . esc_html($this->core->get_option('source_taxonomy')) . '</li>';
        $html .= '<li><strong>' . $custom_attribute_count . '</strong> ' . esc_html__('products have custom (non-taxonomy) attributes with name', 'transfer-brands-for-woocommerce') . ' ' . esc_html($this->core->get_option('source_taxonomy')) . '</li>';
        $html .= '<li><strong>' . $terms_with_images . '</strong> ' . esc_html__('brands have images that will be transferred', 'transfer-brands-for-woocommerce') . '</li>';
        $html .= '</ul>';
        
        // Warning about custom attributes
        if ($custom_attribute_count > 0) {
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
        
        if (!empty($sample_products)) {
            $html .= '<h4>Sample Taxonomy Products</h4>';
            $html .= '<p>Here are some products with taxonomy brand attributes:</p>';
            $html .= '<table class="widefat" style="margin-top: 10px;">';
            $html .= '<thead><tr><th>ID</th><th>Product</th><th>Current Brands</th></tr></thead>';
            $html .= '<tbody>';
            
            foreach ($sample_products as $product) {
                $html .= '<tr>';
                $html .= '<td>' . $product['id'] . '</td>';
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
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
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
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
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
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
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
     */
    public function ajax_delete_old_brands() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = $this->core->get_batch_size();
        
        global $wpdb;
        
        // Get previously processed product IDs
        $processed_ids = get_option('tbfw_brands_processed_ids', []);
        
        // Find products that need processing
        $query_args = [
            '%' . $wpdb->esc_like($this->core->get_option('source_taxonomy')) . '%',
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
        $product_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));
        
        // Count remaining products for progress
        $remaining_query = "SELECT COUNT(DISTINCT post_id) 
                           FROM {$wpdb->postmeta} 
                           WHERE meta_key = '_product_attributes' 
                           AND meta_value LIKE %s
                           AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')";
        
        $remaining_args = ['%' . $wpdb->esc_like($this->core->get_option('source_taxonomy')) . '%'];
        
        if (!empty($processed_ids)) {
            $placeholders = implode(',', array_fill(0, count($processed_ids), '%d'));
            $remaining_query .= " AND post_id NOT IN ($placeholders)";
            $remaining_args = array_merge($remaining_args, $processed_ids);
        }
        
        $remaining = $wpdb->get_var($wpdb->prepare($remaining_query, $remaining_args));
        
        // Total is remaining plus already processed
        $total = $remaining + count($processed_ids);
        
        $this->core->add_debug("Deleting old brands batch", [
            'batch_size' => $batch_size,
            'found_products' => count($product_ids),
            'total_remaining' => $remaining,
            'total_processed' => count($processed_ids),
            'total_products' => $total
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
            
            // Get product attributes
            $attributes = $product->get_attributes();
            
            // Check if the product has the old brand attribute
            if (isset($attributes[$this->core->get_option('source_taxonomy')])) {
                // Create backup if enabled
                if ($backup_enabled) {
                    $this->core->get_backup()->backup_product_attribute($product_id, $attributes[$this->core->get_option('source_taxonomy')]);
                }
                
                // Remove the attribute
                unset($attributes[$this->core->get_option('source_taxonomy')]);
                
                // Update the product
                $product->set_attributes($attributes);
                $product->save();
                
                $actual_modified++;
                
                $this->core->add_debug("Deleted old brand from product", [
                    'product_id' => $product_id,
                    'product_name' => $product->get_name(),
                    'backup_created' => $backup_enabled
                ]);
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
        $log_message = "Removed old brands from {$actual_modified} products in this batch (examined {$processed})";
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
     * AJAX handler for cleaning up all backups
     */
    public function ajax_cleanup_backups() {
        check_ajax_referer('tbfw_transfer_brands_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
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
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
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
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
        }
        
        if (isset($_POST['clear']) && $_POST['clear']) {
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
            wp_die(__('You do not have permission to perform this action.', 'transfer-brands-for-woocommerce'));
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
}