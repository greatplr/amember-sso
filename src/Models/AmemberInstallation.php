<?php

namespace Greatplr\AmemberSso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Greatplr\AmemberSso\Api\AmemberApiClient;

class AmemberInstallation extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'api_url',
        'ip_address',
        'login_url',
        'button_text',
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
     * API client for this installation, using its own api_url and api_key.
     */
    public function getApiClient(): AmemberApiClient
    {
        return new AmemberApiClient(
            $this->api_url,
            $this->api_key,
            (int) config('amember-sso.api.timeout', 10),
        );
    }

    /**
     * The aMember root URL: api_url without its trailing /api.
     */
    public function getRootUrl(): string
    {
        return preg_replace('#/api/?$#', '', rtrim($this->api_url, '/'));
    }

    /**
     * Get the full login URL for SSO.
     */
    public function getLoginUrl(?string $redirectUrl = null): string
    {
        $url = $this->login_url ?? $this->getRootUrl() . '/login';

        if ($redirectUrl) {
            $url .= '?amember_redirect_url=' . urlencode($redirectUrl);
        }

        return $url;
    }

    /**
     * Get login button data for rendering in views.
     *
     * @param string|null $redirectUrl Optional redirect URL after login
     * @return array{text: string, url: string, installation: string}
     */
    public function getLoginButtonData(?string $redirectUrl = null): array
    {
        return [
            'text' => $this->button_text ?? 'Login to ' . $this->name,
            'url' => $this->getLoginUrl($redirectUrl),
            'installation' => $this->name,
        ];
    }

    /**
     * Check the shared secret an aMember webhook carries in a fixed header.
     *
     * aMember doesn't sign webhooks, so the admin adds a header such as
     * `X-Amember-Secret: <secret>` to the webhook in aMember. With no
     * webhook_secret set, every request passes (the caller already matched
     * the installation by IP).
     */
    public function verifyWebhookSecret(?string $providedSecret): bool
    {
        if (!$this->webhook_secret) {
            return true;
        }

        if ($providedSecret === null || $providedSecret === '') {
            return false;
        }

        return hash_equals((string) $this->webhook_secret, $providedSecret);
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
