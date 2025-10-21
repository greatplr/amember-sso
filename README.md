# aMember SSO for Laravel

A comprehensive Laravel package that wraps `plutuss/amember-pro-laravel` to provide SSO authentication, webhook handling for subscription updates, and middleware for access control.

## Features

- **SSO Authentication**: Seamless single sign-on integration with aMember Pro
- **Multi-Installation Support**: Manage multiple aMember installations from a single Laravel app
- **Product Mapping System**: Map aMember products to application tiers, features, and specific models (polymorphic)
- **Webhook Handling**: Automatic processing of subscription updates with queue support (Horizon/Redis)
- **Access Control Middleware**: Protect routes based on product access and subscription status
- **Subscription Management**: Track and manage user subscriptions locally
- **IP-Based Installation Detection**: Automatically detect which aMember installation sent webhooks
- **Configurable Caching**: Improve performance with intelligent caching of subscription data
- **Event System**: Comprehensive events for subscriptions, users, and payments
- **Testing Helpers**: Built-in tools for faking webhooks and events in tests
- **Comprehensive Logging**: Database and debug logging for all webhook activity
- **Queue Processing**: Background webhook processing with retry logic
- **Laravel Auto-Discovery**: Automatic service provider registration
- **Filament Integration**: Optional admin interface for managing installations

## Requirements

- PHP 8.2 or higher
- Laravel 11.0 or higher
- aMember Pro installation with API access

## Installation

Install the package via Composer:

```bash
composer require greatplr/amember-sso
```

### Publish Configuration and Migrations

Publish the configuration file:

```bash
php artisan vendor:publish --tag=amember-sso-config
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=amember-sso-migrations
php artisan migrate
```

### Environment Configuration

Add the following variables to your `.env` file:

```env
# aMember API Configuration
AMEMBER_API_URL=https://your-amember-site.com
AMEMBER_API_KEY=your-api-key

# SSO Configuration
AMEMBER_SSO_ENABLED=true
AMEMBER_SSO_SECRET=your-sso-secret-key
AMEMBER_LOGIN_URL=https://your-amember-site.com/login
AMEMBER_LOGOUT_URL=https://your-amember-site.com/logout
AMEMBER_REDIRECT_AFTER_LOGIN=/dashboard
AMEMBER_REDIRECT_AFTER_LOGOUT=/

# Webhook Configuration
AMEMBER_WEBHOOK_ENABLED=true
AMEMBER_WEBHOOK_SECRET=your-webhook-secret
AMEMBER_WEBHOOK_PREFIX=amember/webhook

# Authentication
AMEMBER_GUARD=web
AMEMBER_USER_MODEL=App\\Models\\User

# Caching
AMEMBER_CACHE_ENABLED=true
AMEMBER_CACHE_TTL=300

# Data Sync
AMEMBER_SYNC_USER_DATA=true
```

## Usage

### SSO Authentication

#### Generate Login URL

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

// Generate SSO login URL for a user
$loginUrl = AmemberSso::generateLoginUrl('user@example.com');

// With custom redirect
$loginUrl = AmemberSso::generateLoginUrl('user@example.com', '/custom-redirect');

return redirect($loginUrl);
```

#### Authenticate User

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

// Authenticate user via SSO
$user = AmemberSso::authenticateUser('user@example.com');

if ($user) {
    // User authenticated successfully
    return redirect()->route('dashboard');
}
```

#### Verify SSO Token

```php
use Greatplr\AmemberSso\Facades\AmemberSso;
use Illuminate\Http\Request;

public function handleSsoCallback(Request $request)
{
    $data = $request->all();

    if (AmemberSso::verifySsoToken($data)) {
        $user = AmemberSso::authenticateUser($data['login']);
        return redirect()->route('dashboard');
    }

    return redirect()->route('login')->with('error', 'SSO authentication failed');
}
```

### Access Control Middleware

#### Check Product Access

Protect routes to ensure users have access to specific products:

```php
// Single product
Route::get('/premium-content', function () {
    return view('premium');
})->middleware(['auth', 'amember.product:1']);

// Multiple products (user needs access to any one)
Route::get('/member-content', function () {
    return view('member');
})->middleware(['auth', 'amember.product:1,2,3']);
```

#### Check Active Subscription

Ensure users have any active subscription:

```php
Route::middleware(['auth', 'amember.subscription'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/account', [AccountController::class, 'show']);
});
```

#### Combining Middleware

```php
Route::middleware(['auth', 'amember.subscription', 'amember.product:5'])->group(function () {
    Route::get('/vip-section', [VipController::class, 'index']);
});
```

### Subscription Management

#### Get User Subscriptions

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

