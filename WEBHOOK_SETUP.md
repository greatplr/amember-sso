# Webhook Setup Guide

Quick reference for configuring webhooks in aMember for this package.

## Required Webhooks

Configure these webhooks in your aMember admin panel (Setup/Configuration → Webhooks):

### Minimum Configuration (Access Control Only)

| Event | URL | Purpose |
|-------|-----|---------|
| `accessAfterInsert` | `https://yourapp.com/amember/webhook` | Grant access when purchased |
| `accessAfterUpdate` | `https://yourapp.com/amember/webhook` | Extend access when subscription renews |
| `accessAfterDelete` | `https://yourapp.com/amember/webhook` | Remove access when refunded/expired/cancelled |

**This is all you need** if users are manually created in your app.

---

### Recommended Configuration (User Sync)

Add this to keep user data in sync when they update their profile:

| Event | URL | Purpose |
|-------|-----|---------|
| `userAfterUpdate` | `https://yourapp.com/amember/webhook` | Sync email/name changes |

**Note:** `userAfterInsert` is **not needed** because `accessAfterInsert` already includes full user data and auto-creates users when they make their first purchase.

---

### Optional Safety Net

| Event | URL | Purpose |
|-------|-----|---------|
| `subscriptionDeleted` | `https://yourapp.com/amember/webhook` | Backup for accessAfterDelete (redundant but safe) |

---

## What Each Webhook Does in Your App

### Core Access Control

**accessAfterInsert**
```
aMember: User purchases product
   ↓
Webhook fires with access record (access_id, product_id, begin_date, expire_date)
   ↓
Your app: Creates row in amember_subscriptions table
   ↓
Middleware: CheckAmemberProduct now allows access
```

**accessAfterUpdate**
```
aMember: Subscription renews (monthly billing)
   ↓
Webhook fires with updated access record (same access_id, new expire_date)
   ↓
Your app: Updates expire_date in amember_subscriptions table
   ↓
Middleware: Access continues without interruption
```

**accessAfterDelete**
```
aMember: Refund issued / Access expires / Admin removes access
   ↓
Webhook fires with access record being deleted
   ↓
Your app: Deletes row from amember_subscriptions table
   ↓
Middleware: CheckAmemberProduct now denies access
```

---

### User Management

**accessAfterInsert (auto-creates users)**
```
aMember: User purchases product (first time)
   ↓
Webhook fires with access[] AND user[] data
   ↓
Your app: Creates user in users table + subscription
   ↓
User can login and has access to product
```

**userAfterUpdate**
```
aMember: User changes email or name
   ↓
Webhook fires with updated user data
   ↓
Your app: Updates user record in users table
   ↓
User data stays in sync
```

---

## Webhook URL

All webhooks point to the same endpoint:
```
https://yourapp.com/amember/webhook
```

The package automatically routes to the correct handler based on the `am-event` field.

---

## What You DON'T Need

These webhooks are handled by aMember and don't need to be sent to your app:

| Event | Why Skip It |
|-------|-------------|
| `userAfterInsert` | `accessAfterInsert` already includes full user data and auto-creates users on first purchase |
| `invoicePaymentRefund` | aMember tracks refunds; `accessAfterDelete` handles access removal |
| `paymentAfterInsert` | aMember tracks payments; you only care about access granted/removed |
| `invoiceAfterInsert` | aMember tracks invoices; not needed for access control |
| `invoiceAfterCancel` | Access remains until expire_date; `accessAfterDelete` fires when it actually expires |
| `subscriptionAdded` | `accessAfterInsert` already creates subscription with more detail |

**Exception:** Only add `userAfterInsert` if users need to login to your app BEFORE making any purchases (e.g., free trial signups, browse-before-buy).

**Keep it simple** - only send webhooks that change state in your application.

---

## Securing Webhooks

aMember identifies nothing and signs nothing: there is no HMAC, signature or
timestamp on its webhooks. The package checks two things instead.

### 1. Source IP (always)

The package finds the installation by the request's source IP.

1. Find your aMember server's public IP address
2. Add installation in your app's database or Filament admin:
   - Name: "My aMember Site"
   - IP Address: `23.226.68.98` (your actual IP)
   - API URL: `https://example.com/members/api`
   - API Key: (from aMember admin)

3. Webhooks from this IP will be accepted; others get HTTP 400.

If your app is behind Cloudflare or a load balancer, configure Laravel's
trusted proxies so `$request->ip()` is aMember's IP, not the proxy's.

### 2. Shared secret header (recommended)

Set a `webhook_secret` on the installation, then send it from aMember as a
fixed header:

1. Generate a long random value, e.g. `php -r 'echo bin2hex(random_bytes(32));'`
2. Save it as the installation's `webhook_secret`
3. In aMember, edit **each** webhook pointing at your app and add this line to
   its **Headers** field:

   ```
   X-Amember-Secret: <the same value>
   ```

Once `webhook_secret` is set, a request without that header, or with a
different value, gets HTTP 403 and is logged as `failed`. The comparison uses
`hash_equals()`. With no `webhook_secret`, the IP check is the only check.

The header name can be changed with `AMEMBER_WEBHOOK_SECRET_HEADER`
(config `amember-sso.webhook.secret_header`); the aMember side must match.

