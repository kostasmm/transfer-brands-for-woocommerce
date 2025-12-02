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
     * @return int Number of products
     */
    public function count_products_with_source() {
        global $wpdb;
        
        $source_taxonomy = $this->core->get_option('source_taxonomy');
        
        // Get all products with this attribute, regardless of whether it's a taxonomy or custom attribute
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
        
        // Log debug info
        $this->core->add_debug("Product count for {$source_taxonomy}: {$count}", [
            'source_taxonomy' => $source_taxonomy,
            'sql' => $wpdb->last_query,
            'count' => $count
        ]);
        
        return $count;
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
}
