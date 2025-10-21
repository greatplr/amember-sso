<?php

namespace Greatplr\AmemberSso\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static ?array checkAccessByLogin(string $login)
 * @method static ?array checkAccessByEmail(string $email)
 * @method static ?array authenticateByLoginPass(string $login, string $password, ?string $ip = null)
 * @method static string generateSsoUrl(string $login, ?string $redirectUrl = null)
 * @method static ?object loginFromAmember(string $loginOrEmail, bool $isEmail = false)
 * @method static ?array getUserByLogin(string $login)
 * @method static ?array getUserById(int $userId)
 * @method static ?array getUserAccess(string $loginOrEmail, bool $isEmail = false)
 * @method static bool hasProductAccess(string $loginOrEmail, int|array $productIds, bool $isEmail = false)
 * @method static bool hasActiveSubscription(string $loginOrEmail, bool $isEmail = false)
 * @method static array getAccessRecords(int $userId)
 * @method static void clearAccessCache(string $loginOrEmail)
 * @method static mixed amember()
 * @method static \Plutuss\AMember\AMemberClient client()
 *
 * Product Mapping (Tier-based)
 * @method static bool hasTierAccess(string $amemberUserId, string $tier, $installationId = null)
 * @method static array getUserTiers(string $amemberUserId, $installationId = null)
 * @method static ?string getUserHighestTier(string $amemberUserId, $installationId = null)
 * @method static bool hasFeatureAccess(string $amemberUserId, string $feature, $installationId = null)
 * @method static mixed getFeatureValue(string $amemberUserId, string $feature, $installationId = null, $default = null)
 *
 * Product Mapping (Polymorphic)
 * @method static \Illuminate\Support\Collection getUserMappables(string $amemberUserId, ?string $mappableType = null, $installationId = null)
 * @method static bool hasMappableAccess(string $amemberUserId, string $mappableType, $mappableId, $installationId = null)
 * @method static \Illuminate\Database\Eloquent\Collection getProductsForMappable(string $mappableType, $mappableId, $installationId = null)
 * @method static bool hasAnyMappableTypeAccess(string $amemberUserId, string $mappableType, $installationId = null)
 *
 * Testing Helpers
 * @method static array fakeWebhook(string $eventType, array $data = [], ?\Greatplr\AmemberSso\Models\AmemberInstallation $installation = null)
 * @method static void fakeEvents()
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

    /**
     * Fake a webhook for testing.
     */
    public static function fakeWebhook(string $eventType, array $data = [], ?\Greatplr\AmemberSso\Models\AmemberInstallation $installation = null): array
    {
        return \Greatplr\AmemberSso\Testing\AmemberSsoTestHelper::fakeWebhook($eventType, $data, $installation);
    }

    /**
     * Fake all aMember events for testing.
     */
    public static function fakeEvents(): void
    {
        \Greatplr\AmemberSso\Testing\AmemberSsoTestHelper::fakeEvents();
    }
}
