/# Leat_Loyalty module

This module allows for the integration of the Piggy.eu loyalty software into Magento.
It does this by:
- Selectively synchronizing data between Magento and Leat in a way no data gets lost or inserted before it is time to do so.
- Retrieving Leat Member level from Leat and saving it in Magento.
- Managing customer loyalty credits and prepaid balance.
- Providing real-time validation for required attributes and configurations.

## Asynchronous Integration

Asynchronous communication with Piggy.eu is handled by the Leat_LoyaltyAsync module. Please refer to that module's README for details on:
- Async queue implementation
- Queue types and builders
- API interaction architecture
- Retry mechanisms and error handling

## Data Synchronization

### Attribute Synchronization
The module provides a system to ensure that all required attributes for transactions and rewards are properly defined in Leat. This is critical for the proper functioning of the integration as attributes form the foundation of data stored in Leat.

#### Required Attributes
- Transaction attributes: Predefined set of attributes required for order transactions
- Reward attributes: Attributes used for reward tracking and management

#### Synchronization Process
- The synchronization is initiated from the Magento admin panel
- The system checks existing attributes in Leat and creates any missing ones
- Validation occurs in real-time to ensure all required attributes exist
- Attributes are created with appropriate data types and settings to match Magento's data structure

#### AttributeResource
The `AttributeResource` class is responsible for:
- Validating that required attributes exist in Leat
- Creating missing attributes during synchronization
- Providing detailed validation results to the admin UI
- Supporting multi-store configurations

### Customer Sync
The customer sync creates so called 'contacts' in Leat for any account made in the shops configured in the admin.
This process will create a 'contact' for the customer based on their email-address. To allow customers to change their
email if necessary, the contact UUID is saved within their customer account. This is to prevent a mismatch between
the email address used within Magento and Leat as the email field within Leat is immutable.  

### Data
The customer sync currently ensures the following data is synced with Leat upon change:
- Email
- Firstname
- Lastname
- Address information
- TODO: DOB

### When
A job will be inserted into the queue whenever the customer changes their account information, address or when a new
account is created.  
It does this based on the following events:
- customer_register_success - New account creation !! **When enabled in configuration** !!
- customer_account_edited - Account details edited
- customer_address_save_commit_after - Address details edited


## Order Export

The module supports two order export modes configured under **Stores > Configuration > Leat Loyalty > Order**:

- **Disabled** – No order export.
- **Enabled | Order API** – Exports orders via the Leat Order API (full order payload including line items, charges,
  discounts, and payments). Handled by `LoyaltyAsync\Cron\Order\OrderExport` using `OrderApiBuilder`.
- **Enabled | Legacy Transaction-based** – Exports individual order item transactions using the older per-item
  transaction system.

### Order API Export Payload Options

When using Order API mode, the following can be included in the payload via admin config:

- **Include Tax as Charge** – Adds order tax as a separate charge entry.
- **Include Shipping as Charge** – Adds shipping cost as a separate charge entry.
- **Shipping Tax Separate** – Reports shipping tax independently rather than bundled into the shipping charge.

### Discount Breakdown Observers

Three observers work together to capture a per-rule, per-tax-rate discount breakdown on the quote and propagate
it to the order. This data is used by the Order API export to include accurate discount entries:

- **ProcessDiscount** (`Observer/Discount/ProcessDiscount`) – Fires on `salesrule_validator_process`. Intercepts
  each rule application and accumulates discount amounts keyed by tax rate into `discount_amount_array`,
  `base_discount_amount_array`, and `discount_description_array` on the quote.
- **ResetDiscounts** (`Observer/Discount/ResetDiscounts`) – Fires when the quote is recalculated. Clears previous
  discount arrays so stale data is never carried forward.
- **AddDataToOrder** (`Observer/Discount/AddDataToOrder`) – Fires on order placement. Copies the accumulated
  discount arrays from the quote to the order for later pickup by the export cron.

### Customer condition
It might happen a customer from one of the configured stores has remained dormant for a long time. If this is the case,
they might not have a Leat contact assigned to them. In this case, a create request will be sent for this customer
before the order is exported.

