<?php

use Illuminate\Support\Facades\URL;

it('returns the collector output for a valid relative signature', function () {
    $url = URL::temporarySignedRoute('voight.system-details.collect', now()->addMinute(), absolute: false);

    $this->getJson($url)
        ->assertSuccessful()
        ->assertJsonPath('framework.name', 'laravel')
        ->assertJsonPath('app.environment', app()->environment())
        ->assertJsonPath('server.php.version', PHP_VERSION)
        ->assertJsonPath('server.php.extensions.json', phpversion('json'))
        ->assertJsonStructure([
            'collected_at',
            'app' => ['environment', 'debug_mode'],
            'framework' => ['name', 'version', 'groups'],
            'server' => [
                'php' => ['version', 'sapi', 'memory_limit', 'max_input_vars', 'ini_path', 'xdebug', 'opcache', 'extensions'],
                'system' => ['os', 'server_software', 'load_average', 'cpu_cores', 'hostname'],
                'deployment' => ['release_path', 'commit', 'php_user', 'server_time'],
                'database',
                'disk',
            ],
        ]);
});

it('rejects requests without a signature, with a tampered one, or after expiry', function () {
    $this->getJson('/api/voight/system-details/collect')->assertForbidden();

    $url = URL::temporarySignedRoute('voight.system-details.collect', now()->addMinute(), absolute: false);
    $this->getJson($url . 'x')->assertForbidden();

    $expired = URL::temporarySignedRoute('voight.system-details.collect', now()->subMinute(), absolute: false);
    $this->getJson($expired)->assertForbidden();
});
