<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Verifies that every denormalized usage counter matches its ledger rows.
 *
 * A mismatch indicates a bug or manual database edit; --fix repairs counters
 * from the append-only api_usage_events table.
 */
final class ReconcileUsage extends Command
{
    /**
     * @var string
     */
    protected $signature = 'usage:reconcile {--fix : Repair counters from the usage ledger}';

    /**
     * @var string
     */
    protected $description = 'Compare api_clients counters with recorded usage events';

    /**
     * Execute the command.
     *
     * @return int Exit code.
     */
    public function handle(): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT c.id,
                   c.name,
                   c.billable_usage_count AS counter,
                   count(e.id)::bigint AS events
            FROM api_clients c
            LEFT JOIN api_usage_events e ON e.api_client_id = c.id
            GROUP BY c.id, c.name, c.billable_usage_count
            ORDER BY c.id
        SQL);

        $mismatches = 0;
        $table = [];

        foreach ($rows as $row) {
            $matches = (int) $row->counter === (int) $row->events;

            if (! $matches) {
                $mismatches++;
            }

            $table[] = [
                $row->id,
                $row->name,
                (int) $row->counter,
                (int) $row->events,
                $matches ? 'OK' : 'MISMATCH',
            ];
        }

        $this->table(['ID', 'Client', 'Counter', 'Events', 'Status'], $table);

        if ($mismatches === 0) {
            $this->info('All usage counters match the ledger.');

            return self::SUCCESS;
        }

        if (! $this->option('fix')) {
            $this->warn("{$mismatches} mismatch(es) found. Re-run with --fix to repair.");

            return self::FAILURE;
        }

        foreach ($rows as $row) {
            if ((int) $row->counter === (int) $row->events) {
                continue;
            }

            DB::table('api_clients')
                ->where('id', $row->id)
                ->update([
                    'billable_usage_count' => (int) $row->events,
                    'updated_at' => now(),
                ]);
        }

        $this->info('Counters repaired from the usage ledger.');

        return self::SUCCESS;
    }
}
