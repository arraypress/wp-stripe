# WordPress Stripe

A comprehensive WordPress library for working with the Stripe API. Provides a clean, WordPress-aware abstraction layer
over the Stripe PHP SDK with webhook handling, currency utilities, and convenience methods for common operations.

## Features

- 🔑 Flexible client with callback-based key resolution and optional API version override
- 🔄 Test/live mode management
- 🪝 Webhook handler with signature verification and replay protection
- 📦 Product and price management with WordPress integration
- 🎟️ Coupon and promotion code helpers (simplified two-step flow)
- 💳 Checkout session creation with auto-detection of payment vs subscription
- 🔄 Refund creation (full and partial) with auto-detection of payment intent vs charge
- 🏦 Customer portal session management
- 🛡️ Radar block/allow list management for fraud prevention
- 💱 Currency utilities via arraypress/wp-currencies
- 🔗 Stripe Dashboard URL generation
- 🧩 Static helpers for formatting, parsing, validation, labels, and options

## Installation

```bash
composer require arraypress/wp-stripe
```

---

## Client

`ArrayPress\Stripe\Client`

Central access point for all Stripe operations. Keys can be provided as static strings or callables that resolve at
runtime.

```php
use ArrayPress\Stripe\Client;

// With static values
$client = new Client( [
    'secret_key'      => 'sk_test_xxx',
    'publishable_key' => 'pk_test_xxx',
    'webhook_secret'  => 'whsec_xxx',
    'mode'            => 'test',
] );

// With callbacks (recommended for plugins)
$client = new Client( [
    'secret_key'      => fn() => get_option( 'stripe_secret_key' ),
    'publishable_key' => fn() => get_option( 'stripe_publishable_key' ),
    'webhook_secret'  => fn() => get_option( 'stripe_webhook_secret' ),
    'mode'            => fn() => get_option( 'stripe_mode', 'test' ),
] );

// With a custom API version (required for preview features like Managed Payments)
$client = new Client( [
    'secret_key'  => fn() => get_option( 'stripe_secret' ),
    'api_version' => '2026-01-28.clover; managed_payments_preview=v1',
] );
```

### Methods

```php
// Direct SDK access
$client->stripe();                 // Returns StripeClient|null

// Key accessors
$client->get_secret_key();         // string
$client->get_publishable_key();    // string
$client->get_webhook_secret();     // string
$client->get_api_version();        // string — empty if not set; SDK default used

// Mode
$client->get_mode();               // 'test' or 'live'
$client->is_test_mode();           // bool
$client->is_live_mode();           // bool

// State
$client->is_configured();          // bool — true if keys present and client initialised

// Connection test (hits /v1/balance)
$result = $client->test_connection();
// [ 'success' => true, 'mode' => 'test', 'message' => 'Connected to Stripe successfully (test mode).' ]

// Reset — forces re-initialisation on next stripe() call (useful when keys change at runtime)
$client->reset();
```

---

## Webhooks

`ArrayPress\Stripe\Webhooks`

Register event handlers; the library handles signature verification, replay protection, and REST endpoint registration.

```php
use ArrayPress\Stripe\Webhooks;

$webhooks = new Webhooks( $client, [
    'namespace'     => 'myplugin/v1',
    'route'         => '/stripe/webhook',
    'tolerance'     => 300,             // Signature timestamp tolerance in seconds
    'replay_ttl'    => DAY_IN_SECONDS,  // Replay protection window
    'replay_prefix' => 'stripe_evt_',   // Transient prefix for replay keys
    'log_callback'  => fn( $message, $level ) => error_log( $message ),
] );
```

### Handler Registration

```php
// Register a handler — multiple handlers can be registered per event type
$webhooks->on( 'checkout.session.completed', function( $event ) {
    $session = $event->data->object;
} );

// Remove all handlers for an event type
$webhooks->off( 'invoice.payment_failed' );

// Query registered handlers
$webhooks->get_registered_events(); // string[] — all registered event type strings
$webhooks->has_handler( 'invoice.paid' ); // bool
```

### REST API Registration

```php
// Register the REST endpoint (hooks into rest_api_init)
$webhooks->register();

// Get the full endpoint URL for Stripe Dashboard configuration
$url = $webhooks->get_endpoint_url();
// https://example.com/wp-json/myplugin/v1/stripe/webhook
```

### Signature Verification & Dispatch

```php
// Manually verify a webhook payload (advanced use)
$event = $webhooks->verify_signature( $payload, $signature );
// Returns Stripe\Event|WP_Error

// Manually dispatch an already-verified event to registered handlers
$result = $webhooks->dispatch( $event );
// Returns true|WP_Error
```

### Replay Protection

```php
$webhooks->is_replay( 'evt_xxx' );       // bool — true if already processed
$webhooks->mark_processed( 'evt_xxx' );  // void — stores event ID in transient
$webhooks->clear_processed( 'evt_xxx' ); // void — deletes transient (allows reprocessing)
```

### WordPress Action Hooks

```php
// Fired for a specific event type (dots replaced with underscores)
add_action( 'arraypress_stripe_webhook_checkout_session_completed', function( $event, $client ) {
    // ...
}, 10, 2 );

// Fired for ALL events
add_action( 'arraypress_stripe_webhook', function( $event, $type, $client ) {
    // ...
}, 10, 3 );
```

---

## Products

`ArrayPress\Stripe\Products`

```php
use ArrayPress\Stripe\Products;
$products = new Products( $client );
```

### Retrieval

```php
// Retrieve a single product (optional expand params)
$product = $products->get( 'prod_xxx' );
$product = $products->get( 'prod_xxx', [ 'expand' => [ 'default_price' ] ] );

// Paginated list — returns [ 'items' => Product[], 'has_more' => bool, 'cursor' => string ]
$page = $products->list( [ 'active' => true, 'limit' => 25 ] );

// Same as list() but returns plain stdClass objects (safe for REST/transient/JSON)
$page = $products->list_serialized( [ 'active' => true ] );

// Search by name (uses Stripe search API)
$results = $products->search_by_name( 'Premium', 10 );
```

### Creation & Updates

