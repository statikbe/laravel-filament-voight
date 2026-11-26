<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Statikbe\FilamentVoight\Enums\TagRuleStatus;
use Statikbe\FilamentVoight\Models\Package;
use Statikbe\FilamentVoight\Models\Project;
use Statikbe\FilamentVoight\Models\Tag;
use Statikbe\FilamentVoight\Models\TagRule;
use Statikbe\FilamentVoight\Models\TagType;
use Statikbe\FilamentVoight\Services\TagRuleRunner;

beforeEach(function () {
    $this->runner = app(TagRuleRunner::class);

    $this->labels = TagType::factory()->create(['name' => 'Labels']);
    $this->triage = Tag::factory()->ofType($this->labels)->create(['name' => 'Needs triage']);

    $this->level = TagType::factory()->single()->create(['name' => 'Level']);
    $this->high = Tag::factory()->ofType($this->level)->create(['name' => 'High']);
    $this->low = Tag::factory()->ofType($this->level)->create(['name' => 'Low']);
});

/**
 * A rule for $tag matching projects whose code is one of $codes.
 *
 * @param  list<string>  $codes
 */
function projectRule(Tag $tag, array $codes, array $attributes = []): TagRule
{
    return TagRule::factory()
        ->for($tag)
        ->matching(Project::query()->whereIn('project_code', $codes)->select('voight_projects.id'))
        ->create($attributes);
}

function pivotRow(Model $record, Tag $tag): ?object
{
    return DB::table('voight_taggables')
        ->where('tag_id', $tag->id)
        ->where('taggable_type', $record->getMorphClass())
        ->where('taggable_id', (string) $record->getKey())
        ->first();
}

it('adds the tag to matching records as automatic', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    $b = Project::factory()->create(['project_code' => 'B']);
    $rule = projectRule($this->triage, ['A']);

    expect($this->runner->run($rule))->toBeTrue();

    expect((bool) pivotRow($a, $this->triage)->is_manual)->toBeFalse()
        ->and(pivotRow($b, $this->triage))->toBeNull()
        ->and($rule->fresh()->last_match_count)->toBe(1)
        ->and($rule->fresh()->last_run_at)->not->toBeNull();
});

it('works for models with ulid keys', function () {
    $package = Package::factory()->create(['name' => 'lodash']);
    $rule = TagRule::factory()->for($this->triage)
        ->matching(Package::query()->where('name', 'lodash')->select('voight_packages.id'), 'voight-package')
        ->create();

    $this->runner->run($rule);

    expect(pivotRow($package, $this->triage))->not->toBeNull();
});

it('removes its tag when a record stops matching but keeps manual tags', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    $b = Project::factory()->create(['project_code' => 'B']);
    $b->attachTag($this->triage);
    $rule = projectRule($this->triage, ['A', 'B']);
    $this->runner->run($rule);

    $a->update(['project_code' => 'A2']);
    $b->update(['project_code' => 'B2']);
    $this->runner->runAll();

    expect(pivotRow($a, $this->triage))->toBeNull()
        ->and((bool) pivotRow($b, $this->triage)->is_manual)->toBeTrue();
});

it('keeps tags another rule for the same tag still matches', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    $first = projectRule($this->triage, ['A']);
    projectRule($this->triage, ['A']);
    $this->runner->runAll();

    $this->runner->removeRule($first, removeTags: true);

    expect(pivotRow($a, $this->triage))->not->toBeNull();
});

it('skips records holding a manual tag of the same single-value type', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    $a->attachTag($this->low);

    $this->runner->run(projectRule($this->high, ['A']));

    expect(pivotRow($a, $this->high))->toBeNull()
        ->and(pivotRow($a, $this->low))->not->toBeNull();
});

it('lets the most recently saved rule win on a single-value type, stably', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    projectRule($this->low, ['A'], ['updated_at' => now()->subDay()]);
    projectRule($this->high, ['A'], ['updated_at' => now()]);

    $this->runner->runAll();
    $this->runner->runAll();

    expect(pivotRow($a, $this->high))->not->toBeNull()
        ->and(pivotRow($a, $this->low))->toBeNull();
});

it('marks a rule with a broken query as failed and leaves its tags alone', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    $rule = projectRule($this->triage, ['A']);
    $this->runner->run($rule);

    $rule->update(['query_sql' => 'select id from voight_no_such_table']);

    expect($this->runner->run($rule))->toBeFalse();

    $rule->refresh();

    expect($rule->status)->toBe(TagRuleStatus::Failed)
        ->and($rule->last_error)->toContain('voight_no_such_table')
        ->and($rule->failed_at)->not->toBeNull()
        ->and(pivotRow($a, $this->triage))->not->toBeNull();
});

it('skips failed and paused rules when running all', function () {
    Project::factory()->create(['project_code' => 'A']);
    $failed = projectRule($this->triage, ['A'], ['status' => TagRuleStatus::Failed]);
    $paused = projectRule($this->triage, ['A'], ['status' => TagRuleStatus::Paused]);

    $this->runner->runAll();

    expect($failed->fresh()->last_run_at)->toBeNull()
        ->and($paused->fresh()->last_run_at)->toBeNull()
        ->and(DB::table('voight_taggables')->count())->toBe(0);
});

it('settles a rule that filters on a tag another rule adds within one run', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    projectRule($this->high, ['A']);
    TagRule::factory()->for($this->triage)
        ->matching(Project::query()->whereHas('tags', fn ($tags) => $tags->whereKey($this->high->id))->select('voight_projects.id'))
        ->create();

    $this->runner->runAll();

    expect(pivotRow($a, $this->triage))->not->toBeNull();
});

it('stops a cycle at the pass limit and logs it', function () {
    Log::spy();
    $a = Project::factory()->create(['project_code' => 'A']);
    $untagged = fn (Tag $tag) => Project::query()->whereDoesntHave('tags', fn ($tags) => $tags->whereKey($tag->id))->select('voight_projects.id');
    TagRule::factory()->for($this->triage)->matching($untagged($this->triage))->create();

    $this->runner->runAll();

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'tag rules'))->once();
});

it('drops matches of records that no longer exist', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    $rule = projectRule($this->triage, ['A']);
    $this->runner->run($rule);

    $a->delete();
    $this->runner->runAll();

    expect(DB::table('voight_tag_rule_matches')->where('tag_rule_id', $rule->id)->count())->toBe(0);
});

it('keeps the tags as manual when a rule is removed without its tags', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    $rule = projectRule($this->triage, ['A']);
    $this->runner->run($rule);

    $this->runner->removeRule($rule, removeTags: false);

    expect(TagRule::query()->count())->toBe(0)
        ->and((bool) pivotRow($a, $this->triage)->is_manual)->toBeTrue();
});

it('removes the tags only it provided when a rule is removed with its tags', function () {
    $a = Project::factory()->create(['project_code' => 'A']);
    $rule = projectRule($this->triage, ['A']);
    $this->runner->run($rule);

    $this->runner->removeRule($rule, removeTags: true);

    expect(pivotRow($a, $this->triage))->toBeNull();
});