// Get all subscriptions for a user
$amemberUserId = auth()->user()->amember_user_id;
$subscriptions = AmemberSso::getUserSubscriptions($amemberUserId);

foreach ($subscriptions as $subscription) {
    echo "Product: {$subscription['product_id']}\n";
    echo "Status: {$subscription['status']}\n";
    echo "Expires: {$subscription['expire_date']}\n";
}
```

#### Check Product Access

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

$amemberUserId = auth()->user()->amember_user_id;

// Check single product
if (AmemberSso::hasProductAccess($amemberUserId, 1)) {
    // User has access to product ID 1
}

// Check multiple products
if (AmemberSso::hasProductAccess($amemberUserId, [1, 2, 3])) {
    // User has access to at least one of these products
}
```

#### Check Active Subscription

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

$amemberUserId = auth()->user()->amember_user_id;

if (AmemberSso::hasActiveSubscription($amemberUserId)) {
    // User has at least one active subscription
}
```

#### Clear Subscription Cache

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

// Clear cache when you know subscription data has changed
$amemberUserId = auth()->user()->amember_user_id;
AmemberSso::clearSubscriptionCache($amemberUserId);
```

### Webhook Handling

The package automatically sets up a webhook endpoint at `/amember/webhook` (configurable).

**See comprehensive guides:**
- [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md) - Configuration and essential webhooks
- [WEBHOOK_WORKFLOWS.md](WEBHOOK_WORKFLOWS.md) - Complete workflow examples
- [WEBHOOK_LOGGING.md](WEBHOOK_LOGGING.md) - Debugging and monitoring
- [QUEUE_SETUP.md](QUEUE_SETUP.md) - Background processing with Horizon

#### Quick Setup

1. In your aMember admin panel, go to **Setup/Configuration → Webhooks**
2. Configure the webhook URL: `https://your-laravel-app.com/amember/webhook`
3. Select essential events (see [WEBHOOK_SETUP.md](WEBHOOK_SETUP.md)):
   - `accessAfterInsert` - Creates users and subscriptions
   - `accessAfterUpdate` - Updates subscriptions on renewal
   - `accessAfterDelete` - Removes subscriptions
   - `userAfterUpdate` - Syncs user data changes

#### Listening to Events

You can listen to subscription events in your application:

```php
// In EventServiceProvider.php

use Greatplr\AmemberSso\Events\SubscriptionAdded;
use Greatplr\AmemberSso\Events\SubscriptionUpdated;
use Greatplr\AmemberSso\Events\SubscriptionDeleted;
use Greatplr\AmemberSso\Events\UserCreated;
use Greatplr\AmemberSso\Events\UserUpdated;
use Greatplr\AmemberSso\Events\PaymentReceived;
use Greatplr\AmemberSso\Events\PaymentRefunded;

protected $listen = [
    SubscriptionAdded::class => [
        \App\Listeners\HandleNewSubscription::class,
    ],
    SubscriptionUpdated::class => [
        \App\Listeners\HandleSubscriptionUpdate::class,
    ],
    SubscriptionDeleted::class => [
        \App\Listeners\HandleSubscriptionDeletion::class,
    ],
    UserCreated::class => [
        \App\Listeners\HandleUserCreated::class,
    ],
    UserUpdated::class => [
        \App\Listeners\HandleUserUpdated::class,
    ],
    PaymentReceived::class => [
        \App\Listeners\HandlePaymentReceived::class,
    ],
    PaymentRefunded::class => [
        \App\Listeners\HandlePaymentRefunded::class,
    ],
];
```

Example listener:

```php
namespace App\Listeners;

use Greatplr\AmemberSso\Events\SubscriptionAdded;

class HandleNewSubscription
{
    public function handle(SubscriptionAdded $event)
    {
        $subscription = $event->subscription;
        $rawData = $event->rawData;
        $productMapping = $event->productMapping;  // AmemberProduct model (nullable)

        // Access product tier, features, or polymorphic mapping
        if ($productMapping) {
            \Log::info('Product tier: ' . $productMapping->tier);

            // If polymorphic mapping exists
            if ($productMapping->mappable_type) {
                $model = $productMapping->mappable;  // Get the actual model
                \Log::info("User gained access to: {$model->title}");
            }
        }

        // Send welcome email, grant access, etc.
        \Log::info('New subscription added', [
            'user_id' => $subscription['user_id'],
            'product_id' => $subscription['product_id'],
        ]);
    }
}
```

### Multi-Installation Support

Manage multiple aMember installations from a single Laravel app.

**See [MULTI_INSTALLATION.md](MULTI_INSTALLATION.md) for complete guide.**

