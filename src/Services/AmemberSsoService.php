<?php

namespace Greatplr\AmemberSso\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Plutuss\AMember\Facades\AMember;
use Plutuss\AMember\AMemberClient;

class AmemberSsoService
{
    protected ?string $secretKey;

    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * Check user access by login (email/username).
     * Uses the aMember check-access API.
     */
    public function checkAccessByLogin(string $login): ?array
    {
        try {
            $response = AMemberClient::getInstance()
                ->setOption('/check-access/by-login', ['login' => $login])
                ->sendPost();

            if ($response && isset($response['ok']) && $response['ok'] === true) {
                return $response;
            }

            $this->logError("Access check failed for login: {$login}");
            return null;
        } catch (\Exception $e) {
            $this->logError("Check access API error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Check user access by email.
     * Uses the aMember check-access API.
     */
    public function checkAccessByEmail(string $email): ?array
    {
        try {
            $response = AMemberClient::getInstance()
                ->setOption('/check-access/by-email', ['email' => $email])
                ->sendPost();

            if ($response && isset($response['ok']) && $response['ok'] === true) {
                return $response;
            }

            $this->logError("Access check failed for email: {$email}");
            return null;
        } catch (\Exception $e) {
            $this->logError("Check access API error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Authenticate user by login and password.
     * Uses the aMember check-access API.
     */
    public function authenticateByLoginPass(string $login, string $password, ?string $ip = null): ?array
    {
        try {
            $params = [
                'login' => $login,
                'pass' => $password,
            ];

            if ($ip) {
                $params['ip'] = $ip;
                $endpoint = '/check-access/by-login-pass-ip';
            } else {
                $endpoint = '/check-access/by-login-pass';
            }

            $response = AMemberClient::getInstance()
                ->setOption($endpoint, $params)
                ->sendPost();

            if ($response && isset($response['ok']) && $response['ok'] === true) {
                $this->logInfo("User authenticated: {$login}");
                return $response;
            }

            $this->logError("Authentication failed for: {$login}");
            return null;
        } catch (\Exception $e) {
            $this->logError("Authentication API error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Generate SSO login URL for a user.
     * This uses aMember's SSO functionality if configured.
     */
    public function generateSsoUrl(string $login, ?string $redirectUrl = null): string
    {
        $redirectUrl = $redirectUrl ?? config('amember-sso.sso.redirect_after_login');
        $amemberUrl = config('amember.url');

        if ($this->secretKey) {
            // Use signed SSO link
            $params = [
                'login' => $login,
                'time' => time(),
                'redirect_url' => $redirectUrl,
            ];
            $params['hash'] = $this->generateHash($params);

            return $amemberUrl . '/login?' . http_build_query($params);
        }

        // Simple login redirect
        return $amemberUrl . '/login?amember_redirect_url=' . urlencode($redirectUrl);
    }

    /**
     * Authenticate Laravel user from aMember.
     * This matches the aMember user to local user and logs them in.
     * Does NOT check product access - that's handled by webhooks + local DB.
     */
    public function loginFromAmember(string $loginOrEmail, bool $isEmail = false): ?object
    {
        try {
            // Verify user exists in aMember
            $accessData = $isEmail
                ? $this->checkAccessByEmail($loginOrEmail)
                : $this->checkAccessByLogin($loginOrEmail);

            if (!$accessData || !$accessData['ok']) {
                $this->logError("User not found in aMember: {$loginOrEmail}");
                return null;
            }

            // Get aMember user ID from response or fetch full user data
            $amemberUserId = null;
            $email = null;

            // Try to get user_id from access data if available
            if (isset($accessData['user_id'])) {
                $amemberUserId = $accessData['user_id'];
            }

            // Get full user data if needed
            $amemberUser = $this->getUserByLogin($loginOrEmail);

            if ($amemberUser) {
                $amemberUserId = $amemberUser['user_id'] ?? $amemberUserId;
                $email = $amemberUser['email'] ?? $loginOrEmail;
            } else {
                $email = $isEmail ? $loginOrEmail : null;
            }

            // Find local user - try amember_user_id first, then email
            $user = $this->findLocalUser($amemberUserId, $email);

            if (!$user) {
                $this->logError("User not found locally. They need to be created via webhook first: {$loginOrEmail}");
                return null;
            }

            // Optionally sync user data
            if ($amemberUser && config('amember-sso.access_control.sync_user_data')) {
                $user = $this->syncUserData($user, $amemberUser);
            }

            // Log the user in
            $guard = config('amember-sso.guard');
            Auth::guard($guard)->login($user);

            $this->logInfo("User authenticated: {$email}");

            return $user;
        } catch (\Exception $e) {
            $this->logError("Login failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Find local user by aMember user ID or email.
     * Prioritizes amember_user_id for matching.
     */
    protected function findLocalUser(?int $amemberUserId, ?string $email): ?object
    {
        $userModel = config('amember-sso.user_model');

        // First try to find by amember_user_id (most reliable)
        if ($amemberUserId) {
            $user = $userModel::where('amember_user_id', $amemberUserId)->first();
            if ($user) {
                return $user;
            }
        }

        // Fall back to email
        if ($email) {
            $user = $userModel::where('email', $email)->first();

            // If found by email but doesn't have amember_user_id, update it
            if ($user && $amemberUserId && !$user->amember_user_id) {
                $user->amember_user_id = $amemberUserId;
                $user->save();
                $this->logInfo("Updated amember_user_id for user: {$email}");
            }

            return $user;
        }

        return null;
    }

    /**
     * Get user from aMember API using the users endpoint.
     */
    public function getUserByLogin(string $login): ?array
    {
        try {
            $response = AMember::users()
                ->filter(['login' => $login])
                ->count(1)
                ->getUsers();

            // Response is a collection, get first item
            if ($response && $response->count() > 0) {
                return $response->first();
            }

            return null;
        } catch (\Exception $e) {
            $this->logError("Failed to get aMember user: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get user by aMember user_id.
     */
    public function getUserById(int $userId): ?array
    {
        try {
            $response = AMember::users()
                ->filter(['user_id' => $userId])
                ->count(1)
                ->getUsers();

            if ($response && $response->count() > 0) {
                return $response->first();
            }

            return null;
        } catch (\Exception $e) {
            $this->logError("Failed to get aMember user: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get user access/subscriptions using check-access API.
     * Returns subscription data with expiration dates.
     */
    public function getUserAccess(string $loginOrEmail, bool $isEmail = false): ?array
    {
        $cacheKey = "amember_access_" . md5($loginOrEmail);

        if (config('amember-sso.access_control.cache_enabled')) {
            return Cache::remember($cacheKey, config('amember-sso.access_control.cache_ttl'), function () use ($loginOrEmail, $isEmail) {
                return $this->fetchUserAccess($loginOrEmail, $isEmail);
            });
        }

        return $this->fetchUserAccess($loginOrEmail, $isEmail);
    }

    /**
     * Fetch user access from check-access API.
     */
    protected function fetchUserAccess(string $loginOrEmail, bool $isEmail = false): ?array
    {
        $accessData = $isEmail
            ? $this->checkAccessByEmail($loginOrEmail)
            : $this->checkAccessByLogin($loginOrEmail);

        return $accessData;
    }

    /**
     * Check if user has access to a specific product.
     * Uses the check-access API which returns active subscriptions.
     */
    public function hasProductAccess(string $loginOrEmail, int|array $productIds, bool $isEmail = false): bool
    {
        $productIds = (array) $productIds;
        $accessData = $this->getUserAccess($loginOrEmail, $isEmail);

        if (!$accessData || !isset($accessData['subscriptions'])) {
            return false;
        }

        // aMember returns subscriptions as: { product_id: "expiration_date", ... }
        $subscriptions = $accessData['subscriptions'];

        foreach ($productIds as $productId) {
            if (isset($subscriptions[$productId])) {
                // Check if not expired
                $expirationDate = $subscriptions[$productId];
                if ($this->isSubscriptionValid($expirationDate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if subscription expiration date is still valid.
     */
    protected function isSubscriptionValid(string $expirationDate): bool
    {
        // Check if expiration is in the future or is a lifetime subscription (2050-01-01)
        $expireTimestamp = strtotime($expirationDate);
        return $expireTimestamp > time();
    }

    /**
     * Check if user has any active subscription.
     */
    public function hasActiveSubscription(string $loginOrEmail, bool $isEmail = false): bool
    {
        $accessData = $this->getUserAccess($loginOrEmail, $isEmail);

        if (!$accessData || !isset($accessData['subscriptions'])) {
            return false;
        }

        // Check if any subscription is still valid
        foreach ($accessData['subscriptions'] as $expirationDate) {
            if ($this->isSubscriptionValid($expirationDate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get access records for a user (detailed subscription info).
     * Uses the access() API endpoint.
     */
    public function getAccessRecords(int $userId): array
    {
        try {
            $response = AMember::access()
                ->filter(['user_id' => $userId])
                ->nested(['product'])
                ->getAccess();

            return $response ? $response->toArray() : [];
        } catch (\Exception $e) {
            $this->logError("Failed to fetch access records: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Create local user from aMember data.
     */
    protected function createLocalUser(array $amemberUser, array $accessData): object
    {
        $userModel = config('amember-sso.user_model');

        $userData = [
            'email' => $amemberUser['email'],
            'name' => trim(($amemberUser['name_f'] ?? '') . ' ' . ($amemberUser['name_l'] ?? '')),
            'amember_user_id' => $amemberUser['user_id'] ?? null,
            'password' => bcrypt(bin2hex(random_bytes(16))), // Random password
        ];

        return $userModel::create($userData);
    }

    /**
     * Sync user data from aMember.
     */
    protected function syncUserData(object $user, array $amemberUser): object
    {
        $syncFields = config('amember-sso.access_control.syncable_fields', []);

        $updated = false;
        foreach ($syncFields as $field) {
            if (isset($amemberUser[$field]) && $user->$field !== $amemberUser[$field]) {
                $user->$field = $amemberUser[$field];
                $updated = true;
            }
        }

        if ($updated) {
            $user->save();
        }

        return $user;
    }

    /**
     * Clear cached access data for a user.
     */
    public function clearAccessCache(string $loginOrEmail): void
    {
        Cache::forget("amember_access_" . md5($loginOrEmail));
    }

    /**
     * Generate hash for SSO parameters.
     */
    protected function generateHash(array $params): string
    {
        $baseString = '';
        ksort($params);

        foreach ($params as $key => $value) {
            if ($key !== 'hash') {
                $baseString .= $key . $value;
            }
        }

        return hash_hmac('sha256', $baseString, $this->secretKey);
    }

    /**
     * Verify hash in SSO parameters.
     */
    protected function verifyHash(array $params): bool
    {
        $receivedHash = $params['hash'] ?? '';
        unset($params['hash']);

        $calculatedHash = $this->generateHash($params);

        return hash_equals($calculatedHash, $receivedHash);
    }

    /**
     * Log info message.
     */
    protected function logInfo(string $message): void
    {
        if (config('amember-sso.logging.enabled')) {
            Log::channel(config('amember-sso.logging.channel'))->info($message);
        }
    }

    /**
     * Log error message.
     */
    protected function logError(string $message): void
    {
        if (config('amember-sso.logging.enabled')) {
            Log::channel(config('amember-sso.logging.channel'))->error($message);
        }
    }

    /**
     * Get direct access to the AMember facade for advanced usage.
     */
    public function amember()
    {
        return AMember::getFacadeRoot();
    }

    /**
     * Get direct access to AMemberClient for custom API calls.
     */
    public function client(): AMemberClient
    {
        return AMemberClient::getInstance();
    }

    /**
     * Product Mapping Methods
     */

    /**
     * Get product mapping for an aMember product ID.
     */
    public function getProductMapping(string $amemberProductId, $installationId): ?\Greatplr\AmemberSso\Models\AmemberProduct
    {
        return \Greatplr\AmemberSso\Models\AmemberProduct::findByAmemberProduct($amemberProductId, $installationId);
    }

    /**
     * Get product mapping by tier.
     */
    public function getProductByTier(string $tier, $installationId): ?\Greatplr\AmemberSso\Models\AmemberProduct
    {
        return \Greatplr\AmemberSso\Models\AmemberProduct::findByTier($tier, $installationId);
    }

    /**
     * Check if user has specific tier access.
     */
    public function hasTierAccess(string $amemberUserId, string $tier, $installationId = null): bool
    {
        $product = $this->getProductByTier($tier, $installationId);

        if (!$product) {
            return false;
        }

        return $this->hasProductAccessLocal($amemberUserId, $product->product_id, $installationId);
    }

    /**
     * Get user's active tier(s).
     */
    public function getUserTiers(string $amemberUserId, $installationId = null): array
    {
        $tableName = config('amember-sso.tables.subscriptions');
        $productsTable = config('amember-sso.tables.products');

        $query = \Illuminate\Support\Facades\DB::table($tableName)
            ->join($productsTable, function ($join) use ($tableName, $productsTable) {
                $join->on("$tableName.product_id", '=', "$productsTable.product_id")
                     ->on("$tableName.installation_id", '=', "$productsTable.installation_id");
            })
            ->where("$tableName.user_id", $amemberUserId)
            ->where("$tableName.status", 'active')
            ->where(function ($q) use ($tableName) {
                $q->whereNull("$tableName.expire_date")
                  ->orWhere("$tableName.expire_date", '>', now());
            })
            ->whereNotNull("$productsTable.tier");

        if ($installationId) {
            $query->where("$tableName.installation_id", $installationId);
        }

        return $query->pluck("$productsTable.tier")->unique()->toArray();
    }

    /**
     * Get user's highest tier (based on sort_order).
     */
    public function getUserHighestTier(string $amemberUserId, $installationId = null): ?string
    {
        $tableName = config('amember-sso.tables.subscriptions');
        $productsTable = config('amember-sso.tables.products');

        $query = \Illuminate\Support\Facades\DB::table($tableName)
            ->join($productsTable, function ($join) use ($tableName, $productsTable) {
                $join->on("$tableName.product_id", '=', "$productsTable.product_id")
                     ->on("$tableName.installation_id", '=', "$productsTable.installation_id");
            })
            ->where("$tableName.user_id", $amemberUserId)
            ->where("$tableName.status", 'active')
            ->where(function ($q) use ($tableName) {
                $q->whereNull("$tableName.expire_date")
                  ->orWhere("$tableName.expire_date", '>', now());
            })
            ->whereNotNull("$productsTable.tier");

        if ($installationId) {
            $query->where("$tableName.installation_id", $installationId);
        }

        return $query->orderBy("$productsTable.sort_order", 'desc')
            ->value("$productsTable.tier");
    }

    /**
     * Check if user has feature access based on product features.
     */
    public function hasFeatureAccess(string $amemberUserId, string $feature, $installationId = null): bool
    {
        $tableName = config('amember-sso.tables.subscriptions');
        $productsTable = config('amember-sso.tables.products');

        $query = \Illuminate\Support\Facades\DB::table($tableName)
            ->join($productsTable, function ($join) use ($tableName, $productsTable) {
                $join->on("$tableName.product_id", '=', "$productsTable.product_id")
                     ->on("$tableName.installation_id", '=', "$productsTable.installation_id");
            })
            ->where("$tableName.user_id", $amemberUserId)
            ->where("$tableName.status", 'active')
            ->where(function ($q) use ($tableName) {
                $q->whereNull("$tableName.expire_date")
                  ->orWhere("$tableName.expire_date", '>', now());
            });

        if ($installationId) {
            $query->where("$tableName.installation_id", $installationId);
        }

        $products = $query->get(["$productsTable.features"]);

        foreach ($products as $product) {
            $features = json_decode($product->features, true) ?? [];
            if (isset($features[$feature]) && $features[$feature]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get feature value from user's products (returns highest/best value).
     */
    public function getFeatureValue(string $amemberUserId, string $feature, $installationId = null, $default = null)
    {
        $tableName = config('amember-sso.tables.subscriptions');
        $productsTable = config('amember-sso.tables.products');

        $query = \Illuminate\Support\Facades\DB::table($tableName)
            ->join($productsTable, function ($join) use ($tableName, $productsTable) {
                $join->on("$tableName.product_id", '=', "$productsTable.product_id")
                     ->on("$tableName.installation_id", '=', "$productsTable.installation_id");
            })
            ->where("$tableName.user_id", $amemberUserId)
            ->where("$tableName.status", 'active')
            ->where(function ($q) use ($tableName) {
                $q->whereNull("$tableName.expire_date")
                  ->orWhere("$tableName.expire_date", '>', now());
            })
            ->orderBy("$productsTable.sort_order", 'desc');

        if ($installationId) {
            $query->where("$tableName.installation_id", $installationId);
        }

        $products = $query->get(["$productsTable.features"]);

        $values = [];
        foreach ($products as $product) {
            $features = json_decode($product->features, true) ?? [];
            if (isset($features[$feature])) {
                $values[] = $features[$feature];
            }
        }

        if (empty($values)) {
            return $default;
        }

        // Return highest numeric value, or first non-null for other types
        if (is_numeric($values[0])) {
            return max($values);
        }

        return $values[0];
    }

    /**
     * Get user's mappable models (polymorphic).
     * Returns collection of models that user has access to.
     */
    public function getUserMappables(string $amemberUserId, ?string $mappableType = null, $installationId = null): \Illuminate\Support\Collection
    {
        $tableName = config('amember-sso.tables.subscriptions');
        $productsTable = config('amember-sso.tables.products');

        $query = \Illuminate\Support\Facades\DB::table($tableName)
            ->join($productsTable, function ($join) use ($tableName, $productsTable) {
                $join->on("$tableName.product_id", '=', "$productsTable.product_id")
                     ->on("$tableName.installation_id", '=', "$productsTable.installation_id");
            })
            ->where("$tableName.user_id", $amemberUserId)
            ->where("$tableName.status", 'active')
            ->where(function ($q) use ($tableName) {
                $q->whereNull("$tableName.expire_date")
                  ->orWhere("$tableName.expire_date", '>', now());
            })
            ->whereNotNull("$productsTable.mappable_type")
            ->whereNotNull("$productsTable.mappable_id");

        if ($mappableType) {
            $query->where("$productsTable.mappable_type", $mappableType);
        }

        if ($installationId) {
            $query->where("$tableName.installation_id", $installationId);
        }

        $results = $query->get(["$productsTable.mappable_type", "$productsTable.mappable_id"]);

        return $results->map(function ($item) {
            $modelClass = $item->mappable_type;
            if (class_exists($modelClass)) {
                return $modelClass::find($item->mappable_id);
            }
            return null;
        })->filter();
    }

    /**
     * Check if user has access to a specific mappable model.
     */
    public function hasMappableAccess(string $amemberUserId, string $mappableType, $mappableId, $installationId = null): bool
    {
        $tableName = config('amember-sso.tables.subscriptions');
        $productsTable = config('amember-sso.tables.products');

        $query = \Illuminate\Support\Facades\DB::table($tableName)
            ->join($productsTable, function ($join) use ($tableName, $productsTable) {
                $join->on("$tableName.product_id", '=', "$productsTable.product_id")
                     ->on("$tableName.installation_id", '=', "$productsTable.installation_id");
            })
            ->where("$tableName.user_id", $amemberUserId)
            ->where("$tableName.status", 'active')
            ->where(function ($q) use ($tableName) {
                $q->whereNull("$tableName.expire_date")
                  ->orWhere("$tableName.expire_date", '>', now());
            })
            ->where("$productsTable.mappable_type", $mappableType)
            ->where("$productsTable.mappable_id", $mappableId);

        if ($installationId) {
            $query->where("$tableName.installation_id", $installationId);
        }

        return $query->exists();
    }

    /**
     * Get product mappings for a specific mappable model.
     */
    public function getProductsForMappable(string $mappableType, $mappableId, $installationId = null): \Illuminate\Database\Eloquent\Collection
    {
        return \Greatplr\AmemberSso\Models\AmemberProduct::findByMappable($mappableType, $mappableId, $installationId);
    }

    /**
     * Check if user has access to ANY model of a specific type.
     * Example: Check if user has access to any Course.
     */
    public function hasAnyMappableTypeAccess(string $amemberUserId, string $mappableType, $installationId = null): bool
    {
        $tableName = config('amember-sso.tables.subscriptions');
        $productsTable = config('amember-sso.tables.products');

        $query = \Illuminate\Support\Facades\DB::table($tableName)
            ->join($productsTable, function ($join) use ($tableName, $productsTable) {
                $join->on("$tableName.product_id", '=', "$productsTable.product_id")
                     ->on("$tableName.installation_id", '=', "$productsTable.installation_id");
            })
            ->where("$tableName.user_id", $amemberUserId)
            ->where("$tableName.status", 'active')
            ->where(function ($q) use ($tableName) {
                $q->whereNull("$tableName.expire_date")
                  ->orWhere("$tableName.expire_date", '>', now());
            })
            ->where("$productsTable.mappable_type", $mappableType);

        if ($installationId) {
            $query->where("$tableName.installation_id", $installationId);
        }

        return $query->exists();
    }
}
