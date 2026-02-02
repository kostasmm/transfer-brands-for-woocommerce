<?php
/**
 * Plugin Name: Transfer Brands for WooCommerce
 * Plugin URI: https://pluginatlas.com/transfer-brands-for-woocommerce
 * Description: Official WooCommerce 9.6 brand migration tool. Transfer from Perfect Brands, YITH, or custom attributes with backup and image support.
 * Version: 3.0.7
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Kostas Malakontas
 * Author URI: https://pluginatlas.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: transfer-brands-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 8.0.0
 * WC tested up to: 10.3.6
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('TBFW_VERSION', '3.0.7');
define('TBFW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TBFW_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TBFW_INCLUDES_DIR', TBFW_PLUGIN_DIR . 'includes/');
define('TBFW_ASSETS_URL', TBFW_PLUGIN_URL . 'assets/');

/**
 * Check if WooCommerce is active
 *
 * @since 2.3.0
 * @return bool True if WooCommerce is active
 */
function tbfw_is_woocommerce_active() {
    $active_plugins = (array) get_option('active_plugins', array());
    
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
    }
    
    return in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
}

/**
 * Display a notice if WooCommerce is not active
 *
 * @since 2.3.0
 */
function tbfw_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e('Transfer Brands for WooCommerce requires WooCommerce to be installed and activated.', 'transfer-brands-for-woocommerce'); ?></p>
    </div>
    <?php
}

/**
 * Initialize the plugin
 *
 * @since 2.3.0
 */
function tbfw_init() {
    // Check if WooCommerce is active
    if (!tbfw_is_woocommerce_active()) {
        add_action('admin_notices', 'tbfw_woocommerce_missing_notice');
        return;
    }

    // Include core files
    require_once TBFW_INCLUDES_DIR . 'class-core.php';
    require_once TBFW_INCLUDES_DIR . 'class-admin.php';
    require_once TBFW_INCLUDES_DIR . 'class-transfer.php';
    require_once TBFW_INCLUDES_DIR . 'class-backup.php';
    require_once TBFW_INCLUDES_DIR . 'class-ajax.php';
    require_once TBFW_INCLUDES_DIR . 'class-utils.php';

    // Initialize the plugin
    TBFW_Transfer_Brands_Core::get_instance();

    // Smart source detection on first admin load (deferred from activation)
    add_action('admin_init', 'tbfw_smart_source_detection');
}
add_action('plugins_loaded', 'tbfw_init');

/**
 * Smart source detection - runs on first admin load after activation
 * Detects installed brand plugins and updates source taxonomy setting
 *
 * @since 3.0.6
 */
function tbfw_smart_source_detection() {
    // Only run if we need smart detection
    if (!get_transient('tbfw_needs_smart_detection')) {
        return;
    }

    // Delete the transient first to prevent repeat runs
    delete_transient('tbfw_needs_smart_detection');

    $options = get_option('tbfw_transfer_brands_options', []);

    // Only detect if still using default pa_brand
    if (isset($options['source_taxonomy']) && $options['source_taxonomy'] === 'pa_brand') {
        $smart_source = 'pa_brand';

        // Check for Perfect Brands (most common)
        if (taxonomy_exists('pwb-brand')) {
            $smart_source = 'pwb-brand';
        }
        // Check for YITH Brands
        elseif (taxonomy_exists('yith_product_brand')) {
            $smart_source = 'yith_product_brand';
        }

        // Update if we found a better source
        if ($smart_source !== 'pa_brand') {
            $options['source_taxonomy'] = $smart_source;
            update_option('tbfw_transfer_brands_options', $options);
        }
    }
}

/**
 * Plugin activation
 *
 * @since 2.3.0
 */
function tbfw_activate() {
    // --- Migrate legacy option names to new namespace ---
    $legacy_options = [
        'wc_transfer_brands_backup'         => 'tbfw_backup',
        'wc_transfer_brands_term_mappings' => 'tbfw_term_mappings',
        'wc_transfer_processed_products'   => 'tbfw_brands_processed_ids',
        'wc_brands_backup_cleanup_log'     => 'tbfw_backup_cleanup_log',
    ];
    foreach ( $legacy_options as $old => $new ) {
        $old_val = get_option( $old, null );
        if ( ! is_null( $old_val ) && false === get_option( $new, false ) ) {
            update_option( $new, $old_val );
            delete_option( $old );
        }
    }
    
    // Add default options - defer smart source detection to admin_init when taxonomies are registered
    if (!get_option('tbfw_transfer_brands_options')) {
        // Set default with pa_brand, smart detection will update on first admin load
        add_option('tbfw_transfer_brands_options', [
            'source_taxonomy' => 'pa_brand',
            'destination_taxonomy' => 'product_brand',
            'batch_size' => 10,
            'backup_enabled' => true,
            'debug_mode' => false
        ]);
        // Set transient to trigger smart detection on first admin load
        set_transient('tbfw_needs_smart_detection', true, DAY_IN_SECONDS);
    }

    // Ensure debug-related options exist and are not autoloaded to avoid slow autoload queries
    if (false === get_option('tbfw_brands_debug_log', false)) {
        add_option('tbfw_brands_debug_log', [], '', 'no');
    } else {
        // If it exists but might be autoloaded, switch to non-autoloaded
        update_option('tbfw_brands_debug_log', get_option('tbfw_brands_debug_log', []), false);
    }

    // Pre-create heavy options as non-autoloaded to prevent loading on every request
    if (false === get_option('tbfw_backup', false)) {
        add_option('tbfw_backup', [], '', 'no');
    } else {
        update_option('tbfw_backup', get_option('tbfw_backup', []), false);
    }

    if (false === get_option('tbfw_term_mappings', false)) {
        add_option('tbfw_term_mappings', [], '', 'no');
    } else {
        update_option('tbfw_term_mappings', get_option('tbfw_term_mappings', []), false);
    }

    if (false === get_option('tbfw_deleted_brands_backup', false)) {
        add_option('tbfw_deleted_brands_backup', [], '', 'no');
    } else {
        update_option('tbfw_deleted_brands_backup', get_option('tbfw_deleted_brands_backup', []), false);
    }

    if (false === get_option('tbfw_brands_processed_ids', false)) {
        add_option('tbfw_brands_processed_ids', [], '', 'no');
    } else {
        update_option('tbfw_brands_processed_ids', get_option('tbfw_brands_processed_ids', []), false);
    }

    if (false === get_option('tbfw_backup_cleanup_log', false)) {
        add_option('tbfw_backup_cleanup_log', [], '', 'no');
    } else {
        update_option('tbfw_backup_cleanup_log', get_option('tbfw_backup_cleanup_log', []), false);
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'tbfw_activate');

/**
 * Declare HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Plugin deactivation
 *
 * @since 2.3.0
 */
function tbfw_deactivate() {
    // Clean up transients
    delete_transient('tbfw_transfer_brands_transient');
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'tbfw_deactivate');
