<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A searchable record: typically a short address-like phrase.
 */
final class Record extends Model
{
    /**
     * @var string
     */
    protected $table = 'records';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'content',
    ];

    /**
     * Get attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
        ];
    }

    /**
     * Usage events that selected this record.
     *
     * @return HasMany<ApiUsageEvent, $this>
     */
    public function usageEvents(): HasMany
    {
        return $this->hasMany(ApiUsageEvent::class, 'record_id');
    }
}
