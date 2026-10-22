<?php

namespace Statikbe\FilamentVoight\Resources\PackageResource\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Services\DependencyGraphService;
use Statikbe\FilamentVoight\Support\DependencyPathResult;

/**
 * Row action for installed packages and audit findings: lists the paths from the
 * environment's direct dependencies down to the installed package.
 *
 * A finding is matched to the installed copies of its version in the latest sync,
 * which may no longer include it.
 */
class WhyInstalledAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'why_installed';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(voightTrans('models.package.why_installed.label'));
        $this->icon(Heroicon::OutlinedQuestionMarkCircle);
        $this->modalHeading(voightTrans('models.package.why_installed.label'));
        $this->modalSubmitAction(false);
        $this->modalCancelActionLabel(voightTrans('models.package.why_installed.close'));
        $this->modalContent(fn (EnvironmentPackage | AuditFinding $record): View => view(
            'filament-voight::why-installed.paths',
            ['explanations' => self::explanations($record)],
        ));
    }

    /**
     * @return array<int, array{node: EnvironmentPackage, result: DependencyPathResult}>
     */
    private static function explanations(EnvironmentPackage | AuditFinding $record): array
    {
        $graphService = app(DependencyGraphService::class);

        $nodes = $record instanceof AuditFinding
            ? $graphService->nodesFor($record->auditRun->environment, $record->package, $record->installed_version)
            : collect([$record]);

        return $nodes
            ->map(fn (EnvironmentPackage $node): array => ['node' => $node, 'result' => $graphService->pathsToRoots($node)])
            ->all();
    }
}
