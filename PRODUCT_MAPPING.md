# Product Mapping & Tier System

Map aMember product IDs to application-specific tiers and features.

## Why Product Mapping?

**aMember products are just IDs:**
- Product `5` = ?
- Product `50` = ?
- Product `31` = ?

**Your app needs:**
- Tier names: `basic`, `premium`, `enterprise`
- Feature flags: `can_use_api`, `max_users: 10`
- Display names: "Premium Membership"
- Pricing info: `$19.99/month`

## Database Structure

The `amember_products` table maps aMember products to your application:

```sql
CREATE TABLE amember_products (
    id BIGINT PRIMARY KEY,
    installation_id BIGINT,        -- Which aMember installation
    product_id VARCHAR(255),        -- aMember's product ID

    -- Application mapping
    tier VARCHAR(255),              -- 'basic', 'premium', 'enterprise'
    display_name VARCHAR(255),      -- 'Premium Membership'
    slug VARCHAR(255),              -- 'premium-plan'

    -- Feature flags (JSON)
    features JSON,                  -- {"can_use_api": true, "max_users": 10}

    -- Pricing (for display)
    price DECIMAL(10,2),           -- 19.99
    currency VARCHAR(3),            -- 'USD'
    billing_period VARCHAR(255),    -- 'monthly'

    -- Sorting
    sort_order INT,                 -- Higher = better tier
    is_active BOOLEAN,
    is_featured BOOLEAN
);
```

## Setup

### 1. Create Product Mappings

```php
use Greatplr\AmemberSso\Models\AmemberProduct;
use Greatplr\AmemberSso\Models\AmemberInstallation;

$installation = AmemberInstallation::where('slug', 'main')->first();

// Basic Plan (aMember Product ID 5)
AmemberProduct::create([
    'installation_id' => $installation->id,
    'product_id' => '5',                    // aMember product ID
    'tier' => 'basic',                      // Your tier name
    'display_name' => 'Basic Plan',
    'slug' => 'basic-plan',
    'features' => [
        'can_use_api' => false,
        'max_users' => 1,
        'max_projects' => 3,
        'support_level' => 'email',
    ],
    'price' => 9.99,
    'currency' => 'USD',
    'billing_period' => 'monthly',
    'sort_order' => 10,                     // Lower number = basic tier
    'is_active' => true,
]);

// Premium Plan (aMember Product ID 50)
AmemberProduct::create([
    'installation_id' => $installation->id,
    'product_id' => '50',
    'tier' => 'premium',
    'display_name' => 'Premium Plan',
    'slug' => 'premium-plan',
    'features' => [
        'can_use_api' => true,
        'max_users' => 10,
        'max_projects' => 50,
        'support_level' => 'priority',
    ],
    'price' => 29.99,
    'currency' => 'USD',
    'billing_period' => 'monthly',
    'sort_order' => 50,                     // Higher number = better tier
    'is_active' => true,
    'is_featured' => true,
]);

// Enterprise Plan (aMember Product ID 31)
AmemberProduct::create([
    'installation_id' => $installation->id,
    'product_id' => '31',
    'tier' => 'enterprise',
    'display_name' => 'Enterprise Plan',
    'slug' => 'enterprise-plan',
    'features' => [
        'can_use_api' => true,
        'max_users' => 999999,
        'max_projects' => 999999,
        'support_level' => 'dedicated',
        'white_label' => true,
    ],
    'price' => 99.99,
    'currency' => 'USD',
    'billing_period' => 'monthly',
    'sort_order' => 100,                    // Highest tier
    'is_active' => true,
]);
```

### 2. Multi-Installation Products

Same product ID can mean different things on different installations:

```php
$mainSite = AmemberInstallation::where('slug', 'main')->first();
$partnerSite = AmemberInstallation::where('slug', 'partner')->first();

// Product ID 5 on Main Site = Basic
AmemberProduct::create([
    'installation_id' => $mainSite->id,
    'product_id' => '5',
    'tier' => 'basic',
    'display_name' => 'Main Site Basic',
]);

// Product ID 5 on Partner Site = Premium (different!)
AmemberProduct::create([
    'installation_id' => $partnerSite->id,
    'product_id' => '5',
    'tier' => 'premium',
    'display_name' => 'Partner Site Premium',
]);
```

