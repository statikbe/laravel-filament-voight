<?php

use Statikbe\FilamentVoight\Mcp\McpAccess;
use Statikbe\FilamentVoight\Mcp\Tools\FindPackageUsageTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetProjectVulnerabilitiesTool;
use Statikbe\FilamentVoight\Mcp\Tools\GetVulnerabilityTool;
use Statikbe\FilamentVoight\Mcp\Tools\ListProjectsTool;
use Statikbe\FilamentVoight\Mcp\Tools\ListTagsTool;
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

beforeEach(function () {
    $this->level = TagType::factory()->single()->appliesTo(['voight-project'])->create(['name' => 'Security level', 'description' => 'How sensitive.']);
    $this->high = Tag::factory()->ofType($this->level)->create(['name' => 'High']);
    $this->low = Tag::factory()->ofType($this->level)->create(['name' => 'Low']);

    $this->triage = TagType::factory()->appliesTo(['voight-vulnerability'])->create(['name' => 'Triage']);
    $this->accepted = Tag::factory()->ofType($this->triage)->create(['name' => 'Accepted risk']);

    $this->myTeam = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->mine = Project::factory()->for($this->myTeam)->create(['project_code' => 'MINE']);
    $this->theirs = Project::factory()->for($this->otherTeam)->create(['project_code' => 'THEIRS']);
    $this->mine->attachTag($this->high);
    $this->theirs->attachTag($this->high);
});

it('lists tag types with their tags', function () {
    $everywhere = TagType::factory()->create(['name' => 'Owner']);

    $types = collect(structuredContent(VoightLocalServer::tool(ListTagsTool::class))['tag_types'])->keyBy('key');

    expect($types['security-level'])->toMatchArray([
        'name' => 'Security level',
        'description' => 'How sensitive.',
        'single' => true,
        'applies_to' => ['project'],
        'applies_to_all' => false,
    ])
        ->and($types['security-level']['tags'])->toBe([
            ['name' => 'High', 'slug' => 'high', 'usage_count' => 2],
            ['name' => 'Low', 'slug' => 'low', 'usage_count' => 0],
        ])
        ->and($types[$everywhere->key]['applies_to'])->toBe(['project', 'environment', 'customer', 'package', 'vulnerability'])
        ->and($types[$everywhere->key]['applies_to_all'])->toBeTrue();
});

it('counts only visible records for a team member', function () {
    $types = collect(structuredContent(VoightServer::actingAs(mcpUser($this->myTeam))->tool(ListTagsTool::class))['tag_types'])->keyBy('key');

    expect($types['security-level']['tags'][0]['usage_count'])->toBe(1);
});

it('returns and filters projects by tag', function () {
    Project::factory()->for($this->myTeam)->create(['project_code' => 'UNTAGGED']);

    $projects = structuredContent(VoightLocalServer::tool(ListProjectsTool::class, [
        'tags' => [['type' => 'security-level', 'tag' => ' high ']],
    ]))['projects'];

    expect(array_column($projects, 'code'))->toEqualCanonicalizing(['MINE', 'THEIRS'])
        ->and($projects[0]['tags'])->toBe([['type' => 'security-level', 'tag' => 'High']]);
});

it('names unknown tag references in a filter', function () {
    VoightLocalServer::tool(ListProjectsTool::class, ['tags' => [
        ['type' => 'security-level', 'tag' => 'Extreme'],
        ['type' => 'nope', 'tag' => 'x'],
    ]])->assertHasErrors(['Unknown tag "Extreme" for tag type "security-level".', 'Unknown tag type "nope".']);
});

it('returns and filters a project\'s vulnerabilities by tag', function () {
    $environment = Environment::factory()->for($this->mine)->create(['name' => 'production']);
    $accepted = Vulnerability::factory()->critical()->create(['source_id' => 'GHSA-acc']);
    $open = Vulnerability::factory()->critical()->create(['source_id' => 'GHSA-open']);
    $accepted->attachTag($this->accepted);
    mcpFinding($environment, $accepted);
    mcpFinding($environment, $open, package: 'axios');

    $all = structuredContent(VoightLocalServer::tool(GetProjectVulnerabilitiesTool::class, ['project_code' => 'MINE']));
    $filtered = structuredContent(VoightLocalServer::tool(GetProjectVulnerabilitiesTool::class, [
        'project_code' => 'MINE',
        'tags' => [['type' => 'triage', 'tag' => 'accepted-risk']],
    ]));

    expect($all['project']['tags'])->toBe([['type' => 'security-level', 'tag' => 'High']])
        ->and(collect($all['vulnerabilities'])->firstWhere('id', 'GHSA-acc')['tags'])->toBe([['type' => 'triage', 'tag' => 'Accepted risk']])
        ->and(array_column($filtered['vulnerabilities'], 'id'))->toBe(['GHSA-acc']);
});

it('returns a vulnerability\'s tags', function () {
    $vulnerability = Vulnerability::factory()->create(['source_id' => 'GHSA-tag']);
    $vulnerability->attachTag($this->accepted);

    expect(structuredContent(VoightLocalServer::tool(GetVulnerabilityTool::class, ['id' => 'GHSA-tag']))['tags'])
        ->toBe([['type' => 'triage', 'tag' => 'Accepted risk']]);
});

it('filters package usage by project tag', function () {
    $untagged = Project::factory()->create(['project_code' => 'PLAIN']);
    mcpFinding(Environment::factory()->for($this->mine)->create(), Vulnerability::factory()->create());
    mcpFinding(Environment::factory()->for($untagged)->create(), Vulnerability::factory()->create());

    $usages = structuredContent(VoightLocalServer::tool(FindPackageUsageTool::class, [
        'package' => 'lodash',
        'tags' => [['type' => 'security-level', 'tag' => 'High']],
    ]))['usages'];

    expect(array_column($usages, 'project_code'))->toBe(['MINE']);
});

it('scopes customers, environments, packages and vulnerabilities to visible projects', function () {
    $myCustomer = Customer::factory()->create();
    $this->mine->update(['customer_id' => $myCustomer->id]);
    $theirCustomer = Customer::factory()->create();
    $this->theirs->update(['customer_id' => $theirCustomer->id]);

    $myFinding = mcpFinding(Environment::factory()->for($this->mine)->create(), Vulnerability::factory()->create(), package: 'mine-pkg');
    $theirFinding = mcpFinding(Environment::factory()->for($this->theirs)->create(), Vulnerability::factory()->create(), package: 'their-pkg');

    $access = McpAccess::forUser(mcpUser($this->myTeam));

    expect($access->visibleCustomers()->pluck('id')->all())->toBe([$myCustomer->id])
        ->and($access->visibleEnvironments()->pluck('id')->all())->toBe([$myFinding->auditRun->environment_id])
        ->and($access->visiblePackages()->pluck('name')->all())->toBe(['mine-pkg'])
        ->and($access->visibleVulnerabilities()->pluck('id')->all())->toBe([$myFinding->vulnerability_id])
        ->and(McpAccess::local()->visiblePackages()->count())->toBe(Package::query()->count());

    unset($theirFinding);
});
