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
        ->toContain('- **Free:** 40 GB')
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
