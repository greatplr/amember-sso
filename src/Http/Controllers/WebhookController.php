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

            // aMember sends event type as 'am-event' in camelCase
            $eventType = $request->input('am-event');

            if (!$eventType) {
                $this->logWebhook($request, 'failed', 'No am-event field');
                return response()->json(['error' => 'Missing event type'], 400);
            }

            $this->logWebhook($request, 'received', "Event: {$eventType} from {$installation->name}");

            // Dispatch to queue for background processing (or process sync if queues disabled)
            if (config('amember-sso.webhook.use_queue', true)) {
                \Greatplr\AmemberSso\Jobs\ProcessAmemberWebhook::dispatch(
                    $eventType,
                    $request->all(),
                    $installation
                )->onQueue(config('amember-sso.webhook.queue_name', 'amember-webhooks'));

                $this->logWebhook($request, 'queued', "Queued {$eventType} for processing");
            } else {
                // Fallback to synchronous processing if queues are disabled
                $this->processSynchronously($eventType, $request, $installation);
                $this->logWebhook($request, 'processed', "Successfully processed {$eventType}");
            }

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
     * Process webhook synchronously (when queues are disabled).
     */
    protected function processSynchronously(string $eventType, Request $request, AmemberInstallation $installation): void
    {
        match ($eventType) {
            'subscriptionAdded' => $this->handleSubscriptionAdded($request, $installation),
            'subscriptionDeleted' => $this->handleSubscriptionDeleted($request, $installation),
            'accessAfterInsert' => $this->handleAccessAfterInsert($request, $installation),
            'accessAfterUpdate' => $this->handleAccessAfterUpdate($request, $installation),
            'accessAfterDelete' => $this->handleAccessAfterDelete($request, $installation),
            'paymentAfterInsert' => $this->handlePaymentAfterInsert($request, $installation),
            'invoicePaymentRefund' => $this->handleInvoicePaymentRefund($request, $installation),
            'userAfterInsert' => $this->handleUserAfterInsert($request, $installation),
            'userAfterUpdate' => $this->handleUserAfterUpdate($request, $installation),
            default => Log::warning('Unknown webhook event type', ['event' => $eventType]),
        };
    }

    /**
     * Handle subscriptionAdded event (user gets new product).
     * Payload: user[], product[]
     */
    protected function handleSubscriptionAdded(Request $request, AmemberInstallation $installation): void
    {
        DB::beginTransaction();

        try {
            $userData = $request->input('user', []);
            $productData = $request->input('product', []);

            // Find or create user
            $user = $this->findOrCreateUser($userData, $installation);

            // Note: subscriptionAdded doesn't include access record details
            // We'll rely on accessAfterInsert for actual subscription creation
            // This event just confirms user got the product

            $this->clearUserCache($userData);

            DB::commit();

            event(new SubscriptionAdded([
                'user' => $userData,
                'product' => $productData,
            ], $request->all()));

            Log::info('Subscription added via webhook', [
                'user_id' => $user->id,
                'installation' => $installation->name,
                'product_id' => $productData['product_id'] ?? null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle subscriptionDeleted event (subscription expired).
     * Payload: user[], product[]
     */
    protected function handleSubscriptionDeleted(Request $request, AmemberInstallation $installation): void
    {
        $userData = $request->input('user', []);
        $productData = $request->input('product', []);

        // Delete all subscriptions for this user/product/installation combination
        $tableName = config('amember-sso.tables.subscriptions');

        DB::table($tableName)
            ->where('user_id', $userData['user_id'] ?? null)
            ->where('product_id', $productData['product_id'] ?? null)
            ->where('installation_id', $installation->id)
            ->delete();

        $this->clearUserCache($userData);

        event(new SubscriptionDeleted($request->all()));

        Log::info('Subscription deleted via webhook', [
            'user_id' => $userData['user_id'] ?? null,
            'product_id' => $productData['product_id'] ?? null,
            'installation' => $installation->name,
        ]);
    }

    /**
     * Handle accessAfterInsert event (actual access record created).
     * Payload: access[], user[]
     * This is the MAIN event for creating subscriptions.
     *
     * Example payload (after Laravel parsing):
     * access[access_id] => ['access' => ['access_id' => '3911', 'product_id' => '50', ...]]
     * user[user_id] => ['user' => ['user_id' => '1977', 'email' => 'user@example.com', ...]]
     */
    protected function handleAccessAfterInsert(Request $request, AmemberInstallation $installation): void
    {
        DB::beginTransaction();

        try {
            // Laravel automatically parses bracket notation into nested arrays
            $accessData = $request->input('access', []);
            $userData = $request->input('user', []);

            if (empty($accessData) || empty($userData)) {
                throw new \Exception('Missing access or user data in webhook payload');
            }

            // Find or create user
            $user = $this->findOrCreateUser($userData, $installation);

            // Create subscription from access record
            $subscription = $this->upsertSubscriptionFromAccess($accessData, $installation, $user);

            $this->clearUserCache($userData);

            DB::commit();

            event(new SubscriptionAdded($subscription, $request->all()));

            Log::info('Access record created via webhook', [
                'user_id' => $user->id,
                'access_id' => $accessData['access_id'] ?? null,
                'product_id' => $accessData['product_id'] ?? null,
                'installation' => $installation->name,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle accessAfterUpdate event.
     * Payload: access[], old[], user[]
     */
    protected function handleAccessAfterUpdate(Request $request, AmemberInstallation $installation): void
    {
        DB::beginTransaction();

        try {
            $accessData = $request->input('access', []);
            $userData = $request->input('user', []);

            $user = $this->findOrCreateUser($userData, $installation);
            $subscription = $this->upsertSubscriptionFromAccess($accessData, $installation, $user);

            $this->clearUserCache($userData);

            DB::commit();

            event(new SubscriptionUpdated($subscription, $request->all()));
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle accessAfterDelete event.
     * Payload: access[], user[]
     */
    protected function handleAccessAfterDelete(Request $request, AmemberInstallation $installation): void
    {
        $accessData = $request->input('access', []);
        $userData = $request->input('user', []);
        $tableName = config('amember-sso.tables.subscriptions');

        DB::table($tableName)
            ->where('access_id', $accessData['access_id'] ?? null)
            ->where('installation_id', $installation->id)
            ->delete();

        $this->clearUserCache($userData);

        event(new SubscriptionDeleted($request->all()));
    }

    /**
     * Handle paymentAfterInsert event.
     * Payload: invoice[], payment[], user[], items[]
     */
    protected function handlePaymentAfterInsert(Request $request, AmemberInstallation $installation): void
    {
        $paymentData = $request->input('payment', []);
        $userData = $request->input('user', []);

        Log::info('Payment received via webhook', [
            'installation' => $installation->name,
            'payment_id' => $paymentData['payment_id'] ?? null,
            'amount' => $paymentData['amount'] ?? null,
            'user_id' => $userData['user_id'] ?? null,
        ]);

        $this->clearUserCache($userData);
    }

    /**
     * Handle invoicePaymentRefund event.
     * Payload: invoice[], refund[], user[]
     */
    protected function handleInvoicePaymentRefund(Request $request, AmemberInstallation $installation): void
    {
        $refundData = $request->input('refund', []);
        $userData = $request->input('user', []);

        Log::info('Payment refunded via webhook', [
            'installation' => $installation->name,
            'refund' => $refundData,
            'user_id' => $userData['user_id'] ?? null,
        ]);

        $this->clearUserCache($userData);
    }

    /**
     * Handle userAfterInsert event.
     * Payload: user[]
     */
    protected function handleUserAfterInsert(Request $request, AmemberInstallation $installation): void
    {
        DB::beginTransaction();

        try {
            $userData = $request->input('user', []);
            $user = $this->findOrCreateUser($userData, $installation);

            DB::commit();

            Log::info('User created via webhook', [
                'user_id' => $user->id,
                'email' => $userData['email'] ?? null,
                'installation' => $installation->name,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle userAfterUpdate event.
     * Payload: user[], oldUser[]
     */
    protected function handleUserAfterUpdate(Request $request, AmemberInstallation $installation): void
    {
        DB::beginTransaction();

        try {
            $userData = $request->input('user', []);

            // Update existing user if found
            $userModel = config('amember-sso.user_model');
            $user = $userModel::where('amember_user_id', $userData['user_id'] ?? null)
                ->where('amember_installation_id', $installation->id)
                ->first();

            if ($user && config('amember-sso.access_control.sync_user_data')) {
                $this->syncUserDataFromWebhook($user, $userData);
            }

            $this->clearUserCache($userData);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Sync user data from webhook to local user model.
     */
    protected function syncUserDataFromWebhook(object $user, array $userData): void
    {
        $syncableFields = config('amember-sso.access_control.syncable_fields', []);
        $changed = false;

        foreach ($syncableFields as $field) {
            if (isset($userData[$field]) && $user->{$field} !== $userData[$field]) {
                $user->{$field} = $userData[$field];
                $changed = true;
            }
        }

        // Handle name fields specially (name_f, name_l -> name)
        if (in_array('name_f', $syncableFields) || in_array('name_l', $syncableFields)) {
            $nameF = $userData['name_f'] ?? '';
            $nameL = $userData['name_l'] ?? '';
            $fullName = trim($nameF . ' ' . $nameL);

            if ($fullName && $user->name !== $fullName) {
                $user->name = $fullName;
                $changed = true;
            }
        }

        if ($changed) {
            $user->save();

            Log::info('User data synced from webhook', [
                'user_id' => $user->id,
                'amember_user_id' => $userData['user_id'] ?? null,
            ]);
        }
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
     * Upsert subscription from access record data.
     */
    protected function upsertSubscriptionFromAccess(array $accessData, AmemberInstallation $installation, object $user): array
    {
        $tableName = config('amember-sso.tables.subscriptions');

        $subscriptionData = [
            'installation_id' => $installation->id,
            'access_id' => $accessData['access_id'] ?? null,
            'user_id' => $accessData['user_id'] ?? $user->amember_user_id,
            'product_id' => $accessData['product_id'] ?? null,
            'begin_date' => $accessData['begin_date'] ?? null,
            'expire_date' => $accessData['expire_date'] ?? null,
            'status' => $this->determineStatus($accessData),
            'data' => json_encode($accessData),
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
     * Upsert subscription record (legacy method for backward compatibility).
     */
    protected function upsertSubscription(array $data, AmemberInstallation $installation, object $user): array
    {
        return $this->upsertSubscriptionFromAccess($data, $installation, $user);
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
            'event_type' => $request->input('am-event'),
            'status' => $status,
            'payload' => $request->getContent(),
            'message' => $message,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
