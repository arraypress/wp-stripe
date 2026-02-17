# Webhook Integration Reference

How wp-stripe webhook events map to SugarCart database tables.

## SugarCart Bootstrap

```php
use ArrayPress\Stripe\Client;
use ArrayPress\Stripe\Webhooks;
use ArrayPress\Stripe\Checkout;
use ArrayPress\Stripe\PaymentIntents;
use ArrayPress\Stripe\Customers;

// 1. Create client
$client = new Client( [
    'secret_key'      => fn() => sugarcart_get_option( 'secret_key' ),
    'publishable_key' => fn() => sugarcart_get_option( 'publishable_key' ),
    'webhook_secret'  => fn() => sugarcart_get_option( 'webhook_secret' ),
    'mode'            => fn() => sugarcart_get_option( 'mode', 'test' ),
] );

// 2. Register webhooks
$webhooks = new Webhooks( $client, [
    'namespace' => 'sugarcart/v1',
    'route'     => '/stripe/webhook',
] );

// Checkout flow
$webhooks->on( 'checkout.session.completed', 'sugarcart_handle_checkout_completed' );
$webhooks->on( 'checkout.session.expired',   'sugarcart_handle_checkout_expired' );

// Payment flow
$webhooks->on( 'payment_intent.succeeded',      'sugarcart_handle_payment_succeeded' );
$webhooks->on( 'payment_intent.payment_failed', 'sugarcart_handle_payment_failed' );

// Subscription lifecycle
$webhooks->on( 'customer.subscription.created', 'sugarcart_handle_subscription_created' );
$webhooks->on( 'customer.subscription.updated', 'sugarcart_handle_subscription_updated' );
$webhooks->on( 'customer.subscription.deleted', 'sugarcart_handle_subscription_deleted' );
$webhooks->on( 'customer.subscription.paused',  'sugarcart_handle_subscription_paused' );
$webhooks->on( 'customer.subscription.resumed', 'sugarcart_handle_subscription_resumed' );

// Invoice / renewal
$webhooks->on( 'invoice.paid',           'sugarcart_handle_invoice_paid' );
$webhooks->on( 'invoice.payment_failed', 'sugarcart_handle_invoice_failed' );

// Refunds
$webhooks->on( 'charge.refunded', 'sugarcart_handle_charge_refunded' );

// Stripe Dashboard edits (sync)
$webhooks->on( 'product.updated', 'sugarcart_handle_product_updated' );
$webhooks->on( 'product.deleted', 'sugarcart_handle_product_deleted' );
$webhooks->on( 'price.updated',   'sugarcart_handle_price_updated' );
$webhooks->on( 'price.deleted',   'sugarcart_handle_price_deleted' );
$webhooks->on( 'coupon.updated',  'sugarcart_handle_coupon_updated' );
$webhooks->on( 'coupon.deleted',  'sugarcart_handle_coupon_deleted' );

// Customer sync
$webhooks->on( 'customer.updated', 'sugarcart_handle_customer_updated' );
$webhooks->on( 'customer.deleted', 'sugarcart_handle_customer_deleted' );

$webhooks->register();
```

---

## Event → Table Mapping

### checkout.session.completed
**The main event.** Creates customer, order, order items, and subscription (if applicable).

```
Tables affected: customers, orders, order_items, subscriptions, prices (sales_count/revenue)
Library method:  Checkout::get_completed_data()
```

```php
function sugarcart_handle_checkout_completed( $event ) {
    $session  = $event->data->object;
    $checkout = new Checkout( sugarcart_get_client() );

    // One call gets everything: line items, payment details, country, customer info
    $data = $checkout->get_completed_data( $session->id );

    if ( is_wp_error( $data ) ) {
        return $data;
    }

    // Now it's pure database work:
    // 1. Upsert customer using $data['customer_id'], $data['customer_email'],
    //    $data['customer_name'], $data['country'], $data['is_test']
    // 2. Create order using $data['payment_intent_id'], $data['total'],
    //    $data['currency'], $data['payment_brand'], $data['payment_last4'], etc.
    // 3. Create order items from $data['line_items'] (each has stripe_price_id,
    //    product_name, quantity, total)
    // 4. Apply discount from $data['discount'] (code, coupon_id)
    // 5. If $data['subscription_id'] — create subscription record
    // 6. Increment sales_count/revenue on prices
}
```

### payment_intent.succeeded
**Confirms payment.** Updates order status and payment details if not already set.

```
Tables affected: orders
Library classes: PaymentIntents
```

```php
function sugarcart_handle_payment_succeeded( $event ) {
    $intent = $event->data->object;

    // Find order by stripe_payment_intent
    // Update: status='completed'
    // If payment_brand/last4 empty, fetch via PaymentIntents::get_payment_details()
}
```

### payment_intent.payment_failed
```
Tables affected: orders
```

```php
function sugarcart_handle_payment_failed( $event ) {
    $intent = $event->data->object;

    // Find order by stripe_payment_intent
    // Update: status='failed'
}
```

### customer.subscription.created
```
Tables affected: subscriptions
Library method:  Subscriptions::get_event_data()
```

```php
function sugarcart_handle_subscription_created( $event ) {
    $subs = new Subscriptions( sugarcart_get_client() );
    $data = $subs->get_event_data( $event );

    // Upsert into sugarcart_subscriptions using:
    // $data['subscription_id'], $data['customer_id'], $data['price_id'],
    // $data['status'], $data['current_period_end'], $data['cancel_at_period_end'],
    // $data['is_test']
}
```

