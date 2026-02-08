=== Transfer Brands for WooCommerce ===
Contributors: malakontask
Tags: woocommerce, brands, migration, woocommerce brands, brand migration
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 3.0.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 8.0.0
WC tested up to: 10.3.6

Official WooCommerce 9.6 brand migration tool. Transfer from Perfect Brands, YITH, or custom attributes with backup and image support.

== Description ==

= Official WooCommerce 9.6 Brand Migration Tool =

**With WooCommerce 9.6 launching on January 20th, 2025, all stores will have the Brands feature enabled by default.** This creates a migration challenge for stores that already use brand attributes - your existing brand data won't automatically transfer to the new system.

Transfer Brands for WooCommerce provides the missing link that WooCommerce doesn't offer - a safe, reliable way to migrate all your existing brand attributes (both taxonomy-based and custom) to the new WooCommerce 9.6 brand taxonomy without losing any data.

Unlike manual migration that risks data loss, our plugin:
* Preserves all brand relationships and hierarchies
* Transfers brand images automatically
* Provides complete backup and rollback functionality
* Shows real-time progress with detailed statistics
* Handles stores of any size with batch processing

Transfer Brands Enhanced for WooCommerce is a powerful tool that simplifies the process of migrating your product brand attributes to a dedicated product_brand taxonomy. This is particularly useful for store owners who want to improve their brand management and enhance storefront navigation.

### Key Features

* **User-friendly Interface**: With an intuitive admin interface showing real-time progress during transfers.
* **Universal Image Transfer**: Automatically detects and transfers brand images from 30+ different themes including Woodmart, Porto, Flatsome, XStore, Electro, and more.
* **Theme Compatibility**: Full support for popular WooCommerce themes - your brand images will transfer regardless of which theme you use.
* **Custom and Taxonomy Attribute Support**: Works with both custom and taxonomy-based brand attributes.
* **Batch Processing**: Process products in batches to avoid timeouts on large stores.
* **Backup System**: Built-in backup and rollback functionality for risk-free transfers.
* **Analysis Tool**: Analyze your brands before transfer to identify potential issues.
* **WooCommerce Integration**: Automatically uses the brand permalink settings from WooCommerce.
* **HPOS Compatible**: Fully compatible with WooCommerce High-Performance Order Storage.
* **ACF Support**: Works with Advanced Custom Fields brand images.
* **Detailed Logging**: Comprehensive logging for troubleshooting (in debug mode).

### Use Cases

* You want to migrate your brand attributes to prepare for WooCommerce 9.6 update
* You need to replace an existing brand attribute with a proper taxonomy for better store organization
* You want to improve your store's SEO by using proper brand taxonomy pages
* You need to implement brand filtering on your shop pages
* You want to consolidate different brand attributes into a single taxonomy

### Why Use a Brand Taxonomy?

Converting your brand attributes to a taxonomy offers several advantages:

* Better SEO with dedicated brand archive pages
* Enhanced filtering options in your store
* Simplified brand management
* Ability to add additional brand metadata (like logos, descriptions, etc.)

== Installation ==

1. Upload the `transfer-brands-for-woocommerce` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Transfer Brands to configure and use the plugin

== Frequently Asked Questions ==

= How does this plugin help with WooCommerce 9.6 migration? =

WooCommerce 9.6 (releasing January 20th, 2025) will enable Brands as a core feature for all stores. However, it doesn't provide any migration tool for existing brand attributes. Our plugin fills this gap by safely transferring all your existing brand data (including images) to the new system with complete backup protection.

= Will I lose my existing brand data when updating to WooCommerce 9.6? =

Without a migration solution, your existing brand attributes will remain separate from the new WooCommerce Brands system. You would need to manually recreate all your brands and reassign them to products. Our plugin automates this process safely with full backup options.

= Is this plugin compatible with WooCommerce 9.6? =

Yes! This plugin is specifically designed to work with WooCommerce 9.6's new brand taxonomy structure. We've thoroughly tested it for compatibility with both the current and upcoming WooCommerce releases.

= Is this plugin compatible with the latest WooCommerce version? =

Yes, this plugin is regularly tested with the latest versions of WooCommerce.

= Will this plugin delete my existing brand data? =

No, the plugin creates a backup of your data before making any changes. You can roll back the changes if needed. Additionally, the original attribute data is left untouched until you explicitly choose to delete it.

