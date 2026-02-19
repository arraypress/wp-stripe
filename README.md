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
- 🔗 Payment Links for shareable, reusable purchase URLs
- 🔄 Refund creation (full and partial) with auto-detection of payment intent vs charge
- 🏦 Customer portal session management
- 🛡️ Radar block/allow list management for fraud prevention
- 🧩 Stripe Connect: Express accounts, onboarding, and transfers
- ✨ Entitlement Features for product-based feature gating
- 🎫 Active Entitlements for checking customer feature access
- ⚖️ Tax rate management (manual tax collection)
- 🚚 Shipping rate management for checkout
- ⚔️ Dispute management with evidence submission
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
$coupons->reactivate_code( 'promo_xxx' );     // PromotionCode|WP_Error — re-enables a deactivated code
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

## Payment Links

`ArrayPress\Stripe\PaymentLinks`

Shareable, reusable purchase URLs. Unlike Checkout Sessions (created per-customer on demand), Payment Links are created
once and shared indefinitely via email, social media, QR codes, or invoices.

```php
use ArrayPress\Stripe\PaymentLinks;
$links = new PaymentLinks( $client );
```

### Creation & Updates

```php
// Create a payment link
$link = $links->create(
    [ [ 'price' => 'price_xxx', 'quantity' => 1 ] ],
    [
        'after_completion'      => 'redirect',
        'after_completion_data' => [ 'url' => home_url( '/thank-you/' ) ],
        'allow_promotion_codes' => true,
        'automatic_tax'         => [ 'enabled' => true ],
        'metadata'              => [ 'source' => 'email_campaign' ],
    ]
);

// All supported args:
$link = $links->create( $line_items, [
    'active'                      => true,
    'after_completion'            => 'redirect',          // or 'hosted_confirmation'
    'after_completion_data'       => [ 'url' => '...' ],  // or [ 'custom_message' => '...' ]
    'allow_promotion_codes'       => true,
    'automatic_tax'               => [ 'enabled' => true ],
    'billing_address_collection'  => 'required',
    'consent_collection'          => [],
    'custom_fields'               => [],                   // up to 3
    'custom_text'                 => [],
    'invoice_creation'            => [ 'enabled' => true ],
    'payment_method_collection'   => 'always',
    'payment_method_types'        => [ 'card', 'klarna' ],
    'phone_number_collection'     => [ 'enabled' => true ],
    'restrictions'                => [ 'completed_sessions' => [ 'limit' => 100 ] ],
    'shipping_address_collection' => [ 'allowed_countries' => [ 'US', 'GB' ] ],
    'shipping_options'            => [ [ 'shipping_rate' => 'shr_xxx' ] ],
    'subscription_data'           => [],
    'tax_id_collection'           => [ 'enabled' => true ],
    'transfer_data'               => [ 'destination' => 'acct_xxx' ],
    'metadata'                    => [],
] );

// Update an existing link
$links->update( 'plink_xxx', [
    'active'                => true,
    'allow_promotion_codes' => false,
    'metadata'              => [ 'updated' => '1' ],
] );
```

### Retrieval

```php
$link = $links->get( 'plink_xxx' ); // PaymentLink|WP_Error

// Get line items for a payment link
$items = $links->get_line_items( 'plink_xxx' );
// Returns [ 'items' => LineItem[], 'has_more' => bool ] | WP_Error
```

### Listing

```php
// Paginated list
$page = $links->list( [ 'active' => true, 'limit' => 25 ] );
// Returns [ 'items' => PaymentLink[], 'has_more' => bool, 'cursor' => string ] | WP_Error

// Active links as key/value for admin dropdowns
$options = $links->get_options();
// [ 'plink_xxx' => 'https://buy.stripe.com/...', ... ]

// Find all links that include a specific price
$matched = $links->list_by_price( 'price_xxx' );           // PaymentLink[]
$matched = $links->list_by_price( 'price_xxx', false );    // includes inactive

// Fetch ALL links (auto-paginating)
$all = $links->all( [ 'active' => true ] );
```

### Status Management

