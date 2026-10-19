<?php

use Illuminate\Support\Collection;
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
        fixableCount: 2,
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
        detailUrl: 'https://voight.test/voight/audit-runs/01ARZ3NDEKTSV4RRFFQ69G5FAV',
        detailLabel: 'View Audit Run',
        projectUrl: 'https://voight.test/voight/projects/PRJ-0001',
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

it('leaves the mailer unset so the host app default is used', function () {
    config()->set('filament-voight.notifications.mailer', null);

    $mail = (new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Email))->toMail(new User);

    expect($mail->mailer)->toBeNull();
});

it('sends through the configured mailer when one is set', function () {
    config()->set('filament-voight.notifications.mailer', 'postmark');

    $mail = (new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Email))->toMail(new User);

    expect($mail->mailer)->toBe('postmark');
});

it('renders the markdown mail body with findings and the detail button', function () {
    $mail = (new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Email))->toMail(new User);

    $html = (string) $mail->render();

    expect($html)->toContain('vendor/package')
        ->and($html)->toContain('Critical')
        ->and($html)->toContain('https://voight.test/voight/audit-runs/01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($html)->toContain('View Audit Run')
        ->and($html)->toContain('production');
});

it('builds a slack block kit message with header, intro section and detail button', function () {
    $notification = new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Slack);

    $payload = $notification->toSlack(new User)->toArray();
    $blocks = collect($payload['blocks']);

    $header = $blocks->firstWhere('type', 'header');
    $section = $blocks->firstWhere('type', 'section');
    $actions = $blocks->firstWhere('type', 'actions');

    expect($header['text']['text'])->toContain('Demo Project')
        ->and($section['text']['type'])->toBe('mrkdwn')
        ->and($section['text']['text'])->toContain('Demo Project')
        ->and($actions['elements'][0]['url'])->toBe('https://voight.test/voight/audit-runs/01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($actions['elements'][0]['text']['text'])->toBe('View Audit Run');
});

function slackBlocks(AuditSummary $summary): Collection
{
    return collect((new AuditRunSummaryNotification($summary, AlertChannel::Slack))->toSlack(new User)->toArray()['blocks']);
}

function summaryWith(array $overrides): AuditSummary
{
    $base = makeAuditSummary();

    return new AuditSummary(...array_merge(get_object_vars($base), $overrides));
}

it('renders a plain slack header without emoji', function () {
    $header = slackBlocks(makeAuditSummary())->firstWhere('type', 'header');

    expect($header['text']['text'])->toStartWith('Demo Project');
});

it('pluralises the slack header', function () {
    $single = slackBlocks(summaryWith(['totalFindings' => 1]))->firstWhere('type', 'header');

    expect($single['text']['text'])->toContain('1 new finding')->not->toContain('findings');
});

it('renders one slack field per severity and the fixable line', function () {
    $blocks = slackBlocks(makeAuditSummary());
    $fields = $blocks->first(fn (array $block): bool => $block['type'] === 'section' && isset($block['fields']))['fields'];

    expect($fields)->toHaveCount(2)
        ->and($fields[0]['text'])->toBe('*Critical*  2')
        ->and($fields[1]['text'])->toBe('*High*  1')
        ->and($blocks->pluck('text.text')->implode("\n"))->toContain('2 of 3 fixable by upgrade');
});

it('omits the fixable line when nothing is fixable', function () {
    $text = slackBlocks(summaryWith(['fixableCount' => 0]))->pluck('text.text')->implode("\n");

    expect($text)->not->toContain('fixable by upgrade');
});

it('lists top findings with the fix version or a no fix yet marker', function () {
    $top = makeAuditSummary()->topFindings[0];

    $blocks = slackBlocks(summaryWith(['topFindings' => [
        $top,
        [...$top, 'package' => 'vendor/unfixed', 'fixed_version' => null, 'summary' => 'A <b> & more'],
    ]]));

    $text = $blocks->pluck('text.text')->implode("\n");

    expect($text)->toContain('*vendor/package* `1.0.0` → `1.0.1` · 9.8')
        ->and($text)->toContain('*vendor/unfixed* no fix yet')
        ->and($text)->toContain('A &lt;b&gt; &amp; more');
});

it('shows how many findings are not listed', function () {
    $text = slackBlocks(makeAuditSummary())->pluck('text.text')->implode("\n");

    expect($text)->toContain('…and 2 more');
});

it('adds a project button only when it differs from the detail link', function () {
    $run = slackBlocks(makeAuditSummary())->firstWhere('type', 'actions');
    $digest = slackBlocks(summaryWith(['detailUrl' => 'https://voight.test/voight/projects/PRJ-0001']))->firstWhere('type', 'actions');

    expect($run['elements'])->toHaveCount(2)
        ->and($run['elements'][1]['text']['text'])->toBe('View Project')
        ->and($digest['elements'])->toHaveCount(1);
});
