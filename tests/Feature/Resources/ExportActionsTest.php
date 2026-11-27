<?php

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\AuditRunStatus;
use Statikbe\FilamentVoight\Exports\AuditRunExporter;
use Statikbe\FilamentVoight\Exports\ProjectExporter;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Resources\AuditRunResource\Pages\ListAuditRuns;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\ListCustomers;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ListPackages;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\TeamResource\Pages\ListTeams;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\Pages\ListVulnerabilities;

beforeEach(function () {
    $this->actingAs(new User);
});

it('offers an export action on every main list table', function (string $page) {
    Livewire::test($page)
        ->assertActionExists(TestAction::make(ExportAction::class)->table())
        ->assertActionVisible(TestAction::make(ExportAction::class)->table())
        ->assertActionExists(TestAction::make(ExportBulkAction::class)->table()->bulk());
})->with([
    'projects' => ListProjects::class,
    'environments' => ListEnvironments::class,
    'customers' => ListCustomers::class,
    'packages' => ListPackages::class,
    'vulnerabilities' => ListVulnerabilities::class,
    'audit runs' => ListAuditRuns::class,
    'teams' => ListTeams::class,
]);

it('keeps the project stack data in the serialized export query', function () {
    Project::factory()->create();

    $query = Livewire::test(ListProjects::class)->instance()->getTableQueryForExport();
    $query = EloquentSerializeFacade::unserialize(EloquentSerializeFacade::serialize($query));

    expect($query->get())->toHaveCount(1)
        ->and($query->first()->relationLoaded('representativeEnvironment'))->toBeTrue();
});

it('exports readable values for rows built from the table query', function () {
    $run = AuditRun::factory()->create(['status' => AuditRunStatus::Completed]);

    $query = Livewire::test(ListAuditRuns::class)->instance()->getTableQueryForExport();
    $columns = collect(AuditRunExporter::getColumns())->mapWithKeys(fn ($column): array => [$column->getName() => $column->getLabel()])->all();

    $row = (new AuditRunExporter(new Export, $columns, []))($query->first());

    expect($row)->toContain($run->environment->project->name, AuditRunStatus::Completed->label());
});

it('exports the project stack columns', function () {
    Project::factory()->create();

    $query = Livewire::test(ListProjects::class)->instance()->getTableQueryForExport();
    $columns = collect(ProjectExporter::getColumns())->mapWithKeys(fn ($column): array => [$column->getName() => $column->getLabel()])->all();

    expect((new ProjectExporter(new Export, $columns, []))($query->first()))->toHaveCount(count($columns));
});