```php
$links->activate( 'plink_xxx' );    // Sets active = true
$links->deactivate( 'plink_xxx' );  // Sets active = false — customers see a deactivated page
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

## Accounts (Stripe Connect)

`ArrayPress\Stripe\Accounts`

Manages Stripe Connect Express connected accounts for affiliate and seller payouts. Handles account creation,
Stripe-hosted onboarding, status checking, and account management.

```php
use ArrayPress\Stripe\Accounts;
$accounts = new Accounts( $client );
```

### Typical Onboarding Flow

1. `create()` — Create the connected account
2. `create_onboarding_link()` — Generate the Stripe-hosted onboarding URL
3. Redirect affiliate to the URL
4. Affiliate completes Stripe's onboarding (KYC, bank details, tax forms)
5. Affiliate redirected to your `return_url`
6. `is_ready()` — Check `charges_enabled` + `payouts_enabled`
7. If not ready, `create_onboarding_link()` again (link is single-use)

Once ready, use Transfers to send commission payments.

### Creation & Retrieval

```php
// Create a new Express connected account
$account = $accounts->create( [
    'email'            => 'affiliate@example.com',
    'country'          => 'US',          // default 'US'
    'business_type'    => 'individual',  // or 'company'
    'individual'       => [ 'first_name' => 'Jane', 'last_name' => 'Doe' ],
    'business_profile' => [ 'url' => 'https://example.com' ],
    'metadata'         => [ 'affiliate_id' => '42' ],
    'capabilities'     => [ 'transfers' => [ 'requested' => true ] ],
] );

// Retrieve
$account = $accounts->get( 'acct_xxx' ); // Account|WP_Error

// Update
$accounts->update( 'acct_xxx', [
    'email'    => 'new@example.com',
    'metadata' => [ 'tier' => 'gold' ],
] );

// Delete (only if no active balance — use with extreme caution)
$accounts->delete( 'acct_xxx' ); // true|WP_Error

// Paginated list
$page = $accounts->list( [ 'limit' => 25 ] );
// Returns [ 'items' => Account[], 'has_more' => bool, 'cursor' => string ] | WP_Error
```

### Onboarding

```php
// Create a Stripe-hosted onboarding link (single-use, expires quickly)
$link = $accounts->create_onboarding_link(
    'acct_xxx',
    home_url( '/affiliate/onboarding-complete/' ),  // return_url
    home_url( '/affiliate/onboarding-refresh/' ),   // refresh_url (regenerate link here)
    'account_onboarding'                             // or 'account_update' for existing accounts
);
wp_redirect( $link->url );

// Convenience wrapper — returns just the URL
$url = $accounts->get_onboarding_url(
    'acct_xxx',
    home_url( '/affiliate/onboarding-complete/' ),
    home_url( '/affiliate/onboarding-refresh/' )
);
// string|WP_Error
```

### Status Checking

```php
// Quick boolean check — is the account ready to receive payouts?
$ready = $accounts->is_ready( 'acct_xxx' ); // bool|WP_Error

// Detailed status summary for admin UI
$status = $accounts->get_status( 'acct_xxx' );
// Returns array|WP_Error:
// account_id, charges_enabled, payouts_enabled, is_ready,
// details_submitted, requirements (currently_due, eventually_due,
// past_due, disabled_reason), email
```

---

## Transfers (Stripe Connect)

`ArrayPress\Stripe\Transfers`

Sends commission payments from your platform balance to connected accounts. The connected account must be fully
onboarded (`is_ready() = true`) before you can transfer to them.

```php
use ArrayPress\Stripe\Transfers;
$transfers = new Transfers( $client );
```

### Creation & Retrieval

```php
// Transfer funds (amount in major units, auto-converted)
$transfer = $transfers->create( 'acct_xxx', 49.99, 'USD', [
    'description'        => 'January commission',
    'transfer_group'     => 'commissions_2026_01',
    'source_transaction' => 'ch_xxx',    // optional: tie to a specific charge
    'metadata'           => [ 'affiliate_id' => '42' ],
] );

// Retrieve
$transfer = $transfers->get( 'tr_xxx' ); // Transfer|WP_Error

// Update metadata or description
$transfers->update( 'tr_xxx', [
    'description' => 'Updated description',
    'metadata'    => [ 'adjusted' => 'true' ],
] );
```

### Listing

```php
// Paginated list
$page = $transfers->list( [ 'limit' => 25 ] );
// Returns [ 'items' => Transfer[], 'has_more' => bool, 'cursor' => string ] | WP_Error

// List by connected account
$page = $transfers->list_by_account( 'acct_xxx' );

// List by transfer group
$page = $transfers->list_by_group( 'commissions_2026_01' );
```

### Reversals

```php
// Full reversal
$reversal = $transfers->reverse( 'tr_xxx' ); // TransferReversal|WP_Error