## Widget:
It is added by using the following block on the desired page/block:

`{{block class="Leat\Loyalty\Block\LoyaltyWidget" widget_id="<ID>" cacheable="false"}}`  

Where 'widget_id' should be adjusted to the id of the desired widget design. 

**Important:**
The '1 column (Disabled FPC)' page type must be used.

A block is used, instead of the given script url, to allow for more configurability.  
In this case, the widget will only show if the customer is on the correct website and has a contact_uuid assigned.  
A fetch request to a controller is used to get an authentication token for the customer contact. This will skip
the customer having to log in themselves.

The optimal position and status of the widget icon is as follows:
* Position: Right
* Open widget by default: Closed
* Offset horizontal: 48
* Offset Vertical: 100

This puts it centred and above the Robin button.

It is also vitally important that the 'login' and 'register' pages of the widget are disabled. As this logic will be
handled by Magento.

The widget opens automatically as soon as the user is logged in to the widget by the Javascript, which is busy in the background.

## Prepaid Balance

The Leat module supports applying prepaid loyalty balance to customer orders during checkout. This feature allows 
customers to use their accumulated loyalty credits as a form of payment.

### How It Works
1. The system retrieves the customer's available prepaid balance
2. During checkout, customers can choose how much of their balance to apply
3. The applied balance is validated to ensure it doesn't exceed available balance or order total
4. The balance amount is applied as a discount to the order
5. Applied balance amount is stored with the order and quote for reference

### Key Components
- **ApplyBalanceInterface** - Service contract for applying balance to a quote
- **BalanceValidatorInterface** - Validates balance amount before application
- **OrderLeatBalanceRepositoryInterface** - Manages order balance data persistence
- **Balance Total Collector** - Applies the balance as a discount in the quote totals calculation
- **Order Observer** - Transfers balance information from quote to order during order placement

### Configuration
The prepaid balance feature can be configured in the Magento admin under:
Stores > Configuration > Leat Loyalty > Leat Loyalty > Prepaid Balance

Available settings include:
- Enable/disable the feature
- Maximum percentage of order total that can be covered by prepaid balance

## Coupon/Rewards Redemption

The module allows customers to apply rewards as coupons to their shopping cart. This feature integrates the Leat loyalty rewards with Magento's native coupon functionality.

### Key Components

#### AppliedCouponsManager
- Manages the application of Leat reward coupons to quotes
- Enables customers to apply reward coupons from their account to the shopping cart
- Supports multi-coupon functionality with reward-type organization
- Handles proper storage and retrieval of applied coupons in the quote
- Manages coupon redemption lifecycle including collection at checkout

#### LoyaltyTransactionRelatedGiftRemover
- Handles proper removal of gift products when a coupon is removed
- Ensures proper cleanup of related gift items in the cart
- Maintains proper rule associations in the quote object

### Technical Implementation
- Uses JSON serialization for storing coupon data in the quote
- Integrates with Magento's native sales rule and coupon systems
- Dispatches events for other modules to hook into the coupon workflow
- Provides proper error handling and logging

### Multiple Leat vouchers

A customer can stack several Leat vouchers on one order. Whether a voucher stacks depends on the cart price rule
mapped to its reward:

- **Combinable with other Leat vouchers** (toggle under *Actions* on the cart price rule, default *No*). Any number
  of combinable vouchers can sit on a quote, plus at most **one** non-combinable voucher.
- **Priority** comes from the rule's *Priority* (`sort_order`) field. When a second non-combinable voucher is applied,
  the rule with the lowest `sort_order` wins; the other voucher is removed from the cart with the message
  *"Combining this voucher with others is not allowed. Voucher %1 has been prioritized."*. On equal priority the
  voucher that was already applied stays.
- The same reward can be applied once per cart (*"This reward is already applied to your cart."*).
- A reward without a mapped cart price rule cannot be applied, and a physical reward without one cannot be redeemed
  (checked before any points are spent).
