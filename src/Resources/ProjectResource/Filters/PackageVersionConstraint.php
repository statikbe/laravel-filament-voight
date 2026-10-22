<?php

namespace Statikbe\FilamentVoight\Resources\ProjectResource\Filters;

use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Statikbe\FilamentVoight\Resources\ProjectResource;
use Statikbe\FilamentVoight\Resources\ProjectResource\Filters\PackageVersionConstraint\Operators\SatisfiesRangeOperator;

/**
 * Query builder constraint for projects: "package X is installed at a version
 * inside range Y" in any of the project's environments.
 */
class PackageVersionConstraint extends Constraint
{
    public static function getDefaultName(): ?string
    {
        return 'package_version';
    }

    /**
     * Projects list URL with this constraint applied, e.g. to jump from an advisory to the projects it hits.
     */
    public static function projectsUrl(string $packageId, string $range): string
    {
        return ProjectResource::getUrl('index', [
            'filters' => [
                'queryBuilder' => [
                    'rules' => [
                        static::getDefaultName() => [
                            'type' => static::getDefaultName(),
                            'data' => [
                                'operator' => 'satisfies',
                                'settings' => [
                                    'package_id' => $packageId,
                                    'range' => $range,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(voightTrans('filters.package_version.label'));

        $this->icon(Heroicon::OutlinedShieldExclamation);

        $this->operators([
            SatisfiesRangeOperator::class,
        ]);
    }
}
