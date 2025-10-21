<?php

namespace Greatplr\AmemberSso\Http\Controllers;

use Greatplr\AmemberSso\Events\SubscriptionAdded;
use Greatplr\AmemberSso\Events\SubscriptionDeleted;
use Greatplr\AmemberSso\Events\SubscriptionUpdated;
use Greatplr\AmemberSso\Models\AmemberInstallation;
use Greatplr\AmemberSso\Services\AmemberSsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        try {
            // Detect installation by IP address
            $installation = AmemberInstallation::byIp($request->ip())->active()->first();

            if (!$installation) {
                Log::warning('aMember webhook from unknown IP', ['ip' => $request->ip()]);
                $this->logWebhook($request, 'failed', 'Unknown installation IP');
                return response()->json(['error' => 'Unknown installation'], 400);
            }

            // Verify webhook signature for this installation
            if (!$this->verifyWebhookSignature($request, $installation)) {
                $this->logWebhook($request, 'failed', 'Invalid signature');
                return response()->json(['error' => 'Invalid signature'], 403);
            }

            $eventType = $request->input('event');
            $data = $request->input('data', []);

            $this->logWebhook($request, 'received', "Event: {$eventType} from {$installation->name}");

            // Process the webhook based on event type
            match ($eventType) {
                'subscription.added' => $this->handleSubscriptionAdded($data, $installation),
                'subscription.updated' => $this->handleSubscriptionUpdated($data, $installation),
                'subscription.deleted' => $this->handleSubscriptionDeleted($data, $installation),
                'payment.completed' => $this->handlePaymentCompleted($data, $installation),
                'payment.refunded' => $this->handlePaymentRefunded($data, $installation),
                default => $this->logWebhook($request, 'ignored', "Unknown event type: {$eventType}"),
            };

            $this->logWebhook($request, 'processed', "Successfully processed {$eventType}");

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            $this->logWebhook($request, 'error', $e->getMessage());
            Log::error('Webhook processing failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Handle subscription added event.
     */
    protected function handleSubscriptionAdded(array $data, AmemberInstallation $installation): void
    {
        DB::beginTransaction();

        try {
            // Find or create user
            $user = $this->findOrCreateUser($data, $installation);

            // Create subscription
            $subscription = $this->upsertSubscription($data, $installation, $user);

            // Clear user's access cache
            $this->clearUserCache($data);

            DB::commit();

            event(new SubscriptionAdded($subscription, $data));

            Log::info('Subscription added via webhook', [
                'user_id' => $user->id,
                'installation' => $installation->name,
                'product_id' => $data['product_id'] ?? null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle subscription updated event.
     */
    protected function handleSubscriptionUpdated(array $data, AmemberInstallation $installation): void
    {
        DB::beginTransaction();

        try {
            // Find or create user (in case webhook order is mixed up)
            $user = $this->findOrCreateUser($data, $installation);

            // Update subscription
            $subscription = $this->upsertSubscription($data, $installation, $user);

            // Clear user's access cache
            $this->clearUserCache($data);

            DB::commit();

            event(new SubscriptionUpdated($subscription, $data));
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle subscription deleted event.
     */
    protected function handleSubscriptionDeleted(array $data, AmemberInstallation $installation): void
    {
        $tableName = config('amember-sso.tables.subscriptions');
        $accessId = $data['access_id'] ?? null;

        if ($accessId) {
            DB::table($tableName)
                ->where('access_id', $accessId)
                ->where('installation_id', $installation->id)
                ->delete();
        }

        // Clear user's access cache
        $this->clearUserCache($data);

        event(new SubscriptionDeleted($data));
    }

    /**
     * Handle payment completed event.
     */
    protected function handlePaymentCompleted(array $data, AmemberInstallation $installation): void
    {
        Log::info('Payment completed', [
            'installation' => $installation->name,
            'data' => $data,
        ]);

        // Clear user's access cache as new payment might affect subscriptions
        $this->clearUserCache($data);
    }

    /**
     * Handle payment refunded event.
     */
    protected function handlePaymentRefunded(array $data, AmemberInstallation $installation): void
    {
        Log::info('Payment refunded', [
            'installation' => $installation->name,
            'data' => $data,
        ]);

        // Clear user's access cache
        $this->clearUserCache($data);
    }

    /**
     * Clear user's access cache from webhook data.
     */
    protected function clearUserCache(array $data): void
    {
        // Try to get login or email from webhook data
        $login = $data['login'] ?? null;
        $email = $data['email'] ?? null;

        if ($login) {
            $this->amemberSso->clearAccessCache($login);
        }

        if ($email && $email !== $login) {
            $this->amemberSso->clearAccessCache($email);
        }
    }

    /**
     * Find or create user from webhook data.
     */
    protected function findOrCreateUser(array $data, AmemberInstallation $installation): object
    {
        $userModel = config('amember-sso.user_model');
        $email = $data['email'] ?? null;
        $amemberUserId = $data['user_id'] ?? null;

        if (!$email) {
            throw new \Exception('Email required in webhook data');
        }

        // Try to find existing user by amember_user_id and installation
        if ($amemberUserId) {
            $user = $userModel::where('amember_user_id', $amemberUserId)
                ->where('amember_installation_id', $installation->id)
                ->first();

            if ($user) {
                return $user;
            }
        }

        // Try to find by email
        $user = $userModel::where('email', $email)->first();

        if ($user) {
            // Update amember_user_id and installation if missing
            if (!$user->amember_user_id && $amemberUserId) {
                $user->amember_user_id = $amemberUserId;
            }
            if (!$user->amember_installation_id) {
                $user->amember_installation_id = $installation->id;
            }
            $user->save();

            return $user;
        }

        // Create new user
        $username = $data['username'] ?? $data['login'] ?? explode('@', $email)[0];
        $name = $data['name'] ?? $data['name_f'] ?? trim(($data['name_f'] ?? '') . ' ' . ($data['name_l'] ?? ''));

        $user = $userModel::create([
            'email' => $email,
            'name' => $name ?: $username,
            'username' => $this->generateUniqueUsername($username),
            'amember_user_id' => $amemberUserId,
            'amember_installation_id' => $installation->id,
            'password' => Hash::make(Str::random(32)), // Random password
        ]);

        Log::info('Created new user from webhook', [
            'user_id' => $user->id,
            'email' => $email,
            'installation' => $installation->name,
        ]);

        return $user;
    }

    /**
     * Generate unique username (handles conflicts).
     */
    protected function generateUniqueUsername(string $baseUsername): string
    {
        $userModel = config('amember-sso.user_model');
        $username = $baseUsername;
        $suffix = 1;

        while ($userModel::where('username', $username)->exists()) {
            $username = $baseUsername . '-' . $suffix;
            $suffix++;
        }

        return $username;
    }

    /**
     * Upsert subscription record.
     */
    protected function upsertSubscription(array $data, AmemberInstallation $installation, object $user): array
    {
        $tableName = config('amember-sso.tables.subscriptions');

        $subscriptionData = [
            'installation_id' => $installation->id,
            'access_id' => $data['access_id'] ?? null,
            'user_id' => $user->amember_user_id ?? $data['user_id'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'begin_date' => $data['begin_date'] ?? null,
            'expire_date' => $data['expire_date'] ?? null,
            'status' => $this->determineStatus($data),
            'data' => json_encode($data),
            'updated_at' => now(),
        ];

        DB::table($tableName)->updateOrInsert(
            [
                'access_id' => $subscriptionData['access_id'],
                'installation_id' => $installation->id,
            ],
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
     * Verify webhook signature for an installation.
     */
    protected function verifyWebhookSignature(Request $request, AmemberInstallation $installation): bool
    {
        if (!$installation->webhook_secret) {
            // No secret configured for this installation
            return true;
        }

        $signature = $request->header('X-Amember-Signature');
        if (!$signature) {
            return false;
        }

        $payload = $request->getContent();
        $calculatedSignature = hash_hmac('sha256', $payload, $installation->webhook_secret);

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
