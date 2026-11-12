<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Jobs\FetchProjectFaviconJob;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
    Queue::fake();

    $this->actingAs(User::forceCreate([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]));
});

function syncWithUrl(?string $url, string $environment = 'production')
{
    return test()->post('/api/voight/lock-file', array_filter([
        'project_code' => 'my-project',
        'environment' => $environment,
        'url' => $url,
        'lockfiles' => [UploadedFile::fake()->createWithContent('composer.lock', '{}')],
    ]));
}

it('stores the site url on the environment and queues a favicon fetch', function () {
    Project::factory()->create(['project_code' => 'my-project']);

    syncWithUrl('https://site.test/')->assertStatus(202);

    expect(Environment::where('name', 'production')->value('url'))->toBe('https://site.test');
    Queue::assertPushed(FetchProjectFaviconJob::class);
});

it('does not queue a favicon fetch when the url is unchanged and the favicon is fresh', function () {
    $project = Project::factory()->create(['project_code' => 'my-project', 'favicon_fetched_at' => now()]);
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    syncWithUrl('https://site.test')->assertStatus(202);

    Queue::assertNotPushed(FetchProjectFaviconJob::class);
});

it('queues a favicon fetch when the url changes', function () {
    $project = Project::factory()->create(['project_code' => 'my-project', 'favicon_fetched_at' => now()]);
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://old.site.test']);

    syncWithUrl('https://site.test')->assertStatus(202);

    Queue::assertPushed(FetchProjectFaviconJob::class);
});

it('leaves the url untouched and queues nothing when no url is sent', function () {
    $project = Project::factory()->create(['project_code' => 'my-project']);
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    syncWithUrl(null)->assertStatus(202);

    expect(Environment::where('name', 'production')->value('url'))->toBe('https://site.test');
    Queue::assertNotPushed(FetchProjectFaviconJob::class);
});

it('does not queue a favicon fetch when fetching is disabled', function () {
    config()->set('filament-voight.favicons.enabled', false);
    Project::factory()->create(['project_code' => 'my-project']);

    syncWithUrl('https://site.test')->assertStatus(202);

    Queue::assertNotPushed(FetchProjectFaviconJob::class);
});

it('rejects a non-http url', function () {
    Project::factory()->create(['project_code' => 'my-project']);

    $this->withHeaders(['Accept' => 'application/json']);

    syncWithUrl('ftp://site.test')
        ->assertStatus(422)
        ->assertJsonValidationErrors('url');
});
