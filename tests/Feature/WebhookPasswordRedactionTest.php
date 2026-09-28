<?php

namespace Greatplr\AmemberSso\Tests\Feature;

use Greatplr\AmemberSso\Events\UserCreated;
use Greatplr\AmemberSso\Jobs\ProcessAmemberWebhook;
use Greatplr\AmemberSso\Models\AmemberInstallation;
use Greatplr\AmemberSso\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * aMember puts the plaintext password in `user[plain_password]` when it knows
 * it, and `setPassword` carries a `password` object. None of it may reach the
 * webhook log table, the application log, the queue or event listeners.
 */
class WebhookPasswordRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected const PLAIN_PASSWORD = 'Hunter2-plaintext-9f3a';
    protected const WEBHOOK_SECRET = 's3cret-header-value';

    protected function setUp(): void
    {
        parent::setUp();

        config(['amember-sso.user_creation.enabled' => true]);

        AmemberInstallation::create([
            'name' => 'Test Installation',
            'slug' => 'test',
            'api_url' => 'https://example.com/amember/api',
            'api_key' => 'test-key',
            'ip_address' => '127.0.0.1',
            'webhook_secret' => self::WEBHOOK_SECRET,
            'is_active' => true,
        ]);
    }

    /**
     * POST a form-encoded body, as aMember does.
     */
    protected function postWebhook(array $payload, string $secret = self::WEBHOOK_SECRET): TestResponse
    {
        $body = http_build_query($payload);
        parse_str($body, $parameters);

        return $this->call('POST', '/amember/webhook', $parameters, [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_X_AMEMBER_SECRET' => $secret,
        ], $body);
    }

    protected function userAfterInsertPayload(): array
    {
        return [
            'am-webhooks-version' => '1.0',
            'am-event' => 'userAfterInsert',
            'am-timestamp' => '2026-09-28T10:00:00+00:00',
            'am-root-url' => 'https://example.com/amember',
            'user' => [
                'user_id' => '42',
                'login' => 'jane',
                'email' => 'jane@example.com',
                'plain_password' => self::PLAIN_PASSWORD,
                'pass' => self::PLAIN_PASSWORD,
            ],
            'oldUser' => [
                'user_id' => '42',
                'plain_password' => self::PLAIN_PASSWORD,
            ],
        ];
    }

    protected function assertPasswordNotInWebhookLogs(): void
    {
        $rows = DB::table('amember_webhook_logs')->get();

        $this->assertNotEmpty($rows, 'Expected the webhook to be logged');

        foreach ($rows as $row) {
            foreach ((array) $row as $column => $value) {
                $this->assertStringNotContainsString(
                    self::PLAIN_PASSWORD,
                    (string) $value,
                    "Plaintext password found in amember_webhook_logs.{$column}"
                );
            }
        }
    }

    #[Test]
    public function it_never_stores_the_plain_password_in_webhook_logs(): void
    {
        $this->postWebhook($this->userAfterInsertPayload())->assertOk();

        $this->assertPasswordNotInWebhookLogs();

        // The rest of the payload is still logged, with the password masked
        $payload = json_decode(DB::table('amember_webhook_logs')->value('payload'), true);
        $this->assertSame('jane@example.com', $payload['user']['email']);
        $this->assertSame('[REDACTED]', $payload['user']['plain_password']);
        $this->assertSame('[REDACTED]', $payload['user']['pass']);
        $this->assertSame('[REDACTED]', $payload['oldUser']['plain_password']);
    }

    #[Test]
    public function it_never_stores_the_plain_password_when_processing_synchronously(): void
    {
        config(['amember-sso.webhook.use_queue' => false]);

        $this->postWebhook($this->userAfterInsertPayload())->assertOk();

        $this->assertPasswordNotInWebhookLogs();
        $this->assertDatabaseHas('amember_webhook_logs', ['status' => 'processed']);
    }

    #[Test]
    public function it_never_stores_the_plain_password_for_rejected_webhooks(): void
    {
        $this->postWebhook($this->userAfterInsertPayload(), 'wrong-secret')->assertStatus(403);

        $this->assertPasswordNotInWebhookLogs();
    }

    #[Test]
    public function it_masks_the_password_object_of_set_password(): void
    {
        $this->postWebhook([
            'am-webhooks-version' => '1.0',
            'am-event' => 'setPassword',
            'am-timestamp' => '2026-09-28T10:00:00+00:00',
            'user' => ['user_id' => '42', 'email' => 'jane@example.com'],
            'password' => ['pass' => self::PLAIN_PASSWORD, 'nested' => ['plain' => self::PLAIN_PASSWORD]],
        ])->assertOk();

        $this->assertPasswordNotInWebhookLogs();
    }

    #[Test]
    public function it_keeps_the_plain_password_off_the_queue(): void
    {
        Queue::fake();

        $this->postWebhook($this->userAfterInsertPayload())->assertOk();

        Queue::assertPushed(ProcessAmemberWebhook::class, function (ProcessAmemberWebhook $job) {
            $this->assertStringNotContainsString(self::PLAIN_PASSWORD, serialize($job));
            $this->assertSame('jane@example.com', $job->payload['user']['email']);

            return true;
        });
    }

    #[Test]
    public function it_keeps_the_plain_password_away_from_event_listeners(): void
    {
        Event::fake([UserCreated::class]);

        $this->postWebhook($this->userAfterInsertPayload())->assertOk();

        Event::assertDispatched(UserCreated::class, function (UserCreated $event) {
            $this->assertStringNotContainsString(self::PLAIN_PASSWORD, json_encode($event->rawData));

            return true;
        });
    }

    #[Test]
    public function it_keeps_the_plain_password_and_secret_out_of_the_debug_log(): void
    {
        config(['amember-sso.logging.debug_webhooks' => true]);

        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
            $logged[] = $message->message . ' ' . json_encode($message->context);
        });

        $this->postWebhook($this->userAfterInsertPayload())->assertOk();

        $log = implode("\n", $logged);
        $this->assertStringContainsString('aMember Webhook Debug', $log);
        $this->assertStringNotContainsString(self::PLAIN_PASSWORD, $log);
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $log);
    }
}
