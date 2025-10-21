<?php

namespace Greatplr\AmemberSso\Tests\Feature;

use Greatplr\AmemberSso\Models\AmemberInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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

    /** @test */
    public function it_handles_subscription_added_webhook()
    {
        $response = $this->post('/amember/webhook', [
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
        ], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // Verify user was created
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'amember_user_id' => 123,
            'amember_installation_id' => $this->installation->id,
        ]);
    }

    /** @test */
    public function it_handles_access_after_insert_webhook()
    {
        $response = $this->post('/amember/webhook', [
            'am-webhooks-version' => '1.0',
            'am-event' => 'accessAfterInsert',
            'am-timestamp' => now()->toIso8601String(),
            'am-root-url' => 'https://example.com/amember',
            'access' => [
                'access_id' => 12345,
                'user_id' => 123,
                'product_id' => 5,
                'begin_date' => '2025-01-01',
                'expire_date' => '2026-01-01',
                'invoice_id' => 9876,
            ],
            'user' => [
                'user_id' => 123,
                'email' => 'test@example.com',
                'login' => 'testuser',
                'name_f' => 'John',
                'name_l' => 'Doe',
            ],
        ], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response->assertStatus(200);

        // Verify subscription was created
        $this->assertDatabaseHas('amember_subscriptions', [
            'access_id' => 12345,
            'user_id' => 123,
            'product_id' => 5,
            'installation_id' => $this->installation->id,
        ]);
    }

    /** @test */
    public function it_handles_subscription_deleted_webhook()
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

        DB::table('amember_subscriptions')->insert([
            'installation_id' => $this->installation->id,
            'user_id' => 123,
            'product_id' => 5,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Send webhook
        $response = $this->post('/amember/webhook', [
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
        ], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response->assertStatus(200);

        // Verify subscription was deleted
        $this->assertDatabaseMissing('amember_subscriptions', [
            'user_id' => 123,
            'product_id' => 5,
            'installation_id' => $this->installation->id,
        ]);
    }

    /** @test */
    public function it_rejects_webhooks_from_unknown_ip()
    {
        $response = $this->post('/amember/webhook', [
            'am-webhooks-version' => '1.0',
            'am-event' => 'subscriptionAdded',
            'am-timestamp' => now()->toIso8601String(),
            'user' => ['user_id' => 123, 'email' => 'test@example.com'],
            'product' => ['product_id' => 5],
        ], [
            'REMOTE_ADDR' => '192.168.1.1', // Unknown IP
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Unknown installation']);
    }

    /** @test */
    public function it_handles_user_after_update_webhook()
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
        $response = $this->post('/amember/webhook', [
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
        ], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response->assertStatus(200);

        // Verify user was updated
        $user->refresh();
        $this->assertEquals('new@example.com', $user->email);
        $this->assertEquals('New Name', $user->name);
    }

    /** @test */
    public function it_logs_webhook_events()
    {
        $this->post('/amember/webhook', [
            'am-webhooks-version' => '1.0',
            'am-event' => 'subscriptionAdded',
            'am-timestamp' => now()->toIso8601String(),
            'user' => ['user_id' => 123, 'email' => 'test@example.com'],
            'product' => ['product_id' => 5],
        ], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        // Verify webhook was logged
        $this->assertDatabaseHas('amember_webhook_logs', [
            'event_type' => 'subscriptionAdded',
            'status' => 'received',
            'ip_address' => '127.0.0.1',
        ]);
    }
}
