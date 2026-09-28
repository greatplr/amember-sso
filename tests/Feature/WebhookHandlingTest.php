<?php

namespace Greatplr\AmemberSso\Tests\Feature;

use Greatplr\AmemberSso\Models\AmemberInstallation;
use Greatplr\AmemberSso\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class WebhookHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected AmemberInstallation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test installation
        $this->installation = AmemberInstallation::create([
            'name' => 'Test Installation',
            'slug' => 'test',
            'api_url' => 'https://example.com/amember/api',
            'api_key' => 'test-key',
            'ip_address' => '127.0.0.1',
            'webhook_secret' => 'test-secret',
            'is_active' => true,
        ]);
    }

    /**
     * POST a webhook the way aMember delivers it: a form-encoded body from the
     * installation's IP, carrying the shared secret header its admin set up.
     */
    protected function postWebhook(array $payload, string $ip = '127.0.0.1', ?string $secret = 'test-secret'): \Illuminate\Testing\TestResponse
    {
        $body = http_build_query($payload);
        parse_str($body, $parameters); // what PHP puts in $_POST: every value a string

        $server = [
            'REMOTE_ADDR' => $ip,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ];

        if ($secret !== null) {
            $server['HTTP_X_AMEMBER_SECRET'] = $secret;
        }

        return $this->call('POST', '/amember/webhook', $parameters, [], [], $server, $body);
    }

    #[Test]
    public function it_handles_subscription_added_webhook(): void
    {
        $response = $this->postWebhook([
            'am-webhooks-version' => '1.0',
            'am-event' => 'subscriptionAdded',
            'am-timestamp' => now()->toIso8601String(),
            'am-root-url' => 'https://example.com/amember',
            'user' => [
                'user_id' => 123,
                'email' => 'test@example.com',
                'login' => 'testuser',
                'name_f' => 'John',
                'name_l' => 'Doe',
                'status' => 1,
                'is_approved' => 1,
            ],
            'product' => [
                'product_id' => 5,
                'title' => 'Premium Plan',
                'description' => 'Full access to all features',
            ],
        ], '127.0.0.1');

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Verify user was created
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'amember_user_id' => 123,
            'amember_installation_id' => $this->installation->id,
        ]);
    }

    #[Test]
    public function it_handles_access_after_insert_webhook(): void
    {
        // Test with real payload structure from aMember 6.3.35 (anonymized)
        $response = $this->postWebhook([
            'am-webhooks-version' => '1.0',
            'am-event' => 'accessAfterInsert',
            'am-timestamp' => '2025-10-20T18:37:07-06:00',
            'am-root-url' => 'https://example.com/members',
            'access' => [
                'access_id' => '3911',
                'invoice_id' => '3053',
                'invoice_public_id' => 'DG9J5',
                'invoice_payment_id' => '3431',
                'invoice_item_id' => '3058',
                'user_id' => '1977',
                'product_id' => '50',
                'transaction_id' => '5T409668ET921644V',
                'begin_date' => '2025-10-20',
                'expire_date' => '2037-12-31',
                'qty' => '1',
            ],
            'user' => [
                'user_id' => '1977',
                'login' => 'johndoe',
                'email' => 'john@example.com',
                'name_f' => 'John',
                'name_l' => 'Doe',
                'country' => 'US',
                'status' => '0',
                'is_approved' => '1',
                'is_locked' => '0',
            ],
        ], '127.0.0.1');

        $response->assertStatus(200);

        // Verify user was created
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'name' => 'John Doe',
            'amember_user_id' => '1977',
            'amember_installation_id' => $this->installation->id,
        ]);

        // Verify subscription was created
        $this->assertDatabaseHas('amember_subscriptions', [
            'access_id' => '3911',
            'user_id' => '1977',
            'product_id' => '50',
            'installation_id' => $this->installation->id,
            'begin_date' => '2025-10-20',
            'expire_date' => '2037-12-31',
        ]);
    }

    #[Test]
    public function it_handles_subscription_deleted_webhook(): void
    {
        // First create a user and subscription
        $userModel = config('amember-sso.user_model');
        $user = $userModel::create([
            'email' => 'test@example.com',
            'name' => 'John Doe',
            'username' => 'testuser',
            'amember_user_id' => 123,
            'amember_installation_id' => $this->installation->id,
            'password' => bcrypt('password'),
        ]);

        // Rows are created from aMember access records, so access_id is required
        DB::table('amember_subscriptions')->insert([
            'installation_id' => $this->installation->id,
            'access_id' => 900,
            'user_id' => 123,
            'product_id' => 5,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Send webhook
        $response = $this->postWebhook([
            'am-webhooks-version' => '1.0',
            'am-event' => 'subscriptionDeleted',
            'am-timestamp' => now()->toIso8601String(),
            'am-root-url' => 'https://example.com/amember',
            'user' => [
                'user_id' => 123,
                'email' => 'test@example.com',
                'login' => 'testuser',
            ],
            'product' => [
                'product_id' => 5,
                'title' => 'Premium Plan',
            ],
        ], '127.0.0.1');

        $response->assertStatus(200);

        // Verify subscription was deleted
        $this->assertDatabaseMissing('amember_subscriptions', [
            'user_id' => 123,
            'product_id' => 5,
            'installation_id' => $this->installation->id,
        ]);
    }

    #[Test]
    public function it_rejects_webhooks_from_unknown_ip(): void
    {
        $response = $this->postWebhook([
            'am-webhooks-version' => '1.0',
            'am-event' => 'subscriptionAdded',
            'am-timestamp' => now()->toIso8601String(),
            'user' => ['user_id' => 123, 'email' => 'test@example.com'],
            'product' => ['product_id' => 5],
        ], '192.168.1.1');

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Unknown installation']);
    }

    #[Test]
    public function it_handles_user_after_update_webhook(): void
    {
        // Create existing user
        $userModel = config('amember-sso.user_model');
        $user = $userModel::create([
            'email' => 'old@example.com',
            'name' => 'Old Name',
            'username' => 'testuser',
            'amember_user_id' => 123,
            'amember_installation_id' => $this->installation->id,
            'password' => bcrypt('password'),
        ]);

        // Send update webhook
        $response = $this->postWebhook([
            'am-webhooks-version' => '1.0',
            'am-event' => 'userAfterUpdate',
            'am-timestamp' => now()->toIso8601String(),
            'am-root-url' => 'https://example.com/amember',
            'user' => [
                'user_id' => 123,
                'email' => 'new@example.com',
                'name_f' => 'New',
                'name_l' => 'Name',
            ],
            'oldUser' => [
                'email' => 'old@example.com',
                'name_f' => 'Old',
                'name_l' => 'Name',
            ],
        ], '127.0.0.1');

        $response->assertStatus(200);

        // Verify user was updated
        $user->refresh();
        $this->assertEquals('new@example.com', $user->email);
        $this->assertEquals('New Name', $user->name);
    }

    #[Test]
    public function it_logs_webhook_events(): void
    {
        $this->postWebhook([
            'am-webhooks-version' => '1.0',
            'am-event' => 'subscriptionAdded',
            'am-timestamp' => now()->toIso8601String(),
            'user' => ['user_id' => 123, 'email' => 'test@example.com'],
            'product' => ['product_id' => 5],
        ], '127.0.0.1');

        // Verify webhook was logged
        $this->assertDatabaseHas('amember_webhook_logs', [
            'event_type' => 'subscriptionAdded',
            'status' => 'received',
            'ip_address' => '127.0.0.1',
        ]);
    }

    #[Test]
    public function a_retried_delivery_does_not_duplicate_anything(): void
    {
        // aMember retries with the same payload until it gets HTTP 200
        $payload = [
            'am-webhooks-version' => '1.0',
            'am-event' => 'accessAfterInsert',
            'am-timestamp' => '2025-10-20T18:37:07-06:00',
            'am-root-url' => 'https://example.com/members',
            'access' => [
                'access_id' => '3911',
                'user_id' => '1977',
                'product_id' => '50',
                'begin_date' => '2025-10-20',
                'expire_date' => '2037-12-31',
            ],
            'user' => [
                'user_id' => '1977',
                'login' => 'johndoe',
                'email' => 'john@example.com',
                'name_f' => 'John',
                'name_l' => 'Doe',
            ],
        ];

        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)->assertStatus(200);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('amember_subscriptions', 1);
    }
}