= How long will the transfer process take? =

The time depends on the number of products and brands in your store. The plugin processes products in configurable batches to prevent timeouts, and displays progress in real-time.

= Can I customize which brand attribute is transferred? =

Yes, you can configure the source attribute in the plugin settings page. The destination taxonomy is automatically set to match your WooCommerce permalink settings.

= Will this plugin work with my custom brand attribute? =

Yes, the plugin supports both taxonomy-based and custom product attributes as the source for brands.

= What if I have multiple brand attributes? =

Currently, the plugin transfers one attribute at a time. You can run multiple transfers if needed.

= How can I troubleshoot issues? =

Enable debug mode in the plugin settings to access detailed logs, which can help identify and resolve issues.

= Can I migrate from Perfect Brands for WooCommerce? =

Yes! Transfer Brands fully supports migrating from Perfect Brands for WooCommerce (pwb-brand taxonomy). Simply select "Perfect Brands" from the source dropdown and your brands, including images, will be transferred to WooCommerce's built-in Brands taxonomy.

= Can I migrate from YITH WooCommerce Brands? =

Yes! The plugin supports YITH WooCommerce Brands (yith_product_brand taxonomy). Select it as your source and transfer all your brand data to WooCommerce Brands with one click.

= What happens to my Perfect Brands or YITH data after migration? =

Your original data remains untouched until you explicitly choose to delete it. The plugin creates a full backup before any transfer, and you can rollback at any time if needed.

== Screenshots ==

1. Main transfer interface
2. Brand analysis results
3. Transfer in progress with detailed statistics
4. Settings page
5. Debug and troubleshooting tools

== Changelog ==

= 3.0.7 =
* **Fixed**: Critical - PWB transfer now correctly transfers ALL brands instead of only the first one
* **Fixed**: Terms with empty/corrupted names are now skipped instead of halting the entire transfer
* **Fixed**: Term creation errors no longer stop the transfer - failed terms are skipped with a log message
* **Fixed**: SEO URL preservation - brand slugs are now preserved during transfer to maintain existing URLs
* **Improved**: Added cache clearing between term batch AJAX calls to prevent stale results on sites with persistent object cache (Redis/Memcached)
* **Improved**: Better logging shows preserved slugs during term creation

= 3.0.6 =
* **Security**: Fixed XSS vulnerabilities in error message display - now properly escapes all dynamic content
* **Fixed**: Critical - Removed dead/shadowed runStep function that caused code confusion
* **Fixed**: Critical - Added tbfwTbe object existence check to prevent JavaScript crashes
* **Fixed**: Critical - Backup creation now properly verified before proceeding with transfer
* **Fixed**: Delete initialization now validates server response before starting operation
* **Fixed**: Smart brand plugin detection now runs on admin_init instead of activation (fixes timing issue)
* **Removed**: Unused autoloader code that was never invoked
* **Improved**: Better error handling and user feedback throughout

= 3.0.5 =
* **Security**: Fixed XSS vulnerability in JavaScript log display - now escapes all log messages
* **Fixed**: Race condition in admin UI - added transfer-in-progress flag to prevent concurrent operations
* **Fixed**: Missing error handler in delete initialization - flag now properly resets on AJAX failure
* **Fixed**: Missing wp_reset_postdata() after WP_Query in get_taxonomy_brand_products()
* **Fixed**: Return type consistency in count_source_terms() and count_destination_terms()
* **Fixed**: Null safety in count_products_with_source() database query
* **Improved**: Integer parsing for restored products count in restore operation

= 3.0.4 =
* **Security**: Fixed XSS vulnerability in debug error messages - now properly escaped with esc_html()
* **Security**: Added capability checks to admin page methods (admin_page, debug_page, enqueue_admin_scripts)
* **Security**: Added capability check to ajax_dismiss_review_notice handler
* **Fixed**: Unchecked wc_get_product() call that could cause fatal error on invalid products
* **Fixed**: Database error handling in delete old brands - now properly checks $wpdb->last_error
* **Fixed**: Unchecked database query results in admin page - now cast to int with null coalescing
* **Fixed**: Array type assumption with deleted_backup - now uses is_array() check (PHP 8+ compatibility)
* **Fixed**: wp_get_object_terms() validation in backup - prevents WP_Error from corrupting backup data
* **Improved**: Better error messages for database failures during deletion operations

