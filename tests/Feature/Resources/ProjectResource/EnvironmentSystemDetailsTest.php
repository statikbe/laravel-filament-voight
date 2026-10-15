<?php

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ViewProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\RelationManagers\EnvironmentsRelationManager;

beforeEach(function () {
    // Outside the local environment Filament only lets users through that implement FilamentUser.
    $user = new class extends User implements FilamentUser
    {
        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }
    };
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $this->actingAs($user);
});

function viewEnvironmentUrl(Environment $environment): string
{
    return EnvironmentResource::getUrl('view', ['project' => $environment->project, 'record' => $environment]);
}

it('renders the view page with the snapshot', function () {
    $environment = Environment::factory()->create(['name' => 'production']);
    EnvironmentSystemDetail::factory()->for($environment)->create([
        'php_version' => '8.3.12',
        'payload' => [
            'server' => [
                'php' => ['version' => '8.3.12', 'sapi' => 'fpm-fcgi', 'max_input_vars' => '1000', 'xdebug' => false, 'extensions' => ['curl' => '8.3.12', 'mbstring' => null, 'imagick' => '3.8.1']],
                'system' => ['os' => 'Linux 6.1', 'hostname' => 'web-01', 'load_average' => [0.08, 0.09, 0.08]],
                'database' => ['driver' => 'mysql', 'version' => '8.0.46'],
                'disk' => ['free_bytes' => 40 * 1024 ** 3, 'total_bytes' => 100 * 1024 ** 3],
                'deployment' => ['release_path' => '/var/www/html'],
                'future_group' => ['new_field' => 'shiny', 'flag' => true],
            ],
            'app' => ['environment' => 'production', 'debug_mode' => false],
            'framework' => ['name' => 'laravel', 'version' => '12.1.0', 'groups' => ['environment' => ['debug_mode' => false], 'drivers' => ['queue' => 'redis']]],
        ],
    ]);

    // Database appears only in the stat card and the Copy-as-Markdown payload; the Server tab has no Database section.
    expect(substr_count($this->get(viewEnvironmentUrl($environment))->getContent(), 'Database'))->toBe(2);

    // Tab ids (not translated labels) drive the persisted ?tab= value.
    $this->get(viewEnvironmentUrl($environment) . '?tab=server')->assertSuccessful()->assertSee('server', false);

    $this->get(viewEnvironmentUrl($environment))
        ->assertSuccessful()
        ->assertSeeInOrder(['PHP', '8.3.12', 'Database', 'mysql 8.0.46', 'Environment', 'Debug mode'])
        ->assertSee('Laravel 12.1.0')
        ->assertSee('Server')
        ->assertSee('Extensions')
        ->assertSee('Extensions bundled with PHP 8.3.12 show only their name.')
        ->assertSee('imagick 3.8.1')
        ->assertDontSee('curl 8.3.12')
        ->assertSee('mbstring')
        ->assertSee('Max input vars')
        ->assertSee('fpm-fcgi')
        ->assertSee('0.08 / 0.09 / 0.08')
        ->assertSee('Linux 6.1')
        ->assertSee('web-01')
        ->assertSee('/var/www/html')
        ->assertSee('Drivers')
        ->assertSee('redis')
        ->assertSee('Future Group')
        ->assertSee('shiny')
        ->assertSee('mbstring')
        ->assertSee('Copy as Markdown')
        ->assertSee('Last reported');
});

it('stacks the laravel groups in two balanced columns', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create([
        'payload' => [
            'server' => [],
            'framework' => ['name' => 'laravel', 'version' => '12.1.0', 'groups' => [
                'cache' => ['driver' => 'cache-marker'],
                'storage' => ['public' => 'storage-marker'],
                'drivers' => ['queue' => 'drivers-marker', 'mail' => 'smtp', 'session' => 'file'],
                'environment' => ['environment' => 'environment-marker', 'debug_mode' => false],
                'livewire' => ['version' => 'livewire-marker'],
            ]],
        ],
    ]);

    // Left stack (environment, drivers) renders before the right stack (cache, storage); livewire goes to the shorter right stack.
    $this->get(viewEnvironmentUrl($environment))
        ->assertSuccessful()
        ->assertSeeInOrder(['environment-marker', 'drivers-marker', 'cache-marker', 'storage-marker', 'livewire-marker']);
});

