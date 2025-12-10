<?php
/**
 * Core class for Transfer Brands for WooCommerce plugin
 *
 * @package TBFW_Transfer_Brands
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Core class for the plugin
 * 
 * Handles initialization and serves as the main controller for the plugin.
 *
 * @since 2.3.0
 */
class TBFW_Transfer_Brands_Core {
    /**
     * Singleton instance
     *
     * @since 2.3.0
     * @var TBFW_Transfer_Brands_Core
     */
    private static $instance = null;
    
    /**
     * Plugin options
     *
     * @since 2.3.0
     * @var array
     */
    private $options;
    
    /**
     * Number of products to process per batch.
     *
     * @since 2.3.0
     * @var int
     */
    private $batch_size = 10;
    
    /**
     * Admin class instance
     *
     * @since 2.3.0
     * @var TBFW_Transfer_Brands_Admin
     */
    private $admin;
    
    /**
     * Transfer class instance
     *
     * @since 2.3.0
     * @var TBFW_Transfer_Brands_Transfer
     */
    private $transfer;
    
    /**
     * Backup class instance
     *
     * @since 2.3.0
     * @var TBFW_Transfer_Brands_Backup
     */
    private $backup;
    
    /**
     * Ajax class instance
     *
     * @since 2.3.0
     * @var TBFW_Transfer_Brands_Ajax
     */
    private $ajax;
    
    /**
     * Utils class instance
     *
     * @since 2.3.0
     * @var TBFW_Transfer_Brands_Utils
     */
    private $utils;
    
    /**
     * Debug log for troubleshooting
     *
     * @since 2.3.0
     * @var array
     */
    private $debug_log = [];
	/**
	 * Tracks whether the debug log has been loaded from the database
	 * and whether a single write has been scheduled for shutdown.
	 *
	 * @since 2.8.1
	 * @var bool
	 */
	private $has_loaded_debug_log = false;
	private $has_scheduled_debug_write = false;
    
    /**
     * Get singleton instance
     * 
     * @since 2.3.0
     * @return TBFW_Transfer_Brands_Core The singleton instance
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     *
     * @since 2.3.0
     */
    private function __construct() {
        // Load options
        $this->options = get_option('tbfw_transfer_brands_options', [
            'source_taxonomy' => 'pa_brand',
            'batch_size' => 10,
            'backup_enabled' => true,
            'debug_mode' => false
        ]);
        
        // Replace destination_taxonomy with the value from WooCommerce
        $this->options['destination_taxonomy'] = $this->get_woocommerce_brand_permalink();
        
        // Set batch size from options
        $this->batch_size = isset($this->options['batch_size']) ? absint($this->options['batch_size']) : 10;
        
        // Initialize component classes
        $this->admin = new TBFW_Transfer_Brands_Admin($this);
        $this->transfer = new TBFW_Transfer_Brands_Transfer($this);
        $this->backup = new TBFW_Transfer_Brands_Backup($this);
        $this->ajax = new TBFW_Transfer_Brands_Ajax($this);
        $this->utils = new TBFW_Transfer_Brands_Utils($this);
        
        // Check and ensure destination taxonomy exists
        add_action('admin_init', [$this, 'ensure_brand_taxonomy_exists']);
    }
    
    /**
     * Gets the brand permalink from WooCommerce settings
     * 
     * @return string Brand permalink (default: product_brand)
     */
    private function get_woocommerce_brand_permalink() {
        $brand_permalink = get_option('woocommerce_brand_permalink', 'product_brand');

        // If empty, use the default value
        if (empty($brand_permalink)) {
            $brand_permalink = 'product_brand';
        }

        return $brand_permalink;
    }
    
    /**
     * Reloads the options when the WooCommerce permalink changes
     */
    public function reload_destination_taxonomy() {
        $this->options['destination_taxonomy'] = $this->get_woocommerce_brand_permalink();
        return $this->options['destination_taxonomy'];
    }
    
    /**
     * Get plugin options
     * 
     * @since 2.3.0
     * @return array Plugin options
     */
    public function get_options() {
        return $this->options;
    }
    
    /**
     * Get a specific option
     * 
     * @since 2.3.0
     * @param string $key Option key
     * @param mixed $default Default value if option doesn't exist
     * @return mixed Option value
     */
    public function get_option($key, $default = null) {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }
    
    /**
     * Get batch size
     * 
     * @since 2.3.0
     * @return int Batch size
     */
    public function get_batch_size() {
        return $this->batch_size;
    }
    
    /**
     * Add debugging info to the log
     * 
     * @since 2.3.0
     * @param string $message Debug message
     * @param mixed $data Optional data to log
     */
    public function add_debug($message, $data = null) {
        if (!isset($this->options['debug_mode']) || !$this->options['debug_mode']) {
            return;
        }
		// Load once per request
		if (!$this->has_loaded_debug_log) {
			$this->debug_log = get_option('tbfw_brands_debug_log', []);
			$this->has_loaded_debug_log = true;
		}
		
        $this->debug_log[] = [
            'time' => current_time('mysql'),
            'message' => $message,
            'data' => $data
        ];
		
        // Schedule a single write at shutdown instead of writing on every call
        if (!$this->has_scheduled_debug_write) {
            $this->has_scheduled_debug_write = true;
            add_action('shutdown', function() {
                // Cap the log to the most recent 1000 entries to avoid unbounded growth
                $log = $this->debug_log;
                if (is_array($log) && count($log) > 1000) {
                    $log = array_slice($log, -1000);
                }
                update_option('tbfw_brands_debug_log', $log);
            });
        }
    }
    
    /**
     * Get component class instances
     *
     * @since 2.3.0
     * @return TBFW_Transfer_Brands_Admin Admin instance
     */
    public function get_admin() {
        return $this->admin;
    }
    
    /**
     * Get transfer instance
     *
     * @since 2.3.0
     * @return TBFW_Transfer_Brands_Transfer Transfer instance
     */
    public function get_transfer() {
        return $this->transfer;
    }
    
    /**
     * Get backup instance
     *
     * @since 2.3.0
     * @return TBFW_Transfer_Brands_Backup Backup instance
     */
    public function get_backup() {
        return $this->backup;
    }
    
    /**
     * Get ajax instance
     *
     * @since 2.3.0
     * @return TBFW_Transfer_Brands_Ajax Ajax instance
     */
    public function get_ajax() {
        return $this->ajax;
    }
    
    /**
     * Get utils instance
     *
     * @since 2.3.0
     * @return TBFW_Transfer_Brands_Utils Utils instance
     */
    public function get_utils() {
        return $this->utils;
    }
    
    /**
     * Ensure the destination brand taxonomy exists
     *
     * @since 2.3.0
     */
    public function ensure_brand_taxonomy_exists() {
        if (!taxonomy_exists($this->options['destination_taxonomy'])) {
            // Register the taxonomy if it doesn't exist
            register_taxonomy(
                $this->options['destination_taxonomy'],
                'product',
                [
                    'hierarchical' => true,
                    'label' => __('Product Brands', 'transfer-brands-for-woocommerce'),
                    'query_var' => true,
                    'rewrite' => ['slug' => $this->options['destination_taxonomy']],
                    'show_in_rest' => true,
                ]
            );
            
            // Flush rewrite rules
            flush_rewrite_rules();
            
            // Add notice
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo sprintf(
                    /* translators: %s: Taxonomy name */
                    esc_html__('Created missing "%s" taxonomy. You can now proceed with brand transfer.', 'transfer-brands-for-woocommerce'), 
                    esc_html($this->options['destination_taxonomy'])
                );
                echo '</p></div>';
            });
        }
    }
}