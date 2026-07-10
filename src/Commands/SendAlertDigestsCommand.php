<?php

namespace Statikbe\FilamentVoight\Commands;

use Illuminate\Console\Command;
use Statikbe\FilamentVoight\Enums\AlertFrequency;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Notifications\AlertDispatcher;
use Statikbe\FilamentVoight\Notifications\AuditDigestNotification;
use Statikbe\FilamentVoight\Notifications\AuditSummary;

class SendAlertDigestsCommand extends Command
{
    use Concerns\HasVoightBanner;

    public $signature = 'voight:send-alert-digests';

    public $description = 'Send due daily/weekly alert digests summarizing outstanding vulnerability findings';

    public function handle(AlertDispatcher $dispatcher): int
    {
        $this->displayBanner();

        $dueSettings = AlertSetting::query()
            ->where('is_enabled', true)
            ->whereIn('frequency', [AlertFrequency::Daily, AlertFrequency::Weekly])
            ->get()
            ->filter(fn (AlertSetting $setting): bool => $setting->isDigestDue());

        $totalSent = 0;

        foreach ($dueSettings as $setting) {
            $sentForSetting = $this->sendDigestsForSetting($setting, $dispatcher);

            if ($sentForSetting > 0) {
                $setting->update(['last_sent_at' => now()]);
                $totalSent += $sentForSetting;
            }
        }

        $this->info("Sent {$totalSent} digest message(s).");

        return self::SUCCESS;
    }

    private function sendDigestsForSetting(AlertSetting $setting, AlertDispatcher $dispatcher): int
    {
        $projects = Project::query()
            ->where('is_muted', false)
            ->when($setting->project_id !== null, fn ($query) => $query->whereKey($setting->project_id))
            ->get();

        $sent = 0;

        foreach ($projects as $project) {
            $summary = AuditSummary::fromProjectOutstanding($project, (float) $setting->severity_threshold);

            if (! $summary->hasFindings()) {
                continue;
            }

            if ($dispatcher->send($setting, new AuditDigestNotification($summary, $setting->channel))) {
                $this->line("  Sent digest for: {$project->project_code} ({$summary->totalFindings} finding(s))");
                $sent++;
            }
        }

        return $sent;
    }
}