```php
// Create
$product = $products->create( [
    'name'        => 'Premium Plan',
    'description' => 'Access to all features',
    'features'    => [ 'Unlimited downloads', 'Priority support', 'API access' ],
    'images'      => [ 'https://example.com/image.jpg' ], // max 8
    'metadata'    => [ 'tier' => 'pro' ],
    'active'      => true,
] );

// Update (only provided fields are sent)
$products->update( 'prod_xxx', [
    'name'        => 'Updated Name',
    'description' => 'Updated description',
    'features'    => [ 'New feature list' ],
    'metadata'    => [ 'tier' => 'enterprise' ],
    'images'      => [ 'https://example.com/new.jpg' ],
    'active'      => true,
] );
```

### Status Management

```php
$products->archive( 'prod_xxx' );   // Sets active = false
$products->unarchive( 'prod_xxx' ); // Sets active = true
$products->delete( 'prod_xxx' );    // Deletes (only if no prices attached) — returns true|WP_Error
```

### Image Management

```php
// Set image from a WordPress media library attachment
$products->set_image_from_attachment( 'prod_xxx', $attachment_id );
// Returns WP_Error if attachment not found or URL is not public

// Remove all images
$products->clear_images( 'prod_xxx' );
```

### Bulk Retrieval

```php
// Fetch ALL products (auto-paginating)
$all = $products->all( [ 'active' => true ] );

// Process in batches via callback — return false from callback to stop early
$total = $products->each_batch( function( $items, $page ) {
    // $items = Product[], $page = int
    foreach ( $items as $product ) { /* sync */ }
}, [ 'active' => true ] );
// Returns int (total processed) | WP_Error
```

---

## Prices

`ArrayPress\Stripe\Prices`

Stripe prices are immutable once created — only `active`, `nickname`, and `metadata` can be changed. Use `replace()` to
change an amount.

```php
use ArrayPress\Stripe\Prices;
$prices = new Prices( $client );
```

### Retrieval

```php
// Retrieve a single price
$price = $prices->get( 'price_xxx' );
$price = $prices->get( 'price_xxx', [ 'expand' => [ 'product' ] ] );

// Paginated list
$page = $prices->list( [
    'active'   => true,
    'product'  => 'prod_xxx',
    'type'     => 'recurring', // 'one_time' or 'recurring'
    'currency' => 'usd',
    'limit'    => 25,
] );

// Same as list() but plain stdClass objects
$page = $prices->list_serialized( [ 'active' => true, 'expand' => [ 'data.product' ] ] );

// All active prices for a product
$items = $prices->list_by_product( 'prod_xxx' );           // Price[]
$items = $prices->list_by_product( 'prod_xxx', false );    // includes inactive

// Same as list_by_product() but plain stdClass objects
$items = $prices->list_by_product_serialized( 'prod_xxx' );
```

### Creation

```php
// One-time price (amount in major currency units, auto-converted to cents)
$price = $prices->create( [
    'product'  => 'prod_xxx',
    'amount'   => 9.99,
    'currency' => 'USD',         // default 'USD'
    'nickname' => 'Basic',
    'metadata' => [ 'tier' => 'basic' ],
    'active'   => true,
] );

// Recurring price
$price = $prices->create( [
    'product'        => 'prod_xxx',
    'amount'         => 19.99,
    'interval'       => 'month',  // 'day', 'week', 'month', 'year'
    'interval_count' => 1,
    'currency'       => 'USD',
    'nickname'       => 'Monthly',
] );
```

### Status Management

```php
$prices->deactivate( 'price_xxx' );  // Sets active = false — returns Price|WP_Error
$prices->activate( 'price_xxx' );    // Sets active = true  — returns Price|WP_Error

// Update (only active, nickname, metadata are mutable)
$prices->update( 'price_xxx', [
    'nickname' => 'Premium Annual',
    'metadata' => [ 'promo' => 'launch' ],
    'active'   => true,
] );
```

### Price Replacement

```php
// Create new price + deactivate old in one call (since amounts are immutable)
$result = $prices->replace( 'price_old', [
    'product'  => 'prod_xxx',
    'amount'   => 14.99,
    'currency' => 'USD',
    'interval' => 'month',
] );
// $result['new_price'] — the new Price object
// $result['old_price'] — the deactivated old Price object
// Returns WP_Error with code 'partial_replace' if new was created but old could not be deactivated
```

### Bulk Retrieval

```php
// Fetch ALL prices (auto-paginating)
$all = $prices->all( [ 'active' => true, 'expand' => [ 'data.product' ] ] );

// Process in batches
$total = $prices->each_batch( function( $items, $page ) {
    // sync batch...
}, [ 'product' => 'prod_xxx' ] );
```

---

## Coupons & Promotion Codes

`ArrayPress\Stripe\Coupons`

Always creates both a Stripe coupon (discount rules) and a customer-facing promotion code in a single call.

```php
use ArrayPress\Stripe\Coupons;
$coupons = new Coupons( $client );
```

### Creation

```php
// Percentage discount
$result = $coupons->create( 'SUMMER25', [
    'percent_off'     => 25,
    'duration'        => 'once',     // 'once', 'repeating', 'forever'
    'max_redemptions' => 100,
    'expires_at'      => strtotime( '+30 days' ),
    'name'            => 'Summer Sale 25% Off',
    'metadata'        => [ 'campaign' => 'summer' ],
] );

// Fixed amount discount ($10 off)
$result = $coupons->create( 'SAVE10', [
    'amount_off' => 10.00,           // major currency units, auto-converted to cents
    'currency'   => 'USD',
    'duration'   => 'forever',
] );

// Repeating discount, first-time customers only, $50 minimum order
$result = $coupons->create( 'WELCOME15', [
    'percent_off'      => 15,
    'duration'         => 'repeating',
    'duration_months'  => 3,
    'first_time_only'  => true,
    'minimum_amount'   => 50.00,
    'minimum_currency' => 'USD',
] );

// Returns array|WP_Error:
// $result['coupon']         — Stripe\Coupon
// $result['promotion_code'] — Stripe\PromotionCode
```

### Retrieval & Listing

