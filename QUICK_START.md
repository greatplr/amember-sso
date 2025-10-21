# Quick Start Guide

## Installation

```bash
composer require greatplr/amember-sso
php artisan vendor:publish --tag=amember-sso-config
php artisan vendor:publish --tag=amember-sso-migrations
php artisan migrate
```

## Environment Setup

```env
# aMember API (used by plutuss/amember-pro-laravel)
AMEMBER_URL=https://your-amember-site.com/api
AMEMBER_API_KEY=your-api-key

# SSO & Webhook Configuration (used by this package)
AMEMBER_SSO_SECRET=your-sso-secret-key
AMEMBER_WEBHOOK_SECRET=your-webhook-secret
```

## Important: Webhook-First Architecture

This package uses a **webhook-first** approach:
1. **Webhooks** create users and manage subscriptions in your local database
2. **Login** authenticates users and matches them to local accounts
3. **Middleware** checks local database (fast, no API calls)

See [ARCHITECTURE.md](ARCHITECTURE.md) for detailed flow.

## Basic Usage

### 1. Setup Webhooks (Do This First!)

Configure in aMember **before** users can login:
- Webhook URL: `https://your-site.com/amember/webhook`
- Secret: Same as `AMEMBER_WEBHOOK_SECRET`
- Events: subscription.added, subscription.updated, subscription.deleted

Webhooks will:
- Create users with `amember_user_id`
- Sync subscription data to local database
- Fire Laravel events for custom logic

### 2. Authentication

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

// Authenticate with login/password
$accessData = AmemberSso::authenticateByLoginPass('user@example.com', 'password');
if ($accessData && $accessData['ok']) {
    // User verified in aMember, now login to Laravel
    // Matches by amember_user_id (preferred) or email (fallback)
    $user = AmemberSso::loginFromAmember('user@example.com', true);

    if ($user) {
        // User logged in successfully
        return redirect('/dashboard');
    } else {
        // User exists in aMember but not locally
        // They need to be created via webhook first
        return back()->withErrors(['email' => 'Please complete signup first']);
    }
}

// Generate SSO URL for redirect to aMember
$ssoUrl = AmemberSso::generateSsoUrl('username', '/redirect-back-url');
```

### 3. Protect Routes with Middleware

Middleware checks **local database** (fast, no API calls):

```php
// Require specific product access
Route::get('/premium', function () {
    return view('premium');
})->middleware(['auth', 'amember.product:1']);

// Multiple products (access to any)
Route::get('/members', function () {
    return view('members');
})->middleware(['auth', 'amember.product:1,2,3']);

// Require any active subscription
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'amember.subscription']);
```

**Note:** Middleware queries `amember_subscriptions` table (populated by webhooks).

### 4. Check Access in Code (Optional)

You can also check access programmatically using the **check-access API**:

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

$userEmail = auth()->user()->email;

// Get real-time access data from aMember API
$accessData = AmemberSso::getUserAccess($userEmail, true);
// Returns: ['ok' => true, 'name' => 'Bob Smith', 'subscriptions' => [12 => '2050-01-01', ...]]

// Check product access via API (cached for performance)
if (AmemberSso::hasProductAccess($userEmail, 1, true)) {
    // User has access
}

// Get detailed subscription records from local DB
$user = auth()->user();
$subscriptions = DB::table('amember_subscriptions')
    ->where('user_id', $user->amember_user_id)
    ->where('expire_date', '>', now())
    ->get();
```

**Recommendation:** Use middleware for route protection (faster, uses local DB).

### 5. Listen to Webhook Events

Configure in aMember:
- Webhook URL: `https://your-site.com/amember/webhook`
- Secret: Same as `AMEMBER_WEBHOOK_SECRET`
- Events: subscription.added, subscription.updated, subscription.deleted

```php
// In EventServiceProvider.php
use Greatplr\AmemberSso\Events\SubscriptionAdded;

protected $listen = [
    SubscriptionAdded::class => [
        \App\Listeners\HandleNewSubscription::class,
    ],
];
```

## Package Structure

```
greatplr/amember-sso/
├── config/
│   └── amember-sso.php           # Configuration
├── database/migrations/           # Database migrations
├── routes/
│   └── webhook.php               # Webhook routes
├── src/
│   ├── AmemberSsoServiceProvider.php
│   ├── Services/
│   │   └── AmemberSsoService.php # Main service class
│   ├── Facades/
│   │   └── AmemberSso.php        # Facade
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── WebhookController.php
│   │   └── Middleware/
│   │       ├── CheckAmemberProduct.php
│   │       └── CheckAmemberSubscription.php
│   └── Events/
│       ├── SubscriptionAdded.php
│       ├── SubscriptionUpdated.php
│       └── SubscriptionDeleted.php
└── README.md                     # Full documentation
```

## Key Features

✅ SSO authentication with aMember Pro
✅ Automatic webhook processing for subscription updates
✅ Product-based access control middleware
✅ Subscription status verification middleware
✅ Event system for subscription changes
✅ Intelligent caching for performance
✅ Comprehensive logging
✅ Laravel auto-discovery support

### 6. Direct API Access

For advanced usage, access the underlying `plutuss/amember-pro-laravel` package:

```php
use Greatplr\AmemberSso\Facades\AmemberSso;
use Plutuss\AMember\Facades\AMember;

// Use AMember facade directly
$users = AMember::users()->count(10)->getUsers();
$products = AMember::products()->getProducts();

// Or through the AmemberSso facade
$client = AmemberSso::client();
$response = $client
    ->setOption('/users')
    ->filter(['email' => 'user@example.com'])
    ->sendGet();
```

## How This Package Works

This package **wraps** `plutuss/amember-pro-laravel` and adds Laravel-specific features:

**plutuss/amember-pro-laravel provides:**
- Raw API access to all aMember endpoints
- HTTP client for API communication

**greatplr/amember-sso adds:**
- ✅ Authentication helpers (check-access API)
- ✅ SSO URL generation
- ✅ Laravel middleware for access control
- ✅ Webhook handling with events
- ✅ User synchronization
- ✅ Caching layer for performance

## Next Steps

1. Configure your aMember API credentials in `.env`
2. Set up webhooks in your aMember admin panel
3. Add middleware to your routes
4. Create event listeners for subscription changes
5. Test authentication and access control

For detailed documentation, see [README.md](README.md).
