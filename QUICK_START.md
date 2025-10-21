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

## Basic Usage

### 1. Authentication

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

// Authenticate with login/password
$accessData = AmemberSso::authenticateByLoginPass('username', 'password');
if ($accessData && $accessData['ok']) {
    // User authenticated, auto-login to Laravel
    $user = AmemberSso::loginFromCheckAccess('username');
}

// Or login with just email/username (if already verified)
$user = AmemberSso::loginFromCheckAccess('user@example.com', true); // true = isEmail

// Generate SSO URL for redirect to aMember
$ssoUrl = AmemberSso::generateSsoUrl('username', '/redirect-back-url');
```

### 2. Protect Routes with Middleware

```php
// Require specific product access
Route::get('/premium', function () {
    return view('premium');
})->middleware(['auth', 'amember.product:1']);

// Require any active subscription
Route::get('/members', function () {
    return view('members');
})->middleware(['auth', 'amember.subscription']);
```

### 3. Check Access in Code

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

$userEmail = auth()->user()->email;

// Check product access (uses check-access API)
if (AmemberSso::hasProductAccess($userEmail, 1, true)) { // true = isEmail
    // User has access to product ID 1
}

// Check multiple products
if (AmemberSso::hasProductAccess($userEmail, [1, 2, 3], true)) {
    // User has access to at least one of these products
}

// Check active subscription
if (AmemberSso::hasActiveSubscription($userEmail, true)) {
    // User has active subscription
}

// Get access data (subscriptions with expiration dates)
$accessData = AmemberSso::getUserAccess($userEmail, true);
// Returns: ['ok' => true, 'name' => 'Bob Smith', 'subscriptions' => [12 => '2050-01-01', ...]]

// Get detailed access records (uses access API)
$accessRecords = AmemberSso::getAccessRecords($amemberUserId);
```

### 4. Setup Webhooks

Configure in aMember:
- Webhook URL: `https://your-site.com/amember/webhook`
- Secret: Same as `AMEMBER_WEBHOOK_SECRET`
- Events: subscription.added, subscription.updated, subscription.deleted

### 5. Listen to Events

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
