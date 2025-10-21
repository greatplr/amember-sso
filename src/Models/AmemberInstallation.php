<?php

namespace Greatplr\AmemberSso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Plutuss\AMember\AMemberClient;

class AmemberInstallation extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'api_url',
        'ip_address',
        'login_url',
        'api_key',
        'webhook_secret',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'api_key',
        'webhook_secret',
    ];

    /**
     * Get API client for this installation.
     */
    public function getApiClient(): AMemberClient
    {
        $client = AMemberClient::getInstance();

        // Set the API URL and key for this specific installation
        // Note: You may need to create a new instance per installation
        // if plutuss/amember-pro-laravel doesn't support multi-instance

        return $client;
    }

    /**
     * Get the full login URL for SSO.
     */
    public function getLoginUrl(?string $redirectUrl = null): string
    {
        $url = $this->login_url ?? rtrim($this->api_url, '/api') . '/login';

        if ($redirectUrl) {
            $url .= '?amember_redirect_url=' . urlencode($redirectUrl);
        }

        return $url;
    }

    /**
     * Verify webhook signature for this installation.
     */
    public function verifyWebhookSignature(string $payload, ?string $signature): bool
    {
        if (!$this->webhook_secret) {
            return true; // No secret configured
        }

        if (!$signature) {
            return false;
        }

        $calculatedSignature = hash_hmac('sha256', $payload, $this->webhook_secret);

        return hash_equals($calculatedSignature, $signature);
    }

    /**
     * Scope: Only active installations.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Find by IP address.
     */
    public function scopeByIp($query, string $ip)
    {
        return $query->where('ip_address', $ip);
    }

    /**
     * Scope: Find by slug.
     */
    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }

    /**
     * Get subscriptions from this installation.
     */
    public function subscriptions(): HasMany
    {
        $tableName = config('amember-sso.tables.subscriptions', 'amember_subscriptions');

        return $this->hasMany(
            config('amember-sso.models.subscription', AmemberSubscription::class),
            'installation_id'
        );
    }
}
