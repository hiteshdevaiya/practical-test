<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiUsageEvent;
use App\Services\Usage\SelectionClaims;
use App\Services\Usage\UsageRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies row locking, duplicate-request handling and counter accuracy.
 */
final class SelectionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A second insert of the same selection_id blocks behind the first
     * transaction and is then ignored, proving serialized exactly-once billing.
     *
     * @return void
     */
    public function test_duplicate_selection_id_waits_for_the_first_transaction(): void
    {
        $config = config('database.connections.pgsql');
        DB::connectUsing('lock_probe_a', $config);
        DB::connectUsing('lock_probe_b', $config);

        $a = DB::connection('lock_probe_a');
        $b = DB::connection('lock_probe_b');

        $suffix = Str::lower(Str::random(12));
        $clientId = null;
        $recordId = null;

        try {
            $clientId = $a->table('api_clients')->insertGetId([
                'name' => 'Lock Probe '.$suffix,
                'api_key_hash' => hash('sha256', 'lock-probe-'.$suffix),
                'billable_usage_count' => 0,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $recordId = $a->table('records')->insertGetId([
                'content' => 'Lock Probe Road '.$suffix,
            ]);

            $event = [
                'api_client_id' => $clientId,
                'record_id' => $recordId,
                'selection_id' => (string) Str::uuid(),
                'search_id' => (string) Str::uuid(),
                'query' => 'lock probe',
                'ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created_at' => now(),
            ];

            $a->beginTransaction();
            $a->table('api_usage_events')->insert($event);

            // The second writer must wait on the unique index, then time out.
            $b->statement("SET statement_timeout = '300ms'");
            $blocked = false;

            try {
                $b->table('api_usage_events')->insert($event);
            } catch (QueryException) {
                $blocked = true;
            } finally {
                $b->statement('SET statement_timeout = 0');
            }

            $this->assertTrue($blocked, 'The duplicate insert did not wait for the open transaction.');
            $this->assertSame(0, (int) $b->table('api_usage_events')
                ->where('selection_id', $event['selection_id'])
                ->count());

            $a->commit();

            $this->assertSame(0, $b->table('api_usage_events')->insertOrIgnore($event));
            $this->assertSame(1, (int) $b->table('api_usage_events')
                ->where('selection_id', $event['selection_id'])
                ->count());
        } finally {
            if ($a->transactionLevel() > 0) {
                $a->rollBack();
            }

            // These rows were committed on a separate connection, so cleanup
            // must also run on that connection. Deleting them through the
            // refreshed test transaction would be undone by its rollback.
            if ($clientId !== null) {
                $a->table('api_usage_events')->where('api_client_id', $clientId)->delete();
                $a->table('api_clients')->where('id', $clientId)->delete();
            }

            if ($recordId !== null) {
                $a->table('records')->where('id', $recordId)->delete();
            }

            DB::purge('lock_probe_a');
            DB::purge('lock_probe_b');
        }
    }

    /**
     * Ten replays of one verified token produce one ledger row and one unit.
     *
     * @return void
     */
    public function test_repeated_recorder_calls_are_idempotent(): void
    {
        [$client] = $this->createApiClient();
        $this->seedRecords(['25 London Road Leeds']);
        $recordId = (int) DB::table('records')->value('id');

        $claims = new SelectionClaims(
            $client->id,
            $recordId,
            (string) Str::uuid(),
            (string) Str::uuid(),
            'london',
            now()->addMinutes(10)->timestamp,
        );

        $recorder = app(UsageRecorder::class);
        $counted = 0;

        for ($i = 0; $i < 10; $i++) {
            if ($recorder->record($client, $claims, request())->counted) {
                $counted++;
            }
        }

        $this->assertSame(1, $counted);
        $this->assertSame(1, ApiUsageEvent::query()->count());
        $this->assertSame(1, (int) $client->fresh()->billable_usage_count);
    }

    /**
     * Different tokens increment the counter by exactly one each time.
     *
     * @return void
     */
    public function test_counter_tracks_each_new_selection(): void
    {
        [$client] = $this->createApiClient();
        $this->seedRecords(['25 London Road Leeds']);
        $recordId = (int) DB::table('records')->value('id');
        $recorder = app(UsageRecorder::class);

        for ($i = 1; $i <= 5; $i++) {
            $claims = new SelectionClaims(
                $client->id,
                $recordId,
                (string) Str::uuid(),
                (string) Str::uuid(),
                'london',
                now()->addMinutes(10)->timestamp,
            );

            $result = $recorder->record($client, $claims, request());

            $this->assertTrue($result->counted);
            $this->assertSame($i, $result->billableUsageCount);
        }

        $this->assertSame(5, ApiUsageEvent::query()->count());
        $this->assertSame(5, (int) $client->fresh()->billable_usage_count);
    }
}
