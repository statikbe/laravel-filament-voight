<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Filters\PackageVersionConstraint\Operators;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\QueryBuilder\Constraints\Operators\Operator;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Support\VersionRange;

/**
 * One query builder line comparing a package's installed version, e.g. "laravel/framework is below 10.48.29".
 * A project matches when any of its environments has the package at a version that passes the comparison.
 */
class VersionComparisonOperator extends Operator
{
    /**
     * Operator names mapped to the Composer comparison they apply.
     */
    public const COMPARISONS = [
        'is' => '=',
        'isNot' => '!=',
        'isBelow' => '<',
        'isAtMost' => '<=',
        'isAbove' => '>',
        'isAtLeast' => '>=',
    ];

    /**
     * @return array<static>
     */
    public static function all(): array
    {
        return array_map(static::make(...), array_keys(self::COMPARISONS));
    }

    public static function nameFor(string $comparison): ?string
    {
        return array_search($comparison, self::COMPARISONS, true) ?: null;
    }

    public function getLabel(): string
    {
        return voightTrans("filters.package_version.operators.{$this->getName()}");
    }

    public function getSummary(): string
    {
        return voightTrans('filters.package_version.summary', [
            'package' => self::packageLabel(Package::find($this->getStringSetting('package_id'))),
            'operator' => mb_strtolower($this->getLabel()),
            'version' => $this->getStringSetting('version'),
        ]);
    }

    public function getFormSchema(): array
    {
        return [
            Select::make('package_id')
                ->label(voightTrans('models.package.label'))
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Package::query()
                    ->where('name', 'like', "%{$search}%")
                    ->orderBy('name')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn (Package $package): array => [$package->getKey() => self::packageLabel($package)])
                    ->all())
                ->getOptionLabelUsing(fn (?string $value): string => self::packageLabel(Package::find($value)))
                ->required(),
            TextInput::make('version')
                ->label(voightTrans('filters.package_version.form.version.label'))
                ->placeholder('10.48.29')
                ->required()
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! VersionRange::isValidVersion($value)) {
                        $fail(voightTrans('filters.package_version.form.version.invalid'));
                    }
                }),
        ];
    }

    public function applyToBaseQuery(Builder $query): Builder
    {
        $packageId = $this->getStringSetting('package_id');
        $version = $this->getStringSetting('version');
        $comparison = self::COMPARISONS[$this->getName()] ?? null;

        if (blank($packageId) || blank($version) || $comparison === null) {
            return $query;
        }

        // Version strings can't be compared in SQL ("1.10" < "1.9"), so compare
        // the package's distinct installed versions in PHP and filter on those.
        $matchingVersions = VersionRange::filterComparing(
            EnvironmentPackage::query()
                ->where('package_id', $packageId)
                ->distinct()
                ->pluck('version'),
            $comparison,
            $version,
        );

        return $query->whereHas(
            'environments.environmentPackages',
            fn (Builder $query): Builder => $query
                ->where('package_id', $packageId)
                ->whereIn('version', $matchingVersions),
        );
    }

    private static function packageLabel(?Package $package): string
    {
        return $package ? "{$package->name} ({$package->type->label()})" : '';
    }
}
