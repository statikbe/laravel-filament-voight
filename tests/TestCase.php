<?php

namespace Statikbe\FilamentVoight\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\Schema;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\Table;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Statikbe\FilamentVoight\FilamentVoightPlugin;
use Statikbe\FilamentVoight\FilamentVoightServiceProvider;
use Statikbe\FilamentVoight\Tests\Support\TestPanelProvider;
use Statikbe\FilamentVoight\Tests\Support\User;

class TestCase extends Orchestra
{
    use RefreshDatabase;
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Statikbe\\FilamentVoight\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
        );

        // Mirrors the defaults the host panel registers; Livewire tests never boot the panel.
        Table::configureUsing(fn (Table $table): Table => $table
            ->defaultDateDisplayFormat(FilamentVoightPlugin::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(FilamentVoightPlugin::DATE_TIME_FORMAT)
            ->defaultTimeDisplayFormat(FilamentVoightPlugin::TIME_FORMAT));

        Schema::configureUsing(fn (Schema $schema): Schema => $schema
            ->defaultDateDisplayFormat(FilamentVoightPlugin::DATE_FORMAT)
            ->defaultDateTimeDisplayFormat(FilamentVoightPlugin::DATE_TIME_FORMAT)
            ->defaultTimeDisplayFormat(FilamentVoightPlugin::TIME_FORMAT));
    }

    protected function getPackageProviders($app)
    {
        $providers = [
            ActionsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            McpServiceProvider::class,
            NotificationsServiceProvider::class,
            SanctumServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentVoightServiceProvider::class,
            TestPanelProvider::class,
        ];

        sort($providers);

        return $providers;
    }

    public function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:T/MupGaBB277pIfrpS71axrorxbGSlIDoTbRHzobgoE=');

        $app['config']->set('database.default', 'testing');
        // Match production databases: cascading deletes (e.g. dependency edges) must actually run.
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);

        $app['config']->set('filesystems.disks.voight-lockfiles', [
            'driver' => 'local',
            'root' => storage_path('app/private/voight/lockfiles'),
        ]);

        $app['config']->set('filament-voight.lockfiles.disk', 'voight-lockfiles');
        $app['config']->set('filament-voight.api.middleware', ['auth']);
        $app['config']->set('filament-voight.mcp.enabled', true);

        $app['config']->set('filament-voight.models.user', User::class);
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('services.slack.notifications', [
            'bot_user_oauth_token' => 'xoxb-test-token',
            'channel' => null,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        // ProjectInfoListSchema lists the project's Sanctum tokens.
        $this->loadMigrationsFrom(dirname(__DIR__) . '/vendor/laravel/sanctum/database/migrations');
    }
}
