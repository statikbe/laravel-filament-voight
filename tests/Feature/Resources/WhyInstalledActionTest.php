<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\DependencyKind;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ViewAuditRun;
use Statikbe\FilamentVoight\Resources\AuditRunResource\RelationManagers\FindingsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\ActiveFindingsRelationManager;
use Statikbe\FilamentVoight\Resources\PackageResource\RelationManagers\InstallationsRelationManager;

beforeEach(function () {
    $this->actingAs(new User);
    $this->environment = Environment::factory()->create();
});

function installedNode(Environment $environment, string $name, string $version, bool $direct = false): EnvironmentPackage
{
    return EnvironmentPackage::factory()
        ->for($environment)
        ->for(Package::query()->firstOrCreate(['name' => $name, 'type' => 'npm']))
        ->create(['version' => $version, 'is_direct' => $direct]);
}

/**
 * express (direct) → send → ms 2.0.0, and react-dom (direct) peer-requires ms.
 */
function nestedMs(Environment $environment): EnvironmentPackage
{
    $express = installedNode($environment, 'express', '4.18.2', direct: true);
    $send = installedNode($environment, 'send', '0.18.0');
    $ms = installedNode($environment, 'ms', '2.0.0');
    $express->children()->attach($send, ['constraint' => '0.18.0', 'kind' => DependencyKind::Dependency]);
    $send->children()->attach($ms, ['constraint' => '~2.0.0', 'kind' => DependencyKind::Dependency]);
    installedNode($environment, 'react-dom', '18.2.0', direct: true)
        ->children()->attach($ms, ['constraint' => '^2.0.0', 'kind' => DependencyKind::Peer]);

    return $ms;
}

it('explains an installation from the package page', function () {
    $ms = nestedMs($this->environment);

    Livewire::test(InstallationsRelationManager::class, ['ownerRecord' => $ms->package, 'pageClass' => ViewPackage::class])
        ->mountAction(TestAction::make('why_installed')->table($ms))
        ->assertMountedActionModalSee('express@4.18.2 → send@0.18.0 (0.18.0) → ms@2.0.0 (~2.0.0)')
        ->assertMountedActionModalSee('react-dom@18.2.0 → ms@2.0.0 (^2.0.0, ' . DependencyKind::Peer->getLabel() . ')');
});

it('notes when more paths exist than are shown', function () {
    $ms = installedNode($this->environment, 'ms', '2.0.0');
    foreach (range(1, 11) as $number) {
        installedNode($this->environment, "root-{$number}", '1.0.0', direct: true)
            ->children()->attach($ms, ['constraint' => '^2.0.0', 'kind' => DependencyKind::Dependency]);
    }

    Livewire::test(InstallationsRelationManager::class, ['ownerRecord' => $ms->package, 'pageClass' => ViewPackage::class])
        ->mountAction(TestAction::make('why_installed')->table($ms))
        ->assertMountedActionModalSee(voightTrans('models.package.why_installed.truncated'));
});

it('explains the installed copies behind an audit run finding', function () {
    $ms = nestedMs($this->environment);
    $run = AuditRun::factory()->for($this->environment)->create();
    $finding = AuditFinding::factory()->for($run, 'auditRun')->for($ms->package)->create(['installed_version' => '2.0.0']);

    Livewire::test(FindingsRelationManager::class, ['ownerRecord' => $run, 'pageClass' => ViewAuditRun::class])
        ->mountAction(TestAction::make('why_installed')->table($finding))
        ->assertMountedActionModalSee('express@4.18.2 → send@0.18.0 (0.18.0) → ms@2.0.0 (~2.0.0)');
});

it('explains a finding from the package page', function () {
    $ms = nestedMs($this->environment);
    $finding = AuditFinding::factory()
        ->for(AuditRun::factory()->for($this->environment), 'auditRun')
        ->for($ms->package)
        ->create(['installed_version' => '2.0.0']);

    Livewire::test(ActiveFindingsRelationManager::class, ['ownerRecord' => $ms->package, 'pageClass' => ViewPackage::class])
        ->mountAction(TestAction::make('why_installed')->table($finding))
        ->assertMountedActionModalSee('react-dom@18.2.0 → ms@2.0.0');
});

it('says so when the finding version is no longer installed', function () {
    $ms = nestedMs($this->environment);
    $run = AuditRun::factory()->for($this->environment)->create();
    $finding = AuditFinding::factory()->for($run, 'auditRun')->for($ms->package)->create(['installed_version' => '1.0.0']);

    Livewire::test(FindingsRelationManager::class, ['ownerRecord' => $run, 'pageClass' => ViewAuditRun::class])
        ->mountAction(TestAction::make('why_installed')->table($finding))
        ->assertMountedActionModalSee(voightTrans('models.package.why_installed.no_longer_installed'));
});
