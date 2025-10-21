<?php

namespace Greatplr\AmemberSso\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string generateLoginUrl(string $email, ?string $redirectUrl = null)
 * @method static bool verifySsoToken(array $data)
 * @method static ?object authenticateUser(string $email)
 * @method static ?object getAmemberUser(string $email)
 * @method static array getUserSubscriptions(int $userId)
 * @method static bool hasProductAccess(int $userId, int|array $productIds)
 * @method static bool hasActiveSubscription(int $userId)
 * @method static void clearSubscriptionCache(int $userId)
 * @method static \Plutuss\AmemberProLaravel\AmemberApi getApiClient()
 *
 * @see \Greatplr\AmemberSso\Services\AmemberSsoService
 */
class AmemberSso extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'amember-sso';
    }
}
