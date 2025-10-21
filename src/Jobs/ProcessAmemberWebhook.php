<?php

namespace Greatplr\AmemberSso\Jobs;

use Greatplr\AmemberSso\Events\PaymentReceived;
use Greatplr\AmemberSso\Events\PaymentRefunded;
use Greatplr\AmemberSso\Events\SubscriptionAdded;
use Greatplr\AmemberSso\Events\SubscriptionDeleted;
use Greatplr\AmemberSso\Events\SubscriptionUpdated;
use Greatplr\AmemberSso\Events\UserCreated;
use Greatplr\AmemberSso\Events\UserUpdated;
use Greatplr\AmemberSso\Models\AmemberInstallation;
use Greatplr\AmemberSso\Models\AmemberProduct;
use Greatplr\AmemberSso\Services\AmemberSsoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessAmemberWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff;

    /**
     * Initialize retry configuration from config.
     */
    public function __construct(
        public string $eventType,
        public array $payload,
        public AmemberInstallation $installation
    ) {
        $this->tries = config('amember-sso.webhook.max_retries', 3);
        $this->backoff = config('amember-sso.webhook.retry_delay', 60);
    }

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Execute the job.
     */
    public function handle(AmemberSsoService $amemberSso): void
    {
        Log::info('Processing aMember webhook', [
            'event' => $this->eventType,
            'installation' => $this->installation->name,
        ]);

        match ($this->eventType) {
            'subscriptionAdded' => $this->handleSubscriptionAdded(),
            'subscriptionDeleted' => $this->handleSubscriptionDeleted(),
            'accessAfterInsert' => $this->handleAccessAfterInsert($amemberSso),
            'accessAfterUpdate' => $this->handleAccessAfterUpdate($amemberSso),
            'accessAfterDelete' => $this->handleAccessAfterDelete($amemberSso),
            'paymentAfterInsert' => $this->handlePaymentAfterInsert(),
            'invoicePaymentRefund' => $this->handleInvoicePaymentRefund(),
            'userAfterInsert' => $this->handleUserAfterInsert(),
            'userAfterUpdate' => $this->handleUserAfterUpdate(),
            default => Log::warning('Unknown webhook event type', ['event' => $this->eventType]),
        };

        Log::info('Webhook processed successfully', [
            'event' => $this->eventType,
            'installation' => $this->installation->name,
        ]);
    }

    /**
     * Handle subscriptionAdded event (user gets new product).
     */
    protected function handleSubscriptionAdded(): void
    {
        DB::beginTransaction();

        try {
            $userData = $this->payload['user'] ?? [];
            $productData = $this->payload['product'] ?? [];

            $user = $this->findOrCreateUser($userData);
            $this->clearUserCache($userData);

            DB::commit();

            event(new SubscriptionAdded([
                'user' => $userData,
                'product' => $productData,
            ], $this->payload));

            Log::info('Subscription added via webhook', [
                'user_id' => $user->id,
                'installation' => $this->installation->name,
                'product_id' => $productData['product_id'] ?? null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle subscriptionDeleted event (subscription expired).
     */
    protected function handleSubscriptionDeleted(): void
    {
        $userData = $this->payload['user'] ?? [];
        $productData = $this->payload['product'] ?? [];

        $tableName = config('amember-sso.tables.subscriptions');

        DB::table($tableName)
            ->where('user_id', $userData['user_id'] ?? null)
            ->where('product_id', $productData['product_id'] ?? null)
            ->where('installation_id', $this->installation->id)
            ->delete();

        $this->clearUserCache($userData);

        event(new SubscriptionDeleted($this->payload));

        Log::info('Subscription deleted via webhook', [
            'user_id' => $userData['user_id'] ?? null,
            'product_id' => $productData['product_id'] ?? null,
            'installation' => $this->installation->name,
        ]);
    }

    /**
     * Handle accessAfterInsert event (actual access record created).
     */
    protected function handleAccessAfterInsert(AmemberSsoService $amemberSso): void
    {
        DB::beginTransaction();

        try {
            $accessData = $this->payload['access'] ?? [];
            $userData = $this->payload['user'] ?? [];

            if (empty($accessData) || empty($userData)) {
                throw new \Exception('Missing access or user data in webhook payload');
            }

            $user = $this->findOrCreateUser($userData);
            $subscription = $this->upsertSubscriptionFromAccess($accessData, $user);

            $this->clearUserCache($userData);

            // Get product mapping
            $productMapping = AmemberProduct::findByAmemberProduct(
                $accessData['product_id'] ?? null,
                $this->installation->id
            );

            DB::commit();

            event(new SubscriptionAdded($subscription, $this->payload, $productMapping));

            Log::info('Access record created via webhook', [
                'user_id' => $user->id,
                'access_id' => $accessData['access_id'] ?? null,
                'product_id' => $accessData['product_id'] ?? null,
                'installation' => $this->installation->name,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle accessAfterUpdate event.
     */
    protected function handleAccessAfterUpdate(AmemberSsoService $amemberSso): void
    {
        DB::beginTransaction();

        try {
            $accessData = $this->payload['access'] ?? [];
            $userData = $this->payload['user'] ?? [];

            $user = $this->findOrCreateUser($userData);
            $subscription = $this->upsertSubscriptionFromAccess($accessData, $user);

            $this->clearUserCache($userData);

            // Get product mapping
            $productMapping = AmemberProduct::findByAmemberProduct(
                $accessData['product_id'] ?? null,
                $this->installation->id
            );

            DB::commit();

            event(new SubscriptionUpdated($subscription, $this->payload, $productMapping));
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle accessAfterDelete event.
     */
    protected function handleAccessAfterDelete(AmemberSsoService $amemberSso): void
    {
        $accessData = $this->payload['access'] ?? [];
        $userData = $this->payload['user'] ?? [];
        $tableName = config('amember-sso.tables.subscriptions');

        // Get product mapping before deletion
        $productMapping = AmemberProduct::findByAmemberProduct(
            $accessData['product_id'] ?? null,
            $this->installation->id
        );

        DB::table($tableName)
            ->where('access_id', $accessData['access_id'] ?? null)
            ->where('installation_id', $this->installation->id)
            ->delete();

        $this->clearUserCache($userData);

        event(new SubscriptionDeleted($this->payload, $productMapping));
    }

    /**
     * Handle paymentAfterInsert event.
     */
    protected function handlePaymentAfterInsert(): void
    {
        $paymentData = $this->payload['payment'] ?? [];
        $userData = $this->payload['user'] ?? [];

        event(new PaymentReceived($paymentData, $this->payload));

        Log::info('Payment received via webhook', [
            'installation' => $this->installation->name,
            'payment_id' => $paymentData['payment_id'] ?? null,
            'amount' => $paymentData['amount'] ?? null,
            'user_id' => $userData['user_id'] ?? null,
        ]);

        $this->clearUserCache($userData);
    }

    /**
     * Handle invoicePaymentRefund event.
     */
    protected function handleInvoicePaymentRefund(): void
    {
        $refundData = $this->payload['refund'] ?? [];
        $userData = $this->payload['user'] ?? [];

        event(new PaymentRefunded($refundData, $this->payload));

        Log::info('Payment refunded via webhook', [
            'installation' => $this->installation->name,
            'refund' => $refundData,
            'user_id' => $userData['user_id'] ?? null,
        ]);

        $this->clearUserCache($userData);
    }

    /**
     * Handle userAfterInsert event.
     */
    protected function handleUserAfterInsert(): void
    {
        // Skip if user creation is disabled
        if (!config('amember-sso.user_creation.enabled', false)) {
            Log::info('User creation webhook skipped (user_creation.enabled = false)', [
                'email' => $this->payload['user']['email'] ?? null,
                'installation' => $this->installation->name,
            ]);
            return;
        }

        DB::beginTransaction();

        try {
            $userData = $this->payload['user'] ?? [];
            $user = $this->findOrCreateUser($userData);

            DB::commit();

            event(new UserCreated($user, $this->payload));

            Log::info('User created via webhook', [
                'user_id' => $user->id,
                'email' => $userData['email'] ?? null,
                'installation' => $this->installation->name,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Handle userAfterUpdate event.
     */
    protected function handleUserAfterUpdate(): void
    {
        // Skip if user creation is disabled
        if (!config('amember-sso.user_creation.enabled', false)) {
            Log::info('User update webhook skipped (user_creation.enabled = false)', [
                'email' => $this->payload['user']['email'] ?? null,
                'installation' => $this->installation->name,
            ]);
            return;
        }

        DB::beginTransaction();

        try {
            $userData = $this->payload['user'] ?? [];

            $userModel = config('amember-sso.user_model');
            $user = $userModel::where('amember_user_id', $userData['user_id'] ?? null)
                ->where('amember_installation_id', $this->installation->id)
                ->first();

            if ($user && config('amember-sso.access_control.sync_user_data')) {
                $this->syncUserDataFromWebhook($user, $userData);
            }

            $this->clearUserCache($userData);

            DB::commit();

            if ($user) {
                event(new UserUpdated($user, $this->payload));
            }
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Find or create user from webhook data.
     */
    protected function findOrCreateUser(array $data): object
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
                ->where('amember_installation_id', $this->installation->id)
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
                $user->amember_installation_id = $this->installation->id;
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
            'amember_installation_id' => $this->installation->id,
            'password' => Hash::make(Str::random(32)),
        ]);

        Log::info('Created new user from webhook', [
            'user_id' => $user->id,
            'email' => $email,
            'installation' => $this->installation->name,
        ]);

        return $user;
    }

    /**
     * Generate unique username.
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
    protected function upsertSubscriptionFromAccess(array $accessData, object $user): array
    {
        $tableName = config('amember-sso.tables.subscriptions');

        $subscriptionData = [
            'installation_id' => $this->installation->id,
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
                'installation_id' => $this->installation->id,
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
     * Sync user data from webhook.
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

        // Handle name fields specially
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
     * Clear user's access cache.
     */
    protected function clearUserCache(array $data): void
    {
        $login = $data['login'] ?? null;
        $email = $data['email'] ?? null;

        $amemberSso = app(AmemberSsoService::class);

        if ($login) {
            $amemberSso->clearAccessCache($login);
        }

        if ($email && $email !== $login) {
            $amemberSso->clearAccessCache($email);
        }
    }
}
