<?php

namespace Greatplr\AmemberSso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmemberSubscription extends Model
{
    protected $fillable = [
        'installation_id',
        'access_id',
        'user_id',
        'product_id',
        'begin_date',
        'expire_date',
        'status',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
        'begin_date' => 'datetime',
        'expire_date' => 'datetime',
    ];

    /**
     * Get the installation this subscription belongs to.
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(AmemberInstallation::class, 'installation_id');
    }

    /**
     * Check if subscription is currently active.
     */
    public function isActive(): bool
    {
        $now = now();

        return $this->begin_date <= $now &&
            ($this->expire_date === null || $this->expire_date > $now) &&
            $this->status === 'active';
    }

    /**
     * Scope: Only active subscriptions.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('begin_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('expire_date')
                    ->orWhere('expire_date', '>', now());
            });
    }

    /**
     * Scope: For specific product(s).
     */
    public function scopeForProduct($query, int|array $productIds)
    {
        return $query->whereIn('product_id', (array) $productIds);
    }

    /**
     * Get the table name from config.
     */
    public function getTable()
    {
        return config('amember-sso.tables.subscriptions', parent::getTable());
    }
}
