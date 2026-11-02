<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates an API client and prints its plain API key exactly once.
 *
 * Only the SHA-256 hash of the key is stored. With --publish, the key is also
 * written to the local .env file for the demo frontend.
 */
final class CreateApiClient extends Command
{
    /**
     * @var string
     */
    protected $signature = 'api-client:create
        {name=Demo Client : Human readable client name}
        {--key= : Use a specific API key instead of generating one}
        {--publish : Write the generated key to DEMO_API_KEY in .env}';

    /**
     * @var string
     */
    protected $description = 'Create an API client and show its API key once';

    /**
     * Execute the command.
     *
     * @return int Exit code.
     */
    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $key = (string) ($this->option('key') ?: 'demo_live_'.Str::random(40));

        if ($name === '' || mb_strlen($name) > 100) {
            $this->error('The client name must be between 1 and 100 characters.');

            return self::FAILURE;
        }

        if (strlen($key) < 16) {
            $this->error('The API key must be at least 16 characters long.');

            return self::FAILURE;
        }

        $hash = hash('sha256', $key);

        if (ApiClient::query()->where('api_key_hash', $hash)->exists()) {
            $this->error('That API key already exists.');

            return self::FAILURE;
        }

        $client = ApiClient::query()->create([
            'name' => $name,
            'api_key_hash' => $hash,
            'billable_usage_count' => 0,
            'is_active' => true,
        ]);

        $this->info("Created API client #{$client->id} ({$client->name}).");
        $this->newLine();
        $this->line('API key (store it now, it is not recoverable):');
        $this->line($key);
        $this->newLine();

        if ($this->option('publish')) {
            $this->publishKey($key);
        }

        return self::SUCCESS;
    }

    /**
     * Write the demo key to the local .env file.
     *
     * @param string $key Plain API key.
     * @return void
     */
    private function publishKey(string $key): void
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $this->warn('No .env file found; DEMO_API_KEY was not published.');

            return;
        }

        $contents = (string) file_get_contents($path);
        $line = 'DEMO_API_KEY='.$key;

        if (preg_match('/^DEMO_API_KEY=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace('/^DEMO_API_KEY=.*$/m', $line, $contents);
        } else {
            $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }

        file_put_contents($path, $contents);
        $this->line('Published DEMO_API_KEY to .env.');

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }
}
