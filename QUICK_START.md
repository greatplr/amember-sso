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
AMEMBER_API_URL=https://your-amember-site.com
AMEMBER_API_KEY=your-api-key
AMEMBER_SSO_SECRET=your-sso-secret-key
AMEMBER_WEBHOOK_SECRET=your-webhook-secret
```

## Basic Usage

### 1. SSO Login

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

// Generate login URL and redirect
$loginUrl = AmemberSso::generateLoginUrl('user@example.com');
return redirect($loginUrl);

// Or authenticate directly
$user = AmemberSso::authenticateUser('user@example.com');
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

$amemberUserId = auth()->user()->amember_user_id;

// Check product access
if (AmemberSso::hasProductAccess($amemberUserId, 1)) {
    // User has access
}

// Check active subscription
if (AmemberSso::hasActiveSubscription($amemberUserId)) {
    // User has active subscription
}

// Get all subscriptions
$subscriptions = AmemberSso::getUserSubscriptions($amemberUserId);
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

## Next Steps

1. Configure your aMember API credentials in `.env`
2. Set up webhooks in your aMember admin panel
3. Add middleware to your routes
4. Create event listeners for subscription changes
5. Test SSO authentication

For detailed documentation, see [README.md](README.md).
