# aMember Webhook Workflows

This document outlines which webhooks fire for typical customer scenarios based on aMember's source code.

## Understanding the Event Hierarchy

aMember has two types of subscription-related events:

1. **High-level subscription events**: `subscriptionAdded`, `subscriptionDeleted`
   - Fire when a user gains/loses access to a product (regardless of how)
   - Do NOT include access record details (no dates, no access_id)
   - Useful for tracking "has product" vs "doesn't have product"

2. **Low-level access events**: `accessAfterInsert`, `accessAfterUpdate`, `accessAfterDelete`
   - Fire when actual access records are created/modified/deleted
   - Include full details (access_id, begin_date, expire_date, invoice_id)
   - **These are what you should use for subscription management**

## Common Customer Workflows

### Workflow 1: Customer Buys Product, Then Refunds, Then Buys Again

#### Initial Purchase
```
1. userAfterInsert (if new customer)
   - Fires when user account is created
   - Payload: user[]

2. invoiceAfterInsert
   - Fires when invoice is created
   - Payload: invoice[], user[]

3. paymentAfterInsert
   - Fires when payment is processed
   - Payload: invoice[], payment[], user[], items[]

4. invoiceStarted
   - Fires when invoice becomes paid/active
   - Payload: user[], invoice[], payment[]

5. accessAfterInsert ⭐ MAIN EVENT
   - Fires when access record is created
   - Payload: access[], user[]
   - This creates the subscription in your local DB

6. subscriptionAdded
   - Fires after access is granted
   - Payload: user[], product[]
```

#### Refund Occurs
```
7. invoicePaymentRefund ⭐ IMPORTANT
   - Fires when payment is refunded or chargebacked
   - Payload: invoice[], refund[], user[]

8. accessAfterDelete ⭐ MAIN EVENT
   - Fires when access record is deleted due to refund
   - Payload: access[], user[]
   - This removes the subscription from your local DB

9. subscriptionDeleted
   - Fires after access is removed
   - Payload: user[], product[]
```

#### Customer Buys Again (1 Month Later)
```
10. invoiceAfterInsert
    - New invoice created

11. paymentAfterInsert
    - New payment processed

12. invoiceStarted
    - New invoice becomes paid

13. accessAfterInsert ⭐ MAIN EVENT
    - NEW access record created (different access_id)
    - Payload: access[], user[]

14. subscriptionAdded
    - Fires again because they regained access
    - Payload: user[], product[]
```

**Key webhooks to handle**: `accessAfterInsert`, `accessAfterDelete`, `invoicePaymentRefund`

---

### Workflow 2: Customer Buys Subscription, Renews, Then Cancels

#### Initial Subscription Purchase
```
1. userAfterInsert (if new customer)
   - User account created

2. invoiceAfterInsert
   - First invoice created

3. paymentAfterInsert
   - First payment processed

4. accessAfterInsert ⭐ MAIN EVENT
   - Access record created with expire_date (e.g., 30 days from now)
   - Payload: access[begin_date], access[expire_date]

5. subscriptionAdded
   - User gains access to product
```

#### First Renewal (Automatic Rebill)
```
6. invoiceAfterInsert
   - Renewal invoice created

7. paymentAfterInsert
   - Renewal payment processed

8. accessAfterUpdate ⭐ IMPORTANT
   - Existing access record is UPDATED with new expire_date
   - Payload: access[], old[], user[]
   - Same access_id, but expire_date extended

OR (depending on aMember configuration):

8. accessAfterDelete + accessAfterInsert
   - Old access deleted, new one created
   - Two separate webhooks
```

#### Second Renewal
```
9. Same as first renewal
   - invoiceAfterInsert
   - paymentAfterInsert
   - accessAfterUpdate (expire_date extended again)
```