// Partial reversal ($10.00)
$reversal = $transfers->reverse( 'tr_xxx', 10.00, 'USD', [
    'description' => 'Partial clawback',
    'metadata'    => [ 'reason' => 'order_cancelled' ],
] );
```

### Bulk Payouts

```php
// Send commissions to multiple accounts in one call
$result = $transfers->bulk_payout( [
    [ 'account_id' => 'acct_aaa', 'amount' => 49.99, 'metadata' => [ 'affiliate' => '1' ] ],
    [ 'account_id' => 'acct_bbb', 'amount' => 125.00 ],
    [ 'account_id' => 'acct_ccc', 'amount' => 30.50 ],
], 'USD', 'commissions_2026_02' );

// Returns array:
// $result['transfer_group'] — the group identifier
// $result['succeeded']      — Transfer[] that completed
// $result['failed']         — [ 'account_id', 'amount', 'error' (WP_Error) ]
// $result['total_sent']     — total transferred in smallest unit
```

---

## Features (Entitlement Features)

`ArrayPress\Stripe\Features`

Manages account-level feature definitions that can be attached to products. When a customer purchases a product with
features attached, Stripe automatically creates active entitlements for those features.

```php
use ArrayPress\Stripe\Features;
$features = new Features( $client );
```

### Feature Management

```php
// Create a feature (lookup_key is immutable after creation)
$feature = $features->create( 'API Access', 'api_access', [
    'metadata' => [ 'tier' => 'pro' ],
] );

// Retrieve
$feature = $features->get( 'feat_xxx' ); // Feature|WP_Error

// Update (only name and metadata — lookup_key is immutable)
$features->update( 'feat_xxx', [
    'name'     => 'Full API Access',
    'metadata' => [ 'updated' => '1' ],
] );

// Archive (cannot be attached to new products; existing attachments unaffected)
$features->archive( 'feat_xxx' ); // Feature|WP_Error
```

### Listing

```php
// Paginated list
$page = $features->list( [ 'limit' => 25 ] );
// Returns [ 'items' => Feature[], 'has_more' => bool, 'cursor' => string ] | WP_Error

// Plain stdClass objects
$page = $features->list_serialized();

// Key/value for admin dropdowns
$options = $features->get_options();
// [ 'feat_xxx' => 'API Access (api_access)', ... ]
```

### Product Attachment

```php
// Attach a feature to a product
$pf = $features->attach_to_product( 'prod_xxx', 'feat_xxx' );
// Returns ProductFeature|WP_Error

// Detach a feature from a product
$features->detach_from_product( 'prod_xxx', 'pf_xxx' ); // true|WP_Error

// List all features attached to a product
$result = $features->list_by_product( 'prod_xxx' );
// Returns [ 'items' => ProductFeature[], 'has_more' => bool, 'cursor' => string ] | WP_Error
```

### Bulk Retrieval

```php
// Fetch ALL features (auto-paginating)
$all = $features->all();

// Process in batches — return false from callback to stop early
$total = $features->each_batch( function( $items, $page ) {
    foreach ( $items as $feature ) { /* sync */ }
} );
```

---

## Entitlements (Active Entitlements)

`ArrayPress\Stripe\Entitlements`

Read-only class for querying which features a customer currently has access to. Entitlements are managed automatically
by
Stripe — they are created when a customer purchases a product with features attached, and removed when a subscription
lapses.

**Performance note:** Stripe recommends persisting active entitlements in your own database rather than querying this
API
on every request. Sync them via the `customer.entitlement.active_entitlement_summary.updated` webhook event.

```php
use ArrayPress\Stripe\Entitlements;
$entitlements = new Entitlements( $client );
```

### Querying

```php
// List all active entitlements for a customer
$result = $entitlements->list_by_customer( 'cus_xxx' );
// Returns [ 'items' => ActiveEntitlement[], 'has_more' => bool, 'cursor' => string ] | WP_Error

// Retrieve a single entitlement
$entitlement = $entitlements->get( 'ent_xxx' ); // ActiveEntitlement|WP_Error

// Get all lookup keys (the value you want to persist in your DB)
$keys = $entitlements->get_lookup_keys( 'cus_xxx' );
// Returns string[]|WP_Error — e.g. [ 'api_access', 'priority_support' ]
```

### Feature Access Checks

```php
// Check a single feature
$has = $entitlements->has_feature( 'cus_xxx', 'api_access' ); // bool|WP_Error

// Check ALL features (must have every one)
$has = $entitlements->has_all_features( 'cus_xxx', [ 'api_access', 'priority_support' ] );

