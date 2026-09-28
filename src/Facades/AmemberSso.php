<?php

namespace Greatplr\AmemberSso\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * API methods take an optional installation last: an AmemberInstallation, its
 * id, or null for the default configured in amember-sso.api.
 *
 * @method static ?array checkAccessByLogin(string $login, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static ?array checkAccessByEmail(string $email, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static ?array authenticateByLoginPass(string $login, string $password, ?string $ip = null, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static string generateSsoUrl(string $login, ?string $redirectUrl = null)
 * @method static ?object loginFromAmember(string $loginOrEmail, bool $isEmail = false, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static ?array getUserByLogin(string $login, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static ?array getUserById(int $userId, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static ?array getUserAccess(string $loginOrEmail, bool $isEmail = false, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static bool hasProductAccess(string $loginOrEmail, int|array $productIds, bool $isEmail = false, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static bool hasActiveSubscription(string $loginOrEmail, bool $isEmail = false, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static array getAccessRecords(int $userId, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static void clearAccessCache(string $loginOrEmail, \Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
 * @method static \Greatplr\AmemberSso\Api\AmemberApiClient client(\Greatplr\AmemberSso\Models\AmemberInstallation|int|null $installation = null)
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