## Usage

### Check Tier Access

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

$amemberUserId = auth()->user()->amember_user_id;

// Check if user has specific tier
if (AmemberSso::hasTierAccess($amemberUserId, 'premium')) {
    // User has premium access
}

// Check across all installations
if (AmemberSso::hasTierAccess($amemberUserId, 'enterprise')) {
    // User is enterprise on ANY installation
}

// Check specific installation
if (AmemberSso::hasTierAccess($amemberUserId, 'basic', $installationId)) {
    // User is basic on THIS installation
}
```

### Get User's Tiers

```php
$amemberUserId = auth()->user()->amember_user_id;

// Get all active tiers
$tiers = AmemberSso::getUserTiers($amemberUserId);
// Returns: ['basic', 'premium']

// Get highest tier (based on sort_order)
$highestTier = AmemberSso::getUserHighestTier($amemberUserId);
// Returns: 'premium'

// Check specific installation
$tiers = AmemberSso::getUserTiers($amemberUserId, $installationId);
```

### Check Feature Access

```php
$amemberUserId = auth()->user()->amember_user_id;

// Check if user has API access
if (AmemberSso::hasFeatureAccess($amemberUserId, 'can_use_api')) {
    // User can use API
}

// Get feature value (returns highest from all products)
$maxUsers = AmemberSso::getFeatureValue($amemberUserId, 'max_users', null, 1);
// If user has both Basic (max_users: 1) and Premium (max_users: 10)
// Returns: 10 (highest value)

$supportLevel = AmemberSso::getFeatureValue($amemberUserId, 'support_level');
// Returns: 'priority' (from highest tier product)
```

### Direct Product Access

```php
use Greatplr\AmemberSso\Models\AmemberProduct;

// Get product by aMember ID
$product = AmemberProduct::findByAmemberProduct('50', $installationId);
echo $product->tier;           // 'premium'
echo $product->display_name;   // 'Premium Plan'
echo $product->formatted_price; // '$29.99/mo'

// Get product by tier
$product = AmemberProduct::findByTier('enterprise', $installationId);
echo $product->product_id;     // '31'

// Check product features
if ($product->hasFeature('white_label')) {
    // This product has white label feature
}

$maxProjects = $product->getFeature('max_projects', 0);
// Returns: 999999
```

## Blade Helpers

### In Blade Templates

```blade
{{-- Check tier access --}}
@if(AmemberSso::hasTierAccess(auth()->user()->amember_user_id, 'premium'))
    <div class="premium-features">
        Premium Features Unlocked!
    </div>
@endif

{{-- Check feature access --}}
@if(AmemberSso::hasFeatureAccess(auth()->user()->amember_user_id, 'can_use_api'))
    <a href="/api/docs">API Documentation</a>
@endif

{{-- Display user's tier --}}
<div class="user-tier">
    Your Plan: {{ AmemberSso::getUserHighestTier(auth()->user()->amember_user_id) }}
</div>

{{-- Feature limits --}}
<p>Users: {{ AmemberSso::getFeatureValue(auth()->user()->amember_user_id, 'max_users', null, 1) }}</p>
```

## Middleware

Protect routes by tier:

```php
// Create middleware: app/Http/Middleware/CheckTier.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Greatplr\AmemberSso\Facades\AmemberSso;

class CheckTier
{
    public function handle(Request $request, Closure $next, string $tier)
    {
        $user = $request->user();

        if (!$user || !$user->amember_user_id) {
            return redirect('/login');
        }

        if (!AmemberSso::hasTierAccess($user->amember_user_id, $tier)) {
            abort(403, "This feature requires {$tier} tier");
        }

        return $next($request);
    }
}
```

Register in `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    'tier' => \App\Http\Middleware\CheckTier::class,
];
```

Use in routes:

```php
Route::get('/premium-feature', function () {
    // Only premium+ users
})->middleware('tier:premium');

Route::get('/enterprise-dashboard', function () {
    // Only enterprise users
})->middleware('tier:enterprise');
```

## Feature-Based Access Control

Protect by features instead of tiers:

```php
// app/Http/Middleware/CheckFeature.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Greatplr\AmemberSso\Facades\AmemberSso;

class CheckFeature
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        $user = $request->user();

        if (!$user || !$user->amember_user_id) {
            return redirect('/login');
        }

        if (!AmemberSso::hasFeatureAccess($user->amember_user_id, $feature)) {
            abort(403, "This feature is not available on your plan");
        }

        return $next($request);
    }
}
```

Use in routes:

```php
Route::get('/api/endpoint', function () {
    // Requires can_use_api feature
})->middleware('feature:can_use_api');

