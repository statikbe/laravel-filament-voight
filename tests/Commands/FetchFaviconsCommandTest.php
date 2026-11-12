<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;

beforeEach(function () {
    Storage::fake('public');
    Http::fake([
        'https://site.test' => Http::response('<html><head><link rel="icon" href="/icon.png"></head></html>'),
        'https://site.test/icon.png' => Http::response('png'),
    ]);
});

it('fetches favicons for projects with an environment url', function () {
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);
    $withoutUrl = Project::factory()->create();

    $this->artisan('voight:fetch-favicons')->assertSuccessful();

    expect($project->refresh()->favicon_path)->not->toBeNull()
        ->and($withoutUrl->refresh()->favicon_fetched_at)->toBeNull();
});

it('skips fresh favicons unless forced', function () {
    $project = Project::factory()->create(['favicon_fetched_at' => now()]);
    Environment::factory()->for($project)->create(['name' => 'production', 'url' => 'https://site.test']);

    $this->artisan('voight:fetch-favicons')->assertSuccessful();
    expect($project->refresh()->favicon_path)->toBeNull();

    $this->artisan('voight:fetch-favicons --force')->assertSuccessful();
    expect($project->refresh()->favicon_path)->not->toBeNull();
});
