# Installation Guide for Transfer Brands for WooCommerce

This document provides detailed instructions for installing and setting up the Transfer Brands for WooCommerce plugin.

## System Requirements

- WordPress 5.6 or higher
- PHP 7.2 or higher
- WooCommerce 5.0 or higher
- MySQL 5.6 or higher / MariaDB 10.0 or higher

## Installation Methods

### Method 1: WordPress.org Repository (Recommended)

1. Log in to your WordPress admin dashboard
2. Navigate to Plugins > Add New
3. Search for "Transfer Brands for WooCommerce"
4. Click "Install Now" and then "Activate"

### Method 2: Manual Installation

1. Download the plugin ZIP file from [WordPress.org](https://wordpress.org/plugins/transfer-brands-for-woocommerce/)
2. Log in to your WordPress admin dashboard
3. Navigate to Plugins > Add New
4. Click "Upload Plugin" and select the ZIP file you downloaded
5. Click "Install Now" and then "Activate Plugin"

### Method 3: Via FTP

1. Download the plugin ZIP file from [WordPress.org](https://wordpress.org/plugins/transfer-brands-for-woocommerce/)
2. Extract the ZIP file to your computer
3. Connect to your website using an FTP client
4. Navigate to the `/wp-content/plugins/` directory
5. Upload the `transfer-brands-for-woocommerce` folder to this directory
6. Log in to your WordPress admin dashboard
7. Navigate to Plugins and activate "Transfer Brands for WooCommerce"

## Post-Installation Setup

1. Navigate to WooCommerce > Transfer Brands to access the main interface
2. Go to the Settings tab to configure the plugin:
   - Set the source attribute taxonomy (default: pa_brand)
   - Configure batch size based on your server capabilities
   - Enable/disable backup functionality (recommended to keep enabled)
   - Enable debug mode if you need detailed troubleshooting

## Destination Taxonomy Configuration

The plugin now automatically uses the brand permalink setting from WooCommerce:

1. To configure the destination taxonomy, go to Settings > Permalinks in your WordPress admin
2. Look for the "Product brand base" option in the permalinks settings
3. Enter your desired slug for brand archives (default: product_brand)
4. Save your changes
5. The plugin will automatically detect and use this setting

## Initial Configuration Recommendations

- Start with a smaller batch size (20-30) and increase if your server can handle larger batches
- Always run the "Analyze Brands" function before initiating a transfer
- Consider testing on a staging site before running on a production store
- Ensure you have a recent database backup before performing a full transfer
- For large stores (1000+ products), ensure your PHP memory limit is at least 256MB

## Usage Tips

- The plugin creates a new taxonomy using the WooCommerce brand permalink setting
- After transfer, you may need to adjust theme templates to display brands
- Brand images will be preserved during transfer
- The plugin handles both taxonomy-based and custom attributes
- After successful transfer, you can optionally delete the old attribute data

## Troubleshooting

If you encounter issues during installation or usage:

1. Enable Debug Mode in the plugin settings
2. Check the Debug Log page for specific error messages
3. Ensure your server meets the minimum requirements
4. Verify that your WooCommerce installation is up to date
5. Check for plugin conflicts by temporarily deactivating other plugins

For additional support, please visit our [support forum on WordPress.org](https://wordpress.org/support/plugin/transfer-brands-for-woocommerce/).

## Version 2.8.0 Notes

### Theme Compatibility Update
Version 2.8.0 brings comprehensive theme compatibility for brand images:
- Automatic detection of 30+ different meta keys
- Full support for Woodmart, Porto, Flatsome, and other popular themes
- Smart image detection handles IDs, URLs, and serialized data
- ACF (Advanced Custom Fields) integration

### Upgrade Instructions
If upgrading from a previous version:
1. Back up your database before upgrading
2. After upgrading, run the "Analyze Brands" tool to see improved image detection
3. The plugin will now automatically detect and transfer images from your theme

## Version 2.6.3 Notes

In version 2.6.3, we've made additional improvements for WordPress.org compliance:

- Updated text domain loading to follow WordPress best practices
- Renamed all remaining legacy option names to use the TBFW prefix
- Fixed version numbering across the plugin

## Version 2.6.0 Notes

In version 2.6.0, we've made significant changes to comply with WordPress.org guidelines:

- All class and function names now use the TBFW_ prefix instead of WC_
- The plugin text domain has been updated to match the plugin slug
- SQL queries have been secured with proper preparation
- JavaScript code has been properly enqueued
- The plugin now explicitly declares its dependency on WooCommerce

If you're upgrading from a previous version, the plugin will automatically migrate your existing settings and data to the new format.