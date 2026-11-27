<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Statikbe\FilamentVoight\Enums\AuditRunTrigger;
use Statikbe\FilamentVoight\Enums\DependencySyncStatus;
use Statikbe\FilamentVoight\Jobs\ProcessLockFilesJob;
use Statikbe\FilamentVoight\Jobs\RunOsvScanJob;
use Statikbe\FilamentVoight\Jobs\RunTagRulesJob;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\DependencySync;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagRule;
use Statikbe\FilamentVoight\Services\RecordEnvironmentAuditRunsService;
use Statikbe\FilamentVoight\Services\TagAssigner;
use Statikbe\FilamentVoight\Services\TagRuleRunner;

beforeEach(function () {
    $this->rule = TagRule::factory()->create();
    Queue::fake([RunTagRulesJob::class]);
});

it('is queued once until it starts processing', function () {
    expect(new RunTagRulesJob)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);

    RunTagRulesJob::dispatch();
    RunTagRulesJob::dispatch();

    Queue::assertPushed(RunTagRulesJob::class, 1);
});

it('is queued when a taggable record is saved or deleted', function () {
    Project::factory()->create();

    Queue::assertPushed(RunTagRulesJob::class);
});

it('is queued when a customer is deleted', function () {
    $customer = Customer::factory()->create();
    Queue::fake([RunTagRulesJob::class]);

    $customer->delete();

    Queue::assertPushed(RunTagRulesJob::class);
});

it('is queued when tags are assigned', function () {
    $project = Project::factory()->create();
    Queue::fake([RunTagRulesJob::class]);

    app(TagAssigner::class)->assign([$project], collect([Tag::factory()->create()]));

    Queue::assertPushed(RunTagRulesJob::class);
});

it('is queued when a scan is recorded', function () {
    $environment = Environment::factory()->create();
    Queue::fake([RunTagRulesJob::class]);

    app(RecordEnvironmentAuditRunsService::class)->record($environment, [], [], [], AuditRunTrigger::Manual);

    Queue::assertPushed(RunTagRulesJob::class);
});

it('is not queued by the rule runner\'s own writes', function () {
    Project::factory()->create();
    Queue::fake([RunTagRulesJob::class]);

    app(TagRuleRunner::class)->runAll();

    Queue::assertNotPushed(RunTagRulesJob::class);
});

it('is not queued when no rule is active', function () {
    $this->rule->delete();

    Project::factory()->create();

    Queue::assertNotPushed(RunTagRulesJob::class);
});

it('runs every active rule from the command', function () {
    Project::factory()->create();

    $this->artisan('voight:run-tag-rules')->assertSuccessful();

    expect($this->rule->fresh()->last_run_at)->not->toBeNull();
});

it('schedules the command daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'voight:run-tag-rules'));

    expect($events)->toHaveCount(1);
});

it('is queued when a lockfile sync finishes', function () {
    Storage::fake('voight-lockfiles');
    Bus::fake([RunOsvScanJob::class]);
    $environment = Environment::factory()->create();
    Storage::disk('voight-lockfiles')->put('p/production/composer.lock', json_encode(['packages' => [['name' => 'acme/lib', 'version' => '1.0.0']]]));
    $sync = DependencySync::factory()->for($environment)->create([
        'lockfile_paths' => ['p/production/composer.lock'],
        'status' => DependencySyncStatus::Pending,
    ]);
    Queue::fake([RunTagRulesJob::class]);

    ProcessLockFilesJob::dispatchSync($sync);

    Queue::assertPushed(RunTagRulesJob::class);
});
