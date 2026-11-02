<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\AuthenticateApiClient;
use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Activates or revokes an API client and clears its authentication cache.
 *
 * Revocation is effective immediately: the cached client entry is removed in
 * the same command, so the next request re-reads is_active from PostgreSQL.
 */
final class RevokeApiClient extends Command
{
    /**
     * @var string
     */
    protected $signature = 'api-client:revoke
        {client : API client id or exact name}
        {--reactivate : Re-enable the client instead of revoking it}';

    /**
     * @var string
     */
    protected $description = 'Enable or revoke an API client and clear its cached credentials';

    /**
     * Execute the command.
     *
     * @param CacheRepository $cache Cache store holding resolved clients.
     * @return int Exit code.
     */
    public function handle(CacheRepository $cache): int
    {
        $identifier = trim((string) $this->argument('client'));
        $client = ctype_digit($identifier)
            ? ApiClient::query()->find((int) $identifier)
            : ApiClient::query()->where('name', $identifier)->first();

        if ($client === null) {
            $this->error('No API client matched that id or name.');

            return self::FAILURE;
        }

        $active = (bool) $this->option('reactivate');
        $client->forceFill(['is_active' => $active])->save();

        try {
            $cache->forget(AuthenticateApiClient::CACHE_PREFIX.$client->api_key_hash);
        } catch (\Throwable $exception) {
            report($exception);
            $this->warn('The client was updated but its cache entry could not be cleared.');
        }

        $this->info(sprintf(
            'API client #%d (%s) is now %s.',
            $client->id,
            $client->name,
            $active ? 'active' : 'revoked',
        ));

        return self::SUCCESS;
    }
}
