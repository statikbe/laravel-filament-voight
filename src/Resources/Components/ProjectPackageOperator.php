<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Statikbe\FilamentVoight\Enums\Severity;
use Statikbe\FilamentVoight\Facades\FilamentVoight;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Vulnerability;

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
            ? $this->projectsWithPackages($packages)
            : $this->projectsWithCurrentFindings($packages, $severities);

        return $query->whereIn($query->qualifyColumn('id'), $projectIds, not: $this->isInverse());
    }

    /**
     * Project ids with any of the packages installed in the version range.
     *
     * @param  Collection<int, Package>  $packages
     * @return Builder<Environment>
     */
    private function projectsWithPackages(Collection $packages): Builder
    {
        $config = FilamentVoight::config();
        $installed = $config->getEnvironmentPackageModel()::query()->whereIn('package_id', $packages->modelKeys());
        $this->versionConstraint()->apply($installed, $installed->qualifyColumn('version_sort'));

        return $config->getEnvironmentModel()::query()
            ->select('project_id')
            ->whereIn('id', $installed->select('environment_id'));
    }

    /**
     * Project ids with a current finding of one of the severities on a selected package, installed in the version range.
     *
     * @param  Collection<int, Package>  $packages
     * @param  array<int, Severity>  $severities
     * @return Builder<Environment>
     */
    private function projectsWithCurrentFindings(Collection $packages, array $severities): Builder
    {
        $findings = AuditFinding::constrainToCurrent(AuditFinding::query())
            ->whereIn('package_id', $packages->modelKeys())
            ->whereHas('vulnerability', fn (Builder $vulnerability) => Vulnerability::constrainToSeverities($vulnerability, ...$severities));
        $this->versionConstraint()->apply($findings, $findings->qualifyColumn('installed_version_sort'));

        $config = FilamentVoight::config();

        return $config->getEnvironmentModel()::query()
            ->select('project_id')
            ->whereIn('id', $config->getAuditRunModel()::query()->select('environment_id')->whereIn('id', $findings->select('audit_run_id')));
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
