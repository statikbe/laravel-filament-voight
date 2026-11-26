<?php

namespace Statikbe\FilamentVoight\Resources\Components;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Filters\QueryBuilder\Constraints\Operators\Operator;
use Illuminate\Database\Eloquent\Builder;
use Statikbe\FilamentVoight\Support\VersionConstraint;
use Statikbe\FilamentVoight\Support\VersionKey;

/**
 * Restricts installation rows to a version range on their version key (see VersionKey). The inverse keeps
 * every row outside the range, including versions without a key such as `dev-main`. Meant for a query on
 * environment_packages.
 */
class InstalledVersionRangeOperator extends Operator
{
    private const MIN_OPERATORS = ['>' => '>', '>=' => '>='];

    private const MAX_OPERATORS = ['<' => '<', '<=' => '<='];

    /** Whether one version is required while the other is blank. */
    protected bool $versionRequired = true;

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
        return [$this->versionGrid()];
    }

    protected function versionGrid(): Grid
    {
        return Grid::make(2)->schema([
            Select::make('min_operator')
                ->label(voightTrans('models.package.installed_version.min_operator'))
                ->options(self::MIN_OPERATORS)
                ->default('>')
                ->selectablePlaceholder(false),
            $this->versionInput('min_version', 'max_version'),
            Select::make('max_operator')
                ->label(voightTrans('models.package.installed_version.max_operator'))
                ->options(self::MAX_OPERATORS)
                ->default('<')
                ->selectablePlaceholder(false),
            $this->versionInput('max_version', 'min_version'),
        ]);
    }

    #[\Override]
    public function apply(Builder $query, string $qualifiedColumn): Builder
    {
        $constraint = $this->versionConstraint();
        $key = $query->qualifyColumn('version_sort');

        if (! $this->isInverse()) {
            return $constraint->apply($query, $key);
        }

        return $query->where(fn (Builder $outside): Builder => $outside
            ->whereNot(fn (Builder $inside): Builder => $constraint->apply($inside, $key))
            ->orWhereNull($key));
    }

    private function versionInput(string $name, string $otherName): TextInput
    {
        return TextInput::make($name)
            ->label(voightTrans("models.package.installed_version.{$name}"))
            ->regex('/^v?\d\S*$/i')
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && VersionKey::for((string) $value) === null) {
                    $fail(voightTrans('models.package.installed_version.invalid'));
                }
            })
            ->required(fn (Get $get): bool => $this->versionRequired && blank($get($otherName)));
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

    protected function versionConstraint(): VersionConstraint
    {
        return VersionConstraint::between(
            $this->bound('min', self::MIN_OPERATORS, '>'),
            $this->bound('max', self::MAX_OPERATORS, '<'),
        );
    }
}
