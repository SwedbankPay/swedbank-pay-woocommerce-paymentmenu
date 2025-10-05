# Changelog

All notable changes of krokedil/woocommerce are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
- CHANGELOG.md for tracking changes.
 
### Changed
- The "Capture Payment" button in the metabox will now set the order status to "Completed". Previously, it was set to "Processing".

------------------
## [4.1.1] — 2025-09-30
### Changed
- Changelog formatting updated. No functional or code changes.

## [4.1.0] — 2025-09-30
### Added
- Support for WooCommerce Subscriptions.

### Changed
- Order status is now determined directly by WooCommerce (previously, authorized orders were “on-hold”).
- Captured orders are now set to “Completed” instead of “Processing”.

## [4.0.1] — 2025-09-02
### Fixed
- Included missing update files in the release.

## [4.0.0] — 2025-09-01
### Added
- Support for the WooCommerce Action Scheduler.
- Setting to exclude order lines from the payment request (improves compatibility with gift card plugins).
- Enhanced tracing and logging of errors for easier troubleshooting.
- Entry in the system report for encountered errors and plugin settings.
- PHP scoping to prevent dependency conflicts with other plugins/themes.

### Fixed
- Various PHP warnings.
- Warning related to referencing the text domain before it was loaded.

## [3.6.6]
### Added
- `swedbank_pay_dispatch_queue_at_shutdown` WP filter.

## [3.6.5]
### Changed
- Improved verbose logging.
- Adjusted transaction handling.

## [3.6.4]
### Fixed
- Transaction callback now retrieves the order by `orderReference`.

## [3.6.3]
### Changed
- Updated behavior for the “Autocomplete” option.

## [3.6.2]
### Fixed
- “Autocomplete” option now sets “Completed” status for one-phase payments.

## [3.6.1]
### Fixed
- Additional fixes in the background queue processing.

## [3.6.0]
### Fixed
- Compatibility fixes for YITH WooCommerce Gift Cards v4.11.

## [3.5.0]
### Added
- “Autocomplete” option.

### Removed
- “Subsite” option.

## [3.4.0]
### Changed
- Use server configuration for cURL CA certificates.
- Updated the SDK library.
- Added geolocation detection for “International Telephone Input”.

## [3.3.2]
### Added
- Tax calculation for YITH WooCommerce Gift Cards.

## [3.3.1]
### Fixed
- Compatibility issue with HPOS activation.

## [3.3.0]
### Added
- Support for YITH WooCommerce Gift Cards.

### Changed
- Improved error message clarity.

## [3.2.0]
### Added
- Refund modes: by amount or by order items.

## [3.1.1]
### Fixed
- Prevent online refund when manual refund is initiated.

## [3.1.0]
### Changed
- Removed “Full refund” button.
- Improved refund behavior via the “Refund” button.
- Improved handling of refunds when changing order status to “Refunded”.

## [3.0.0]
### Added
- Interactive payment status checker on the “Thank You” page.
- “International Telephone Input” extension (disabled by default).
- Special validation for the `StreetAddress` field.

### Fixed
- Prevented rollback of order status when errors occur.
- Fixed issue where order status changed from “Pending” to “Cancelled”.
- Prevented duplicate WooCommerce refund creation.



## [2.0.2]
### Fixed
- Order status incorrectly changing from “Processing” to “Completed”.

## [2.0.1]
### Fixed
- Issues with the “Full Refund” button.

## [2.0.0]
### Changed
- Code refactoring and internal restructuring.
- Core library moved into the plugin.

### Added
- Support for WooCommerce Blocks.

### Fixed
- Various payment action bugs.

## [1.3.0]
### Fixed
- “Full refund” issues when “Display prices in the shop” is set to “Including tax”.

## [1.2.0]
### Changed
- Improved callback handling.

### Fixed
- Issues with capture and refund operations.

## [1.0.1]
### Added
- “Title” option.

### Fixed
- Partial refund and capture actions in the admin backend.

## [1.0.0]
### Added
- Initial public release.
