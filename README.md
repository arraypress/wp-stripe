# WordPress Stripe

A comprehensive WordPress library for working with the Stripe API. Provides a clean, WordPress-aware abstraction layer
over the Stripe PHP SDK with webhook handling, currency utilities, and convenience methods for common operations.

## Features

- 🔑 Flexible client with callback-based key resolution
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

## Installation

```bash
composer require arraypress/wp-stripe
```

## Client Setup

The client accepts configuration as strings or callables, making it compatible with any settings system:

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

// Direct SDK access when needed
$client->stripe()->products->retrieve( 'prod_xxx' );

// Key accessors
$client->get_secret_key();
$client->get_publishable_key();
$client->get_webhook_secret();
$client->get_mode();          // 'test' or 'live'

// State checks
$client->is_configured();     // true if keys are set and client initialized
$client->is_test_mode();      // true if in test mode
$client->is_live_mode();      // true if in live mode

// Test the connection (hits /v1/balance)
$result = $client->test_connection();
// [ 'success' => true, 'mode' => 'test', 'message' => 'Connected to Stripe successfully (test mode).' ]

// Reset (forces re-initialization on next call — useful when keys change at runtime)
$client->reset();
```

## Webhooks

Register event handlers and let the library handle signature verification, replay protection, and REST API endpoint
registration:

```php
use ArrayPress\Stripe\Webhooks;

$webhooks = new Webhooks( $client, [
    'namespace'    => 'myplugin/v1',
    'route'        => '/stripe/webhook',
    'tolerance'    => 300,            // Signature tolerance in seconds
    'replay_ttl'   => DAY_IN_SECONDS, // Replay protection window
    'log_callback' => fn( $message, $level ) => error_log( $message ),
] );

// Register handlers
$webhooks->on( 'checkout.session.completed', function( $event ) {
    $session = $event->data->object;
    // Process successful payment...
} );

$webhooks->on( 'invoice.payment_failed', function( $event ) {
    $invoice = $event->data->object;
    // Handle failed payment...
} );

$webhooks->on( 'customer.subscription.deleted', function( $event ) {
    $subscription = $event->data->object;
    // Handle cancellation...
} );

// Remove a handler
$webhooks->off( 'invoice.payment_failed' );

// Query registered handlers
$webhooks->get_registered_events(); // [ 'checkout.session.completed', ... ]
$webhooks->has_handler( 'invoice.paid' ); // false

// Register the REST API endpoint
$webhooks->register();

// Get the endpoint URL (for Stripe Dashboard configuration)
$url = $webhooks->get_endpoint_url();
// https://example.com/wp-json/myplugin/v1/stripe/webhook

// Manual verification and dispatch (for advanced use cases)
$event = $webhooks->verify_signature( $payload, $signature );
$webhooks->dispatch( $event );

// Replay protection
$webhooks->is_replay( 'evt_xxx' );       // Check if already processed
$webhooks->mark_processed( 'evt_xxx' );  // Mark as processed
$webhooks->clear_processed( 'evt_xxx' ); // Clear (for reprocessing)
```

WordPress action hooks are also fired for each event:

```php
// Handle specific events via WordPress actions
add_action( 'arraypress_stripe_webhook_checkout_session_completed', function( $event, $client ) {
    // Handle event...
}, 10, 2 );

// Handle all events
add_action( 'arraypress_stripe_webhook', function( $event, $type, $client ) {
    // Log all events...
}, 10, 3 );
```

## Products

```php
use ArrayPress\Stripe\Products;

$products = new Products( $client );

// Create
$product = $products->create( [
    'name'        => 'Premium Plan',
    'description' => 'Access to all features',
    'features'    => [ 'Unlimited downloads', 'Priority support', 'API access' ],
] );

// Retrieve
$product = $products->get( 'prod_xxx' );

// Update
$products->update( 'prod_xxx', [
    'name'        => 'Updated Name',
    'description' => 'Updated description',
] );

// Status management
$products->archive( 'prod_xxx' );   // Set inactive
$products->unarchive( 'prod_xxx' ); // Set active
$products->delete( 'prod_xxx' );    // Delete (only if no prices attached)

// Image from WordPress attachment
$products->set_image_from_attachment( 'prod_xxx', $attachment_id );
$products->clear_images( 'prod_xxx' );

