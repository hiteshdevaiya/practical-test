<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An authenticated API consumer with a billable usage counter.
 *
 * Only the SHA-256 hash of the API key is persisted.
 */
final class ApiClient extends Model
{
    /**
     * @var string
     */
    protected $table = 'api_clients';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'api_key_hash',
        'billable_usage_count',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_key_hash',
    ];

    /**
     * Get attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billable_usage_count' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Usage events belonging to this client.
     *
     * @return HasMany<ApiUsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(ApiUsageEvent::class);
    }

    /**
     * Scope a query to active clients only.
     *
     * @param Builder<ApiClient> $query Query builder.
     * @return Builder<ApiClient>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
