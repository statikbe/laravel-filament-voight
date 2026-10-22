<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Filters;

use Filament\QueryBuilder\Forms\Components\RuleBuilder;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\ProjectResource\Filters\PackageVersionConstraint\Operators\VersionComparisonOperator;
use Statikbe\FilamentVoight\Support\VersionRange;

/**
 * Query builder constraint for projects: "package X is below / above / ... version Y"
 * in any of the project's environments. Combine lines to express a range.
 */
class PackageVersionConstraint extends Constraint
{
    public static function getDefaultName(): ?string
    {
        return 'package_version';
    }

    /**
     * Projects list URL with the range written out as query builder lines, e.g. to jump from an
     * advisory to the projects it hits. Alternatives in the range become an OR group.
     */
    public static function projectsUrl(string $packageId, string $range): ?string
    {
        $alternatives = VersionRange::toComparisons($range);

        if ($alternatives === null) {
            return null;
        }

        $groups = array_map(fn (array $comparisons): array => self::rulesFor($packageId, $comparisons), $alternatives);

        $rules = count($groups) === 1
            ? $groups[0]
            : ['or' => [
                'type' => RuleBuilder::OR_BLOCK_NAME,
                'data' => [
                    RuleBuilder::OR_BLOCK_GROUPS_REPEATER_NAME => collect($groups)
                        ->mapWithKeys(fn (array $rules, int $index): array => ["group{$index}" => ['rules' => $rules]])
                        ->all(),
                ],
            ]];

        return ProjectResource::getUrl('index', ['filters' => ['queryBuilder' => ['rules' => $rules]]]);
    }

    /**
     * Only offer the direct operators: their inverses ("is not below") would duplicate other lines ("is at least").
     *
     * @return array<string, string>
     */
    public function getOperatorSelectOptions(): array
    {
        $options = [];

        foreach ($this->getOperators() as $name => $operator) {
            $options[$name] = $operator->getLabel();
        }

        return $options;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(voightTrans('filters.package_version.label'));

        $this->icon(Heroicon::OutlinedShieldExclamation);

        $this->operators(VersionComparisonOperator::all());
    }

    /**
     * @param  list<array{0: string, 1: string}>  $comparisons
     * @return array<string, array<string, mixed>>
     */
    private static function rulesFor(string $packageId, array $comparisons): array
    {
        return collect($comparisons)
            ->mapWithKeys(fn (array $comparison, int $index): array => ["rule{$index}" => [
                'type' => static::getDefaultName(),
                'data' => [
                    'operator' => VersionComparisonOperator::nameFor($comparison[0]),
                    'settings' => [
                        'package_id' => $packageId,
                        'version' => $comparison[1],
                    ],
                ],
            ]])
            ->all();
    }
}
