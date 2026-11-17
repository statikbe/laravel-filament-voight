<?php

namespace Statikbe\FilamentVoight\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Statikbe\FilamentVoight\Enums\EnvironmentStage;
use Statikbe\FilamentVoight\Enums\Framework;
use Statikbe\FilamentVoight\Enums\UpgradeStatus;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\EnvironmentSystemDetail;
use Statikbe\FilamentVoight\Models\Project;

/**
 * A project's stack (framework versions, PHP version, upgrade status) as read from its representative
 * environment: the first environment of the highest stage, in EnvironmentStage order.
 *
 * Versions are compared in SQL through a numeric cast (`REPLACE(version, 'v', '') + 0`): "12.3.0" becomes
 * 12.3 and "dev-main" becomes 0. That orders by major.minor only, which is enough for sorting and major filters.
 */
class ProjectStackService
{
    /**
     * Select the representative environment of each project and eager load what the stack columns read.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function withStack(Builder $query): Builder
    {
        [$sql, $bindings] = self::representativeEnvironmentSql();

        if (blank($query->getQuery()->columns)) {
            $query->select($query->getModel()->qualifyColumn('*'));
        }

        return $query
            ->selectRaw("({$sql}) as representative_environment_id", $bindings)
            ->with([
                'representativeEnvironment.systemDetails',
                'representativeEnvironment.environmentPackages' => fn ($packages) => $packages
                    ->whereHas('package', fn ($package) => $package->whereIn('name', self::packageNames(Framework::cases())))
                    ->with('package'),
            ]);
    }

    public static function version(Project $project, Framework $framework): ?string
    {
        return $project->representativeEnvironment?->environmentPackages
            ->first(fn (EnvironmentPackage $installed): bool => $installed->package->name === $framework->value)
            ?->version;
    }

    public static function phpVersion(Project $project): ?string
    {
        return $project->representativeEnvironment?->systemDetails?->php_version;
    }

    public static function upgradeStatus(Project $project): UpgradeStatus
    {
        if (self::installedVersions($project)->isEmpty()) {
            return UpgradeStatus::Unknown;
        }

        return self::packagesBehind($project)->isEmpty() ? UpgradeStatus::Current : UpgradeStatus::NeedsUpgrade;
    }

    /**
     * One "Laravel 11.3.0 → 13" line per tracked package behind its latest major, null when none is.
     */
    public static function upgradeDescription(Project $project): ?string
    {
        $lines = self::packagesBehind($project)->map(function (string $version, string $package): string {
            $framework = Framework::from($package);

            return voightTrans('models.project.fields.upgrade_line', [
                'framework' => $framework->label(),
                'from' => $version,
                'to' => $framework->latestMajor(),
            ]);
        });

        return $lines->isEmpty() ? null : $lines->implode("\n");
    }

    /**
     * @return Collection<string, string> Installed version per package name
     */
    private static function installedVersions(Project $project): Collection
    {
        return collect(Framework::cases())
            ->mapWithKeys(fn (Framework $framework): array => [$framework->value => self::version($project, $framework)])
            ->filter();
    }