// Search & list
$results = $products->search_by_name( 'Premium' );
$page    = $products->list( [ 'active' => true, 'limit' => 25 ] );
// $page['items'], $page['has_more'], $page['cursor']

// Bulk (auto-paginating)
$all = $products->all();
$products->each_batch( function( $items, $page ) {
    // Process batch...
}, [ 'active' => true ] );
```

## Prices

```php
use ArrayPress\Stripe\Prices;

$prices = new Prices( $client );

// One-time price (amount in dollars, auto-converted to cents)
$price = $prices->create( [
    'product' => 'prod_xxx',
    'amount'  => 9.99,
] );

// Recurring price
$price = $prices->create( [
    'product'  => 'prod_xxx',
    'amount'   => 19.99,
    'interval' => 'month',
] );

// Yearly with options
$price = $prices->create( [
    'product'        => 'prod_xxx',
    'amount'         => 99.99,
    'currency'       => 'GBP',
    'interval'       => 'year',
    'interval_count' => 1,
    'nickname'       => 'Annual Plan',
    'metadata'       => [ 'tier' => 'pro' ],
] );

// Retrieve
$price = $prices->get( 'price_xxx' );

// Update (prices are mostly immutable — only active, nickname, metadata can change)
$prices->update( 'price_xxx', [ 'nickname' => 'Premium Annual' ] );
$prices->deactivate( 'price_xxx' );
$prices->activate( 'price_xxx' );

// Replace a price (create new, deactivate old — since amounts are immutable)
$result = $prices->replace( 'price_old', [
    'product'  => 'prod_xxx',
    'amount'   => 14.99,
    'currency' => 'USD',
] );
// $result['new_price'], $result['old_price']

// List & search
$page           = $prices->list( [ 'active' => true ] );
$product_prices = $prices->list_by_product( 'prod_xxx' );

// Bulk (auto-paginating)
$all = $prices->all( [ 'expand' => [ 'data.product' ] ] );
$prices->each_batch( function( $items, $page ) {
    // Sync batch...
} );
```

## Coupons & Promotion Codes

Creates both the Stripe coupon (discount rules) and a customer-facing promotion code in a single call:

```php
use ArrayPress\Stripe\Coupons;

$coupons = new Coupons( $client );

// 25% off code
$result = $coupons->create( 'SUMMER25', [
    'percent_off'     => 25,
    'duration'        => 'once',
    'max_redemptions' => 100,
] );
// $result['coupon'], $result['promotion_code']

// $10 off code
$result = $coupons->create( 'SAVE10', [
    'amount_off' => 10.00,
    'currency'   => 'USD',
    'duration'   => 'forever',
] );

// Repeating discount, first-time customers only, $50 minimum
$result = $coupons->create( 'WELCOME15', [
    'percent_off'      => 15,
    'duration'         => 'repeating',
    'duration_months'  => 3,
    'first_time_only'  => true,
    'minimum_amount'   => 50.00,
    'minimum_currency' => 'USD',
] );

// Retrieve & list
$coupon = $coupons->get( 'coupon_id' );
$page   = $coupons->list( [ 'limit' => 25 ] );

// Manage
$coupons->delete( 'coupon_id' );
$coupons->deactivate_code( 'promo_xxx' );
```

## Checkout Sessions

```php
use ArrayPress\Stripe\Checkout;

$checkout = new Checkout( $client );

// Auto-detect mode from line items (recommended)
$session = $checkout->create_session(
    [ [ 'price' => 'price_xxx', 'quantity' => 1 ] ],
    [
        'success_url'           => Checkout::success_url( home_url( '/thank-you/' ) ),
        'cancel_url'            => home_url( '/cart/' ),
        'customer_email'        => 'user@example.com',
        'allow_promotion_codes' => true,
        'metadata'              => [ 'order_source' => 'website' ],
        // Pass known recurring price IDs for auto-detection (no extra API calls)
        'recurring_price_ids'   => [ 'price_monthly', 'price_yearly' ],
    ]
);

// Or use explicit mode
$session = $checkout->create( 'payment', $line_items, $args );
$session = $checkout->create( 'subscription', $line_items, $args );

// Retrieve
$session = $checkout->get( 'cs_xxx' );
$session = $checkout->get_expanded( 'cs_xxx' ); // with line items, payment intent, customer

