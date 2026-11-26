<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Statikbe\FilamentVoight\Enums\TagRuleStatus;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagRule;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Resources\TagResource\Pages\ViewTag;
use Statikbe\FilamentVoight\Resources\TagResource\RelationManagers\RulesRelationManager;
use Statikbe\FilamentVoight\Resources\TagTypeResource\Pages\EditTagType;
use Statikbe\FilamentVoight\Resources\TagTypeResource\RelationManagers\TagsRelationManager;
use Statikbe\FilamentVoight\Services\TagRuleRunner;

beforeEach(function () {
    $this->actingAs(new User);

    $this->type = TagType::factory()->create(['name' => 'Labels']);
    $this->tag = Tag::factory()->ofType($this->type)->create(['name' => 'Needs triage']);
    $this->project = Project::factory()->create(['project_code' => 'A']);
    $this->rule = TagRule::factory()->for($this->tag)
        ->matching(Project::query()->where('project_code', 'A')->select('voight_projects.id'))
        ->create(['description' => ['Code: A'], 'tab' => 'all', 'table_state' => ['filters' => ['queryBuilder' => ['rules' => queryBuilderRule('project_code', 'equals', ['text' => 'A'])]], 'search' => 'A']]);
    app(TagRuleRunner::class)->run($this->rule);
});

function rulesManager(Tag $tag): Testable
{
    return Livewire::test(RulesRelationManager::class, ['ownerRecord' => $tag, 'pageClass' => ViewTag::class]);
}

it('shows the tag page with its rules', function () {
    Livewire::test(ViewTag::class, ['record' => $this->tag->getRouteKey()])->assertSuccessful()->assertSee('Needs triage');

    rulesManager($this->tag)->assertCanSeeTableRecords([$this->rule])->assertSee('Code: A');
});

it('pauses and resumes a rule', function () {
    rulesManager($this->tag)->callAction(TestAction::make('pause')->table($this->rule));

    expect($this->rule->fresh()->status)->toBe(TagRuleStatus::Paused);

    rulesManager($this->tag)->callAction(TestAction::make('resume')->table($this->rule));

    expect($this->rule->fresh()->status)->toBe(TagRuleStatus::Active);
});

it('retries a failed rule', function () {
    $this->rule->update(['status' => TagRuleStatus::Failed, 'last_error' => 'boom']);

    rulesManager($this->tag)->callAction(TestAction::make('retry')->table($this->rule));

    expect($this->rule->fresh()->status)->toBe(TagRuleStatus::Active);
});

it('deletes a rule with or without its tags', function (bool $removeTags, bool $tagged) {
    rulesManager($this->tag)->callAction(TestAction::make('deleteRule')->table($this->rule), ['remove_tags' => $removeTags]);

    expect(TagRule::query()->count())->toBe(0)
        ->and($this->project->fresh()->tags->isNotEmpty())->toBe($tagged);
})->with([
    'removing its tags' => [true, false],
    'keeping its tags' => [false, true],
]);

it('opens the rule in its table with the saved filter, search, tab and rule', function () {
    $url = RulesRelationManager::openInTableUrl($this->rule);

    expect($url)->toContain('tab=all')
        ->toContain('search=A')
        ->toContain('tag_rule=' . $this->rule->id)
        ->toContain('filters');
});

it('shows rule counts, failed rules and automatic uses on the tag type page', function () {
    TagRule::factory()->for($this->tag)->create(['status' => TagRuleStatus::Failed]);

    $tag = Tag::query()->find($this->tag->id);

    Livewire::test(TagsRelationManager::class, ['ownerRecord' => $this->type, 'pageClass' => EditTagType::class])
        ->assertTableColumnStateSet('rules_count', 2, $tag)
        ->assertTableColumnFormattedStateSet('usage_count', '1 (1 by rule)', $tag)
        ->assertSee(voightTrans('tags.rules.failed_count', ['count' => 1]));

    expect(DB::table('voight_taggables')->where('is_manual', false)->count())->toBe(1);
});