- **A voucher that gives nothing never stays on the cart.** After a voucher is applied the totals are collected and
  the rule must have done something: a discount, a gift item or free shipping (`AppliedCouponsManager::hasEffect()`).
  If not, the cart is restored to its previous vouchers and the customer gets a message, either *"This voucher has no
  effect on your order: it cannot be combined with your other voucher or your order does not meet its conditions."* or,
  when it was the only voucher, *"...your order does not meet its conditions."*. Typical causes are *Discard subsequent
  rules* on a higher priority rule, unmet rule conditions or an inactive rule. The `leat_coupon_applied` and
  `leat_coupon_removed` events are dispatched only once the voucher is confirmed, so a rollback fires none of them.
- **Safeguard at order placement:** a voucher whose rule had no effect on the order is not marked as used in Leat
  (`markCouponsAsCollected()`), so the customer keeps the reward and points. The order gets a status history comment
  and the voucher is left out of the order's `leat_loyalty_applied_coupons`.
- **Set "Discard subsequent rules" to No on every combinable rule.** Magento stops evaluating lower priority rules
  after a rule with *Discard subsequent rules = Yes*, so a second voucher would give no discount. Such a voucher is
  refused with the message above. This is Magento behaviour and cannot be fixed in the module.

`ResetDiscountArraysPlugin` clears the per rule discount arrays on the quote (`discount_amount_array` etc.) before every
real totals collect. Without it `ProcessDiscount` would keep adding to amounts of earlier collects and the check above
could not tell whether a voucher gave a discount.

The name customers see (totals row, *prioritized* message) is the rule's **Label** (Labels tab, store specific before
the default). Without a label the totals row reads *Leat voucher* and the message falls back to the rule name. Inactive
rules never block other vouchers.

Cart and checkout show one totals row per applied voucher, built from
`totals.extension_attributes.leat_voucher_breakdown` (`CartTotalRepositoryPlugin`). Totals refresh right after a
voucher is applied or removed.

**Apply on redeem** (`Stores > Configuration > Leat Loyalty > Configuration > Rewards > Apply reward to cart on
redeem`, default *Yes*) applies a reward to the cart straight after the customer redeems it, so the customer doesn't
have to click *Apply Voucher* once the voucher shows up. If the apply fails, the redeem still succeeds and the apply
error is appended to the message. A voucher pushed out by the new one (non-combinable priority) is named in the message.
A redeem without enough points is stopped in the browser before calling Leat, with a clear message.
Before anything is redeemed, a physical reward must have a cart price rule linked to it (reward UUID on the rule).
If not, the redeem is refused with *"This reward cannot be redeemed at the moment, no points have been spent."*, so
points are never spent on a voucher that could not give anything. Digital rewards need no rule.

**Max vouchers of this reward** (`leat_max_applied`, cart price rule, default 1): how many vouchers of one reward can
sit on a cart. A newer voucher of the reward replaces the oldest beyond the maximum. `AppliedVoucherCounter` tells the
rule actions how many vouchers of the reward are applied, because a rule is only evaluated once:
- *Fixed amount* rules (`cart_fixed`, `by_fixed`) are scaled by that number (`ScaleFixedDiscountPlugin`), so three
  vouchers give three discounts. Percentage rules are left alone: three 10% vouchers do not become 30%.
- *Gift* rules add the gift once per voucher (see below).

**Voucher gift lines:** every applied voucher owns its gift line(s), keyed by the voucher's loyalty transaction UUID in
the buy request (`GiftProductManager::BUYREQUEST_VOUCHER_UUID`). Two vouchers never merge into one line (see
`PreventGiftcardMergePlugin` and `CompareOptionsPlugin`) and removing a voucher removes exactly its gift. Lines of a
voucher that is gone, and lines without voucher UUID, are removed and re-added when still due. Gift lines cannot be
edited and their quantity is fixed (`ProtectGiftItemsPlugin`). Removing a gift line unapplies its voucher, and emptying
the cart unapplies all vouchers (cart page, minicart, REST and GraphQL).

**Checks at order placement:**
- `CheckVouchersBeforeQuoteSubmit` checks every applied voucher against the customer's collectable vouchers again,
  because a voucher is only marked as used after the order is placed. A voucher used in the meantime (another cart or
  session) stops the order, and so does a failed check (fail closed).
- If a voucher cannot be marked as used after the order is placed, or had no effect, the order gets a status history
  comment instead.