#### Customer Cancels Renewal
```
10. invoiceAfterCancel
    - Fires when subscription is cancelled
    - Payload: invoice[], user[]
    - Note: Access remains until expire_date!

11. (Later, when expire_date is reached)
    accessAfterDelete ⭐ IMPORTANT
    - Fires when access actually expires
    - Payload: access[], user[]

12. subscriptionDeleted
    - User loses access to product
    - Payload: user[], product[]
```

**Key webhooks to handle**: `accessAfterInsert`, `accessAfterUpdate`, `accessAfterDelete`, `invoiceAfterCancel`

---

### Workflow 3: Free Trial → Paid Subscription

#### Free Trial Starts
```
1. userAfterInsert
   - User signs up

2. invoiceAfterInsert
   - $0 invoice created for trial

3. invoiceStarted
   - Trial invoice becomes active (even though $0)
   - Payload includes: user[], invoice[], payment[]

4. accessAfterInsert ⭐ MAIN EVENT
   - Access granted for trial period
   - access[begin_date]: today
   - access[expire_date]: trial end date

5. subscriptionAdded
   - User has access during trial
```

#### Trial Converts to Paid
```
6. invoiceAfterInsert
   - New paid invoice created

7. paymentAfterInsert
   - First payment processed

8. accessAfterUpdate ⭐ IMPORTANT
   - Trial access record updated with new expire_date
   - OR: Trial access deleted and new paid access created
```

#### Trial Expires Without Payment
```
6. accessAfterDelete ⭐ IMPORTANT
   - Trial access expires
   - Payload: access[], user[]

7. subscriptionDeleted
   - User loses access
```

**Key webhooks to handle**: `accessAfterInsert`, `accessAfterUpdate`, `accessAfterDelete`, `invoiceStarted`

---

### Workflow 4: Customer Updates Email or Profile

```
1. userAfterUpdate ⭐ IMPORTANT
   - Fires when user data changes
   - Payload: user[], oldUser[]
   - Use this to sync email, name, etc. to local DB
```

**Key webhooks to handle**: `userAfterUpdate`

---

### Workflow 5: Customer Changes Subscription Plan (Upgrade/Downgrade)

#### Upgrade from Basic to Premium
```
1. invoiceAfterInsert
   - New invoice for premium plan

2. paymentAfterInsert
   - Payment for upgrade

3. accessAfterDelete
   - Old "basic" access removed
   - Payload: access[product_id]: 1 (basic)

4. subscriptionDeleted
   - Lost access to basic product
   - Payload: product[product_id]: 1

5. accessAfterInsert ⭐ MAIN EVENT
   - New "premium" access created
   - Payload: access[product_id]: 2 (premium)

6. subscriptionAdded
   - Gained access to premium product
   - Payload: product[product_id]: 2
```

**Key webhooks to handle**: `accessAfterInsert`, `accessAfterDelete`

---

### Workflow 6: Payment Fails (Recurring Subscription)

#### Failed Payment
```
1. (No paymentAfterInsert - payment didn't succeed)

2. invoiceStatusChange
   - Invoice status changes to failed/pending
   - Payload: invoice[], status[], oldStatus[], user[]

3. (Access remains until expire_date is reached)

4. accessAfterDelete (when expire_date passes)
   - Access removed because payment failed
   - Payload: access[], user[]

5. subscriptionDeleted
   - User loses access
```

#### Payment Retry Succeeds
```
6. paymentAfterInsert
   - Successful payment

7. accessAfterInsert
   - Access restored (new access record)
   - Payload: access[], user[]

8. subscriptionAdded
   - User regains access
```

**Key webhooks to handle**: `accessAfterDelete`, `accessAfterInsert`, `invoiceStatusChange`

---

## Recommended Webhook Configuration

### Essential Webhooks (Must Have)
These handle all subscription lifecycle events:

1. **accessAfterInsert** - Create subscription when access granted
2. **accessAfterUpdate** - Extend subscription when renewed
3. **accessAfterDelete** - Remove subscription when access expires/revoked
4. **userAfterInsert** - Create user account
5. **userAfterUpdate** - Sync user data changes (email, name)