```php
$coupon = $coupons->get( 'coupon_id' );    // Coupon|WP_Error

$page = $coupons->list( [ 'limit' => 25 ] );
// [ 'items' => Coupon[], 'has_more' => bool, 'cursor' => string ] | WP_Error

$page = $coupons->list_serialized();       // Same but plain stdClass objects
```

### Management

```php
$coupons->delete( 'coupon_id' );              // true|WP_Error — existing discounts unaffected
$coupons->deactivate_code( 'promo_xxx' );     // PromotionCode|WP_Error — coupon remains active
```

---

## Checkout Sessions

`ArrayPress\Stripe\Checkout`

```php
use ArrayPress\Stripe\Checkout;
$checkout = new Checkout( $client );
```

### Session Creation

```php
// Auto-detect mode from line items (recommended)
$session = $checkout->create(
    [ [ 'price' => 'price_xxx', 'quantity' => 1 ] ],
    [
        'success_url'           => Checkout::success_url( home_url( '/thank-you/' ) ),
        'cancel_url'            => home_url( '/cart/' ),
        'customer_email'        => 'user@example.com',
        'allow_promotion_codes' => true,
        'metadata'              => [ 'order_source' => 'website' ],
        // Pass known recurring price IDs to avoid extra API calls during mode detection
        'recurring_price_ids'   => [ 'price_monthly', 'price_yearly' ],
    ]
);

// All supported args:
$session = $checkout->create( $line_items, [
    'mode'                       => 'payment',   // explicit: 'payment', 'subscription', 'setup'
    'success_url'                => '...',
    'cancel_url'                 => '...',
    'customer'                   => 'cus_xxx',   // mutually exclusive with customer_email
    'customer_email'             => 'user@example.com',
    'metadata'                   => [],
    'allow_promotion_codes'      => true,
    'billing_address_collection' => 'required',  // 'auto' or 'required'
    'phone_number_collection'    => [ 'enabled' => true ],
    'automatic_tax'              => [ 'enabled' => true ],
    'tax_id_collection'          => [ 'enabled' => true ],
    'custom_fields'              => [],          // up to 3
    'custom_text'                => [],
    'consent_collection'         => [],
    'subscription_data'          => [],          // subscription mode only
    'managed_payments'           => [],
    'locale'                     => 'en',
    'payment_method_types'       => [ 'card' ],
    'payment_intent_data'        => [],
    'invoice_creation'           => [],
    'after_expiration'           => [],
    'expires_at'                 => time() + 3600,
    'shipping_address_collection' => [],
    'shipping_options'           => [],
] );
```

### Retrieval

```php
$session = $checkout->get( 'cs_xxx' );
$session = $checkout->get( 'cs_xxx', [ 'expand' => [ 'payment_intent' ] ] );

// With common expansions (line_items, payment_intent, customer)
$session = $checkout->get_expanded( 'cs_xxx' );

// Get line items separately
$items = $checkout->get_line_items( 'cs_xxx' ); // array|WP_Error
```

### Management

```php
// Expire an open session immediately
$checkout->expire( 'cs_xxx' ); // Session|WP_Error
```

### URL Helper

```php
// Appends {CHECKOUT_SESSION_ID} placeholder — Stripe replaces it after payment
$url = Checkout::success_url( home_url( '/thank-you/' ) );
// https://example.com/thank-you/?session_id={CHECKOUT_SESSION_ID}

$url = Checkout::success_url( home_url( '/thank-you/' ), 'sid' ); // custom param name
```

### Webhook Data Extraction

```php
// Get complete order data from a completed session (one API call)
$data = $checkout->get_completed_data( 'cs_xxx' );

// Returns array|WP_Error with keys:
// session_id, payment_intent_id, subscription_id, customer_id,
// customer_email, customer_name, total, currency, country,
// payment_brand, payment_last4, payment_type, mode, status,
// payment_status, metadata, is_test, line_items[], discount[]
//
// Each line_item: stripe_price_id, stripe_product_id, product_name,
//                 quantity, total, unit_amount, currency, interval, interval_count
//
// discount: code, coupon_id, amount_off, percent_off
```

---

## Customer Portal

`ArrayPress\Stripe\Portal`

```php
use ArrayPress\Stripe\Portal;
$portal = new Portal( $client );
```

```php
// Create a portal session
$session = $portal->create( 'cus_xxx', home_url( '/account/' ) );
wp_redirect( $session->url );

// With optional args
$session = $portal->create( 'cus_xxx', home_url( '/account/' ), [
    'configuration' => 'bpc_xxx',  // billing portal configuration ID
    'locale'        => 'en',
    'flow_data'     => [],
] );

// Get just the URL (convenience wrapper)
$url = $portal->get_url( 'cus_xxx', home_url( '/account/' ) ); // string|WP_Error
```

---

## Refunds

`ArrayPress\Stripe\Refunds`

```php
use ArrayPress\Stripe\Refunds;
$refunds = new Refunds( $client );
```

### Creation

```php
// Full refund — accepts payment intent (pi_xxx) or charge (ch_xxx)
$refund = $refunds->create( 'pi_xxx' );
$refund = $refunds->create( 'ch_xxx', [ 'reason' => 'requested_by_customer' ] );

// Partial refund ($5.00)
$refund = $refunds->create( 'pi_xxx', [
    'amount'   => 5.00,      // major currency units, auto-converted to cents
    'currency' => 'USD',
    'reason'   => 'duplicate',  // 'duplicate', 'fraudulent', 'requested_by_customer'
    'metadata' => [],
] );
```

### Retrieval

```php
$refund = $refunds->get( 'ref_xxx' );

// List refunds for a payment intent or charge
$items = $refunds->list_by_payment( 'pi_xxx', 100 );          // Refund[]|WP_Error
$items = $refunds->list_by_payment_serialized( 'pi_xxx' );    // stdClass[]|WP_Error
```

### Webhook Data Extraction

```php
// In a charge.refunded handler
$data = $refunds->get_refund_data( $event );
// Returns array: charge_id, payment_intent_id, amount_refunded, amount_captured,
//                currency, fully_refunded, reason, refund_id, latest_amount, status
```

---

## Subscriptions

`ArrayPress\Stripe\Subscriptions`

Subscriptions are created via Checkout Sessions. This class handles post-creation management.

