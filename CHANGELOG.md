# Changelog

All notable changes to Transfer Brands for WooCommerce will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2025-12-10

### Added
- **Smart Detection Banner**: Automatically detects installed brand plugins (Perfect Brands, YITH) and shows contextual guidance
- **One-Click Source Switching**: Switch between brand taxonomies without visiting settings
- **Smart Default Selection**: On activation, automatically selects the best source taxonomy based on detected plugins
- **Button Loading States**: All action buttons now show spinners to prevent double-clicks
- **Keyboard Accessibility**: Modals can be closed with Escape key, includes focus trap
- **ARIA Labels**: Added proper accessibility labels for screen readers
- **Review Request Notice**: Non-intrusive review prompt shown after successful transfer
- **New FAQs**: Added competitor-focused FAQs for Perfect Brands and YITH migration

### Fixed
- **CRITICAL**: Delete Old Brands now works correctly for brand plugin taxonomies (pwb-brand, yith_product_brand)
- **Backup System**: Fixed wrong option name and added missing backup_enabled checks in 3 methods
- **Debug Log Clear**: Created missing `clear-debug-log.js` file for clearing debug logs

### Improved
- **Debug Mode**: Only logs during user-initiated operations, not on page load
- **Batch Size**: Default reduced from 20 to 10, maximum from 100 to 50 for better shared hosting support
- **i18n Compliance**: Added proper translators comments for all placeholder strings
- **SEO Optimization**: Updated short description and tags for better WordPress.org discoverability

### Technical
- Added `backup_brand_plugin_terms()` method for brand plugin backups
- Updated `rollback_deleted_brands()` to handle `is_brand_plugin` flag
- Added `ajax_switch_source()` AJAX handler
- Added `ajax_dismiss_review_notice()` AJAX handler
- Added `maybe_show_review_notice()` admin notice method
- New CSS: Smart banner styles, review notice styles, button loading states

## [2.8.1] - 2025-08-09

### Changed
- Buffered debug logging writes to a single shutdown update per request
- Limited `tbfw_brands_debug_log` to last 1000 entries to prevent unbounded growth
- Ensured heavy options (`tbfw_backup`, `tbfw_term_mappings`, `tbfw_deleted_brands_backup`, `tbfw_brands_processed_ids`, `tbfw_backup_cleanup_log`, `tbfw_brands_debug_log`) are non-autoloaded
- Removed global `wp_cache_flush()` usage from admin UI and counts refresh; replaced with targeted cache deletion
- Escaped debug output in Debug page to avoid XSS in admin

### Fixed
- Reduced slow queries on multisite and large stores caused by frequent option reads/writes

## [2.8.0] - 2025-08-08

### Added
- **Major Enhancement**: Comprehensive theme compatibility for brand images
- Support for 30+ different meta keys used by popular themes and plugins
- Automatic detection of Woodmart, Porto, Flatsome, XStore, Electro, and 10+ other themes
- Smart image detection that handles IDs, URLs, and serialized data
- ACF (Advanced Custom Fields) integration for brand images
- Automatic conversion of image URLs to attachment IDs

### Improved
- Brand image transfer now works with:
  - **Woodmart theme** (image, _woodmart_image, woodmart_image fields)
  - **Porto theme** (brand_thumb_id, porto_brand_image fields)
  - **Flatsome theme** (brand_image, flatsome_brand_image fields)
  - **XStore theme** (xstore_brand_image, brand_logo fields)
  - **Electro theme** (electro_brand_image field)
  - **Martfury theme** (martfury_brand_image field)
  - **Shopkeeper theme** (shopkeeper_brand_image field)
  - **BeTheme** (betheme_brand_image field)
  - **Avada theme** (avada_brand_image field)
  - **Salient theme** (salient_brand_image field)
  - **The7 theme** (dt_brand_image field)
  - **Perfect Brands for WooCommerce** plugin
  - **YITH WooCommerce Brands** plugin
  - And many more themes and plugins

### Fixed
- Brand images not transferring when using non-standard meta keys
- Images stored as URLs not being properly converted
- Serialized/array image data not being detected
- Woodmart and other themes' brand images now properly detected and transferred

### Technical
- Added `find_brand_image()` method with extensive meta key checking
- Added `set_brand_image_for_all_themes()` for maximum compatibility
- Added `get_theme_specific_image_keys()` for theme detection
- Added `get_attachment_id_from_url()` for URL to ID conversion
- Updated image detection in analysis tool to use new comprehensive method

## [2.7.0] - 2025-08-07

### Added
- Full compatibility with WordPress 6.8.2
- Full compatibility with WooCommerce 10.0.4
- Support for both 'thumbnail_id' and 'brand_image_id' meta keys for brand images
- Enhanced security with capability checks in all AJAX handlers
- Stores brand images in both meta keys for maximum compatibility

