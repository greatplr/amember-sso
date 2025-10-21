# aMember Webhook Payloads

Complete documentation of webhook payloads sent by aMember Pro.

## Table of Contents

- [Webhook Structure](#webhook-structure)
- [Common Fields](#common-fields)
- [Event Types](#event-types)
- [Payload Examples](#payload-examples)
- [Parsing Webhooks](#parsing-webhooks)

## Webhook Structure

### Headers

```
Content-Type: application/x-www-form-urlencoded
User-Agent: aMember PRO/6.3.31 (https://www.amember.com)
```

### Body Format

aMember sends **flattened POST data** with bracket notation:

```
user[user_id]=1048
user[email]=user@example.com
user[name_f]=John
product[product_id]=31
product[title]=Product Name
```

**Important:** Data is sent as `application/x-www-form-urlencoded`, NOT JSON!

## Common Fields

Every webhook includes these metadata fields:

| Field | Description | Example |
|-------|-------------|---------|
| `am-webhooks-version` | Webhook API version | `1.0` |
| `am-event` | Event type identifier | `subscriptionDeleted` |
| `am-timestamp` | Event timestamp (ISO 8601) | `2025-10-21T00:21:16+00:00` |
| `am-root-url` | aMember installation URL | `http://example.com/member` |

## Event Types

Based on aMember source code analysis:

### Access Events

| Event | Trigger | Params |
|-------|---------|--------|
| `accessAfterInsert` | Access record created | `access`, `user` |
| `accessAfterUpdate` | Access record updated | `access`, `old`, `user` |
| `accessAfterDelete` | Access record deleted | `access`, `user` |

### Invoice Events

| Event | Trigger | Params |
|-------|---------|--------|
| `invoiceAfterInsert` | Invoice created | `invoice`, `user` |
| `invoiceStarted` | Invoice becomes active/paid | `user`, `invoice`, `payment` |
| `invoiceStatusChange` | Invoice status changed | `invoice`, `status`, `oldStatus`, `user` |
| `invoiceAfterCancel` | Invoice cancelled | `invoice`, `user` |
| `invoiceAfterDelete` | Invoice deleted | `invoice`, `user` |
| `invoicePaymentRefund` | Payment refunded/chargebacked | `invoice`, `refund`, `user` |

### Payment Events

| Event | Trigger | Params |
|-------|---------|--------|
| `paymentAfterInsert` | Payment inserted (not for free) | `invoice`, `payment`, `user`, `items` |

### Subscription Events

| Event | Trigger | Params |
|-------|---------|--------|
| `subscriptionAdded` | User gets new product subscription | `user`, `product` |
| `subscriptionDeleted` | User subscription expires | `user`, `product` |

### User Events

| Event | Trigger | Params |
|-------|---------|--------|
| `userAfterInsert` | New user created | `user` |
| `userAfterUpdate` | User record updated | `user`, `oldUser` |
| `userAfterDelete` | User deleted | `user` |
| `setPassword` | Password changed | `user`, `password` |
| `userNoteAfterInsert` | Admin adds note to user | `user`, `note` |

## Payload Examples

### subscriptionDeleted Event

**Real example from aMember:**

```
am-webhooks-version: 1.0
am-event: subscriptionDeleted
am-timestamp: 2025-10-21T00:21:16+00:00
am-root-url: https://example.com/members

user[user_id]: 1048
user[login]: johndoe
user[email]: john@example.com
user[name_f]: John
user[name_l]: Doe
user[state]:
user[country]: US
user[added]: 2025-07-20 23:08:23
user[remote_addr]: 192.0.2.1
user[status]: 2
user[unsubscribed]: 0
user[email_confirmed]: 0
user[i_agree]: 0
user[is_approved]: 1
user[is_locked]: 0
user[last_login]: 2025-08-12 22:07:09
user[last_ip]: 192.0.2.2
user[last_user_agent]: Mozilla/5.0...
user[subusers_parent_id]: 0
user[phone_confirmed]: 0
user[mobile_confirmed]: 0
user[data.external_id]: johndoeexamplecom
user[data.need_session_refresh]: 1
user[data.signup_email_sent]: 1

product[product_id]: 31
product[title]: Premium Membership
product[description]: Full access to premium features
product[url]: https://example.com
product[start_date]: product,group,payment
product[tax_group]: -1
product[tax_digital]: 0
product[sort_order]: 31
product[renewal_group]:
product[require_other]: CATEGORY-ACTIVE-1
product[prevent_if_other]:
product[comment]:
product[default_billing_plan_id]: 31
product[is_tangible]: 0
product[is_disabled]: 0
product[is_archived]: 0
product[data.aweber_tags]: BLOB_VALUE
```

### subscriptionAdded Event

**Expected structure (similar to subscriptionDeleted):**

```
am-webhooks-version: 1.0
am-event: subscriptionAdded
am-timestamp: 2025-10-21T01:00:00+00:00
am-root-url: http://example.com/member

user[user_id]: 1050
user[login]: johndoe
user[email]: john@example.com
user[name_f]: John
user[name_l]: Doe
user[country]: US
user[status]: 1
user[is_approved]: 1
...

product[product_id]: 5
product[title]: Premium Membership
product[description]: Full access to all features
...
```

### accessAfterInsert Event

**Real example from aMember 6.3.35 (anonymized):**

```
Content-Type: application/x-www-form-urlencoded
User-Agent: aMember PRO/6.3.35 (https://www.amember.com)

am-webhooks-version: 1.0
am-event: accessAfterInsert
am-timestamp: 2025-10-20T18:37:07-06:00
am-root-url: https://example.com/members

access[access_id]: 3911
access[invoice_id]: 3053
access[invoice_public_id]: DG9J5
access[invoice_payment_id]: 3431
access[invoice_item_id]: 3058
access[user_id]: 1977
access[product_id]: 50
access[transaction_id]: 5T409668ET921644V
access[begin_date]: 2025-10-20
access[expire_date]: 2037-12-31
access[qty]: 1

user[user_id]: 1977
user[login]: johndoe
user[pass]: $P$B0YRy6lJMeFmTqwW1r3VOQqhxs71cu0
user[pass_dattm]: 2025-10-20 18:37:06
user[email]: john@example.com
user[name_f]: John
user[name_l]: Doe
user[state]: CA
user[country]: US
user[added]: 2025-10-20 18:37:06
user[remote_addr]: 192.0.2.1
user[status]: 0
user[unsubscribed]: 0
user[i_agree]: 0
user[is_approved]: 1
user[is_locked]: 0
user[email_confirmed]: 0
user[subusers_parent_id]: 0
user[mobile_confirmed]: 0
user[data.external_id]: johndoeexamplecom
```

**Note:** This is the MAIN event for creating subscriptions. It includes the full access record with dates, invoice information, and complete user data.

### paymentAfterInsert Event

**Expected structure:**

```
am-webhooks-version: 1.0
am-event: paymentAfterInsert
am-timestamp: 2025-10-21T01:00:00+00:00
am-root-url: http://example.com/member

payment[payment_id]: 789
payment[invoice_id]: 456
payment[amount]: 99.00
payment[currency]: USD
payment[paysys_id]: stripe
payment[receipt_id]: ch_xxxxxxxxxxxxx
payment[dattm]: 2025-10-21 01:00:00

invoice[invoice_id]: 456
invoice[user_id]: 1050
invoice[tm_added]: 2025-10-21 00:59:00
invoice[status]: 1
invoice[total]: 99.00

user[user_id]: 1050
user[email]: john@example.com
...

items[0][item_id]: 101
items[0][product_id]: 5
items[0][qty]: 1
items[0][first_price]: 99.00
```

## Parsing Webhooks in Laravel

### Understanding the Format

aMember sends data as **form-encoded** (`application/x-www-form-urlencoded`) with bracket notation. Laravel automatically parses this into nested arrays.

**Raw POST data (what aMember sends):**
```
access[access_id]=3911&access[product_id]=50&user[user_id]=1977&user[email]=test@example.com
```

**What Laravel receives (automatically parsed):**
```php
$request->all() = [
    'am-webhooks-version' => '1.0',
    'am-event' => 'accessAfterInsert',
    'am-timestamp' => '2025-10-20T18:37:07-06:00',
    'access' => [
        'access_id' => '3911',
        'product_id' => '50',
        'user_id' => '1977',
        'begin_date' => '2025-10-20',
        'expire_date' => '2037-12-31',
    ],
    'user' => [
        'user_id' => '1977',
        'email' => 'test@example.com',
        'name_f' => 'michael',
        'name_l' => 'mack',
    ]
]
```

**Important:** You don't need to manually parse the bracket notation - Laravel does it automatically!

### Accessing Data

```php
// Method 1: Array access
$userId = $request->input('user.user_id');
$email = $request->input('user.email');
$productId = $request->input('product.product_id');

// Method 2: Get entire object
$user = $request->input('user');
$product = $request->input('product');
$access = $request->input('access');

// Method 3: Metadata
$event = $request->input('am-event');
$timestamp = $request->input('am-timestamp');
```

### Event Detection

```php
$eventType = $request->input('am-event');

switch ($eventType) {
    case 'subscriptionAdded':
        $user = $request->input('user');
        $product = $request->input('product');
        break;

    case 'accessAfterInsert':
        $access = $request->input('access');
        $user = $request->input('user');
        break;

    case 'paymentAfterInsert':
        $payment = $request->input('payment');
        $invoice = $request->input('invoice');
        $user = $request->input('user');
        break;
}
```

## Important Notes

### 1. Event Names

aMember uses **camelCase** event names in webhooks:
- `subscriptionAdded` (not `subscription.added`)
- `accessAfterInsert` (not `access.after.insert`)
- `paymentAfterInsert` (not `payment.after.insert`)

### 2. Data Fields

**Custom data fields** use dot notation:
```
user[data.external_id]
user[data.signup_email_sent]
product[data.aweber_tags]
```

In Laravel:
```php
$externalId = $request->input('user.data.external_id');
```

### 3. Nested Objects

Some events include nested arrays (e.g., `paymentAfterInsert` includes `items`):
```php
$items = $request->input('items'); // Array of items
foreach ($items as $item) {
    $productId = $item['product_id'];
    $price = $item['first_price'];
}
```

### 4. Date Formats

Dates are sent as strings in various formats:
```
user[added]: 2025-07-20 23:08:23
am-timestamp: 2025-10-21T00:21:16+00:00
access[begin_date]: 2025-01-01
```

Parse with Carbon:
```php
$added = Carbon::parse($request->input('user.added'));
$timestamp = Carbon::parse($request->input('am-timestamp'));
```

### 5. BLOB Values

Some fields may contain `BLOB_VALUE` placeholder:
```
product[data.aweber_tags]: BLOB_VALUE
```

This indicates the actual value is too large for the webhook.

## Webhook Security

### No Signature Header

Based on the real webhook example, aMember does **NOT** send an `X-Amember-Signature` header by default.

**You must configure** signature verification in your aMember installation if you want it.

### IP Whitelisting

More reliable: Detect installation by source IP address (as we do):
```php
$installation = AmemberInstallation::byIp($request->ip())->first();
```

## Testing Webhooks

### 1. Use Request Bin / n8n

Test webhook reception:
```
https://requestbin.com
https://webhook.site
n8n (as shown in your example)
```

### 2. Manual Test in Laravel

```php
Route::post('/test-webhook', function (Request $request) {
    Log::info('Webhook received', [
        'event' => $request->input('am-event'),
        'headers' => $request->headers->all(),
        'body' => $request->all(),
    ]);

    return response()->json(['ok' => true]);
});
```

### 3. Simulate in Tests

```php
$this->post('/amember/webhook', [
    'am-webhooks-version' => '1.0',
    'am-event' => 'subscriptionAdded',
    'am-timestamp' => now()->toIso8601String(),
    'user' => [
        'user_id' => 123,
        'email' => 'test@example.com',
        'name_f' => 'John',
        'name_l' => 'Doe',
    ],
    'product' => [
        'product_id' => 5,
        'title' => 'Premium Plan',
    ],
]);
```

## Differences from Our Current Implementation

### What We Got Wrong

1. **Event names:** We used `subscription.added`, aMember uses `subscriptionAdded`
2. **Payload structure:** We expected flat data, aMember sends nested arrays
3. **Fields:** We looked for `access_id`, but it's in `access[access_id]`
4. **No signature header:** We expected `X-Amember-Signature`, but it's not sent by default

### What We Got Right

1. **IP detection:** Yes, webhooks come from static IPs
2. **User creation:** Yes, we need to create users from webhook data
3. **Multiple installations:** Yes, installations can be differentiated

## Next Steps

1. Update `WebhookController` to use correct event names
2. Parse nested array structure properly
3. Handle all event types documented above
4. Add tests using real webhook structure

## Reference

- aMember Webhook Source: `/Users/ryan/Code/reference/webhooks/Bootstrap.php`
- Real Example: n8n webhook capture (subscriptionDeleted)
- aMember Version: 6.3.31
