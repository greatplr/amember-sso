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
