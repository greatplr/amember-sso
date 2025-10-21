<?php

namespace Greatplr\AmemberSso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AmemberProduct extends Model
{
    protected $fillable = [
        'installation_id',
        'product_id',
        'title',
        'description',
        'tier',
        'display_name',
        'slug',
        'mappable_type',
        'mappable_id',
        'features',
        'price',
        'currency',
        'billing_period',
        'sort_order',
        'is_active',
        'is_featured',
        'metadata',
    ];

    protected $casts = [
        'features' => 'array',
        'metadata' => 'array',
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the table name from config.
     */
    public function getTable()
    {
        return config('amember-sso.tables.products', 'amember_products');
    }

    /**
     * Get the installation this product belongs to.
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(
            config('amember-sso.models.installation', AmemberInstallation::class),
            'installation_id'
        );
    }

    /**
     * Get the mapped model (polymorphic).
     */
    public function mappable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: Only active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Only featured products.
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope: Filter by tier.
     */
    public function scopeTier($query, string $tier)
    {
        return $query->where('tier', $tier);
    }

    /**
     * Scope: Filter by installation.
     */
    public function scopeForInstallation($query, $installationId)
    {
        return $query->where('installation_id', $installationId);
    }

    /**
     * Find product by aMember product ID and installation.
     */
    public static function findByAmemberProduct(string $productId, $installationId): ?self
    {
        return static::where('product_id', $productId)
            ->where('installation_id', $installationId)
            ->first();
    }

    /**
     * Find product by tier and installation.
     */
    public static function findByTier(string $tier, $installationId): ?self
    {
        return static::where('tier', $tier)
            ->where('installation_id', $installationId)
            ->first();
    }

    /**
     * Check if product has a specific feature.
     */
    public function hasFeature(string $feature): bool
    {
        return isset($this->features[$feature]) && $this->features[$feature];
    }

    /**
     * Get feature value.
     */
    public function getFeature(string $feature, $default = null)
    {
        return $this->features[$feature] ?? $default;
    }

    /**
     * Get formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        if (!$this->price) {
            return 'Free';
        }

        $symbol = match ($this->currency) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => $this->currency . ' ',
        };

        $price = $symbol . number_format($this->price, 2);

        if ($this->billing_period) {
            $period = match ($this->billing_period) {
                'monthly' => '/mo',
                'yearly' => '/yr',
                'lifetime' => ' (lifetime)',
                default => '/' . $this->billing_period,
            };
            $price .= $period;
        }

        return $price;
    }

    /**
     * Get display name or fallback to title.
     */
    public function getDisplayNameAttribute($value): string
    {
        return $value ?? $this->title ?? "Product {$this->product_id}";
    }

    /**
     * Scope: Filter by mappable model.
     */
    public function scopeForMappable($query, string $type, $id)
    {
        return $query->where('mappable_type', $type)
            ->where('mappable_id', $id);
    }

    /**
     * Find products by mappable model.
     */
    public static function findByMappable(string $type, $id, $installationId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = static::where('mappable_type', $type)
            ->where('mappable_id', $id);

        if ($installationId) {
            $query->where('installation_id', $installationId);
        }

        return $query->get();
    }

    /**
     * Check if this product is mapped to a specific model.
     */
    public function isMappedTo(string $type, $id = null): bool
    {
        if ($id === null) {
            return $this->mappable_type === $type;
        }

        return $this->mappable_type === $type && $this->mappable_id == $id;
    }
}