// Get line items separately
$items = $checkout->get_line_items( 'cs_xxx' );

// Expire an open session
$checkout->expire( 'cs_xxx' );

// Get all data needed after checkout.session.completed (see Webhook Data Extraction)
$data = $checkout->get_completed_data( 'cs_xxx' );
```

## Customer Portal

```php
use ArrayPress\Stripe\Portal;

$portal = new Portal( $client );

// Create session and redirect
$session = $portal->create( 'cus_xxx', home_url( '/account/' ) );
wp_redirect( $session->url );

// Or get URL directly
$url = $portal->get_url( 'cus_xxx', home_url( '/account/' ) );
```

## Refunds

```php
use ArrayPress\Stripe\Refunds;

$refunds = new Refunds( $client );

// Full refund (accepts payment intent or charge ID)
$refund = $refunds->create( 'pi_xxx' );
$refund = $refunds->create( 'ch_xxx', [
    'reason' => 'requested_by_customer',
] );

// Partial refund ($5.00)
$refund = $refunds->create( 'pi_xxx', [
    'amount'   => 5.00,
    'currency' => 'USD',
] );

// Retrieve
$refund = $refunds->get( 'ref_xxx' );

// List refunds for a payment
$payment_refunds = $refunds->list_by_payment( 'pi_xxx' );
```

## Radar (Block/Allow Lists)

```php
use ArrayPress\Stripe\Radar;

$radar = new Radar( $client );

// Block an email, IP, customer, card fingerprint, or country
$radar->block_email( 'fraud@example.com' );
$radar->block_ip( '192.168.1.1' );
$radar->block_customer( 'cus_xxx' );
$radar->block_card( 'card_fingerprint_xxx' );
$radar->block_country( 'NG' );

// Allow trusted entities
$radar->allow_email( 'vip@example.com' );
$radar->allow_customer( 'cus_xxx' );

// Add to any list by alias or ID
$radar->add_item( 'custom_blocklist', 'some_value' );
$radar->add_item( 'rsl_xxx', 'some_value' );

// List items in a block list
$items = $radar->list_items( Radar::LIST_BLOCK_EMAIL );

// Remove an item
$radar->remove_item( 'rsli_xxx' );

// Custom list management
$list = $radar->create_list( 'vip_emails', 'VIP Customers', 'email' );
$list = $radar->get_list( 'rsl_xxx' );
$all  = $radar->list_all();
$radar->delete_list( 'rsl_xxx' );
```

## Subscriptions

```php
use ArrayPress\Stripe\Subscriptions;

$subscriptions = new Subscriptions( $client );

// Retrieve
$sub = $subscriptions->get( 'sub_xxx' );
$sub = $subscriptions->get_expanded( 'sub_xxx' ); // with payment method, invoice, product

// List by customer
$page   = $subscriptions->list_by_customer( 'cus_xxx' );
$active = $subscriptions->list_by_customer( 'cus_xxx', [ 'status' => 'active' ] );
$all    = $subscriptions->get_all_for_customer( 'cus_xxx' ); // auto-paginating

// Cancel at period end (recommended for user-initiated cancellations)
$subscriptions->cancel( 'sub_xxx' );

// Cancel immediately
$subscriptions->cancel_immediately( 'sub_xxx', [
    'prorate' => true,
    'invoice' => true,
] );

// Reactivate (undo cancel at period end, only while still active)
$subscriptions->reactivate( 'sub_xxx' );

// Pause / resume
$subscriptions->pause( 'sub_xxx' );
$subscriptions->pause( 'sub_xxx', 'void', strtotime( '+30 days' ) );
$subscriptions->resume( 'sub_xxx' );

// Change price (handles item lookup + proration)
$subscriptions->change_price( 'sub_xxx', 'price_new' );
$subscriptions->change_price( 'sub_xxx', 'price_new', [
    'proration_behavior' => 'none',
] );

// Update
$subscriptions->update_metadata( 'sub_xxx', [ 'plan' => 'enterprise' ] );
$subscriptions->update_payment_method( 'sub_xxx', 'pm_xxx' );
```

## Customers

```php
use ArrayPress\Stripe\Customers;

$customers = new Customers( $client );

// Create
$customer = $customers->create( [
    'email'    => 'user@example.com',
    'name'     => 'Jane Doe',
    'metadata' => [ 'source' => 'website' ],
] );

