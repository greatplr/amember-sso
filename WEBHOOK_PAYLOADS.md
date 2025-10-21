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
Content-Type: application/json
User-Agent: aMember PRO/6.3.31 (https://www.amember.com)
```

### Body Format

aMember sends **JSON** with nested objects:

```json
{
  "am-webhooks-version": "1.0",
  "am-event": "accessAfterInsert",
  "am-timestamp": "2025-06-17T22:53:45-06:00",
  "am-root-url": "https://example.com/members",
  "access": {
    "access_id": "3252",
    "product_id": "5",
    "user_id": "302"
  },
  "user": {
    "user_id": "302",
    "email": "user@example.com",
    "name_f": "John"
  }
}
```

**Important:** Data is sent as `application/json`, NOT form-encoded!

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

**Real example from aMember production (anonymized):**

```json
{
  "am-webhooks-version": "1.0",
  "am-event": "accessAfterInsert",
  "am-timestamp": "2025-06-17T22:53:45-06:00",
  "am-root-url": "https://example.com/members",
  "access": {
    "access_id": "3252",
    "invoice_id": "484",
    "invoice_public_id": "5Q7F5",
    "invoice_payment_id": "2809",
    "invoice_item_id": "484",
    "user_id": "302",
    "product_id": "5",
    "transaction_id": "0J540749EW088400G-B084",
    "begin_date": "2025-06-20",
    "expire_date": "2025-07-20",
    "qty": "1"
  },
  "user": {
    "user_id": "302",
    "login": "johndoe",
    "pass": "$P$BBr9zIzfI2eLPaMeJCj.KNjwySEwIv.",
    "pass_dattm": "2018-08-28 16:00:53",
    "email": "john@example.com",
    "name_f": "John",
    "name_l": "Doe",
    "state": "",
    "country": "US",
    "added": "2018-07-18 07:30:36",
    "remote_addr": "192.0.2.1",
    "status": "1",
    "unsubscribed": "0",
    "i_agree": "0",
    "is_approved": "1",
    "is_locked": "0",
    "last_login": "2019-04-24 15:17:24",
    "last_ip": "192.0.2.2",
    "last_user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.103 Safari/537.36",
    "email_confirmed": "0",
    "subusers_parent_id": "0",
    "mobile_confirmed": "0",
    "data.aweber.5087766": "71758987",
    "data.aweber.5087769": "78591587",
    "data.external_id": "johndoeexamplecom",
    "data.need_session_refresh": "1",
    "data.signup_email_sent": "1"
  }
}
```

**Note:** This is the MAIN event for creating subscriptions. It includes the full access record with dates, invoice information, and complete user data.

### accessAfterDelete Event

**Real example from aMember production (anonymized):**

```json
{
  "am-webhooks-version": "1.0",
  "am-event": "accessAfterDelete",
  "am-timestamp": "2025-07-01T10:11:03-06:00",
  "am-root-url": "https://example.com/members",
  "access": {
    "access_id": "3532",
    "invoice_id": "2711",
    "invoice_public_id": "71RUC",
    "invoice_payment_id": "3087",
    "invoice_item_id": "2715",
    "user_id": "1803",
    "product_id": "50",
    "transaction_id": "05687560CT632713C",
    "begin_date": "2025-06-30",
    "expire_date": "2037-12-31",
    "qty": "1",
    "comment": ""
  },
  "user": {
    "user_id": "1803",
    "login": "johndoe",
    "pass": "$P$BQ3.6fs6A8kajPPp/utFqbFC4Zc5YK1",
    "remember_key": "b3fb8bb8504ef99ee8448a63b95ee1765284bae1",
    "pass_dattm": "2025-06-30 09:43:24",
    "email": "john@example.com",
    "name_f": "John",
    "name_l": "Doe",
    "street": "",
    "street2": "",
    "city": "",
    "state": "",
    "zip": "",
    "country": "US",
    "phone": "",
    "added": "2025-06-30 09:43:24",
    "remote_addr": "192.0.2.1",
    "user_agent": "",
    "saved_form_id": "",
    "status": "1",
    "unsubscribed": "0",
    "lang": "",
    "i_agree": "0",
    "is_approved": "1",
    "is_locked": "0",
    "disable_lock_until": "",
    "reseller_id": "",
    "comment": "",
    "tax_id": "",
    "last_login": "2025-07-01 09:21:33",
    "last_ip": "192.0.2.2",
    "last_user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36",
    "aff_id": "",
    "aff_added": "",
    "is_affiliate": "",
    "aff_payout_type": "",
    "email_confirmed": "0",
    "email_confirmation_date": "",
    "subusers_parent_id": "0",
    "auth_key": "",
    "mobile_area_code": "",
    "mobile_number": "",
    "mobile_confirmed": "0",
    "mobile_confirmation_date": "",
    "data.external_id": "johndoeexamplecom",
    "data.need_session_refresh": "1",
    "data.signup_email_sent": "1"
  }
}
```

**Note:** This fires when access is deleted (refund, expiration, or manual removal). Contains the same access record structure as `accessAfterInsert`.

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

aMember sends data as **JSON** (`application/json`). Laravel automatically parses this into nested arrays.

**Raw POST data (what aMember sends):**
```json
{
  "am-webhooks-version": "1.0",
  "am-event": "accessAfterInsert",
  "am-timestamp": "2025-06-17T22:53:45-06:00",
  "access": {
    "access_id": "3252",
    "product_id": "5",
    "user_id": "302"
  },
  "user": {
    "user_id": "302",
    "email": "john@example.com"
  }
}
```

**What Laravel receives (automatically parsed):**
```php
$request->all() = [
    'am-webhooks-version' => '1.0',
    'am-event' => 'accessAfterInsert',
    'am-timestamp' => '2025-06-17T22:53:45-06:00',
    'access' => [
        'access_id' => '3252',
        'product_id' => '5',
        'user_id' => '302',
        'begin_date' => '2025-06-20',
        'expire_date' => '2025-07-20',
    ],
    'user' => [
        'user_id' => '302',
        'email' => 'john@example.com',
        'name_f' => 'John',
        'name_l' => 'Doe',
    ]
]
```

**Important:** You don't need to manually parse JSON - Laravel does it automatically!

### Accessing Data

```php
// Method 1: Dot notation
$userId = $request->input('user.user_id');
$email = $request->input('user.email');
$productId = $request->input('access.product_id');

// Method 2: Get entire nested array
$user = $request->input('user', []);
$access = $request->input('access', []);

// Method 3: Metadata
$event = $request->input('am-event');
$timestamp = $request->input('am-timestamp');

// Real example from webhook:
$accessData = $request->input('access');
// Returns: ['access_id' => '3252', 'product_id' => '5', ...]

$userData = $request->input('user');
// Returns: ['user_id' => '302', 'email' => 'john@example.com', ...]
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

## Key Implementation Details

### Payload Format

1. **Content-Type:** `application/json` (not form-encoded)
2. **Event names:** camelCase (`accessAfterInsert`, `subscriptionDeleted`)
3. **Structure:** Nested JSON objects (`access`, `user`, `product`)
4. **Laravel:** Automatically parses JSON into nested arrays

### What Works

1. ✅ **IP detection:** Webhooks come from static IPs - use for installation detection
2. ✅ **User creation:** `accessAfterInsert` includes full user data
3. ✅ **Multiple installations:** Differentiate by source IP
4. ✅ **Laravel parsing:** `$request->input('access')` returns the access array
5. ✅ **Nested access:** `$request->input('user.email')` works perfectly

## Next Steps

1. Update `WebhookController` to use correct event names
2. Parse nested array structure properly
3. Handle all event types documented above
4. Add tests using real webhook structure

## Reference

- aMember Webhook Source: `/Users/ryan/Code/reference/webhooks/Bootstrap.php`
- Real Example: n8n webhook capture (subscriptionDeleted)
- aMember Version: 6.3.31
