# Architecture: Webhook-First Flow

## Overview

This package separates **authentication** (proving who you are) from **authorization** (what you can access):

- **Webhooks** manage authorization → Create users and sync product access to local DB
- **SSO/Login** handles authentication → Match aMember user to local Laravel user
- **Middleware** checks authorization → Query local DB (fast, no API calls)

## The Flow

### 1. Initial Setup: Webhooks Create Users

```
aMember → Webhook → Laravel DB
```

When a user subscribes in aMember:
1. aMember sends webhook with subscription data
2. Webhook controller receives event
3. Creates/updates user in local `users` table with `amember_user_id`
4. Creates subscription record in `amember_subscriptions` table
5. Fires Laravel event (e.g., `SubscriptionAdded`)

**Result:** User exists locally with subscription data

### 2. User Login: Authentication Only

```
User → aMember Login → check-access API → Match Local User → Laravel Auth
```

When a user logs in:
1. User authenticates via aMember (SSO or login form)
2. Your app calls `AmemberSso::loginFromAmember('user@example.com', true)`
3. Package verifies user exists in aMember (check-access API)
4. Package finds local user by:
   - **First:** `amember_user_id` (most reliable)
   - **Fallback:** `email` address
5. If found by email without `amember_user_id`, updates the user record
6. Logs user into Laravel using `Auth::login($user)`

**Result:** User is authenticated in Laravel

### 3. Access Control: Check Local DB

```
Request → Middleware → Local DB Query → Allow/Deny
```

When user accesses protected routes:
1. Middleware checks if user is authenticated
2. Gets `amember_user_id` from authenticated user
3. Queries local `amember_subscriptions` table
4. Checks for active subscription with valid dates
5. Allows or denies access

**Result:** Fast authorization without API calls

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│                      aMember Pro                        │
│  - User Management                                      │
│  - Subscription Management                              │
│  - Payment Processing                                   │
└───────────────┬──────────────────┬──────────────────────┘
                │                  │
                │ Webhooks         │ check-access API
                │ (Authorization)  │ (Authentication)
                ▼                  ▼
┌───────────────────────────────────────────────────────────┐
│              Laravel Application                          │
│                                                           │
│  ┌──────────────────┐        ┌────────────────────────┐  │
│  │  Webhook Handler │        │  Login Controller      │  │
│  │                  │        │                        │  │
│  │  - Create users  │        │  - Verify with aMember │  │
│  │  - Sync subscr.  │        │  - Match local user    │  │
│  │  - Fire events   │        │  - Auth::login()       │  │
│  └────────┬─────────┘        └───────────┬────────────┘  │
│           │                              │               │
│           ▼                              ▼               │
│  ┌─────────────────────────────────────────────────┐    │
│  │           Local Database                        │    │
│  │                                                  │    │
│  │  users:                                          │    │
│  │  - id, email, amember_user_id, ...              │    │
│  │                                                  │    │
│  │  amember_subscriptions:                         │    │
│  │  - user_id, product_id, begin_date, expire_date │    │
│  └────────────────────────┬────────────────────────┘    │
│                           │                             │
│                           │ Fast queries                │
│                           ▼                             │
│  ┌────────────────────────────────────────────────┐    │
│  │         Middleware                             │    │
│  │  - amember.product:1,2,3                       │    │
│  │  - amember.subscription                        │    │
│  │  → Queries local DB only (no API calls)        │    │
│  └────────────────────────────────────────────────┘    │
└───────────────────────────────────────────────────────────┘
```

## Key Design Decisions

### Why Match by `amember_user_id` First?

**Problem:** Email addresses can change
- User changes email in aMember → login breaks
- User changes email in Laravel → webhook sync breaks

**Solution:** Use `amember_user_id` as primary key
- Immutable identifier
- Survives email changes
- Fallback to email for initial matching

### Why Check Local DB for Authorization?

**Benefits:**
- ⚡ **Fast** - No API calls on every request
- 🔒 **Reliable** - Works even if aMember is down
- 💰 **Efficient** - Reduces API usage
- 🎯 **Accurate** - Webhooks keep data in sync

**Trade-offs:**
- Webhook dependency (mitigated by webhook retry mechanisms)
- Slight delay between aMember change and local update (usually <1 second)

### Why Use check-access API for Authentication?

**Benefits:**
- ✅ Validates user exists in aMember
- ✅ Returns user_id for matching
- ✅ Lightweight endpoint
- ✅ Designed for this exact use case

**Alternatives Considered:**
- `/users` API - Slower, returns more data than needed
- SSO tokens - Requires more setup, less flexible

## Example Implementation

### Login Controller

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

class AmemberLoginController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Authenticate with aMember
        $accessData = AmemberSso::authenticateByLoginPass(
            $credentials['email'],
            $credentials['password']
        );

        if (!$accessData || !$accessData['ok']) {
            return back()->withErrors(['email' => 'Invalid credentials']);
        }

        // Login to Laravel (matches by amember_user_id or email)
        $user = AmemberSso::loginFromAmember($credentials['email'], true);

        if (!$user) {
            return back()->withErrors([
                'email' => 'User not found. Please complete signup first.'
            ]);
        }

        return redirect()->intended('/dashboard');
    }
}
```

