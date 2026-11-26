<?php

use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Resources\Components\TagFields;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ViewPackage;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\EditProject;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Tests\Support\ConfigurableProjectPolicy;

beforeEach(function () {
    $this->actingAs(new User);

    $this->level = TagType::factory()->single()->appliesTo(['voight-project'])->create(['name' => 'Security level']);
    $this->low = Tag::factory()->ofType($this->level)->create(['name' => 'Low']);
    $this->high = Tag::factory()->ofType($this->level)->create(['name' => 'High']);

    $this->ecosystem = TagType::factory()->appliesTo(['voight-package'])->create(['name' => 'Ecosystem']);
    $this->core = Tag::factory()->ofType($this->ecosystem)->create(['name' => 'Core']);

    $this->labels = TagType::factory()->create(['name' => 'Labels']);
    $this->legacy = Tag::factory()->ofType($this->labels)->create(['name' => 'Legacy']);
});

it('shows only the applicable tag types on the project form', function () {
    $project = Project::factory()->create();

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertFormFieldExists(TagFields::fieldName($this->level), fn (Select $field): bool => ! $field->isMultiple())
        ->assertFormFieldExists(TagFields::fieldName($this->labels), fn (Select $field): bool => $field->isMultiple())
        ->assertFormFieldDoesNotExist(TagFields::fieldName($this->ecosystem));
});

it('fills and saves tags on the project form', function () {
    $project = Project::factory()->create();
    $project->attachTag($this->low);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertSchemaStateSet([TagFields::fieldName($this->level) => $this->low->id])
        ->fillForm([
            TagFields::fieldName($this->level) => $this->high->id,
            TagFields::fieldName($this->labels) => [$this->legacy->id],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($project->fresh()->tags->pluck('id')->all())->toEqualCanonicalizing([$this->high->id, $this->legacy->id]);
});

it('edits tags from the package view page', function () {
    $package = Package::factory()->create();

    Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
        ->callAction('editTags', [TagFields::fieldName($this->ecosystem) => [$this->core->id]])
        ->assertHasNoFormErrors();

    expect($package->fresh()->tags->pluck('id')->all())->toBe([$this->core->id]);
});

it('applies tags to the selected projects in bulk', function () {
    $selected = Project::factory()->count(2)->create();
    $other = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->selectTableRecords($selected->modelKeys())
        ->callAction(TestAction::make('applyTags')->table()->bulk(), ['tags' => [$this->legacy->id]])
        ->assertHasNoFormErrors();

    foreach ($selected as $project) {
        expect($project->fresh()->tags->pluck('id')->all())->toBe([$this->legacy->id]);
    }

    expect($other->fresh()->tags)->toBeEmpty();
});

it('replaces the existing tag of a single-value type in bulk', function () {
    $project = Project::factory()->create();
    $project->attachTag($this->low);

    Livewire::test(ListProjects::class)
        ->selectTableRecords([$project->getKey()])
        ->callAction(TestAction::make('applyTags')->table()->bulk(), ['tags' => [$this->high->id]]);

    expect($project->fresh()->tags->pluck('id')->all())->toBe([$this->high->id]);
});

it('refuses two tags of a single-value type in bulk and writes nothing', function () {
    $project = Project::factory()->create();

    Livewire::test(ListProjects::class)
        ->selectTableRecords([$project->getKey()])
        ->callAction(TestAction::make('applyTags')->table()->bulk(), ['tags' => [$this->low->id, $this->high->id]])
        ->assertNotified();

    expect($project->fresh()->tags)->toBeEmpty();
});

it('offers only applicable tags in the bulk action', function () {
    Livewire::test(ListProjects::class)
        ->mountAction(TestAction::make('applyTags')->table()->bulk())
        ->assertFormFieldExists('tags', function (Select $field): bool {
            $ids = collect($field->getOptions())->flatMap(fn (array $group): array => array_keys($group))->all();

            return in_array($this->high->id, $ids, true) && ! in_array($this->core->id, $ids, true);
        });
});

it('removes tags in bulk', function () {
    $project = Project::factory()->create();
    $project->attachTags([$this->legacy, $this->high]);

    Livewire::test(ListProjects::class)
        ->selectTableRecords([$project->getKey()])
        ->callAction(TestAction::make('removeTags')->table()->bulk(), ['tags' => [$this->legacy->id]]);

    expect($project->fresh()->tags->pluck('id')->all())->toBe([$this->high->id]);
});

it('shows tags as type and name in the projects table', function () {
    $project = Project::factory()->create();
    $project->attachTag($this->high);

    Livewire::test(ListProjects::class)
        ->assertTableColumnStateSet('tags.name', ['Security level: High'], $project);
});

it('skips records whose policy denies update when applying tags in bulk', function () {
    Gate::policy(Project::class, ConfigurableProjectPolicy::class);
    ConfigurableProjectPolicy::$deniedProjects = ['LOCKED'];

    $locked = Project::factory()->create(['project_code' => 'LOCKED']);
    $open = Project::factory()->create(['project_code' => 'OPEN']);

    Livewire::test(ListProjects::class)
        ->selectTableRecords([$locked->getKey(), $open->getKey()])
        ->callAction(TestAction::make('applyTags')->table()->bulk(), ['tags' => [$this->legacy->id]]);

    expect($locked->fresh()->tags)->toBeEmpty()
        ->and($open->fresh()->tags->pluck('id')->all())->toBe([$this->legacy->id]);

    ConfigurableProjectPolicy::$deniedProjects = [];
});

it('shows tags on the package view page', function () {
    $package = Package::factory()->create();
    $package->attachTag($this->core);

    Livewire::test(ViewPackage::class, ['record' => $package->getRouteKey()])
        ->assertSee('Ecosystem: Core');
});