### Important Webhooks (Recommended)
These provide additional context and tracking:

6. **invoicePaymentRefund** - Track refunds and chargebacks
7. **paymentAfterInsert** - Log successful payments
8. **invoiceAfterCancel** - Know when user cancels (even if access remains)
9. **subscriptionAdded** - High-level "user got product" notification
10. **subscriptionDeleted** - High-level "user lost product" notification

### Optional Webhooks (Nice to Have)
These are useful for advanced scenarios:

11. **invoiceAfterInsert** - Track all invoices
12. **invoiceStarted** - Track when invoices become active
13. **invoiceStatusChange** - Track invoice status changes
14. **userAfterDelete** - Clean up when users are deleted
15. **setPassword** - Track password changes (rare)

---

## Key Insights

### Access Records vs Subscriptions
- **Access records** (`accessAfter*` events) are the source of truth
- **Subscription events** (`subscriptionAdded/Deleted`) are convenience wrappers
- Always use `accessAfterInsert` to create local subscriptions (has dates, invoice_id, etc.)
- `subscriptionAdded` doesn't include access_id or dates

### Access Record Lifecycle
- **Created**: `accessAfterInsert` → Store in local DB
- **Extended**: `accessAfterUpdate` → Update expire_date
- **Removed**: `accessAfterDelete` → Delete from local DB

### Important Fields to Store
From `accessAfterInsert` webhook:
- `access_id` - Unique identifier for this access record
- `user_id` - aMember user ID
- `product_id` - Product they have access to
- `begin_date` - When access starts
- `expire_date` - When access ends (can be far future for lifetime)
- `invoice_id` - Which invoice granted this access
- `transaction_id` - Payment gateway transaction ID

### Timing Considerations
- Refunds trigger immediate `accessAfterDelete`
- Cancellations trigger `invoiceAfterCancel`, but access remains until `expire_date`
- Multiple access records can exist for same user+product (e.g., after refund and repurchase)
- Use `access_id` as primary key, not `user_id + product_id`

### Current Implementation Status

Your current `WebhookController.php` already handles:
- ✅ `accessAfterInsert` - Creates subscription
- ✅ `accessAfterUpdate` - Updates subscription
- ✅ `accessAfterDelete` - Removes subscription
- ✅ `subscriptionAdded` - Creates user and fires event
- ✅ `subscriptionDeleted` - Deletes subscriptions by user+product
- ✅ `userAfterInsert` - Creates user account
- ✅ `userAfterUpdate` - Syncs user data
- ✅ `paymentAfterInsert` - Logs payment (currently just logging)
- ✅ `invoicePaymentRefund` - Logs refund (currently just logging)

### Missing Handlers (Optional)
You may want to add:
- `invoiceAfterCancel` - Track cancellations separately from expiration
- `invoiceStatusChange` - Track payment failures/retries
- `userAfterDelete` - Clean up user data when deleted in aMember

---

## Testing Your Webhooks

### Test Scenario 1: Purchase → Refund → Repurchase
1. Create test product in aMember
2. Purchase as test user → Verify `accessAfterInsert` creates local subscription
3. Refund in aMember → Verify `accessAfterDelete` removes local subscription
4. Purchase again → Verify new `accessAfterInsert` creates new subscription (different access_id)

### Test Scenario 2: Recurring Subscription
1. Create recurring product (monthly)
2. Purchase → Verify `accessAfterInsert` with expire_date = 1 month
3. Wait for renewal or manually renew → Verify `accessAfterUpdate` extends expire_date
4. Cancel subscription → Verify access remains until expire_date
5. Wait for expire_date → Verify `accessAfterDelete` removes access

### Test Scenario 3: Email Change
1. Update user email in aMember admin
2. Verify `userAfterUpdate` webhook syncs new email to local DB
3. Verify user can still login with new email
