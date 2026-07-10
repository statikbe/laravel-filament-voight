<?php

namespace Statikbe\FilamentVoight\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ActionsBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
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
            ->subject(voightTrans("notifications.{$this->langGroup()}.subject", $this->replacements()))
            ->markdown('filament-voight::mail.audit-summary', [
                'summary' => $this->summary,
                'headline' => voightTrans("notifications.{$this->langGroup()}.headline", $this->replacements()),
                'intro' => voightTrans("notifications.{$this->langGroup()}.intro", $this->replacements()),
            ]);

        $from = FilamentVoight::config()->getAlertMailFrom();

        if ($from !== null) {
            $message->from($from['address'], $from['name']);
        }

        return $message;
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text(voightTrans("notifications.{$this->langGroup()}.subject", $this->replacements()))
            ->headerBlock(voightTrans("notifications.{$this->langGroup()}.headline", $this->replacements()))
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text($this->buildSlackSummaryText())->markdown();
            })
            ->contextBlock(function (ContextBlock $block): void {
                $block->text(voightTrans('notifications.common.environments', [
                    'environments' => $this->summary->environmentList(),
                ]));
            })
            ->actionsBlock(function (ActionsBlock $block): void {
                $block->button(voightTrans('notifications.common.view_project'))
                    ->url($this->summary->detailUrl)
                    ->primary();
            });
    }

    private function buildSlackSummaryText(): string
    {
        $severityLines = collect($this->summary->severityCounts)
            ->map(fn (int $count, string $severityValue): string => '*' . Severity::from($severityValue)->label() . "*: {$count}")
            ->implode("\n");

        return voightTrans("notifications.{$this->langGroup()}.intro", $this->replacements()) . "\n\n" . $severityLines;
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
