<?php

use Illuminate\Support\Facades\Notification;
use Statikbe\FilamentVoight\Jobs\SendAuditAlertsJob;
use Statikbe\FilamentVoight\Models\AlertNotificationLog;
use Statikbe\FilamentVoight\Models\AlertRecipient;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Notifications\AuditRunSummaryNotification;
use Statikbe\FilamentVoight\Tests\Support\User;

function createAuditRunWithFinding(float $score = 9.5, ?Project $project = null): AuditRun
{
    $project ??= Project::factory()->create();
    $environment = Environment::factory()->for($project)->create(['name' => 'production']);
    $run = AuditRun::factory()->for($environment)->create();

    AuditFinding::factory()->for($run, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => $score])->id,
    ]);

    return $run;
}

function attachAlertEmailRecipient(AlertSetting $setting, string $email = 'dev@example.com'): User
{
    $user = User::forceCreate([
        'name' => 'Dev',
        'email' => $email,
        'password' => bcrypt('secret'),
    ]);

    AlertRecipient::factory()->for($setting)->forRecipient($user)->create();

    return $user;
}

beforeEach(function () {
    Notification::fake();
});

it('sends an immediate summary to resolved email recipients and stamps last_sent_at', function () {
    $project = Project::factory()->create();
    $run = createAuditRunWithFinding(9.5, $project);
    $setting = AlertSetting::factory()->immediate()->for($project)->create();
    $user = attachAlertEmailRecipient($setting);

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertSentTo(
        $user,
        AuditRunSummaryNotification::class,
        fn (AuditRunSummaryNotification $notification): bool => $notification->summary->totalFindings === 1,
    );
    expect($setting->refresh()->last_sent_at)->not->toBeNull();
});

it('fires global settings for any project scan', function () {
    $run = createAuditRunWithFinding();
    $setting = AlertSetting::factory()->global()->immediate()->create();
    $user = attachAlertEmailRecipient($setting);

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertSentTo($user, AuditRunSummaryNotification::class);
});

it('ignores disabled and non-immediate settings', function () {
    $project = Project::factory()->create();
    $run = createAuditRunWithFinding(9.5, $project);

    $disabled = AlertSetting::factory()->immediate()->for($project)->create(['is_enabled' => false]);
    attachAlertEmailRecipient($disabled, 'disabled@example.com');

    $daily = AlertSetting::factory()->for($project)->create();
    attachAlertEmailRecipient($daily, 'daily@example.com');

    $weekly = AlertSetting::factory()->weekly()->for($project)->create();
    attachAlertEmailRecipient($weekly, 'weekly@example.com');

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertNothingSent();
});

it('sends nothing when all findings are below the threshold', function () {
    $project = Project::factory()->create();
    $run = createAuditRunWithFinding(5.0, $project);
    $setting = AlertSetting::factory()->immediate()->for($project)->create(['severity_threshold' => 7.0]);
    attachAlertEmailRecipient($setting);

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertNothingSent();
    expect($setting->refresh()->last_sent_at)->toBeNull();
});

it('does not report the same vulnerability again on a later scan', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create(['name' => 'production']);
    $vulnerability = Vulnerability::factory()->create(['vulnerability_score' => 9.5]);
    $package = Package::factory()->create();

    $setting = AlertSetting::factory()->immediate()->for($project)->create();
    $user = attachAlertEmailRecipient($setting);

    $firstRun = AuditRun::factory()->for($environment)->create();
    AuditFinding::factory()->for($firstRun, 'auditRun')->create([
        'vulnerability_id' => $vulnerability->id,
        'package_id' => $package->id,
    ]);

    SendAuditAlertsJob::dispatchSync($firstRun);
    Notification::assertSentToTimes($user, AuditRunSummaryNotification::class, 1);

    // The vulnerability is still unfixed, so tonight's scan finds it again.
    $secondRun = AuditRun::factory()->for($environment)->create();
    AuditFinding::factory()->for($secondRun, 'auditRun')->create([
        'vulnerability_id' => $vulnerability->id,
        'package_id' => $package->id,
    ]);

    SendAuditAlertsJob::dispatchSync($secondRun);

    Notification::assertSentToTimes($user, AuditRunSummaryNotification::class, 1);
    expect(AlertNotificationLog::query()->count())->toBe(1);
});