```php
use ArrayPress\Stripe\Subscriptions;
$subscriptions = new Subscriptions( $client );
```

### Retrieval

```php
$sub = $subscriptions->get( 'sub_xxx' );
$sub = $subscriptions->get( 'sub_xxx', [ 'expand' => [ 'default_payment_method' ] ] );

// With default_payment_method, latest_invoice, items.data.price.product expanded
$sub = $subscriptions->get_expanded( 'sub_xxx' );

// Paginated list for a customer
$page = $subscriptions->list_by_customer( 'cus_xxx' );
$page = $subscriptions->list_by_customer( 'cus_xxx', [ 'status' => 'active', 'limit' => 25 ] );
// Returns [ 'items' => Subscription[], 'has_more' => bool, 'cursor' => string ] | WP_Error

// Same but plain stdClass objects
$page = $subscriptions->list_by_customer_serialized( 'cus_xxx' );

// Auto-paginating — fetches all subscriptions for a customer
$all = $subscriptions->get_all_for_customer( 'cus_xxx', [ 'status' => 'active' ] );
```

### Cancellation

```php
// Cancel at period end (recommended — customer retains access until period ends)
$subscriptions->cancel( 'sub_xxx' );
$subscriptions->cancel( 'sub_xxx', [
    'cancellation_details' => [ 'comment' => 'Customer requested via portal' ],
    'metadata'             => [],
] );

// Cancel immediately (no further invoices generated)
$subscriptions->cancel_immediately( 'sub_xxx' );
$subscriptions->cancel_immediately( 'sub_xxx', [
    'prorate' => true,   // create prorated credit for unused time
    'invoice' => true,   // generate a final invoice immediately
] );

// Reactivate — removes cancel_at_period_end flag (only while subscription is still active)
$subscriptions->reactivate( 'sub_xxx' );
```

### Pause / Resume

```php
// Pause payment collection
$subscriptions->pause( 'sub_xxx' );
$subscriptions->pause( 'sub_xxx', 'void' );                               // behavior
$subscriptions->pause( 'sub_xxx', 'mark_uncollectible', strtotime( '+30 days' ) ); // auto-resume at
// Valid behaviors: 'mark_uncollectible' (default), 'keep_as_draft', 'void'

// Resume a paused subscription
$subscriptions->resume( 'sub_xxx' );
```

### Price Changes

```php
// Change price — handles subscription item lookup automatically
$subscriptions->change_price( 'sub_xxx', 'price_new' );
$subscriptions->change_price( 'sub_xxx', 'price_new', [
    'proration_behavior' => 'none',  // 'create_prorations' (default), 'none', 'always_invoice'
    'quantity'           => 2,
] );
```

### Updates

```php
$subscriptions->update_metadata( 'sub_xxx', [ 'plan' => 'enterprise' ] );
$subscriptions->update_payment_method( 'sub_xxx', 'pm_xxx' );
```

### Webhook Data Extraction

```php
// Normalise data from any customer.subscription.* event
$data = $subscriptions->get_event_data( $event );

// Returns array: subscription_id, customer_id, status, price_id, product_id,
//                quantity, current_period_end, current_period_start,
//                cancel_at_period_end, canceled_at, ended_at,
//                currency, amount, interval, interval_count,
//                is_test, metadata, event_type, latest_invoice,
//                default_payment_method
// Timestamps formatted as 'Y-m-d H:i:s'
```

---

## Customers

`ArrayPress\Stripe\Customers`

```php
use ArrayPress\Stripe\Customers;
$customers = new Customers( $client );
```

### Retrieval

```php
$customer = $customers->get( 'cus_xxx' );
$customer = $customers->get( 'cus_xxx', [ 'expand' => [ 'default_source' ] ] );

// With default_source and invoice_settings.default_payment_method expanded
$customer = $customers->get_expanded( 'cus_xxx' );

// Find by email — returns most recent match or null if not found
$customer = $customers->find_by_email( 'user@example.com' ); // Customer|null|WP_Error

// Paginated list
$page = $customers->list( [ 'email' => 'user@example.com', 'limit' => 25 ] );
// Returns [ 'items' => Customer[], 'has_more' => bool, 'cursor' => string ] | WP_Error

$page = $customers->list_serialized(); // plain stdClass objects
```

### Creation & Updates

```php
// Create
$customer = $customers->create( [
    'email'          => 'user@example.com',
    'name'           => 'Jane Doe',
    'phone'          => '+447911123456',
    'description'    => 'VIP customer',
    'metadata'       => [ 'source' => 'website' ],
    'address'        => [
        'line1'       => '123 Main St',
        'city'        => 'London',
        'postal_code' => 'SW1A 1AA',
        'country'     => 'GB',
    ],
    'payment_method' => 'pm_xxx',  // attaches and sets as default
] );

// Update (only provided fields are sent)
$customers->update( 'cus_xxx', [ 'name' => 'Jane Smith', 'metadata' => [ 'vip' => '1' ] ] );

// Upsert by email — creates if new, updates if exists
$result = $customers->upsert_by_email( 'user@example.com', [ 'name' => 'Jane Doe' ] );
// $result['customer'] — Customer object
// $result['created']  — bool (true = newly created)

// Delete (permanently removes customer and cancels all active subscriptions)
$customers->delete( 'cus_xxx' ); // true|WP_Error
```

### Notes

```php
// Appends note to the customer's description field with a UTC timestamp
$customers->add_note( 'cus_xxx', 'VIP customer, handle with care.' );
// Format: "[2025-06-01 09:30] VIP customer, handle with care."
```

### Payment Methods

```php
// List payment methods (default type 'card')
$methods = $customers->list_payment_methods( 'cus_xxx' );
$methods = $customers->list_payment_methods( 'cus_xxx', 'sepa_debit' );

// Set the default payment method for invoices
$customers->set_default_payment_method( 'cus_xxx', 'pm_xxx' );
```

### Bulk Retrieval

```php
// Fetch ALL customers (auto-paginating)
$all = $customers->all( [ 'email' => 'user@example.com' ] );

// Process in batches
$total = $customers->each_batch( function( $items, $page ) {
    // $items = Customer[], $page = int
}, [ /* optional filters */ ] );
```

