<?php

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Resources\EnvironmentResource;

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

it('renders the system detail stats on the environment page', function () {
    $environment = Environment::factory()->create();
    EnvironmentSystemDetail::factory()->for($environment)->create(['php_version' => '8.4.1']);

    // Nested resource: the parent record is resolved from the request route, so mount through a real HTTP request.
    $this->get(EnvironmentResource::getUrl('view', ['project' => $environment->project, 'record' => $environment]))
        ->assertSuccessful()
        ->assertSee('8.4.1')
        ->assertSee(voightTrans('models.environment.system_details.stats.debug_mode'));
});