// Check ANY feature (must have at least one)
$has = $entitlements->has_any_feature( 'cus_xxx', [ 'api_access', 'priority_support' ] );
```

### Database Sync

```php
// Get a summary suitable for persisting against a user record
$summary = $entitlements->get_summary( 'cus_xxx' );
// Returns array|WP_Error:
// customer_id, lookup_keys (string[]), count, updated_at (Unix timestamp)
```

---

## Tax Rates

`ArrayPress\Stripe\TaxRates`

Manages manual tax rates for applying to checkout sessions and invoices. For fully automatic tax collection, pass
`automatic_tax: [ 'enabled' => true ]` in your Checkout session instead — no rate management needed in that flow.

Note: Once created, a tax rate's `percentage` and `inclusive` flag are immutable. Use `replace()` to correct either
value.

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
    'inclusive'     => false,
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
    'inclusive'     => true,
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
// Update (only display_name, description, jurisdiction, metadata, and active are mutable)
$tax_rates->update( 'txr_xxx', [
    'display_name' => 'Updated Tax',
    'description'  => 'Updated sales tax for CA',
    'jurisdiction' => 'California',
    'metadata'     => [ 'updated' => 'true' ],
    'active'       => true,
] );

// Archive / Unarchive
$tax_rates->archive( 'txr_xxx' );   // Sets active = false
$tax_rates->unarchive( 'txr_xxx' ); // Sets active = true
```

### Tax Rate Replacement

```php
// Since percentage and inclusive are immutable, replace creates a new rate and archives the old
$result = $tax_rates->replace( 'txr_old', [
    'display_name' => 'Updated VAT',
    'percentage'   => 21.0,
    'inclusive'     => true,
    'country'      => 'GB',
    'jurisdiction' => 'UK VAT',
    'tax_type'     => 'vat',
] );
// $result['new_rate'] — the new TaxRate object
// $result['old_rate'] — the archived old TaxRate object
```

### Bulk Retrieval

```php
// Fetch ALL tax rates (auto-paginating)
$all = $tax_rates->all( [ 'active' => true ] );

// Process in batches
$total = $tax_rates->each_batch( function( $items, $page ) {
    foreach ( $items as $rate ) { /* sync */ }
}, [ 'active' => true ] );
```

---

## Shipping Rates

`ArrayPress\Stripe\ShippingRates`

Manages shipping rate options for Checkout sessions. Shipping rates can be one-time fixed amounts or free, and are
passed via the `shipping_options` parameter when creating a Checkout session.

```php
use ArrayPress\Stripe\ShippingRates;
$shipping = new ShippingRates( $client );
```

### Creation

```php
// Fixed amount shipping rate (amount in major units, auto-converted)
$rate = $shipping->create( [
    'display_name' => 'Standard Shipping',
    'amount'       => 5.99,
    'currency'     => 'USD',
    'type'         => 'fixed_amount',   // currently the only type
    'metadata'     => [ 'speed' => 'standard' ],
] );

// Free shipping
$rate = $shipping->create( [
    'display_name'         => 'Free Shipping',
    'amount'               => 0,
    'currency'             => 'USD',
    'delivery_estimate'    => [
        'minimum' => [ 'unit' => 'business_day', 'value' => 5 ],
        'maximum' => [ 'unit' => 'business_day', 'value' => 7 ],
    ],
    'tax_behavior'         => 'exclusive',  // 'exclusive', 'inclusive', 'unspecified'
    'tax_code'             => 'txcd_92010001',
] );

// Use in checkout:
$checkout->create( $line_items, [
    'shipping_options' => [
        [ 'shipping_rate' => $rate->id ],
    ],
] );
```

### Retrieval

```php
$rate = $shipping->get( 'shr_xxx' ); // ShippingRate|WP_Error

// Paginated list
$page = $shipping->list( [ 'active' => true, 'limit' => 25 ] );
// Returns [ 'items' => ShippingRate[], 'has_more' => bool, 'cursor' => string ] | WP_Error

// Plain stdClass objects
$page = $shipping->list_serialized( [ 'active' => true ] );

// Key/value for admin dropdowns
$options = $shipping->get_options();
// [ 'shr_xxx' => 'Standard Shipping ($5.99 USD)', ... ]
```

### Updates & Status Management

```php
// Update (only display_name, metadata, active, tax_behavior are mutable)
$shipping->update( 'shr_xxx', [
    'display_name' => 'Express Shipping',
    'metadata'     => [ 'speed' => 'express' ],
    'active'       => true,
] );

// Archive / Unarchive
$shipping->archive( 'shr_xxx' );   // Sets active = false
$shipping->unarchive( 'shr_xxx' ); // Sets active = true
```

### Bulk Retrieval