---

## Invoices

`ArrayPress\Stripe\Invoices`

```php
use ArrayPress\Stripe\Invoices;
$invoices = new Invoices( $client );
```

### Retrieval

```php
$invoice = $invoices->get( 'in_xxx' );
$invoice = $invoices->get( 'in_xxx', [ 'expand' => [ 'payment_intent' ] ] );

// With payment_intent.payment_method, payment_intent.latest_charge, subscription, customer
$invoice = $invoices->get_expanded( 'in_xxx' );

// List for a customer
$page = $invoices->list_by_customer( 'cus_xxx' );
$page = $invoices->list_by_customer( 'cus_xxx', [
    'status'       => 'paid',   // 'draft', 'open', 'paid', 'void', 'uncollectible'
    'subscription' => 'sub_xxx',
    'limit'        => 25,
] );

// All invoices for a subscription (returns Invoice[] directly, not paged)
$items = $invoices->list_by_subscription( 'sub_xxx', 100 );

// Upcoming invoice preview (uses createPreview API — stripe-php v17+)
$upcoming = $invoices->get_upcoming( 'cus_xxx' );           // Invoice|null|WP_Error
$upcoming = $invoices->get_upcoming( 'cus_xxx', 'sub_xxx' );
```

### Lifecycle Management

```php
$invoices->finalize( 'in_xxx' );            // Lock draft for payment — Invoice|WP_Error
$invoices->send( 'in_xxx' );               // Email invoice to customer — Invoice|WP_Error
$invoices->pay( 'in_xxx' );                // Collect payment — Invoice|WP_Error
$invoices->pay( 'in_xxx', 'pm_xxx' );      // Pay with specific payment method
$invoices->void( 'in_xxx' );               // Mark as void — Invoice|WP_Error
$invoices->mark_uncollectible( 'in_xxx' ); // Write off — Invoice|WP_Error

// Update the customer-visible memo (draft invoices only)
$invoices->set_memo( 'in_xxx', 'Thank you for your purchase!' );
```

### Webhook Data Extraction

```php
// Get all data needed to process an invoice.paid renewal
// Returns null for billing_reason='subscription_create' (already handled by checkout)
$data = $invoices->get_renewal_data( 'in_xxx' );
$data = $invoices->get_renewal_data( 'in_xxx', false ); // skip_initial = false to process all

// Returns array|null|WP_Error with keys:
// invoice_id, payment_intent_id, subscription_id, customer_id,
// customer_email, customer_name, total, subtotal, tax, currency,
// country, payment_brand, payment_last4, payment_type,
// billing_reason, status, period_start, period_end, is_test,
// line_items[]
//
// Each line_item: stripe_price_id, stripe_product_id, product_name,
//                 quantity, total, unit_amount, currency,
//                 interval, interval_count, period_start, period_end
```

---

## Payment Intents

`ArrayPress\Stripe\PaymentIntents`

```php
use ArrayPress\Stripe\PaymentIntents;
$intents = new PaymentIntents( $client );
```

### Retrieval

```php
$intent = $intents->get( 'pi_xxx' );
$intent = $intents->get( 'pi_xxx', [ 'expand' => [ 'latest_charge' ] ] );

// With latest_charge, payment_method, customer expanded
$intent = $intents->get_expanded( 'pi_xxx' );
```

### Payment Details

```php
// Extract card brand, last4, expiry, country from the payment method used
$details = $intents->get_payment_details( 'pi_xxx' );
// Returns array: brand, last4, exp_month, exp_year, country, type
// All keys present with empty/zero defaults if not a card payment

// Get customer country (checks billing address first, then card country)
$country = $intents->get_country( 'pi_xxx' ); // string|WP_Error — e.g. "GB"
```

### Management

```php
$intents->update_metadata( 'pi_xxx', [ 'order_id' => '12345' ] );

// Cancel (only works on uncaptured intents)
$intents->cancel( 'pi_xxx' );
$intents->cancel( 'pi_xxx', 'duplicate' );
// Valid reasons: 'duplicate', 'fraudulent', 'requested_by_customer', 'abandoned'

// Capture a manually-authorised intent (must be within 7 days of authorisation)
$intents->capture( 'pi_xxx' );
$intents->capture( 'pi_xxx', [ 'amount_to_capture' => 500 ] ); // partial capture in smallest unit
```

---

## Events

`ArrayPress\Stripe\Events`

```php
use ArrayPress\Stripe\Events;
$events = new Events( $client );
```

```php
// Retrieve a specific event
$event = $events->get( 'evt_xxx' ); // Event|WP_Error

// Paginated list
$page = $events->list( [
    'type'    => 'checkout.session.completed',
    'created' => [ 'gte' => strtotime( '-7 days' ) ],
    'limit'   => 25,
] );
// Returns [ 'items' => Event[], 'has_more' => bool, 'cursor' => string ] | WP_Error

// List events from the last N hours (useful for debugging missed webhooks)
$recent = $events->list_recent( 24 );                              // last 24 hours
$recent = $events->list_recent( 6, 'invoice.payment_failed' );    // filtered by type

// Reprocess a missed event — clears replay protection, then dispatches through handlers
$result = $events->reprocess( 'evt_xxx', $webhooks ); // true|WP_Error
```

---

## Radar (Block/Allow Lists)

`ArrayPress\Stripe\Radar`

```php
use ArrayPress\Stripe\Radar;
$radar = new Radar( $client );
```

### Quick Block/Allow

```php
// Block — adds to Stripe's default lists
$radar->block_email( 'fraud@example.com' );
$radar->block_ip( '192.168.1.1' );
$radar->block_customer( 'cus_xxx' );
$radar->block_card( 'card_fingerprint_xxx' );
$radar->block_country( 'NG' );   // two-letter ISO code

// Allow — adds to Stripe's default allow lists
$radar->allow_email( 'vip@example.com' );
$radar->allow_customer( 'cus_xxx' );
```

### List Item Management