### Changed
- Updated minimum PHP requirement to 7.4
- Updated minimum WordPress requirement to 6.0
- Updated minimum WooCommerce requirement to 8.0.0
- Improved SQL queries with proper placeholder handling
- Enhanced error handling and debugging capabilities

### Fixed
- Fixed critical brand images transfer issue preventing images from migrating properly
- Fixed duplicate code in product batch processing
- Fixed SQL query security issues with proper prepared statements
- Added missing permission checks in AJAX handlers

## [2.6.3] - 2025-05-16

### Changed
- Updated textdomain loading to follow WordPress.org best practices
- Moved load_plugin_textdomain to the 'init' hook for better compatibility
- Renamed remaining legacy option names for full WordPress.org compliance

### Fixed
- Replaced all instances of 'wc_deleted_brands_backup' with 'tbfw_deleted_brands_backup'
- Fixed version numbering in plugin constants

## [2.6.2] - 2025-05-16

### Changed
- Prefixed all plugin options with `tbfw_` to avoid namespace conflicts
- Further hardened SQL queries using placeholders for better security

### Added
- Migration routine for legacy options to new format

## [2.6.1] - 2025-05-08

### Added
- Explicit compatibility with WooCommerce HPOS (High-Performance Order Storage)

## [2.6.0] - 2025-05-08

### Added
- Explicit WooCommerce dependency in plugin header
- Additional contributor in the readme.txt file
- New JavaScript file for taxonomy refresh functionality

### Changed
- Renamed all class prefixes from "WC_" to "TBFW_" for better compatibility
- Updated text domain from "wc-transfer-brands" to "transfer-brands-for-woocommerce"
- Improved SQL queries with proper placeholders for better security
- Moved inline JavaScript to properly enqueued script files
- Updated option names with new prefix
- Enhanced code organization and standard compliance

### Fixed
- Security issues with database queries by using proper wpdb prepare statements
- SQL injection vulnerabilities in exclusion conditions
- Potential conflicts with other plugins by using unique function and class names

## [2.5.0] - 2025-04-30

### Added
- Enhanced deletion process for old brand attributes with improved tracking
- Better progress calculation during deletion operations

### Changed
- Improved batch processing to ensure all products are properly processed
- Enhanced UI feedback during delete operations
- Updated internal documentation and code comments

### Fixed
- Fixed issue where some products were skipped during delete operations
- Fixed inaccurate progress reporting in delete old brands feature
- Fixed inconsistent progress bar behavior when processing large stores

## [2.4.0] - 2025-04-29

### Added
- Automatic integration with WooCommerce brand permalink settings
- "Refresh Destination Taxonomy" button to update taxonomy settings without reloading
- Better information display in the settings about the destination taxonomy

### Changed
- Removed manual destination taxonomy setting field in favor of WooCommerce integration
- Improved handling of backup settings for delete operations
- Enhanced UI with better descriptions and tooltips
- More accurate information display on the transfer page

### Fixed
- Fixed issue where rollback button appeared when no backup was available
- Fixed problem with transfer not working when backup was disabled
- Fixed issue where Clean Up Backups button appeared even when no valid backups existed
- Fixed incorrect destination taxonomy info in the brand analysis results

## [2.3.0] - 2025-04-29

### Added
- Full internationalization (i18n) support
- Translation template (.pot) file
- Support for WordPress coding standards
- Improved error handling and debugging
- English text for all frontend elements
- Comprehensive documentation
- Proper file headers and function documentation
- Code organization into separate class files
- Ready for WordPress.org submission

### Changed
- Updated option defaults
- Improved user interface text
- Enhanced security with proper data sanitization
- Better JavaScript organization with i18n support

### Fixed
- Various PHP notices and warnings
- Potential security issues
- Improved escaping of output values

## [2.2.0] - 2025-03-15

### Added
- Brand analysis tool
- Improved backup functionality
- Better handling of custom attributes
- Enhanced progress tracking
- Fixed bug affecting large product catalogs

## [2.1.0] - 2025-02-10

### Added
- Option to delete old brand attributes
- Improved rollback feature
- Enhanced debug logging
- Better error reporting
- Support for larger sites with improved batching

## [2.0.0] - 2025-01-05

### Added
- Batch processing for large stores
- Implemented image transfer
- Added complete backup and restore system
- Added detailed reporting
- Progress visualization
- Support for WordPress 6.2+

## [1.0.0] - 2024-12-15

### Added
- Initial release
- Basic transfer functionality
- Support for taxonomy attributes
- Simple admin interface