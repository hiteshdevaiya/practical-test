<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billable selection in the append-only usage ledger.
 */
final class ApiUsageEvent extends Model
{
    /**
     * @var string
     */
    protected $table = 'api_usage_events';

    /**
     * The ledger only has a created_at column.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'api_client_id',
        'record_id',
        'selection_id',
        'search_id',
        'query',
        'ip',
        'user_agent',
        'created_at',
    ];

    /**
     * Get attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Client that owns the event.
     *
     * @return BelongsTo<ApiClient, $this>
     */
    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    /**
     * Selected record.
     *
     * @return BelongsTo<Record, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }
}