> Upgrading from 1.x: 1.x expected an HMAC in `X-Amember-Signature`, which
> aMember never sends, so installations with a `webhook_secret` were rejecting
> every webhook. Add the `X-Amember-Secret` header above in aMember.

---

## Responses and Retries

aMember treats **only HTTP 200** as delivered. Anything else, including 201,
202 and 204, counts as a failure: aMember retries every 5 minutes, up to 10
times, then (if enabled) emails the admin that the webhook failed.

The package answers:

| Response | When |
|---|---|
| `200 {"status":"success"}` | Accepted (queued, or processed when `use_queue` is off) |
| `400` | Unknown source IP, or no `am-event` |
| `403` | `webhook_secret` set and the header is missing or wrong |
| `500` | Processing threw (only when processing synchronously) |

A retry resends the same payload, so the handlers are idempotent: access
records are upserted by `access_id`, users are matched before being created,
and deletes of missing rows are no-ops. Your own listeners on the package's
events can see the same event more than once and should cope with that.

Webhooks are sent by aMember's cron, so expect up to a minute or two of delay.

---

## Testing Your Webhooks

### Test 1: One-Time Purchase
```
1. Purchase product in aMember
2. Check your app's amember_subscriptions table
   → Should have new row with access_id, product_id, expire_date
3. Visit protected route with that product requirement
   → Should grant access
4. Refund in aMember
5. Check amember_subscriptions table
   → Row should be deleted
6. Visit protected route
   → Should deny access
```

### Test 2: Recurring Subscription
```
1. Purchase monthly subscription
2. Check amember_subscriptions table
   → expire_date should be ~30 days from now
3. Wait for renewal OR manually renew in aMember admin
4. Check amember_subscriptions table
   → expire_date should extend ~30 more days (via accessAfterUpdate)
5. Cancel subscription in aMember
6. Check amember_subscriptions table
   → Row still exists until expire_date
7. Wait for expire_date to pass OR manually expire in aMember
8. Check amember_subscriptions table
   → Row should be deleted (via accessAfterDelete)
```

### Test 3: User Auto-Creation
```
1. New user purchases product in aMember
2. Check your app's users table
   → Should have new user with email, name, amember_user_id (via accessAfterInsert)
3. Check amember_subscriptions table
   → Should have subscription for that user
4. Update user's email in aMember admin
5. Check users table
   → Email should update (via userAfterUpdate)
```

---

## Troubleshooting

### Webhook Not Firing
1. Check aMember's webhook queue: Admin → Webhooks → Queue
2. Check webhook logs in your app: `amember_webhook_logs` table
3. Verify IP address matches in `amember_installations` table

### Webhooks Rejected with 403
The installation has a `webhook_secret` and the request didn't carry it.
Check the webhook's **Headers** field in aMember reads exactly
`X-Amember-Secret: <webhook_secret>` (or your configured header name), on
every webhook pointing at your app.

### Access Not Granted After Purchase
1. Check webhook logs - did `accessAfterInsert` fire?
2. Check `amember_subscriptions` table - was row created?
3. Check middleware config - is product_id correct?
4. Check user's `amember_user_id` - does it match the subscription?

### Access Not Removed After Refund
1. Check if `accessAfterDelete` webhook fired
2. Check webhook logs for errors
3. Verify subscription was deleted from `amember_subscriptions` table

### User Not Created Automatically
1. Users are auto-created on first purchase via `accessAfterInsert`
2. Check webhook logs - did `accessAfterInsert` fire?
3. Verify user's email doesn't already exist (would update, not create)
4. Check `amember_installation_id` is set correctly
5. If users need to exist BEFORE purchasing, add `userAfterInsert` webhook

---

## Multi-Installation Setup

If you have multiple aMember installations sending webhooks:

### Database Setup
```sql
-- Each installation has unique IP and API credentials
INSERT INTO amember_installations (name, ip_address, api_url, api_key) VALUES
('Main Site', '23.226.68.98', 'https://main.com/members/api', 'key1'),
('Partner Site', '45.67.89.10', 'https://partner.com/members/api', 'key2');
```

### Webhook Configuration
- Both installations send webhooks to **same URL**: `https://yourapp.com/amember/webhook`
- Package automatically detects which installation by source IP
- Subscriptions are scoped to installation: `WHERE installation_id = X`
- Users can exist in multiple installations with same email but different `amember_user_id`

### User Matching Logic
1. First tries: `amember_user_id` + `installation_id` (exact match)
2. Falls back to: `email` (if amember_user_id not set)
3. Creates new user if no match found

This prevents conflicts when same person signs up on multiple aMember sites.

---

## Summary

**Minimum viable setup (3 webhooks):**
- `accessAfterInsert` - Creates users + subscriptions
- `accessAfterUpdate` - Extends subscriptions on renewal
- `accessAfterDelete` - Removes subscriptions on refund/expiry

**Recommended setup (4 webhooks):**
- Above three +
- `userAfterUpdate` - Syncs email/name changes

**Optional (5th webhook):**
- `userAfterInsert` - Only if users need to login BEFORE making purchases

**All point to same URL:** `https://yourapp.com/amember/webhook`

**That's it!** Everything else is handled by aMember's built-in analytics and reporting.