// Retrieve
$customer = $customers->get( 'cus_xxx' );
$customer = $customers->get_expanded( 'cus_xxx' ); // with subscriptions, invoices, sources

// Find by email (returns most recent match)
$customer = $customers->find_by_email( 'user@example.com' );

// Upsert — create if new, update if exists
$result = $customers->upsert_by_email( 'user@example.com', [
    'name' => 'Jane Doe',
] );
// $result['customer'], $result['created'] (bool)

// Update
$customers->update( 'cus_xxx', [ 'name' => 'Jane Smith' ] );

// Delete
$customers->delete( 'cus_xxx' );

// Notes (appends to description with UTC timestamp)
$customers->add_note( 'cus_xxx', 'VIP customer, handle with care.' );

// Payment methods
$methods = $customers->list_payment_methods( 'cus_xxx' );
$methods = $customers->list_payment_methods( 'cus_xxx', 'sepa_debit' );
$customers->set_default_payment_method( 'cus_xxx', 'pm_xxx' );

// List
$page = $customers->list( [ 'email' => 'user@example.com' ] );

// Bulk (auto-paginating)
$all = $customers->all();
$customers->each_batch( function( $items, $page ) {
    // Sync batch...
} );
```

## Invoices

```php
use ArrayPress\Stripe\Invoices;

$invoices = new Invoices( $client );

// Retrieve
$invoice = $invoices->get( 'in_xxx' );
$invoice = $invoices->get_expanded( 'in_xxx' ); // with charge, subscription, customer

// List
$history      = $invoices->list_by_customer( 'cus_xxx' );
$sub_invoices = $invoices->list_by_subscription( 'sub_xxx' );

// Upcoming invoice preview
$upcoming = $invoices->get_upcoming( 'cus_xxx' );
$upcoming = $invoices->get_upcoming( 'cus_xxx', 'sub_xxx' );

// Lifecycle management
$invoices->finalize( 'in_xxx' );           // Lock for payment
$invoices->send( 'in_xxx' );              // Email to customer
$invoices->pay( 'in_xxx' );               // Collect payment
$invoices->pay( 'in_xxx', 'pm_xxx' );     // Pay with specific method
$invoices->void( 'in_xxx' );              // Mark as void
$invoices->mark_uncollectible( 'in_xxx' ); // Write off

// Memo (customer-visible note on invoice PDF, draft invoices only)
$invoices->set_memo( 'in_xxx', 'Thank you for your purchase!' );
```

## Payment Intents

```php
use ArrayPress\Stripe\PaymentIntents;

$intents = new PaymentIntents( $client );

// Retrieve
$intent = $intents->get( 'pi_xxx' );
$intent = $intents->get_expanded( 'pi_xxx' ); // with payment method, latest charge

// Get card details (for order records)
$details = $intents->get_payment_details( 'pi_xxx' );
// [ 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2026,
//   'funding' => 'credit', 'country' => 'US', 'type' => 'card' ]

// Get customer country from billing address or card
$country = $intents->get_country( 'pi_xxx' ); // "US"

// Management
$intents->update_metadata( 'pi_xxx', [ 'order_id' => '12345' ] );
$intents->cancel( 'pi_xxx' );
$intents->cancel( 'pi_xxx', 'duplicate' ); // duplicate, fraudulent, requested_by_customer, abandoned
```

## Events

```php
use ArrayPress\Stripe\Events;

$events = new Events( $client );

// Retrieve a specific event
$event = $events->get( 'evt_xxx' );

// List (with optional filters)
$page = $events->list( [ 'type' => 'checkout.session.completed', 'limit' => 25 ] );

// List recent events (last N hours, useful for debugging missed webhooks)
$recent = $events->list_recent( 24 );
$recent = $events->list_recent( 6, 'invoice.payment_failed' );

// Reprocess a missed webhook event through your handlers
$events->reprocess( 'evt_xxx', $webhooks );
```

## Webhook Data Extraction

Each resource class provides a `get_*_data()` method that assembles everything
needed for webhook processing in a single call — no juggling multiple API requests:

```php
// checkout.session.completed — line items, payment details, customer, country, discount
$checkout = new Checkout( $client );
$data     = $checkout->get_completed_data( $session->id );
// $data['session_id'], $data['payment_intent_id'], $data['subscription_id'],
// $data['customer_id'], $data['customer_email'], $data['customer_name'],
// $data['total'], $data['currency'], $data['country'],
// $data['payment_brand'], $data['payment_last4'], $data['payment_type'],
// $data['mode'], $data['status'], $data['payment_status'],
// $data['metadata'], $data['is_test'], $data['line_items'], $data['discount']