```php
// Fetch ALL shipping rates (auto-paginating)
$all = $shipping->all( [ 'active' => true ] );

// Process in batches
$total = $shipping->each_batch( function( $items, $page ) {
    foreach ( $items as $rate ) { /* sync */ }
}, [ 'active' => true ] );
```

---

## Disputes

`ArrayPress\Stripe\Disputes`

Manages chargebacks and payment disputes. When a customer disputes a payment with their bank, Stripe creates a Dispute
object. You can submit evidence to fight the dispute, or close it to accept the chargeback.

```php
use ArrayPress\Stripe\Disputes;
$disputes = new Disputes( $client );
```

### Retrieval

```php
$dispute = $disputes->get( 'dp_xxx' ); // Dispute|WP_Error

// Paginated list
$page = $disputes->list( [ 'limit' => 25 ] );
// Returns [ 'items' => Dispute[], 'has_more' => bool, 'cursor' => string ] | WP_Error

// Filter by charge or payment intent
$page = $disputes->list_by_charge( 'ch_xxx' );
$page = $disputes->list_by_payment_intent( 'pi_xxx' );

// Disputes needing a response (status = 'needs_response')
$page = $disputes->list_needs_response( 25 );
```

### Evidence Management

```php
// Stage evidence (save without submitting — lets you build incrementally)
$disputes->stage_evidence( 'dp_xxx', [
    'customer_name'          => 'Jane Doe',
    'customer_email_address' => 'jane@example.com',
    'product_description'    => 'Premium Plan — annual subscription',
    'uncategorized_text'     => 'Customer accessed the product on 6 separate occasions...',
] );

// Submit evidence (finalises and sends to card network — IRREVERSIBLE)
$disputes->submit_evidence( 'dp_xxx', [
    'customer_name'          => 'Jane Doe',
    'customer_email_address' => 'jane@example.com',
    'product_description'    => 'Premium Plan — annual subscription',
    'uncategorized_text'     => 'Customer accessed the product on 6 separate occasions...',
] );

// Evidence fields (all optional — submit what you have):
// customer_name, customer_email_address, customer_purchase_ip,
// product_description, uncategorized_text,
// billing_address, shipping_address, shipping_date, shipping_carrier, shipping_tracking_number,
// receipt, refund_policy, refund_policy_disclosure, refund_refusal_explanation,
// cancellation_policy, cancellation_policy_disclosure, cancellation_rebuttal,
// access_activity_log, service_date, service_documentation,
// customer_communication, duplicate_charge_documentation, duplicate_charge_explanation,
// duplicate_charge_id
```

### Close a Dispute

```php
// Accept the chargeback — funds returned to customer permanently
$disputes->close( 'dp_xxx' ); // Dispute|WP_Error
```

### Webhook Data Extraction

```php
// In a charge.dispute.created handler
$data = $disputes->get_event_data( $event );
// Returns array: dispute_id, charge_id, payment_intent_id, amount,
//                currency, reason, status, evidence_due_by, is_test
```

---

## Radar (Fraud Prevention)

`ArrayPress\Stripe\Radar`

Manages Stripe Radar block and allow lists for fraud prevention.

```php
use ArrayPress\Stripe\Radar;
$radar = new Radar( $client );
```

### Block Lists

```php
// Block by email
$radar->block_email( 'fraud@example.com' );

// Block by card fingerprint (from payment details)
$radar->block_card( 'card_fingerprint_xxx' );

// Block by IP address (IPv4 or IPv6)
$radar->block_ip( '198.51.100.42' );

// Block by customer ID
$radar->block_customer( 'cus_xxx' );
```

### Allow Lists

```php
// Allow by email
$radar->allow_email( 'vip@example.com' );

// Allow by card fingerprint
$radar->allow_card( 'card_fingerprint_xxx' );

// Allow by customer ID
$radar->allow_customer( 'cus_xxx' );
```

### List Management

```php
// Check if a value is blocked or allowed
$radar->is_blocked( 'fraud@example.com', 'email' );  // bool|WP_Error
$radar->is_allowed( 'vip@example.com', 'email' );    // bool|WP_Error

// List items
$page = $radar->list_blocked( 'email', 25 );          // ValueListItem[]|WP_Error
$page = $radar->list_allowed( 'email', 25 );           // ValueListItem[]|WP_Error

// Remove from list
$radar->remove_blocked( 'fraud@example.com', 'email' );  // true|WP_Error
$radar->remove_allowed( 'vip@example.com', 'email' );    // true|WP_Error
```

---

## Static Utility Classes

