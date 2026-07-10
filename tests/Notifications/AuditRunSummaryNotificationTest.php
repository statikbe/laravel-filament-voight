<?php

use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Notifications\AuditRunSummaryNotification;
use Statikbe\FilamentVoight\Notifications\AuditSummary;
use Statikbe\FilamentVoight\Tests\Support\User;

function makeAuditSummary(): AuditSummary
{
    return new AuditSummary(
        projectName: 'Demo Project',
        projectCode: 'PRJ-0001',
        environmentNames: ['production'],
        severityCounts: ['critical' => 2, 'high' => 1],
        totalFindings: 3,
        topFindings: [
            [
                'package' => 'vendor/package',
                'summary' => 'Remote code execution',
                'severity' => Severity::Critical,
                'score' => 9.8,
                'installed_version' => '1.0.0',
                'fixed_version' => '1.0.1',
            ],
        ],
        detailUrl: 'https://voight.test/voight/projects/PRJ-0001',
        generatedAt: now(),
    );
}

it('routes to the mail channel for email settings', function () {
    $notification = new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Email);

    expect($notification->via(new User))->toBe(['mail']);
});

it('routes to the slack channel for slack settings', function () {
    $notification = new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Slack);

    expect($notification->via(new User))->toBe(['slack']);
});

it('builds a markdown mail message with subject replacements', function () {
    $notification = new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Email);

    $mail = $notification->toMail(new User);

    expect($mail->markdown)->toBe('filament-voight::mail.audit-summary')
        ->and($mail->subject)->toContain('Demo Project')
        ->and($mail->subject)->toContain('3')
        ->and($mail->subject)->toContain('production')
        ->and($mail->viewData['summary'])->toBeInstanceOf(AuditSummary::class);
});

it('omits the mail from address when none is configured', function () {
    config()->set('filament-voight.notifications.mail_from_address', null);

    $mail = (new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Email))->toMail(new User);

    expect($mail->from)->toBe([]);
});

it('applies the configured mail from address and name', function () {
    config()->set('filament-voight.notifications.mail_from_address', 'alerts@example.com');
    config()->set('filament-voight.notifications.mail_from_name', 'Voight Alerts');

    $mail = (new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Email))->toMail(new User);

    expect($mail->from)->toBe(['alerts@example.com', 'Voight Alerts']);
});

it('renders the markdown mail body with findings and the detail button', function () {
    $mail = (new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Email))->toMail(new User);

    $html = (string) $mail->render();

    expect($html)->toContain('vendor/package')
        ->and($html)->toContain('Critical')
        ->and($html)->toContain('https://voight.test/voight/projects/PRJ-0001')
        ->and($html)->toContain('production');
});

it('builds a slack block kit message with header, severity section and detail button', function () {
    $notification = new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Slack);

    $payload = $notification->toSlack(new User)->toArray();
    $blocks = collect($payload['blocks']);

    $header = $blocks->firstWhere('type', 'header');
    $section = $blocks->firstWhere('type', 'section');
    $actions = $blocks->firstWhere('type', 'actions');

    expect($header['text']['text'])->toContain('Demo Project')
        ->and($section['text']['type'])->toBe('mrkdwn')
        ->and($section['text']['text'])->toContain('Critical')
        ->and($section['text']['text'])->toContain('2')
        ->and($actions['elements'][0]['url'])->toBe('https://voight.test/voight/projects/PRJ-0001');
});
