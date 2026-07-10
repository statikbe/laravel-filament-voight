<?php

use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Notifications\AuditDigestNotification;
use Statikbe\FilamentVoight\Notifications\AuditSummary;
use Statikbe\FilamentVoight\Tests\Support\User;

function makeDigestSummary(): AuditSummary
{
    return new AuditSummary(
        projectName: 'Demo Project',
        projectCode: 'PRJ-0001',
        environmentNames: ['production', 'staging'],
        severityCounts: ['high' => 4],
        totalFindings: 4,
        topFindings: [],
        detailUrl: 'https://voight.test/voight/projects/PRJ-0001',
        generatedAt: now(),
    );
}

it('uses the digest translation keys for mail', function () {
    $mail = (new AuditDigestNotification(makeDigestSummary(), AlertChannel::Email))->toMail(new User);

    expect($mail->subject)->toBe(voightTrans('notifications.audit_digest.subject', [
        'project' => 'Demo Project',
        'environment' => 'production, staging',
        'total' => 4,
    ]))
        ->and($mail->subject)->toContain('digest')
        ->and($mail->markdown)->toBe('filament-voight::mail.audit-summary');
});

it('uses the digest translation keys for the slack header', function () {
    $payload = (new AuditDigestNotification(makeDigestSummary(), AlertChannel::Slack))->toSlack(new User)->toArray();

    $header = collect($payload['blocks'])->firstWhere('type', 'header');

    expect($header['text']['text'])->toBe(voightTrans('notifications.audit_digest.headline', [
        'project' => 'Demo Project',
        'environment' => 'production, staging',
        'total' => 4,
    ]));
});
