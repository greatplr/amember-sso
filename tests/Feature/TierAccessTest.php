<?php

namespace Greatplr\AmemberSso\Tests\Feature;

use Greatplr\AmemberSso\Models\AmemberInstallation;
use Greatplr\AmemberSso\Models\AmemberProduct;
use Greatplr\AmemberSso\Services\AmemberSsoService;
use Greatplr\AmemberSso\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * hasTierAccess() resolves the tier to a product, then checks the local
 * subscriptions table for an active, unexpired row in that installation.
 */
class TierAccessTest extends TestCase
{
    use RefreshDatabase;

    protected AmemberInstallation $installation;

    protected AmemberInstallation $otherInstallation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installation = $this->makeInstallation('main');
        $this->otherInstallation = $this->makeInstallation('other');

        AmemberProduct::create([
            'installation_id' => $this->installation->id,
            'product_id' => '5',
            'title' => 'Premium Plan',
            'tier' => 'premium',
        ]);
    }

    protected function makeInstallation(string $slug): AmemberInstallation
    {
        return AmemberInstallation::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'api_url' => "https://{$slug}.example.com/amember/api",
            'api_key' => "{$slug}-key",
            'is_active' => true,
        ]);
    }

    protected function subscribe(array $attributes = []): void
    {
        static $accessId = 1;

        DB::table(config('amember-sso.tables.subscriptions'))->insert(array_merge([
            'access_id' => $accessId++,
            'installation_id' => $this->installation->id,
            'user_id' => 123,
            'product_id' => 5,
            'status' => 'active',
            'begin_date' => now()->subMonth(),
            'expire_date' => now()->addMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    protected function hasPremium(): bool
    {
        return app(AmemberSsoService::class)->hasTierAccess('123', 'premium', $this->installation->id);
    }

    #[Test]
    public function an_active_subscription_grants_the_tier(): void
    {
        $this->subscribe();

        $this->assertTrue($this->hasPremium());
    }

    #[Test]
    public function a_subscription_without_an_expire_date_grants_the_tier(): void
    {
        $this->subscribe(['expire_date' => null]);

        $this->assertTrue($this->hasPremium());
    }

    #[Test]
    public function an_expired_subscription_does_not_grant_the_tier(): void
    {
        $this->subscribe(['expire_date' => now()->subDay()]);

        $this->assertFalse($this->hasPremium());
    }

    #[Test]
    public function a_non_active_status_does_not_grant_the_tier(): void
    {
        $this->subscribe(['status' => 'expired']);

        $this->assertFalse($this->hasPremium());
    }

    #[Test]
    public function a_subscription_in_another_installation_does_not_grant_the_tier(): void
    {
        $this->subscribe(['installation_id' => $this->otherInstallation->id]);

        $this->assertFalse($this->hasPremium());
    }

    #[Test]
    public function another_users_subscription_does_not_grant_the_tier(): void
    {
        $this->subscribe(['user_id' => 999]);

        $this->assertFalse($this->hasPremium());
    }
}
