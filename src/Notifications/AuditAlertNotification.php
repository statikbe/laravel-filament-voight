<?php

namespace Statikbe\FilamentVoight\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ActionsBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Enums\AlertChannel;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Facades\FilamentVoight;

abstract class AuditAlertNotification extends Notification
{
    public function __construct(
        public readonly AuditSummary $summary,
        public readonly AlertChannel $channel,
    ) {}

    abstract protected function langGroup(): string;

    /**
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        return match ($this->channel) {
            AlertChannel::Email => ['mail'],
            AlertChannel::Slack => ['slack'],
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->subject())
            ->markdown('filament-voight::mail.audit-summary', [
                'summary' => $this->summary,
                'headline' => voightTrans("notifications.{$this->langGroup()}.headline", $this->replacements()),
                'intro' => $this->intro(),
            ]);

        $mailer = FilamentVoight::config()->getAlertMailer();

        if ($mailer !== null) {
            $message->mailer($mailer);
        }

        $from = FilamentVoight::config()->getAlertMailFrom();

        if ($from !== null) {
            $message->from($from['address'], $from['name']);
        }

        return $message;
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $summary = $this->summary;
        $headline = voightTransChoice("notifications.{$this->langGroup()}.slack_headline", $summary->totalFindings, $this->replacements());

        $message = (new SlackMessage)
            ->text($this->subject())
            // Slack rejects header text above 150 characters.
            ->headerBlock(Str::limit($headline, 150))
            ->contextBlock(function (ContextBlock $block) use ($summary): void {
                $scanned = voightTrans('notifications.common.scanned', [
                    'time' => "<!date^{$summary->generatedAt->timestamp}^{date_short_pretty} {time}|{$summary->generatedAt->toDateTimeString()}>",
                ]);

                $block->text($summary->environmentList() . ' · ' . $scanned)->markdown();
            });

        if ($summary->sections !== []) {
            $table = $this->buildSlackTable();

            $message->sectionBlock(function (SectionBlock $block) use ($table): void {
                $block->text("```\n{$table}\n```")->markdown();
            });
        }

        return $message
            ->dividerBlock()
            ->actionsBlock(function (ActionsBlock $block) use ($summary): void {
                $block->button($summary->detailLabel)->url($summary->detailUrl)->primary();

                // A digest already links to the project, so a second button would repeat it.
                if ($summary->projectUrl !== $summary->detailUrl) {
                    $block->button(voightTrans('notifications.common.view_project'))->url($summary->projectUrl);
                }
            });
    }

    /**
     * Monospace table, one row per package type; severity columns only for severities present in some section.
     */
    private function buildSlackTable(): string
    {
        $severities = collect([Severity::Critical, Severity::High, Severity::Medium, Severity::Low, Severity::None])
            ->filter(fn (Severity $severity): bool => collect($this->summary->sections)->contains(fn (array $section): bool => ($section['severityCounts'][$severity->value] ?? 0) > 0));

        $rows = [[
            '',
            ...$severities->map(fn (Severity $severity): string => $severity->label())->all(),
            voightTrans('notifications.common.fixable_column'),
        ]];

        foreach ($this->summary->sections as $section) {
            $rows[] = [
                $section['label'],
                ...$severities->map(fn (Severity $severity): string => (string) ($section['severityCounts'][$severity->value] ?? 0))->all(),
                "{$section['fixableCount']}/{$section['total']}",
            ];
        }

        $widths = array_map(
            fn (int $column): int => max(array_map(fn (array $row): int => mb_strlen($row[$column]), $rows)),
            array_keys($rows[0]),
        );

        return collect($rows)
            ->map(fn (array $row): string => $this->escapeSlack(rtrim(collect($row)
                ->map(fn (string $cell, int $column): string => mb_str_pad($cell, $widths[$column], ' ', $column === 0 ? STR_PAD_RIGHT : STR_PAD_LEFT))
                ->implode('  '))))
            ->implode("\n");
    }

    /**
     * Slack mrkdwn treats these three characters as control characters.
     */
    private function escapeSlack(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    private function subject(): string
    {
        return voightTransChoice("notifications.{$this->langGroup()}.subject", $this->summary->totalFindings, $this->replacements());
    }

    private function intro(): string
    {
        return voightTransChoice("notifications.{$this->langGroup()}.intro", $this->summary->totalFindings, $this->replacements());
    }

    /**
     * @return array{project: string, environment: string, total: int}
     */
    protected function replacements(): array
    {
        return [
            'project' => $this->summary->projectName,
            'environment' => $this->summary->environmentList(),
            'total' => $this->summary->totalFindings,
        ];
    }
}
