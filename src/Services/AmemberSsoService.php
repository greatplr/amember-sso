<?php

namespace Greatplr\AmemberSso\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plutuss\AmemberProLaravel\AmemberApi;

class AmemberSsoService
{
    protected string $apiUrl;
    protected string $apiKey;
    protected string $secretKey;
    protected AmemberApi $amemberApi;

    public function __construct(string $apiUrl, string $apiKey, string $secretKey)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->secretKey = $secretKey;
        $this->amemberApi = new AmemberApi($apiUrl, $apiKey);
    }

    /**
     * Generate SSO login URL for a user.
     */
    public function generateLoginUrl(string $email, ?string $redirectUrl = null): string
    {
        $redirectUrl = $redirectUrl ?? config('amember-sso.sso.redirect_after_login');

        $params = [
            'login' => $email,
            'time' => time(),
            'redirect_url' => $redirectUrl,
        ];

        $params['hash'] = $this->generateHash($params);

        return $this->apiUrl . '/api/check-access/by-login-link?' . http_build_query($params);
    }

    /**
     * Verify SSO token and authenticate user.
     */
    public function verifySsoToken(array $data): bool
    {
        if (!$this->verifyHash($data)) {
            $this->logError('SSO token verification failed: Invalid hash');
            return false;
        }

        // Check token expiration (5 minute window)
        $time = $data['time'] ?? 0;
        if (abs(time() - $time) > 300) {
            $this->logError('SSO token verification failed: Token expired');
            return false;
        }

        return true;
    }

    /**
     * Authenticate user via SSO.
     */
    public function authenticateUser(string $email): ?object
    {
        try {
            // Get user from aMember
            $amemberUser = $this->getAmemberUser($email);

            if (!$amemberUser) {
                $this->logError("User not found in aMember: {$email}");
                return null;
            }

            // Find or create local user
            $userModel = config('amember-sso.user_model');
            $user = $userModel::where('email', $email)->first();

            if (!$user) {
                $user = $this->createLocalUser($amemberUser);
            } elseif (config('amember-sso.access_control.sync_user_data')) {
                $user = $this->syncUserData($user, $amemberUser);
            }

            // Log the user in
            $guard = config('amember-sso.guard');
            Auth::guard($guard)->login($user);

            $this->logInfo("User authenticated via SSO: {$email}");

            return $user;
        } catch (\Exception $e) {
            $this->logError("SSO authentication failed: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get user from aMember API.
     */
    public function getAmemberUser(string $email): ?object
    {
        try {
            $response = $this->amemberApi->getUserByLogin($email);
            return $response ? (object) $response : null;
        } catch (\Exception $e) {
            $this->logError("Failed to get aMember user: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Get user subscriptions from aMember.
     */
    public function getUserSubscriptions(int $userId): array
    {
        $cacheKey = "amember_subscriptions_{$userId}";

        if (config('amember-sso.access_control.cache_enabled')) {
            return Cache::remember($cacheKey, config('amember-sso.access_control.cache_ttl'), function () use ($userId) {
                return $this->fetchUserSubscriptions($userId);
            });
        }

        return $this->fetchUserSubscriptions($userId);
    }

    /**
     * Fetch user subscriptions from API.
     */
    protected function fetchUserSubscriptions(int $userId): array
    {
        try {
            $response = $this->amemberApi->getAccessRecords($userId);
            return $response ?? [];
        } catch (\Exception $e) {
            $this->logError("Failed to fetch user subscriptions: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Check if user has access to a specific product.
     */
    public function hasProductAccess(int $userId, int|array $productIds): bool
    {
        $productIds = (array) $productIds;
        $subscriptions = $this->getUserSubscriptions($userId);

        foreach ($subscriptions as $subscription) {
            if (in_array($subscription['product_id'] ?? 0, $productIds)) {
                // Check if subscription is active
                if ($this->isSubscriptionActive($subscription)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if subscription is active.
     */
    protected function isSubscriptionActive(array $subscription): bool
    {
        $now = time();
        $beginDate = strtotime($subscription['begin_date'] ?? 'now');
        $expireDate = $subscription['expire_date']
            ? strtotime($subscription['expire_date'])
            : null;

        return $now >= $beginDate && ($expireDate === null || $now <= $expireDate);
    }

    /**
     * Check if user has any active subscription.
     */
    public function hasActiveSubscription(int $userId): bool
    {
        $subscriptions = $this->getUserSubscriptions($userId);

        foreach ($subscriptions as $subscription) {
            if ($this->isSubscriptionActive($subscription)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create local user from aMember data.
     */
    protected function createLocalUser(object $amemberUser): object
    {
        $userModel = config('amember-sso.user_model');

        $userData = [
            'email' => $amemberUser->email,
            'name' => trim(($amemberUser->name_f ?? '') . ' ' . ($amemberUser->name_l ?? '')),
            'amember_user_id' => $amemberUser->user_id ?? null,
            'password' => bcrypt(bin2hex(random_bytes(16))), // Random password
        ];

        return $userModel::create($userData);
    }

    /**
     * Sync user data from aMember.
     */
    protected function syncUserData(object $user, object $amemberUser): object
    {
        $syncFields = config('amember-sso.access_control.syncable_fields', []);

        $updated = false;
        foreach ($syncFields as $field) {
            if (isset($amemberUser->$field) && $user->$field !== $amemberUser->$field) {
                $user->$field = $amemberUser->$field;
                $updated = true;
            }
        }

        if ($updated) {
            $user->save();
        }

        return $user;
    }

    /**
     * Clear cached subscription data for a user.
     */
    public function clearSubscriptionCache(int $userId): void
    {
        Cache::forget("amember_subscriptions_{$userId}");
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
     * Get the underlying AmemberApi instance.
     */
    public function getApiClient(): AmemberApi
    {
        return $this->amemberApi;
    }
}