// invoice.paid — renewal data (automatically skips initial subscription invoices)
$invoices = new Invoices( $client );
$data     = $invoices->get_renewal_data( $invoice->id );
// Returns null for billing_reason='subscription_create'
// $data['invoice_id'], $data['subscription_id'], $data['customer_id'],
// $data['total'], $data['subtotal'], $data['tax'], $data['currency'],
// $data['payment_brand'], $data['payment_last4'], $data['country'],
// $data['line_items'], $data['is_test']

// customer.subscription.* — normalized from any subscription lifecycle event
$subs = new Subscriptions( $client );
$data = $subs->get_event_data( $event );
// $data['subscription_id'], $data['customer_id'], $data['status'],
// $data['price_id'], $data['product_id'], $data['quantity'],
// $data['current_period_end'], $data['current_period_start'],
// $data['cancel_at_period_end'], $data['canceled_at'], $data['ended_at'],
// $data['currency'], $data['amount'], $data['interval'], $data['interval_count'],
// $data['latest_invoice'], $data['default_payment_method'],
// $data['is_test'], $data['metadata'], $data['event_type']

// charge.refunded — refund amounts and status
$refunds = new Refunds( $client );
$data    = $refunds->get_refund_data( $event );
// $data['charge_id'], $data['payment_intent_id'],
// $data['amount_refunded'], $data['amount_captured'], $data['currency'],
// $data['fully_refunded'], $data['reason'],
// $data['refund_id'], $data['latest_amount'], $data['status']
```

See `WEBHOOK-REFERENCE.md` for a complete mapping of events to database tables.

## Utilities

### Currency (via arraypress/wp-currencies)

Currency conversion and formatting is handled by the dedicated `arraypress/wp-currencies` library, which supports all
135 Stripe currencies with locale-aware formatting:

```php
use ArrayPress\Currencies\Currency;

// Format amounts (input is in smallest unit / cents)
Currency::format( 999, 'USD' );              // $9.99
Currency::format( 1000, 'JPY' );             // ¥1,000
Currency::format_localized( 999, 'EUR' );    // 9,99 €

// Convert between decimal and Stripe units
Currency::to_smallest_unit( 9.99, 'USD' );   // 999
Currency::from_smallest_unit( 999, 'USD' );  // 9.99

// With billing interval
Currency::format_with_interval( 999, 'USD', 'month' );  // $9.99 per month
```

### Billing Labels

```php
use ArrayPress\Stripe\Utilities;

Utilities::get_billing_label( 'month' );           // "Monthly"
Utilities::get_billing_label( 'month', 3 );        // "Every 3 months"
Utilities::get_billing_suffix( 'year' );           // "/yr"
Utilities::get_interval_options( true );           // ['one_time' => ..., 'day' => ...]
```

### Dashboard URLs

```php
Utilities::dashboard_url( 'products', true );      // test dashboard products page
Utilities::product_url( 'prod_xxx', true );        // test dashboard URL
Utilities::price_url( 'price_xxx' );               // live dashboard URL
Utilities::customer_url( 'cus_xxx' );
Utilities::payment_url( 'pi_xxx' );
Utilities::subscription_url( 'sub_xxx' );
Utilities::invoice_url( 'in_xxx' );
Utilities::coupon_url( 'coupon_xxx' );
```

### Image & ID Helpers

```php
Utilities::is_public_url( $url );                  // false for localhost
Utilities::get_image_extension( $response, $body ); // 'jpg', 'png', etc.
Utilities::get_id_type( 'prod_xxx' );              // 'product'
Utilities::get_id_type( 'pi_xxx' );                // 'payment_intent'
Utilities::is_valid_id( 'prod_xxx', 'prod_' );     // true
```

## Requirements

- PHP 8.2 or higher
- WordPress 6.9.1 or higher
- Stripe PHP SDK ^19.0 (API version 2025-09-30.clover)
- arraypress/wp-currencies ^1.0

## License

GPL-2.0-or-later
