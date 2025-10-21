<?php

namespace Greatplr\AmemberSso\Testing;

use Greatplr\AmemberSso\Models\AmemberInstallation;
use Illuminate\Support\Facades\Event;

class AmemberSsoTestHelper
{
    /**
     * Fake a webhook event.
     */
    public static function fakeWebhook(string $eventType, array $data = [], ?AmemberInstallation $installation = null): array
    {
        $installation = $installation ?? static::createTestInstallation();

        $payload = static::buildWebhookPayload($eventType, $data);

        return [
            'event_type' => $eventType,
            'payload' => $payload,
            'installation' => $installation,
        ];
    }

    /**
     * Build a webhook payload for a specific event type.
     */
    public static function buildWebhookPayload(string $eventType, array $data = []): array
    {
        $defaults = static::getDefaultPayload($eventType);

        return array_merge($defaults, $data);
    }

    /**
     * Get default payload structure for an event type.
     */
    protected static function getDefaultPayload(string $eventType): array
    {
        return match ($eventType) {
            'accessAfterInsert', 'accessAfterUpdate' => [
                'am-webhooks-version' => '1.0',
                'am-event' => $eventType,
                'access' => [
                    'access_id' => '999',
                    'user_id' => '100',
                    'product_id' => '5',
                    'begin_date' => now()->format('Y-m-d'),
                    'expire_date' => now()->addYear()->format('Y-m-d'),
                ],
                'user' => [
                    'user_id' => '100',
                    'login' => 'testuser',
                    'email' => 'test@example.com',
                    'name_f' => 'Test',
                    'name_l' => 'User',
                ],
            ],
            'accessAfterDelete' => [
                'am-webhooks-version' => '1.0',
                'am-event' => $eventType,
                'access' => [
                    'access_id' => '999',
                    'user_id' => '100',
                    'product_id' => '5',
                ],
                'user' => [
                    'user_id' => '100',
                    'email' => 'test@example.com',
                ],
            ],
            'userAfterInsert', 'userAfterUpdate' => [
                'am-webhooks-version' => '1.0',
                'am-event' => $eventType,
                'user' => [
                    'user_id' => '100',
                    'login' => 'testuser',
                    'email' => 'test@example.com',
                    'name_f' => 'Test',
                    'name_l' => 'User',
                    'added' => now()->format('Y-m-d H:i:s'),
                ],
            ],
            'paymentAfterInsert' => [
                'am-webhooks-version' => '1.0',
                'am-event' => $eventType,
                'payment' => [
                    'payment_id' => '500',
                    'user_id' => '100',
                    'amount' => '29.99',
                    'currency' => 'USD',
                    'receipt_id' => 'RECEIPT-123',
                ],
                'user' => [
                    'user_id' => '100',
                    'email' => 'test@example.com',
                ],
            ],
            'invoicePaymentRefund' => [
                'am-webhooks-version' => '1.0',
                'am-event' => $eventType,
                'refund' => [
                    'refund_id' => '600',
                    'payment_id' => '500',
                    'amount' => '29.99',
                ],
                'user' => [
                    'user_id' => '100',
                    'email' => 'test@example.com',
                ],
            ],
            default => [
                'am-webhooks-version' => '1.0',
                'am-event' => $eventType,
            ],
        };
    }

    /**
     * Create a test installation.
     */
    public static function createTestInstallation(array $attributes = []): AmemberInstallation
    {
        return AmemberInstallation::create(array_merge([
            'name' => 'Test Installation',
            'slug' => 'test',
            'api_url' => 'https://test.example.com/amember/api',
            'api_key' => 'test-api-key',
            'ip_address' => '192.0.2.1',
            'login_url' => 'https://test.example.com/amember/login',
            'webhook_secret' => 'test-webhook-secret',
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Fake all aMember events.
     */
    public static function fakeEvents(): void
    {
        Event::fake([
            \Greatplr\AmemberSso\Events\SubscriptionAdded::class,
            \Greatplr\AmemberSso\Events\SubscriptionUpdated::class,
            \Greatplr\AmemberSso\Events\SubscriptionDeleted::class,
            \Greatplr\AmemberSso\Events\UserCreated::class,
            \Greatplr\AmemberSso\Events\UserUpdated::class,
            \Greatplr\AmemberSso\Events\PaymentReceived::class,
            \Greatplr\AmemberSso\Events\PaymentRefunded::class,
        ]);
    }

    /**
     * Assert that a subscription event was dispatched.
     */
    public static function assertSubscriptionAdded(callable $callback = null): void
    {
        Event::assertDispatched(\Greatplr\AmemberSso\Events\SubscriptionAdded::class, $callback);
    }

    /**
     * Assert that a subscription updated event was dispatched.
     */
    public static function assertSubscriptionUpdated(callable $callback = null): void
    {
        Event::assertDispatched(\Greatplr\AmemberSso\Events\SubscriptionUpdated::class, $callback);
    }

    /**
     * Assert that a subscription deleted event was dispatched.
     */
    public static function assertSubscriptionDeleted(callable $callback = null): void
    {
        Event::assertDispatched(\Greatplr\AmemberSso\Events\SubscriptionDeleted::class, $callback);
    }

    /**
     * Assert that a user created event was dispatched.
     */
    public static function assertUserCreated(callable $callback = null): void
    {
        Event::assertDispatched(\Greatplr\AmemberSso\Events\UserCreated::class, $callback);
    }

    /**
     * Assert that a user updated event was dispatched.
     */
    public static function assertUserUpdated(callable $callback = null): void
    {
        Event::assertDispatched(\Greatplr\AmemberSso\Events\UserUpdated::class, $callback);
    }

    /**
     * Assert that a payment received event was dispatched.
     */
    public static function assertPaymentReceived(callable $callback = null): void
    {
        Event::assertDispatched(\Greatplr\AmemberSso\Events\PaymentReceived::class, $callback);
    }

    /**
     * Assert that a payment refunded event was dispatched.
     */
    public static function assertPaymentRefunded(callable $callback = null): void
    {
        Event::assertDispatched(\Greatplr\AmemberSso\Events\PaymentRefunded::class, $callback);
    }
}