it('reports only the findings that are new since the last alert', function () {
    $project = Project::factory()->create();
    $environment = Environment::factory()->for($project)->create(['name' => 'production']);
    $known = Vulnerability::factory()->create(['vulnerability_score' => 9.5]);
    $package = Package::factory()->create();

    $setting = AlertSetting::factory()->immediate()->for($project)->create();
    $user = attachAlertEmailRecipient($setting);

    $firstRun = AuditRun::factory()->for($environment)->create();
    AuditFinding::factory()->for($firstRun, 'auditRun')->create([
        'vulnerability_id' => $known->id,
        'package_id' => $package->id,
    ]);
    SendAuditAlertsJob::dispatchSync($firstRun);

    // Second scan re-finds the known vulnerability and discovers one more.
    $secondRun = AuditRun::factory()->for($environment)->create();
    AuditFinding::factory()->for($secondRun, 'auditRun')->create([
        'vulnerability_id' => $known->id,
        'package_id' => $package->id,
    ]);
    AuditFinding::factory()->for($secondRun, 'auditRun')->create([
        'vulnerability_id' => Vulnerability::factory()->create(['vulnerability_score' => 8.0])->id,
        'package_id' => Package::factory()->create()->id,
    ]);

    SendAuditAlertsJob::dispatchSync($secondRun);

    Notification::assertSentToTimes($user, AuditRunSummaryNotification::class, 2);
    Notification::assertSentTo(
        $user,
        AuditRunSummaryNotification::class,
        fn (AuditRunSummaryNotification $notification): bool => $notification->summary->totalFindings === 1,
    );
    expect(AlertNotificationLog::query()->count())->toBe(2);
});

it('tracks notified findings per alert setting', function () {
    $project = Project::factory()->create();
    $run = createAuditRunWithFinding(9.5, $project);

    $first = AlertSetting::factory()->immediate()->for($project)->create();
    $firstUser = attachAlertEmailRecipient($first, 'first@example.com');
    $second = AlertSetting::factory()->immediate()->for($project)->create();
    $secondUser = attachAlertEmailRecipient($second, 'second@example.com');

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertSentTo($firstUser, AuditRunSummaryNotification::class);
    Notification::assertSentTo($secondUser, AuditRunSummaryNotification::class);
    expect($first->notificationLogs()->count())->toBe(1)
        ->and($second->notificationLogs()->count())->toBe(1);
});

it('does not record a notification log when the alert could not be delivered', function () {
    $project = Project::factory()->create();
    $run = createAuditRunWithFinding(9.5, $project);

    // Email setting with no recipients: nothing resolves, so nothing is sent.
    $setting = AlertSetting::factory()->immediate()->for($project)->create();

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertNothingSent();
    expect($setting->notificationLogs()->count())->toBe(0);
});

it('routes slack alerts to the channel configured on the setting', function () {
    $project = Project::factory()->create();
    $run = createAuditRunWithFinding(9.5, $project);
    AlertSetting::factory()->immediate()->for($project)->slack('#security')->create();

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertSentOnDemand(
        AuditRunSummaryNotification::class,
        fn (AuditRunSummaryNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['slack'] === '#security',
    );
});

it('falls back to the module default slack channel', function () {
    config()->set('filament-voight.notifications.slack_default_channel', '#module-default');

    $project = Project::factory()->create();
    $run = createAuditRunWithFinding(9.5, $project);
    AlertSetting::factory()->immediate()->for($project)->slack(null)->create();

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertSentOnDemand(
        AuditRunSummaryNotification::class,
        fn (AuditRunSummaryNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['slack'] === '#module-default',
    );
});

it('falls back to the services slack channel when no other channel is configured', function () {
    config()->set('services.slack.notifications.channel', '#services-default');

    $project = Project::factory()->create();
    $run = createAuditRunWithFinding(9.5, $project);
    AlertSetting::factory()->immediate()->for($project)->slack(null)->create();

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertSentOnDemand(
        AuditRunSummaryNotification::class,
        fn (AuditRunSummaryNotification $notification, array $channels, object $notifiable): bool => $notifiable->routes['slack'] === '#services-default',
    );
});

it('skips slack alerts entirely when no channel can be resolved', function () {
    $project = Project::factory()->create();
    $run = createAuditRunWithFinding(9.5, $project);
    $setting = AlertSetting::factory()->immediate()->for($project)->slack(null)->create();

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertNothingSent();
    expect($setting->refresh()->last_sent_at)->toBeNull();
});

it('skips muted projects', function () {
    $project = Project::factory()->muted()->create();
    $run = createAuditRunWithFinding(9.5, $project);
    $setting = AlertSetting::factory()->immediate()->for($project)->create();
    attachAlertEmailRecipient($setting);

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertNothingSent();
});

it('does not fire settings scoped to another project', function () {
    $run = createAuditRunWithFinding();
    $otherProjectSetting = AlertSetting::factory()->immediate()->create();
    attachAlertEmailRecipient($otherProjectSetting);

    SendAuditAlertsJob::dispatchSync($run);

    Notification::assertNothingSent();
});