= 3.0.3 =
* **Fixed**: Added taxonomy validation before transfer - prevents cryptic errors when taxonomies don't exist
* **Fixed**: Race condition in batch processing - added transient-based locking to prevent concurrent transfers
* **Fixed**: Temporary tracking options now properly cleaned up after transfer completion
* **Fixed**: rollback_deleted_brands now has proper error handling for wp_insert_term and wp_set_object_terms
* **Fixed**: Backup preserved when rollback_deleted_brands has errors - prevents data loss
* **Fixed**: Added capability check to cleanup_backups - prevents unauthorized access
* **Added**: Lock mechanism prevents duplicate batch processing from concurrent AJAX requests
* **Added**: Detailed error tracking in rollback operations
* **Improved**: Rollback operations now report partial success with preserved backup for retry

= 3.0.2 =
* **Fixed**: Critical memory issue - terms batch processing now uses proper pagination instead of loading all terms on every batch
* **Fixed**: Silent transfer failures - wp_set_object_terms() now has comprehensive error checking
* **Fixed**: Products incorrectly marked as processed even when transfer failed
* **Fixed**: Rollback could delete backup data before verifying rollback was successful
* **Added**: Failed products tracking for diagnostics (stored separately for troubleshooting)
* **Added**: Detailed rollback reporting showing products restored and terms deleted
* **Improved**: Better error handling throughout the transfer process
* **Improved**: Rollback now only clears backup data when no errors occur

= 3.0.1 =
* **Fixed**: Products to Transfer count breakdown now correctly shows counts for brand plugin taxonomies (PWB, YITH)
* **Fixed**: "Custom: 0, Taxonomy: 0, Total: X" display issue when using brand plugins - now shows "Brand plugin products: X"
* **Fixed**: Brands appearing empty after transfer - added comprehensive cache clearing on completion
* **Added**: "Verify Transfer" button to check what was actually transferred and diagnose issues
* **Added**: Products with multiple brands are now listed in both "Analyze Brands" and "Preview Transfer" results
* **Added**: Expandable table showing affected products with edit links for easy fixing
* **Added**: Clear explanation message when products use brand plugin taxonomy instead of WooCommerce attributes
* **Added**: Automatic cache clearing after transfer (term cache, product transients, rewrite rules)
* **Improved**: Better diagnostic information for brand plugin migrations

= 3.0.0 =
* **Major UX Enhancement**: Smart detection banner automatically detects installed brand plugins
* Added: One-click source switching when alternative brand taxonomy detected
* Added: Smart default selection on activation (detects Perfect Brands, YITH Brands)
* Added: Button loading states with spinners to prevent double-clicks
* Added: Keyboard accessibility for modals (Escape to close, focus trap)
* Added: ARIA labels for screen reader accessibility
* Fixed: **CRITICAL** - Delete Old Brands now works correctly for brand plugin taxonomies
* Fixed: Backup system now correctly checks if backups are enabled
* Improved: Debug mode only logs during user-initiated operations
* Improved: Batch size defaults optimized for shared hosting (default: 10, max: 50)
* Improved: i18n compliance with proper translators comments for all placeholders

= 2.8.7 =
* Fixed: Removed UTF-8 BOM that caused "3 characters of unexpected output" warning during activation


= 2.8.6 =
* Updated: Compatibility with WordPress 6.9
* Updated: Compatibility with WooCommerce 10.3.6

= 2.8.5 =
* Added: Support for Perfect Brands for WooCommerce plugin (pwb-brand taxonomy)
* Added: Support for YITH WooCommerce Brands plugin (yith_product_brand taxonomy)
* Added: Automatic detection of third-party brand plugins in the source dropdown
* Added: Brand plugin taxonomies now shown in a separate "Brand Plugins" section in settings
* Improved: Analysis tool now properly displays brand plugin statistics
* Improved: Transfer logic handles taxonomy-to-taxonomy transfers for brand plugins
* Fixed: "Invalid taxonomy" error when using Perfect Brands for WooCommerce

