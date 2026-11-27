# Leat LoyaltyAdminUI Module

## Overview
The LeatAdminUI module provides admin interfaces and backend functionality for the Leat loyalty and rewards system. It extends Magento's admin area with Leat-specific features and configurations.

## Features

### Data Synchronization System

#### Sync Data Interface
- Provides a unified system configuration interface for data synchronization with Leat
- Uses a modular, extensible design for adding additional sync operations beyond attributes
- Real-time validation to ensure required data structures exist at Leat
- Direct API validation instead of flag-based status tracking for data integrity

#### Validation Framework
- Built-in validation system to check if required attributes exist in Leat
- Status is displayed directly in the admin UI with appropriate styling
- Supports multi-store configuration with store-specific validation
- Extensible architecture for adding new validation rules

#### Technical Details
- Direct connection testing without dependency on cached flags
- Live attribute validation through AttributeResource
- Clean separation of concerns between Sync UI and validation logic
- Consistent status reporting with color-coded messages

### Connection Testing System
- Provides real-time connection status checking for Leat API
- Direct API validation without relying on cached flag data
- Clear status reporting with appropriate styling based on connection state
- Store-specific configuration support

### Config Source: OrderExport
`Model/Config/Source/OrderExport` provides the order export mode dropdown:

| Value | Label | Constant |
|-------|-------|----------|
| `0` | Disabled | `DISABLED` |
| `2` | Enabled \| Order API | `ENABLED` |
| `1` | Enabled \| Legacy Transaction-based | `ENABLED_LEGACY` |

Used by `Leat\Loyalty\Model\Config::getIsOrderExportEnabled()` and `getIsLegacyOrderExportEnabled()`.

### Data Export Status Blocks

Sync status widgets rendered in the admin system config panel via `AbstractStatus`:

- **Ping** (`Status/Ping`) - Live API connection check
- **Attribute** (`Status/Attribute`) - Validates required Leat attributes exist
- **Category** (`Status/Category`) - Shows last category export run time and result, sourced from the
  `leat_category_export_last_run_{shopUUID}` flag written by `LoyaltyAsync\Cron\Data\CategoryExport`
- **Product** (`Status/Product`) - Shows last product export run time and result, sourced from the
  `leat_product_export_last_run_{shopUUID}` flag written by `LoyaltyAsync\Cron\Data\ProductExport`

All status blocks display a neutral "Awaiting first sync..." message before the first cron run, a "Sync in progress..."
notice during active execution, and either a timestamped success or error message afterwards.

### Async Queue Views

Two places where the queue of `Leat_AsyncQueue` shows up outside its own menu:

- **Queue Errors banner** (`Status/QueueErrors`) is the first group of the Leat configuration (sort order 0). The group
  has no label and no fields, its frontend model renders the whole banner, so it is always visible. Red when a request
  is out of retries, yellow while requests are failing, green when none are. The table lists failing requests of open
  jobs, most recent first, with the attempts, the latest failure and the date of the latest failure; each row links to its
  job (when the admin may view the queue). On a website or store scope only the jobs of that scope are listed. The look
  follows the Amasty license banner.
- **Leat Queue Jobs tab** on the customer (`customer_form.xml`, `Ui/Component/Form/JobsFieldset`) embeds the async queue
  job grid filtered on the customer id. It is left out for admins without `Leat_AsyncQueue::jobs` and hidden for a
  customer that is not saved yet.

### Coupon Usage Plugin

#### UsagePlugin for SalesRule Coupon
- Intercepts Magento's standard coupon usage tracking
- Allows Leat reward coupons to use a custom tracking mechanism
- Properly marks Leat coupons as collected when used in orders
- Integrates with the AppliedCouponsManager from the core Leat module

#### Technical Details
- Uses the `aroundUpdateCustomerRulesUsages` plugin method to intercept Magento's core coupon usage tracking
- Identifies Leat coupons by checking if they exist in the applied coupons data
- Provides proper error handling and logging
- Maintains compatibility with Magento's standard coupon system for non-Leat coupons

### Gift Product Cart Price Rule UI

#### Custom Action Type
- Adds "Add gift products to cart" to the available cart price rule actions
- Provides a field for specifying comma-separated product SKUs
- Handles visibility of fields based on selected action type
- Supports both simple products and configurable product variants
- Integrates with standard discount_qty field to limit gift quantities
- Uses discount_amount field for percentage-based gift discounts (0-100%)

#### Extension Attribute Implementation
- Implements extension attributes for SalesRule entity
- Stores gift_skus in a dedicated database table (leat_loyalty_salesrule_extension)
- Persists and loads the gift SKUs field across page loads and rule edits
- Uses model-resource-repository pattern for clean data access
- Provides reliable persistence even with complex form submissions

#### Rule Form Integration
- Properly loads extension attribute data into the admin form
- Maintains data integrity through the entire edit-save lifecycle
- Works seamlessly with Magento's validation system
- Handles both new rule creation and existing rule editing

#### Voucher Combination Fields
- Adds "Combinable with other Leat vouchers" (`leat_combinable`) and "Max vouchers of this reward on one cart"
  (`leat_max_applied`) to the cart price rule form, stored in the same extension table
- See the Leat_Loyalty README for how vouchers are combined
- The "Reward" condition dropdown labels each reward with the scope(s) it is configured in (Global, Website, Store View)

#### Extension-Friendly Implementation
- Uses non-invasive JavaScript to enhance the sales rule form
- Compatible with other extensions that customize the same form
- Follows Magento best practices for UI customizations
- Properly handles all field visibility states
- Respects core Magento fields (discount_qty, discount_amount) for consistent behavior

#### Technical Details
- Uses jQuery event listeners to detect form field changes
- Dynamically shows/hides the gift SKUs field based on selected action
- Supports both initial page load and subsequent form changes
- Implements a clean, maintainable approach that doesn't override core components
- Persists extension attributes with plugins for rule repository

### Leat Coupon Type
- Adds a "Leat Coupon" type to the standard Magento coupon types
- Shows/hides appropriate fields based on the selected coupon type
- Integrates with the core Leat reward system

## Architecture
The module follows a clean architecture that extends Magento's admin functionality without overriding core components:

- **Plugins** to extend existing functionality
- **UI Components** to add new form fields
- **JavaScript** for dynamic behavior
- **Templates** for custom rendering
- **XML Layouts** for structural changes
- **Service Layer** with validation classes for business logic encapsulation

All implementations are designed to work alongside other extensions and maintain compatibility with future Magento updates.