The following classes in `ArrayPress\Stripe\Helpers\` are stateless and require no client instance. They provide
formatting, validation, label generation, and option arrays for building admin UIs.

### Format

`ArrayPress\Stripe\Helpers\Format`

```php
use ArrayPress\Stripe\Helpers\Format;

// Currency formatting (uses arraypress/wp-currencies)
Format::amount( 1999, 'USD' );          // '$19.99'
Format::amount( 1999, 'JPY' );          // '¥1,999' (zero-decimal currency)
Format::zero_decimal( 19.99, 'USD' );   // 1999 (convert major → minor units)
Format::zero_decimal( 1999, 'JPY' );    // 1999 (already in minor units)
Format::from_zero_decimal( 1999, 'USD' ); // 19.99 (minor → major)

// Interval / billing cycle
Format::interval( 'month', 1 );         // 'Monthly'
Format::interval( 'month', 3 );         // 'Every 3 months'
Format::interval( 'year', 1 );          // 'Yearly'

// Price display (combines amount + interval)
Format::price_amount( 1999, 'USD', 'month', 1 );   // '$19.99 / month'
Format::price_amount( 9999, 'USD', 'year', 1 );    // '$99.99 / year'
Format::price_amount( 999, 'USD' );                 // '$9.99' (one-time)

// Stripe object formatting
Format::card( 'visa', '4242' );          // 'Visa ending in 4242'
Format::card( 'amex', '1234' );          // 'American Express ending in 1234'
Format::status( 'active' );              // 'Active'
Format::status( 'past_due' );            // 'Past Due'
Format::dispute_reason( 'fraudulent' );  // 'Fraudulent'
Format::dispute_status( 'needs_response' ); // 'Needs Response'

// Date helpers
Format::timestamp( 1717200000 );         // '2025-06-01 00:00:00' (Y-m-d H:i:s)
Format::timestamp( 1717200000, 'M j, Y' ); // 'Jun 1, 2025'
Format::period( 1717200000, 1719878400 ); // 'Jun 1, 2025 – Jul 2, 2025'
```

### Validate

`ArrayPress\Stripe\Helpers\Validate`

```php
use ArrayPress\Stripe\Helpers\Validate;

// ID format validation
Validate::id( 'cus_xxx', 'cus' );    // true
Validate::id( 'invalid', 'cus' );    // false

// Detect ID type from prefix
Validate::get_id_type( 'cus_xxx' );    // 'customer'
Validate::get_id_type( 'sub_xxx' );    // 'subscription'
Validate::get_id_type( 'pi_xxx' );     // 'payment_intent'
Validate::get_id_type( 'ch_xxx' );     // 'charge'
Validate::get_id_type( 'in_xxx' );     // 'invoice'
Validate::get_id_type( 'prod_xxx' );   // 'product'
Validate::get_id_type( 'price_xxx' );  // 'price'
Validate::get_id_type( 'pm_xxx' );     // 'payment_method'
Validate::get_id_type( 'evt_xxx' );    // 'event'
Validate::get_id_type( 'cs_xxx' );     // 'checkout_session'
Validate::get_id_type( 'dp_xxx' );     // 'dispute'
Validate::get_id_type( 'plink_xxx' );  // 'payment_link'
Validate::get_id_type( 'acct_xxx' );   // 'account'
Validate::get_id_type( 'tr_xxx' );     // 'transfer'
Validate::get_id_type( 'shr_xxx' );    // 'shipping_rate'
Validate::get_id_type( 'txr_xxx' );    // 'tax_rate'
Validate::get_id_type( 'feat_xxx' );   // 'feature'
Validate::get_id_type( 'unknown' );    // null

// Key format validation
Validate::secret_key( 'sk_test_xxx' );       // true
Validate::publishable_key( 'pk_live_xxx' );  // true
Validate::webhook_secret( 'whsec_xxx' );     // true

// Stripe object type checks
Validate::is_payment_intent( 'pi_xxx' );  // true
Validate::is_charge( 'ch_xxx' );          // true
```

### Labels

`ArrayPress\Stripe\Helpers\Labels`

Human-readable labels for Stripe constants — useful for admin tables and dropdowns.

```php
use ArrayPress\Stripe\Helpers\Labels;

// Status labels
Labels::subscription_status( 'past_due' );   // 'Past Due'
Labels::invoice_status( 'uncollectible' );   // 'Uncollectible'
Labels::payment_status( 'requires_action' ); // 'Requires Action'
Labels::dispute_status( 'needs_response' );  // 'Needs Response'
Labels::dispute_reason( 'product_not_received' ); // 'Product Not Received'