```php
// Add to any list by alias constant or ID (rsl_xxx)
$item = $radar->add_item( Radar::LIST_BLOCK_EMAIL, 'fraud@example.com' );
$item = $radar->add_item( 'rsl_xxx', 'some_value' );

// Remove an item by item ID (rsli_xxx)
$radar->remove_item( 'rsli_xxx' ); // true|WP_Error

// List items in a value list
$result = $radar->list_items( Radar::LIST_BLOCK_EMAIL );
$result = $radar->list_items( Radar::LIST_BLOCK_EMAIL, 50 ); // limit
// Returns [ 'items' => ValueListItem[], 'has_more' => bool ] | WP_Error

// Same but plain stdClass objects
$result = $radar->list_items_serialized( Radar::LIST_BLOCK_IP );
```

### List Alias Constants

```php
Radar::LIST_BLOCK_EMAIL           // 'block_list_email'
Radar::LIST_BLOCK_IP              // 'block_list_ip_address'
Radar::LIST_BLOCK_CARD            // 'block_list_card_fingerprint'
Radar::LIST_BLOCK_CARD_BIN        // 'block_list_card_bin'
Radar::LIST_BLOCK_CUSTOMER        // 'block_list_customer_id'
Radar::LIST_BLOCK_CARD_COUNTRY    // 'block_list_card_country'
Radar::LIST_BLOCK_CLIENT_COUNTRY  // 'block_list_ip_country'
Radar::LIST_BLOCK_CHARGE_DESC     // 'block_list_charge_description'
Radar::LIST_ALLOW_EMAIL           // 'allow_list_email'
Radar::LIST_ALLOW_IP              // 'allow_list_ip_address'
Radar::LIST_ALLOW_CARD            // 'allow_list_card_fingerprint'
Radar::LIST_ALLOW_CUSTOMER        // 'allow_list_customer_id'
```

### Custom List Management

```php
// Create a custom list
$list = $radar->create_list(
    'vip_emails',           // alias (referenced in Radar rules as @vip_emails)
    'VIP Customers',        // display name
    'email',                // item_type — see VALID_ITEM_TYPES below
    [ 'source' => 'crm' ]  // optional metadata
);

// Valid item types:
// 'card_fingerprint', 'card_bin', 'email', 'ip_address', 'country',
// 'string', 'case_sensitive_string', 'customer_id',
// 'sepa_debit_fingerprint', 'us_bank_account_fingerprint'

$list = $radar->get_list( 'rsl_xxx' );       // ValueList|WP_Error

$result = $radar->list_all();                // [ 'items' => ValueList[], 'has_more' => bool ] | WP_Error
$result = $radar->list_all( [ 'alias' => 'vip_emails' ] );
$result = $radar->list_all_serialized();     // plain stdClass objects

$radar->delete_list( 'rsl_xxx' );           // true|WP_Error — list must not be in active rules
```

## Tax Rates

`ArrayPress\Stripe\TaxRates`

Manages manual tax rates for applying to checkout sessions and invoices. For fully automatic tax collection, pass `automatic_tax: [ 'enabled' => true ]` in your Checkout session instead — no rate management needed in that flow.

Note: Once created, a tax rate's `percentage` and `inclusive` flag are immutable. Use `replace()` to correct either value.

```php
use ArrayPress\Stripe\TaxRates;
$tax_rates = new TaxRates( $client );
```

### Creation

```php
// Exclusive tax (added on top of the price)
$rate = $tax_rates->create( [
    'display_name' => 'Sales Tax',
    'percentage'   => 8.5,
    'inclusive'    => false,
    'country'      => 'US',
    'state'        => 'CA',
    'jurisdiction' => 'California',
    'tax_type'     => 'sales_tax',
    'metadata'     => [ 'region' => 'west_coast' ],
] );

// Inclusive tax (already included in the displayed price)
$rate = $tax_rates->create( [
    'display_name' => 'VAT',
    'percentage'   => 20.0,
    'inclusive'    => true,
    'country'      => 'GB',
    'jurisdiction' => 'UK VAT',
    'tax_type'     => 'vat',
] );

// Valid tax_type values:
// 'vat', 'gst', 'hst', 'qst', 'rst', 'sales_tax', 'jct',
// 'igst', 'cgst', 'sgst', 'cess', 'lease_tax', 'amusement_tax', 'communications_tax'
```

### Retrieval

```php
// Retrieve a single rate
$rate = $tax_rates->get( 'txr_xxx' );

// Paginated list — returns [ 'items' => TaxRate[], 'has_more' => bool, 'cursor' => string ]
$page = $tax_rates->list( [ 'limit' => 25 ] );
$page = $tax_rates->list( [ 'active' => true, 'inclusive' => false ] );

// Same but plain stdClass objects
$page = $tax_rates->list_serialized( [ 'active' => true ] );

// Active rates only — optional inclusive filter
// null = all, true = inclusive only, false = exclusive only
$page = $tax_rates->get_active();
$page = $tax_rates->get_active( true );   // inclusive only
$page = $tax_rates->get_active( false );  // exclusive only

// Same but plain stdClass objects
$page = $tax_rates->get_active_serialized();
$page = $tax_rates->get_active_serialized( false );
```

### Dropdown Options

```php
// Key/value array for admin dropdowns — format: 'txr_xxx' => 'VAT (20%) — UK VAT'
// Jurisdiction is appended when set; omitted when empty
$options = $tax_rates->list_options();          // all rates
$options = $tax_rates->list_options( true );    // inclusive only
$options = $tax_rates->list_options( false );   // exclusive only

// Active rates only (the one you'll use most often in settings pages)
$options = $tax_rates->get_options();
$options = $tax_rates->get_options( true );     // inclusive only

// Example output:
// [
//     'txr_aaa' => 'VAT (20%) — UK VAT',
//     'txr_bbb' => 'GST (10%) — Australia',
//     'txr_ccc' => 'Sales Tax (8.5%) — California',
// ]
```

### Updates & Status Management

```php
// Update mutable fields only (display_name, jurisdiction, description, metadata, active)
$tax_rates->update( 'txr_xxx', [
    'display_name' => 'EU VAT',
    'jurisdiction' => 'European Union',
    'metadata'     => [ 'updated' => '1' ],
] );

// Archive — prevents future use, existing transactions unaffected
$tax_rates->archive( 'txr_xxx' );   // TaxRate|WP_Error

// Unarchive — makes a previously archived rate active again
$tax_rates->unarchive( 'txr_xxx' ); // TaxRate|WP_Error
```

