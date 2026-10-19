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
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text($this->intro())->markdown();
            });

        if ($summary->severityCounts !== []) {
            $message->sectionBlock(function (SectionBlock $block) use ($summary): void {
                foreach ($summary->severityCounts as $severityValue => $count) {
                    $severity = Severity::from($severityValue);

                    $block->field("*{$severity->label()}*  {$count}")->markdown();
                }
            });
        }

        if ($summary->fixableCount > 0) {
            $message->sectionBlock(function (SectionBlock $block) use ($summary): void {
                $block->text(voightTrans('notifications.common.fixable', [
                    'fixable' => $summary->fixableCount,
                    'total' => $summary->totalFindings,
                ]))->markdown();
            });
        }

        if ($summary->topFindings !== []) {
            $message
                ->dividerBlock()
                ->sectionBlock(function (SectionBlock $block): void {
                    $block->text($this->buildSlackTopFindingsText())->markdown();
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

    private function buildSlackTopFindingsText(): string
    {
        $lines = collect($this->summary->topFindings)->map(function (array $finding): string {
            $version = filled($finding['fixed_version'])
                ? trim(($finding['installed_version'] ? "`{$finding['installed_version']}` → " : '') . "`{$finding['fixed_version']}`")
                : voightTrans('notifications.common.no_fix');

            $score = number_format($finding['score'], 1);
            $summary = $this->escapeSlack(Str::limit((string) $finding['summary'], 140));

            return "*{$this->escapeSlack($finding['package'])}* {$version} · {$score}\n    {$summary}";
        });

        $hidden = $this->summary->totalFindings - count($this->summary->topFindings);

        if ($hidden > 0) {
            $lines->push(voightTrans('notifications.common.more', ['count' => $hidden]));
        }

        return '*' . voightTrans('notifications.common.top_issues') . "*\n" . $lines->implode("\n");
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