- The prepaid balance amount is validated against the customer's balance when applied and again at order placement;
  an invalid amount stops the order.

## Gift Product Cart Price Rule

The module adds a new cart price rule action type that allows automatically adding gift products to the cart when a promotion rule is applied.

### Key Components

#### GiftProductManager
- Core service for managing gift products in the cart
- Adds products based on SKUs specified in the rule configuration
- Supports both simple and configurable products
- Handles proper gift marking and pricing
- Enforces gift quantity limits based on the rule's discount_qty setting
- Applies percentage discounts based on rule's discount_amount instead of making items free

#### ConfigurableProductResolver
- Resolves configurable product options when a simple product SKU is provided
- Automatically determines the parent configurable product
- Finds the appropriate super attributes to select the desired variant

#### Action/AddGiftProducts
- Implements the cart price rule action for adding gift products
- Interfaces with Magento's sales rule calculation system
- Ensures gifts are only added once per rule application
- Respects discount_qty for limiting the number of gift items
- Applies discount_amount for percentage-based discounts

#### Extension Attributes for Tracking Gift Products
- Extends CartItemInterface with extension attributes:
  - `is_gift`: Flags an item as a gift product
  - `gift_rule_id`: Links the gift item to its originating sales rule
  - `original_product_sku`: Stores the original product SKU for tracking

#### Observer/RemoveInvalidGiftProductsFromQuote
- Removes gift products when their associated rules no longer apply
- Keeps the cart in sync with eligible promotions
- Maintains consistency when cart contents change

#### EnforceGiftItemQuantitiesPlugin
- Ensures gift item quantities don't exceed the rule's discount_qty setting
- Prevents manual quantity adjustments from bypassing rule limits
- Automatically adjusts quantities when total exceeds the rule limit
- Preserves the oldest gift items when adjustments are necessary

#### LoyaltyTransactionRelatedGiftRemover
- Manages removal of gift products when associated rules are invalidated
- Handles proper cleanup of related items in the cart
- Maintains correct rule associations in the quote object

### Technical Implementation
- Fully integrates with Magento's sales rule system
- Supports comma-separated list of SKUs in rule configuration
- Uses extension attributes for persistent gift item tracking
- Handles both simple products and configurable product variants
- Applies percentage-based discounts according to rule settings
- Enforces quantity limits per rule using discount_qty
- Includes detailed logging for troubleshooting
- Prevents duplicate gift additions on cart updates

## Earn Points

Points that aren't earned through an order (follow on Instagram, add a birthday, ...). Configured under
**Stores > Configuration > Leat Loyalty > Configuration > Earn Points** (store view scope) as full-width dynamic rows:
icon, title, button text, button link, points, repeat and key.

- **Honor system:** customers claim the points by pressing the button; nothing verifies the action. Don't configure an
  earning you don't want to hand out on trust.
- **0 points:** the card is a plain link. No claim request, no "Completed" state, no points line.
- **Repeat:** `once`, `yearly`, `monthly`, `weekly` or `daily`. Resets on calendar boundaries in the store timezone
  (yearly resets on 1 January, weekly on the ISO week). `Model\EarnPoints\Repeat::periodKey()` turns it into the
  period of a claim (`once`, `2026`, `2026-11`, `2026-W47`, `2026-11-18`).
- **Claim table:** `leat_loyalty_earning_claim` has a unique key on `(customer_id, earning_id, period_key)`. Inserting
  the row (`ResourceModel\EarnClaim::claim()`) is the lock: a double-click or a second tab loses the race and gets
  "already claimed". The row is committed before the credit is queued, and it stays when queueing fails (the failure is
  logged to the `earn_points` log).
- **Credit right away:** after the claim the controller runs the queued job immediately (`JobDigest::setJob()`), so the
  balance and activity log are current when the XHR returns (the `leat/earnpoints/claim` action invalidates the `leat`
  customer-data section). The queue is only the fallback: when Leat fails, or an earlier job of the customer is still
  pending, the job stays queued and the cron credits it later; the customer already sees "Completed".