### Protected Routes

```php
// Check for specific product access (queries local DB)
Route::get('/premium-content', function () {
    return view('premium');
})->middleware(['auth', 'amember.product:1,2']);

// Check for any active subscription (queries local DB)
Route::get('/members-area', function () {
    return view('members');
})->middleware(['auth', 'amember.subscription']);
```

### Event Listener

```php
use Greatplr\AmemberSso\Events\SubscriptionAdded;

class HandleNewSubscription
{
    public function handle(SubscriptionAdded $event)
    {
        $subscription = $event->subscription;

        // Send welcome email, grant additional access, etc.
        Mail::to($subscription['email'])->send(new WelcomeEmail());
    }
}
```

## Sync Strategies

### Webhook-Driven (Recommended)

✅ Real-time updates
✅ No polling needed
✅ Event-driven architecture

Configure in aMember:
- webhook.added → Create user + subscription
- webhook.updated → Update subscription
- webhook.deleted → Remove subscription

### API Polling (Alternative)

If webhooks aren't available:

```php
// Artisan command
AmemberSso::client()
    ->setOption('/users')
    ->nested(['access'])
    ->sendGet();
```

Run via cron to sync periodically.

## Security Considerations

1. **Webhook Signature Verification** - All webhooks validated with HMAC-SHA256
2. **Local User Required** - Users must exist locally before login (webhook-first)
3. **aMember as Source of Truth** - Webhooks keep local data in sync
4. **Fast Local Checks** - Authorization happens at DB level (no auth bypass)

## Performance

| Operation | Method | Latency |
|-----------|--------|---------|
| Login | check-access API | ~100-300ms |
| Route Protection | Local DB query | ~1-5ms |
| Subscription Check | Local DB query | ~1-5ms |
| Webhook Processing | DB write + event | ~10-50ms |

## Troubleshooting

### User can't login

1. Check if user exists in local DB: `User::where('email', $email)->exists()`
2. Check if `amember_user_id` is set
3. Verify webhook was received and processed
4. Check webhook logs table

### Access denied despite active subscription

1. Check local subscription table: `amember_subscriptions`
2. Verify `expire_date` is in future
3. Verify `begin_date` is in past
4. Check `user_id` matches `amember_user_id`
5. Review webhook logs for sync issues

### Email changed in aMember

If user changes email in aMember:
1. Webhook updates local user email
2. `amember_user_id` remains the same
3. Login works with new or old email (fallback matching)
4. Eventually both systems sync to new email

## Migration from Other Architectures

If you're currently checking API on every request:

1. **Add webhook handling** - Start syncing to local DB
2. **Update middleware** - Change from API calls to DB queries
3. **Update login flow** - Use `loginFromAmember()`
4. **Test thoroughly** - Verify local data stays in sync
5. **Monitor webhooks** - Check `amember_webhook_logs` table

## Summary

This architecture provides:
- ✅ Fast, local authorization checks
- ✅ Reliable authentication via aMember
- ✅ User matching that survives email changes
- ✅ Real-time sync via webhooks
- ✅ Clear separation of concerns
