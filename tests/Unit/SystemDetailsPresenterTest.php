<?php

use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Support\SystemDetailsPresenter;

it('builds markdown with a heading per group and formatted values', function () {
    $environment = Environment::factory()->create(['name' => 'production']);
    EnvironmentSystemDetail::factory()->for($environment)->create(['payload' => [
        'server' => [
            'php' => ['version' => '8.4.24', 'max_input_vars' => '1000', 'xdebug' => false, 'extensions' => ['redis' => '6.3.0', 'curl' => '8.4.24', 'mbstring' => '8.4.24']],
            'system' => ['load_average' => [0.08, 0.09, 0.08], 'cpu_cores' => null],
            'disk' => ['free_bytes' => 40 * 1024 ** 3],
            'future_group' => ['new_field' => 'shiny'],
        ],
        'framework' => ['name' => 'laravel', 'version' => '12.1.0', 'groups' => ['environment' => ['debug_mode' => true]]],
    ]]);

    $markdown = SystemDetailsPresenter::markdown($environment->fresh('systemDetails'));

    expect($markdown)
        ->toContain("# production\n")
        ->toContain("## Environment\n- **Debug Mode:** Yes")
        ->toContain('## PHP')
        ->toContain('- **Max input vars:** 1000')
        ->toContain('- **Xdebug loaded:** No')
        ->toContain('- **Load average (1/5/15 min):** 0.08 / 0.09 / 0.08')
        ->toContain('- **CPU cores:** —')
        ->not->toContain('40 GB')
        ->toContain("## Future Group\n- **New Field:** shiny")
        ->toContain("## Extensions\ncurl, mbstring, redis 6.3.0")
        ->not->toContain('**Extensions:**');
});

it('keeps filesystem path keys verbatim as row labels', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create(['payload' => [
        'framework' => ['groups' => ['storage' => ['/var/www/html/public/storage' => 'LINKED']]],
    ]]);

    expect(SystemDetailsPresenter::framework($environment->fresh('systemDetails'))['storage']['rows'][0]['label'])
        ->toBe('/var/www/html/public/storage');
});

it('titles the framework by name and version, falling back to the generic label', function () {
    $environment = Environment::factory()->create();
    $details = EnvironmentSystemDetail::factory()->for($environment)->create(['payload' => ['framework' => ['name' => 'craft', 'version' => '5.4.0']]]);

    expect(SystemDetailsPresenter::frameworkTitle($environment->fresh('systemDetails')))->toBe('Craft 5.4.0');

    $details->update(['payload' => ['framework' => ['name' => 'craft']]]);
    expect(SystemDetailsPresenter::frameworkTitle($environment->fresh('systemDetails')))->toBe('Craft');

    $details->update(['payload' => ['server' => []]]);
    expect(SystemDetailsPresenter::frameworkTitle($environment->fresh('systemDetails')))->toBe('Framework');
});

it('reads the legacy list of extension names as versionless entries', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create(['payload' => [
        'server' => ['php' => ['extensions' => ['curl', 'mbstring']]],
    ]]);

    expect(SystemDetailsPresenter::extensions($environment->fresh('systemDetails')))
        ->toBe(['curl' => null, 'mbstring' => null]);
});

it('sorts extension labels by name and only shows versions that differ from PHP', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create(['payload' => [
        'server' => ['php' => ['version' => '8.4.24', 'extensions' => [
            'gd' => '8.4.24',
            'FFI' => '8.4.24',
            'PDO' => '8.4.24',
            'mysqlnd' => 'mysqlnd 8.4.24',
            'imagick' => '3.8.1',
            'Zend OPcache' => null,
            'curl' => '8.4.24',
        ]]],
    ]]);

    expect(SystemDetailsPresenter::extensionLabels($environment->fresh('systemDetails')))
        ->toBe(['curl', 'FFI', 'gd', 'imagick 3.8.1', 'mysqlnd', 'PDO', 'Zend OPcache']);
});

it('orders the new server groups and formats their byte values', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create(['payload' => [
        'server' => [
            'disk' => ['free_bytes' => 1024 ** 3],
            'ssl' => ['issuer' => "Let's Encrypt", 'issuer_cn' => 'R11'],
            'system' => ['cgroup_memory_limit_bytes' => 4 * 1024 ** 3, 'cgroup_cpu_limit' => 1.0],
            'opcache' => ['memory_used_bytes' => 1024 ** 2, 'jit' => false],
            'php' => ['version' => '8.4.24'],
        ],
    ]]);

    $server = SystemDetailsPresenter::server($environment->fresh('systemDetails'));

    expect(array_keys($server))->toBe(['php', 'opcache', 'system', 'ssl'])
        ->and($server['opcache']['title'])->toBe('OPcache')
        ->and($server['opcache']['rows'][0])->toMatchArray(['label' => 'Memory used', 'value' => '1 MB'])
        ->and($server['system']['rows'][0])->toMatchArray(['label' => 'Container memory limit', 'value' => '4 GB'])
        ->and($server['ssl']['rows'][1])->toMatchArray(['label' => 'Issuer CN', 'value' => 'R11']);
});

it('hides noisy package groups from the framework details', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create(['payload' => [
        'framework' => ['groups' => [
            'environment' => ['debug_mode' => false],
            'livewire' => ['livewire' => '4.0.0'],
            'spatie_permissions' => ['features_teams' => 'Disabled'],
            'filament_shield' => ['auth_provider' => 'Configured'],
        ]],
    ]]);

    expect(array_keys(SystemDetailsPresenter::framework($environment->fresh('systemDetails'))))->toBe(['environment']);
});

it('moves the OPcache flags into the opcache group and hides hit_rate', function (array $server) {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create(['payload' => ['server' => $server]]);

    $groups = SystemDetailsPresenter::server($environment->fresh('systemDetails'));
    $php = array_column($groups['php']['rows'], 'key');
    $opcache = array_column($groups['opcache']['rows'], 'key');

    expect($php)->toBe(['version'])
        ->and(array_slice($opcache, 0, 2))->toBe(['enabled', 'validate_timestamps'])
        ->and($opcache)->not->toContain('hit_rate')
        ->and($groups['opcache']['rows'][0])->toMatchArray(['label' => 'Enabled', 'value' => true])
        ->and($groups['opcache']['rows'][1])->toMatchArray(['label' => 'Validate timestamps', 'value' => false]);
})->with([
    'old snapshot without opcache group' => [['php' => ['version' => '8.3.1', 'opcache' => true, 'opcache_validate_timestamps' => false]]],
    'existing opcache group' => [[
        'php' => ['version' => '8.3.1', 'opcache' => true, 'opcache_validate_timestamps' => false],
        'opcache' => ['hit_rate' => 99.5, 'jit' => false],
    ]],
]);