### Replacement

```php
// Archive old rate + create corrected copy in one call
// (required when percentage or inclusive need changing — both are immutable)
$result = $tax_rates->replace( 'txr_old', [
    'percentage' => 23.0,   // corrected value
] );
// Omitted fields are copied from the old rate automatically

// $result['new_rate'] — the new TaxRate object
// $result['old_rate'] — the archived old TaxRate object
// Returns WP_Error with code 'partial_replace' if new was created but old could not be archived
```

### Bulk Retrieval

```php
// Fetch ALL rates (auto-paginating)
$all = $tax_rates->all( [ 'active' => true ] );

// Process in batches — return false from callback to stop early
$total = $tax_rates->each_batch( function( $items, $page ) {
    foreach ( $items as $rate ) { /* sync */ }
}, [ 'active' => true ] );
// Returns int (total processed) | WP_Error
```

---

## Dashboard URLs

`ArrayPress\Stripe\Dashboard` — all static methods

```php
use ArrayPress\Stripe\Dashboard;

// Generic URL builder
Dashboard::url( 'products', 'prod_xxx', true );  // test mode
// https://dashboard.stripe.com/test/products/prod_xxx

// Resource-specific helpers (all accept $id, $is_test = false)
Dashboard::product( 'prod_xxx', true );
Dashboard::price( 'price_xxx' );
Dashboard::customer( 'cus_xxx' );
Dashboard::payment( 'pi_xxx' );
Dashboard::subscription( 'sub_xxx' );
Dashboard::invoice( 'in_xxx' );
Dashboard::coupon( 'coupon_xxx' );
```

---

## Format

`ArrayPress\Stripe\Format` — all static methods

Combines currency formatting (via `arraypress/wp-currencies`) with Stripe billing interval data.

```php
use ArrayPress\Stripe\Format;

// Format amount in smallest currency unit
Format::price( 999, 'USD' );                          // "$9.99"
Format::price( 999, 'USD', 'de_DE' );                 // locale-aware: "9,99 $" (requires PHP intl)

// Format with recurring interval suffix
Format::price_with_interval( 999, 'USD', 'month' );           // "$9.99 per month"
Format::price_with_interval( 999, 'USD', 'month', 3 );        // "$9.99 every 3 months"
Format::price_with_interval( 999, 'USD', null );               // "$9.99" (no interval)
Format::price_with_interval( 999, 'USD', 'year', 1, 'fr_FR' ); // locale-aware

// Format directly from a Stripe price object or line item object
// Compatible with: Stripe\Price, line items, subscription items, invoice lines, stdClass equivalents
Format::price_from_object( $price );                           // string|null
Format::price_from_object( $price, 'GBP' );                   // currency override
Format::price_from_object( $price, '', 'month', 1 );          // interval override

// Wrapped in <span class="price">
Format::price_html( $price );                                  // string|null
Format::price_html( $price, 'EUR', 'year' );
```

---

## Labels

`ArrayPress\Stripe\Labels` — all static methods

Human-readable display strings for billing intervals.

```php
use ArrayPress\Stripe\Labels;

// Standalone label (for dropdowns, status badges)
Labels::get_billing_label( 'month' );        // "Monthly"
Labels::get_billing_label( 'year' );         // "Yearly"
Labels::get_billing_label( 'day' );          // "Daily"
Labels::get_billing_label( 'week' );         // "Weekly"
Labels::get_billing_label( 'month', 3 );     // "Every 3 months"
Labels::get_billing_label( 'year', 2 );      // "Every 2 years"

// Short suffix for compact price displays (e.g., "$9.99/mo")
Labels::get_billing_suffix( 'month' );       // "/mo"
Labels::get_billing_suffix( 'year' );        // "/yr"
Labels::get_billing_suffix( 'day' );         // "/day"
Labels::get_billing_suffix( 'week' );        // "/wk"
Labels::get_billing_suffix( 'month', 3 );    // "/3 mo"

// Suffix-style text to append to a price (for storefront display)
Labels::get_interval_text( 'month' );        // "per month"
Labels::get_interval_text( 'year' );         // "per year"
Labels::get_interval_text( 'day' );          // "per day"
Labels::get_interval_text( 'week' );         // "per week"
Labels::get_interval_text( 'month', 3 );     // "every 3 months"
Labels::get_interval_text( 'year', 2 );      // "every 2 years"
```

---

## Options

`ArrayPress\Stripe\Options` — all static methods

Pre-built `key => label` arrays for use in admin dropdowns and form inputs.

```php
use ArrayPress\Stripe\Options;

// Billing intervals
Options::intervals();                   // [ 'day' => 'Daily', 'week' => ..., 'month' => ..., 'year' => ... ]
Options::intervals( true );             // prepends 'one_time' => 'One-Time'

// Price types
Options::price_types();
// [ 'one_time' => 'One-Time', 'recurring' => 'Recurring' ]

// Coupon durations
Options::coupon_durations();
// [ 'once' => 'Once', 'repeating' => 'Repeating', 'forever' => 'Forever' ]

// Subscription statuses
Options::subscription_statuses();
// trialing, active, past_due, unpaid, paused, canceled, incomplete, incomplete_expired
Options::subscription_statuses( true ); // prepends '' => 'All Statuses'

// Invoice statuses
Options::invoice_statuses();
// draft, open, paid, uncollectible, void
Options::invoice_statuses( true );      // prepends '' => 'All Statuses'

// Charge statuses
Options::charge_statuses();
// succeeded, pending, failed
Options::charge_statuses( true );       // prepends '' => 'All Statuses'

// Simplified tax categories (maps human labels to Stripe tax codes)
Options::tax_categories();
// e.g. [ 'txcd_10103000' => 'Software as a Service (SaaS) - Personal Use', ... ]

// Tax codes approved for Stripe Managed Payments (use instead of tax_categories() when Managed Payments is enabled)
Options::managed_payments_tax_codes();
// e.g. [ 'txcd_10201000' => 'Video Games - Downloaded - Non Subscription - Permanent Rights', ... ]
```

---

## Parse

