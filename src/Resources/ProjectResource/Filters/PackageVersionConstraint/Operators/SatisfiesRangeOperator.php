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

class SatisfiesRangeOperator extends Operator
{
    public function getName(): string
    {
        return 'satisfies';
    }

    public function getLabel(): string
    {
        return voightTrans($this->isInverse()
            ? 'filters.package_version.operators.satisfies.inverse'
            : 'filters.package_version.operators.satisfies.direct');
    }

    public function getSummary(): string
    {
        return voightTrans($this->isInverse()
            ? 'filters.package_version.summary.inverse'
            : 'filters.package_version.summary.direct', [
                'package' => self::packageLabel(Package::find($this->getStringSetting('package_id'))),
                'range' => $this->getStringSetting('range'),
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
            TextInput::make('range')
                ->label(voightTrans('filters.package_version.form.range.label'))
                ->placeholder('>=10.0.0,<10.48.29')
                ->helperText(voightTrans('filters.package_version.form.range.help'))
                ->required()
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! VersionRange::isValid($value)) {
                        $fail(voightTrans('filters.package_version.form.range.invalid'));
                    }
                }),
        ];
    }

    public function applyToBaseQuery(Builder $query): Builder
    {
        $packageId = $this->getStringSetting('package_id');
        $range = $this->getStringSetting('range');

        if (blank($packageId) || blank($range)) {
            return $query;
        }

        // Version strings can't be range-compared in SQL ("1.10" < "1.9"), so match
        // the package's distinct installed versions in PHP and filter on those.
        $matchingVersions = VersionRange::filterSatisfying(
            EnvironmentPackage::query()
                ->where('package_id', $packageId)
                ->distinct()
                ->pluck('version'),
            $range,
        );

        return $query->{$this->isInverse() ? 'whereDoesntHave' : 'whereHas'}(
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
