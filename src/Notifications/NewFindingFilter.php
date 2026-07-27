<?php

namespace Statikbe\FilamentVoight\Notifications;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statikbe\FilamentVoight\Models\AlertNotificationLog;
use Statikbe\FilamentVoight\Models\AlertSetting;
use Statikbe\FilamentVoight\Models\AuditFinding;

/**
 * Keeps immediate alerts from repeating themselves.
 *
 * A vulnerability that stays unfixed reappears in every nightly scan. Without this
 * filter an immediate alert setting would re-send the same summary every night, so
 * each vulnerability/package pair is recorded once per alert setting and skipped on
 * every later run.
 */
class NewFindingFilter
{
    /**
     * Findings this alert setting has never reported before.
     *
     * @param  Collection<int, AuditFinding>  $findings
     * @return Collection<int, AuditFinding>
     */
    public function unnotified(AlertSetting $alertSetting, Collection $findings): Collection
    {
        if ($findings->isEmpty()) {
            return $findings;
        }

        $alreadyNotified = $alertSetting->notificationLogs()
            ->whereIn('vulnerability_id', $findings->pluck('vulnerability_id')->unique())
            ->whereIn('package_id', $findings->pluck('package_id')->unique())
            ->get()
            ->map(fn (AlertNotificationLog $log): string => $this->key($log->vulnerability_id, $log->package_id))
            ->flip();

        return $findings
            ->reject(fn (AuditFinding $finding): bool => $alreadyNotified->has(
                $this->key($finding->vulnerability_id, $finding->package_id)
            ))
            ->values();
    }

    /**
     * Record the findings as reported so they are never sent by this setting again.
     *
     * @param  Collection<int, AuditFinding>  $findings
     */
    public function markNotified(AlertSetting $alertSetting, Collection $findings): void
    {
        $now = now();

        $rows = $findings
            ->unique(fn (AuditFinding $finding): string => $this->key($finding->vulnerability_id, $finding->package_id))
            ->map(fn (AuditFinding $finding): array => [
                'id' => (string) Str::ulid(),
                'alert_setting_id' => $alertSetting->id,
                'vulnerability_id' => $finding->vulnerability_id,
                'package_id' => $finding->package_id,
                'notified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($rows === []) {
            return;
        }

        AlertNotificationLog::query()->insertOrIgnore($rows);
    }

    private function key(string $vulnerabilityId, string $packageId): string
    {
        return "{$vulnerabilityId}:{$packageId}";
    }
}
