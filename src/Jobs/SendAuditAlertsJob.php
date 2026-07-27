<?php

namespace Statikbe\FilamentVoight\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentVoight\Enums\AlertFrequency;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Notifications\AlertDispatcher;
use Statikbe\FilamentVoight\Notifications\AuditRunSummaryNotification;
use Statikbe\FilamentVoight\Notifications\AuditSummary;
use Statikbe\FilamentVoight\Notifications\NewFindingFilter;

class SendAuditAlertsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public AuditRun $auditRun,
    ) {
        if ($queue = FilamentVoight::config()->getAlertsQueue()) {
            $this->onQueue($queue);
        }
    }

    public function handle(AlertDispatcher $dispatcher, NewFindingFilter $newFindingFilter): void
    {
        $project = $this->auditRun->environment->project;

        if ($project->is_muted) {
            Log::info('[Voight] Audit alerts skipped: project is muted', [
                'audit_run' => $this->auditRun->id,
                'project' => $project->project_code,
            ]);

            return;
        }

        $settings = AlertSetting::query()
            ->where('is_enabled', true)
            ->where('frequency', AlertFrequency::Immediate)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('project_id')
                ->orWhere('project_id', $project->id))
            ->get();

        foreach ($settings as $setting) {
            $findings = AuditSummary::findingsForRun($this->auditRun, (float) $setting->severity_threshold);
            $newFindings = $newFindingFilter->unnotified($setting, $findings);

            if ($newFindings->isEmpty()) {
                Log::info('[Voight] Audit alert skipped: no findings this setting has not already reported', [
                    'alert_setting' => $setting->id,
                    'audit_run' => $this->auditRun->id,
                    'findings' => $findings->count(),
                ]);

                continue;
            }

            $summary = AuditSummary::fromRunFindings($this->auditRun, $newFindings);

            if (! $dispatcher->send($setting, new AuditRunSummaryNotification($summary, $setting->channel))) {
                continue;
            }

            $newFindingFilter->markNotified($setting, $newFindings);
            $setting->update(['last_sent_at' => now()]);

            Log::info('[Voight] Audit alert sent', [
                'alert_setting' => $setting->id,
                'audit_run' => $this->auditRun->id,
                'channel' => $setting->channel->value,
                'findings' => $summary->totalFindings,
            ]);
        }
    }
}