= 2.8.4 =
* Added: Pre-transfer validation to check if WooCommerce Brands feature is enabled
* Added: Clear error message and instructions when WooCommerce Brands is not enabled
* Added: WooCommerce Brands status check in the "Analyze Brands" tool
* Added: Disabled "Start Transfer" button when WooCommerce Brands is not properly configured
* Fixed: Issue where transfers appeared successful but brands didn't show in WooCommerce admin
* Improved: Better detection of WooCommerce Brands feature status using multiple indicators
* Improved: More detailed technical information for troubleshooting

= 2.8.3 =
* Fixed: Brands not appearing in WooCommerce after transfer
* Fixed: 404 errors on brand pages by flushing rewrite rules after transfer
* Improved: Better WooCommerce 9.6+ brand taxonomy detection
* Added: Taxonomy cache clearing after transfer completion
* Added: Validation warnings if destination taxonomy doesn't exist

= 2.8.2 =
* Fixed WordPress.org plugin guidelines compliance
* Reduced tags to 5 as per WordPress.org requirements
* Shortened short description to meet 150 character limit

= 2.8.1 =
* Performance improvements for large stores and multisite installations
* Optimized debug logging to use buffered writes and limit log size
* Converted heavy options to non-autoloaded to reduce memory usage
* Replaced global cache flushes with targeted cache deletion for better performance
* Fixed slow queries caused by frequent option reads/writes
* Enhanced security by escaping debug output in admin area
* Improved overall plugin performance on stores with thousands of products

= 2.8.0 =
* **Major Enhancement**: Universal theme compatibility for brand images
* Added support for 30+ different brand image meta keys used by popular themes
* Automatic detection and support for Woodmart, Porto, Flatsome, XStore, Electro themes
* Support for Martfury, Shopkeeper, BeTheme, Avada, Salient, The7 themes
* Added ACF (Advanced Custom Fields) integration for brand images
* Smart detection handles image IDs, URLs, and serialized data
* Automatic conversion of image URLs to attachment IDs
* Fixed brand images not transferring with Woodmart and other themes
* Enhanced analysis tool to use comprehensive image detection

= 2.7.0 =
* Full compatibility with WordPress 6.8.2 and WooCommerce 10.0.4
* Fixed brand images transfer issue - now supports both 'thumbnail_id' and 'brand_image_id' meta keys
* Enhanced security with proper capability checks in all AJAX handlers
* Updated minimum PHP requirement to 7.4 for better performance and security
* Updated minimum WordPress requirement to 6.0
* Improved SQL queries with proper placeholder handling
* Fixed duplicate code in product batch processing
* Added support for WooCommerce's new brand taxonomy structure
* Stores brand images in both meta keys for maximum compatibility
* Enhanced error handling and debugging capabilities

= 2.6.3 =
* Updated textdomain loading to follow WordPress best practices
* Fixed remaining instances of legacy option names
* Improved plugin codebase to fully comply with WordPress.org guidelines

= 2.6.2 =
* Prefixed plugin options with `tbfw_` to avoid clashes
* Hardened SQL queries using placeholders
* Added migration routine for legacy options

= 2.6.1 =
* Added explicit compatibility with WooCommerce HPOS (High-Performance Order Storage)

= 2.6.0 =
* Code updates to comply with WordPress.org standards
* Fixed security issues with database queries
* Improved code organization and unique naming
* Updated text domain for better internationalization
* Added explicit WooCommerce dependency

= 2.5.0 =
* Enhanced deletion process for old brand attributes with improved tracking
* Better progress calculation during deletion operations
* Improved batch processing to ensure all products are properly processed
* Fixed issue where some products were skipped during delete operations
* Fixed inaccurate progress reporting in delete old brands feature

= 2.4.0 =
* Added automatic detection of WooCommerce brand permalink settings
* Removed manual destination taxonomy setting in favor of WooCommerce integration
* Improved handling of backup settings for delete operations
* Fixed issues with rollback button visibility
* Enhanced user interface and descriptions

= 2.3.0 =
* Added internationalization support
* Improved compatibility with WordPress.org standards
* Enhanced UI for better user experience
* Added more robust error handling
* Performance improvements for large product catalogs

= 2.2.0 =
* Added brand analysis tool
* Improved backup functionality
* Better handling of custom attributes
* Enhanced progress tracking

= 2.1.0 =
* Added option to delete old brand attributes
* Improved rollback feature
* Enhanced debug logging
* Better error reporting