- **Key:** the key of a row is what a claim is locked on (`earning_id` in the claim table). It is filled with the row id
  on the first save when left empty, and stays editable: letters, numbers, `_` and `-`, max 64, unique within the table.
  Change the title, button text, link or points freely and customers who already claimed stay locked. Changing a key
  (or the repeat) makes the earning claimable again. Rows saved before keys existed use their row id as key. A store
  view that overrides the whole table can reuse the same keys to share the lock; without that it starts with its own.
- **Activity log:** every credit is sent to Leat with a `transaction_note` attribute holding the card's title at the time
  of the claim (plus the `transaction_hash`). The activity log shows that note as the line text; credits without a note
  keep the generic "Credit Reception". The note is also visible on the transaction in Leat. Renaming a card later
  doesn't change earlier lines.
- **Icons:** see [Earn points icons](#earn-points-icons).

### Earn points icons

An icon is an SVG file in `Leat_LoyaltyFrontend/view/frontend/web/images/earn-points/<name>.svg`. The widget inlines the
file (so `currentColor` follows the card's text colour) and finds it through the normal theme fallback:

1. `<theme>/Leat_LoyaltyFrontend/web/images/earn-points/<name>.svg` (and parent themes)
2. `Leat_LoyaltyFrontend/view/frontend/web/images/earn-points/<name>.svg`

The names in the admin dropdown come from `Leat\Loyalty\Model\EarnPoints\Icons`: the built-in icons plus whatever is
registered through `di.xml`. Built-in: `cart`, `birthday`, `gift`, `heart`, `review`, `share`, `newsletter`, `generic`
(general) and `instagram`, `facebook`, `x`, `tiktok`, `youtube`, `pinterest`, `linkedin`, `whatsapp`, `snapchat`,
`twitch`, `telegram`, `spotify` (social media). A row that points to a
name that is no longer registered shows the `generic` icon.

**Admin:** an admin can only pick from the list; the files belong to the theme, so changing or adding an icon is a
developer task. The "Earnings" field in the admin explains the steps, so it can be handed over as is.

**Developer: change an existing icon.** Put an SVG with the same name in the theme (path above), deploy static content
and flush the cache. Nothing else is needed, `<name>` is the icon name.

**Developer: add a new icon.** No change to the Leat modules is needed, so it keeps working when they are installed
through vendor:

1. Add `pinterest.svg` to your theme: `<theme>/Leat_LoyaltyFrontend/web/images/earn-points/pinterest.svg`. The file
   has to live in a theme (or the Leat_LoyaltyFrontend module itself): icons are looked up as
   `Leat_LoyaltyFrontend::images/earn-points/<name>.svg`, so another module's `web/` folder is not searched. The name is
   lowercase letters, digits and dashes (`pinterest`, `gift-box`); anything else is ignored because the name ends up in
   a file path.
2. Register the name in the `etc/di.xml` of a custom module (a theme can't carry a `di.xml`, any module of your
   project can, for example the one that holds your other customisations):

   ```xml
   <type name="Leat\Loyalty\Model\EarnPoints\Icons">
       <arguments>
           <argument name="names" xsi:type="array">
               <item name="pinterest" xsi:type="string">pinterest</item>
           </argument>
       </arguments>
   </type>
   ```

   The item key only needs to be unique (it lets another module remove or replace the entry); the value is the icon name.
3. Flush the cache, run `bin/magento setup:di:compile` in production mode and deploy static content. The icon appears in
   the Icon dropdown of the Earn Points rows (label: the name with the first letter capitalised and dashes as spaces).

**SVG requirements:** `viewBox="0 0 24 24"`; `stroke="currentColor"` or `fill="currentColor"` instead of fixed colours
(the icon sits white on a dark tile); no scripts. The widget sets the size (`.leat-earn-points-icon svg`), so
`width`/`height` attributes don't matter. The file is output unescaped, so only use files from a trusted source.

**Troubleshooting:** icon missing in the dropdown: `di.xml` not picked up (flush cache / recompile) or an invalid name.
Dropdown shows it but the card shows the generic icon: the SVG isn't found at the expected path, check the file name
(exact, lowercase) and the theme fallback; static content may need redeploying in production mode.
