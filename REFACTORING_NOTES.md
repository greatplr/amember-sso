# Refactoring Notes

## What Changed

The package was refactored to properly **wrap and extend** `plutuss/amember-pro-laravel` instead of duplicating its functionality.

### Key Changes

#### 1. **Use Check-Access API Properly**
Instead of creating our own authentication logic, we now use aMember's `/check-access` endpoints:
- `/check-access/by-login`
- `/check-access/by-email`
- `/check-access/by-login-pass`
- `/check-access/by-login-pass-ip`

These endpoints return:
```json
{
   "ok": true,
   "name": "Bob Smith",
   "subscriptions": {
      "12": "2012-04-03",
      "33": "2050-01-01"
   }
}
```

#### 2. **Leverage Existing AMember Facade**
Instead of creating our own API client, we use:
- `AMember::users()` - User management
- `AMember::access()` - Access records
- `AMemberClient::getInstance()` - Custom API calls

#### 3. **Simplified Service Class**
The `AmemberSsoService` now:
- **Wraps** check-access API for authentication
- **Adds** Laravel-specific features (auto-login, user sync)
- **Provides** middleware helpers
- **Caches** access data for performance
- **Extends** with SSO URL generation

#### 4. **Updated Method Signatures**
Old methods that used `$userId` now use `$loginOrEmail` to work with check-access API:

**Before:**
```php
AmemberSso::hasProductAccess($userId, [1, 2, 3]);
AmemberSso::hasActiveSubscription($userId);
```

**After:**
```php
AmemberSso::hasProductAccess('user@example.com', [1, 2, 3], true); // true = isEmail
AmemberSso::hasActiveSubscription('user@example.com', true);
```

#### 5. **New Simplified Configuration**
Removed duplicate API configuration. Now relies on `config/amember.php`:
```env
# In .env - used by plutuss/amember-pro-laravel
AMEMBER_URL=https://your-amember-site.com/api
AMEMBER_API_KEY=your-api-key

# Additional SSO configuration - used by this package
AMEMBER_SSO_SECRET=your-sso-secret
AMEMBER_WEBHOOK_SECRET=your-webhook-secret
```

### What the Package Now Provides

✅ **Laravel-Specific Features:**
- Middleware for route protection
- Auto-login and user synchronization
- Event system for subscription changes
- Webhook handling with signature verification
- Intelligent caching layer

✅ **Convenience Methods:**
- `loginFromCheckAccess()` - Check access + auto-login
- `hasProductAccess()` - Quick product access check
- `hasActiveSubscription()` - Quick subscription check
- `getUserAccess()` - Get all subscriptions with caching

✅ **Direct API Access:**
- `amember()` - Access AMember facade
- `client()` - Access AMemberClient for custom calls

### Benefits

1. **No Duplication** - Uses existing package for API communication
2. **Better Performance** - Check-access API is optimized for this use case
3. **Simpler Code** - Less code to maintain
4. **More Flexible** - Can still access full AM API through facade
5. **Laravel Integration** - Adds missing Laravel-specific features

### What This Package Does vs plutuss/amember-pro-laravel

**plutuss/amember-pro-laravel provides:**
- Raw API access to all aMember endpoints
- Basic HTTP client
- Response formatting (JSON/array/collection)

**greatplr/amember-sso adds:**
- SSO integration helpers
- Laravel middleware for access control
- Webhook handling with events
- User synchronization
- Caching layer
- Authentication helpers

Think of it as: `plutuss/amember-pro-laravel` is the **transport layer**, and `greatplr/amember-sso` is the **Laravel application layer**.
