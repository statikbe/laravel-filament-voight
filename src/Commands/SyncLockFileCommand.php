<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

class SyncLockFileCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:sync-lockfile
        {--project= : The project code}
        {--environment= : The environment name}
        {--path= : Path to the directory containing lockfiles}
        {--url= : The API base URL (defaults to APP_URL)}
        {--token= : The API bearer token for authentication}';

    public $description = 'Send lockfiles to the Voight sync API for testing';

    public function handle(): int
    {
        $this->displayBanner();

        $projectCode = $this->option('project') ?? $this->ask('Project code');
        $environment = $this->option('environment') ?? $this->ask('Environment name', 'production');
        $path = $this->option('path') ?? $this->ask('Path to directory containing lockfiles', getcwd());
        $baseUrl = $this->option('url') ?? $this->ask('API base URL', config('app.url'));
        $token = $this->option('token') ?? $this->ask('API bearer token');

        $path = rtrim($path, '/');
        $allowedNames = FilamentVoight::config()->getAllowedLockfileNames();
        $files = [];

        foreach ($allowedNames as $filename) {
            $filePath = "{$path}/{$filename}";
            if (file_exists($filePath)) {
                $files[$filename] = $filePath;
            }
        }

        if (empty($files)) {
            $this->error("No lockfiles found in {$path}");
            $this->line('Looked for: ' . implode(', ', $allowedNames));

            return self::FAILURE;
        }

        $this->info('Found lockfiles:');
        foreach ($files as $filename => $filePath) {
            $this->line("  - {$filename} (" . $this->formatBytes(filesize($filePath)) . ')');
        }

        $url = rtrim($baseUrl, '/') . '/api/voight/lock-file';
        $this->line("Sending to: {$url}");

        $request = Http::withToken($token)
            ->acceptJson();

        foreach ($files as $filename => $filePath) {
            $request = $request->attach(
                "lockfiles[{$filename}]",
                file_get_contents($filePath),
                $filename,
            );
        }

        $response = $request->post($url, [
            'project_code' => $projectCode,
            'environment' => $environment,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $this->info('Sync created successfully!');
            $this->line("  Sync ID: {$data['sync_id']}");
            $this->line("  Status: {$data['status']}");

            return self::SUCCESS;
        }

        $this->error("Request failed with status {$response->status()}");

        if ($response->status() === 422) {
            $errors = $response->json('errors', []);
            foreach ($errors as $field => $messages) {
                foreach ($messages as $message) {
                    $this->line("  {$field}: {$message}");
                }
            }
        } else {
            $this->line($response->body());
        }

        return self::FAILURE;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
