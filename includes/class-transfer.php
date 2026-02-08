<?php
/**
 * Transfer class for WooCommerce Transfer Brands Enhanced plugin
 *
 * Handles the actual brand transfer functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class TBFW_Transfer_Brands_Transfer {
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
     * Process a batch of terms to transfer
     *
     * @param int $offset Current offset
     * @return array Result data
     */
    public function process_terms_batch($offset = 0) {
        $source_taxonomy = $this->core->get_option('source_taxonomy');
        $destination_taxonomy = $this->core->get_option('destination_taxonomy');

        // Validate both taxonomies exist before processing
        if (!taxonomy_exists($source_taxonomy)) {
            $this->core->add_debug("Source taxonomy does not exist", [
                'taxonomy' => $source_taxonomy
            ]);
            return [
                'success' => false,
                'message' => 'Error: Source taxonomy "' . esc_html($source_taxonomy) . '" does not exist. Please check your settings.'
            ];
        }

        if (!taxonomy_exists($destination_taxonomy)) {
            $this->core->add_debug("Destination taxonomy does not exist", [
                'taxonomy' => $destination_taxonomy
            ]);
            return [
                'success' => false,
                'message' => 'Error: Destination taxonomy "' . esc_html($destination_taxonomy) . '" does not exist. Please enable WooCommerce Brands.'
            ];
        }

        // Get total count first (cached after first call)
        $total = wp_count_terms([
            'taxonomy' => $source_taxonomy,
            'hide_empty' => false
        ]);

        if (is_wp_error($total)) {
            $this->core->add_debug("Error counting terms", [
                'error' => $total->get_error_message()
            ]);
            return [
                'success' => false,
                'message' => 'Error counting terms: ' . $total->get_error_message()
            ];
        }

        $total = (int) $total;

        // Clear term cache to prevent stale results between AJAX batch calls
        // This is critical for sites using persistent object cache (Redis, Memcached)
        clean_taxonomy_cache($source_taxonomy);

        // Get only ONE term at the current offset (memory efficient)
        $terms = get_terms([
            'taxonomy' => $source_taxonomy,
            'hide_empty' => false,
            'number' => 1,
            'offset' => $offset,
            'orderby' => 'term_id',
            'order' => 'ASC'
        ]);

        if (is_wp_error($terms)) {
            $this->core->add_debug("Error getting terms", [
                'error' => $terms->get_error_message()
            ]);
            return [
                'success' => false,
                'message' => 'Error getting terms: ' . $terms->get_error_message()
            ];
        }

        if (!empty($terms)) {
            $term = $terms[0];
            $log_message = '';

            // Validate term has a non-empty name before processing
            // Source taxonomies can contain corrupted/empty terms from plugin bugs or DB issues
            if (empty(trim($term->name))) {
                $this->core->add_debug("Skipped term with empty name", [
                    'term_id' => $term->term_id ?? '',
                    'term_slug' => $term->slug ?? '',
                    'offset' => $offset
                ]);

                $offset++;
                $percent = min(45, round(($offset / $total) * 40) + 5);

                return [
                    'success' => true,
                    'step' => 'terms',
                    'offset' => $offset,
                    'total' => $total,
                    'percent' => $percent,
                    'message' => "Transferring terms: {$offset} of {$total}",
                    'log' => 'Skipped term with empty name (ID: ' . ($term->term_id ?? '') . ')'
                ];
            }

            // Create or get new term - PRESERVE ORIGINAL SLUG for SEO
            $new = term_exists($term->name, $this->core->get_option('destination_taxonomy'));
            if (!$new) {
                $new = wp_insert_term($term->name, $this->core->get_option('destination_taxonomy'), [
                    'slug' => $term->slug, // Preserve original slug for SEO/URL preservation
                    'description' => $term->description
                ]);
                $log_message = 'Created new term: ' . $term->name . ' (slug: ' . $term->slug . ')';
            } else {
                $log_message = 'Using existing term: ' . $term->name;
            }

            // If term creation failed, skip this term and continue with the next one
            // instead of halting the entire transfer process
            if (is_wp_error($new)) {
                $this->core->add_debug("Error creating term, skipping", [
                    'term' => $term->name,
                    'term_id' => $term->term_id,
                    'error' => $new->get_error_message()
                ]);

                $offset++;
                $percent = min(45, round(($offset / $total) * 40) + 5);

                return [
                    'success' => true,
                    'step' => 'terms',
                    'offset' => $offset,
                    'total' => $total,
                    'percent' => $percent,
                    'message' => "Transferring terms: {$offset} of {$total}",
                    'log' => 'Skipped term "' . esc_html($term->name) . '": ' . esc_html($new->get_error_message())
                ];
            }

            $new_id = is_array($new) ? $new['term_id'] : $new;

            // Transfer image if exists - support multiple theme meta keys
            $image_id = $this->find_brand_image($term->term_id);
            if ($image_id) {
                // Store in multiple keys for maximum theme compatibility
                $this->set_brand_image_for_all_themes($new_id, $image_id);
                $log_message .= ' (with image)';
            }

            // Store mapping for potential rollback
            $this->core->get_backup()->add_term_mapping($term->term_id, $new_id);

            // Add debug info
            $this->core->add_debug("Term processed", [
                'term_id' => $term->term_id,
                'term_name' => $term->name,
                'new_term_id' => $new_id,
                'has_image' => !empty($image_id)
            ]);

            $offset++;
            $percent = min(45, round(($offset / $total) * 40) + 5);

            return [
                'success' => true,
                'step' => 'terms',
                'offset' => $offset,
                'total' => $total,
                'percent' => $percent,
                'message' => "Transferring terms: {$offset} of {$total}",
                'log' => $log_message
            ];
        } else {
            return [
                'success' => true,
                'step' => 'products',
                'offset' => 0,
                'percent' => 45,
                'message' => 'Terms transferred, now updating products...',
                'log' => 'All terms transferred successfully. Starting product updates.'
            ];
        }
    }
    
    /**
     * Process a batch of products
     *
     * @since 2.8.5 Added support for brand plugin taxonomies (pwb-brand, etc.)
     * @return array Result data
     */
    public function process_products_batch() {
        global $wpdb;

        // Acquire lock to prevent race conditions from concurrent requests
        $lock_key = 'tbfw_transfer_lock';
        $lock_timeout = 300; // 5 minutes

        if (get_transient($lock_key)) {
            return [
                'success' => false,
                'message' => 'Another transfer batch is in progress. Please wait a moment and try again.'
            ];
        }

        // Set lock before processing
        set_transient($lock_key, wp_generate_uuid4(), $lock_timeout);

        $source_taxonomy = $this->core->get_option('source_taxonomy');
        $destination_taxonomy = $this->core->get_option('destination_taxonomy');
        $is_brand_plugin = $this->core->get_utils()->is_brand_plugin_taxonomy($source_taxonomy);

        // Validate taxonomies exist
        if (!taxonomy_exists($source_taxonomy) || !taxonomy_exists($destination_taxonomy)) {
            delete_transient($lock_key);
            return [
                'success' => false,
                'message' => 'Error: Required taxonomies no longer exist. Please check your settings.'
            ];
        }

        // Get products that have already been processed
        $processed_products = get_option('tbfw_brands_processed_ids', []);

        // Different query logic for brand plugin taxonomies vs WooCommerce attributes
        if ($is_brand_plugin) {
            // For brand plugin taxonomies, query products via taxonomy relationship
            $product_ids = $this->get_brand_plugin_products($source_taxonomy, $processed_products);
            $total = $this->count_brand_plugin_products($source_taxonomy);
        } else {
            // For WooCommerce attributes, use the original _product_attributes meta query
            $exclude_condition = '';
            $query_args = [
                '%' . $wpdb->esc_like($source_taxonomy) . '%'
            ];

            if (!empty($processed_products)) {
                $placeholders = implode(',', array_fill(0, count($processed_products), '%d'));
                $exclude_condition = " AND post_id NOT IN ($placeholders)";
                $query_args = array_merge($query_args, $processed_products);
            }

            $query_args[] = $this->core->get_batch_size();

            // Find products with brand attribute that haven't been processed yet
            $query = "SELECT DISTINCT post_id
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_product_attributes'
                    AND meta_value LIKE %s
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')
                    {$exclude_condition}
                    LIMIT %d";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is built dynamically with proper placeholders and $wpdb->prepare() handles escaping
            $product_ids = $wpdb->get_col($wpdb->prepare($query, $query_args));

            // Count total products for progress calculation
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration tool requires direct query
            $total = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT post_id)
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = '_product_attributes'
                    AND meta_value LIKE %s
                    AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')",
                    '%' . $wpdb->esc_like($source_taxonomy) . '%'
                )
            );
        }

        $this->core->add_debug("Total products with source brand", [
            'total' => $total,
            'is_brand_plugin' => $is_brand_plugin
        ]);
        
        // If there are no more products to process, we complete
        if (empty($product_ids)) {
            $this->core->get_backup()->update_completion_timestamp();

            // Mark transfer as completed for review notice
            update_option('tbfw_transfer_completed', true, false);

            // Clear all caches to ensure brands appear correctly
            $this->clear_transfer_caches();

            // Cleanup temporary tracking options
            delete_option('tbfw_brands_processed_ids');
            delete_option('tbfw_transfer_failed_products');

            // Release the lock
            delete_transient($lock_key);

            return [
                'success' => true,
                'step' => 'done',
                'percent' => 100,
                'message' => 'Transfer completed successfully!',
                'log' => 'All products updated. Transfer process complete. Cleaned up temporary data.'
            ];
        }
        
        $newly_processed = [];
        $successfully_transferred = [];
        $failed_products = [];
        $processed_count = 0;
        $custom_processed = 0;
        $brand_plugin_processed = 0;
        $log_message = '';

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                $failed_products[] = $product_id;
                continue;
            }

            $processed_count++;
            $transfer_success = false;

            // Handle brand plugin taxonomies differently
            if ($is_brand_plugin) {
                // For brand plugins like Perfect Brands, get terms directly from taxonomy
                $source_terms = get_the_terms($product_id, $source_taxonomy);

                if ($source_terms && !is_wp_error($source_terms)) {
                    $new_brand_ids = [];

                    foreach ($source_terms as $term) {
                        // Find corresponding term in destination taxonomy
                        $new_term = get_term_by('name', $term->name, $this->core->get_option('destination_taxonomy'));
                        if ($new_term) {
                            $new_brand_ids[] = (int)$new_term->term_id;
                        }
                    }

                    if (!empty($new_brand_ids)) {
                        // Store previous assignments for potential rollback
                        $this->core->get_backup()->backup_product_terms($product_id);

                        // Assign new terms and check for errors
                        $result = wp_set_object_terms($product_id, $new_brand_ids, $this->core->get_option('destination_taxonomy'));

                        if (is_wp_error($result)) {
                            $failed_products[] = $product_id;
                            $this->core->add_debug("Error assigning brand terms", [
                                'product_id' => $product_id,
                                'error' => $result->get_error_message()
                            ]);
                        } else {
                            $transfer_success = true;
                            $brand_plugin_processed++;

                            $this->core->add_debug("Brand plugin product processed", [
                                'product_id' => $product_id,
                                'source_taxonomy' => $source_taxonomy,
                                'source_terms' => wp_list_pluck($source_terms, 'name'),
                                'new_term_ids' => $new_brand_ids
                            ]);
                        }
                    } else {
                        // No matching terms found - still mark as processed to avoid infinite loop
                        $transfer_success = true;
                    }
                } else {
                    // No source terms - mark as processed
                    $transfer_success = true;
                }
            } else {
                // Original logic for WooCommerce attributes
                $attrs = $product->get_attributes();

                // Get the raw product attributes
                $raw_attributes = get_post_meta($product_id, '_product_attributes', true);

                // First try to process it as a taxonomy attribute
                if (isset($attrs[$source_taxonomy])) {
                    $brand_ids = [];

                    // Check if this is a taxonomy attribute
                    if ($attrs[$source_taxonomy]->is_taxonomy()) {
                        // Get term IDs from the attribute
                        $brand_ids = $attrs[$source_taxonomy]->get_options();

                        $new_brand_ids = [];
                        foreach ($brand_ids as $old_id) {
                            $term = get_term($old_id, $source_taxonomy);
                            if ($term && !is_wp_error($term)) {
                                $new_term = get_term_by('name', $term->name, $this->core->get_option('destination_taxonomy'));
                                if ($new_term) {
                                    $new_brand_ids[] = (int)$new_term->term_id;
                                }
                            }
                        }

                        if (!empty($new_brand_ids)) {
                            // Store previous assignments for potential rollback
                            $this->core->get_backup()->backup_product_terms($product_id);

                            // Assign new terms and check for errors
                            $result = wp_set_object_terms($product_id, $new_brand_ids, $this->core->get_option('destination_taxonomy'));

                            if (is_wp_error($result)) {
                                $failed_products[] = $product_id;
                                $this->core->add_debug("Error assigning taxonomy terms", [
                                    'product_id' => $product_id,
                                    'error' => $result->get_error_message()
                                ]);
                            } else {
                                $transfer_success = true;
                            }
                        } else {
                            $transfer_success = true;
                        }
                    } else {
                        // This is a custom attribute, not a taxonomy
                        $custom_processed++;

                        // Get the value from the attribute
                        if (isset($raw_attributes[$source_taxonomy]) &&
                            isset($raw_attributes[$source_taxonomy]['value'])) {

                            $brand_value = $raw_attributes[$source_taxonomy]['value'];

                            // Try to find a term with this ID first (common case)
                            $term = get_term($brand_value, $source_taxonomy);

                            if ($term && !is_wp_error($term)) {
                                // We found a matching term by ID
                                $new_term = get_term_by('name', $term->name, $this->core->get_option('destination_taxonomy'));
                                if ($new_term) {
                                    // Store previous assignments for potential rollback
                                    $this->core->get_backup()->backup_product_terms($product_id);

                                    // Assign new term and check for errors
                                    $result = wp_set_object_terms($product_id, [(int)$new_term->term_id], $this->core->get_option('destination_taxonomy'));

                                    if (is_wp_error($result)) {
                                        $failed_products[] = $product_id;
                                        $this->core->add_debug("Error assigning custom term by ID", [
                                            'product_id' => $product_id,
                                            'error' => $result->get_error_message()
                                        ]);
                                    } else {
                                        $transfer_success = true;
                                        $this->core->add_debug("Custom attribute processed using term ID", [
                                            'product_id' => $product_id,
                                            'brand_value' => $brand_value,
                                            'term_id' => $term->term_id,
                                            'term_name' => $term->name,
                                            'new_term_id' => $new_term->term_id
                                        ]);
                                    }
                                } else {
                                    $transfer_success = true; // No matching term, but processed
                                }
                            } else {
                                // If we couldn't find by ID, treat it as a name or slug
                                $new_term = get_term_by('name', $brand_value, $this->core->get_option('destination_taxonomy'));

                                if (!$new_term) {
                                    // Try creating the term
                                    $insert_result = wp_insert_term($brand_value, $this->core->get_option('destination_taxonomy'));
                                    if (!is_wp_error($insert_result)) {
                                        $new_term_id = $insert_result['term_id'];

                                        // Store previous assignments for potential rollback
                                        $this->core->get_backup()->backup_product_terms($product_id);

                                        // Assign new term and check for errors
                                        $result = wp_set_object_terms($product_id, [$new_term_id], $this->core->get_option('destination_taxonomy'));

                                        if (is_wp_error($result)) {
                                            $failed_products[] = $product_id;
                                            $this->core->add_debug("Error assigning newly created term", [
                                                'product_id' => $product_id,
                                                'error' => $result->get_error_message()
                                            ]);
                                        } else {
                                            $transfer_success = true;
                                            $this->core->add_debug("Custom attribute processed by creating new term", [
                                                'product_id' => $product_id,
                                                'brand_value' => $brand_value,
                                                'new_term_id' => $new_term_id
                                            ]);
                                        }
                                    } else {
                                        $failed_products[] = $product_id;
                                        $this->core->add_debug("Error creating term from custom attribute", [
                                            'product_id' => $product_id,
                                            'brand_value' => $brand_value,
                                            'error' => $insert_result->get_error_message()
                                        ]);
                                    }
                                } else {
                                    // Store previous assignments for potential rollback
                                    $this->core->get_backup()->backup_product_terms($product_id);

                                    // Assign existing term and check for errors
                                    $result = wp_set_object_terms($product_id, [(int)$new_term->term_id], $this->core->get_option('destination_taxonomy'));

                                    if (is_wp_error($result)) {
                                        $failed_products[] = $product_id;
                                        $this->core->add_debug("Error assigning existing term", [
                                            'product_id' => $product_id,
                                            'error' => $result->get_error_message()
                                        ]);
                                    } else {
                                        $transfer_success = true;
                                        $this->core->add_debug("Custom attribute processed using existing term", [
                                            'product_id' => $product_id,
                                            'brand_value' => $brand_value,
                                            'new_term_id' => $new_term->term_id,
                                            'new_term_name' => $new_term->name
                                        ]);
                                    }
                                }
                            }
                        } else {
                            $transfer_success = true; // No brand value to process
                        }
                    }
                } else {
                    $transfer_success = true; // No matching attribute
                }
            }

            // Only add to successfully processed if transfer succeeded
            if ($transfer_success) {
                $successfully_transferred[] = $product_id;
            }

            // Always add to newly_processed to track attempted products (prevents infinite loop)
            $newly_processed[] = $product_id;
        }

        // Update the list of processed products (includes all attempted, successful or not)
        $processed_products = array_merge($processed_products, $newly_processed);
        update_option('tbfw_brands_processed_ids', $processed_products);

        // Track failed products separately for diagnostics
        if (!empty($failed_products)) {
            $existing_failed = get_option('tbfw_transfer_failed_products', []);
            $existing_failed = array_merge($existing_failed, $failed_products);
            update_option('tbfw_transfer_failed_products', array_unique($existing_failed), false);
        }

        // Calculate overall progress
        $processed_total = count($processed_products);
        $percent = min(95, 45 + round(($processed_total / ($total + 1)) * 50));

        // Build log message based on processing type
        if ($is_brand_plugin) {
            $log_message = "Processed {$processed_count} products from brand plugin ({$brand_plugin_processed} transferred). Total processed: {$processed_total} of {$total}";
        } else {
            $log_message = "Processed {$processed_count} products in this batch ({$custom_processed} with custom attributes). Total processed: {$processed_total} of {$total}";
        }

        // Release the lock after batch completes
        delete_transient($lock_key);

        return [
            'success' => true,
            'step' => 'products',
            'offset' => count($newly_processed),
            'total' => $total,
            'processed_total' => $processed_total,
            'percent' => $percent,
            'message' => "Updating products: {$processed_total} of {$total}",
            'log' => $log_message
        ];
    }
    
    /**
     * Find brand image from various theme-specific meta keys
     * 
     * @param int $term_id The term ID to search for images
     * @return int|false The image ID if found, false otherwise
     * @since 2.8.0
     */
    private function find_brand_image($term_id) {
        // List of all possible meta keys used by popular themes and plugins
        $image_meta_keys = [
            // Standard WooCommerce and WordPress
            'thumbnail_id',
            'brand_image_id',
            
            // Woodmart theme
            'brand_thumbnail_id',
            'image',
            '_woodmart_image',
            'woodmart_image',
            
            // Porto theme
            'brand_thumb_id',
            'porto_brand_image',
            'product_brand_image',
            
            // Flatsome theme
            'brand_image',
            'flatsome_brand_image',
            
            // XStore theme
            'xstore_brand_image',
            'brand_logo',
            
            // Electro theme
            'electro_brand_image',
            
            // WooCommerce Brands plugin
            'brand_image_url', // Sometimes stores URL, we'll handle this
            
            // Perfect Brands for WooCommerce
            'pwb_brand_image',
            'pwb_brand_banner',
            
            // YITH WooCommerce Brands
            'yith_woocommerce_brand_thumbnail_id',
            
            // Martfury theme
            'martfury_brand_image',
            
            // Shopkeeper theme
            'shopkeeper_brand_image',
            
            // BeTheme
            'betheme_brand_image',
            
            // Avada theme
            'avada_brand_image',
            
            // Salient theme
            'salient_brand_image',
            
            // The7 theme
            'dt_brand_image',
            
            // Additional common variations
            'logo',
            'brand_logo_id',
            'image_id',
            'attachment_id',
            'icon_id',
            'brand_icon'
        ];
        
        // Try each meta key
        foreach ($image_meta_keys as $meta_key) {
            $value = get_term_meta($term_id, $meta_key, true);
            
            if ($value) {
                // Check if it's a valid attachment ID
                if (is_numeric($value) && wp_attachment_is_image($value)) {
                    $this->core->add_debug("Found brand image", [
                        'term_id' => $term_id,
                        'meta_key' => $meta_key,
                        'image_id' => $value
                    ]);
                    return (int)$value;
                }
                
                // Handle cases where URL is stored instead of ID
                if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                    $attachment_id = $this->get_attachment_id_from_url($value);
                    if ($attachment_id) {
                        $this->core->add_debug("Found brand image from URL", [
                            'term_id' => $term_id,
                            'meta_key' => $meta_key,
                            'url' => $value,
                            'image_id' => $attachment_id
                        ]);
                        return $attachment_id;
                    }
                }
                
                // Handle serialized data (some themes store arrays)
                if (is_array($value)) {
                    // Check for common array keys
                    $array_keys = ['id', 'ID', 'image_id', 'attachment_id', 'url', 'src'];
                    foreach ($array_keys as $key) {
                        if (isset($value[$key])) {
                            if (is_numeric($value[$key]) && wp_attachment_is_image($value[$key])) {
                                $this->core->add_debug("Found brand image in array", [
                                    'term_id' => $term_id,
                                    'meta_key' => $meta_key,
                                    'array_key' => $key,
                                    'image_id' => $value[$key]
                                ]);
                                return (int)$value[$key];
                            } elseif (is_string($value[$key]) && filter_var($value[$key], FILTER_VALIDATE_URL)) {
                                $attachment_id = $this->get_attachment_id_from_url($value[$key]);
                                if ($attachment_id) {
                                    return $attachment_id;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Also check for ACF fields (Advanced Custom Fields)
        if (function_exists('get_field')) {
            $acf_fields = ['brand_image', 'brand_logo', 'image', 'logo', 'thumbnail'];
            foreach ($acf_fields as $field) {
                $acf_value = get_field($field, 'term_' . $term_id);
                if ($acf_value) {
                    if (is_numeric($acf_value) && wp_attachment_is_image($acf_value)) {
                        $this->core->add_debug("Found brand image via ACF", [
                            'term_id' => $term_id,
                            'field' => $field,
                            'image_id' => $acf_value
                        ]);
                        return (int)$acf_value;
                    } elseif (is_array($acf_value) && isset($acf_value['ID'])) {
                        return (int)$acf_value['ID'];
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * Set brand image for all known theme meta keys
     * 
     * @param int $term_id The term ID to set images for
     * @param int $image_id The image attachment ID
     * @since 2.8.0
     */
    private function set_brand_image_for_all_themes($term_id, $image_id) {
        // Primary keys that should always be set
        $primary_keys = [
            'thumbnail_id',
            'brand_image_id',
            'brand_thumbnail_id'
        ];
        
        // Theme-specific keys based on detected theme
        $theme_specific_keys = $this->get_theme_specific_image_keys();
        
        // Combine all keys
        $all_keys = array_merge($primary_keys, $theme_specific_keys);
        
        // Set the image ID for all relevant meta keys
        foreach ($all_keys as $meta_key) {
            update_term_meta($term_id, $meta_key, $image_id);
        }
        
        // Also store the image URL for themes that use it
        $image_url = wp_get_attachment_url($image_id);
        if ($image_url) {
            update_term_meta($term_id, 'brand_image_url', $image_url);
        }
        
        $this->core->add_debug("Set brand image for multiple themes", [
            'term_id' => $term_id,
            'image_id' => $image_id,
            'keys_updated' => $all_keys,
            'image_url' => $image_url
        ]);
    }
    
    /**
     * Get theme-specific image meta keys based on active theme
     * 
     * @return array Array of meta keys specific to the active theme
     * @since 2.8.0
     */
    private function get_theme_specific_image_keys() {
        $theme = wp_get_theme();
        $theme_name = $theme->get('Name');
        $theme_template = $theme->get_template();
        
        $keys = [];
        
        // Check theme name and template
        $theme_identifier = strtolower($theme_name . ' ' . $theme_template);
        
        // Woodmart
        if (strpos($theme_identifier, 'woodmart') !== false) {
            $keys = ['image', '_woodmart_image', 'woodmart_image', 'brand_thumbnail_id'];
        }
        // Porto
        elseif (strpos($theme_identifier, 'porto') !== false) {
            $keys = ['brand_thumb_id', 'porto_brand_image', 'product_brand_image'];
        }
        // Flatsome
        elseif (strpos($theme_identifier, 'flatsome') !== false) {
            $keys = ['brand_image', 'flatsome_brand_image'];
        }
        // XStore
        elseif (strpos($theme_identifier, 'xstore') !== false) {
            $keys = ['xstore_brand_image', 'brand_logo'];
        }
        // Electro
        elseif (strpos($theme_identifier, 'electro') !== false) {
            $keys = ['electro_brand_image'];
        }
        // Martfury
        elseif (strpos($theme_identifier, 'martfury') !== false) {
            $keys = ['martfury_brand_image'];
        }
        // Shopkeeper
        elseif (strpos($theme_identifier, 'shopkeeper') !== false) {
            $keys = ['shopkeeper_brand_image'];
        }
        // BeTheme
        elseif (strpos($theme_identifier, 'betheme') !== false || strpos($theme_identifier, 'be theme') !== false) {
            $keys = ['betheme_brand_image'];
        }
        // Avada
        elseif (strpos($theme_identifier, 'avada') !== false) {
            $keys = ['avada_brand_image'];
        }
        // Salient
        elseif (strpos($theme_identifier, 'salient') !== false) {
            $keys = ['salient_brand_image'];
        }
        // The7
        elseif (strpos($theme_identifier, 'the7') !== false || strpos($theme_identifier, 'dt-the7') !== false) {
            $keys = ['dt_brand_image'];
        }
        
        // Also check for active brand plugins
        if (is_plugin_active('perfect-woocommerce-brands/perfect-woocommerce-brands.php') || 
            is_plugin_active('perfect-woocommerce-brands/main.php')) {
            $keys[] = 'pwb_brand_image';
            $keys[] = 'pwb_brand_banner';
        }
        
        if (is_plugin_active('yith-woocommerce-brands-add-on/init.php') || 
            is_plugin_active('yith-woocommerce-brands-add-on-premium/init.php')) {
            $keys[] = 'yith_woocommerce_brand_thumbnail_id';
        }
        
        return array_unique($keys);
    }
    
    /**
     * Get attachment ID from URL
     * 
     * @param string $url The attachment URL
     * @return int|false The attachment ID or false if not found
     * @since 2.8.0
     */
    private function get_attachment_id_from_url($url) {
        global $wpdb;
        
        // First try the WordPress function
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id) {
            return $attachment_id;
        }
        
        // If that fails, try a direct database query
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WP function to query attachments by guid
        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE guid = %s AND post_type = 'attachment'",
                $url
            )
        );

        if ($attachment_id) {
            return (int)$attachment_id;
        }

        // Try without protocol and www
        $url_parts = wp_parse_url($url);
        if (isset($url_parts['path'])) {
            $path = $url_parts['path'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No WP function to query attachments by guid
            $attachment_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE guid LIKE %s AND post_type = 'attachment'",
                    '%' . $wpdb->esc_like($path)
                )
            );

            if ($attachment_id) {
                return (int)$attachment_id;
            }
        }

        return false;
    }

    /**
     * Get products from a brand plugin taxonomy
     *
     * @since 2.8.5
     * @param string $taxonomy The brand plugin taxonomy (e.g., pwb-brand)
     * @param array $exclude_ids Product IDs to exclude (already processed)
     * @return array Array of product IDs
     */
    private function get_brand_plugin_products($taxonomy, $exclude_ids = []) {
        global $wpdb;

        $batch_size = $this->core->get_batch_size();

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
     * Count total products with a brand plugin taxonomy
     *
     * @since 2.8.5
     * @param string $taxonomy The brand plugin taxonomy
     * @return int Total number of products
     */
    private function count_brand_plugin_products($taxonomy) {
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
     * Clear all caches after transfer completion
     *
     * This ensures that the transferred brands appear correctly in WooCommerce admin
     * and on the frontend without requiring manual cache clearing.
     *
     * @since 3.0.1
     */
    private function clear_transfer_caches() {
        $destination_taxonomy = $this->core->get_option('destination_taxonomy');

        // Clear term cache for destination taxonomy
        clean_taxonomy_cache($destination_taxonomy);

        // Clear WooCommerce product transients
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients();
        }

        // Clear term counts
        delete_transient('wc_term_counts');

        // Clear product counts
        delete_transient('wc_product_count');

        // Clear object term cache for all products
        wp_cache_flush_group('terms');

        // Flush rewrite rules to ensure brand URLs work
        flush_rewrite_rules();

        // Clear any WooCommerce specific caches
        if (function_exists('wc_clear_product_transients_and_cache')) {
            wc_clear_product_transients_and_cache();
        }

        // Log the cache clearing
        $this->core->add_debug('Transfer caches cleared', [
            'destination_taxonomy' => $destination_taxonomy,
            'timestamp' => current_time('mysql')
        ]);
    }
}
