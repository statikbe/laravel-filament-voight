<?php

use Illuminate\Foundation\Auth\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Models\AuditFinding;
use Statikbe\FilamentVoight\Models\AuditRun;
use Statikbe\FilamentVoight\Models\Customer;
use Statikbe\FilamentVoight\Models\Environment;
use Statikbe\FilamentVoight\Models\EnvironmentPackage;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Models\Vulnerability;
use Statikbe\FilamentVoight\Resources\AuditFindingResource\Pages\ListOpenFindings;
use Statikbe\FilamentVoight\Resources\Components\TagConstraint;
use Statikbe\FilamentVoight\Resources\CustomerResource\Pages\ListCustomers;
use Statikbe\FilamentVoight\Resources\EnvironmentResource\Pages\ListEnvironments;
use Statikbe\FilamentVoight\Resources\PackageResource\Pages\ListPackages;
use Statikbe\FilamentVoight\Resources\ProjectResource\Pages\ListProjects;
use Statikbe\FilamentVoight\Resources\VulnerabilityResource\Pages\ListVulnerabilities;

beforeEach(function () {
    $this->actingAs(new User);

    $this->level = TagType::factory()->single()->appliesTo(['voight-project'])->create(['name' => 'Security level']);
    $this->high = Tag::factory()->ofType($this->level)->create(['name' => 'High']);
    $this->low = Tag::factory()->ofType($this->level)->create(['name' => 'Low']);
});

function filterOnTag(string $page, string $scope, string $operator, array $tagIds, ?TagType $type = null): Testable
{
    return Livewire::test($page)
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule('tag', $operator, ['type' => $scope . ':' . ($type ?? test()->level)->key, 'values' => $tagIds]))
        ->call('applyTableFilters');
}

it('names the one tag constraint', function () {
    expect(TagConstraint::NAME)->toBe('tag');
});

it('filters projects on their own tags', function (string $operator, bool $taggedVisible) {
    $tagged = Project::factory()->create();
    $tagged->attachTag($this->high);
    $other = Project::factory()->create();
    $other->attachTag($this->low);

    $test = filterOnTag(ListProjects::class, 'project', $operator, [$this->high->id]);

    $taggedVisible
        ? $test->assertCanSeeTableRecords([$tagged])->assertCanNotSeeTableRecords([$other])
        : $test->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$tagged]);
})->with([
    'is' => ['is', true],
    'is not' => ['is.inverse', false],
]);

it('filters environments on their project tags', function () {
    $tagged = Environment::factory()->create();
    $tagged->project->attachTag($this->high);
    $other = Environment::factory()->create();

    filterOnTag(ListEnvironments::class, 'project', 'is', [$this->high->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters packages on the tags of projects installing them', function () {
    $tagged = EnvironmentPackage::factory()->create();
    $tagged->environment->project->attachTag($this->high);
    $other = EnvironmentPackage::factory()->create();

    filterOnTag(ListPackages::class, 'project', 'is', [$this->high->id])
        ->assertCanSeeTableRecords([$tagged->package])
        ->assertCanNotSeeTableRecords([$other->package]);
});

it('filters open findings on their project tags', function () {
    $finding = fn (): AuditFinding => AuditFinding::factory()
        ->for(AuditRun::factory()->for(Environment::factory()->create(['name' => 'production'])))
        ->for(Vulnerability::factory()->critical())
        ->create();

    $tagged = $finding();
    $tagged->auditRun->environment->project->attachTag($this->high);
    $other = $finding();

    filterOnTag(ListOpenFindings::class, 'project', 'is', [$this->high->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$other]);
});

it('offers a type that applies to all models on every table', function () {
    $everywhere = TagType::factory()->create(['name' => 'Owner']);
    $tag = Tag::factory()->ofType($everywhere)->create();
    $tagged = Customer::factory()->create();
    $tagged->attachTag($tag);
    $other = Customer::factory()->create();

    filterOnTag(ListCustomers::class, 'customer', 'is', [$tag->id], $everywhere)
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters vulnerabilities on the tags of projects they currently affect', function () {
    $finding = fn (): AuditFinding => AuditFinding::factory()
        ->for(AuditRun::factory()->for(Environment::factory()->create(['name' => 'production'])))
        ->for(Vulnerability::factory()->critical())
        ->create();

    $tagged = $finding();
    $tagged->auditRun->environment->project->attachTag($this->high);
    $other = $finding();

    filterOnTag(ListVulnerabilities::class, 'project', 'is', [$this->high->id])
        ->assertCanSeeTableRecords([$tagged->vulnerability])
        ->assertCanNotSeeTableRecords([$other->vulnerability]);

    filterOnTag(ListVulnerabilities::class, 'project', 'is.inverse', [$this->high->id])
        ->assertCanSeeTableRecords([$other->vulnerability])
        ->assertCanNotSeeTableRecords([$tagged->vulnerability]);
});

it('lists the tag types per scope in the one tag filter', function () {
    $customerOnly = TagType::factory()->appliesTo(['voight-customer'])->create(['name' => 'Tier']);

    $operator = TagConstraint::make([
        'project' => [Project::class],
        'customer' => [Customer::class, 'customer'],
    ])->getOperators()['is'];

    $options = collect($operator->getFormSchema())->first()->getOptions();

    expect($options)->toHaveCount(2)
        ->and(array_keys(array_values($options)[0]))->toBe(['project:' . $this->level->key])
        ->and(array_keys(array_values($options)[1]))->toBe(['customer:' . $customerOnly->key]);
});

it('lists a project-only type only under the project scope', function () {
    $constraint = fn (array $scopes): array => collect(TagConstraint::make($scopes)->getOperators()['is']->getFormSchema())->first()->getOptions();
    $key = 'project:' . $this->level->key;

    expect($constraint(['customer' => [Customer::class]]))->toBe([])
        ->and($constraint(['environment' => [Environment::class], 'customer' => [Customer::class, 'project.customer']]))->toBe([])
        ->and(array_keys($constraint(['environment' => [Environment::class], 'project' => [Project::class, 'project']])['Project'] ?? []))->toBe([$key]);
});
