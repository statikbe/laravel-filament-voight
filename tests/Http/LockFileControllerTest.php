<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;

beforeEach(function () {
    Storage::fake('voight-lockfiles');
});

it('accepts a lockfile sync request and dispatches job', function () {
    Queue::fake();

    $project = Project::factory()->create(['project_code' => 'my-project']);
    $user = \Illuminate\Foundation\Auth\User::forceCreate([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    $composerLock = UploadedFile::fake()->createWithContent(
        'composer.lock',
        json_encode([
            'packages' => [['name' => 'foo/bar', 'version' => '1.0.0']],
            'packages-dev' => [],
        ]),
    );

    $response = $this->actingAs($user)
        ->post('/api/voight/lock-file', [
            'project_code' => 'my-project',
            'environment' => 'production',
            'lockfiles' => [$composerLock],
        ]);

    $response->assertStatus(202)
        ->assertJsonStructure(['sync_id', 'status']);

    Queue::assertPushed(ProcessLockFilesJob::class);

    expect(DependencySync::count())->toBe(1);
    expect(Environment::where('project_id', $project->id)->where('name', 'production')->exists())->toBeTrue();

    $sync = DependencySync::first();
    expect($sync->lockfile_paths)->toHaveCount(1);
    Storage::disk('voight-lockfiles')->assertExists('my-project/production/composer.lock');
});

it('creates the environment if it does not exist', function () {
    Queue::fake();

    Project::factory()->create(['project_code' => 'new-project']);
    $user = \Illuminate\Foundation\Auth\User::forceCreate([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    $lockfile = UploadedFile::fake()->createWithContent('composer.lock', '{}');

    $this->actingAs($user)
        ->post('/api/voight/lock-file', [
            'project_code' => 'new-project',
            'environment' => 'staging',
            'lockfiles' => [$lockfile],
        ])
        ->assertStatus(202);

    expect(Environment::where('name', 'staging')->exists())->toBeTrue();
});

it('auto-creates project if it does not exist', function () {
    Queue::fake();

    $user = \Illuminate\Foundation\Auth\User::forceCreate([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    $lockfile = UploadedFile::fake()->createWithContent('composer.lock', '{}');

    $this->actingAs($user)
        ->post('/api/voight/lock-file', [
            'project_code' => 'brand-new-project',
            'environment' => 'production',
            'lockfiles' => [$lockfile],
        ])
        ->assertStatus(202);

    expect(Project::where('project_code', 'brand-new-project')->exists())->toBeTrue();
    expect(Environment::where('name', 'production')->exists())->toBeTrue();
    Queue::assertPushed(ProcessLockFilesJob::class);
});

it('rejects unauthenticated requests', function () {
    $lockfile = UploadedFile::fake()->createWithContent('composer.lock', '{}');

    $response = $this->post('/api/voight/lock-file', [
        'project_code' => 'test',
        'environment' => 'production',
        'lockfiles' => [$lockfile],
    ]);

    expect($response->status())->toBeGreaterThanOrEqual(300);
});

it('rejects request without lockfiles', function () {
    $user = \Illuminate\Foundation\Auth\User::forceCreate([
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    Project::factory()->create(['project_code' => 'test']);

    $this->actingAs($user)
        ->post('/api/voight/lock-file', [
            'project_code' => 'test',
            'environment' => 'production',
        ])
        ->assertStatus(302);
});
