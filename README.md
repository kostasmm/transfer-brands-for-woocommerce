# Transfer Brands for WooCommerce

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/transfer-brands-for-woocommerce)](https://wordpress.org/plugins/transfer-brands-for-woocommerce/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/transfer-brands-for-woocommerce)](https://wordpress.org/plugins/transfer-brands-for-woocommerce/)
[![WordPress Plugin Rating](https://img.shields.io/wordpress/plugin/stars/transfer-brands-for-woocommerce)](https://wordpress.org/plugins/transfer-brands-for-woocommerce/)
[![WordPress Tested](https://img.shields.io/badge/WordPress-6.9-green.svg)](https://wordpress.org/)
[![WooCommerce Tested](https://img.shields.io/badge/WooCommerce-10.3.6-purple.svg)](https://woocommerce.com/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Official WooCommerce 9.6 brand migration tool. Transfer from Perfect Brands, YITH, or custom attributes with backup and image support.

## Why This Plugin?

**With WooCommerce 9.6 launching on January 20th, 2025, all stores will have the Brands feature enabled by default.** This creates a migration challenge for stores that already use brand attributes - your existing brand data won't automatically transfer to the new system.

Transfer Brands for WooCommerce provides the missing link that WooCommerce doesn't offer - a safe, reliable way to migrate all your existing brand attributes to the new WooCommerce 9.6 brand taxonomy without losing any data.

## Features

- **Smart Detection** - Automatically detects Perfect Brands, YITH, and other brand plugins
- **One-Click Switching** - Switch between brand sources without navigating to settings
- **Preview Transfer** - See exactly what will happen before starting
- **User-friendly Interface** - Intuitive admin interface with real-time progress
- **Universal Image Transfer** - Automatically transfers brand images from 30+ themes
- **Theme Compatibility** - Supports Woodmart, Porto, Flatsome, XStore, Electro, and more
- **Custom & Taxonomy Attributes** - Works with both custom and taxonomy-based brand attributes
- **Batch Processing** - Process products in batches to avoid timeouts on large stores
- **Backup System** - Built-in backup and rollback functionality
- **Analysis Tool** - Analyze brands before transfer to identify potential issues
- **HPOS Compatible** - Full compatibility with WooCommerce High-Performance Order Storage
- **ACF Support** - Works with Advanced Custom Fields brand images
- **Accessibility** - Full keyboard navigation and screen reader support

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+

## Installation

1. Upload the `transfer-brands-for-woocommerce` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Transfer Brands to configure and use the plugin

## How It Works

The transfer process operates in three phases:

1. **Backup** - Creates a backup of existing brand data
2. **Terms Transfer** - Creates or maps taxonomy terms with images
3. **Product Assignment** - Associates products with the new taxonomy terms

## Before You Transfer

1. Use the "Analyze Brands" function to check your data
2. Ensure you have a recent database backup
3. Test on a staging site if possible
4. Verify that the backup feature is enabled in settings

## Supported Themes

The plugin automatically detects and transfers brand images from:

- Woodmart, Porto, Flatsome, XStore, Electro
- Martfury, Shopkeeper, BeTheme, Avada, Salient, The7
- And 30+ other popular WooCommerce themes

## Support

- **Documentation**: [pluginatlas.com/transfer-brands-for-woocommerce](https://pluginatlas.com/transfer-brands-for-woocommerce/)
- **Support**: [WordPress.org Forums](https://wordpress.org/support/plugin/transfer-brands-for-woocommerce/)
- **Issues**: [GitHub Issues](https://github.com/kostasmm/transfer-brands-for-woocommerce/issues)

## License

This plugin is licensed under the GPL v2 or later.

---

Made with care by [PluginAtlas](https://pluginatlas.com)