#### Quick Overview

The package supports multiple aMember installations with:
- **IP-based detection** - Webhooks automatically routed to correct installation
- **Per-installation credentials** - Separate API keys and webhook secrets
- **User matching** - Users identified by `(amember_user_id, installation_id)` composite key
- **Optional Filament admin** - Manage installations via admin panel

#### Add Installation

```php
use Greatplr\AmemberSso\Models\AmemberInstallation;

AmemberInstallation::create([
    'name' => 'Main Site',
    'slug' => 'main',
    'api_url' => 'https://main.com/amember/api',
    'api_key' => 'your-api-key',
    'ip_address' => '23.226.68.98',  // For webhook detection
    'login_url' => 'https://main.com/amember/login',
    'webhook_secret' => 'your-webhook-secret',
    'is_active' => true,
]);
```

All webhooks automatically include installation context - no additional configuration needed!

### Product Mapping

Map aMember product IDs to application-specific tiers, features, and resources (polymorphic).

**See [PRODUCT_MAPPING.md](PRODUCT_MAPPING.md) for complete guide.**

#### Quick Overview

The package supports two product mapping patterns:

**1. Tier-Based Mapping** (Simple subscription tiers)
```php
use Greatplr\AmemberSso\Models\AmemberProduct;
use Greatplr\AmemberSso\Facades\AmemberSso;

// Map aMember Product 5 to 'premium' tier
AmemberProduct::create([
    'installation_id' => $installation->id,
    'product_id' => '5',
    'tier' => 'premium',
    'display_name' => 'Premium Plan',
    'features' => [
        'can_use_api' => true,
        'max_users' => 10,
        'support_level' => 'priority',
    ],
    'price' => 29.99,
    'currency' => 'USD',
]);

// Check tier access
if (AmemberSso::hasTierAccess($amemberUserId, 'premium')) {
    // User has premium access
}

// Check feature access
if (AmemberSso::hasFeatureAccess($amemberUserId, 'can_use_api')) {
    // User can use API
}
```

**2. Polymorphic Mapping** (Specific resource access)
```php
// Map aMember Product 5 to Course #42
AmemberProduct::create([
    'installation_id' => $installation->id,
    'product_id' => '5',
    'mappable_type' => 'App\Models\Course',
    'mappable_id' => 42,
    'tier' => 'premium',  // Can combine with tier
    'display_name' => 'Premium Course Bundle',
]);

// Check access to specific course
if (AmemberSso::hasMappableAccess($amemberUserId, 'App\Models\Course', 42)) {
    // User can access Course #42
}

// Get all courses user has access to
$courses = AmemberSso::getUserMappables($amemberUserId, 'App\Models\Course');
```

**Use Cases:**
- **Tier-based**: Simple subscription plans (Basic, Premium, Enterprise)
- **Polymorphic**: Complex apps where products grant access to specific resources (Course + Ebook + Membership)

**Multi-Resource Access**: One aMember product can map to multiple resources by creating multiple `AmemberProduct` records with the same `product_id` but different `mappable_type` and `mappable_id`.

See [PRODUCT_MAPPING.md](PRODUCT_MAPPING.md) for middleware, events, testing, and complete examples.

### Direct API Access

Access the underlying `AmemberApi` client:

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

$apiClient = AmemberSso::getApiClient();

