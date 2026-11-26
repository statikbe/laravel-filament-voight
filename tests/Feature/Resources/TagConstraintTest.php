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

beforeEach(function () {
    $this->actingAs(new User);

    $this->level = TagType::factory()->single()->appliesTo(['voight-project'])->create(['name' => 'Security level']);
    $this->high = Tag::factory()->ofType($this->level)->create(['name' => 'High']);
    $this->low = Tag::factory()->ofType($this->level)->create(['name' => 'Low']);
});

function filterOnTag(string $page, string $constraint, string $operator, array $tagIds): Testable
{
    return Livewire::test($page)
        ->set('tableDeferredFilters.queryBuilder.rules', queryBuilderRule($constraint, $operator, ['values' => $tagIds]))
        ->call('applyTableFilters');
}

it('names constraints after the type and the related model', function () {
    expect(TagConstraint::name($this->level))->toBe('tag_security_level')
        ->and(TagConstraint::name($this->level, 'project'))->toBe('tag_project_security_level');
});

it('filters projects on their own tags', function (string $operator, bool $taggedVisible) {
    $tagged = Project::factory()->create();
    $tagged->attachTag($this->high);
    $other = Project::factory()->create();
    $other->attachTag($this->low);

    $test = filterOnTag(ListProjects::class, 'tag_security_level', $operator, [$this->high->id]);

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

    filterOnTag(ListEnvironments::class, 'tag_project_security_level', 'is', [$this->high->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters packages on the tags of projects installing them', function () {
    $tagged = EnvironmentPackage::factory()->create();
    $tagged->environment->project->attachTag($this->high);
    $other = EnvironmentPackage::factory()->create();

    filterOnTag(ListPackages::class, 'tag_project_security_level', 'is', [$this->high->id])
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

    filterOnTag(ListOpenFindings::class, 'tag_project_security_level', 'is', [$this->high->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$other]);
});

it('offers a type that applies to all models on every table', function () {
    $everywhere = TagType::factory()->create(['name' => 'Owner']);
    $tag = Tag::factory()->ofType($everywhere)->create();
    $tagged = Customer::factory()->create();
    $tagged->attachTag($tag);
    $other = Customer::factory()->create();

    filterOnTag(ListCustomers::class, 'tag_owner', 'is', [$tag->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$other]);
});
