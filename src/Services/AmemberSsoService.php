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
     * Authenticate Laravel user from aMember check-access response.
     * This syncs the user to your local database and logs them in.
     */
    public function loginFromCheckAccess(string $loginOrEmail, bool $isEmail = false): ?object
    {
        try {
            // Check access via aMember API
            $accessData = $isEmail
                ? $this->checkAccessByEmail($loginOrEmail)
                : $this->checkAccessByLogin($loginOrEmail);

            if (!$accessData) {
                $this->logError("Access check failed for: {$loginOrEmail}");
                return null;
            }

            // Get full user data from aMember
            $amemberUser = $this->getUserByLogin($loginOrEmail);

            if (!$amemberUser) {
                $this->logError("User not found in aMember: {$loginOrEmail}");
                return null;
            }

            // Find or create local user
            $userModel = config('amember-sso.user_model');
            $email = $amemberUser['email'] ?? $loginOrEmail;
            $user = $userModel::where('email', $email)->first();

            if (!$user) {
                $user = $this->createLocalUser($amemberUser, $accessData);
            } elseif (config('amember-sso.access_control.sync_user_data')) {
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
}