it('renders a non-Laravel framework tab with its own groups', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create([
        'payload' => [
            'server' => [],
            'framework' => ['name' => 'craft', 'version' => '5.4.0', 'groups' => [
                'edition' => ['edition' => 'pro-marker'],
                'plugins' => ['seo' => 'plugin-marker'],
            ]],
        ],
    ]);

    $this->get(viewEnvironmentUrl($environment))
        ->assertSuccessful()
        ->assertSee('Craft 5.4.0')
        ->assertSeeInOrder(['Edition', 'pro-marker', 'Plugins', 'plugin-marker']);
});

it('renders the view page with a sparse snapshot where sections failed', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create([
        'php_version' => null,
        'payload' => [
            'app' => null,
            'framework' => null,
            'server' => ['php' => null, 'system' => null, 'deployment' => null, 'database' => ['driver' => null, 'version' => null], 'disk' => null],
        ],
    ]);

    $this->get(viewEnvironmentUrl($environment))
        ->assertSuccessful()
        ->assertSee('Copy as Markdown');
});

it('renders the view page without a snapshot', function () {
    $environment = Environment::factory()->create();

    $this->get(viewEnvironmentUrl($environment))
        ->assertSuccessful()
        ->assertSee('Never')
        ->assertSee('No server snapshot received yet.')
        ->assertDontSee('Copy as Markdown')
        ->assertDontSee('Extensions');
});

it('links relation manager rows to the view page and shows the PHP version', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create();
    EnvironmentSystemDetail::factory()->for($environment)->create(['php_version' => '8.4.1']);

    Livewire::test(EnvironmentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertCanSeeTableRecords([$environment])
        ->assertTableColumnStateSet('systemDetails.php_version', '8.4.1', $environment);
});

it('lists environments across projects and filters by version', function () {
    $php83 = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($php83)->create(['php_version' => '8.3.12']);
    $php84 = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($php84)->create(['php_version' => '8.4.1']);
    $never = Environment::factory()->create();

    Livewire::test(ListEnvironments::class)
        ->assertSuccessful()
        ->filterTable('server_reported', null)
        ->assertCanSeeTableRecords([$php83, $php84, $never])
        ->assertTableFilterExists('php_version')
        ->filterTable('php_version', '8.3.12')
        ->assertCanSeeTableRecords([$php83])
        ->assertCanNotSeeTableRecords([$php84, $never]);
});

it('filters the environments list by name', function () {
    $production = Environment::factory()->create(['name' => 'production']);
    $staging = Environment::factory()->create(['name' => 'staging']);
    $acceptance = Environment::factory()->create(['name' => 'acceptance']);

    Livewire::test(ListEnvironments::class)
        ->filterTable('server_reported', null)
        ->assertTableFilterExists('name')
        ->filterTable('name', ['production', 'staging'])
        ->assertCanSeeTableRecords([$production, $staging])
        ->assertCanNotSeeTableRecords([$acceptance]);
});

it('shows only reported environments by default, newest report first', function () {
    $old = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($old)->create(['received_at' => now()->subDay()]);
    $new = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($new)->create(['received_at' => now()]);
    $never = Environment::factory()->create();

    Livewire::test(ListEnvironments::class)
        ->assertCanSeeTableRecords([$new, $old], inOrder: true)
        ->assertCanNotSeeTableRecords([$never])
        ->filterTable('server_reported', false)
        ->assertCanSeeTableRecords([$never])
        ->assertCanNotSeeTableRecords([$new, $old]);
});

it('renders storage symlink paths colored by link state', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create([
        'payload' => [
            'server' => [],
            'framework' => ['name' => 'laravel', 'version' => '12.1.0', 'groups' => ['storage' => [
                '/var/www/html/public/storage' => true,
                '/var/www/html/public/missing' => false,
            ]]],
        ],
    ]);

    $this->get(viewEnvironmentUrl($environment))
        ->assertSuccessful()
        ->assertSee('Storage linked')
        ->assertSee('/var/www/html/public/storage')
        ->assertSee('/var/www/html/public/missing')
        ->assertSee('fi-color-success', false)
        ->assertSee('fi-color-danger', false);
});
