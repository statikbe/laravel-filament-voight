<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\HostingTag;
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

function hostingNames(): array
{
    return Environment::query()->sole()->hostings->pluck('name')->sort()->values()->all();
}

it('tags the environment with the hostings, creating unknown ones', function () {
    syncWithHosting('Combell, Shared server')->assertStatus(202);

    expect(hostingNames())->toBe(['Combell', 'Shared server']);
});

it('reuses an existing hosting whatever the case', function () {
    HostingTag::factory()->create(['name' => ['en' => 'Combell']]);

    syncWithHosting('combell')->assertStatus(202);

    expect(hostingNames())->toBe(['Combell'])
        ->and(HostingTag::query()->count())->toBe(1);
});

it('replaces hostings set by hand', function () {
    Environment::factory()->for($this->project)->create(['name' => 'production'])->syncHostings(['Own server', 'Combell']);

    syncWithHosting('Level27')->assertStatus(202);

    expect(hostingNames())->toBe(['Level27']);
});

it('leaves the hostings untouched when none are sent', function () {
    Environment::factory()->for($this->project)->create(['name' => 'production'])->syncHostings(['Combell']);

    syncWithHosting(null)->assertStatus(202);

    expect(hostingNames())->toBe(['Combell']);
});

it('rejects an overly long hosting list', function () {
    syncWithHosting(str_repeat('a', 1001))->assertSessionHasErrors('hosting');
});
