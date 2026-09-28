<?php

namespace Greatplr\AmemberSso\Services;

use Greatplr\AmemberSso\Api\AmemberApiClient;
use Greatplr\AmemberSso\Api\AmemberApiException;
use Greatplr\AmemberSso\Models\AmemberInstallation;
use Greatplr\AmemberSso\Support\UserDataSync;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Every method that calls aMember's API takes an optional installation as its
 * last argument: an AmemberInstallation, its id, or null for the default
 * installation configured in `amember-sso.api` (AMEMBER_URL / AMEMBER_API_KEY).
 */
class AmemberSsoService
{
    protected ?string $secretKey;

    public function __construct(?string $secretKey = null)
    {
        $this->secretKey = $secretKey;
    }

    /**
     * API client for an installation, or for the configured default when null.
     *
     * @throws AmemberApiException when the default isn't configured
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException for an unknown installation id
     */
    public function client(AmemberInstallation|int|null $installation = null): AmemberApiClient
    {
        if ($installation === null) {
            return AmemberApiClient::fromConfig();
        }

        if (is_int($installation)) {
            $installation = AmemberInstallation::findOrFail($installation);
        }

        return $installation->getApiClient();
    }

    /**
     * Check user access by login (username or email), without a password.
     * Uses check-access/by-login.
     *
     * Returns aMember's response when `ok` is true, otherwise null.
     * `subscriptions` maps product id => expiry date (Y-m-d).
     */
    public function checkAccessByLogin(string $login, AmemberInstallation|int|null $installation = null): ?array
    {
        return $this->checkAccess('by-login', ['login' => $login], "login: {$login}", $installation);
    }

    /**
     * Check user access by email, without a password.
     * Uses check-access/by-email.
     */
    public function checkAccessByEmail(string $email, AmemberInstallation|int|null $installation = null): ?array
    {
        return $this->checkAccess('by-email', ['email' => $email], "email: {$email}", $installation);
    }

    /**
     * Authenticate a user by login (username or email) and password.
     * Uses check-access/by-login-pass, or by-login-pass-ip when $ip is given.
     *
     * @param string|null $ip The end user's real IP address, never a server
     *        IP, placeholder or id. aMember writes it to the user's access log
     *        and, when the number of distinct IPs exceeds its account-sharing
     *        limit, LOCKS the account. Behind Cloudflare or a proxy, resolve
     *        the client's real IP first. Pass null if unsure: by-login-pass
     *        does no IP logging.
     */
    public function authenticateByLoginPass(
        string $login,
        string $password,
        ?string $ip = null,
        AmemberInstallation|int|null $installation = null,
    ): ?array {
        $params = ['login' => $login, 'pass' => $password];

        if ($ip) {
            $params['ip'] = $ip;
        }

        $response = $this->checkAccess($ip ? 'by-login-pass-ip' : 'by-login-pass', $params, "authentication: {$login}", $installation);

        if ($response) {
            $this->logInfo("User authenticated: {$login}");
        }

        return $response;
    }

    /**
     * Call a check-access action; null unless aMember answered `ok: true`.
     */
    protected function checkAccess(string $action, array $params, string $subject, AmemberInstallation|int|null $installation): ?array
    {
        try {
            $response = $this->client($installation)->checkAccess($action, $params);

            if (($response['ok'] ?? false) === true) {
                return $response;
            }

            $this->logError(sprintf(
                'aMember check-access/%s failed for %s (code %s: %s)',
                $action,
                $subject,
                $response['code'] ?? 'none',
                $response['msg'] ?? 'no message',
            ));

            return null;
        } catch (\Exception $e) {
            $this->logApiError("check-access/{$action} for {$subject}", $e);

            return null;
        }
    }

    /**
     * Generate SSO login URL for a user.
     *
     * Uses `amember-sso.sso.login_url` (AMEMBER_LOGIN_URL), or else the
     * aMember root derived from `amember-sso.api.url` followed by /login.
     */
    public function generateSsoUrl(string $login, ?string $redirectUrl = null): string
    {
        $redirectUrl = $redirectUrl ?? config('amember-sso.sso.redirect_after_login');
        $loginUrl = $this->defaultLoginUrl();

        if ($this->secretKey) {
            // Use signed SSO link
            $params = [
                'login' => $login,
                'time' => time(),
                'redirect_url' => $redirectUrl,
            ];
            $params['hash'] = $this->generateHash($params);

            return $loginUrl . '?' . http_build_query($params);
        }

        // Simple login redirect
        return $loginUrl . '?amember_redirect_url=' . urlencode($redirectUrl);
    }

    protected function defaultLoginUrl(): string
    {
        if ($loginUrl = config('amember-sso.sso.login_url')) {
            return rtrim($loginUrl, '/');
        }

        $apiUrl = rtrim((string) config('amember-sso.api.url'), '/');

        return preg_replace('#/api$#', '', $apiUrl) . '/login';
    }