`ArrayPress\Stripe\Parse` — all static methods

Extracts and normalises values from Stripe API objects or flat DB row objects. All methods accept live SDK objects or
plain `stdClass` objects interchangeably.

```php
use ArrayPress\Stripe\Parse;
```

### Images

```php
Parse::product_image( $product );    // string — first image URL or ''
Parse::product_images( $product );   // string[] — all image URLs or []
```

### Features

```php
Parse::product_features( $product );      // string[] — marketing feature names
Parse::product_features_json( $product ); // string|null — JSON-encoded, null if empty
```

### Metadata

```php
Parse::metadata( $item );       // array — key/value pairs, empty array if none
Parse::metadata_json( $item );  // string|null — JSON-encoded, null if empty
```

### Currency

```php
// Resolves in priority order: direct currency → price->currency → price_data->currency → $default
Parse::currency( $item );           // string — uppercase ISO code, e.g. 'USD'
Parse::currency( $item, 'EUR' );    // with custom default
```

### Recurring / Interval

```php
// For Stripe API price objects
Parse::interval( $price );           // string|null — 'day', 'week', 'month', 'year', or null
Parse::interval_count( $price );     // int|null

// Resolves both from any object shape (DB row, API price, line item, subscription item)
Parse::interval_data( $item );
// Returns [ 'interval' => string|null, 'interval_count' => int ]
// Handles: flat DB rows (recurring_interval column), nested price->recurring,
//          inline price_data->recurring, direct recurring property

// Check if a price is recurring
Parse::is_recurring( $price ); // bool
```

---

## Validate

`ArrayPress\Stripe\Validate` — all static methods

Boolean validation helpers for Stripe IDs, API keys, and URLs.

```php
use ArrayPress\Stripe\Validate;

// Stripe IDs (format check only — does not verify ID exists in Stripe)
Validate::is_valid_id( 'prod_abc123' );           // bool
Validate::is_valid_id( 'prod_abc123', 'prod_' );  // bool — also checks prefix

// Identify resource type from ID prefix
Validate::get_id_type( 'prod_xxx' );    // 'product'
Validate::get_id_type( 'pi_xxx' );      // 'payment_intent'
Validate::get_id_type( 'cs_xxx' );      // 'checkout_session'
Validate::get_id_type( 'cus_xxx' );     // 'customer'
Validate::get_id_type( 'sub_xxx' );     // 'subscription'
Validate::get_id_type( 'si_xxx' );      // 'subscription_item'
Validate::get_id_type( 'ch_xxx' );      // 'charge'
Validate::get_id_type( 'in_xxx' );      // 'invoice'
Validate::get_id_type( 'ii_xxx' );      // 'invoice_item'
Validate::get_id_type( 're_xxx' );      // 'refund'
Validate::get_id_type( 'evt_xxx' );     // 'event'
Validate::get_id_type( 'pm_xxx' );      // 'payment_method'
Validate::get_id_type( 'seti_xxx' );    // 'setup_intent'
Validate::get_id_type( 'promo_xxx' );   // 'promotion_code'
Validate::get_id_type( 'bps_xxx' );     // 'portal_session'
Validate::get_id_type( 'dp_xxx' );      // 'dispute'
Validate::get_id_type( 'acct_xxx' );    // 'account'
Validate::get_id_type( 'plink_xxx' );   // 'payment_link'
// Returns null for unrecognised prefixes

// API key mode checks
Validate::is_test_key( 'sk_test_xxx' ); // bool
Validate::is_test_key( 'pk_test_xxx' ); // bool
Validate::is_live_key( 'sk_live_xxx' ); // bool
Validate::is_live_key( 'pk_live_xxx' ); // bool

// URL accessibility (Stripe requires public URLs for product images)
Validate::is_public_url( $url );
// Returns false for: localhost, 127.0.0.1, ::1, 10.x.x.x, 172.16-31.x.x,
//                    192.168.x.x, *.local, *.test
```

---

## Utilities

`ArrayPress\Stripe\Utilities` — static methods

```php
use ArrayPress\Stripe\Utilities;

// Determine image file extension from an HTTP response
// Checks Content-Type header first, then binary-inspects body via finfo
$extension = Utilities::get_image_extension( $response, $body );
// Returns 'jpg', 'png', 'gif', 'webp', or 'svg' — defaults to 'jpg'
// $response = WP_HTTP API response array, $body = raw response body string
```

---

## Currency (via arraypress/wp-currencies)

```php
use ArrayPress\Currencies\Currency;

// Format amount (input in smallest unit — cents, pence, etc.)
Currency::format( 999, 'USD' );                             // "$9.99"
Currency::format( 1000, 'JPY' );                            // "¥1,000"
Currency::format_localized( 999, 'EUR', 'de_DE' );          // "9,99 €"
Currency::format_with_interval( 999, 'USD', 'month' );      // "$9.99 per month"

// Convert between decimal and Stripe units
Currency::to_smallest_unit( 9.99, 'USD' );    // 999  (int)
Currency::from_smallest_unit( 999, 'USD' );   // 9.99 (float)
```

---

## Webhook Data Extraction Reference

Each resource class exposes a method that assembles everything needed from a single webhook event, eliminating multiple
API round-trips in handler code.

| Event                        | Method                                          | Returns                                        |
|------------------------------|-------------------------------------------------|------------------------------------------------|
| `checkout.session.completed` | `Checkout::get_completed_data( $session_id )`   | Full order + line items + discount             |
| `invoice.paid`               | `Invoices::get_renewal_data( $invoice_id )`     | Renewal order data; `null` for initial invoice |
| `customer.subscription.*`    | `Subscriptions::get_event_data( $event )`       | Normalised subscription row                    |
| `charge.refunded`            | `Refunds::get_refund_data( $event )`            | Refund amounts and status                      |
| `payment_intent.succeeded`   | `PaymentIntents::get_payment_details( $pi_id )` | Card brand, last4, country                     |

---

## Requirements

- PHP 8.2 or higher
- WordPress 6.9.1 or higher
- Stripe PHP SDK ^19.0 (API version 2025-09-30.clover)
- arraypress/wp-currencies ^1.0

## License

GPL-2.0-or-later