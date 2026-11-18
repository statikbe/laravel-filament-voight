<?php

namespace Statikbe\FilamentVoight\Services\Mcp;

use Illuminate\Support\Facades\DB;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Mcp\McpAccess;

/**
 * Current findings aggregated per project, team, customer, package or vulnerability. Only
 * visible projects count towards any group.
 */
class StatisticsService
{
    public const array GROUPS = ['project', 'team', 'customer', 'package', 'vulnerability'];

    /**
     * @return array{group_by: string, groups: array<int, array<string, mixed>>, total: int, truncated: bool, next_offset: int|null}
     */
    public function statistics(McpAccess $access, string $groupBy, ?Severity $minSeverity, ?string $team, ?string $customer, Page $page): array
    {
        $query = CurrentFindings::query($access, $minSeverity)
            ->leftJoin('voight_teams as t', 't.id', '=', 'p.team_id')
            ->leftJoin('voight_customers as c', 'c.id', '=', 'p.customer_id')
            ->when(filled($team), fn ($query) => $query->where('t.name', $team))
            ->when(filled($customer), fn ($query) => $query->where('c.name', $customer));

        [$key, $label, $groupColumns] = match ($groupBy) {
            'project' => ['p.project_code', 'p.name', ['p.project_code', 'p.name']],
            'team' => ['t.id', 't.name', ['t.id', 't.name']],
            'customer' => ['c.id', 'c.name', ['c.id', 'c.name']],
            'package' => [$this->concat(['pk.type', "':'", 'pk.name']), 'pk.name', ['pk.type', 'pk.name']],
            default => ['v.source_id', 'v.summary', ['v.source_id', 'v.summary']],
        };

        $query
            ->groupBy($groupColumns)
            ->select([
                DB::raw($key . ' as group_key'),
                DB::raw($label . ' as group_label'),
                ...CurrentFindings::severityColumns(),
                DB::raw('count(distinct p.id) as affected_projects'),
            ]);

        $total = (clone $query)->getCountForPagination();
        $rows = $query
            ->orderByDesc('max_score')
            ->orderByDesc('total')
            ->orderByDesc('affected_projects')
            ->orderBy('group_key')
            ->offset($page->offset)
            ->limit($page->limit)
            ->get();

        return [
            'group_by' => $groupBy,
            'groups' => $rows->map(fn (object $row): array => [
                'key' => $row->group_key,
                'label' => $row->group_label,
                ...CurrentFindings::severitySummary($row),
                'affected_projects' => (int) $row->affected_projects,
            ])->all(),
            ...$page->meta($total),
        ];
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function concat(array $parts): string
    {
        return in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)
            ? implode(' || ', $parts)
            : 'concat(' . implode(', ', $parts) . ')';
    }
}
