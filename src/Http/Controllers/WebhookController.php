<?php

namespace Greatplr\AmemberSso\Http\Controllers;

use Greatplr\AmemberSso\Events\SubscriptionAdded;
use Greatplr\AmemberSso\Events\SubscriptionDeleted;
use Greatplr\AmemberSso\Events\SubscriptionUpdated;
use Greatplr\AmemberSso\Services\AmemberSsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        protected AmemberSsoService $amemberSso
    ) {}

    /**
     * Handle incoming webhooks from aMember.
     */
    public function handle(Request $request): JsonResponse
    {
        // Verify webhook signature
        if (!$this->verifyWebhookSignature($request)) {
            $this->logWebhook($request, 'failed', 'Invalid signature');
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $eventType = $request->input('event');
        $data = $request->input('data', []);

        try {
            $this->logWebhook($request, 'received', "Event: {$eventType}");

            // Process the webhook based on event type
            match ($eventType) {
                'subscription.added' => $this->handleSubscriptionAdded($data),
                'subscription.updated' => $this->handleSubscriptionUpdated($data),
                'subscription.deleted' => $this->handleSubscriptionDeleted($data),
                'payment.completed' => $this->handlePaymentCompleted($data),
                'payment.refunded' => $this->handlePaymentRefunded($data),
                default => $this->logWebhook($request, 'ignored', "Unknown event type: {$eventType}"),
            };

            $this->logWebhook($request, 'processed', "Successfully processed {$eventType}");

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            $this->logWebhook($request, 'error', $e->getMessage());
            Log::error('Webhook processing failed: ' . $e->getMessage(), [
                'event' => $eventType,
                'data' => $data,
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle subscription added event.
     */
    protected function handleSubscriptionAdded(array $data): void
    {
        $subscription = $this->upsertSubscription($data);

        // Clear user's subscription cache
        if (isset($data['user_id'])) {
            $this->amemberSso->clearSubscriptionCache($data['user_id']);
        }

        event(new SubscriptionAdded($subscription, $data));
    }

    /**
     * Handle subscription updated event.
     */
    protected function handleSubscriptionUpdated(array $data): void
    {
        $subscription = $this->upsertSubscription($data);

        // Clear user's subscription cache
        if (isset($data['user_id'])) {
            $this->amemberSso->clearSubscriptionCache($data['user_id']);
        }

        event(new SubscriptionUpdated($subscription, $data));
    }

    /**
     * Handle subscription deleted event.
     */
    protected function handleSubscriptionDeleted(array $data): void
    {
        $tableName = config('amember-sso.tables.subscriptions');
        $accessId = $data['access_id'] ?? null;

        if ($accessId) {
            DB::table($tableName)
                ->where('access_id', $accessId)
                ->delete();
        }

        // Clear user's subscription cache
        if (isset($data['user_id'])) {
            $this->amemberSso->clearSubscriptionCache($data['user_id']);
        }

        event(new SubscriptionDeleted($data));
    }

    /**
     * Handle payment completed event.
     */
    protected function handlePaymentCompleted(array $data): void
    {
        // You can extend this to store payment records if needed
        Log::info('Payment completed', $data);

        // Clear user's subscription cache as new payment might affect subscriptions
        if (isset($data['user_id'])) {
            $this->amemberSso->clearSubscriptionCache($data['user_id']);
        }
    }

    /**
     * Handle payment refunded event.
     */
    protected function handlePaymentRefunded(array $data): void
    {
        // You can extend this to update subscription status
        Log::info('Payment refunded', $data);

        // Clear user's subscription cache
        if (isset($data['user_id'])) {
            $this->amemberSso->clearSubscriptionCache($data['user_id']);
        }
    }

    /**
     * Upsert subscription record.
     */
    protected function upsertSubscription(array $data): array
    {
        $tableName = config('amember-sso.tables.subscriptions');

        $subscriptionData = [
            'access_id' => $data['access_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'begin_date' => $data['begin_date'] ?? null,
            'expire_date' => $data['expire_date'] ?? null,
            'status' => $this->determineStatus($data),
            'data' => json_encode($data),
            'updated_at' => now(),
        ];

        DB::table($tableName)->updateOrInsert(
            ['access_id' => $subscriptionData['access_id']],
            array_merge($subscriptionData, ['created_at' => now()])
        );

        return $subscriptionData;
    }

    /**
     * Determine subscription status from data.
     */
    protected function determineStatus(array $data): string
    {
        $now = time();
        $beginDate = isset($data['begin_date']) ? strtotime($data['begin_date']) : 0;
        $expireDate = isset($data['expire_date']) ? strtotime($data['expire_date']) : null;

        if ($now < $beginDate) {
            return 'pending';
        }

        if ($expireDate && $now > $expireDate) {
            return 'expired';
        }

        return 'active';
    }

    /**
     * Verify webhook signature.
     */
    protected function verifyWebhookSignature(Request $request): bool
    {
        $secret = config('amember-sso.webhook.secret');

        if (!$secret) {
            // If no secret is configured, skip verification (not recommended for production)
            return true;
        }

        $signature = $request->header('X-Amember-Signature');
        if (!$signature) {
            return false;
        }

        $payload = $request->getContent();
        $calculatedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($calculatedSignature, $signature);
    }

    /**
     * Log webhook event.
     */
    protected function logWebhook(Request $request, string $status, string $message = ''): void
    {
        $tableName = config('amember-sso.tables.webhook_logs');

        DB::table($tableName)->insert([
            'event_type' => $request->input('event'),
            'status' => $status,
            'payload' => $request->getContent(),
            'message' => $message,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
