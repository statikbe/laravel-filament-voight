<?php

use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Support\SystemDetailsPresenter;

it('builds markdown with a heading per group and formatted values', function () {
    $environment = Environment::factory()->create(['name' => 'production']);
    EnvironmentSystemDetail::factory()->for($environment)->create(['payload' => [
        'server' => [
            'php' => ['version' => '8.4.1', 'max_input_vars' => '1000', 'xdebug' => false, 'extensions' => ['curl', 'mbstring']],
            'system' => ['load_average' => [0.08, 0.09, 0.08], 'cpu_cores' => null],
            'disk' => ['free_bytes' => 40 * 1024 ** 3],
            'future_group' => ['new_field' => 'shiny'],
        ],
        'laravel' => ['environment' => ['debug_mode' => true]],
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
        ->toContain("## Extensions\ncurl, mbstring")
        ->not->toContain('**Extensions:**');
});
