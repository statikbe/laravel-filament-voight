<?php

use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Models\AlertNotificationLog;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Notifications\NewFindingFilter;

function makeFinding(Vulnerability $vulnerability, Package $package): AuditFinding
{
    return AuditFinding::factory()->for(AuditRun::factory()->create(), 'auditRun')->create([
        'vulnerability_id' => $vulnerability->id,
        'package_id' => $package->id,
    ]);
}

it('returns every finding when nothing has been notified yet', function () {
    $setting = AlertSetting::factory()->immediate()->create();
    $findings = new Collection([
        makeFinding(Vulnerability::factory()->create(), Package::factory()->create()),
        makeFinding(Vulnerability::factory()->create(), Package::factory()->create()),
    ]);

    expect((new NewFindingFilter)->unnotified($setting, $findings))->toHaveCount(2);
});

it('drops findings this setting has already reported', function () {
    $setting = AlertSetting::factory()->immediate()->create();
    $vulnerability = Vulnerability::factory()->create();
    $package = Package::factory()->create();
    $filter = new NewFindingFilter;

    $findings = new Collection([makeFinding($vulnerability, $package)]);
    $filter->markNotified($setting, $findings);

    expect($filter->unnotified($setting, $findings))->toBeEmpty();
});

it('matches the vulnerability and package as a pair, not independently', function () {
    $setting = AlertSetting::factory()->immediate()->create();
    $vulnerability = Vulnerability::factory()->create();
    $otherVulnerability = Vulnerability::factory()->create();
    $package = Package::factory()->create();
    $otherPackage = Package::factory()->create();
    $filter = new NewFindingFilter;

    // Report vulnerability A on package B, and vulnerability B on package A.
    $filter->markNotified($setting, new Collection([
        makeFinding($vulnerability, $otherPackage),
        makeFinding($otherVulnerability, $package),
    ]));

    // A on A shares its vulnerability with one logged row and its package with the
    // other, but the pair itself is new and must still be reported.
    $unreported = new Collection([makeFinding($vulnerability, $package)]);

    expect($filter->unnotified($setting, $unreported))->toHaveCount(1);
});

it('keeps notification logs isolated per alert setting', function () {
    $first = AlertSetting::factory()->immediate()->create();
    $second = AlertSetting::factory()->immediate()->create();
    $findings = new Collection([makeFinding(Vulnerability::factory()->create(), Package::factory()->create())]);
    $filter = new NewFindingFilter;

    $filter->markNotified($first, $findings);

    expect($filter->unnotified($first, $findings))->toBeEmpty()
        ->and($filter->unnotified($second, $findings))->toHaveCount(1);
});

it('records a finding once even when marked repeatedly', function () {
    $setting = AlertSetting::factory()->immediate()->create();
    $vulnerability = Vulnerability::factory()->create();
    $package = Package::factory()->create();
    $filter = new NewFindingFilter;

    $filter->markNotified($setting, new Collection([makeFinding($vulnerability, $package)]));
    $filter->markNotified($setting, new Collection([makeFinding($vulnerability, $package)]));

    expect(AlertNotificationLog::query()->count())->toBe(1);
});

it('deduplicates repeated pairs within a single batch', function () {
    $setting = AlertSetting::factory()->immediate()->create();
    $vulnerability = Vulnerability::factory()->create();
    $package = Package::factory()->create();

    (new NewFindingFilter)->markNotified($setting, new Collection([
        makeFinding($vulnerability, $package),
        makeFinding($vulnerability, $package),
    ]));

    expect(AlertNotificationLog::query()->count())->toBe(1);
});

it('handles an empty finding set without querying', function () {
    $setting = AlertSetting::factory()->immediate()->create();
    $filter = new NewFindingFilter;

    $filter->markNotified($setting, new Collection);

    expect($filter->unnotified($setting, new Collection))->toBeEmpty()
        ->and(AlertNotificationLog::query()->count())->toBe(0);
});