= 2.0.0 =
* Added batch processing for large stores
* Implemented image transfer
* Added complete backup and restore system
* Added detailed reporting

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 3.0.7 =
**Critical bugfix and SEO preservation**: Fixes issue where only the first brand transfers from PWB. Empty/corrupted terms no longer halt the transfer. Brand slugs are now preserved during transfer to maintain existing URLs and search rankings. Strongly recommended for all users migrating from PWB.

= 3.0.6 =
**Critical security and reliability update**: Fixes XSS vulnerabilities in error displays, removes dead code, adds proper backup verification before transfers, and fixes smart brand detection timing. Strongly recommended for all users.

= 3.0.5 =
**Security and reliability update**: Fixes XSS vulnerability in JavaScript logs, adds race condition protection to prevent UI conflicts during concurrent clicks, and improves code robustness with proper type handling. Recommended for all users.

= 3.0.4 =
**Security and stability update**: Fixes XSS vulnerability in debug messages, adds missing capability checks, prevents fatal errors from invalid products, improves database error handling, and ensures PHP 8+ compatibility. Recommended for all users.

= 3.0.3 =
**Security and reliability fixes**: Adds race condition protection, taxonomy validation, proper error handling in rollback operations, and capability checks. Prevents data corruption from concurrent requests and data loss during failed rollbacks.

= 3.0.2 =
**Critical reliability fixes**: Resolves memory issues with large stores (1000+ brands), adds proper error handling to prevent silent transfer failures, and ensures rollback only clears backup data after successful rollback. Highly recommended for all users.

= 3.0.1 =
**Important fixes for brand plugin migrations**: Fixes confusing count display when using PWB/YITH, adds "Verify Transfer" button to diagnose empty brands issue, and adds automatic cache clearing after transfer completion.

= 3.0.0 =
Major UX update! Smart brand plugin detection, one-click source switching, improved accessibility, and critical fix for Delete Old Brands with brand plugins.

= 2.8.5 =
**New**: Now supports Perfect Brands for WooCommerce and YITH WooCommerce Brands! If you're using these popular brand plugins and want to migrate to WooCommerce's built-in Brands, this update makes it possible. Simply select your brand plugin's taxonomy from the dropdown and transfer.

= 2.8.4 =
**Important**: This update prevents a common issue where brands appear to transfer successfully but don't show in WooCommerce admin. The plugin now validates that WooCommerce Brands is properly enabled before allowing transfers, with clear instructions on how to enable it.

= 2.8.3 =
Important fix for users experiencing brands not appearing after transfer or 404 errors on brand pages. This update flushes rewrite rules automatically and improves WooCommerce 9.6+ compatibility.

= 2.8.2 =
Minor update to comply with WordPress.org plugin guidelines. No functional changes.

= 2.8.0 =
Full theme compatibility! Brand images now transfer correctly with Woodmart, Porto, Flatsome, and 30+ other themes. Fixes brand images not transferring.

= 2.7.0 =
Critical update for WooCommerce 10.0.4 compatibility. Fixes brand image transfer issues and adds full support for WordPress 6.8.2. This update is required for proper brand migration with the latest WooCommerce version.

= 2.6.3 =
This update optimizes the plugin for WooCommerce 9.6 compatibility and improves overall security and performance.

= 2.6.1 =
This update adds explicit compatibility with WooCommerce HPOS (High-Performance Order Storage).

= 2.6.0 =
This update improves code security and compatibility with WordPress.org standards. Recommended for all users.

= 2.5.0 =
This update significantly improves the "Delete Old Brands" functionality, ensuring all products are properly processed. Recommended for all users who need to clean up old brand attributes.

== Additional Information ==

= How to test before transferring =

Before running a full transfer, we recommend:

1. Using the "Analyze Brands" function to check your data
2. Ensuring you have a recent database backup
3. Testing on a staging site if possible
4. Verifying that the backup feature is enabled in settings

= Transfer Process =

The transfer process operates in three phases:

1. **Backup**: Creates a backup of existing brand data
2. **Terms Transfer**: Creates or maps taxonomy terms
3. **Product Assignment**: Associates products with the new taxonomy terms

= After Transfer =

After completing a transfer, you can:

* Keep both the old attribute and new taxonomy (default)
* Remove the old brand attribute data (optional)
* Further customize your brand taxonomy

= Support =

For support requests, please use the WordPress.org support forums.
