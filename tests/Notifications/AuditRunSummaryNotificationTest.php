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
        totalFindings: 3,
        sections: [
            'composer' => [
                'label' => 'Composer',
                'total' => 3,
                'severityCounts' => ['critical' => 2, 'high' => 1],
                'fixableCount' => 2,
                'topFindings' => [
                    [
                        'package' => 'vendor/package',
                        'summary' => 'Remote code execution',
                        'severity' => Severity::Critical,
                        'score' => 9.8,
                        'installed_version' => '1.0.0',
                        'fixed_version' => '1.0.1',
                    ],
                ],
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

it('builds a slack block kit message with header, table and detail button', function () {
    $notification = new AuditRunSummaryNotification(makeAuditSummary(), AlertChannel::Slack);

    $blocks = collect($notification->toSlack(new User)->toArray()['blocks']);

    $header = $blocks->firstWhere('type', 'header');
    $section = $blocks->firstWhere('type', 'section');
    $actions = $blocks->firstWhere('type', 'actions');

    expect($header['text']['text'])->toContain('Demo Project')
        ->and($section['text']['type'])->toBe('mrkdwn')
        ->and($section['text']['text'])->toStartWith("```\n")->toEndWith("\n```")
        ->and($blocks->where('type', 'section'))->toHaveCount(1)
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

function withComposerSection(array $overrides): AuditSummary
{
    $sections = makeAuditSummary()->sections;
    $sections['composer'] = [...$sections['composer'], ...$overrides];

    return summaryWith(['sections' => $sections]);
}

it('renders a plain slack header without emoji', function () {
    $header = slackBlocks(makeAuditSummary())->firstWhere('type', 'header');

    expect($header['text']['text'])->toStartWith('Demo Project');
});

it('pluralises the slack header', function () {
    $single = slackBlocks(summaryWith(['totalFindings' => 1]))->firstWhere('type', 'header');

    expect($single['text']['text'])->toContain('1 new finding')->not->toContain('findings');
});

it('renders an aligned slack table with severity columns and fixable ratio', function () {
    $table = slackBlocks(makeAuditSummary())->firstWhere('type', 'section')['text']['text'];

    expect($table)->toBe("```\n          Critical  High  Fixable\nComposer         2     1      2/3\n```");
});

it('omits severity columns without findings and shows 0/total when nothing is fixable', function () {
    $table = slackBlocks(withComposerSection(['severityCounts' => ['high' => 3], 'fixableCount' => 0]))->firstWhere('type', 'section')['text']['text'];

    expect($table)->not->toContain('Critical')->not->toContain('Low')
        ->and($table)->toContain('High')->toContain('0/3');
});

it('does not repeat the intro sentence or individual findings in slack', function () {
    $text = slackBlocks(makeAuditSummary())->pluck('text.text')->implode("\n");

    expect($text)->not->toContain('The latest scan')
        ->not->toContain('vendor/package')
        ->not->toContain('Remote code execution')
        ->not->toContain('more');
});

it('adds a project button only when it differs from the detail link', function () {
    $run = slackBlocks(makeAuditSummary())->firstWhere('type', 'actions');
    $digest = slackBlocks(summaryWith(['detailUrl' => 'https://voight.test/voight/projects/PRJ-0001']))->firstWhere('type', 'actions');

    expect($run['elements'])->toHaveCount(2)
        ->and($run['elements'][1]['text']['text'])->toBe('View Project')
        ->and($digest['elements'])->toHaveCount(1);
});

function twoTypeSummary(): AuditSummary
{
    $finding = fn (string $package): array => [
        'package' => $package,
        'summary' => 'Issue',
        'severity' => Severity::High,
        'score' => 7.5,
        'installed_version' => '1.0.0',
        'fixed_version' => null,
    ];

    return summaryWith([
        'totalFindings' => 6,
        'sections' => [
            'composer' => ['label' => 'Composer', 'total' => 4, 'severityCounts' => ['high' => 4], 'fixableCount' => 0, 'topFindings' => [$finding('vendor/php-one'), $finding('vendor/php-two'), $finding('vendor/php-three')]],
            'npm' => ['label' => 'NPM', 'total' => 2, 'severityCounts' => ['high' => 2], 'fixableCount' => 0, 'topFindings' => [$finding('js-one'), $finding('js-two')]],
        ],
    ]);
}

it('renders one slack table row per package type, composer first', function () {
    $table = slackBlocks(twoTypeSummary())->firstWhere('type', 'section')['text']['text'];

    expect($table)->toBe("```\n          High  Fixable\nComposer     4      0/4\nNPM          2      0/2\n```")
        ->and($table)->not->toContain('vendor/php-one')->not->toContain('js-one');
});

it('omits the slack row of a package type without findings', function () {
    $summary = summaryWith(['sections' => ['npm' => twoTypeSummary()->sections['npm']]]);
    $table = slackBlocks($summary)->firstWhere('type', 'section')['text']['text'];

    expect($table)->toContain('NPM')->not->toContain('Composer');
});

it('omits the slack table without sections', function () {
    expect(slackBlocks(summaryWith(['sections' => []]))->where('type', 'section'))->toBeEmpty();
});

it('renders the sample slack table', function () {
    $sections = [
        'composer' => ['label' => 'Composer', 'total' => 6, 'severityCounts' => ['high' => 1, 'medium' => 3, 'low' => 2], 'fixableCount' => 6, 'topFindings' => []],
        'npm' => ['label' => 'npm', 'total' => 95, 'severityCounts' => ['critical' => 1, 'high' => 50, 'medium' => 39, 'low' => 5], 'fixableCount' => 92, 'topFindings' => []],
    ];

    expect(slackBlocks(summaryWith(['sections' => $sections]))->firstWhere('type', 'section')['text']['text'])
        ->toBe("```\n          Critical  High  Medium  Low  Fixable\nComposer         0     1       3    2      6/6\nnpm              1    50      39    5    92/95\n```");
});

it('renders one mail section per package type with its own more line', function () {
    $html = (string) (new AuditRunSummaryNotification(twoTypeSummary(), AlertChannel::Email))->toMail(new User)->render();

    expect($html)->toContain('Composer (4)')
        ->and($html)->toContain('NPM (2)')
        ->and(strpos($html, 'Composer (4)'))->toBeLessThan(strpos($html, 'NPM (2)'))
        ->and(strpos($html, 'js-one'))->toBeGreaterThan(strpos($html, 'NPM (2)'))
        ->and(substr_count($html, '…and 1 more'))->toBe(1);
});

it('omits the mail section of a package type without findings', function () {
    $summary = summaryWith(['sections' => ['npm' => twoTypeSummary()->sections['npm']]]);
    $html = (string) (new AuditRunSummaryNotification($summary, AlertChannel::Email))->toMail(new User)->render();

    expect($html)->toContain('NPM (2)')->not->toContain('Composer (')->not->toContain('vendor/php-one');
});
