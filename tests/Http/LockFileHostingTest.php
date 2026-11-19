<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Hosting;
use Statikbe\FilamentVoight\Models\Project;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    Queue::fake();

    $this->actingAs(User::forceCreate([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]));

    $this->project = Project::factory()->create(['project_code' => 'my-project']);
});

function syncWithHosting(?string $hosting)
{
    return test()->post('/api/voight/lock-file', array_filter([
        'project_code' => 'my-project',
        'environment' => 'production',
        'hosting' => $hosting,
        'lockfiles' => [UploadedFile::fake()->createWithContent('composer.lock', '{}')],
    ]));
}

it('creates an unknown hosting and assigns it to the environment', function () {
    syncWithHosting('Combell')->assertStatus(202);

    expect(Environment::query()->sole()->hosting->name)->toBe('Combell');
});

it('reuses an existing hosting whatever the case', function () {
    $combell = Hosting::factory()->create(['name' => 'Combell']);

    syncWithHosting('combell')->assertStatus(202);

    expect(Environment::query()->sole()->hosting_id)->toBe($combell->getKey())
        ->and(Hosting::query()->count())->toBe(1);
});

it('overwrites a hosting set by hand', function () {
    Environment::factory()->for($this->project)->for(Hosting::factory()->state(['name' => 'Own server']))->create(['name' => 'production']);

    syncWithHosting('Level27')->assertStatus(202);

    expect(Environment::query()->sole()->hosting->name)->toBe('Level27');
});

it('leaves the hosting untouched when none is sent', function () {
    $environment = Environment::factory()->for($this->project)->for(Hosting::factory())->create(['name' => 'production']);

    syncWithHosting(null)->assertStatus(202);

    expect($environment->fresh()->hosting_id)->toBe($environment->hosting_id);
});

it('rejects an overly long hosting name', function () {
    syncWithHosting(str_repeat('a', 256))->assertSessionHasErrors('hosting');
});
