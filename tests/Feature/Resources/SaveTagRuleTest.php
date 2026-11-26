<?php

use Filament\Forms\Components\Select;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagRule;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\Pages\ListVulnerabilities;

beforeEach(function () {
    $this->actingAs(new User);

    $this->triageType = TagType::factory()->appliesTo(['voight-vulnerability'])->create(['name' => 'Triage']);
    $this->needsTriage = Tag::factory()->ofType($this->triageType)->create(['name' => 'Needs triage']);

    $this->sourceType = TagType::factory()->appliesTo(['voight-vulnerability'])->create(['name' => 'Origin']);
    $this->external = Tag::factory()->ofType($this->sourceType)->create(['name' => 'External']);

    $this->projectType = TagType::factory()->appliesTo(['voight-project'])->create(['name' => 'Level']);
    $this->high = Tag::factory()->ofType($this->projectType)->create(['name' => 'High']);
});

function filteredVulnerabilities(array $tagIds): Testable
{
    return Livewire::test(ListVulnerabilities::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('tag_origin', 'is', ['values' => $tagIds]))
        ->call('applyTableFilters');
}

it('stores a rule whose query matches exactly what the table shows, and tags those records', function () {
    $external = Vulnerability::factory()->count(2)->create();
    $external->each(fn (Vulnerability $vulnerability) => $vulnerability->attachTag($this->external));
    $other = Vulnerability::factory()->create();

    filteredVulnerabilities([$this->external->id])
        ->assertCanSeeTableRecords($external)
        ->assertCanNotSeeTableRecords([$other])
        ->callAction('saveTagRule', ['tag' => $this->needsTriage->id])
        ->assertHasNoFormErrors();

    $rule = TagRule::query()->sole();
    $ids = collect(DB::select($rule->query_sql, $rule->query_bindings))->map(fn (object $row): string => (string) array_values((array) $row)[0]);

    expect($ids->sort()->values()->all())->toBe($external->modelKeys() === [] ? [] : collect($external->modelKeys())->map(fn ($id) => (string) $id)->sort()->values()->all())
        ->and($rule->taggable_type)->toBe('voight-vulnerability')
        ->and($rule->source_page)->toBe(ListVulnerabilities::class)
        ->and($rule->tab)->toBe('all')
        ->and($rule->description)->not->toBeEmpty()
        ->and($rule->table_state['filters'])->not->toBeEmpty()
        ->and($rule->created_by)->toBeNull();

    foreach ($external as $vulnerability) {
        expect($vulnerability->fresh()->tags->pluck('id'))->toContain($this->needsTriage->id);
    }

    expect($other->fresh()->tags)->toBeEmpty();
});

it('hides the action on an unfiltered table', function () {
    Livewire::test(ListProjects::class)
        ->assertActionHidden('saveTagRule');
});

it('offers only tags that apply to the table\'s model', function () {
    filteredVulnerabilities([$this->external->id])
        ->mountAction('saveTagRule')
        ->assertFormFieldExists('tag', function (Select $field): bool {
            $ids = collect($field->getOptions())->flatMap(fn (array $group): array => array_keys($group))->all();

            return in_array($this->needsTriage->id, $ids, true) && ! in_array($this->high->id, $ids, true);
        });
});

it('replaces the rule it was opened from', function () {
    Vulnerability::factory()->create()->attachTag($this->external);
    $existing = TagRule::factory()->for($this->needsTriage)->create([
        'taggable_type' => 'voight-vulnerability',
        'source_page' => ListVulnerabilities::class,
        'status' => 'failed',
    ]);

    Livewire::withQueryParams(['tag_rule' => $existing->id])
        ->test(ListVulnerabilities::class)
        ->set('activeTab', 'all')
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('tag_origin', 'is', ['values' => [$this->external->id]]))
        ->call('applyTableFilters')
        ->callAction('saveTagRule', ['tag' => $this->needsTriage->id, 'replace' => true])
        ->assertHasNoFormErrors();

    expect(TagRule::query()->count())->toBe(1)
        ->and($existing->fresh()->status->value)->toBe('active')
        ->and($existing->fresh()->tab)->toBe('all');
});

it('stores who saved the rule', function () {
    $user = mcpUser();
    $this->actingAs($user);
    Project::factory()->create();
    Vulnerability::factory()->create()->attachTag($this->external);

    filteredVulnerabilities([$this->external->id])
        ->callAction('saveTagRule', ['tag' => $this->needsTriage->id]);

    expect(TagRule::query()->sole()->created_by)->toBe($user->id);
});

it('stores working SQL on tables with joins and aggregates', function (string $page, Closure $make, string $search) {
    $type = TagType::factory()->create(['name' => 'Anywhere']);
    $tag = Tag::factory()->ofType($type)->create();
    $match = $make('zeta-match');
    $make('alpha-other');

    Livewire::test($page)
        ->set('tableSearch', $search)
        ->callAction('saveTagRule', ['tag' => $tag->id])
        ->assertHasNoFormErrors();

    $rule = TagRule::query()->sole();
    $ids = collect(DB::select($rule->query_sql, $rule->query_bindings))->map(fn (object $row): string => (string) array_values((array) $row)[0])->all();

    expect($ids)->toBe([(string) $match->getKey()]);
})->with([
    'projects' => [ListProjects::class, fn (string $name) => Project::factory()->create(['name' => $name, 'project_code' => $name]), 'zeta'],
    'environments' => [ListEnvironments::class, fn (string $name) => Environment::factory()->create(['name' => $name]), 'zeta'],
]);
