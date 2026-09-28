<?php

namespace Greatplr\AmemberSso\Tests\Feature;

use Greatplr\AmemberSso\Models\AmemberInstallation;
use Greatplr\AmemberSso\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * aMember doesn't sign webhooks; an installation with a webhook_secret expects
 * it back in a fixed header that the admin adds to the webhook in aMember.
 */
class WebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    protected function installation(?string $secret): AmemberInstallation
    {
        return AmemberInstallation::create([
            'name' => 'Test Installation',
            'slug' => 'test',
            'api_url' => 'https://example.com/amember/api',
            'api_key' => 'test-key',
            'ip_address' => '127.0.0.1',
            'webhook_secret' => $secret,
            'is_active' => true,
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function postWebhook(array $headers = []): TestResponse
    {
        $payload = [
            'am-webhooks-version' => '1.0',
            'am-event' => 'userAfterInsert',
            'am-timestamp' => '2026-09-28T10:00:00+00:00',
            'am-root-url' => 'https://example.com/amember',
            'user' => ['user_id' => '42', 'login' => 'jane', 'email' => 'jane@example.com'],
        ];

        $body = http_build_query($payload);
        parse_str($body, $parameters);

        $server = ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/amember/webhook', $parameters, [], [], $server, $body);
    }

    #[Test]
    public function it_accepts_the_correct_secret(): void
    {
        $this->installation('s3cret-value');

        $this->postWebhook(['X-Amember-Secret' => 's3cret-value'])
            ->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    #[Test]
    public function it_rejects_a_wrong_secret(): void
    {
        $this->installation('s3cret-value');

        $this->postWebhook(['X-Amember-Secret' => 'wrong'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
        $this->assertDatabaseHas('amember_webhook_logs', ['status' => 'failed', 'event_type' => 'userAfterInsert']);
    }

    #[Test]
    public function it_rejects_a_missing_secret_header(): void
    {
        $this->installation('s3cret-value');

        $this->postWebhook()->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    #[Test]
    public function it_accepts_any_request_from_the_installation_ip_when_no_secret_is_set(): void
    {
        $this->installation(null);

        $this->postWebhook()->assertStatus(200);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    #[Test]
    public function the_header_name_is_configurable(): void
    {
        config(['amember-sso.webhook.secret_header' => 'X-Webhook-Token']);
        $this->installation('s3cret-value');

        $this->postWebhook(['X-Amember-Secret' => 's3cret-value'])->assertStatus(403);
        $this->postWebhook(['X-Webhook-Token' => 's3cret-value'])->assertStatus(200);
    }
}
