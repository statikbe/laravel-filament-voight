<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Services\SystemDetailsCollector;

class PushSystemDetailsCommand extends Command
{
    public $signature = 'voight:push-system-details
        {--url= : The Voight base URL (defaults to VOIGHT_API_BASE_URL)}
        {--token= : The project API token (defaults to VOIGHT_API_TOKEN)}
        {--environment= : The environment name (defaults to VOIGHT_PUSH_ENVIRONMENT or APP_ENV)}';

    public $description = 'Push a snapshot of this server (PHP, OS, database, Laravel about) to Voight';

    public function handle(SystemDetailsCollector $collector): int
    {
        $config = FilamentVoight::config();
        $baseUrl = $this->option('url') ?? $config->getPushBaseUrl();

        if (blank($baseUrl)) {
            $this->line('No Voight base URL configured, skipping system details push.');

            return self::SUCCESS;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        $isHttps = str_starts_with(strtolower($baseUrl), 'https://');

        if (! $isHttps && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            $this->error('The Voight base URL must use https://.');

            return self::FAILURE;
        }

        $payload = ['environment' => $this->option('environment') ?? $config->getPushEnvironment() ?? app()->environment()]
            + ($this->collectViaWebServer() ?? $collector->collect());

        $url = rtrim($baseUrl, '/') . '/api/voight/system-details';

        try {
            $response = Http::withToken($this->option('token') ?? $config->getPushToken())
                ->acceptJson()
                ->timeout(10)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            $this->error("Could not reach {$url}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("Request failed with status {$response->status()}");

            return self::FAILURE;
        }

        $this->info("System details pushed for environment '{$payload['environment']}'.");

        return self::SUCCESS;
    }

    /**
     * Ask this app's own web server for the snapshot so ini values reflect FPM/Apache, not the CLI.
     *
     * @return array<string, mixed>|null
     */
    private function collectViaWebServer(): ?array
    {
        // Relative signature: the host part is irrelevant, so the loopback base may differ from the app URL.
        $path = URL::temporarySignedRoute('voight.system-details.collect', now()->addMinute(), absolute: false);
        $url = rtrim(FilamentVoight::config()->getPushLoopbackUrl(), '/') . $path;

        try {
            $response = Http::acceptJson()->timeout(10)->get($url);
            $data = $response->json();
        } catch (ConnectionException $e) {
            $this->warn("Web collection failed ({$e->getMessage()}), using CLI values.");

            return null;
        }

        if (! $response->successful() || ! is_array($data) || ! isset($data['server'])) {
            $this->warn("Web collection failed (status {$response->status()}), using CLI values.");

            return null;
        }

        return $data;
    }
}
