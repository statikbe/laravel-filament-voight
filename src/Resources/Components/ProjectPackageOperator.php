<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Services\Mcp\PackageUsageService;

/**
 * Matches projects with any of the selected packages installed in any environment, optionally within a version range.
 * With severities selected, a package only counts when a current finding of that severity exists on it.
 */
class ProjectPackageOperator extends InstalledVersionRangeOperator
{
    protected bool $versionRequired = false;

    #[\Override]
    public function getLabel(): string
    {
        return voightTrans($this->isInverse() ? 'models.package.installed_version.not_installed' : 'models.package.installed_version.installed');
    }

    #[\Override]
    public function getSummary(): string
    {
        $severities = collect($this->selectedSeverities())->map(fn (Severity $severity): string => $severity->label());

        return trim($this->selectedPackages()->pluck('name')->implode(', ')
            . ' ' . parent::getSummary()
            . ($severities->isEmpty() ? '' : ' (' . $severities->implode(', ') . ')'));
    }

    /**
     * @return array<int, Select|Grid>
     */
    #[\Override]
    public function getFormSchema(): array
    {
        return [
            Select::make('package_id')
                ->label(voightTrans('models.package.label'))
                ->multiple()
                ->searchable()
                ->required()
                ->getSearchResultsUsing(fn (string $search): array => Package::query()
                    ->where('name', 'like', '%' . $search . '%')
                    ->orderBy('name')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Package $package): array => [$package->id => "{$package->name} ({$package->type->label()})"])
                    ->all())
                ->getOptionLabelsUsing(fn (array $values): array => Package::query()
                    ->whereIn('id', $values)
                    ->get()
                    ->mapWithKeys(fn (Package $package): array => [$package->id => "{$package->name} ({$package->type->label()})"])
                    ->all()),
            Select::make('severities')
                ->label(voightTrans('models.project.fields.current_severity'))
                ->options(Arr::except(Severity::options(), Severity::None->value))
                ->multiple(),
            $this->versionGrid(),
        ];
    }

    #[\Override]
    public function apply(Builder $query, string $qualifiedColumn): Builder
    {
        $packages = $this->selectedPackages();
        $severities = $this->selectedSeverities();

        $projectIds = $severities === []
            ? $packages->flatMap(fn (Package $package): array => app(PackageUsageService::class)->projectIdsWithPackage(
                $package->name,
                $package->type->value,
                $this->versionConstraint(),
            ))->unique()->all()
            : $this->projectIdsWithCurrentFindings($packages, $severities);

        return $query->whereIn($query->qualifyColumn('id'), $projectIds, not: $this->isInverse());
    }

    /**
     * @param  Collection<int, Package>  $packages
     * @param  array<int, Severity>  $severities
     * @return array<int, int>
     */
    private function projectIdsWithCurrentFindings(Collection $packages, array $severities): array
    {
        $constraint = $this->versionConstraint();

        return AuditFinding::constrainToCurrent(AuditFinding::query())
            ->whereIn('package_id', $packages->modelKeys())
            ->whereHas('vulnerability', fn (Builder $vulnerability) => Vulnerability::constrainToSeverities($vulnerability, ...$severities))
            ->with('auditRun.environment:id,project_id')
            ->get()
            ->filter(fn (AuditFinding $finding): bool => $constraint->matches($finding->installed_version))
            ->map(fn (AuditFinding $finding): int => $finding->auditRun->environment->project_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Package>
     */
    private function selectedPackages(): Collection
    {
        return Package::query()->findMany(Arr::wrap($this->getSettings()['package_id'] ?? []));
    }

    /**
     * @return array<int, Severity>
     */
    private function selectedSeverities(): array
    {
        return array_map(Severity::from(...), Arr::wrap($this->getSettings()['severities'] ?? []));
    }
}
