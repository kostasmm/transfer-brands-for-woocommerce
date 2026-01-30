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
        $update_result = update_option('tbfw_backup', $backup);

        // Check if update succeeded (returns false if value unchanged or on failure)
        // For new backups, we need to verify the option was saved
        $saved_backup = get_option('tbfw_backup', []);
        $backup_valid = !empty($saved_backup) && isset($saved_backup['timestamp']) && $saved_backup['timestamp'] === $backup['timestamp'];

        $this->core->add_debug("Created backup", [
            'dest_terms_count' => is_wp_error($dest_terms) ? 0 : count($dest_terms),
            'timestamp' => $backup['timestamp'],
            'save_result' => $backup_valid
        ]);

        return $backup_valid;
    }
    
    /**
     * Store a mapping between old and new term IDs
     *
     * @param int $old_id Original term ID
     * @param int $new_id New term ID
     */
    public function add_term_mapping($old_id, $new_id) {
        // Skip if backup is disabled
        if (!$this->core->get_option('backup_enabled')) {
            return;
        }

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
        // Skip if backup is disabled
        if (!$this->core->get_option('backup_enabled')) {
            return;
        }

        $backup = get_option('tbfw_backup', []);

        if (!isset($backup['products'][$product_id])) {
            $terms = wp_get_object_terms($product_id, $this->core->get_option('destination_taxonomy'), ['fields' => 'ids']);

            // Validate return value - don't store WP_Error objects in backup
            if (is_wp_error($terms)) {
                $this->core->add_debug("Error backing up product terms", [
                    'product_id' => $product_id,
                    'error' => $terms->get_error_message()
                ]);
                return;
            }

            $backup['products'][$product_id] = is_array($terms) ? $terms : [];
            update_option('tbfw_backup', $backup);
        }
    }
    
    /**
     * Update completion timestamp
     */
    public function update_completion_timestamp() {
        // Skip if backup is disabled
        if (!$this->core->get_option('backup_enabled')) {
            return;
        }

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

        $rollback_errors = [];
        $products_restored = 0;
        $terms_deleted = 0;

        // Restore products to their previous state
        if (isset($backup['products']) && is_array($backup['products'])) {
            foreach ($backup['products'] as $product_id => $term_ids) {
                $result = wp_set_object_terms($product_id, $term_ids, $this->core->get_option('destination_taxonomy'));

                if (is_wp_error($result)) {
                    $rollback_errors[] = [
                        'type' => 'product',
                        'id' => $product_id,
                        'error' => $result->get_error_message()
                    ];
                    $this->core->add_debug("Rollback error restoring product", [
                        'product_id' => $product_id,
                        'error' => $result->get_error_message()
                    ]);
                } else {
                    $products_restored++;
                }
            }
        }

        // Delete terms that were created during the transfer
        $mappings = get_option('tbfw_term_mappings', []);

        foreach ($mappings as $old_id => $new_id) {
            // Only delete terms created during transfer (not existing ones)
            if (!isset($backup['terms'][$new_id])) {
                $result = wp_delete_term($new_id, $this->core->get_option('destination_taxonomy'));

                if (is_wp_error($result)) {
                    $rollback_errors[] = [
                        'type' => 'term',
                        'id' => $new_id,
                        'error' => $result->get_error_message()
                    ];
                    $this->core->add_debug("Rollback error deleting term", [
                        'term_id' => $new_id,
                        'error' => $result->get_error_message()
                    ]);
                } elseif ($result !== false) {
                    $terms_deleted++;
                }
            }
        }

        // Log rollback results
        $this->core->add_debug("Rollback completed", [
            'products_restored' => $products_restored,
            'terms_deleted' => $terms_deleted,
            'errors' => count($rollback_errors)
        ]);

        // Only clear backup and mappings if rollback was successful (no critical errors)
        if (empty($rollback_errors)) {
            delete_option('tbfw_term_mappings');
            delete_option('tbfw_backup');
            delete_option('tbfw_transfer_failed_products');

            return [
                'success' => true,
                'message' => "Rollback completed successfully. Restored {$products_restored} products, deleted {$terms_deleted} terms."
            ];
        } else {
            // Keep backup data for retry, but return partial success info
            return [
                'success' => false,
                'message' => "Rollback completed with errors. Restored {$products_restored} products, deleted {$terms_deleted} terms. " .
                             count($rollback_errors) . " errors occurred. Backup preserved for retry.",
                'errors' => $rollback_errors
            ];
        }
    }
    
    /**
     * Rollback deleted brands
     *
     * @since 2.8.8 Added support for brand plugin taxonomies
     * @since 3.0.2 Added error handling and conditional backup deletion
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
        $failed_count = 0;
        $total_in_backup = is_array($deleted_backup) ? count($deleted_backup) : 0;
        $restore_errors = [];

        // Iterate through each product in the backup
        foreach ($deleted_backup as $product_id => $backup_data) {
            // Get product
            $product = wc_get_product($product_id);
            if (!$product) {
                $skipped_count++;
                continue;
            }

            // Process the restoration
            $this->core->add_debug("Attempting to restore product attributes", [
                'product_id' => $product_id,
                'backup_data' => $backup_data
            ]);

            try {
                // Get the attribute info from backup
                $taxonomy_name = $backup_data['attribute_taxonomy'];
                $is_brand_plugin = isset($backup_data['is_brand_plugin']) ? (bool)$backup_data['is_brand_plugin'] : false;
                $is_taxonomy = isset($backup_data['is_taxonomy']) ? (bool)$backup_data['is_taxonomy'] : true;
                $brand_names = $backup_data['brand_names'] ?? [];

                // Handle brand plugin taxonomies differently (pwb-brand, yith_product_brand)
                if ($is_brand_plugin) {
                    // For brand plugins, check if product already has terms in this taxonomy
                    $existing_terms = get_the_terms($product_id, $taxonomy_name);
                    if ($existing_terms && !is_wp_error($existing_terms)) {
                        $skipped_count++;
                        $this->core->add_debug("Skipped - product already has brand plugin terms", [
                            'product_id' => $product_id,
                            'taxonomy' => $taxonomy_name,
                            'existing_terms' => wp_list_pluck($existing_terms, 'name')
                        ]);
                        continue;
                    }

                    // Find or create terms and assign to product
                    $term_ids = [];
                    foreach ($brand_names as $brand_name) {
                        $term = get_term_by('name', $brand_name, $taxonomy_name);
                        if (!$term) {
                            // Create the term if it doesn't exist
                            $result = wp_insert_term($brand_name, $taxonomy_name);
                            if (is_wp_error($result)) {
                                $this->core->add_debug("Failed to create term during rollback", [
                                    'term_name' => $brand_name,
                                    'taxonomy' => $taxonomy_name,
                                    'error' => $result->get_error_message()
                                ]);
                                $restore_errors[] = [
                                    'type' => 'term_create',
                                    'product_id' => $product_id,
                                    'term_name' => $brand_name,
                                    'error' => $result->get_error_message()
                                ];
                            } elseif (isset($result['term_id']) && $result['term_id'] > 0) {
                                $term_ids[] = $result['term_id'];
                            }
                        } else {
                            $term_ids[] = $term->term_id;
                        }
                    }

                    // Assign terms to product
                    if (!empty($term_ids)) {
                        $set_result = wp_set_object_terms($product_id, $term_ids, $taxonomy_name);

                        if (is_wp_error($set_result)) {
                            $failed_count++;
                            $restore_errors[] = [
                                'type' => 'term_assign',
                                'product_id' => $product_id,
                                'error' => $set_result->get_error_message()
                            ];
                            $this->core->add_debug("Failed to assign terms to product", [
                                'product_id' => $product_id,
                                'taxonomy' => $taxonomy_name,
                                'error' => $set_result->get_error_message()
                            ]);
                        } else {
                            $restored_count++;
                            $this->core->add_debug("Successfully restored brand plugin terms", [
                                'product_id' => $product_id,
                                'taxonomy' => $taxonomy_name,
                                'restored_terms' => $brand_names
                            ]);
                        }
                    } else {
                        $failed_count++;
                    }
                } else {
                    // For WooCommerce attributes, use the original logic
                    $current_attributes = get_post_meta($product_id, '_product_attributes', true);
                    if (!is_array($current_attributes)) {
                        $current_attributes = [];
                    }

                    $options = $backup_data['options'];

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
                                if (is_wp_error($result)) {
                                    $this->core->add_debug("Failed to create term during rollback", [
                                        'term_name' => $brand_name,
                                        'taxonomy' => $taxonomy_name,
                                        'error' => $result->get_error_message()
                                    ]);
                                    $restore_errors[] = [
                                        'type' => 'term_create',
                                        'product_id' => $product_id,
                                        'term_name' => $brand_name,
                                        'error' => $result->get_error_message()
                                    ];
                                } elseif (isset($result['term_id']) && $result['term_id'] > 0) {
                                    $term_ids[] = $result['term_id'];
                                }
                            } else {
                                $term_ids[] = $term->term_id;
                            }
                        }

                        // Now assign the terms to the product
                        if (!empty($term_ids)) {
                            $set_result = wp_set_object_terms($product_id, $term_ids, $taxonomy_name);

                            if (is_wp_error($set_result)) {
                                $restore_errors[] = [
                                    'type' => 'term_assign',
                                    'product_id' => $product_id,
                                    'error' => $set_result->get_error_message()
                                ];
                                $this->core->add_debug("Failed to assign taxonomy terms", [
                                    'product_id' => $product_id,
                                    'error' => $set_result->get_error_message()
                                ]);
                            }
                        }

                        // For taxonomy attributes, WooCommerce stores 'value' as empty string
                        $current_attributes[$taxonomy_name]['value'] = '';
                    } else {
                        // For custom attributes, value holds the actual data
                        $current_attributes[$taxonomy_name]['value'] = implode('|', $brand_names);
                    }

                    // Update the product's attributes
                    $update_result = update_post_meta($product_id, '_product_attributes', $current_attributes);

                    if ($update_result === false) {
                        $failed_count++;
                        $restore_errors[] = [
                            'type' => 'meta_update',
                            'product_id' => $product_id,
                            'error' => 'Failed to update product attributes'
                        ];
                        $this->core->add_debug("Failed to update product attributes", [
                            'product_id' => $product_id
                        ]);
                    } else {
                        $restored_count++;
                        $this->core->add_debug("Successfully restored brand attribute", [
                            'product_id' => $product_id,
                            'attribute' => $current_attributes[$taxonomy_name]
                        ]);
                    }
                }

            } catch (Exception $e) {
                $failed_count++;
                $restore_errors[] = [
                    'type' => 'exception',
                    'product_id' => $product_id,
                    'error' => $e->getMessage()
                ];
                $this->core->add_debug("Error restoring brand attribute", [
                    'product_id' => $product_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Log rollback results
        $this->core->add_debug("Deleted brands rollback completed", [
            'restored' => $restored_count,
            'skipped' => $skipped_count,
            'failed' => $failed_count,
            'errors' => count($restore_errors)
        ]);

        // Only delete backup if we successfully restored something AND no critical errors
        if ($restored_count > 0 && empty($restore_errors)) {
            delete_option('tbfw_deleted_brands_backup');

            return [
                'success' => true,
                'message' => "Brands restored to {$restored_count} products.",
                'restored' => $restored_count,
                'skipped' => $skipped_count,
                'failed' => $failed_count,
                'total_in_backup' => $total_in_backup,
                'details' => "Total products in backup: {$total_in_backup}, Restored: {$restored_count}, Skipped: {$skipped_count}"
            ];
        } elseif ($restored_count > 0) {
            // Partial success - some restored, some errors - keep backup for retry
            return [
                'success' => true,
                'message' => "Partially restored brands to {$restored_count} products. {$failed_count} failed. Backup preserved for retry.",
                'restored' => $restored_count,
                'skipped' => $skipped_count,
                'failed' => $failed_count,
                'total_in_backup' => $total_in_backup,
                'errors' => $restore_errors,
                'details' => "Total: {$total_in_backup}, Restored: {$restored_count}, Skipped: {$skipped_count}, Failed: {$failed_count}"
            ];
        } else {
            // Nothing restored - keep backup
            return [
                'success' => false,
                'message' => "Failed to restore any brands. Backup preserved for retry.",
                'restored' => 0,
                'skipped' => $skipped_count,
                'failed' => $failed_count,
                'total_in_backup' => $total_in_backup,
                'errors' => $restore_errors,
                'details' => "Total: {$total_in_backup}, Restored: 0, Skipped: {$skipped_count}, Failed: {$failed_count}"
            ];
        }
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
     * Backup brand plugin taxonomy terms before deletion
     *
     * @since 2.8.8
     * @param int $product_id Product ID
     * @param array $terms Array of WP_Term objects
     * @param string $taxonomy The taxonomy name (e.g., pwb-brand, yith_product_brand)
     */
    public function backup_brand_plugin_terms($product_id, $terms, $taxonomy) {
        $backup_key = 'tbfw_deleted_brands_backup';
        $backup = get_option($backup_key, []);

        // Only backup if we haven't already
        if (!isset($backup[$product_id])) {
            // Get term names and IDs for restoration
            $term_data = [];
            foreach ($terms as $term) {
                $term_data[] = [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'description' => $term->description
                ];
            }

            // Create a comprehensive backup
            $backup[$product_id] = [
                'timestamp' => current_time('mysql'),
                'product_id' => $product_id,
                'attribute_taxonomy' => $taxonomy,
                'is_taxonomy' => true,
                'is_brand_plugin' => true,
                'is_visible' => true,
                'is_variation' => false,
                'position' => 0,
                'options' => wp_list_pluck($terms, 'term_id'),
                'brand_names' => wp_list_pluck($terms, 'name'),
                'term_data' => $term_data
            ];

            update_option($backup_key, $backup);

            $this->core->add_debug("Created backup for brand plugin terms", [
                'product_id' => $product_id,
                'taxonomy' => $taxonomy,
                'terms' => wp_list_pluck($terms, 'name')
            ]);
        }
    }

    /**
     * Clean up all backups
     *
     * @since 3.0.2 Added capability check
     * @return array Result data
     */
    public function cleanup_backups() {
        // Security check - only administrators can delete all backups
        if (!current_user_can('manage_options')) {
            $this->core->add_debug("Cleanup backups denied - insufficient permissions", [
                'user_id' => get_current_user_id()
            ]);
            return [
                'success' => false,
                'message' => 'Insufficient permissions to clean up backups.'
            ];
        }

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