    /**
     * @return Collection<string, string> Installed version per package name that is behind its latest major
     */
    private static function packagesBehind(Project $project): Collection
    {
        return self::installedVersions($project)->filter(function (string $version, string $package): bool {
            $major = preg_match('/^v?(\d+)/i', $version, $matches) === 1 ? (int) $matches[1] : 0;

            return $major > 0 && $major < Framework::from($package)->latestMajor();
        });
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function orderByVersion(Builder $query, Framework $framework, string $direction): Builder
    {
        $version = self::installedPackages($framework)->select('version')->limit(1);

        return $query->orderByRaw("REPLACE(({$version->toSql()}), 'v', '') + 0 " . self::normalizeDirection($direction), $version->getBindings());
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function orderByPhpVersion(Builder $query, string $direction): Builder
    {
        $version = self::phpDetails()->select('php_version')->limit(1);

        return $query->orderByRaw("({$version->toSql()}) + 0 " . self::normalizeDirection($direction), $version->getBindings());
    }

    /**
     * @param  Builder<Project>  $query
     * @param  array<int, int|string>  $majors
     * @return Builder<Project>
     */
    public static function constrainToMajors(Builder $query, Framework $framework, array $majors): Builder
    {
        $number = self::versionNumber();

        return $query->whereExists(self::installedPackages($framework)->where(function (Builder $installed) use ($majors, $number): void {
            foreach ($majors as $major) {
                $installed->orWhereRaw("{$number} >= ? and {$number} < ?", [(int) $major, (int) $major + 1]);
            }
        }));
    }

    /**
     * @param  Builder<Project>  $query
     * @param  array<int, string>  $versions  Minor versions such as "8.3"
     * @return Builder<Project>
     */
    public static function constrainToPhpVersions(Builder $query, array $versions): Builder
    {
        return $query->whereExists(self::phpDetails()->where(function (Builder $details) use ($versions): void {
            foreach ($versions as $version) {
                $details->orWhere('php_version', $version)->orWhere('php_version', 'like', "{$version}.%");
            }
        }));
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function constrainToUpgradeStatuses(Builder $query, UpgradeStatus ...$statuses): Builder
    {
        $number = self::versionNumber();
        $tracked = fn (): Builder => self::installedPackages(...Framework::cases());
        $behind = fn (): Builder => $tracked()->where(function (Builder $installed) use ($number): void {
            foreach (Framework::cases() as $framework) {
                $installed->orWhere(fn (Builder $installed) => $installed
                    ->whereHas('package', fn ($package) => $package->where('name', $framework->value))
                    ->whereRaw("{$number} > 0 and {$number} < ?", [$framework->latestMajor()]));
            }
        });

        return $query->where(function (Builder $query) use ($statuses, $tracked, $behind): void {
            foreach ($statuses as $status) {
                $query->orWhere(fn (Builder $query) => match ($status) {
                    UpgradeStatus::NeedsUpgrade => $query->whereExists($behind()),
                    UpgradeStatus::Current => $query->whereNotExists($behind())->whereExists($tracked()),
                    UpgradeStatus::Unknown => $query->whereNotExists($tracked()),
                });
            }
        });
    }

    /**
     * Order by UpgradeStatus case order (needs upgrade, current, unknown), then by name. Each status is
     * evaluated by constrainToUpgradeStatuses() against an aliased probe of the project row, so the filter
     * and the sort cannot drift apart.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public static function orderByUpgradeStatus(Builder $query, string $direction): Builder
    {
        $model = $query->getModel();
        $bindings = [];
        $cases = [];

        foreach (UpgradeStatus::cases() as $position => $status) {
            $probe = $model->newQuery()
                ->withoutGlobalScopes()
                ->from("{$model->getTable()} as upgrade_status_probe")
                ->selectRaw('1')
                ->whereColumn('upgrade_status_probe.' . $model->getKeyName(), $model->getQualifiedKeyName());

            self::constrainToUpgradeStatuses($probe, $status);

            $cases[] = "when exists ({$probe->toSql()}) then {$position}";
            $bindings = [...$bindings, ...$probe->getBindings()];
        }

        return $query
            ->orderByRaw('case ' . implode(' ', $cases) . ' else ' . count($cases) . ' end ' . self::normalizeDirection($direction), $bindings)
            ->orderBy($model->qualifyColumn('name'));
    }

    /**
     * Minor versions ("8.3") reported by any environment, newest first.
     *
     * @return array<string, string>
     */
    public static function phpVersionOptions(): array
    {
        return EnvironmentSystemDetail::query()
            ->whereNotNull('php_version')
            ->distinct()
            ->pluck('php_version')
            ->map(fn (string $version): string => implode('.', array_slice(explode('.', $version), 0, 2)))
            ->unique()
            ->sortDesc(SORT_NATURAL)
            ->mapWithKeys(fn (string $version): array => [$version => $version])
            ->all();
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function representativeEnvironmentSql(): array
    {
        $environment = new (FilamentVoight::config()->getEnvironmentModel());
        $projectTable = (new (FilamentVoight::config()->getProjectModel()))->getTable();

        $subqueries = array_map(fn (EnvironmentStage $stage) => $environment->newQuery()
            ->select($environment->qualifyColumn('id'))
            ->whereColumn($environment->qualifyColumn('project_id'), "{$projectTable}.id")
            ->tap(fn (Builder $environments) => $environment::constrainToStages($environments, $stage))
            ->orderBy($environment->qualifyColumn('id'))
            ->limit(1)
            ->toBase(), EnvironmentStage::cases());

        return [
            'coalesce(' . implode(', ', array_map(fn ($subquery): string => "({$subquery->toSql()})", $subqueries)) . ')',
            array_merge(...array_map(fn ($subquery): array => $subquery->getBindings(), $subqueries)),
        ];
    }

    /**
     * @return Builder<EnvironmentPackage>
     */
    private static function installedPackages(Framework ...$frameworks): Builder
    {
        $installed = new (FilamentVoight::config()->getEnvironmentPackageModel());
        [$sql, $bindings] = self::representativeEnvironmentSql();

        return $installed->newQuery()
            ->whereRaw("{$installed->qualifyColumn('environment_id')} = ({$sql})", $bindings)
            ->whereHas('package', fn ($package) => $package->whereIn('name', self::packageNames($frameworks)));
    }

    /**
     * @return Builder<EnvironmentSystemDetail>
     */
    private static function phpDetails(): Builder
    {
        [$sql, $bindings] = self::representativeEnvironmentSql();

        return EnvironmentSystemDetail::query()->whereRaw("environment_id = ({$sql})", $bindings);
    }

    private static function versionNumber(): string
    {
        $table = (new (FilamentVoight::config()->getEnvironmentPackageModel()))->getTable();

        return "(REPLACE({$table}.version, 'v', '') + 0)";
    }

    private static function normalizeDirection(string $direction): string
    {
        return strtolower($direction) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * @param  array<int, Framework>  $frameworks
     * @return array<int, string>
     */
    private static function packageNames(array $frameworks): array
    {
        return array_map(fn (Framework $framework): string => $framework->value, $frameworks);
    }
}