// Use any method from plutuss/amember-pro-laravel
$user = $apiClient->getUserByLogin('user@example.com');
$products = $apiClient->getProducts();
```

## Configuration

The configuration file `config/amember-sso.php` provides extensive customization options:

### API Configuration
- `api_url`: Your aMember installation URL
- `api_key`: aMember API key

### SSO Configuration
- `sso.enabled`: Enable/disable SSO
- `sso.secret_key`: Secret key for SSO token generation
- `sso.redirect_after_login`: Default redirect after successful login
- `sso.redirect_after_logout`: Default redirect after logout
- `sso.session_lifetime`: Session lifetime in minutes

### Guard Configuration
- `guard`: Laravel authentication guard to use

### User Model
- `user_model`: User model class for authentication

### Webhook Configuration
- `webhook.enabled`: Enable/disable webhook handling
- `webhook.secret`: Secret for webhook signature verification
- `webhook.route_prefix`: Webhook endpoint prefix
- `webhook.events`: Events to process

### Access Control
- `access_control.cache_enabled`: Enable subscription caching
- `access_control.cache_ttl`: Cache TTL in seconds
- `access_control.sync_user_data`: Sync user data from aMember
- `access_control.syncable_fields`: Fields to sync

### Database Tables
- `tables.subscriptions`: Subscriptions table name
- `tables.products`: Products table name
- `tables.webhook_logs`: Webhook logs table name

### Logging
- `logging.enabled`: Enable logging
- `logging.channel`: Log channel to use

## Database Schema

The package creates the following tables:

### amember_subscriptions
Stores user subscription data:
- `id`: Primary key
- `access_id`: aMember access record ID (unique)
- `user_id`: aMember user ID
- `product_id`: Product ID
- `begin_date`: Subscription start date
- `expire_date`: Subscription expiration date
- `status`: Subscription status (active, pending, expired)
- `data`: JSON data from aMember
- `timestamps`

### amember_products
Stores product mapping information:
- `id`: Primary key
- `installation_id`: Foreign key to amember_installations
- `product_id`: aMember product ID
- `title`: Product title (synced from aMember)
- `description`: Product description (synced from aMember)
- `tier`: Application tier (e.g., 'basic', 'premium', 'enterprise')
- `display_name`: Display name for UI
- `slug`: URL-friendly identifier
- `mappable_type`: Polymorphic model class (e.g., 'App\Models\Course')
- `mappable_id`: Polymorphic model ID
- `features`: JSON feature flags
- `price`, `currency`, `billing_period`: Pricing information
- `sort_order`: Tier hierarchy
- `is_active`, `is_featured`: Display flags
- `metadata`: Additional JSON data
- `timestamps`
- **Note**: No unique constraint on `(installation_id, product_id)` - allows one aMember product to map to multiple resources

### amember_webhook_logs
Logs all webhook requests:
- `id`: Primary key
- `event_type`: Event type
- `status`: Processing status (received, processed, failed, error)
- `payload`: Full webhook payload
- `message`: Log message
- `ip_address`: Sender IP address
- `timestamps`

### users table modification
Adds `amember_user_id` column to link local users with aMember users.

## Security

### Webhook Security

The package verifies webhook signatures using HMAC-SHA256. Make sure to:
1. Set a strong `AMEMBER_WEBHOOK_SECRET`
2. Use HTTPS for webhook endpoints in production
3. Monitor webhook logs for suspicious activity

### SSO Security

- SSO tokens expire after 5 minutes
- All tokens are verified using HMAC-SHA256
- Use strong secret keys for both SSO and webhooks

## Testing

The package includes comprehensive testing helpers for simulating webhooks and events.

### Faking Webhooks

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

// Fake all aMember events
AmemberSso::fakeEvents();

// Generate a webhook payload
$webhookData = AmemberSso::fakeWebhook('accessAfterInsert', [
    'access' => [
        'product_id' => '5',
        'user_id' => '100',
    ],
    'user' => [
        'email' => 'test@example.com',
    ],
]);

// Process webhook and assert events
dispatch(new ProcessAmemberWebhook(
    $webhookData['event_type'],
    $webhookData['payload'],
    $webhookData['installation']
));

Event::assertDispatched(SubscriptionAdded::class, function ($event) {
    return $event->subscription['product_id'] === '5';
});
```

### Testing Helpers

```php
use Greatplr\AmemberSso\Testing\AmemberSsoTestHelper;

// Create test installation
$installation = AmemberSsoTestHelper::createTestInstallation();

// Fake specific event types
$payload = AmemberSsoTestHelper::buildWebhookPayload('accessAfterInsert', [
    'access' => ['product_id' => '5'],
]);

// Assert events
AmemberSsoTestHelper::assertSubscriptionAdded();
AmemberSsoTestHelper::assertUserCreated();
AmemberSsoTestHelper::assertPaymentReceived();
```

### Run Test Suite

```bash
composer test
```

## Troubleshooting

### Webhooks Not Working

1. Check webhook logs in `amember_webhook_logs` table
2. Verify webhook secret matches in both aMember and Laravel
3. Ensure webhook URL is accessible from aMember server
4. Check Laravel logs for errors

### SSO Authentication Failing

1. Verify API credentials are correct
2. Check that `amember_user_id` exists in users table
3. Ensure SSO secret key matches
4. Check Laravel logs for detailed error messages

### Subscription Cache Not Updating

Clear the cache manually:
```php
AmemberSso::clearSubscriptionCache($amemberUserId);
```

Or disable caching in config:
```php
'access_control' => [
    'cache_enabled' => false,
],
```

## Contributing

Contributions are welcome! Please submit pull requests or open issues on GitHub.

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Credits

- Built on top of [plutuss/amember-pro-laravel](https://github.com/plutuss/amember-pro-laravel)
- Developed by [GreatPLR](https://github.com/greatplr)

## Support

For issues, questions, or feature requests, please [open an issue](https://github.com/greatplr/amember-sso/issues) on GitHub.