// Billing intervals
Labels::get_interval_text( 'month', 1 );     // 'Monthly'
Labels::get_interval_text( 'year', 1 );      // 'Yearly'
Labels::get_interval_text( 'week', 2 );      // 'Every 2 weeks'

// Duration labels
Labels::duration( 'once' );                  // 'Once'
Labels::duration( 'repeating' );             // 'Repeating'
Labels::duration( 'forever' );               // 'Forever'

// Card brands
Labels::card_brand( 'visa' );                // 'Visa'
Labels::card_brand( 'amex' );                // 'American Express'

// Tax types
Labels::tax_type( 'vat' );                   // 'VAT'
Labels::tax_type( 'sales_tax' );             // 'Sales Tax'
Labels::tax_type( 'gst' );                   // 'GST'
```

### Options

`ArrayPress\Stripe\Helpers\Options`

Pre-built key/value arrays for `<select>` dropdowns in WordPress admin pages. All methods return
`[ 'value' => 'Label' ]`
arrays.

```php
use ArrayPress\Stripe\Helpers\Options;

// Intervals
Options::intervals();               // [ 'day' => 'Daily', 'week' => 'Weekly', ... ]

// Subscription statuses
Options::subscription_statuses();   // [ 'active' => 'Active', 'past_due' => 'Past Due', ... ]

// Invoice statuses
Options::invoice_statuses();        // [ 'draft' => 'Draft', 'open' => 'Open', ... ]

// Payment method types
Options::payment_method_types();    // [ 'card' => 'Card', 'sepa_debit' => 'SEPA Debit', ... ]

// Currencies (via arraypress/wp-currencies)
Options::currencies();              // [ 'USD' => 'US Dollar', 'EUR' => 'Euro', ... ]
Options::zero_decimal_currencies(); // [ 'JPY' => 'Japanese Yen', ... ]

// Tax types
Options::tax_types();               // [ 'vat' => 'VAT', 'gst' => 'GST', ... ]

// Tax behaviors
Options::tax_behaviors();           // [ 'exclusive' => 'Exclusive', 'inclusive' => 'Inclusive', ... ]

// Refund reasons
Options::refund_reasons();          // [ 'duplicate' => 'Duplicate', ... ]

// Coupon durations
Options::coupon_durations();        // [ 'once' => 'Once', 'repeating' => 'Repeating', 'forever' => 'Forever' ]

// Card brands
Options::card_brands();             // [ 'visa' => 'Visa', 'mastercard' => 'Mastercard', ... ]

// Dispute statuses
Options::dispute_statuses();        // [ 'needs_response' => 'Needs Response', ... ]

// Dispute reasons
Options::dispute_reasons();         // [ 'fraudulent' => 'Fraudulent', ... ]

// Proration behaviors
Options::proration_behaviors();     // [ 'create_prorations' => 'Create Prorations', ... ]
```

### Dashboard

`ArrayPress\Stripe\Helpers\Dashboard`

Generates direct links to Stripe Dashboard pages — respects test/live mode.

```php
use ArrayPress\Stripe\Helpers\Dashboard;

// Object links — all accept optional $test_mode bool (default true)
Dashboard::customer( 'cus_xxx' );          // https://dashboard.stripe.com/test/customers/cus_xxx
Dashboard::subscription( 'sub_xxx' );      // ...subscriptions/sub_xxx
Dashboard::invoice( 'in_xxx' );            // ...invoices/in_xxx
Dashboard::payment_intent( 'pi_xxx' );     // ...payments/pi_xxx
Dashboard::product( 'prod_xxx' );          // ...products/prod_xxx
Dashboard::price( 'price_xxx' );           // ...prices/price_xxx
Dashboard::coupon( 'coupon_id' );          // ...coupons/coupon_id
Dashboard::event( 'evt_xxx' );             // ...events/evt_xxx
Dashboard::refund( 'ref_xxx' );            // ...refunds/ref_xxx (actually payments/ref_xxx)
Dashboard::charge( 'ch_xxx' );             // ...payments/ch_xxx
Dashboard::tax_rate( 'txr_xxx' );          // ...tax-rates/txr_xxx
Dashboard::shipping_rate( 'shr_xxx' );     // ...shipping-rates/shr_xxx
Dashboard::feature( 'feat_xxx' );          // ...entitlements/features/feat_xxx
Dashboard::dispute( 'dp_xxx' );            // ...disputes/dp_xxx
Dashboard::payment_link( 'plink_xxx' );    // ...payment-links/plink_xxx
Dashboard::transfer( 'tr_xxx' );           // ...connect/transfers/tr_xxx
Dashboard::connect_account( 'acct_xxx' );  // ...connect/accounts/acct_xxx

