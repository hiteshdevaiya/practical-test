<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Models\ApiClient;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Records billable usage exactly once per selection token.
 *
 * The usage ledger insert and the denormalized counter update happen in one
 * transaction. The unique index on selection_id makes duplicate requests a
 * no-op, so retries, double-clicks and replays are idempotent.
 */
final class UsageRecorder
{
    /**
     * @param ConnectionInterface $connection Writable PostgreSQL connection.
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    /**
     * Persist one billable selection.
     *
     * @param ApiClient $client Authenticated API client.
     * @param SelectionClaims $claims Verified token claims.
     * @param Request $request Current HTTP request for audit metadata.
     * @return UsageResult Whether the usage was counted and the new total.
     */
    public function record(ApiClient $client, SelectionClaims $claims, Request $request): UsageResult
    {
        return $this->connection->transaction(function () use ($client, $claims, $request): UsageResult {
            $inserted = $this->connection->table('api_usage_events')->insertOrIgnore([
                'api_client_id' => $client->id,
                'record_id' => $claims->recordId,
                'selection_id' => $claims->selectionId,
                'search_id' => $claims->searchId,
                'query' => mb_substr($claims->query, 0, 100),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => Carbon::now(),
            ]);

            if ($inserted === 1) {
                $row = $this->connection->selectOne(
                    'UPDATE api_clients
                     SET billable_usage_count = billable_usage_count + 1,
                         updated_at = now()
                     WHERE id = ?
                     RETURNING billable_usage_count',
                    [$client->id]
                );

                logger()->info('Billable selection recorded', [
                    'api_client_id' => $client->id,
                    'record_id' => $claims->recordId,
                    'search_id' => $claims->searchId,
                    'selection_id' => $claims->selectionId,
                    'billable_usage_count' => (int) $row->billable_usage_count,
                ]);

                return new UsageResult(true, (int) $row->billable_usage_count);
            }

            $total = $this->connection->table('api_clients')
                ->where('id', $client->id)
                ->value('billable_usage_count');

            logger()->info('Duplicate selection ignored', [
                'api_client_id' => $client->id,
                'record_id' => $claims->recordId,
                'selection_id' => $claims->selectionId,
            ]);

            return new UsageResult(false, (int) $total);
        }, 3);
    }
}