Route::post('/whitelabel', function () {
    // Requires white_label feature
})->middleware('feature:white_label');
```

## Syncing Products from aMember

Automatically sync product details when webhooks arrive:

```php
// In your webhook handler or artisan command
use Greatplr\AmemberSso\Models\AmemberProduct;
use Greatplr\AmemberSso\Models\AmemberInstallation;
use Plutuss\AMember\Facades\AMember;

$installation = AmemberInstallation::find(1);

// Get products from aMember API
$amemberProducts = AMember::products()->getProducts();

foreach ($amemberProducts as $amemberProduct) {
    // Update or create product mapping
    $product = AmemberProduct::updateOrCreate(
        [
            'installation_id' => $installation->id,
            'product_id' => $amemberProduct['product_id'],
        ],
        [
            'title' => $amemberProduct['title'],
            'description' => $amemberProduct['description'],
            // Keep existing tier/features mappings
            // Only sync title/description from aMember
        ]
    );
}
```

## Pricing Page Example

Display pricing from product mappings:

```php
// Controller
use Greatplr\AmemberSso\Models\AmemberProduct;

public function pricing()
{
    $plans = AmemberProduct::forInstallation($installationId)
        ->active()
        ->orderBy('sort_order')
        ->get();

    return view('pricing', compact('plans'));
}
```

```blade
{{-- View: pricing.blade.php --}}
<div class="pricing-grid">
    @foreach($plans as $plan)
        <div class="pricing-card {{ $plan->is_featured ? 'featured' : '' }}">
            <h3>{{ $plan->display_name }}</h3>
            <div class="price">{{ $plan->formatted_price }}</div>
            <ul class="features">
                @if($plan->hasFeature('can_use_api'))
                    <li>API Access</li>
                @endif
                <li>{{ $plan->getFeature('max_users') }} Users</li>
                <li>{{ $plan->getFeature('max_projects') }} Projects</li>
                <li>{{ ucfirst($plan->getFeature('support_level')) }} Support</li>
            </ul>
            <a href="{{ $installation->login_url }}/signup?product_id={{ $plan->product_id }}">
                Subscribe
            </a>
        </div>
    @endforeach
</div>
```

## Best Practices

### 1. **Use Tiers for Major Plans**
```php
'tier' => 'basic'    // Good: Clear hierarchy
'tier' => 'product5' // Bad: Not descriptive
```

### 2. **Use Features for Capabilities**
```php
'features' => [
    'can_use_api' => true,        // Boolean features
    'max_users' => 10,            // Numeric limits
    'support_level' => 'priority' // String values
]
```

### 3. **Sort Order = Tier Hierarchy**
```php
'sort_order' => 10   // Basic
'sort_order' => 50   // Premium
'sort_order' => 100  // Enterprise
```

### 4. **Check Features, Not Tiers**
```php
// Good: Works across tier changes
if (AmemberSso::hasFeatureAccess($userId, 'can_export_data')) {
    // ...
}

// Bad: Breaks when you rename tiers
if (AmemberSso::hasTierAccess($userId, 'premium')) {
    // ...
}
```

### 5. **Store Features in JSON**
Flexible and easy to extend:
```php
'features' => [
    'can_use_api' => true,
    'max_users' => 10,
    // Easy to add more later
    'custom_branding' => false,
]
```

## Summary

✅ **Package handles product mapping**
- Database table for mappings
- Model with helper methods
- Service methods for checking access
- Multi-installation aware
- Feature flags support

✅ **Usage patterns**
- Check tier access: `hasTierAccess()`
- Check feature access: `hasFeatureAccess()`
- Get feature values: `getFeatureValue()`
- Display pricing: `formatted_price`

✅ **Flexible**
- Different tiers per installation
- Custom features per product
- Sort order for hierarchy
- JSON metadata for extensions

✅ **Every app needs this**
- Map aMember IDs to tiers
- Feature-based access control
- Pricing displays
- Tier-based middleware