    /**
     * Authenticate Laravel user from aMember.
     * This matches the aMember user to local user and logs them in.
     * Does NOT check product access - that's handled by webhooks + local DB.
     */
    public function loginFromAmember(string $loginOrEmail, bool $isEmail = false, AmemberInstallation|int|null $installation = null): ?object
    {
        try {
            // Verify user exists in aMember
            $accessData = $isEmail
                ? $this->checkAccessByEmail($loginOrEmail, $installation)
                : $this->checkAccessByLogin($loginOrEmail, $installation);

            if (!$accessData) {
                $this->logError("User not found in aMember: {$loginOrEmail}");
                return null;
            }

            $amemberUserId = $accessData['user_id'] ?? null;
            $email = $accessData['email'] ?? ($isEmail ? $loginOrEmail : null);

            // Full user record, for syncing
            $amemberUser = $isEmail
                ? $this->findUser(['email' => $loginOrEmail], $installation)
                : $this->getUserByLogin($loginOrEmail, $installation);

            if ($amemberUser) {
                $amemberUserId = $amemberUser['user_id'] ?? $amemberUserId;
                $email = $amemberUser['email'] ?? $email;
            }

            // Find local user - try amember_user_id first, then email
            $user = $this->findLocalUser($amemberUserId !== null ? (int) $amemberUserId : null, $email);

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
     * Get a user record by username from /api/users.
     */
    public function getUserByLogin(string $login, AmemberInstallation|int|null $installation = null): ?array
    {
        return $this->findUser(['login' => $login], $installation);
    }

    /**
     * Get a user record by aMember user_id from /api/users.
     */
    public function getUserById(int $userId, AmemberInstallation|int|null $installation = null): ?array
    {
        return $this->findUser(['user_id' => $userId], $installation);
    }

    /**
     * First /api/users record matching the filter, or null.
     */
    protected function findUser(array $filter, AmemberInstallation|int|null $installation): ?array
    {
        try {
            return $this->client($installation)->list('users', $filter, 1)[0] ?? null;
        } catch (\Exception $e) {
            $this->logApiError('users lookup', $e);

            return null;
        }
    }

    /**
     * Get user access/subscriptions using check-access API (cached when enabled).
     * Returns subscription data with expiration dates.
     */
    public function getUserAccess(string $loginOrEmail, bool $isEmail = false, AmemberInstallation|int|null $installation = null): ?array
    {
        if (config('amember-sso.access_control.cache_enabled')) {
            return Cache::remember(
                $this->accessCacheKey($loginOrEmail, $installation),
                config('amember-sso.access_control.cache_ttl'),
                fn () => $this->fetchUserAccess($loginOrEmail, $isEmail, $installation)
            );
        }

        return $this->fetchUserAccess($loginOrEmail, $isEmail, $installation);
    }

    /**
     * Fetch user access from check-access API.
     */
    protected function fetchUserAccess(string $loginOrEmail, bool $isEmail = false, AmemberInstallation|int|null $installation = null): ?array
    {
        return $isEmail
            ? $this->checkAccessByEmail($loginOrEmail, $installation)
            : $this->checkAccessByLogin($loginOrEmail, $installation);
    }

    /**
     * Check if user has access to a specific product.
     * Uses the check-access API which returns active subscriptions.
     */
    public function hasProductAccess(string $loginOrEmail, int|array $productIds, bool $isEmail = false, AmemberInstallation|int|null $installation = null): bool
    {
        $productIds = (array) $productIds;
        $accessData = $this->getUserAccess($loginOrEmail, $isEmail, $installation);

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
    public function hasActiveSubscription(string $loginOrEmail, bool $isEmail = false, AmemberInstallation|int|null $installation = null): bool
    {
        $accessData = $this->getUserAccess($loginOrEmail, $isEmail, $installation);

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
     * Get a user's access records from /api/access, each with its product
     * record from /api/products under `product` (null if it couldn't be read).
     *
     * aMember's access controller has no nested relations, so the products
     * are fetched separately, once per distinct product.
     *
     * @return list<array<string, mixed>>
     */
    public function getAccessRecords(int $userId, AmemberInstallation|int|null $installation = null): array
    {
        try {
            $client = $this->client($installation);

            $records = [];
            $page = 0;
            do {
                $batch = $client->list('access', ['user_id' => $userId], AmemberApiClient::MAX_PAGE_SIZE, $page++);
                array_push($records, ...$batch);
            } while (count($batch) === AmemberApiClient::MAX_PAGE_SIZE);

            $products = [];
            foreach (array_unique(array_column($records, 'product_id')) as $productId) {
                try {
                    $products[$productId] = $client->find('products', $productId);
                } catch (\Exception $e) {
                    $this->logApiError("product {$productId} lookup", $e);
                    $products[$productId] = null;
                }
            }

            return array_map(
                fn (array $record) => $record + ['product' => $products[$record['product_id'] ?? ''] ?? null],
                $records
            );
        } catch (\Exception $e) {
            $this->logApiError('access records lookup', $e);

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
        $changed = UserDataSync::apply($user, $amemberUser);

        if ($changed) {
            $user->save();
        }

        return $user;
    }

    /**
     * Clear cached access data for a user. Clears the default installation's
     * entry and, when given, the installation's own entry.
     */
    public function clearAccessCache(string $loginOrEmail, AmemberInstallation|int|null $installation = null): void
    {
        Cache::forget($this->accessCacheKey($loginOrEmail, null));

        if ($installation !== null) {
            Cache::forget($this->accessCacheKey($loginOrEmail, $installation));
        }
    }

    protected function accessCacheKey(string $loginOrEmail, AmemberInstallation|int|null $installation): string
    {
        $installationId = $installation instanceof AmemberInstallation ? $installation->getKey() : $installation;

        return $installationId === null
            ? 'amember_access_' . md5($loginOrEmail)
            : "amember_access_{$installationId}_" . md5($loginOrEmail);
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
    protected function logError(string $message, array $context = []): void
    {
        if (config('amember-sso.logging.enabled')) {
            Log::channel(config('amember-sso.logging.channel'))->error($message, $context);
        }
    }

    /**
     * Log a failed API call with its HTTP status (null when there was no response).
     */
    protected function logApiError(string $what, \Exception $e): void
    {
        $this->logError("aMember API error during {$what}: {$e->getMessage()}", [
            'status' => $e instanceof AmemberApiException ? $e->status : null,
        ]);
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
