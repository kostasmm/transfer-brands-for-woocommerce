<?php
/**
 * Utils class for WooCommerce Transfer Brands Enhanced plugin
 *
 * Provides utility functions for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class TBFW_Transfer_Brands_Utils {
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
     * Count source terms
     * 
     * @return int Number of terms
     */
    public function count_source_terms() {
        $terms = get_terms([
            'taxonomy' => $this->core->get_option('source_taxonomy'), 
            'hide_empty' => false,
            'fields' => 'count'
        ]);
        
        return is_wp_error($terms) ? 0 : $terms;
    }
    
    /**
     * Count destination terms
     * 
     * @return int Number of terms
     */
    public function count_destination_terms() {
        $terms = get_terms([
            'taxonomy' => $this->core->get_option('destination_taxonomy'), 
            'hide_empty' => false,
            'fields' => 'count'
        ]);
        
        return is_wp_error($terms) ? 0 : $terms;
    }
    
    /**
     * Count products with source brand - Improved version for both taxonomy and custom attributes
     *
     * @since 2.8.5 Added support for brand plugin taxonomies (pwb-brand, etc.)
     * @return int Number of products
     */
    public function count_products_with_source() {
        global $wpdb;

        $source_taxonomy = $this->core->get_option('source_taxonomy');

        // Check if this is a brand plugin taxonomy (not a WooCommerce attribute)
        if ($this->is_brand_plugin_taxonomy($source_taxonomy)) {
            // For brand plugin taxonomies, count products using the taxonomy relationship
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT tr.object_id)
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
                    WHERE tt.taxonomy = %s
                    AND p.post_type = 'product'
                    AND p.post_status = 'publish'",
                    $source_taxonomy
                )
            );
        } else {
            // For WooCommerce attributes, use the _product_attributes meta query
            $count = $wpdb->get_var(
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

        // Log debug info
        $this->core->add_debug("Product count for {$source_taxonomy}: {$count}", [
            'source_taxonomy' => $source_taxonomy,
            'is_brand_plugin' => $this->is_brand_plugin_taxonomy($source_taxonomy),
            'count' => $count
        ]);

        return $count;
    }

    /**
     * Check if the given taxonomy is from a brand plugin (not a WooCommerce attribute)
     *
     * @since 2.8.5
     * @param string $taxonomy The taxonomy to check
     * @return bool True if it's a brand plugin taxonomy
     */
    public function is_brand_plugin_taxonomy($taxonomy) {
        // List of known brand plugin taxonomies
        $brand_plugin_taxonomies = [
            'pwb-brand',           // Perfect Brands for WooCommerce
            'yith_product_brand',  // YITH WooCommerce Brands
        ];

        return in_array($taxonomy, $brand_plugin_taxonomies, true);
    }

    /**
     * Get the image meta key for a brand plugin taxonomy
     *
     * @since 2.8.5
     * @param string $taxonomy The taxonomy to get the image key for
     * @return string|false The meta key or false if not a brand plugin
     */
    public function get_brand_plugin_image_key($taxonomy) {
        $image_keys = [
            'pwb-brand' => 'pwb_brand_image',
            'yith_product_brand' => 'yith_woocommerce_brand_thumbnail_id',
        ];

        return isset($image_keys[$taxonomy]) ? $image_keys[$taxonomy] : false;
    }
    
    /**
     * Get products with custom brand attributes
     * 
     * @param int $limit Number of products to retrieve
     * @return array Product details
     */
    public function get_custom_brand_products($limit = 10) {
        global $wpdb;
        
        $products = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
                WHERE meta_key = '_product_attributes' 
                AND meta_value LIKE %s
                AND meta_value LIKE %s
                AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')
                LIMIT %d",
                '%' . $wpdb->esc_like($this->core->get_option('source_taxonomy')) . '%',
                '%"is_taxonomy";i:0;%',
                $limit
            )
        );
        
        $result = [];
        foreach ($products as $product) {
            $attributes = maybe_unserialize($product->meta_value);
            $product_obj = wc_get_product($product->post_id);
            
            if ($product_obj && is_array($attributes) && isset($attributes[$this->core->get_option('source_taxonomy')])) {
                $result[] = [
                    'id' => $product->post_id,
                    'name' => $product_obj->get_name(),
                    'attribute' => $attributes[$this->core->get_option('source_taxonomy')]
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Get products with taxonomy brand attributes
     * 
     * @param int $limit Number of products to retrieve
     * @return array Product details
     */
    public function get_taxonomy_brand_products($limit = 10) {
        $query = new WP_Query([
            'post_type' => 'product',
            'posts_per_page' => $limit,
            'tax_query' => [
                [
                    'taxonomy' => $this->core->get_option('source_taxonomy'),
                    'operator' => 'EXISTS',
                ]
            ]
        ]);
        
        $result = [];
        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                $product = wc_get_product($post->ID);
                if (!$product) continue;
                
                $attrs = $product->get_attributes();
                if (isset($attrs[$this->core->get_option('source_taxonomy')])) {
                    $terms = [];
                    foreach ($attrs[$this->core->get_option('source_taxonomy')]->get_options() as $term_id) {
                        $term = get_term($term_id, $this->core->get_option('source_taxonomy'));
                        if ($term && !is_wp_error($term)) {
                            $terms[] = $term->name;
                        }
                    }
                    
                    $result[] = [
                        'id' => $post->ID,
                        'name' => $product->get_name(),
                        'brands' => $terms
                    ];
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Get terms with images
     * 
     * @return array Term details
     */
    public function get_terms_with_images() {
        $terms = get_terms([
            'taxonomy' => $this->core->get_option('source_taxonomy'), 
            'hide_empty' => false
        ]);
        
        if (is_wp_error($terms)) {
            return [];
        }
        
        $result = [];
        foreach ($terms as $term) {
            // Check both possible image meta keys
            $image_id = get_term_meta($term->term_id, 'thumbnail_id', true);
            if (!$image_id) {
                $image_id = get_term_meta($term->term_id, 'brand_image_id', true);
            }
            if ($image_id) {
                $result[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'image_id' => $image_id
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Get terms that already exist in destination
     *
     * @return array Term names
     */
    public function get_conflicting_terms() {
        $source_terms = get_terms([
            'taxonomy' => $this->core->get_option('source_taxonomy'),
            'hide_empty' => false
        ]);

        if (is_wp_error($source_terms)) {
            return [];
        }

        $conflicting_terms = [];
        foreach ($source_terms as $term) {
            $exists = term_exists($term->name, $this->core->get_option('destination_taxonomy'));
            if ($exists) {
                $conflicting_terms[] = $term->name;
            }
        }

        return $conflicting_terms;
    }

    /**
     * Check if WooCommerce Brands feature is properly enabled
     *
     * WooCommerce 9.6+ has built-in Brands that must be explicitly enabled.
     * This method checks various indicators to determine if the feature is active.
     *
     * @since 2.8.4
     * @return array Status information with 'enabled' boolean and 'message' string
     */
    public function check_woocommerce_brands_status() {
        $result = [
            'enabled' => false,
            'message' => '',
            'details' => [],
            'instructions' => ''
        ];

        // Check 1: Is WooCommerce active?
        if (!class_exists('WooCommerce')) {
            $result['message'] = __('WooCommerce is not active.', 'transfer-brands-for-woocommerce');
            $result['details'][] = __('WooCommerce must be installed and activated.', 'transfer-brands-for-woocommerce');
            return $result;
        }

        // Check 2: WooCommerce version (Brands introduced in 9.4, stable in 9.6)
        $wc_version = defined('WC_VERSION') ? WC_VERSION : '0.0.0';
        $result['details'][] = sprintf(
            /* translators: %s: WooCommerce version number */
            __('WooCommerce version: %s', 'transfer-brands-for-woocommerce'),
            $wc_version
        );

        if (version_compare($wc_version, '9.4.0', '<')) {
            $result['message'] = __('WooCommerce version is too old for built-in Brands.', 'transfer-brands-for-woocommerce');
            $result['details'][] = __('WooCommerce 9.4+ is required for the built-in Brands feature.', 'transfer-brands-for-woocommerce');
            $result['instructions'] = __('Please update WooCommerce to version 9.4 or higher.', 'transfer-brands-for-woocommerce');
            return $result;
        }

        // Check 3: Is the product_brand taxonomy registered?
        $destination_taxonomy = $this->core->get_option('destination_taxonomy', 'product_brand');
        $taxonomy_exists = taxonomy_exists($destination_taxonomy);

        $result['details'][] = sprintf(
            /* translators: %s: Taxonomy name */
            __('Destination taxonomy "%s": %s', 'transfer-brands-for-woocommerce'),
            $destination_taxonomy,
            $taxonomy_exists ? __('Registered', 'transfer-brands-for-woocommerce') : __('Not registered', 'transfer-brands-for-woocommerce')
        );

        // Check 4: Check if WooCommerce Brands feature is enabled via the feature flag
        // WooCommerce uses the woocommerce_feature_product_brands_enabled option
        $brands_feature_enabled = get_option('woocommerce_feature_product_brands_enabled', 'no');

        // Also check the newer format used in some WC versions
        if ($brands_feature_enabled !== 'yes') {
            // Try checking via WC Features API if available
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                // Check if the feature flag system recognizes brands
                $brands_feature_enabled = get_option('woocommerce_feature_product_brand_enabled', 'no');
            }
        }

        $result['details'][] = sprintf(
            __('WooCommerce Brands feature flag: %s', 'transfer-brands-for-woocommerce'),
            $brands_feature_enabled === 'yes' ? __('Enabled', 'transfer-brands-for-woocommerce') : __('Disabled', 'transfer-brands-for-woocommerce')
        );

        // Check 5: Check if there's a Brands menu item in WooCommerce Products menu
        // This is registered when the feature is properly enabled
        $brands_admin_menu_exists = false;
        if ($taxonomy_exists) {
            $taxonomy_obj = get_taxonomy($destination_taxonomy);
            if ($taxonomy_obj && isset($taxonomy_obj->show_ui) && $taxonomy_obj->show_ui) {
                $brands_admin_menu_exists = true;
            }
        }

        $result['details'][] = sprintf(
            __('Brands admin UI: %s', 'transfer-brands-for-woocommerce'),
            $brands_admin_menu_exists ? __('Available', 'transfer-brands-for-woocommerce') : __('Not available', 'transfer-brands-for-woocommerce')
        );

        // Check 6: Verify it's WooCommerce's taxonomy (not a custom one)
        $is_wc_brands = false;
        if ($taxonomy_exists) {
            $taxonomy_obj = get_taxonomy($destination_taxonomy);
            // WooCommerce's brand taxonomy is associated with 'product' post type
            // and has specific labels set by WooCommerce
            if ($taxonomy_obj &&
                in_array('product', (array) $taxonomy_obj->object_type) &&
                isset($taxonomy_obj->labels->menu_name)) {
                $is_wc_brands = true;
            }
        }

        // Determine final status
        if ($taxonomy_exists && ($brands_feature_enabled === 'yes' || $is_wc_brands) && $brands_admin_menu_exists) {
            $result['enabled'] = true;
            $result['message'] = __('WooCommerce Brands is properly enabled and ready.', 'transfer-brands-for-woocommerce');
        } elseif ($taxonomy_exists && !$brands_admin_menu_exists) {
            $result['enabled'] = false;
            $result['message'] = __('The brand taxonomy exists but WooCommerce Brands feature may not be fully enabled.', 'transfer-brands-for-woocommerce');
            $result['instructions'] = sprintf(
                /* translators: %1$s: Opening link tag, %2$s: Closing link tag */
                __('Please enable the Brands feature in %1$sWooCommerce → Settings → Advanced → Features%2$s and look for "Product Brands" or similar option.', 'transfer-brands-for-woocommerce'),
                '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=advanced&section=features')) . '" target="_blank">',
                '</a>'
            );
        } elseif (!$taxonomy_exists) {
            $result['enabled'] = false;
            $result['message'] = __('WooCommerce Brands taxonomy is not registered.', 'transfer-brands-for-woocommerce');
            $result['instructions'] = sprintf(
                /* translators: %1$s: Opening link tag, %2$s: Closing link tag */
                __('Please enable the Brands feature in %1$sWooCommerce → Settings → Advanced → Features%2$s. After enabling, refresh this page.', 'transfer-brands-for-woocommerce'),
                '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=advanced&section=features')) . '" target="_blank">',
                '</a>'
            );
        } else {
            // Taxonomy exists, feature flag might be different, let's allow with warning
            $result['enabled'] = true;
            $result['message'] = __('Brand taxonomy is available. Transfer can proceed.', 'transfer-brands-for-woocommerce');
            $result['details'][] = __('Note: Could not verify if this is WooCommerce official Brands. Brands may have been created by another plugin.', 'transfer-brands-for-woocommerce');
        }

        // Log debug info
        $this->core->add_debug("WooCommerce Brands status check", $result);

        return $result;
    }

    /**
     * Quick check if transfer can proceed
     *
     * @since 2.8.4
     * @return bool True if transfer can proceed
     */
    public function can_transfer() {
        $status = $this->check_woocommerce_brands_status();
        return $status['enabled'];
    }
}