### customer.subscription.updated
**Covers: plan changes, cancellation scheduling, period end updates, status changes.**

```
Tables affected: subscriptions
Library method:  Subscriptions::get_event_data()
```

```php
function sugarcart_handle_subscription_updated( $event ) {
    $subs = new Subscriptions( sugarcart_get_client() );
    $data = $subs->get_event_data( $event );

    // Find by $data['subscription_id']
    // Update: status, price_id (may have changed on plan switch),
    //         current_period_end, cancel_at_period_end
}
```

### customer.subscription.deleted
```
Tables affected: subscriptions
Library method:  Subscriptions::get_event_data()
```

```php
function sugarcart_handle_subscription_deleted( $event ) {
    $subs = new Subscriptions( sugarcart_get_client() );
    $data = $subs->get_event_data( $event );

    // Find by $data['subscription_id']
    // Update: status='canceled' ($data['status'] will be 'canceled')
}
```

### customer.subscription.paused / resumed
```
Tables affected: subscriptions
Library method:  Subscriptions::get_event_data()
```

```php
function sugarcart_handle_subscription_paused( $event ) {
    $subs = new Subscriptions( sugarcart_get_client() );
    $data = $subs->get_event_data( $event );
    // Update: status='paused' (or use $data['status'])
}

function sugarcart_handle_subscription_resumed( $event ) {
    $subs = new Subscriptions( sugarcart_get_client() );
    $data = $subs->get_event_data( $event );
    // Update: status='active'
}
```

### invoice.paid
**Subscription renewals.** Creates a new order for each renewal payment.

```
Tables affected: orders, order_items, prices (sales_count/revenue)
Library method:  Invoices::get_renewal_data()
```

```php
function sugarcart_handle_invoice_paid( $event ) {
    $invoice  = $event->data->object;
    $invoices = new Invoices( sugarcart_get_client() );

    // Returns null for initial subscription invoices (already handled by checkout)
    $data = $invoices->get_renewal_data( $invoice->id );

    if ( is_wp_error( $data ) || $data === null ) {
        return;
    }

    // Pure database work:
    // 1. Create renewal order using $data['payment_intent_id'], $data['total'],
    //    $data['subscription_id'], $data['payment_brand'], $data['payment_last4']
    // 2. Create order items from $data['line_items']
    // 3. Increment sales_count/revenue on prices
}
```

### invoice.payment_failed
```
Tables affected: subscriptions
Library classes: (notification logic in SugarCart)
```

```php
function sugarcart_handle_invoice_failed( $event ) {
    $invoice = $event->data->object;

    // Find subscription by stripe_subscription_id
    // Update: status='past_due'
    // Trigger notification (SugarCart business logic)
}
```

### charge.refunded
```
Tables affected: orders, prices (revenue adjustment)
Library method:  Refunds::get_refund_data()
```

```php
function sugarcart_handle_charge_refunded( $event ) {
    $refunds = new Refunds( sugarcart_get_client() );
    $data    = $refunds->get_refund_data( $event );

    // Find order by $data['payment_intent_id']
    // Update: refunded = $data['amount_refunded']
    // If $data['fully_refunded']: status = 'refunded'
    // Adjust revenue on prices using $data['latest_amount']
}
```

### product.updated / product.deleted
```
Tables affected: prices (product_name, product_description)
```

```php
function sugarcart_handle_product_updated( $event ) {
    $product = $event->data->object;

    // Find all prices by stripe_product_id
    // Update: product_name, product_description
    // If product deactivated: update price status
}
```

### price.updated / price.deleted
```
Tables affected: prices
```

```php
function sugarcart_handle_price_updated( $event ) {
    $price = $event->data->object;

    // Find by stripe_price_id
    // Update: status (active/inactive), price_nickname
    // Note: amount/currency/interval are immutable
}
```

### coupon.updated / coupon.deleted
```
Tables affected: coupons
```

```php
function sugarcart_handle_coupon_updated( $event ) {
    $coupon = $event->data->object;

    // Find by stripe_coupon_id
    // Update: name, times_redeemed, status
}
```

### customer.updated / customer.deleted
```
Tables affected: customers
```

```php
function sugarcart_handle_customer_updated( $event ) {
    $customer = $event->data->object;

    // Find by stripe_customer_id
    // Update: email, name
}
```

---

## What Lives Where

### wp-stripe library handles:
- Stripe API communication (all HTTP, auth, error handling)
- Webhook signature verification + replay protection
- REST endpoint registration
- Event dispatch to handlers
- Currency conversion (amounts ↔ cents)
- Dashboard URL generation
- Auto-paginated bulk fetching
- Payment detail extraction (brand, last4, country)

### SugarCart handles:
- Database operations (insert/update/query local tables)
- Order number generation
- Customer authentication (login codes)
- Referral tracking logic
- Sales count / revenue aggregation
- Email notifications
- Admin UI (tables, forms, settings)
- Checkout form assembly (which prices, success/cancel URLs)
- Download / file delivery logic
- Cross-sell logic
- Image sideloading / attachment management
- Sync orchestration (when to call each_batch)

### The boundary:
```
Stripe API ←→ [wp-stripe] ←→ [SugarCart webhook handlers] ←→ [SugarCart database]
                                    ↑ uses library classes
                                    ↑ for API follow-up calls
```
