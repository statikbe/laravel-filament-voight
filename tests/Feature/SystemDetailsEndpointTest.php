<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use Statikbe\FilamentVoight\Events\EnvironmentCreatedViaApi;
use Statikbe\FilamentVoight\Http\Middleware\AuthenticateProjectToken;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Models\Project;

beforeEach(function () {
    // The test case swaps the API middleware for `auth`; swap the real project-token middleware back in.
    $route = Route::getRoutes()->match(Request::create('/api/voight/system-details', 'POST'));
    $route->action['middleware'] = [AuthenticateProjectToken::class];
});

function systemDetailsPayload(array $overrides = []): array
{
    return array_merge([
        'environment' => 'production',
        'collected_at' => '2026-10-06T09:00:00+00:00',
        'app' => ['environment' => 'production', 'debug_mode' => false],
        'framework' => ['name' => 'laravel', 'version' => '12.0.0', 'groups' => ['environment' => ['laravel_version' => '12.0.0']]],
        'server' => ['php' => ['version' => '8.3.12', 'extensions' => ['curl' => '8.3.12', 'mbstring' => null]]],
    ], $overrides);
}

it('rejects requests without a token', function () {
    $this->postJson('/api/voight/system-details', systemDetailsPayload())->assertUnauthorized();
});

it('rejects a token that does not belong to a project', function () {
    $plain = 'user-token-plain';
    PersonalAccessToken::forceCreate([
        'tokenable_type' => Customer::class,
        'tokenable_id' => Customer::factory()->create()->id,
        'name' => 'x',
        'token' => hash('sha256', $plain),
        'abilities' => ['*'],
    ]);
    $token = $plain;

    $this->withToken($token)->postJson('/api/voight/system-details', systemDetailsPayload())->assertUnauthorized();
});

it('stores the snapshot and indexed php version on the token project environment', function () {
    $project = Project::factory()->create();
    $token = $project->createToken('ci')->plainTextToken;

    $this->withToken($token)->postJson('/api/voight/system-details', systemDetailsPayload())->assertNoContent();

    $environment = Environment::where('project_id', $project->id)->where('name', 'production')->firstOrFail();
    $details = $environment->systemDetails;

    expect($details->php_version)->toBe('8.3.12')
        ->and($details->payload['framework']['version'])->toBe('12.0.0')
        ->and($details->payload['server']['php']['version'])->toBe('8.3.12')
        ->and($details->payload['framework']['groups'])->not->toBeEmpty()
        ->and($details->collected_at->toIso8601String())->toBe('2026-10-06T09:00:00+00:00')
        ->and($details->received_at)->not->toBeNull();
});

it('accepts a payload without app and framework', function () {
    $project = Project::factory()->create();
    $token = $project->createToken('ci')->plainTextToken;

    $this->withToken($token)->postJson('/api/voight/system-details', systemDetailsPayload(['app' => null, 'framework' => null, 'server' => ['php' => []]]))->assertNoContent();

    expect(EnvironmentSystemDetail::first()->php_version)->toBeNull();
});

it('overwrites the previous snapshot', function () {
    $project = Project::factory()->create();
    $token = $project->createToken('ci')->plainTextToken;

    $this->withToken($token)->postJson('/api/voight/system-details', systemDetailsPayload())->assertNoContent();
    $this->withToken($token)->postJson('/api/voight/system-details', systemDetailsPayload([
        'server' => ['php' => ['version' => '8.4.1']],
    ]))->assertNoContent();

    expect(Environment::count())->toBe(1)
        ->and(EnvironmentSystemDetail::count())->toBe(1)
        ->and(EnvironmentSystemDetail::first()->php_version)->toBe('8.4.1')
        ->and(EnvironmentSystemDetail::first()->payload['server']['php']['version'])->toBe('8.4.1');
});

it('creates an unknown environment and dispatches the event', function () {
    Event::fake([EnvironmentCreatedViaApi::class]);
    $project = Project::factory()->create();
    $token = $project->createToken('ci')->plainTextToken;

    $this->withToken($token)->postJson('/api/voight/system-details', systemDetailsPayload(['environment' => 'acceptance']))
        ->assertNoContent();

    expect(Environment::where('project_id', $project->id)->where('name', 'acceptance')->exists())->toBeTrue();
    Event::assertDispatched(EnvironmentCreatedViaApi::class);
});

it('requires the server section', function () {
    $token = Project::factory()->create()->createToken('ci')->plainTextToken;

    $this->withToken($token)->postJson('/api/voight/system-details', systemDetailsPayload(['server' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('server');
});

it('rejects oversized bodies with 413', function () {
    $token = Project::factory()->create()->createToken('ci')->plainTextToken;

    $this->withToken($token)->postJson('/api/voight/system-details', systemDetailsPayload([
        'server' => ['php' => ['blob' => str_repeat('a', 300 * 1024)]],
    ]))->assertStatus(413);
});
