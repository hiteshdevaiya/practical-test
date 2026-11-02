<?php

declare(strict_types=1);

namespace App\Services\Usage;

/**
 * Outcome of recording one selection.
 */
final readonly class UsageResult
{
    /**
     * @param bool $counted True when this request created the usage event.
     * @param int $billableUsageCount Current total for the API client.
     */
    public function __construct(
        public bool $counted,
        public int $billableUsageCount,
    ) {}
}
