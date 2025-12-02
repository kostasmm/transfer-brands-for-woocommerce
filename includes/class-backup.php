<?php
/**
 * Backup class for WooCommerce Transfer Brands Enhanced plugin
 *
 * Handles backup, restore, and rollback functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class TBFW_Transfer_Brands_Backup {
    /**
     * Reference to core plugin instance
     */
    private $core;
    
    /**
     * Constructor
     * 
     * @param WC_Transfer_Brands_Core $core Core plugin instance
     */
    public function __construct($core) {
        $this->core = $core;
    }
    
    /**
     * Create a backup of current brands
     * 
     * @return bool Success status
     */
    public function create_backup() {
        $backup = [
            'source_taxonomy' => $this->core->get_option('source_taxonomy'),
            'destination_taxonomy' => $this->core->get_option('destination_taxonomy'),
            'timestamp' => current_time('mysql'),
            'terms' => [],
            'products' => []
        ];
        
        // Backup existing destination terms
        $dest_terms = get_terms([
            'taxonomy' => $this->core->get_option('destination_taxonomy'), 
            'hide_empty' => false
        ]);
        
        if (!is_wp_error($dest_terms)) {
            foreach ($dest_terms as $term) {
                // Check both possible image meta keys
                $image_id = get_term_meta($term->term_id, 'thumbnail_id', true);
                if (!$image_id) {
                    $image_id = get_term_meta($term->term_id, 'brand_image_id', true);
                }
                $backup['terms'][$term->term_id] = [
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'description' => $term->description,
                    'image_id' => $image_id
                ];
            }
        }
        
        // Don't backup all products yet - we'll do this incrementally
        update_option('tbfw_backup', $backup);
        
        $this->core->add_debug("Created backup", [
            'dest_terms_count' => count($dest_terms),
            'timestamp' => $backup['timestamp']
        ]);
        
        return true;
    }
    
    /**
     * Store a mapping between old and new term IDs
     * 
     * @param int $old_id Original term ID
     * @param int $new_id New term ID
     */
    public function add_term_mapping($old_id, $new_id) {
        $mappings = get_option('tbfw_term_mappings', []);
        $mappings[$old_id] = $new_id;
        update_option('tbfw_term_mappings', $mappings);
    }
    
    /**
     * Backup a product's current terms
     * 
     * @param int $product_id Product ID
     */
    public function backup_product_terms($product_id) {
        $backup = get_option('tbfw_backup', []);
        
        if (!isset($backup['products'][$product_id])) {
            $terms = wp_get_object_terms($product_id, $this->core->get_option('destination_taxonomy'), ['fields' => 'ids']);
            $backup['products'][$product_id] = $terms;
            update_option('tbfw_backup', $backup);
        }
    }
    
    /**
     * Update completion timestamp
     */
    public function update_completion_timestamp() {
        $backup = get_option('tbfw_backup', []);
        $backup['completed'] = current_time('mysql');
        update_option('tbfw_backup', $backup);
    }
    
    /**
     * Rollback a transfer
     * 
     * @return array Result data
     */
    public function rollback_transfer() {
        $backup = get_option('tbfw_backup', []);
        
        if (empty($backup) || !isset($backup['timestamp'])) {
            return [
                'success' => false,
                'message' => 'No backup found to restore from.'
            ];
        }
        
        // Restore products to their previous state
        if (isset($backup['products']) && is_array($backup['products'])) {
            foreach ($backup['products'] as $product_id => $term_ids) {
                wp_set_object_terms($product_id, $term_ids, $this->core->get_option('destination_taxonomy'));
            }
        }
        
        // Delete terms that were created during the transfer
        $mappings = get_option('tbfw_term_mappings', []);
        
        foreach ($mappings as $old_id => $new_id) {
            // Only delete terms created during transfer (not existing ones)
            if (!isset($backup['terms'][$new_id])) {
                wp_delete_term($new_id, $this->core->get_option('destination_taxonomy'));
            }
        }
        
        // Clear the backup and mappings
        delete_option('tbfw_term_mappings');
        delete_option('tbfw_backup');
        
        return [
            'success' => true,
            'message' => 'Rollback completed successfully.'
        ];
    }
    
    /**
     * Rollback deleted brands
     * 
     * @return array Result data
     */
    public function rollback_deleted_brands() {
        $deleted_backup = get_option('tbfw_deleted_brands_backup', []);
        
        if (empty($deleted_backup)) {
            return [
                'success' => false,
                'message' => 'Backup for deleted brands not found.'
            ];
        }
        
        // Count for reporting
        $restored_count = 0;
        $skipped_count = 0;
        $total_in_backup = count($deleted_backup);
        
        // Iterate through each product in the backup
        foreach ($deleted_backup as $product_id => $backup_data) {
            // Get product
            $product = wc_get_product($product_id);
            if (!$product) {
                $skipped_count++;
                continue;
            }
            
            // Process the restoration - we need to modify the product attributes directly
            $this->core->add_debug("Attempting to restore product attributes", [
                'product_id' => $product_id,
                'backup_data' => $backup_data
            ]);
            
            try {
                // Get current product attributes
                $current_attributes = get_post_meta($product_id, '_product_attributes', true);
                if (!is_array($current_attributes)) {
                    $current_attributes = [];
                }
                
                // Get the attribute info from backup
                $taxonomy_name = $backup_data['attribute_taxonomy'];
                $is_taxonomy = isset($backup_data['is_taxonomy']) ? (bool)$backup_data['is_taxonomy'] : true;
                $options = $backup_data['options'];
                $brand_names = $backup_data['brand_names'] ?? [];
                
                // Skip if this attribute already exists
                if (isset($current_attributes[$taxonomy_name])) {
                    $skipped_count++;
                    continue;
                }
                
                // Recreate the attribute array in the format WooCommerce expects
                $current_attributes[$taxonomy_name] = [
                    'name' => $taxonomy_name,
                    'is_visible' => 1,
                    'is_variation' => 0,
                    'is_taxonomy' => $is_taxonomy ? 1 : 0,
                    'position' => count($current_attributes),
                ];
                
                // For taxonomy attributes we need to link to terms
                if ($is_taxonomy) {
                    // First check if the terms exist, create them if not
                    $term_ids = [];
                    foreach ($brand_names as $brand_name) {
                        $term = get_term_by('name', $brand_name, $taxonomy_name);
                        if (!$term) {
                            // Create the term
                            $result = wp_insert_term($brand_name, $taxonomy_name);
                            if (!is_wp_error($result)) {
                                $term_ids[] = $result['term_id'];
                            }
                        } else {
                            $term_ids[] = $term->term_id;
                        }
                    }
                    
                    // Now assign the terms to the product
                    if (!empty($term_ids)) {
                        wp_set_object_terms($product_id, $term_ids, $taxonomy_name);
                    }
                    
                    // For taxonomy attributes, WooCommerce stores 'value' as empty string
                    $current_attributes[$taxonomy_name]['value'] = '';
                } else {
                    // For custom attributes, value holds the actual data
                    $current_attributes[$taxonomy_name]['value'] = implode('|', $brand_names);
                }
                
                // Update the product's attributes
                update_post_meta($product_id, '_product_attributes', $current_attributes);
                
                $restored_count++;
                
                $this->core->add_debug("Successfully restored brand attribute", [
                    'product_id' => $product_id,
                    'attribute' => $current_attributes[$taxonomy_name]
                ]);
                
            } catch (Exception $e) {
                $this->core->add_debug("Error restoring brand attribute", [
                    'product_id' => $product_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Delete the backup after successful restore
        delete_option('tbfw_deleted_brands_backup');
        
        // Return success response with detailed information
        return [
            'success' => true,
            'message' => "Brands restored to {$restored_count} products.",
            'restored' => $restored_count,
            'skipped' => $skipped_count,
            'total_in_backup' => $total_in_backup,
            'details' => "Total products in backup: {$total_in_backup}, Restored: {$restored_count}, Skipped: {$skipped_count}"
        ];
    }
    
    /**
     * Backup product attribute before deletion
     * 
     * @param int $product_id Product ID
     * @param object $attribute WC_Product_Attribute object
     */
    public function backup_product_attribute($product_id, $attribute) {
        $backup_key = 'tbfw_deleted_brands_backup';
        $backup = get_option($backup_key, []);
        
        // Get the attribute taxonomy name (this is what we'll restore to)
        $taxonomy_name = $this->core->get_option('source_taxonomy');
        
        // Only backup if we haven't already
        if (!isset($backup[$product_id])) {
            // Get term/option names for better restoration
            $brand_names = [];
            $options = $attribute->get_options();
            
            // Check if this is a taxonomy attribute
            if ($attribute->is_taxonomy()) {
                foreach ($options as $term_id) {
                    $term = get_term($term_id, $taxonomy_name);
                    if ($term && !is_wp_error($term)) {
                        $brand_names[] = $term->name;
                    }
                }
            } else {
                // For custom attributes, the options likely already contain names
                $brand_names = $options;
            }
            
            // Create a more comprehensive backup
            $backup[$product_id] = [
                'timestamp' => current_time('mysql'),
                'product_id' => $product_id,
                'attribute_taxonomy' => $taxonomy_name,
                'is_taxonomy' => $attribute->is_taxonomy(),
                'is_visible' => $attribute->get_visible(),
                'is_variation' => $attribute->get_variation(),
                'position' => $attribute->get_position(),
                'options' => $options,
                'brand_names' => $brand_names
            ];
            
            // Save full product attribute data
            $product_attributes = get_post_meta($product_id, '_product_attributes', true);
            if (is_array($product_attributes) && isset($product_attributes[$taxonomy_name])) {
                $backup[$product_id]['raw_attribute_data'] = $product_attributes[$taxonomy_name];
            }
            
            update_option($backup_key, $backup);
            
            $this->core->add_debug("Created comprehensive backup for deleted brand", [
                'product_id' => $product_id,
                'backup_data' => $backup[$product_id]
            ]);
        }
    }
    
    /**
     * Clean up all backups
     * 
     * @return array Result data
     */
    public function cleanup_backups() {
        // Delete all backup related options
        delete_option('tbfw_backup');
        delete_option('tbfw_term_mappings');
        delete_option('tbfw_deleted_brands_backup');
        
        // Log the action for future reference
        $cleanup_log = [
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        ];
        update_option('tbfw_backup_cleanup_log', $cleanup_log);
        
        $this->core->add_debug("Cleaned up all backups", [
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql')
        ]);
        
        return [
            'success' => true,
            'message' => 'All backups have been deleted successfully.'
        ];
    }
}