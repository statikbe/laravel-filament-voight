<?php

use Illuminate\Support\Facades\Notification;
use Statikbe\FilamentVoight\Models\AlertRecipient;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Notifications\AuditDigestNotification;
use Statikbe\FilamentVoight\Tests\Support\User;

function createProjectWithOutstandingFinding(float $score = 9.5, bool $muted = false): Project
{
    $project = $muted
        ? Project::factory()->muted()->create()
        : Project::factory()->create();

    $environment = Environment::factory()->for($project)->create(['name' => 'production']);
    $run = AuditRun::factory()->for($environment)->create();

    AuditFinding::factory()->for($run, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => $score])->id,
    ]);

    return $project;
}

function attachDigestEmailRecipient(AlertSetting $setting, string $email = 'digest@example.com'): User
{
    $user = User::forceCreate([
        'name' => 'Digest Dev',
        'email' => $email,
        'password' => bcrypt('secret'),
    ]);

    AlertRecipient::factory()->for($setting)->forRecipient($user)->create();

    return $user;
}

beforeEach(function () {
    Notification::fake();
});

it('applies daily due-ness based on last_sent_at', function (?int $hoursAgo, bool $shouldSend) {
    $project = createProjectWithOutstandingFinding();
    $setting = AlertSetting::factory()->for($project)->create([
        'last_sent_at' => $hoursAgo === null ? null : now()->subHours($hoursAgo),
    ]);
    $user = attachDigestEmailRecipient($setting);

    $this->artisan('voight:send-alert-digests')->assertExitCode(0);

    if ($shouldSend) {
        Notification::assertSentTo($user, AuditDigestNotification::class);
    } else {
        Notification::assertNothingSent();
    }
})->with([
    'never sent' => [null, true],
    'sent 12 hours ago' => [12, false],
    'sent 25 hours ago' => [25, true],
]);

it('applies weekly due-ness based on last_sent_at', function (int $daysAgo, bool $shouldSend) {
    $project = createProjectWithOutstandingFinding();
    $setting = AlertSetting::factory()->weekly()->for($project)->sentAt(now()->subDays($daysAgo))->create();
    $user = attachDigestEmailRecipient($setting);

    $this->artisan('voight:send-alert-digests')->assertExitCode(0);

    if ($shouldSend) {
        Notification::assertSentTo($user, AuditDigestNotification::class);
    } else {
        Notification::assertNothingSent();
    }
})->with([
    'sent 6 days ago' => [6, false],
    'sent 8 days ago' => [8, true],
]);

it('sends one digest per project with findings for global settings, skipping muted and clean projects', function () {
    $projectA = createProjectWithOutstandingFinding();
    $projectB = createProjectWithOutstandingFinding();
    $cleanProject = Project::factory()->create();
    $mutedProject = createProjectWithOutstandingFinding(muted: true);

    $setting = AlertSetting::factory()->global()->create();
    $user = attachDigestEmailRecipient($setting);

    $this->artisan('voight:send-alert-digests')->assertExitCode(0);

    Notification::assertSentToTimes($user, AuditDigestNotification::class, 2);

    $sentProjectCodes = Notification::sent($user, AuditDigestNotification::class)
        ->map(fn (AuditDigestNotification $notification): string => $notification->summary->projectCode);

    expect($sentProjectCodes)->toContain($projectA->project_code, $projectB->project_code)
        ->and($sentProjectCodes)->not->toContain($cleanProject->project_code, $mutedProject->project_code);
});

it('summarizes outstanding findings from the latest run per environment only', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create(['name' => 'production']);

    $oldRun = AuditRun::factory()->for($environment)->create(['started_at' => now()->subDays(2)]);
    AuditFinding::factory()->count(2)->for($oldRun, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 9.5])->id,
    ]);

    $latestRun = AuditRun::factory()->for($environment)->create(['started_at' => now()->subHour()]);
    AuditFinding::factory()->for($latestRun, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 9.5])->id,
    ]);

    $setting = AlertSetting::factory()->for($project)->create();
    $user = attachDigestEmailRecipient($setting);

    $this->artisan('voight:send-alert-digests')->assertExitCode(0);

    Notification::assertSentTo(
        $user,
        AuditDigestNotification::class,
        fn (AuditDigestNotification $notification): bool => $notification->summary->totalFindings === 1,
    );
});

it('advances last_sent_at only when a digest was actually sent', function () {
    $projectWithFindings = createProjectWithOutstandingFinding();
    $settingWithFindings = AlertSetting::factory()->for($projectWithFindings)->create();
    attachDigestEmailRecipient($settingWithFindings, 'with-findings@example.com');

    $cleanProject = Project::factory()->create();
    $settingWithoutFindings = AlertSetting::factory()->for($cleanProject)->create();
    attachDigestEmailRecipient($settingWithoutFindings, 'without-findings@example.com');

    $this->artisan('voight:send-alert-digests')->assertExitCode(0);

    expect($settingWithFindings->refresh()->last_sent_at)->not->toBeNull()
        ->and($settingWithoutFindings->refresh()->last_sent_at)->toBeNull();
});

it('never picks up immediate settings', function () {
    $project = createProjectWithOutstandingFinding();
    $setting = AlertSetting::factory()->immediate()->for($project)->create();
    attachDigestEmailRecipient($setting);

    $this->artisan('voight:send-alert-digests')->assertExitCode(0);

    Notification::assertNothingSent();
    expect($setting->refresh()->last_sent_at)->toBeNull();
});
