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

## Polymorphic Product Mapping

For complex applications where aMember products grant access to **specific resources** (like courses, ebooks, memberships), use polymorphic mapping.

### Why Polymorphic?

**Tier-based is great for:**
- Simple subscription tiers (Basic, Premium, Enterprise)
- Feature flags (can_use_api, max_users)
- Pricing pages

**Polymorphic is essential for:**
- **Multi-resource access**: Product grants Course + Ebook + Membership
- **Specific model access**: Product 5 = Access to Course #42
- **Complex apps**: LinkController, LMS, content platforms
- **Existing models**: Map to existing Subscription, Plan, Course models

### Database Setup

The migration already includes polymorphic fields:

```php
$table->string('mappable_type')->nullable();  // Model class: 'App\Models\Course'
$table->unsignedBigInteger('mappable_id')->nullable();  // Model ID: 42
```

You can use **both tier AND polymorphic** on the same product:
- `tier` = 'premium' (for general access checks)
- `mappable_type` + `mappable_id` = specific resource granted

### Example: LinkController Use Case

**Scenario**: aMember Product 5 grants access to:
- Course #42
- Ebook #18
- Membership Role #3

```php
use Greatplr\AmemberSso\Models\AmemberProduct;
use Greatplr\AmemberSso\Models\AmemberInstallation;

$installation = AmemberInstallation::where('slug', 'main')->first();

// Product 5 grants Course access
AmemberProduct::create([
    'installation_id' => $installation->id,
    'product_id' => '5',
    'mappable_type' => 'App\Models\Course',
    'mappable_id' => 42,
    'tier' => 'premium',  // Still useful for tier-based checks
    'display_name' => 'Premium Course Bundle',
]);

// Product 5 ALSO grants Ebook access
AmemberProduct::create([
    'installation_id' => $installation->id,
    'product_id' => '5',
    'mappable_type' => 'App\Models\Ebook',
    'mappable_id' => 18,
    'tier' => 'premium',
    'display_name' => 'Bonus Ebook Access',
]);

// Product 5 ALSO grants Membership role
AmemberProduct::create([
    'installation_id' => $installation->id,
    'product_id' => '5',
    'mappable_type' => 'App\Models\Role',
    'mappable_id' => 3,
    'tier' => 'premium',
    'display_name' => 'Premium Member Role',
]);
```

**Important**: One aMember product can map to **multiple resources** by creating multiple `AmemberProduct` records with different `mappable_type` and `mappable_id` but same `product_id`.

### Checking Access to Specific Models

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

$amemberUserId = auth()->user()->amember_user_id;

// Check if user has access to Course #42
if (AmemberSso::hasMappableAccess($amemberUserId, 'App\Models\Course', 42)) {
    // User can access this specific course
}

// Check if user has access to Ebook #18
if (AmemberSso::hasMappableAccess($amemberUserId, 'App\Models\Ebook', 18)) {
    // User can access this specific ebook
}

// Check if user has access to ANY course
if (AmemberSso::hasAnyMappableTypeAccess($amemberUserId, 'App\Models\Course')) {
    // User has access to at least one course
}
```

### Get All Accessible Models

```php
use Greatplr\AmemberSso\Facades\AmemberSso;

$amemberUserId = auth()->user()->amember_user_id;

// Get all courses user has access to
$courses = AmemberSso::getUserMappables($amemberUserId, 'App\Models\Course');

foreach ($courses as $course) {
    echo $course->title . "\n";
}

// Get ALL mappable models (courses, ebooks, roles, etc.)
$allAccess = AmemberSso::getUserMappables($amemberUserId);
// Returns collection of mixed models
```

### Working with Model Relationships

Add a method to your Course model:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    /**
     * Get aMember products that grant access to this course.
     */
    public function amemberProducts()
    {
        return \Greatplr\AmemberSso\Models\AmemberProduct::where('mappable_type', self::class)
            ->where('mappable_id', $this->id)
            ->get();
    }

    /**
     * Check if a specific aMember user has access.
     */
    public function hasUserAccess(string $amemberUserId): bool
    {
        return \Greatplr\AmemberSso\Facades\AmemberSso::hasMappableAccess(
            $amemberUserId,
            self::class,
            $this->id
        );
    }
}
```

Usage:

```php
$course = Course::find(42);

// Check if user has access
if ($course->hasUserAccess(auth()->user()->amember_user_id)) {
    // Show course content
}

// See which aMember products grant access
$products = $course->amemberProducts();
// Returns: AmemberProduct collection
```

### Middleware for Polymorphic Access

```php
// app/Http/Middleware/CheckMappableAccess.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Greatplr\AmemberSso\Facades\AmemberSso;

class CheckMappableAccess
{
    public function handle(Request $request, Closure $next, string $type, string $idParam = 'id')
    {
        $user = $request->user();

        if (!$user || !$user->amember_user_id) {
            return redirect('/login');
        }

        $modelId = $request->route($idParam);

        if (!AmemberSso::hasMappableAccess($user->amember_user_id, $type, $modelId)) {
            abort(403, "You don't have access to this resource");
        }

        return $next($request);
    }
}
```

Register in `app/Http/Kernel.php`:

```php
protected $middlewareAliases = [
    'mappable' => \App\Http\Middleware\CheckMappableAccess::class,
];
```

Use in routes:

```php
Route::get('/courses/{id}', function ($id) {
    $course = Course::findOrFail($id);
    return view('courses.show', compact('course'));
})->middleware('mappable:App\Models\Course,id');

Route::get('/ebooks/{ebook}', function ($ebook) {
    return view('ebooks.show', compact('ebook'));
})->middleware('mappable:App\Models\Ebook,ebook');
```

### Combining Tier and Polymorphic

Use **both** patterns in the same application:

```php
// Tier-based: General access levels
if (AmemberSso::hasTierAccess($amemberUserId, 'premium')) {
    // Can use API, premium support, etc.
}

// Polymorphic: Specific resource access
if (AmemberSso::hasMappableAccess($amemberUserId, 'App\Models\Course', 42)) {
    // Can access this specific course
}

// Feature-based: Capability checks
if (AmemberSso::hasFeatureAccess($amemberUserId, 'can_download_videos')) {
    // Show download button
}
```

### Events Include Product Mapping

All subscription events now include the product mapping:

```php
use Greatplr\AmemberSso\Events\SubscriptionAdded;

class HandleNewSubscription
{
    public function handle(SubscriptionAdded $event)
    {
        $subscription = $event->subscription;
        $productMapping = $event->productMapping;  // AmemberProduct model

        if ($productMapping && $productMapping->mappable_type === 'App\Models\Course') {
            $course = $productMapping->mappable;

            // Grant user access to course
            Log::info("User gained access to course: {$course->title}");
        }
    }
}
```

### Testing with Polymorphic

```php
use Greatplr\AmemberSso\Facades\AmemberSso;
use Greatplr\AmemberSso\Models\AmemberProduct;

// Create test product mapping
$product = AmemberProduct::create([
    'installation_id' => $installation->id,
    'product_id' => '5',
    'mappable_type' => 'App\Models\Course',
    'mappable_id' => 42,
    'tier' => 'premium',
]);

// Fake webhook
AmemberSso::fakeEvents();

$webhookData = AmemberSso::fakeWebhook('accessAfterInsert', [
    'access' => [
        'product_id' => '5',
        'user_id' => '100',
    ],
]);

// Process webhook and assert
AmemberSso::assertSubscriptionAdded(function ($event) {
    return $event->productMapping->mappable_type === 'App\Models\Course';
});
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
