<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Statikbe\FilamentVoight\Mcp\Tools\AssignTagsTool;
use Statikbe\FilamentVoight\Mcp\VoightLocalServer;
use Statikbe\FilamentVoight\Mcp\VoightServer;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Models\Team;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Tests\Support\ConfigurableProjectPolicy;

beforeEach(function () {
    $this->level = TagType::factory()->single()->appliesTo(['voight-project'])->create(['name' => 'Security level']);
    $this->low = Tag::factory()->ofType($this->level)->create(['name' => 'Low']);
    $this->high = Tag::factory()->ofType($this->level)->create(['name' => 'High']);

    $this->labels = TagType::factory()->create(['name' => 'Labels']);
    $this->legacy = Tag::factory()->ofType($this->labels)->create(['name' => 'Legacy']);

    $this->myTeam = Team::factory()->create();
    $this->mine = Project::factory()->for($this->myTeam)->create(['project_code' => 'MINE']);
});

function tagNamesOf(Model $record): array
{
    return $record->fresh()->tags->pluck('name')->sort()->values()->all();
}

it('adds tags to projects by code', function () {
    Project::factory()->create(['project_code' => 'OTHER']);

    $result = structuredContent(VoightLocalServer::tool(AssignTagsTool::class, [
        'model' => 'project',
        'rows' => [
            ['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'security-level', 'tag' => 'high'], ['type' => 'labels', 'tag' => 'Legacy']]],
            ['record' => ['project_code' => 'OTHER'], 'tags' => [['type' => 'security-level', 'tag' => 'Low']]],
        ],
    ]));

    expect(tagNamesOf($this->mine))->toBe(['High', 'Legacy'])
        ->and(tagNamesOf(Project::query()->where('project_code', 'OTHER')->sole()))->toBe(['Low'])
        ->and($result['rows'][0])->toMatchArray(['row' => 0, 'added' => 2, 'replaced' => 0, 'removed' => 0, 'unchanged' => 0]);
});

it('replaces the tag of a single-value type and reports it', function () {
    $this->mine->attachTag($this->low);

    $result = structuredContent(VoightLocalServer::tool(AssignTagsTool::class, [
        'model' => 'project',
        'rows' => [['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'security-level', 'tag' => 'High']]]],
    ]));

    expect(tagNamesOf($this->mine))->toBe(['High'])
        ->and($result['rows'][0])->toMatchArray(['added' => 0, 'replaced' => 1, 'removed' => 0, 'unchanged' => 0]);
});

it('reports tags a record already has as unchanged', function () {
    $this->mine->attachTag($this->legacy);

    $result = structuredContent(VoightLocalServer::tool(AssignTagsTool::class, [
        'model' => 'project',
        'rows' => [['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'labels', 'tag' => 'Legacy']]]],
    ]));

    expect($result['rows'][0])->toMatchArray(['added' => 0, 'unchanged' => 1]);
});

it('removes tags', function () {
    $this->mine->attachTags([$this->legacy, $this->high]);

    $result = structuredContent(VoightLocalServer::tool(AssignTagsTool::class, [
        'model' => 'project',
        'action' => 'remove',
        'rows' => [['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'labels', 'tag' => 'Legacy']]]],
    ]));

    expect(tagNamesOf($this->mine))->toBe(['High'])
        ->and($result['rows'][0])->toMatchArray(['removed' => 1]);
});

it('tags environments, customers, packages and vulnerabilities by their natural keys', function () {
    $environment = Environment::factory()->for($this->mine)->create(['name' => 'production']);
    $customer = Customer::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    $package = Package::factory()->create(['name' => 'lodash', 'type' => 'npm']);
    $vulnerability = Vulnerability::factory()->create(['source_id' => 'GHSA-xyz']);

    $assign = fn (string $model, array $record) => VoightLocalServer::tool(AssignTagsTool::class, [
        'model' => $model,
        'rows' => [['record' => $record, 'tags' => [['type' => 'labels', 'tag' => 'Legacy']]]],
    ])->assertHasNoErrors();

    $assign('environment', ['project_code' => 'MINE', 'environment' => 'production']);
    $assign('customer', ['name' => 'Acme']);
    $assign('package', ['type' => 'npm', 'name' => 'lodash']);
    $assign('vulnerability', ['source_id' => 'GHSA-xyz']);

    foreach ([$environment, $customer, $package, $vulnerability] as $record) {
        expect(tagNamesOf($record))->toBe(['Legacy']);
    }
});

it('lists every problem and writes nothing when any row is invalid', function () {
    $response = VoightLocalServer::tool(AssignTagsTool::class, [
        'model' => 'project',
        'rows' => [
            ['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'labels', 'tag' => 'Legacy']]],
            ['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'nope', 'tag' => 'x']]],
            ['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'security-level', 'tag' => 'Extreme']]],
            ['record' => ['project_code' => 'GHOST'], 'tags' => [['type' => 'labels', 'tag' => 'Legacy']]],
            ['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'security-level', 'tag' => 'Low'], ['type' => 'security-level', 'tag' => 'High']]],
        ],
    ]);

    $response->assertHasErrors([
        'Row 1: Unknown tag type "nope".',
        'Row 2: Unknown tag "Extreme" for tag type "security-level".',
        'Row 3: project "GHOST" not found.',
        'Row 4: Tag type "Security level" holds a single value: pick one tag.',
        'Ask an admin',
    ]);

    expect(tagNamesOf($this->mine))->toBe([]);
});

it('refuses a tag type that does not apply to the model', function () {
    Package::factory()->create(['name' => 'lodash', 'type' => 'npm']);

    VoightLocalServer::tool(AssignTagsTool::class, [
        'model' => 'package',
        'rows' => [['record' => ['type' => 'npm', 'name' => 'lodash'], 'tags' => [['type' => 'security-level', 'tag' => 'High']]]],
    ])->assertHasErrors(['Row 0: Tag type "Security level" cannot be assigned to Packages.']);
});

it('treats a package outside the caller\'s projects as not found', function () {
    $otherTeamProject = Project::factory()->for(Team::factory())->create();
    mcpFinding(Environment::factory()->for($otherTeamProject)->create(), Vulnerability::factory()->create(), package: 'secret-pkg');

    VoightServer::actingAs(mcpUser($this->myTeam))->tool(AssignTagsTool::class, [
        'model' => 'package',
        'rows' => [['record' => ['type' => 'npm', 'name' => 'secret-pkg'], 'tags' => [['type' => 'labels', 'tag' => 'Legacy']]]],
    ])->assertHasErrors(['Row 0: package "npm secret-pkg" not found.']);

    expect(Package::query()->where('name', 'secret-pkg')->sole()->tags)->toBeEmpty();
});

it('refuses records the policy does not let the caller update', function () {
    Gate::policy(Project::class, ConfigurableProjectPolicy::class);
    ConfigurableProjectPolicy::$abilities = ['update' => false];

    VoightServer::actingAs(mcpUser($this->myTeam))->tool(AssignTagsTool::class, [
        'model' => 'project',
        'rows' => [['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'labels', 'tag' => 'Legacy']]]],
    ])->assertHasErrors(['Row 0: not allowed to tag project "MINE".']);

    ConfigurableProjectPolicy::$abilities = [];

    expect(tagNamesOf($this->mine))->toBe([]);
});

it('rejects more than 500 rows', function () {
    $rows = array_fill(0, 501, ['record' => ['project_code' => 'MINE'], 'tags' => [['type' => 'labels', 'tag' => 'Legacy']]]);

    VoightLocalServer::tool(AssignTagsTool::class, ['model' => 'project', 'rows' => $rows])->assertHasErrors();
});

it('is hidden when write tools are off', function () {
    config(['filament-voight.mcp.write_tools' => false]);

    $names = array_column((fn (): array => $this->items)->call(VoightLocalServer::tools()), 'name');

    expect($names)->not->toContain('assign_tags');
});
