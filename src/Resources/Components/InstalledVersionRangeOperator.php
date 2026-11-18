<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Filters\QueryBuilder\Constraints\Operators\Operator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Statikbe\FilamentVoight\Support\VersionConstraint;

/**
 * Versions cannot be ordered in SQL, so the versions of the already scoped installation rows are
 * compared in PHP and the query is restricted to the matching rows. Meant for a query on environment_packages.
 */
class InstalledVersionRangeOperator extends Operator
{
    private const MIN_OPERATORS = ['>' => '>', '>=' => '>='];

    private const MAX_OPERATORS = ['<' => '<', '<=' => '<='];

    #[\Override]
    public function getLabel(): string
    {
        return voightTrans($this->isInverse() ? 'models.package.installed_version.not_in_range' : 'models.package.installed_version.in_range');
    }

    #[\Override]
    public function getSummary(): string
    {
        $settings = $this->getSettings();

        return collect([
            filled($settings['min_version'] ?? null) ? $settings['min_operator'] . ' ' . $settings['min_version'] : null,
            filled($settings['max_version'] ?? null) ? $settings['max_operator'] . ' ' . $settings['max_version'] : null,
        ])->filter()->implode(' & ');
    }

    /**
     * @return array<int, Grid>
     */
    #[\Override]
    public function getFormSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('min_operator')
                    ->label(voightTrans('models.package.installed_version.min_operator'))
                    ->options(self::MIN_OPERATORS)
                    ->default('>')
                    ->selectablePlaceholder(false),
                self::versionInput('min_version', 'max_version'),
                Select::make('max_operator')
                    ->label(voightTrans('models.package.installed_version.max_operator'))
                    ->options(self::MAX_OPERATORS)
                    ->default('<')
                    ->selectablePlaceholder(false),
                self::versionInput('max_version', 'min_version'),
            ]),
        ];
    }

    #[\Override]
    public function apply(Builder $query, string $qualifiedColumn): Builder
    {
        return $query->whereIn($query->qualifyColumn('id'), $this->matchedInstallationIds($query), not: $this->isInverse());
    }

    private static function versionInput(string $name, string $otherName): TextInput
    {
        return TextInput::make($name)
            ->label(voightTrans("models.package.installed_version.{$name}"))
            ->regex('/^v?\d\S*$/i')
            ->required(fn (Get $get): bool => blank($get($otherName)));
    }

    /**
     * @param  array<string, string>  $allowedOperators
     * @return array{0: string, 1: string}|null
     */
    private function bound(string $side, array $allowedOperators, string $defaultOperator): ?array
    {
        $settings = $this->getSettings();

        if (blank($settings["{$side}_version"] ?? null)) {
            return null;
        }

        return [
            $allowedOperators[$settings["{$side}_operator"] ?? null] ?? $defaultOperator,
            $settings["{$side}_version"],
        ];
    }

    /**
     * @return array<int, int|string>
     */
    private function matchedInstallationIds(Builder $query): array
    {
        $constraint = VersionConstraint::between(
            $this->bound('min', self::MIN_OPERATORS, '>'),
            $this->bound('max', self::MAX_OPERATORS, '<'),
        );

        return (clone $query)
            ->reorder()
            ->get([$query->qualifyColumn('id'), $query->qualifyColumn('version')])
            ->filter(fn (Model $installed): bool => $constraint->matches((string) $installed->getAttribute('version')))
            ->pluck('id')
            ->all();
    }
}