// Section links
Dashboard::customers();                    // ...customers
Dashboard::subscriptions();                // ...subscriptions
Dashboard::invoices();                     // ...invoices
Dashboard::products();                     // ...products
Dashboard::coupons();                      // ...coupons
Dashboard::events();                       // ...events
Dashboard::webhooks();                     // ...webhooks
Dashboard::api_keys();                     // ...apikeys
Dashboard::payments();                     // ...payments

// Auto-detect from any Stripe ID
Dashboard::link( 'cus_xxx' );             // Customer link
Dashboard::link( 'sub_xxx' );             // Subscription link
Dashboard::link( 'pi_xxx' );              // Payment Intent link
Dashboard::link( 'dp_xxx' );              // Dispute link

// Live mode
Dashboard::customer( 'cus_xxx', false );   // https://dashboard.stripe.com/customers/cus_xxx
```

### Utilities

`ArrayPress\Stripe\Helpers\Utilities`

General-purpose helpers.

```php
use ArrayPress\Stripe\Helpers\Utilities;

// Interval formatting (also available via Labels::get_interval_text)
Utilities::get_interval_text( 'month', 1 );  // 'Monthly'
Utilities::get_interval_text( 'year', 1 );   // 'Yearly'

// Generate a unique idempotency key (useful for retryable API calls)
Utilities::idempotency_key();                // 'stripe_663a1f2e3b4c5'

// Serialise a Stripe object to a plain stdClass (safe for wp_cache, transients, REST)
$plain = Utilities::serialize_object( $stripe_customer );
```

---

## Webhook Data Extraction Reference

Quick reference for extracting normalised data from webhook events:

| Webhook Event                                                      | Method                            | Key Data Returned                                                                                      |
|--------------------------------------------------------------------|-----------------------------------|--------------------------------------------------------------------------------------------------------|
| `checkout.session.completed`                                       | `Checkout::get_completed_data()`  | session_id, customer, total, currency, line_items[], discount[], payment details, metadata             |
| `invoice.paid`                                                     | `Invoices::get_renewal_data()`    | invoice_id, customer, total, subtotal, tax, line_items[] with periods, payment details, billing_reason |
| `customer.subscription.created/updated/deleted/paused/resumed/...` | `Subscriptions::get_event_data()` | subscription_id, customer_id, status, price_id, product_id, period, cancel flags, payment method       |
| `charge.refunded`                                                  | `Refunds::get_refund_data()`      | charge_id, payment_intent_id, amounts, fully_refunded flag, reason, refund_id                          |
| `charge.dispute.created/updated/closed`                            | `Disputes::get_event_data()`      | dispute_id, charge_id, payment_intent_id, amount, reason, status, evidence_due_by                      |
| `customer.entitlement.active_entitlement_summary.updated`          | `Entitlements::get_summary()`     | customer_id, lookup_keys[], count, updated_at                                                          |

---

## Currency Support

Currency handling is powered by `arraypress/wp-currencies`. Zero-decimal currencies (JPY, KRW, etc.) are automatically
detected. All amount parameters in this library accept **major currency units** (e.g. `9.99` not `999`) — the library
handles conversion internally.

```php
// Check if a currency is zero-decimal
\ArrayPress\Stripe\Helpers\Format::zero_decimal( 19.99, 'USD' ); // 1999
\ArrayPress\Stripe\Helpers\Format::zero_decimal( 1999, 'JPY' );  // 1999 (no conversion)
```

---

## Error Handling

All API methods return `WP_Error` on failure with structured error codes:

```php
$result = $products->create( [ 'name' => '' ] );

if ( is_wp_error( $result ) ) {
    $code    = $result->get_error_code();    // 'stripe_error' or 'stripe_not_configured'
    $message = $result->get_error_message(); // Human-readable message from Stripe
    $data    = $result->get_error_data();    // [ 'status' => 400, 'type' => 'invalid_request_error', ... ]
}
```

Common error codes:

| Code                    | Meaning                                                 |
|-------------------------|---------------------------------------------------------|
| `stripe_not_configured` | Client not initialised or keys missing                  |
| `stripe_error`          | Stripe API returned an error                            |
| `invalid_id`            | Malformed Stripe object ID                              |
| `missing_param`         | Required parameter not provided                         |
| `partial_replace`       | Replace created new object but failed to deactivate old |

---

## License

GPL-2.0-or-later